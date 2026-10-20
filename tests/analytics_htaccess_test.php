<?php

/**
 * .htaccess in web/analytics/ weigert sqlite-bestanden en laat analytics.php bereikbaar.
 * php tests/analytics_htaccess_test.php
 */
define('ANALYTICS_LIBRARY_ONLY', true);
require __DIR__ . '/../web/analytics/analytics.php';

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

function htaccess_pattern(string $body): string
{
    if (!preg_match('/<FilesMatch "(.+)">/', $body, $match)) {
        return '';
    }

    return '#' . $match[1] . '#';
}

$root = sys_get_temp_dir() . '/angelia-analytics-htaccess-' . getmypid();
mkdir($root);

$missing = $root . '/missing';
mkdir($missing);
analytics_ensure_htaccess($missing);
$body = (string) file_get_contents($missing . '/.htaccess');
$pattern = htaccess_pattern($body);
check(is_file($missing . '/.htaccess'), 'ontbrekende .htaccess wordt aangemaakt');
check(str_contains($body, 'Require all denied'), 'sqlite-bestanden worden geweigerd');
check($pattern !== '', 'FilesMatch-patroon aanwezig');
check(preg_match($pattern, 'analytics.php') !== 1, 'analytics.php valt niet onder het weigerpatroon');
check(preg_match($pattern, 'rules.md') !== 1, 'rules.md valt niet onder het weigerpatroon');
foreach (['analytics.sqlite', 'analytics.sqlite-wal', 'analytics.sqlite-shm', 'analytics.sqlite-journal'] as $name) {
    check(preg_match($pattern, $name) === 1, $name . ' valt onder het weigerpatroon');
}

$created = file_get_contents($missing . '/.htaccess');
analytics_ensure_htaccess($missing);
check(file_get_contents($missing . '/.htaccess') === $created, 'bestaande sqlite-regel blijft ongewijzigd');

$kept = $root . '/kept';
mkdir($kept);
file_put_contents($kept . '/.htaccess', "# bestaand\nOptions -Indexes\n");
analytics_ensure_htaccess($kept);
$merged = (string) file_get_contents($kept . '/.htaccess');
check(str_starts_with($merged, "# bestaand\nOptions -Indexes\n"), 'bestaande regels blijven staan');
check(str_contains($merged, 'Require all denied'), 'sqlite-weigering wordt toegevoegd');
$mergedAgain = $merged;
analytics_ensure_htaccess($kept);
check(file_get_contents($kept . '/.htaccess') === $mergedAgain, 'aanvullen gebeurt maar één keer');

$absent = $root . '/no-such-dir';
analytics_ensure_htaccess($absent);
check(!is_dir($absent), 'ontbrekende map wordt niet aangemaakt');

$repoHtaccess = (string) file_get_contents(__DIR__ . '/../web/analytics/.htaccess');
check($repoHtaccess === $created, 'gecommitte .htaccess is gelijk aan wat analytics.php schrijft');

exec('rm -rf ' . escapeshellarg($root));

echo $failures === 0 ? "OK\n" : "FAILED {$failures}\n";
exit($failures === 0 ? 0 : 1);
