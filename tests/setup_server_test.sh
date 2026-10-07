#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2016 # "A && ok || bad" (ok faalt nooit); PHP-/grep-tekst bewust letterlijk
# Droge testrun van scripts/setup-server.sh in een tijdelijke map (geen root, geen systemctl, geen apt).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fails=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fails=$((fails + 1)); }
mkdir -p "$T/web" "$T/etc" "$T/systemd" "$T/sbin" "$T/php/8.4/apache2"
touch "$T/web/index.php"
printf '<?php\n$allowedUsers = [];\n' > "$T/web/auth.php"
printf 'disable_functions = exec,proc_open\n' > "$T/php/8.4/apache2/php.ini"
OWNER_NAME="$(id -un)"
export ANGELIA_SETUP_ETC="$T/etc" ANGELIA_SETUP_SYSTEMD="$T/systemd" ANGELIA_SETUP_SBIN="$T/sbin" \
    ANGELIA_SETUP_PHPINI_GLOB="$T/php/*/apache2/php.ini" ANGELIA_SETUP_OWNER="$OWNER_NAME" \
    ANGELIA_SETUP_SKIP_SYSTEMCTL=1 ANGELIA_SETUP_SKIP_ROOTCHECK=1
G=11111111-2222-3333-4444-555555555555
export PW='Ge#heim w8'

# run 1: alles nieuw, blok toevoegen aan auth.php
printf '%s\n' "$T/web" "$G" "$G" "$G" "" "$PW" "$PW" "j" | bash "$ROOT/scripts/setup-server.sh" > "$T/out1" 2>&1 || { cat "$T/out1"; bad "run 1 geslaagd"; }
if grep -qF "$PW" "$T/out1"; then bad "wachtwoord niet in uitvoer"; else ok "wachtwoord niet in uitvoer"; fi
if openssl pkcs12 -in "$T/web/data/certs/angelia-exo.pfx" -passin "pass:$PW" -noout 2>/dev/null; then ok ".pfx met wachtwoord"; else bad ".pfx met wachtwoord"; fi
[ "$(stat -c %a "$T/web/data/certs/angelia-exo.pfx")" = 600 ] && ok ".pfx 600" || bad ".pfx 600"
[ "$(stat -c %a "$T/etc/cert-rotate.env")" = 600 ] && ok "env 600" || bad "env 600"
# shellcheck disable=SC1091
( source "$T/etc/cert-rotate.env"; [ "$PFX_PASSWORD" = "$PW" ] && [ "$EXO_ORGANIZATION" = kvtnl.onmicrosoft.com ] ) && ok "env-waarden (standaardorganisatie)" || bad "env-waarden"
[ -f "$T/etc/angelia-exo.cer" ] && grep -qF "$T/etc/angelia-exo.cer" "$T/out1" && ok ".cer klaargezet + pad getoond" || bad ".cer"
[ -f "$T/systemd/angelia-cert-rotate.timer" ] && [ -x "$T/sbin/angelia-cert-rotate.sh" ] && ok "systemd-bestanden geïnstalleerd" || bad "systemd"
grep -q "proc_open staat uit" "$T/out1" && ok "proc_open-waarschuwing" || bad "proc_open-waarschuwing"
php -l "$T/web/auth.php" >/dev/null && php -r "require '$T/web/auth.php'; exit(\$exchange['certificate_password'] === getenv('PW') && \$exchange['app_id'] === '$G' ? 0 : 1);" && ok "auth.php aangevuld en geldig" || bad "auth.php"
sum1="$(sha256sum "$T/web/data/certs/angelia-exo.pfx")"

# run 2: idempotent – certificaat en env niet vervangen, auth.php niet nog eens
printf '%s\n' "$T/web" "$G" "$G" "$G" "" "n" "$PW" "$PW" "n" | bash "$ROOT/scripts/setup-server.sh" > "$T/out2" 2>&1 || { cat "$T/out2"; bad "run 2 geslaagd"; }
[ "$(sha256sum "$T/web/data/certs/angelia-exo.pfx")" = "$sum1" ] && ok "bestaand certificaat blijft" || bad "certificaat vervangen"
[ "$(grep -c '^\$exchange' "$T/web/auth.php")" = 1 ] && ok "auth.php niet dubbel" || bad "auth.php dubbel"
grep -q "\*\*\*\*\*\*\*\*" "$T/out2" && ok "blok getoond met gemaskeerd wachtwoord" || bad "masker"

# run 3: wachtwoorden verschillen → fout
if printf '%s\n' "$T/web" "$G" "$G" "$G" "" "n" "a" "b" | bash "$ROOT/scripts/setup-server.sh" > "$T/out3" 2>&1; then bad "ongelijke wachtwoorden geweigerd"; else ok "ongelijke wachtwoorden geweigerd"; fi

