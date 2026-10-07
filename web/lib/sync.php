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
        $groups[] = [
            'id' => $group['id'],
            'exchange_group' => $group['exchange_group'],
            'display_name' => 'Angelia - ' . $company['name'] . ' - ' . $group['name'],
            'enabled' => !empty($group['enabled']),
            'members' => array_values($group['members']),
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
        $problems[] = "{$email} staat in meerdere actieve groepen (" . implode(', ', $ids) . ') en krijgt dan meerdere handtekeningen.';
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
