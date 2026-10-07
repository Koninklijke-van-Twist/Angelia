<?php

/**
 * Bouwt het gewenste Exchange-plan (desired state) uit de store. Het PowerShell-script
 * sync/Angelia-Sync.ps1 vergelijkt dat met Exchange Online en voert alleen verschillen uit.
 */

const ANGELIA_RULE_PREFIX = 'Angelia - ';

function angelia_rule_name(string $groupId, string $variant): string
{
    return ANGELIA_RULE_PREFIX . $groupId . ' - ' . $variant;
}

function angelia_build_plan(array $store): array
{
    $groups = [];
    $problems = [];
    // Hoogstens één groep per adres: bij een conflict wint de laatst gewijzigde groep.
    $owner = [];
    $byRecent = $store['groups'];
    usort($byRecent, static fn(array $a, array $b): int => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
    foreach ($byRecent as $g) {
        foreach (angelia_group_addresses($g) as $email) {
            $owner[$email] ??= $g['id'];
        }
    }
    foreach ($store['groups'] as $group) {
        $company = angelia_company($store, $group['company_id']);
        if ($company === null) {
            $problems[] = "Groep {$group['id']}: bedrijf ontbreekt.";
            continue;
        }
        $rules = [];
        foreach (angelia_exchange_variants($group, $company, angelia_banner_url($group)) as $variant => $v) {
            if (mb_strlen($v['html']) > ANGELIA_MAX_DISCLAIMER_CHARS) {
                $problems[] = "Groep {$group['id']} ({$variant}): HTML is langer dan " . ANGELIA_MAX_DISCLAIMER_CHARS . ' tekens.';
            }
            $rules[] = [
                'name' => angelia_rule_name($group['id'], $variant),
                'variant' => $variant,
                'ddg' => $v['filter'] === null ? null : [
                    'name' => $group['exchange_group'] . '-' . $variant,
                    'filter' => $v['filter'],
                ],
                'sender_domain' => $company['domain'],
                'html' => $v['html'],
            ];
        }
        foreach ($group['shared_mailboxes'] ?? [] as $mb) {
            if ($owner[$mb['email']] !== $group['id']) {
                continue;
            }
            $html = angelia_render_for_user($group, $company, angelia_banner_url($group), $mb);
            $rules[] = [
                'name' => angelia_rule_name($group['id'], 'Shared ' . $mb['email']),
                'variant' => 'Shared',
                'ddg' => null,
                'from_address' => $mb['email'],
                'sender_domain' => substr(strrchr($mb['email'], '@'), 1),
                'html' => $html,
            ];
        }
        $groups[] = [
            'id' => $group['id'],
            'exchange_group' => $group['exchange_group'],
            'display_name' => 'Angelia - ' . $company['name'] . ' - ' . $group['name'],
            'enabled' => !empty($group['enabled']),
            'members' => array_values(array_filter($group['members'], static fn(string $e): bool => $owner[$e] === $group['id'])),
            'rules' => $rules,
        ];
    }
    $deleted = [];
    foreach ($store['queue'] as $job) {
        if ($job['type'] === 'delete_group') {
            $deleted[] = ['id' => $job['group_id'], 'exchange_group' => $job['exchange_group']];
        }
    }
    foreach (angelia_member_conflicts($store) as $email => $ids) {
        $problems[] = "{$email} staat in meerdere groepen (" . implode(', ', $ids) . "); alleen {$owner[$email]} (laatst gewijzigd) wordt gesynchroniseerd.";
    }
    return [
        'generated_at' => time(),
        'rule_prefix' => ANGELIA_RULE_PREFIX,
        'marker_word' => ANGELIA_MARKER_WORD,
        'groups' => $groups,
        'deleted' => $deleted,
        'problems' => $problems,
    ];
}

/**
 * Live-modus alleen als de Exchange-app-registratie in auth.php staat én dry-run niet gevraagd is.
 */
function angelia_exchange_configured(): bool
{
    $cfg = angelia_config('exchange', []);
    return is_array($cfg) && ($cfg['app_id'] ?? '') !== '' && ($cfg['organization'] ?? '') !== ''
        && (($cfg['certificate_thumbprint'] ?? '') !== '' || ($cfg['certificate_path'] ?? '') !== '');
}
