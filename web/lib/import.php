<?php

/**
 * Bestaande handtekeningen uit Exchange overnemen als (uitgeschakelde) Angelia-groepen.
 * Bron: het overzicht van Angelia-Import.ps1 (web/data/exchange_snapshot.json): transportregels met
 * ApplyHtmlDisclaimerText + de leden van hun FromMemberOf-groepen (ook dynamische groepen).
 */

/** Exchange-token → Angelia-placeholder (omgekeerde van ANGELIA_USER_TOKENS). */
function angelia_import_html(string $html): string
{
    $html = str_ireplace(ANGELIA_MARKER, '', $html);
    foreach (ANGELIA_USER_TOKENS as $name => $token) {
        $html = str_ireplace($token, '{{' . $name . '}}', $html);
    }
    return trim($html);
}

function angelia_import_list(mixed $value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = preg_split('/\s*,\s*/', trim((string) $value)) ?: [];
    }
    return array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $items), static fn(string $v): bool => $v !== ''));
}

/**
 * Kandidaat-groepen uit het Exchange-overzicht, met status per kandidaat.
 * status: 'nieuw' | 'bestaat' (al in Angelia / door Angelia beheerd) | 'ongeldig' (template-fout, geen bedrijf)
 * @return array<int, array<string, mixed>>
 */
function angelia_import_candidates(array $snapshot, array $store): array
{
    $groupMembers = [];
    foreach ((array) ($snapshot['groups'] ?? []) as $g) {
        $groupMembers[strtolower((string) $g['name'])] = angelia_parse_members((array) ($g['members'] ?? []));
    }
    $known = [];
    foreach ($store['groups'] as $g) {
        $known[strtolower((string) $g['exchange_group'])] = $g['id'];
        if (!empty($g['imported_from'])) {
            $known['rule:' . strtolower((string) $g['imported_from'])] = $g['id'];
        }
    }
    $out = [];
    foreach ((array) ($snapshot['rules'] ?? []) as $rule) {
        $name = (string) ($rule['name'] ?? '');
        $html = (string) ($rule['html'] ?? '');
        if ($name === '') {
            continue;
        }
        $memberOf = angelia_import_list($rule['from_member_of'] ?? []);
        $fromAddresses = angelia_parse_members(angelia_import_list($rule['from_addresses'] ?? []));
        $domains = array_map('strtolower', angelia_import_list($rule['domains'] ?? ($rule['domain'] ?? '')));
        $members = [];
        $missingGroups = [];
        foreach ($memberOf as $gName) {
            if (isset($groupMembers[strtolower($gName)])) {
                $members = array_merge($members, $groupMembers[strtolower($gName)]);
            } else {
                $missingGroups[] = $gName;
            }
        }
        $members = angelia_parse_members(array_values(array_diff($members, $fromAddresses)));

        // Bedrijf: eerst SenderDomainIs, anders het domein van de meeste adressen.
        $company = null;
        foreach ($domains as $d) {
            foreach ($store['companies'] as $c) {
                if (strtolower($c['domain']) === $d) {
                    $company = $c;
                    break 2;
                }
            }
        }
        if ($company === null) {
            $count = [];
            foreach (array_merge($members, $fromAddresses) as $em) {
                $d = substr($em, strpos($em, '@') + 1);
                $count[$d] = ($count[$d] ?? 0) + 1;
            }
            arsort($count);
            foreach (array_keys($count) as $d) {
                foreach ($store['companies'] as $c) {
                    if (strtolower($c['domain']) === $d) {
                        $company = $c;
                        break 2;
                    }
                }
            }
        }

        $existing = $known['rule:' . strtolower($name)] ?? null;
        if ($existing === null && str_starts_with(strtolower($name), 'angelia-')) {
            $existing = $known[strtolower($name)] ?? '(Angelia-regel)';
        }
        foreach ($memberOf as $gName) {
            if ($existing === null && isset($known[strtolower($gName)])) {
                $existing = $known[strtolower($gName)];
            }
        }
        $converted = angelia_import_html($html);
        $problems = angelia_template_problems($converted);
        if ($company === null) {
            $problems[] = 'Geen bedrijf gevonden bij het afzenderdomein.';
        }
        if ($members === [] && $fromAddresses === []) {
            $problems[] = 'Geen leden of shared mailboxes gevonden (regel geldt mogelijk voor het hele domein).';
        }
        $out[] = [
            'key' => substr(hash('sha256', $name), 0, 16),
            'rule' => $name,
            'enabled_in_exchange' => !empty($rule['enabled']),
            'has_marker' => stripos($html, ANGELIA_MARKER) !== false,
            'html' => $converted,
            'member_of' => $memberOf,
            'missing_groups' => $missingGroups,
            'members' => $members,
            'shared_mailboxes' => $fromAddresses,
            'domains' => $domains,
            'company_id' => $company['id'] ?? null,
            'company_name' => $company['name'] ?? null,
            'existing_group' => $existing,
            'problems' => $problems,
            'status' => $existing !== null ? 'bestaat' : ($problems === [] ? 'nieuw' : 'ongeldig'),
        ];
    }
    return $out;
}

/**
 * Maakt uitgeschakelde Angelia-groepen voor de gekozen kandidaten (op 'key').
 * Adressen die al in een Angelia-groep (of in een eerder gekozen kandidaat) zitten worden NIET verplaatst
 * maar overgeslagen en als conflict gemeld (hoogstens één groep per adres).
 * @return array{created: array<int, array{id: string, rule: string}>, skipped: array<int, array{rule: string, reason: string}>, conflicts: array<int, array{email: string, rule: string, group: string}>}
 */
function angelia_import_apply(array &$store, array $snapshot, array $keys, string $actor): array
{
    $result = ['created' => [], 'skipped' => [], 'conflicts' => []];
    $taken = [];
    foreach ($store['groups'] as $g) {
        foreach (angelia_group_addresses($g) as $em) {
            $taken[$em] = $g['id'];
        }
    }
    foreach (angelia_import_candidates($snapshot, $store) as $cand) {
        if (!in_array($cand['key'], $keys, true)) {
            continue;
        }
        if ($cand['status'] !== 'nieuw') {
            $result['skipped'][] = ['rule' => $cand['rule'], 'reason' => $cand['status'] === 'bestaat'
                ? 'Staat al in Angelia (' . $cand['existing_group'] . ').' : implode(' ', $cand['problems'])];
            continue;
        }
        $keep = static function (array $emails) use (&$taken, &$result, $cand): array {
            $ok = [];
            foreach ($emails as $em) {
                if (isset($taken[$em])) {
                    $result['conflicts'][] = ['email' => $em, 'rule' => $cand['rule'], 'group' => $taken[$em]];
                } else {
                    $ok[] = $em;
                }
            }
            return $ok;
        };
        $members = $keep($cand['members']);
        $shared = $keep($cand['shared_mailboxes']);
        $id = angelia_save_group($store, [
            'name' => $cand['rule'],
            'company_id' => $cand['company_id'],
            'html' => $cand['html'],
            'members' => $members,
            'shared_mailboxes' => $shared,
            'enabled' => '',
        ], $actor);
        $i = angelia_find_index($store['groups'], $id);
        $store['groups'][$i]['imported_from'] = $cand['rule'];
        $store['groups'][$i]['imported_at'] = time();
        foreach (array_merge($members, $shared) as $em) {
            $taken[$em] = $id;
        }
        $result['created'][] = ['id' => $id, 'rule' => $cand['rule']];
    }
    return $result;
}
