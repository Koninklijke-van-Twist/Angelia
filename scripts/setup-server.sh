#!/usr/bin/env bash
# Angelia: eenmalige serverinrichting (Ubuntu 25.04). Gebruik: sudo bash scripts/setup-server.sh
#
# - jq installeren (indien nodig)
# - certificaat (RSA-2048, 2 jaar, CN=Angelia) + .pfx in <webroot>/data/certs (www-data, 600)
# - .cer klaarzetten om in Entra te uploaden
# - /etc/angelia/cert-rotate.env (root:root 600) + systemd-timer voor de jaarlijkse rotatie
# - proc_open controleren in de php.ini van de webserver
# - $exchange-blok voor auth.php tonen (wachtwoord gemaskeerd) of toevoegen aan auth.php
#
# Idempotent: bestaand certificaat/config wordt alleen na bevestiging vervangen.
# Het wachtwoord wordt nooit getoond.
#
# Voor tests zijn paden te overschrijven: ANGELIA_SETUP_ETC, ANGELIA_SETUP_SYSTEMD, ANGELIA_SETUP_SBIN,
# ANGELIA_SETUP_PHPINI_GLOB, ANGELIA_SETUP_OWNER, ANGELIA_SETUP_SKIP_SYSTEMCTL=1, ANGELIA_SETUP_SKIP_ROOTCHECK=1.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ETC_DIR="${ANGELIA_SETUP_ETC:-/etc/angelia}"
SYSTEMD_DIR="${ANGELIA_SETUP_SYSTEMD:-/etc/systemd/system}"
SBIN_DIR="${ANGELIA_SETUP_SBIN:-/usr/local/sbin}"
PHPINI_GLOB="${ANGELIA_SETUP_PHPINI_GLOB:-/etc/php/*/apache2/php.ini /etc/php/*/fpm/php.ini}"
OWNER="${ANGELIA_SETUP_OWNER:-www-data}"

say() { printf '%s\n' "$*"; }
warn() { printf 'LET OP: %s\n' "$*" >&2; }
fail() { printf 'FOUT: %s\n' "$*" >&2; exit 1; }

ask() { # ask <vraag> <standaard> → antwoord op stdout
    local answer
    read -r -p "$1${2:+ [$2]}: " answer || true
    printf '%s' "${answer:-$2}"
}
confirm() { # confirm <vraag> → 0 bij ja (standaard nee)
    local answer
    read -r -p "$1 (j/N): " answer || true
    [[ "$answer" =~ ^[jJyY]$ ]]
}

if [[ "${ANGELIA_SETUP_SKIP_ROOTCHECK:-}" != 1 && $EUID -ne 0 ]]; then
    fail "Start met sudo: sudo bash scripts/setup-server.sh"
fi

say "=== Angelia serverinrichting ==="

# 1. jq
if command -v jq >/dev/null 2>&1; then
    say "jq is aanwezig."
else
    say "jq installeren…"
    if ! { apt-get update -qq && apt-get install -y -qq jq >/dev/null; }; then fail "jq installeren mislukt"; fi
fi
command -v openssl >/dev/null 2>&1 || fail "openssl ontbreekt (apt install openssl)"

# 2. gegevens
WEBROOT="$(ask "Webroot van Angelia (de map met index.php)" "/var/www/html/angelia")"
[[ -f "$WEBROOT/index.php" ]] || warn "$WEBROOT/index.php niet gevonden; is dit de juiste map?"
TENANT_ID="$(ask "Tenant-ID (Directory ID)" "")"
CLIENT_ID="$(ask "Client-ID (Application ID van de app-registratie)" "")"
APP_OBJECT_ID="$(ask "Object-ID van de app-registratie (voor de rotatie; Overview → Object ID)" "")"
ORGANIZATION="$(ask "Organisatie" "kvtnl.onmicrosoft.com")"
uuid_re='^[0-9a-fA-F]{8}-([0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
[[ "$TENANT_ID" =~ $uuid_re ]] || fail "Tenant-ID is geen GUID."
[[ "$CLIENT_ID" =~ $uuid_re ]] || fail "Client-ID is geen GUID."
[[ "$APP_OBJECT_ID" =~ $uuid_re ]] || fail "Object-ID is geen GUID."

