<?php

/**
 * Opslag van bedrijven, groepen en de sync-wachtrij in web/data/ (JSON, met file-lock).
 * web/data/ staat niet in git en wordt door de FTP-deploy overgeslagen.
 */

const ANGELIA_SCHEMA_VERSION = 1;

function angelia_data_dir(): string
{
    $dir = getenv('ANGELIA_DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    return $dir;
}

function angelia_store_path(): string
{
    return angelia_data_dir() . '/angelia.json';
}

/**
 * Standaardinhoud bij eerste start: de drie entiteiten uit de wiki (KVT User Onboarding §2).
 */
function angelia_default_store(): array
{
    return [
        'schema' => ANGELIA_SCHEMA_VERSION,
        'companies' => [
            ['id' => 'kvt', 'name' => 'KVT', 'domain' => 'kvt.nl', 'website' => 'https://www.kvt.nl'],
            ['id' => 'kvt-germany', 'name' => 'KVT Germany', 'domain' => 'kvtgermany.de', 'website' => 'https://www.kvtgermany.de'],
            ['id' => 'hvt', 'name' => 'HVT', 'domain' => 'hunter.be', 'website' => 'https://www.hunter.be'],
        ],
        'groups' => [],
        'queue' => [],
        'last_sync' => null,
    ];
}

/**
 * Leest de store. Zonder bestand: standaardinhoud.
 */
function angelia_load(): array
{
    $path = angelia_store_path();
    if (!is_file($path)) {
        return angelia_default_store();
    }
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('angelia.json is onleesbaar.');
    }
    return $data + angelia_default_store();
}

/**
 * Leest, wijzigt en schrijft de store atomair onder een exclusieve lock.
 * $mutator krijgt de store by-reference; de returnwaarde wordt doorgegeven.
 */
function angelia_transaction(callable $mutator): mixed
{
    $lock = fopen(angelia_data_dir() . '/angelia.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Kan de opslag niet vergrendelen.');
    }
    try {
        $data = angelia_load();
        $result = $mutator($data);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = angelia_store_path() . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException('Kan angelia.json niet schrijven.');
        }
        if (!rename($tmp, angelia_store_path())) {
            @unlink($tmp);
            throw new RuntimeException('Kan angelia.json niet vervangen.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function angelia_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'ë' => 'e', 'è' => 'e', 'ï' => 'i']);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function angelia_find_index(array $items, string $id): ?int
{
    foreach ($items as $i => $item) {
        if (($item['id'] ?? null) === $id) {
            return $i;
        }
    }
    return null;
}

function angelia_company(array $store, string $id): ?array
{
    $i = angelia_find_index($store['companies'], $id);
    return $i === null ? null : $store['companies'][$i];
}

function angelia_group(array $store, string $id): ?array
{
    $i = angelia_find_index($store['groups'], $id);
    return $i === null ? null : $store['groups'][$i];
}

/**
 * Normaliseert een ledenlijst (e-mailadressen, één per regel of komma-gescheiden).
 */
function angelia_parse_members(string|array $input): array
{
    $items = is_array($input) ? $input : (preg_split('/[\s,;]+/', $input) ?: []);
    $out = [];
    foreach ($items as $item) {
        $email = strtolower(trim((string) $item));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[$email] = true;
        }
    }
    $list = array_keys($out);
    sort($list);
    return $list;
}

/**
 * Valideert en bewaart een bedrijf. Geeft de id terug.
 */
function angelia_save_company(array &$store, array $input, string $actor): string
{
    $name = trim((string) ($input['name'] ?? ''));
    $domain = strtolower(trim((string) ($input['domain'] ?? '')));
    if ($name === '') {
        throw new InvalidArgumentException('Naam van het bedrijf is verplicht.');
    }
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        throw new InvalidArgumentException('Ongeldig e-maildomein.');
    }
    $id = trim((string) ($input['id'] ?? ''));
    $record = [
        'name' => $name,
        'domain' => $domain,
        'website' => trim((string) ($input['website'] ?? '')),
        'updated_at' => time(),
        'updated_by' => $actor,
    ];
    if ($id === '') {
        $id = angelia_slugify($name);
        if ($id === '' || angelia_find_index($store['companies'], $id) !== null) {
            throw new InvalidArgumentException('Er bestaat al een bedrijf met deze naam.');
        }
        $store['companies'][] = ['id' => $id] + $record;
    } else {
        $i = angelia_find_index($store['companies'], $id);
        if ($i === null) {
            throw new InvalidArgumentException('Bedrijf niet gevonden.');
        }
        $store['companies'][$i] = $record + $store['companies'][$i];
        foreach ($store['groups'] as $group) {
            if ($group['company_id'] === $id) {
                angelia_enqueue($store, 'upsert_group', $group['id'], $actor);
            }
        }
    }
    return $id;
}

