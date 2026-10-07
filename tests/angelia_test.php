<?php

/**
 * Unit-tests zonder framework: php tests/angelia_test.php
 * Draait de echte worker in mock-modus als pwsh beschikbaar is (ANGELIA_PWSH of 'pwsh').
 */
$tmp = sys_get_temp_dir() . '/angelia-test-' . getmypid();
putenv('ANGELIA_DATA_DIR=' . $tmp);
require __DIR__ . '/../web/lib/bootstrap.php';

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

// --- datum
check(angelia_format_datetime(strtotime('2026-10-07T08:30:00Z')) === '7 oktober 2026, 10:30', 'Nederlandse datum in Europe/Amsterdam');
check(angelia_format_datetime(null) === 'nooit', 'lege datum');

// --- store / CRUD
$store = angelia_default_store();
check(count($store['companies']) === 3, 'drie standaardbedrijven');
$cid = angelia_save_company($store, ['name' => 'KVT Gas', 'domain' => 'kvtgas.nl'], 'test');
check($cid === 'kvt-gas', 'bedrijf toevoegen');
try {
    angelia_save_company($store, ['name' => 'X', 'domain' => 'geen domein'], 'test');
    check(false, 'ongeldig domein geweigerd');
} catch (InvalidArgumentException) {
    check(true, 'ongeldig domein geweigerd');
}
$gid = angelia_save_group($store, [
    'name' => 'Kantoor', 'company_id' => 'kvt', 'html' => angelia_default_template(), 'text_color' => '#112233',
    'banner_link' => 'https://www.kvt.nl/actie', 'members' => "B@kvt.nl\na@kvt.nl, a@kvt.nl\nfout", 'enabled' => '1',
], 'test');
check($gid === 'kvt-kantoor', 'groep-id = bedrijf + naam');
$g = angelia_group($store, $gid);
check($g['members'] === ['a@kvt.nl', 'b@kvt.nl'], 'leden genormaliseerd, ontdubbeld, gesorteerd');
check($g['exchange_group'] === 'Angelia-kvt-kantoor', 'exchange-groepnaam');
check(count($store['queue']) === 1, 'opslaan zet taak in wachtrij');
angelia_save_group($store, ['id' => $gid] + $g + ['enabled' => '1'], 'test');
check(count($store['queue']) === 1, 'opnieuw opslaan vervangt taak (geen dubbel)');
try {
    angelia_delete_company($store, 'kvt');
    check(false, 'bedrijf met groepen niet verwijderbaar');
} catch (InvalidArgumentException) {
    check(true, 'bedrijf met groepen niet verwijderbaar');
}
check(angelia_template_problems('<p>{{onbekend}}</p>') !== [], 'onbekende placeholder gemeld');
check(angelia_template_problems('[[telefoon]]x') !== [], 'open blok gemeld');
check(angelia_template_problems(angelia_default_template()) === [], 'standaardtemplate geldig');

// --- weergave per gebruiker
$company = angelia_company($store, 'kvt');
$g['banner_file'] = 'kvt-kantoor.png';
$html = angelia_render_for_user($g, $company, 'https://x/b.png', ['name' => 'Piet <b>', 'email' => 'p@kvt.nl', 'phone' => '078', 'mobile' => '', 'title' => 'Monteur']);
check(str_starts_with($html, ANGELIA_MARKER), 'marker KVTSIG staat bovenaan');
check(str_contains($html, 'Piet &lt;b&gt;'), 'gebruikersdata ge-escaped');
check(str_contains($html, '078') && !str_contains($html, '<strong>Mobiel</strong>'), 'mobiel-blok valt weg zonder nummer');
check(str_contains($html, 'color: #112233'), 'tekstkleur ingevuld');
check(str_contains($html, '<a href="https://www.kvt.nl/actie"><img src="https://x/b.png"'), 'banner met link');

// --- Exchange-varianten
$variants = angelia_exchange_variants($g, $company, 'https://x/b.png');
check(array_keys($variants) === ['Compleet', 'AlleenTel', 'AlleenMobiel', 'GeenTelMobiel'], 'vier varianten bij [[telefoon]]/[[mobiel]]');
check(str_contains($variants['Compleet']['html'], '%%MobileNumber%%') && !str_contains($variants['AlleenTel']['html'], '%%MobileNumber%%'), 'varianten bevatten juiste tokens');
check(str_contains($variants['Compleet']['html'], '%%DisplayName%%') && str_contains($variants['Compleet']['html'], '%%WindowsEmailAddress%%'), 'Exchange-tokens voor naam/e-mail');
$simple = angelia_exchange_variants(['html' => '<p>{{naam}}</p>'] + $g, $company, '');
check(array_keys($simple) === ['Standaard'] && $simple['Standaard']['filter'] === null, 'zonder blokken één variant zonder DDG');