CERT_DIR="$WEBROOT/data/certs"
PFX_PATH="$CERT_DIR/angelia-exo.pfx"
CER_PATH="$ETC_DIR/angelia-exo.cer"
ENV_FILE="$ETC_DIR/cert-rotate.env"

# 3. certificaat
make_cert=1
if [[ -f "$PFX_PATH" ]]; then
    if confirm "Er staat al een certificaat in $PFX_PATH. Vervangen door een nieuw certificaat?"; then
        warn "Het oude certificaat werkt niet meer zodra je het nieuwe in Entra gebruikt."
    else
        make_cert=0
    fi
fi

PFX_PASSWORD=""
read_password() {
    local p1 p2
    read -r -s -p "Wachtwoord voor het certificaat: " p1 || true; echo
    read -r -s -p "Herhaal het wachtwoord: " p2 || true; echo
    [[ -n "$p1" ]] || fail "Leeg wachtwoord is niet toegestaan."
    [[ "$p1" == "$p2" ]] || fail "De wachtwoorden zijn niet gelijk."
    PFX_PASSWORD="$p1"
    export PFX_PASSWORD
}
if [[ $make_cert -eq 1 ]]; then
    read_password
else
    say "Bestaand certificaat blijft. Geef het wachtwoord van dat certificaat (voor de rotatie-config)."
    read_password
    openssl pkcs12 -in "$PFX_PATH" -passin env:PFX_PASSWORD -noout 2>/dev/null ||
        fail "Dit wachtwoord past niet bij $PFX_PATH."
fi
export PFX_PASSWORD

install -d -m 700 "$ETC_DIR"
install -d -m 750 "$WEBROOT/data"
install -d -m 700 "$CERT_DIR"
chown "$OWNER" "$WEBROOT/data" "$CERT_DIR"

if [[ $make_cert -eq 1 ]]; then
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT
    umask 077
    openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes -subj "/CN=Angelia" \
        -keyout "$work/key.pem" -out "$work/cert.pem" 2>/dev/null || fail "certificaat maken mislukt"
    openssl pkcs12 -export -inkey "$work/key.pem" -in "$work/cert.pem" -passout env:PFX_PASSWORD -out "$work/angelia-exo.pfx" ||
        fail ".pfx maken mislukt"
    install -m 600 -o "$OWNER" "$work/angelia-exo.pfx" "$PFX_PATH.new"
    mv -f "$PFX_PATH.new" "$PFX_PATH"
    openssl x509 -in "$work/cert.pem" -outform der -out "$CER_PATH"
    chmod 644 "$CER_PATH"
    say "Certificaat gemaakt: $PFX_PATH (eigenaar $OWNER, 600)."
else
    openssl pkcs12 -in "$PFX_PATH" -passin env:PFX_PASSWORD -clcerts -nokeys 2>/dev/null | openssl x509 -outform der -out "$CER_PATH"
    chmod 644 "$CER_PATH"
fi
say ""
say ">>> Upload dit bestand in Entra: App registrations → <jouw app> → Certificates & secrets → Certificates → Upload certificate:"
say "    $CER_PATH"
say "    (vingerafdruk SHA1: $(openssl x509 -in "$CER_PATH" -inform der -noout -fingerprint -sha1 | cut -d= -f2 | tr -d ':'))"
say ""

# 4. rotatie-config
write_env=1
if [[ -f "$ENV_FILE" ]] && ! confirm "$ENV_FILE bestaat al. Overschrijven?"; then
    write_env=0