function angelia_delete_company(array &$store, string $id): void
{
    foreach ($store['groups'] as $group) {
        if ($group['company_id'] === $id) {
            throw new InvalidArgumentException('Dit bedrijf heeft nog groepen. Verwijder of verplaats die eerst.');
        }
    }
    $i = angelia_find_index($store['companies'], $id);
    if ($i === null) {
        throw new InvalidArgumentException('Bedrijf niet gevonden.');
    }
    array_splice($store['companies'], $i, 1);
}

/**
 * Valideert en bewaart een groep en zet een sync-taak in de wachtrij. Geeft de id terug.
 */
function angelia_save_group(array &$store, array $input, string $actor): string
{
    $name = trim((string) ($input['name'] ?? ''));
    $companyId = (string) ($input['company_id'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Naam van de groep is verplicht.');
    }
    if (angelia_company($store, $companyId) === null) {
        throw new InvalidArgumentException('Kies een geldig bedrijf.');
    }
    $color = (string) ($input['text_color'] ?? '#00529B');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        throw new InvalidArgumentException('Tekstkleur moet een hexkleur zijn (bv. #00529B).');
    }
    $bannerLink = trim((string) ($input['banner_link'] ?? ''));
    if ($bannerLink !== '' && !preg_match('#^https?://#i', $bannerLink)) {
        throw new InvalidArgumentException('Banner-link moet met http:// of https:// beginnen.');
    }
    $html = (string) ($input['html'] ?? '');
    $problems = angelia_template_problems($html);
    if ($problems !== []) {
        throw new InvalidArgumentException(implode(' ', $problems));
    }

    $id = trim((string) ($input['id'] ?? ''));
    $record = [
        'name' => $name,
        'company_id' => $companyId,
        'html' => $html,
        'text_color' => strtoupper($color),
        'banner_link' => $bannerLink,
        'members' => angelia_parse_members($input['members'] ?? []),
        'enabled' => !empty($input['enabled']),
        'updated_at' => time(),
        'updated_by' => $actor,
    ];
    if ($id === '') {
        $base = angelia_slugify($companyId . '-' . $name);
        if ($base === '') {
            throw new InvalidArgumentException('Ongeldige groepsnaam.');
        }
        $id = $base;
        for ($n = 2; angelia_find_index($store['groups'], $id) !== null; $n++) {
            $id = $base . '-' . $n;
        }
        $record['exchange_group'] = 'Angelia-' . $id;
        $record['banner_file'] = null;
        $record['created_at'] = time();
        $store['groups'][] = ['id' => $id] + $record;
    } else {
        $i = angelia_find_index($store['groups'], $id);
        if ($i === null) {
            throw new InvalidArgumentException('Groep niet gevonden.');
        }
        $store['groups'][$i] = $record + $store['groups'][$i];
    }
    angelia_enqueue($store, 'upsert_group', $id, $actor);
    return $id;
}

function angelia_delete_group(array &$store, string $id, string $actor): void
{
    $i = angelia_find_index($store['groups'], $id);
    if ($i === null) {
        throw new InvalidArgumentException('Groep niet gevonden.');
    }
    $group = $store['groups'][$i];
    array_splice($store['groups'], $i, 1);
    angelia_enqueue($store, 'delete_group', $id, $actor, ['exchange_group' => $group['exchange_group']]);
}

/**
 * Voegt een sync-taak toe. Een nog openstaande taak voor dezelfde groep wordt vervangen.
 */
function angelia_enqueue(array &$store, string $type, string $groupId, string $actor, array $extra = []): void
{
    $store['queue'] = array_values(array_filter($store['queue'], static fn(array $job): bool => $job['group_id'] !== $groupId));
    $store['queue'][] = ['type' => $type, 'group_id' => $groupId, 'queued_at' => time(), 'queued_by' => $actor] + $extra;
}

/**
 * Leden die in meer dan één actieve groep staan (zouden twee handtekeningen krijgen).
 * @return array<string, string[]> e-mail => groep-id's
 */
function angelia_member_conflicts(array $store): array
{
    $seen = [];
    foreach ($store['groups'] as $group) {
        if (empty($group['enabled'])) {
            continue;
        }
        foreach ($group['members'] as $email) {
            $seen[$email][] = $group['id'];
        }
    }
    return array_filter($seen, static fn(array $ids): bool => count($ids) > 1);
}