// --- plan
$plan = angelia_build_plan($store);
check($plan['groups'][0]['rules'][0]['name'] === 'Angelia - kvt-kantoor - Compleet', 'regelnaam');
check($plan['groups'][0]['rules'][0]['sender_domain'] === 'kvt.nl', 'SenderDomainIs = bedrijfsdomein');
// --- hoogstens één groep per adres
angelia_save_group($store, ['name' => 'Dubbel', 'company_id' => 'kvt', 'html' => '<p>{{naam}}</p>', 'members' => 'a@kvt.nl', 'enabled' => '1',
    'shared_mailboxes' => "ICT@kvt.nl | Afdeling ICT | Serviceteam | +31 78 000 00 00"], 'test', $moved);
check($moved === ['kvt-kantoor' => ['a@kvt.nl']], 'opslaan verplaatst lid uit andere groep');
check(angelia_group($store, 'kvt-kantoor')['members'] === ['b@kvt.nl'], 'lid weg uit oude groep');
check(angelia_member_conflicts($store) === [], 'geen dubbele lidmaatschappen na opslaan');
$manual = $store;
$manual['groups'][0]['members'][] = 'a@kvt.nl';
$manual['groups'][0]['updated_at'] = 1;
check(count(angelia_member_conflicts($manual)) === 1, 'handmatig dubbel lid gedetecteerd');
$mp = angelia_build_plan($manual);
check(count($mp['problems']) === 1 && $mp['groups'][0]['members'] === ['b@kvt.nl'], 'plan synct dubbel lid alleen in laatst gewijzigde groep');
try {
    angelia_save_group($store, ['name' => 'Fout', 'company_id' => 'kvt', 'html' => '<p>x</p>', 'members' => 'z@kvt.nl', 'shared_mailboxes' => 'z@kvt.nl'], 'test');
    check(false, 'adres als lid én shared mailbox geweigerd');
} catch (InvalidArgumentException) {
    check(true, 'adres als lid én shared mailbox geweigerd');
}
// --- shared mailbox
$dub = angelia_group($store, 'kvt-dubbel');
check($dub['shared_mailboxes'][0]['email'] === 'ict@kvt.nl', 'shared mailbox opgeslagen');
$shared = array_values(array_filter(angelia_build_plan($store)['groups'][1]['rules'], static fn($r) => isset($r['from_address'])));
check(count($shared) === 1 && $shared[0]['from_address'] === 'ict@kvt.nl' && str_contains($shared[0]['html'], 'Afdeling ICT')
    && !str_contains($shared[0]['html'], '%%'), 'shared mailbox: eigen regel met From en statische inhoud');

// --- certificaatwaarschuwing
check(angelia_certificate_warning() === null, 'geen certificaat geconfigureerd: geen waarschuwing');
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$crt = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key), null, $key, 10);
openssl_pkcs12_export_to_file($crt, angelia_data_dir() . '/c.pfx', $key, 'pw');
$GLOBALS['exchange'] = ['certificate_path' => angelia_data_dir() . '/c.pfx', 'certificate_password' => 'pw'];
check(str_contains((string) angelia_certificate_warning(), 'verloopt op'), 'certificaat < 30 dagen: waarschuwing');
check(angelia_certificate_warning(5) === null, 'certificaat > drempel: geen waarschuwing');
$GLOBALS['exchange'] = ['certificate_path' => angelia_data_dir() . '/c.pfx', 'certificate_password' => 'fout'];
check(str_contains((string) angelia_certificate_warning(), 'niet gelezen'), 'onleesbaar certificaat: waarschuwing');
unset($GLOBALS['exchange']);

