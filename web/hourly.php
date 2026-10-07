<?php

/**
 * Uurlijkse sync naar Exchange (aangeroepen door run-pages.sh op de server, zoals bij de andere apps).
 * Toegang: localhost of een ingelogde sessie (logincheck.php).
 * Doet alleen iets als er in Angelia iets gewijzigd is sinds de laatste geslaagde sync
 * (version ≠ synced_version of open taken); anders direct een korte JSON.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/bootstrap.php';

set_time_limit(900);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$certWarning = angelia_certificate_warning();
$store = angelia_load();
if (!angelia_is_dirty($store)) {
    angelia_json(['ok' => true, 'skipped' => true, 'reason' => 'Geen wijzigingen sinds de laatste sync.', 'certificate_warning' => $certWarning]);
}

try {
    $result = angelia_run_sync(false);
} catch (AngeliaSyncBusy $e) {
    angelia_json(['ok' => true, 'skipped' => true, 'reason' => $e->getMessage(), 'certificate_warning' => $certWarning]);
} catch (Throwable $e) {
    angelia_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

angelia_json([
    'ok' => $result['errors'] === [],
    'skipped' => false,
    'mode' => $result['mode'],
    'at' => angelia_format_datetime($result['at']),
    'actions' => count($result['actions']),
    'errors' => $result['errors'],
    'problems' => $result['problems'],
    'certificate_warning' => $certWarning,
], $result['errors'] === [] ? 200 : 500);
