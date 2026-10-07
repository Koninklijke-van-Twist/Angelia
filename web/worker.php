<?php

/**
 * Sync-worker (alleen CLI). Voorstel cron (niet automatisch ingericht):
 *   * /5 * * * *  php /pad/naar/web/worker.php            (alleen als er taken in de wachtrij staan)
 *
 *   php worker.php --dry-run     toon wat er zou gebeuren
 *   php worker.php --mock        nooit live, gebruikt web/data/mock_exchange.json
 *   php worker.php --force       ook draaien als de wachtrij leeg is (drift herstellen)
 *   php worker.php --import      alleen-lezen overzicht van groepen/regels uit Exchange ophalen
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Alleen via de commandline.');
}
if (is_file(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$mock = in_array('--mock', $args, true);
$force = in_array('--force', $args, true);

if (in_array('--import', $args, true)) {
    $snap = angelia_import_snapshot($mock);
    echo 'Overzicht opgehaald (' . $snap['mode'] . '): ' . count($snap['groups']) . ' groepen, ' . count($snap['rules']) . " handtekeningregels.\n";
    exit(0);
}

$store = angelia_load();
if (!$force && !$dryRun && $store['queue'] === []) {
    echo "Wachtrij leeg, niets te doen.\n";
    exit(0);
}

$result = angelia_run_sync($dryRun, $mock);
echo 'Modus: ' . $result['mode'] . ($result['dry_run'] ? ' (dry-run)' : '') . ' — ' . angelia_format_datetime($result['at']) . "\n";
foreach ($result['problems'] as $p) {
    echo "LET OP: {$p}\n";
}
foreach ($result['actions'] as $a) {
    echo "- {$a['action']}: {$a['target']}" . ($a['detail'] !== '' ? " ({$a['detail']})" : '') . "\n";
}
if ($result['actions'] === []) {
    echo "Geen wijzigingen nodig.\n";
}
foreach ($result['errors'] as $e) {
    fwrite(STDERR, "FOUT: {$e}\n");
}
exit($result['errors'] === [] ? 0 : 1);
