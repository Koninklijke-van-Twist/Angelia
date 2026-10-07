#!/usr/bin/env bash
# Angelia: jaarlijkse rotatie van het app-certificaat voor Exchange Online (app-only).
#
#  1. nieuw self-signed RSA-2048-certificaat, 2 jaar geldig
#  2. toevoegen aan de app-registratie via Graph POST /applications/{id}/addKey,
#     met een proof-JWT ondertekend door het HUIDIGE certificaat (geen extra Graph-rechten nodig)
#  3. Exchange-login testen met het nieuwe certificaat (Connect-ExchangeOnline, met retries)
#  4. .pfx in web/data/certs atomair vervangen (www-data, chmod 600; vorige als .prev)
#  5. pas daarna de oude key verwijderen (removeKey)
# Faalt een stap: oude certificaat blijft in gebruik, een al toegevoegde nieuwe key wordt
# weer verwijderd, exit-code ≠ 0.
#
# Config (buiten git), standaard /etc/angelia/cert-rotate.env:
#   TENANT_ID=…            Directory (tenant) ID
#   CLIENT_ID=…            Application (client) ID
#   APP_OBJECT_ID=…        Object ID van de app-registratie (niet de service principal)
#   EXO_ORGANIZATION=kvt.onmicrosoft.com
#   PFX_PATH=/var/www/…/angelia/data/certs/angelia-exo.pfx
#   PFX_PASSWORD=…         zelfde als certificate_password in web/auth.php (blijft gelijk)
#   PFX_OWNER=www-data     (optioneel)
#   PWSH=/usr/bin/pwsh     (optioneel)
#   CERT_SUBJECT="/CN=Angelia Exchange Sync"   (optioneel)
set -euo pipefail

CONFIG="${ANGELIA_CERT_CONFIG:-/etc/angelia/cert-rotate.env}"
# shellcheck disable=SC1090
source "$CONFIG"
: "${TENANT_ID:?}" "${CLIENT_ID:?}" "${APP_OBJECT_ID:?}" "${EXO_ORGANIZATION:?}" "${PFX_PATH:?}" "${PFX_PASSWORD:?}"
PFX_OWNER="${PFX_OWNER:-www-data}"
PWSH="${PWSH:-pwsh}"
CERT_SUBJECT="${CERT_SUBJECT:-/CN=Angelia Exchange Sync}"
GRAPH="${GRAPH_BASE:-https://graph.microsoft.com/v1.0}"
LOGIN="${LOGIN_BASE:-https://login.microsoftonline.com}"
export CLIENT_ID EXO_ORGANIZATION

log() { echo "[angelia-cert-rotate] $*" >&2; }
fail() { log "FOUT: $*"; exit 1; }

umask 077
WORK="$(mktemp -d)"
NEW_KEY_ID=""
TOKEN=""
cleanup() {
    local code=$?
    if [[ $code -ne 0 && -n "$NEW_KEY_ID" && -n "$TOKEN" ]]; then
        log "Nieuwe key $NEW_KEY_ID weer verwijderen (oude certificaat blijft actief)."
        remove_key "$NEW_KEY_ID" "$WORK/old.key" "$WORK/old.crt" || log "Opruimen van de nieuwe key mislukt; verwijder hem handmatig in Entra."
    fi
    rm -rf "$WORK"
    exit $code
}
trap cleanup EXIT

export PFX_PASSWORD
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
x5t() { openssl x509 -in "$1" -outform der | openssl dgst -sha1 -binary | b64url; }
thumb_hex() { openssl x509 -in "$1" -noout -fingerprint -sha1 | cut -d= -f2 | tr -d ':'; }

# jwt <key> <cert> <payload-json>
jwt() {
    local header payload sig
    header=$(printf '{"alg":"RS256","typ":"JWT","x5t":"%s"}' "$(x5t "$2")" | b64url)
    payload=$(printf '%s' "$3" | b64url)
    sig=$(printf '%s.%s' "$header" "$payload" | openssl dgst -sha256 -sign "$1" -binary | b64url)
    printf '%s.%s.%s' "$header" "$payload" "$sig"
}

proof_jwt() { # proof voor addKey/removeKey, ondertekend met een geldig bestaand certificaat
    local now; now=$(date +%s)
    jwt "$1" "$2" "$(printf '{"aud":"00000002-0000-0000-c000-000000000000","iss":"%s","nbf":%d,"exp":%d}' "$APP_OBJECT_ID" "$now" $((now + 600)))"
}

graph_token() {
    local now assertion
    now=$(date +%s)
    assertion=$(jwt "$WORK/old.key" "$WORK/old.crt" "$(printf '{"aud":"https://login.microsoftonline.com/%s/oauth2/v2.0/token","iss":"%s","sub":"%s","jti":"%s","nbf":%d,"exp":%d}' \
        "$TENANT_ID" "$CLIENT_ID" "$CLIENT_ID" "$(cat /proc/sys/kernel/random/uuid)" "$now" $((now + 600)))")
    curl -sS --fail-with-body "$LOGIN/$TENANT_ID/oauth2/v2.0/token" \
        --data-urlencode "client_id=$CLIENT_ID" \
        --data-urlencode "scope=https://graph.microsoft.com/.default" \
        --data-urlencode "grant_type=client_credentials" \
        --data-urlencode "client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer" \
        --data-urlencode "client_assertion=$assertion" | jq -er .access_token
}

