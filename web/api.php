<?php

/**
 * Angelia API.
 *
 * Met X-API-Key (zie $apiKeys in auth.php), zonder Entra-login:
 *   GET  ?action=groups                               lijst van groepen
 *   GET  ?action=signature&group=<id>[&format=html|json|exchange]
 *        optioneel per gebruiker: &email=…&name=…&title=…&phone=…&mobile=…
 *        Zonder group maar met email: de groep waar het adres lid van is.
 *   POST ?action=assign    {"email": "…", "group": "<id>"}   (scope 'assign') – zet in die groep, haalt uit andere
 *   POST ?action=unassign  {"email": "…"}                      (scope 'assign') – uit alle groepen (offboarding)
 *
 * Met Entra-sessie (UI, beheerders, CSRF): save_company, delete_company, save_group, delete_group,
 * upload_banner, preview, dry_run, import (alleen-lezen overzicht uit Exchange),
 * import_preview (ophalen + plan per bedrijf), import_groups (keys[] uit het overzicht → uitgeschakelde Angelia-groepen; verandert niets in Exchange-regels).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/bootstrap.php';

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $client = angelia_api_key_client();
    if ($client === null) {
        angelia_json(['ok' => false, 'error' => 'Ongeldige API-sleutel.'], 401);
    }
    $actor = 'api:' . $client['name'];
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : $_POST;
    try {
        angelia_handle_api($action, $method, $client, $actor, $body);
    } catch (InvalidArgumentException $e) {
        angelia_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

require_once __DIR__ . '/logincheck.php';
if ($action !== 'preview' && !angelia_is_admin()) {
    angelia_json(['ok' => false, 'error' => 'Alleen beheerders mogen dit.'], 403);
}
if ($method !== 'POST') {
    angelia_json(['ok' => false, 'error' => 'Gebruik POST.'], 405);
}
angelia_check_csrf();
$actor = angelia_current_email();

try {
    switch ($action) {
        case 'save_company':
            $id = angelia_transaction(static fn(array &$s) => angelia_save_company($s, $_POST, $actor));
            angelia_json(['ok' => true, 'id' => $id]);
        case 'delete_company':
            angelia_transaction(static fn(array &$s) => angelia_delete_company($s, (string) ($_POST['id'] ?? '')));
            angelia_json(['ok' => true]);
        case 'save_group':
            [$id, $moved] = angelia_transaction(static function (array &$s) use ($actor): array {
                $id = angelia_save_group($s, $_POST, $actor, $moved);
                return [$id, $moved ?? []];
            });
            angelia_json(['ok' => true, 'id' => $id, 'moved' => $moved]);
        case 'delete_group':
            angelia_transaction(static fn(array &$s) => angelia_delete_group($s, (string) ($_POST['id'] ?? ''), $actor));
            angelia_json(['ok' => true]);
        case 'upload_banner':
            $id = (string) ($_POST['id'] ?? '');
            $upload = $_FILES['banner'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Geen bestand ontvangen.');
            }
            [$bytes, $mime, $ext, $w, $h] = angelia_validate_banner((string) $upload['tmp_name'], (int) $upload['size']);
            $url = angelia_transaction(static function (array &$s) use ($id, $bytes, $mime, $ext, $actor): string {
                $i = angelia_find_index($s['groups'], $id);
                if ($i === null) {
                    throw new InvalidArgumentException('Groep niet gevonden.');
                }
                $s['groups'][$i]['banner_file'] = angelia_store_banner($id, $bytes, $mime, $ext);
                $s['groups'][$i]['banner_updated_at'] = time();
                $s['groups'][$i]['banner_updated_by'] = $actor;
                // De URL blijft gelijk zolang het bestandstype gelijk blijft; sync zet de regel zo nodig bij.
                angelia_enqueue($s, 'upsert_group', $id, $actor);
                return angelia_banner_url($s['groups'][$i]);
            });
            angelia_json(['ok' => true, 'url' => $url, 'width' => $w, 'height' => $h]);
        case 'preview':
            $store = angelia_load();
            $company = angelia_company($store, (string) ($_POST['company_id'] ?? '')) ?? $store['companies'][0];
            $existing = angelia_group($store, (string) ($_POST['id'] ?? '')) ?? [];
            $group = [
                'html' => (string) ($_POST['html'] ?? ''),
                'text_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['text_color'] ?? '')) ? $_POST['text_color'] : '#00529B',
                'banner_link' => (string) ($_POST['banner_link'] ?? ''),
                'banner_file' => $existing['banner_file'] ?? null,
                'id' => $existing['id'] ?? '',
            ];
            $sample = angelia_sample_user($company);
            if (($_POST['sample'] ?? '') === 'zonder_nummers') {
                $sample['phone'] = $sample['mobile'] = '';
            }
            $html = angelia_render_for_user($group, $company, angelia_banner_url($group), $sample);
            angelia_json([
                'ok' => true,
                'html' => $html,
                'problems' => angelia_template_problems($group['html']),
                'length' => mb_strlen($html),
                'max_length' => ANGELIA_MAX_DISCLAIMER_CHARS,
            ]);
        case 'import':
            $snap = angelia_import_snapshot();
            angelia_json(['ok' => true, 'groups' => count($snap['groups']), 'rules' => count($snap['rules'])]);
        case 'import_preview':
            $snap = angelia_import_snapshot();
            $sim = angelia_load();
            $plan = angelia_import_apply($sim, $snap, array_column(angelia_import_candidates($snap, $sim), 'key'), $actor);
            angelia_json(['ok' => true, 'at_text' => angelia_format_datetime($snap['at']), 'summary' => angelia_import_summary($sim, $plan)]
                + ['keys' => array_column(angelia_import_candidates($snap, angelia_load()), 'key')]);
        case 'import_groups':
            $snap = angelia_load_snapshot();
            if ($snap === null) {
                throw new InvalidArgumentException('Haal eerst het overzicht op uit Exchange.');
            }
            $keys = array_values(array_map('strval', (array) ($_POST['keys'] ?? [])));
            if ($keys === []) {
                throw new InvalidArgumentException('Kies minstens één handtekening om te importeren.');
            }
            $report = angelia_transaction(static fn(array &$s) => angelia_import_apply($s, $snap, $keys, $actor));
            angelia_json(['ok' => true, 'report' => $report]);
        case 'dry_run':
            $result = angelia_run_sync(true);
            angelia_json(['ok' => true, 'result' => $result, 'at_text' => angelia_format_datetime($result['at'])]);
        default:
            angelia_json(['ok' => false, 'error' => 'Onbekende actie.'], 400);
    }
} catch (InvalidArgumentException $e) {
    angelia_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    angelia_json(['ok' => false, 'error' => 'Er ging iets mis: ' . $e->getMessage()], 500);
}

/**
 * API-sleutel-acties.
 */