fi
if [[ $write_env -eq 1 ]]; then
    tmp_env="$(mktemp "$ETC_DIR/.cert-rotate.env.XXXXXX")"
    {
        printf 'TENANT_ID=%q\n' "$TENANT_ID"
        printf 'CLIENT_ID=%q\n' "$CLIENT_ID"
        printf 'APP_OBJECT_ID=%q\n' "$APP_OBJECT_ID"
        printf 'EXO_ORGANIZATION=%q\n' "$ORGANIZATION"
        printf 'PFX_PATH=%q\n' "$PFX_PATH"
        printf 'PFX_PASSWORD=%q\n' "$PFX_PASSWORD"
        printf 'PFX_OWNER=%q\n' "$OWNER"
    } > "$tmp_env"
    chmod 600 "$tmp_env"
    [[ "${ANGELIA_SETUP_SKIP_ROOTCHECK:-}" == 1 ]] || chown root:root "$tmp_env"
    mv -f "$tmp_env" "$ENV_FILE"
    say "Rotatie-config geschreven: $ENV_FILE (600)."
fi

# 5. systemd
install -m 750 "$REPO/scripts/systemd/angelia-cert-rotate.sh" "$SBIN_DIR/angelia-cert-rotate.sh"
install -m 644 "$REPO/scripts/systemd/angelia-cert-rotate.service" "$SYSTEMD_DIR/angelia-cert-rotate.service"
install -m 644 "$REPO/scripts/systemd/angelia-cert-rotate.timer" "$SYSTEMD_DIR/angelia-cert-rotate.timer"
if [[ "${ANGELIA_SETUP_SKIP_SYSTEMCTL:-}" != 1 ]]; then
    systemctl daemon-reload
    systemctl enable --now angelia-cert-rotate.timer >/dev/null
    say "Timer actief: $(systemctl list-timers angelia-cert-rotate.timer --no-legend | awk '{print $1, $2, $3}')"
fi

# 6. proc_open
found_ini=0
# shellcheck disable=SC2086 # glob moet hier splitsen
for ini in $PHPINI_GLOB; do
    [[ -f "$ini" ]] || continue
    found_ini=1
    if grep -Eq '^[[:space:]]*disable_functions[[:space:]]*=.*\bproc_open\b' "$ini"; then
        warn "proc_open staat uit in $ini (disable_functions). Haal het daar weg en herstart de webserver."
    else
        say "proc_open toegestaan in $ini."
    fi
done
[[ $found_ini -eq 1 ]] || warn "Geen php.ini van apache2/fpm gevonden; controleer proc_open zelf."

# 7. auth.php
exchange_block() { # exchange_block <wachtwoord-tekst>
    cat <<PHP

\$exchange = [
    'app_id' => '$CLIENT_ID',
    'organization' => '$ORGANIZATION',
    'certificate_path' => __DIR__ . '/data/certs/angelia-exo.pfx',
    'certificate_password' => $1,
    'certificate_thumbprint' => '',
];
PHP
}
AUTH="$WEBROOT/auth.php"
if [[ -f "$AUTH" ]] && grep -Eq "^[[:space:]]*\\\$exchange[[:space:]]*=" "$AUTH"; then
    say "auth.php heeft al een \$exchange-blok; niet aangepast. Controleer het zelf:"
    exchange_block "'********' /* vul zelf in */"
elif [[ -f "$AUTH" ]] && confirm "\$exchange-blok toevoegen aan $AUTH (met het wachtwoord)?"; then
    pw_php="$(php -r 'echo var_export(getenv("PFX_PASSWORD"), true);' 2>/dev/null || true)"
    [[ -n "$pw_php" ]] || fail "php-cli ontbreekt; voeg het blok zelf toe."
    exchange_block "$pw_php" >> "$AUTH"
    say "Toegevoegd aan $AUTH."
else
    say "Zet dit in $AUTH (vul het wachtwoord zelf in):"
    exchange_block "'********' /* vul zelf in */"
fi

say ""
say "Klaar. Volgende stappen: .cer uploaden in Entra, rechten (Exchange.ManageAsApp + Exchange Administrator) controleren,"
say "daarna: php $WEBROOT/worker.php --import && php $WEBROOT/worker.php --dry-run"