// --- worker in mock-modus (echt Angelia-Sync.ps1)
$pwsh = getenv('ANGELIA_PWSH') ?: 'pwsh';
$GLOBALS['pwshPath'] = $pwsh;
exec(escapeshellarg($pwsh) . ' -NoProfile -Command "exit 0" 2>/dev/null', $o, $code);
if ($code !== 0) {
    if (getenv('ANGELIA_REQUIRE_PWSH') === '1') {
        check(false, 'pwsh beschikbaar (ANGELIA_REQUIRE_PWSH=1)');
    } else {
        echo "skip worker-tests (pwsh niet gevonden)\n";
    }
} else {
    angelia_transaction(static function (array &$s) use ($store): void { $s = $store; });
    $dry = angelia_run_sync(true, true);
    check($dry['errors'] === [], 'dry-run zonder fouten');
    check(!is_file($tmp . '/mock_exchange.json'), 'dry-run schrijft niets');
    $counts = array_count_values(array_column($dry['actions'], 'action'));
    check(($counts['groep_aanmaken'] ?? 0) === 2 && ($counts['regel_aanmaken'] ?? 0) === 6 && ($counts['ddg_aanmaken'] ?? 0) === 4, 'dry-run: 2 groepen, 4 DDG, 6 regels (incl. shared mailbox)');

    check(angelia_is_dirty(angelia_load()), 'na wijziging: dirty');
    $run = angelia_run_sync(false, true);
    check(!angelia_is_dirty(angelia_load()), 'na geslaagde sync: niet meer dirty');
    $lock = fopen($tmp . '/sync.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        angelia_run_sync(true, true);
        check(false, 'lock: tweede sync tegelijk geweigerd');
    } catch (AngeliaSyncBusy) {
        check(true, 'lock: tweede sync tegelijk geweigerd');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    check($run['errors'] === [] && count($run['actions']) === count($dry['actions']), 'sync voert dezelfde acties uit');
    check(angelia_load()['queue'] === [], 'wachtrij leeg na sync');
    $again = angelia_run_sync(false, true);
    check($again['actions'] === [], 'tweede sync: geen wijzigingen (idempotent)');

    angelia_transaction(static function (array &$s): void {
        $i = angelia_find_index($s['groups'], 'kvt-kantoor');
        $s['groups'][$i]['members'] = ['b@kvt.nl', 'c@kvt.nl'];
        $s['groups'][$i]['html'] = '<p style="color: {{kleur}}">{{naam}}</p>';
    });
    $diff = array_column(angelia_run_sync(false, true)['actions'], 'action');
    sort($diff);
    check($diff === ['ddg_verwijderen', 'ddg_verwijderen', 'ddg_verwijderen', 'ddg_verwijderen', 'lid_toevoegen',
        'regel_aanmaken', 'regel_verwijderen', 'regel_verwijderen', 'regel_verwijderen', 'regel_verwijderen'], 'wijziging: alleen verschillen');

    angelia_transaction(static fn(array &$s) => angelia_delete_group($s, 'kvt-dubbel', 'test'));
    $del = array_column(angelia_run_sync(false, true)['actions'], 'action');
    check(in_array('groep_verwijderen', $del, true) && in_array('regel_verwijderen', $del, true), 'verwijderde groep opgeruimd');
    $state = json_decode((string) file_get_contents($tmp . '/mock_exchange.json'), true);
    check(!isset($state['groups']['Angelia-kvt-dubbel']) && isset($state['groups']['Angelia-kvt-kantoor']), 'mock-state klopt');
    check(angelia_run_sync(false, true)['actions'] === [], 'na verwijderen weer idempotent');
    $snap = angelia_import_snapshot(true);
        check($snap['mode'] === 'mock' && count($snap['groups']) === 1 && count($snap['rules']) === 1, 'import-overzicht (mock)');
}

// --- bestaande handtekeningen importeren uit Exchange
$imp = angelia_default_store();
angelia_save_group($imp, ['name' => 'Bestaand', 'company_id' => 'kvt', 'html' => '<p>{{naam}}</p>', 'members' => 'al@kvt.nl'], 'test');
$snapImp = [
    'at' => time(), 'mode' => 'mock',
    'groups' => [
        ['name' => 'KVT-Sig-Compleet', 'members' => ['Jan@kvt.nl', 'al@kvt.nl', 'piet@kvt.nl']],
        ['name' => 'Angelia-kvt-bestaand', 'members' => ['al@kvt.nl']],
        ['name' => 'DE-Sig', 'members' => ['piet@kvt.nl', 'hans@kvtgermany.de']],
    ],
    'rules' => [
        ['name' => 'Handtekening KVT NL - Compleet', 'enabled' => true, 'html' => "<!-- KVTSIG -->\n<p>%%DisplayName%% %%Title%% %%PhoneNumber%%</p>",
            'from_member_of' => ['KVT-Sig-Compleet'], 'from_addresses' => [], 'domains' => ['kvt.nl']],
        ['name' => 'Angelia-kvt-bestaand', 'enabled' => false, 'html' => '<!-- KVTSIG --><p>x</p>', 'from_member_of' => ['Angelia-kvt-bestaand'], 'domains' => ['kvt.nl']],
        ['name' => 'DE zonder domein', 'enabled' => true, 'html' => '<p>%%DisplayName%%</p>', 'from_member_of' => ['DE-Sig', 'Weg-Groep'], 'from_addresses' => ['info@kvtgermany.de']],
        ['name' => 'Kapot', 'enabled' => true, 'html' => '<p>{{onzin}}</p>', 'from_addresses' => ['x@kvt.nl'], 'domains' => ['kvt.nl']],
    ],
];
$cands = angelia_import_candidates($snapImp, $imp);
$byRule = array_column($cands, null, 'rule');
$nl = $byRule['Handtekening KVT NL - Compleet'];
check($nl['status'] === 'nieuw' && $nl['company_id'] === 'kvt', 'kandidaat: nieuw, bedrijf via SenderDomainIs');
check($nl['html'] === '<p>{{naam}} {{functie}} {{telefoon}}</p>', 'kandidaat: marker weg, Exchange-tokens naar placeholders');
check($nl['members'] === ['al@kvt.nl', 'jan@kvt.nl', 'piet@kvt.nl'], 'kandidaat: leden uit FromMemberOf-groep');
check($byRule['Angelia-kvt-bestaand']['status'] === 'bestaat', 'Angelia-regel gemarkeerd als al in Angelia');
$de = $byRule['DE zonder domein'];
check($de['company_id'] === 'kvt-germany' && $de['shared_mailboxes'] === ['info@kvtgermany.de'], 'bedrijf via meeste adressen, From = shared mailbox');
check($de['missing_groups'] === ['Weg-Groep'], 'ontbrekende groep gemeld');
check($byRule['Kapot']['status'] === 'ongeldig', 'ongeldige template niet importeerbaar');
$rep = angelia_import_apply($imp, $snapImp, array_column($cands, 'key'), 'test');
check(count($rep['created']) === 2 && count($rep['skipped']) === 2, 'import: 2 aangemaakt, 2 overgeslagen');
$created = angelia_group($imp, $rep['created'][0]['id']);
check(empty($created['enabled']) && $created['imported_from'] === 'Handtekening KVT NL - Compleet', 'geimporteerde groep staat uit en onthoudt bron');
check($created['members'] === ['jan@kvt.nl', 'piet@kvt.nl'], 'adres uit bestaande groep niet verplaatst');
check(angelia_group($imp, 'kvt-bestaand')['members'] === ['al@kvt.nl'], 'bestaande groep onaangetast');
$confl = array_map(static fn(array $c): string => $c['email'] . '>' . $c['group'], $rep['conflicts']);
check($confl === ['al@kvt.nl>kvt-bestaand', 'piet@kvt.nl>' . $created['id']], 'conflicten gemeld (max. 1 groep per adres)');
check(angelia_member_conflicts($imp) === [], 'na import geen dubbele adressen');
$again = angelia_import_apply($imp, $snapImp, array_column(angelia_import_candidates($snapImp, $imp), 'key'), 'test');
check($again['created'] === [], 'tweede import maakt niets dubbel');

$sim = angelia_default_store();
angelia_save_group($sim, ['name' => 'Bestaand', 'company_id' => 'kvt', 'html' => '<p>{{naam}}</p>', 'members' => 'al@kvt.nl'], 'test');
$plan = angelia_import_apply($sim, $snapImp, array_column(angelia_import_candidates($snapImp, $sim), "key"), "test");
$sum = angelia_import_summary($sim, $plan);
$sumBy = array_column($sum, null, 'company');
check(count($sumBy['KVT']['groups']) === 1 && $sumBy['KVT']['groups'][0]['members'] === 2, 'samenvatting per bedrijf: KVT 1 groep, 2 leden');
check(count($sumBy['KVT Germany']['groups']) === 1 && count($sumBy['Overgeslagen']['skipped']) === 2, 'samenvatting: KVT Germany + overgeslagen');

exec('rm -rf ' . escapeshellarg($tmp));
echo $failures === 0 ? "\nAlle tests geslaagd.\n" : "\n{$failures} test(s) mislukt.\n";
exit($failures === 0 ? 0 : 1);