function angelia_handle_api(string $action, string $method, array $client, string $actor, array $body): never
{
    $scopes = (array) ($client['scopes'] ?? []);
    $store = angelia_load();
    switch ($action) {
        case 'groups':
            $out = [];
            foreach ($store['groups'] as $g) {
                $c = angelia_company($store, $g['company_id']);
                $out[] = [
                    'id' => $g['id'], 'name' => $g['name'], 'company' => $c['name'] ?? null, 'domain' => $c['domain'] ?? null,
                    'exchange_group' => $g['exchange_group'], 'enabled' => !empty($g['enabled']), 'member_count' => count($g['members']),
                ];
            }
            angelia_json(['ok' => true, 'groups' => $out]);
        case 'signature':
            $email = strtolower(trim((string) ($_GET['email'] ?? '')));
            $groupId = (string) ($_GET['group'] ?? '');
            if ($groupId === '' && $email !== '') {
                foreach ($store['groups'] as $g) {
                    if (in_array($email, $g['members'], true)) {
                        $groupId = $g['id'];
                        break;
                    }
                }
            }
            $group = angelia_group($store, $groupId);
            if ($group === null) {
                angelia_json(['ok' => false, 'error' => 'Groep niet gevonden.'], 404);
            }
            $company = angelia_company($store, $group['company_id']);
            $bannerUrl = angelia_banner_url($group);
            $format = (string) ($_GET['format'] ?? 'json');
            if ($format === 'exchange') {
                angelia_json(['ok' => true, 'group' => $group['id'], 'variants' => angelia_exchange_variants($group, $company, $bannerUrl)]);
            }
            $user = [
                'email' => $email,
                'name' => (string) ($_GET['name'] ?? ''),
                'title' => (string) ($_GET['title'] ?? ''),
                'phone' => (string) ($_GET['phone'] ?? ''),
                'mobile' => (string) ($_GET['mobile'] ?? ''),
            ];
            $html = angelia_render_for_user($group, $company, $bannerUrl, $user);
            if ($format === 'html') {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
                echo $html;
                exit;
            }
            angelia_json([
                'ok' => true, 'group' => $group['id'], 'exchange_group' => $group['exchange_group'],
                'company' => $company['name'], 'html' => $html, 'banner_url' => $bannerUrl,
                'banner_link' => $group['banner_link'], 'updated_at' => $group['updated_at'],
                'updated_at_text' => angelia_format_datetime($group['updated_at']),
            ]);
        case 'assign':
        case 'unassign':
            if ($method !== 'POST') {
                angelia_json(['ok' => false, 'error' => 'Gebruik POST.'], 405);
            }
            if (!in_array('assign', $scopes, true)) {
                angelia_json(['ok' => false, 'error' => 'Deze sleutel mag geen leden wijzigen.'], 403);
            }
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig e-mailadres.');
            }
            $target = $action === 'assign' ? (string) ($body['group'] ?? '') : '';
            $changed = angelia_transaction(static function (array &$s) use ($email, $target, $actor): array {
                if ($target !== '' && angelia_find_index($s['groups'], $target) === null) {
                    throw new InvalidArgumentException('Groep niet gevonden.');
                }
                // Hoogstens één groep per adres: eerst uit alle andere groepen (ook als shared mailbox).
                $changed = array_map(static fn(string $id): string => '-' . $id, array_keys(angelia_remove_from_other_groups($s, [$email], $target, $actor)));
                $i = $target === '' ? null : angelia_find_index($s['groups'], $target);
                if ($i !== null && !in_array($email, angelia_group_addresses($s['groups'][$i]), true)) {
                    $s['groups'][$i]['members'] = angelia_parse_members(array_merge($s['groups'][$i]['members'], [$email]));
                    $s['groups'][$i]['updated_at'] = time();
                    angelia_enqueue($s, 'upsert_group', $target, $actor);
                    $changed[] = '+' . $target;
                }
                return $changed;
            });
            angelia_json(['ok' => true, 'changed' => $changed, 'note' => 'Wordt bij de volgende sync in Exchange doorgevoerd.']);
        default:
            angelia_json(['ok' => false, 'error' => 'Onbekende actie.'], 400);
    }
}
