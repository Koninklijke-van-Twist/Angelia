<?php

function angelia_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function angelia_current_email(): string
{
    return strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}

function angelia_is_admin(): bool
{
    $admins = array_map(static fn($e): string => strtolower(trim((string) $e)), (array) angelia_config('admins', []));
    $me = angelia_current_email();
    return $me !== '' && in_array($me, $admins, true);
}

function angelia_csrf_token(): string
{
    if (empty($_SESSION['angelia_csrf'])) {
        // login/lib.php sluit de sessie (session_write_close); heropen kort zodat het token bewaard blijft.
        $wasClosed = session_status() !== PHP_SESSION_ACTIVE;
        if ($wasClosed) {
            @session_start();
        }
        if (empty($_SESSION['angelia_csrf'])) {
            $_SESSION['angelia_csrf'] = bin2hex(random_bytes(16));
        }
        if ($wasClosed) {
            session_write_close();
        }
    }
    return (string) $_SESSION['angelia_csrf'];
}

function angelia_check_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(angelia_csrf_token(), $sent)) {
        angelia_json(['ok' => false, 'error' => 'Sessie verlopen, herlaad de pagina.'], 403);
    }
}

/**
 * Controleert X-API-Key tegen $apiKeys (auth.php): ['sleutel' => ['name' => 'onboarding', 'scopes' => ['read', 'assign']]].
 * Geeft de sleutel-config terug of null.
 */
function angelia_api_key_client(): ?array
{
    $sent = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($sent === '') {
        return null;
    }
    foreach ((array) angelia_config('apiKeys', []) as $key => $client) {
        if (is_string($key) && $key !== '' && hash_equals($key, $sent)) {
            return (array) $client + ['name' => 'api', 'scopes' => ['read']];
        }
    }
    return null;
}
