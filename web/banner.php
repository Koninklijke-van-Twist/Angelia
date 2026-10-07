<?php

/**
 * Publieke banner van een groep (vaste URL, geen login): banner.php?g=<groep-id>.
 * Wordt alleen gebruikt als er geen Azure Blob is geconfigureerd.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$id = (string) ($_GET['g'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,100}$/', $id)) {
    http_response_code(404);
    exit;
}
$files = glob(angelia_banner_dir() . '/' . $id . '.*') ?: [];
$file = $files[0] ?? '';
$mime = array_search(pathinfo($file, PATHINFO_EXTENSION), ANGELIA_BANNER_TYPES, true);
if ($file === '' || $mime === false) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=300');
header('Content-Length: ' . filesize($file));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
header('X-Content-Type-Options: nosniff');
readfile($file);
