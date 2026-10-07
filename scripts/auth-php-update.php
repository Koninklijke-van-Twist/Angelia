<?php

/**
 * Hulpscript voor setup-server.sh: auth.php veilig aanmaken/aanvullen.
 *
 *   php auth-php-update.php <auth.php> <auth_TEMPLATE.php> <add|password>
 *   env: ANGELIA_APP_ID, ANGELIA_ORG, PFX_PASSWORD
 *
 * add       Ontbreekt $exchange: blok toevoegen. Bestaat het al: alleen ontbrekende niets doen (exit 3).
 * password  Alleen 'certificate_password' in een bestaand $exchange-blok vervangen.
 *
 * Altijd: bestand begint met <?php, een afsluitende ?> wordt verwijderd (anders komt alles erna
 * als tekst in de browser), backup vooraf, php -l achteraf, bij een fout de backup terug.
 * Exit 0 = gewijzigd, 3 = niets te doen, 1 = fout (backup teruggezet).
 */

[$self, $path, $template, $mode] = $argv + [null, '', '', 'add'];
// Backup en tijdelijk bestand bevatten het wachtwoord: direct 600 aanmaken.
umask(0077);
$password = (string) getenv('PFX_PASSWORD');
if ($password === '') {
    fwrite(STDERR, "PFX_PASSWORD ontbreekt.\n");
    exit(1);
}

$existed = is_file($path);
$original = $existed ? (string) file_get_contents($path) : null;
if ($existed) {
    $source = $original;
} elseif (is_file($template)) {
    // Nieuw vanaf het template, zonder het lege voorbeeld-$exchange-blok.
    $source = (string) file_get_contents($template);
    $source = (string) preg_replace('/^\$exchange\s*=\s*\[.*?^\];\s*$/ms', '', $source);
} else {
    $source = "<?php\n";
}

/* Normaliseren: BOM weg, moet met de openingstag beginnen, geen afsluitende tag. */
$source = (string) preg_replace('/^\xEF\xBB\xBF/', '', $source);
if (!preg_match('/^\s*<\?php\b/', $source)) {
    $source = "<?php\n" . ltrim($source);
}
$source = (string) preg_replace('/\?>\s*$/', '', $source);
$source = rtrim($source) . "\n";

$hasExchange = (bool) preg_match('/^\s*\$exchange\s*=/m', $source);
$pw = var_export($password, true);

if ($mode === 'password') {
    if (!$hasExchange) {
        fwrite(STDERR, "Geen \$exchange-blok in auth.php.\n");
        exit(1);
    }
    // Alleen binnen het $exchange-blok vervangen, niet een eerdere 'certificate_password' elders.
    $count = 0;
    $passwordPattern = "/('certificate_password'\s*=>\s*)(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/";
    $source = (string) preg_replace_callback('/^\s*\$exchange\s*=\s*\[.*?^\s*\];/ms', static function (array $block) use ($pw, $passwordPattern, &$count): string {
        return (string) preg_replace_callback($passwordPattern, static fn(array $m): string => $m[1] . $pw, $block[0], 1, $count);
    }, $source, 1);
    if ($count !== 1) {
        fwrite(STDERR, "'certificate_password' niet gevonden in het \$exchange-blok.\n");
        exit(1);
    }
} elseif ($hasExchange) {
    if ($existed && $source === $original) {
        exit(3);
    }
    /* Alleen normalisatie (openingstag of afsluitende tag) nodig: doorgaan en opslaan. */
} else {
    $source .= "\n\$exchange = [\n"
        . "    'app_id' => " . var_export((string) getenv('ANGELIA_APP_ID'), true) . ",\n"
        . "    'organization' => " . var_export((string) getenv('ANGELIA_ORG'), true) . ",\n"
        . "    'certificate_path' => __DIR__ . '/data/certs/angelia-exo.pfx',\n"
        . "    'certificate_password' => {$pw},\n"
        . "    'certificate_thumbprint' => '',\n"
        . "];\n";
}

$backup = $path . '.bak-' . date('YmdHis');
if ($existed && !copy($path, $backup)) {
    fwrite(STDERR, "Backup maken mislukt.\n");
    exit(1);
}
$tmp = $path . '.tmp-' . getmypid();
if (file_put_contents($tmp, $source) !== strlen($source)) {
    @unlink($tmp);
    fwrite(STDERR, "Schrijven mislukt.\n");
    exit(1);
}
if ($existed) {
    @chmod($tmp, fileperms($path) & 0777);
    @chown($tmp, fileowner($path));
    @chgrp($tmp, filegroup($path));
} else {
    chmod($tmp, 0640);
}
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
if ($code !== 0 || !str_starts_with($source, '<?php')) {
    @unlink($tmp);
    fwrite(STDERR, "auth.php zou ongeldig worden; niets gewijzigd" . ($existed ? " (backup: {$backup})" : '') . ".\n");
    exit(1);
}
if (!rename($tmp, $path)) {
    @unlink($tmp);
    if ($existed) {
        copy($backup, $path);
    }
    fwrite(STDERR, "Vervangen mislukt; backup teruggezet.\n");
    exit(1);
}
// Backup bevat het (oude) wachtwoord: alleen voor root leesbaar.
if ($existed) {
    chmod($backup, 0600);
    echo "Backup: {$backup}\n";
}
exit(0);
