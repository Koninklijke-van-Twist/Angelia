<?php

/**
 * Start php -S op een tijdelijke kopie van web/ en test UI + API (sleutel, scopes, CSRF).
 * php tests/api_access_test.php
 */
$dir = sys_get_temp_dir() . '/angelia-web-' . getmypid();
exec('cp -r ' . escapeshellarg(__DIR__ . '/../web') . ' ' . escapeshellarg($dir) . ' && rm -rf ' . escapeshellarg($dir . '/data'));
file_put_contents($dir . '/auth.php', "<?php\n\$allowedUsers = ['tfalken@kvt.nl'];\n\$admins = ['tfalken@kvt.nl'];\n"
    . "\$apiKeys = ['lees' => ['name' => 'lezer', 'scopes' => ['read']], 'onb' => ['name' => 'onboarding', 'scopes' => ['read', 'assign']]];\n");
$port = 18000 + getmypid() % 1000;
$server = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(500000);

$failures = 0;
function check(bool $c, string $l): void { global $failures; echo ($c ? 'ok   ' : 'FAIL ') . "$l\n"; $failures += $c ? 0 : 1; }
function req(string $method, string $path, array $headers = [], ?string $body = null, string $cookie = ''): array
{
    global $port;
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array_merge($headers, $cookie !== '' ? ["Cookie: {$cookie}"] : [])]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $hs), substr($raw, 0, $hs)];
}

try {
    [$c, $page, $hdr] = req('GET', 'index.php');
    check($c === 200 && str_contains($page, 'KVT Germany') && str_contains($page, 'mock-modus'), 'overzicht laadt (lokaal ingelogd), mock-melding');
    preg_match('/PHPSESSID=([^;]+)/', $hdr, $m);
    $cookie = 'PHPSESSID=' . ($m[1] ?? '');
    preg_match('/data-csrf="([a-f0-9]+)"/', $page, $m);
    $csrf = $m[1] ?? '';

    [$c] = req('POST', 'api.php?action=save_group', [], 'name=X&company_id=kvt', $cookie);
    check($c === 403, 'zonder CSRF geweigerd');
    $body = http_build_query(['name' => 'Monteurs', 'company_id' => 'kvt', 'html' => '<p style="color:{{kleur}}">{{naam}} [[mobiel]]{{mobiel}}[[/mobiel]]</p>{{banner}}',
        'text_color' => '#00529B', 'members' => "jan@kvt.nl", 'enabled' => '1', 'banner_link' => 'https://www.kvt.nl']);
    [$c, $b] = req('POST', 'api.php?action=save_group', ["X-CSRF-Token: {$csrf}"], $body, $cookie);
    check($c === 200 && json_decode($b, true)['id'] === 'kvt-monteurs', 'groep aanmaken via UI-API');
    [$c, $b] = req('POST', 'api.php?action=preview', ["X-CSRF-Token: {$csrf}"], $body, $cookie);
    check($c === 200 && str_contains(json_decode($b, true)['html'], 'Jan de Vries'), 'live preview');
    [$c, $page] = req('GET', 'index.php?groep=kvt-monteurs', [], null, $cookie);
    check($c === 200 && str_contains($page, 'Angelia-kvt-monteurs'), 'editor laadt');

    [$c] = req('GET', 'api.php?action=groups', ['X-API-Key: fout']);
    check($c === 401, 'foute API-sleutel 401');
    [$c, $b] = req('GET', 'api.php?action=groups', ['X-API-Key: lees']);
    check($c === 200 && json_decode($b, true)['groups'][0]['id'] === 'kvt-monteurs', 'groepen ophalen met sleutel');
    [$c, $b] = req('GET', 'api.php?action=signature&email=jan@kvt.nl&name=Jan&mobile=0612', ['X-API-Key: lees']);
    $j = json_decode($b, true);
    check($c === 200 && $j['group'] === 'kvt-monteurs' && str_contains($j['html'], '0612') && preg_match('/^\d{1,2} [a-z]+ \d{4}, \d{2}:\d{2}$/', $j['updated_at_text']) === 1, 'handtekening per gebruiker (groep via e-mail)');
    [$c, $b] = req('GET', 'api.php?action=signature&group=kvt-monteurs&format=html', ['X-API-Key: lees']);
    check($c === 200 && str_starts_with($b, '<!-- KVTSIG -->'), 'handtekening als HTML');
    [$c] = req('POST', 'api.php?action=assign', ['X-API-Key: lees', 'Content-Type: application/json'], '{"email":"piet@kvt.nl","group":"kvt-monteurs"}');
    check($c === 403, 'assign zonder scope geweigerd');
    [$c, $b] = req('POST', 'api.php?action=assign', ['X-API-Key: onb', 'Content-Type: application/json'], '{"email":"Piet@kvt.nl","group":"kvt-monteurs"}');
    check($c === 200 && json_decode($b, true)['changed'] === ['+kvt-monteurs'], 'assign (onboarding)');
    $body2 = http_build_query(['name' => 'Kantoor', 'company_id' => 'kvt', 'html' => '<p>{{naam}}</p>', 'text_color' => '#00529B', 'members' => '', 'enabled' => '1']);
    req('POST', 'api.php?action=save_group', ["X-CSRF-Token: {$csrf}"], $body2, $cookie);
    [$c, $b] = req('POST', 'api.php?action=assign', ['X-API-Key: onb', 'Content-Type: application/json'], '{"email":"piet@kvt.nl","group":"kvt-kantoor"}');
    check($c === 200 && json_decode($b, true)['changed'] === ['-kvt-monteurs', '+kvt-kantoor'], 'assign naar andere groep haalt uit de oude (max 1 groep)');
    [$c, $b] = req('POST', 'api.php?action=unassign', ['X-API-Key: onb', 'Content-Type: application/json'], '{"email":"piet@kvt.nl"}');
    check($c === 200 && json_decode($b, true)['changed'] === ['-kvt-kantoor'], 'unassign (offboarding)');
    [$c] = req('GET', 'banner.php?g=../../etc');
    check($c === 404, 'banner.php weigert vreemde id');
} finally {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($dir));
}
echo $failures === 0 ? "\nAlle tests geslaagd.\n" : "\n{$failures} test(s) mislukt.\n";
exit($failures === 0 ? 0 : 1);
