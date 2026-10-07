<?php

/**
 * Voert een sync uit: plan schrijven → Angelia-Sync.ps1 (live of mock, eventueel dry-run) → resultaat bewaren.
 *
 * @param bool $dryRun   alleen tonen wat er zou gebeuren
 * @param bool $forceMock nooit live, ook als de Exchange-config compleet is
 */
function angelia_run_sync(bool $dryRun, bool $forceMock = false): array
{
    $startedAt = time();
    $store = angelia_load();
    $plan = angelia_build_plan($store);
    $live = !$forceMock && angelia_exchange_configured();

    $planPath = angelia_data_dir() . '/plan.json';
    file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $result = [
        'at' => $startedAt,
        'mode' => $live ? 'live' : 'mock',
        'dry_run' => $dryRun,
        'actions' => [],
        'errors' => [],
        'problems' => $plan['problems'],
    ];

    $args = ['-PlanPath', $planPath];
    if ($dryRun) {
        $args[] = '-DryRun';
    }
    [$json, $error] = angelia_run_pwsh('Angelia-Sync.ps1', $args, $live);
    if ($error !== null) {
        $result['errors'][] = $error;
    } else {
        $result['actions'] = $json['actions'] ?? [];
        $result['errors'] = array_merge($result['errors'], (array) ($json['errors'] ?? []));
    }

    $loadedJobs = array_map('serialize', $store['queue']);
    angelia_transaction(static function (array &$data) use ($result, $loadedJobs): void {
        if (!$result['dry_run'] && $result['errors'] === []) {
            // Alleen de taken die in het geladen plan zaten zijn verwerkt; nieuwere blijven staan.
            $data['queue'] = array_values(array_filter($data['queue'], static fn(array $job): bool => !in_array(serialize($job), $loadedJobs, true)));
        }
        if ($result['dry_run']) {
            $data['last_dry_run'] = $result;
        } else {
            $data['last_sync'] = $result;
        }
    });
    return $result;
}

/**
 * Start een script uit web/sync/ met PowerShell 7 en geeft [json-uitvoer, foutmelding] terug.
 * Live: certificaat-config via omgevingsvariabelen (nooit op de commandline). Anders mock-state.
 */
function angelia_run_pwsh(string $script, array $args, bool $live): array
{
    $pwsh = (string) angelia_config('pwshPath', 'pwsh');
    $cmd = array_merge([$pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', __DIR__ . '/../sync/' . $script], $args);
    $env = getenv();
    if ($live) {
        $cfg = angelia_config('exchange', []);
        $env['ANGELIA_EXO_APPID'] = (string) $cfg['app_id'];
        $env['ANGELIA_EXO_ORG'] = (string) $cfg['organization'];
        $env['ANGELIA_EXO_CERTPATH'] = (string) ($cfg['certificate_path'] ?? '');
        $env['ANGELIA_EXO_CERTPASS'] = (string) ($cfg['certificate_password'] ?? '');
        $env['ANGELIA_EXO_THUMBPRINT'] = (string) ($cfg['certificate_thumbprint'] ?? '');
    } else {
        $cmd[] = '-MockStatePath';
        $cmd[] = angelia_data_dir() . '/mock_exchange.json';
    }
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        return [null, 'PowerShell (' . $pwsh . ') kon niet gestart worden.'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
    $json = $lines === [] ? null : json_decode((string) end($lines), true);
    if (!is_array($json)) {
        return [null, 'Onverwachte uitvoer van ' . $script . ' (exit ' . $code . '): ' . mb_substr(trim($stderr . ' ' . $stdout), 0, 500)];
    }
    return [$json, null];
}

/**
 * Haalt het alleen-lezen Exchange-overzicht op en bewaart het in web/data/exchange_snapshot.json.
 */
function angelia_import_snapshot(bool $forceMock = false): array
{
    [$json, $error] = angelia_run_pwsh('Angelia-Import.ps1', [], !$forceMock && angelia_exchange_configured());
    if ($error !== null) {
        throw new RuntimeException($error);
    }
    $json['at'] = time();
    file_put_contents(angelia_data_dir() . '/exchange_snapshot.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $json;
}

function angelia_load_snapshot(): ?array
{
    $path = angelia_data_dir() . '/exchange_snapshot.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($data) ? $data : null;
}