remove_key() { # remove_key <keyId> <proof-key> <proof-cert>
    curl -sS --fail-with-body -X POST "$GRAPH/applications/$APP_OBJECT_ID/removeKey" \
        -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
        -d "$(jq -n --arg k "$1" --arg p "$(proof_jwt "$2" "$3")" '{keyId: $k, proof: $p}')" >/dev/null
}

command -v jq >/dev/null || fail "jq ontbreekt"
[[ -f "$PFX_PATH" ]] || fail "$PFX_PATH bestaat niet"

log "Huidig certificaat uitlezen."
openssl pkcs12 -in "$PFX_PATH" -passin env:PFX_PASSWORD -nocerts -nodes -out "$WORK/old.key" 2>/dev/null || fail "kan de sleutel uit de huidige .pfx niet lezen (wachtwoord?)"
openssl pkcs12 -in "$PFX_PATH" -passin env:PFX_PASSWORD -clcerts -nokeys -out "$WORK/old.crt" 2>/dev/null || fail "kan het certificaat uit de huidige .pfx niet lezen"
openssl x509 -in "$WORK/old.crt" -noout -checkend 0 >/dev/null || fail "het huidige certificaat is al verlopen; addKey met proof kan dan niet meer, roteer handmatig in Entra"
OLD_THUMB=$(thumb_hex "$WORK/old.crt")
OLD_THUMB_B64=$(openssl x509 -in "$WORK/old.crt" -outform der | openssl dgst -sha1 -binary | openssl base64 -A)

log "Graph-token ophalen met het huidige certificaat."
TOKEN=$(graph_token) || fail "Graph-token ophalen mislukt"

OLD_KEY_ID=$(curl -sS --fail-with-body "$GRAPH/applications/$APP_OBJECT_ID?\$select=keyCredentials" -H "Authorization: Bearer $TOKEN" |
    jq -er --arg t "$OLD_THUMB" --arg b "$OLD_THUMB_B64" '.keyCredentials[] | select((.customKeyIdentifier // "") as $c | ($c | ascii_upcase) == $t or $c == $b) | .keyId') ||
    fail "oude key (thumbprint $OLD_THUMB) niet gevonden op de app"

log "Nieuw certificaat maken (RSA-2048, 2 jaar)."
openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes -subj "$CERT_SUBJECT" \
    -keyout "$WORK/new.key" -out "$WORK/new.crt" 2>/dev/null || fail "openssl req mislukt"

log "Nieuwe key toevoegen aan de app (addKey)."
NEW_DER_B64=$(openssl x509 -in "$WORK/new.crt" -outform der | openssl base64 -A)
NEW_KEY_ID=$(curl -sS --fail-with-body -X POST "$GRAPH/applications/$APP_OBJECT_ID/addKey" \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
    -d "$(jq -n --arg k "$NEW_DER_B64" --arg p "$(proof_jwt "$WORK/old.key" "$WORK/old.crt")" \
        '{keyCredential: {type: "AsymmetricX509Cert", usage: "Verify", key: $k}, passwordCredential: null, proof: $p}')" |
    jq -er .keyId) || fail "addKey mislukt"

PFX_DIR=$(dirname "$PFX_PATH")
NEW_PFX="$PFX_DIR/.angelia-exo.pfx.new"
openssl pkcs12 -export -inkey "$WORK/new.key" -in "$WORK/new.crt" -passout env:PFX_PASSWORD -out "$NEW_PFX" || fail "nieuwe .pfx maken mislukt"
chown "$PFX_OWNER" "$NEW_PFX"
chmod 600 "$NEW_PFX"

log "Exchange-login testen met het nieuwe certificaat (max. 10 pogingen, de key heeft even tijd nodig)."
ok=0
for attempt in $(seq 1 10); do
    # shellcheck disable=SC2016 # PowerShell-variabelen, bewust niet door bash uitgebreid
    if ANGELIA_PFX="$NEW_PFX" "$PWSH" -NoLogo -NoProfile -NonInteractive -Command '
        $ErrorActionPreference = "Stop"
        Import-Module ExchangeOnlineManagement
        $pw = ConvertTo-SecureString $env:PFX_PASSWORD -AsPlainText -Force
        Connect-ExchangeOnline -AppId $env:CLIENT_ID -Organization $env:EXO_ORGANIZATION -CertificateFilePath $env:ANGELIA_PFX -CertificatePassword $pw -ShowBanner:$false
        Get-OrganizationConfig | Out-Null
        Disconnect-ExchangeOnline -Confirm:$false' >/dev/null 2>&1; then
        ok=1; break
    fi
    log "  poging $attempt mislukt, opnieuw proberen."
    sleep "${ANGELIA_RETRY_SLEEP:-60}"
done
[[ $ok -eq 1 ]] || { rm -f "$NEW_PFX"; fail "Exchange-login met het nieuwe certificaat lukt niet"; }

log ".pfx vervangen."
cp -p "$PFX_PATH" "$PFX_PATH.prev"
mv -f "$NEW_PFX" "$PFX_PATH"
KEEP_NEW_KEY_ID="$NEW_KEY_ID"
NEW_KEY_ID=""   # vanaf hier niet meer terugdraaien: het nieuwe certificaat is in gebruik

log "Oude key $OLD_KEY_ID verwijderen (removeKey)."
remove_key "$OLD_KEY_ID" "$WORK/new.key" "$WORK/new.crt" || fail "removeKey van de oude key mislukt; het nieuwe certificaat werkt wel. Verwijder de oude key handmatig."
rm -f "$PFX_PATH.prev"
log "Klaar. Nieuwe key $KEEP_NEW_KEY_ID, geldig tot $(openssl x509 -in "$WORK/new.crt" -noout -enddate | cut -d= -f2)."