# run 4: --rotate-password (zelfde certificaat, nieuw wachtwoord in .pfx, env en auth.php)
export PW2='Nieuw$ww 2!'
printf '%s\n' "$PW2" "$PW2" | bash "$ROOT/scripts/setup-server.sh" --rotate-password > "$T/out4" 2>&1 || { cat "$T/out4"; bad "rotate-password geslaagd"; }
openssl pkcs12 -in "$T/web/data/certs/angelia-exo.pfx" -passin env:PW2 -noout 2>/dev/null && ok "rotate: .pfx met nieuw wachtwoord" || bad "rotate: .pfx"
# shellcheck disable=SC1091
( source "$T/etc/cert-rotate.env"; [ "$PFX_PASSWORD" = "$PW2" ] ) && ok "rotate: env bijgewerkt" || bad "rotate: env"
php -r "require '$T/web/auth.php'; exit(\$exchange['certificate_password'] === getenv('PW2') ? 0 : 1);" && ok "rotate: auth.php bijgewerkt" || bad "rotate: auth.php"
grep -qF "$PW2" "$T/out4" && bad "rotate: wachtwoord niet in uitvoer" || ok "rotate: wachtwoord niet in uitvoer"

# auth.php-randgevallen via het hulpscript
H="$ROOT/scripts/auth-php-update.php"
printf '$allowedUsers = [\x27a@kvt.nl\x27];\n' > "$T/a1.php"
ANGELIA_APP_ID=x ANGELIA_ORG=y PFX_PASSWORD=p php "$H" "$T/a1.php" "" add >/dev/null && head -1 "$T/a1.php" | grep -qx '<?php' && php -l "$T/a1.php" >/dev/null && ok "auth.php zonder openingstag gerepareerd" || bad "zonder openingstag"
printf '<?php\n$allowedUsers = [];\n?>\n' > "$T/a2.php"
ANGELIA_APP_ID=x ANGELIA_ORG=y PFX_PASSWORD=p php "$H" "$T/a2.php" "" add >/dev/null && ! grep -q '?>' "$T/a2.php" && php -r "require '$T/a2.php'; exit(\$exchange['app_id'] === 'x' ? 0 : 1);" && ok "afsluitende tag verwijderd vóór toevoegen" || bad "afsluitende tag"
out="$(cd "$T" && php "$T/a2.php")"; [ -z "$out" ] && ok "auth.php geeft geen uitvoer (niets lekt naar de browser)" || bad "auth.php geeft uitvoer"
rm -f "$T/a3.php"
ANGELIA_APP_ID=x ANGELIA_ORG=y PFX_PASSWORD=p php "$H" "$T/a3.php" "$ROOT/web/auth_TEMPLATE.php" add >/dev/null && php -r "require '$T/a3.php'; exit(\$exchange['certificate_password'] === 'p' && isset(\$allowedUsers) ? 0 : 1);" && [ "$(grep -c '^\$exchange' "$T/a3.php")" = 1 ] && ok "nieuw vanaf template, één \$exchange-blok" || bad "vanaf template"
printf '<?php\n$x = ;\n' > "$T/a4.php"; cp "$T/a4.php" "$T/a4.orig"
if ANGELIA_APP_ID=x ANGELIA_ORG=y PFX_PASSWORD=p php "$H" "$T/a4.php" "" add >/dev/null 2>&1; then bad "ongeldige php geweigerd"; else cmp -s "$T/a4.php" "$T/a4.orig" && ok "ongeldige php: origineel ongewijzigd" || bad "origineel gewijzigd"; fi
printf '<?php\n$oud = [\x27certificate_password\x27 => \x27x\x27];\n$exchange = [\n    \x27certificate_password\x27 => \x27oud\x27,\n];\n' > "$T/a5.php"
PFX_PASSWORD=nieuw php "$H" "$T/a5.php" "" password >/dev/null && php -r "require '$T/a5.php'; exit(\$exchange['certificate_password'] === 'nieuw' && \$oud['certificate_password'] === 'x' ? 0 : 1);" && ok "password: alleen binnen \$exchange vervangen" || bad "password-scope"
bak="$(find "$T" -maxdepth 1 -name 'a5.php.bak-*' | head -1)"; [ "$(stat -c %a "$bak")" = 600 ] && ok "backup 600" || bad "backup-rechten"
f="$ROOT/web/auth_TEMPLATE.php"; head -1 "$f" | grep -qx '<?php' && ! grep -q '?>' "$f" && ok "auth_TEMPLATE.php: openingstag, geen afsluitende tag" || bad "auth_TEMPLATE.php"

if [ "$fails" -eq 0 ]; then echo "Alle tests geslaagd."; else echo "$fails test(s) mislukt."; exit 1; fi
