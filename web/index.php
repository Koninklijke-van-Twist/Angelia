<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/bootstrap.php';

$store = angelia_load();
$isAdmin = angelia_is_admin();
$editId = (string) ($_GET['groep'] ?? '');
$isNew = isset($_GET['nieuw']);
$editGroup = $editId !== '' ? angelia_group($store, $editId) : null;
if ($isNew) {
    $editGroup = [
        'id' => '', 'name' => '', 'company_id' => (string) ($_GET['bedrijf'] ?? ($store['companies'][0]['id'] ?? '')),
        'html' => angelia_default_template(), 'text_color' => '#00529B', 'banner_link' => '', 'members' => [], 'shared_mailboxes' => [],
        'enabled' => false, 'banner_file' => null, 'exchange_group' => '',
    ];
}
$conflicts = angelia_member_conflicts($store);
$h = 'angelia_h';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Angelia – e-mailhandtekeningen</title>
    <link rel="icon" href="favicon.ico">
    <link rel="stylesheet" href="brand.css">
    <link rel="stylesheet" href="angelia.css">
</head>
<body data-csrf="<?= $h(angelia_csrf_token()) ?>">
<header class="top">
    <h1><a href="index.php">Angelia</a></h1>
    <span class="sub">E-mailhandtekeningen (Exchange Online)</span>
    <span class="me"><?= $h(angelia_current_email()) ?><?= $isAdmin ? ' · beheerder' : ' · alleen lezen' ?></span>
</header>
<main>
<?php if (!angelia_exchange_configured()): ?>
    <p class="notice">Exchange is nog niet gekoppeld (geen app-registratie in auth.php). Synchronisatie draait in <strong>mock-modus</strong>: er verandert niets in Exchange.</p>
<?php endif; ?>
<?php foreach ($conflicts as $email => $ids): ?>
    <p class="notice warn"><?= $h($email) ?> staat in meerdere actieve groepen (<?= $h(implode(', ', $ids)) ?>) en krijgt dan meerdere handtekeningen.</p>
<?php endforeach; ?>

<?php if ($editGroup !== null): ?>
    <?php require __DIR__ . '/views/editor.php'; ?>
