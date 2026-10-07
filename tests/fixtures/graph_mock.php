<?php
// Nep-Entra/Graph voor tests/cert_rotate_test.sh. State: $GRAPH_MOCK_STATE (JSON met keyCredentials + certs).
$statePath = getenv('GRAPH_MOCK_STATE');
$state = json_decode((string) file_get_contents($statePath), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64d = static fn(string $s): string => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$verify = static function (string $jwt) use ($state, $b64d): ?array {
    [$h, $p, $s] = explode('.', $jwt) + ['', '', ''];
    $header = json_decode($b64d($h), true);
    foreach ($state['keys'] as $k) {
        if ($k['x5t'] === ($header['x5t'] ?? '') && openssl_verify("$h.$p", $b64d($s), $k['pem'], OPENSSL_ALGO_SHA256) === 1) {
            return json_decode($b64d($p), true);
        }
    }
    return null;
};
$save = static function () use (&$state, $statePath): void { file_put_contents($statePath, json_encode($state)); };
header('Content-Type: application/json');
if (str_ends_with($path, '/oauth2/v2.0/token')) {
    if ($verify((string) $_POST['client_assertion']) === null) { http_response_code(401); exit('{"error":"bad assertion"}'); }
    exit('{"access_token":"tok"}');
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok') { http_response_code(401); exit('{}'); }
$body = json_decode((string) file_get_contents('php://input'), true);
if (preg_match('#/applications/[^/]+$#', $path)) {
    exit(json_encode(['keyCredentials' => array_map(static fn($k) => ['keyId' => $k['keyId'], 'customKeyIdentifier' => $k['thumb']], $state['keys'])]));
}
$claims = isset($body['proof']) ? $verify($body['proof']) : null;
if ($claims === null || $claims['aud'] !== '00000002-0000-0000-c000-000000000000') { http_response_code(400); exit('{"error":"bad proof"}'); }
if (str_ends_with($path, '/addKey')) {
    $der = base64_decode($body['keyCredential']['key']);
    $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
    $id = bin2hex(random_bytes(8));
    $state['keys'][] = ['keyId' => $id, 'pem' => $pem, 'x5t' => rtrim(strtr(base64_encode(sha1($der, true)), '+/', '-_'), '='), 'thumb' => strtoupper(sha1($der))];
    $save();
    exit(json_encode(['keyId' => $id]));
}
if (str_ends_with($path, '/removeKey')) {
    $state['keys'] = array_values(array_filter($state['keys'], static fn($k) => $k['keyId'] !== $body['keyId']));
    $save();
    http_response_code(204);
    exit;
}
http_response_code(404);
