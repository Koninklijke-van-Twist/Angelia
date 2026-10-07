#!/usr/bin/env bash
# Test van scripts/systemd/angelia-cert-rotate.sh tegen een nep-Graph (php -S) en een nep-pwsh.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'kill $SRV 2>/dev/null || true; rm -rf "$T"' EXIT
fails=0
check() { if eval "$1"; then echo "ok   $2"; else echo "FAIL $2"; fails=$((fails+1)); fi; }

openssl req -x509 -newkey rsa:2048 -days 30 -nodes -subj "/CN=oud" -keyout "$T/o.key" -out "$T/o.crt" 2>/dev/null
openssl pkcs12 -export -inkey "$T/o.key" -in "$T/o.crt" -passout pass:geheim -out "$T/cert.pfx"
init_state() {
    php -r '$pem=file_get_contents($argv[1]); $der=base64_decode(preg_replace("/-----[^-]+-----|\s/","",$pem));
      echo json_encode(["keys"=>[["keyId"=>"oud","pem"=>$pem,"x5t"=>rtrim(strtr(base64_encode(sha1($der,true)),"+/","-_"),"="),"thumb"=>strtoupper(sha1($der))]]]);' "$T/o.crt" > "$T/state.json"
}
init_state
PORT=$((19000 + $$ % 1000))
GRAPH_MOCK_STATE="$T/state.json" php -S 127.0.0.1:$PORT "$ROOT/tests/fixtures/graph_mock.php" >/dev/null 2>&1 & SRV=$!
sleep 0.5
printf '#!/bin/sh\n[ -z "$FAKE_LOGIN_FAIL" ]\n' > "$T/pwsh"; chmod +x "$T/pwsh"
cat > "$T/env" <<CFG
TENANT_ID=t
CLIENT_ID=c
APP_OBJECT_ID=o
EXO_ORGANIZATION=kvt.onmicrosoft.com
PFX_PATH=$T/cert.pfx
PFX_PASSWORD=geheim
PFX_OWNER=$(id -un)
PWSH=$T/pwsh
GRAPH_BASE=http://127.0.0.1:$PORT/v1.0
LOGIN_BASE=http://127.0.0.1:$PORT
CFG
sha() { sha256sum "$T/cert.pfx" | cut -d' ' -f1; }

# 1. mislukte Exchange-login: oud certificaat blijft, nieuwe key weer weg
before=$(sha)
if FAKE_LOGIN_FAIL=1 ANGELIA_CERT_CONFIG="$T/env" ANGELIA_RETRY_SLEEP=0 bash "$ROOT/scripts/systemd/angelia-cert-rotate.sh" 2>"$T/log1"; then r=0; else r=$?; fi
check '[ $r -ne 0 ]' 'mislukte login: exit ≠ 0'
check '[ "$(sha)" = "$before" ]' 'mislukte login: oude .pfx ongewijzigd'
check '[ "$(jq ".keys|length" "$T/state.json")" = 1 ] && [ "$(jq -r ".keys[0].keyId" "$T/state.json")" = oud ]' 'mislukte login: nieuwe key teruggedraaid'

# 2. geslaagde rotatie
ANGELIA_CERT_CONFIG="$T/env" ANGELIA_RETRY_SLEEP=0 bash "$ROOT/scripts/systemd/angelia-cert-rotate.sh" 2>"$T/log2"; r=$?
check '[ $r -eq 0 ]' 'rotatie geslaagd'
check '[ "$(sha)" != "$before" ]' '.pfx vervangen'
check '[ "$(stat -c %a "$T/cert.pfx")" = 600 ]' '.pfx chmod 600'
check '[ "$(jq ".keys|length" "$T/state.json")" = 1 ] && [ "$(jq -r ".keys[0].keyId" "$T/state.json")" != oud ]' 'alleen nieuwe key over (oude verwijderd)'
check 'openssl pkcs12 -in "$T/cert.pfx" -passin pass:geheim -nokeys 2>/dev/null | openssl x509 -noout -checkend $((700*86400)) >/dev/null' 'nieuw certificaat ~2 jaar geldig'
check '[ ! -e "$T/cert.pfx.prev" ]' 'geen .prev achtergebleven'
[ $fails -eq 0 ] && echo "Alle tests geslaagd." || { echo "$fails test(s) mislukt."; cat "$T/log1" "$T/log2"; exit 1; }