<?php else: ?>
    <section class="panel">
        <h2>Groepen</h2>
        <?php foreach ($store['companies'] as $company): ?>
            <?php $groups = array_values(array_filter($store['groups'], static fn($g) => $g['company_id'] === $company['id'])); ?>
            <h3><?= $h($company['name']) ?> <small><?= $h($company['domain']) ?></small>
                <?php if ($isAdmin): ?><a class="btn small" href="?nieuw&amp;bedrijf=<?= $h($company['id']) ?>">+ Nieuwe groep</a><?php endif; ?></h3>
            <?php if ($groups === []): ?>
                <p class="muted">Nog geen groepen.</p>
            <?php else: ?>
                <table class="list">
                    <thead><tr><th>Groep</th><th>Exchange-groep</th><th>Leden</th><th>Banner</th><th>Status</th><th>Laatst gewijzigd</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><a href="?groep=<?= $h($g['id']) ?>"><?= $h($g['name']) ?></a></td>
                            <td><code><?= $h($g['exchange_group']) ?></code></td>
                            <td><?= count($g['members']) ?></td>
                            <td><?= !empty($g['banner_file']) ? 'ja' : '–' ?></td>
                            <td><?= !empty($g['enabled']) ? '<span class="tag on">actief</span>' : '<span class="tag off">uit</span>' ?></td>
                            <td><?= $h(angelia_format_datetime($g['updated_at'] ?? null)) ?> <span class="muted"><?= $h($g['updated_by'] ?? '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Bedrijven</h2>
        <table class="list">
            <thead><tr><th>Naam</th><th>E-maildomein</th><th>Website</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($store['companies'] as $c): ?>
                <tr>
                    <?php if ($isAdmin): ?>
                        <td><input form="c-<?= $h($c['id']) ?>" name="name" value="<?= $h($c['name']) ?>" required></td>
                        <td><input form="c-<?= $h($c['id']) ?>" name="domain" value="<?= $h($c['domain']) ?>" required></td>
                        <td><input form="c-<?= $h($c['id']) ?>" name="website" value="<?= $h($c['website'] ?? '') ?>"></td>
                        <td class="actions">
                            <form id="c-<?= $h($c['id']) ?>" data-action="save_company"><input type="hidden" name="id" value="<?= $h($c['id']) ?>"><button>Opslaan</button></form>
                            <form data-action="delete_company" data-confirm="Bedrijf <?= $h($c['name']) ?> verwijderen?"><input type="hidden" name="id" value="<?= $h($c['id']) ?>"><button class="danger">Verwijderen</button></form>
                        </td>
                    <?php else: ?>
                        <td><?= $h($c['name']) ?></td><td><?= $h($c['domain']) ?></td><td><?= $h($c['website'] ?? '') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
                <tr>
                    <td><input form="c-new" name="name" placeholder="Nieuw bedrijf" required></td>
                    <td><input form="c-new" name="domain" placeholder="domein.nl" required></td>
                    <td><input form="c-new" name="website" placeholder="https://…"></td>
                    <td><form id="c-new" data-action="save_company"><button>Toevoegen</button></form></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>Synchronisatie met Exchange</h2>
        <p>Openstaande wijzigingen: <strong><?= count($store['queue']) ?></strong>.
            Laatste sync: <strong><?= $h(angelia_format_datetime($store['last_sync']['at'] ?? null)) ?></strong>
            <?= isset($store['last_sync']) ? '(' . $h($store['last_sync']['mode']) . ', ' . count($store['last_sync']['actions']) . ' wijzigingen, ' . count($store['last_sync']['errors']) . ' fouten)' : '' ?></p>
        <?php foreach (($store['last_sync']['errors'] ?? []) as $e): ?><p class="notice warn"><?= $h($e) ?></p><?php endforeach; ?>
        <?php if ($isAdmin): ?>
            <button id="dry-run">Proefrun (dry-run): wat zou er veranderen?</button>
            <div id="dry-run-result"></div>
        <?php endif; ?>
        <p class="muted">De echte sync draait elk uur via <code>hourly.php</code>, alleen als er iets gewijzigd is.</p>
    </section>

    <?php $snap = angelia_load_snapshot(); ?>
    <section class="panel">
        <h2>Huidige inrichting in Exchange</h2>
        <?php if ($snap === null): ?>
            <p class="muted">Nog niet opgehaald.</p>
        <?php else: ?>
            <p class="muted">Opgehaald <?= $h(angelia_format_datetime($snap['at'] ?? null)) ?> (<?= $h($snap['mode'] ?? '') ?>).</p>
            <?php
            $snapSeen = [];
            foreach ($snap['groups'] as $sg) {
                foreach ((array) $sg['members'] as $em) {
                    $snapSeen[$em][] = $sg['name'];
                }
            }
            foreach (array_filter($snapSeen, static fn(array $n): bool => count($n) > 1) as $em => $names): ?>
                <p class="notice warn">In Exchange staat <?= $h($em) ?> in meerdere handtekening-groepen: <?= $h(implode(', ', $names)) ?>.</p>
            <?php endforeach; ?>
            <h3>Groepen</h3>
            <table class="list"><thead><tr><th>Groep</th><th>Leden</th></tr></thead><tbody>
            <?php foreach ($snap['groups'] as $sg): ?>
                <tr><td><code><?= $h($sg['name']) ?></code></td><td><?= $h(implode(', ', (array) $sg['members'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <h3>Handtekeningregels</h3>
            <table class="list"><thead><tr><th>Regel</th><th>Afzender</th><th>Domein</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($snap['rules'] as $r): ?>
                <tr><td><?= $h($r['name']) ?></td><td><?= $h((string) $r['from']) ?></td><td><?= $h((string) $r['domain']) ?></td>
                    <td><?= !empty($r['enabled']) ? '<span class="tag on">aan</span>' : '<span class="tag off">uit</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php if ($isAdmin): ?><form data-action="import"><button>Ophalen uit Exchange</button></form><?php endif; ?>
    </section>
<?php endif; ?>
</main>
<script src="angelia.js"></script>
</body>
</html>
