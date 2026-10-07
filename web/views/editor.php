<?php
/** @var array $editGroup @var array $store @var bool $isAdmin @var callable $h */
if (!isset($editGroup, $store)) {
    http_response_code(404);
    exit;
}
$ro = $isAdmin ? '' : ' disabled';
// Tekstvelden readonly i.p.v. disabled, zodat de preview ook voor alleen-lezen gebruikers de inhoud meestuurt.
$roText = $isAdmin ? '' : ' readonly';
$placeholders = [
    '{{naam}}' => 'Weergavenaam (%%DisplayName%%)',
    '{{functie}}' => 'Functie (%%Title%%)',
    '{{email}}' => 'E-mail (%%WindowsEmailAddress%%)',
    '{{telefoon}}' => 'Telefoon (%%PhoneNumber%%)',
    '{{mobiel}}' => 'Mobiel (%%MobileNumber%%)',
    '{{bedrijf}}' => 'Bedrijfsnaam (vast per bedrijf)',
    '{{website}}' => 'Website van het bedrijf',
    '{{kleur}}' => 'Gekozen tekstkleur',
    '{{banner}}' => 'Banner met link',
    '{{banner_url}}' => 'Alleen de banner-URL',
    '[[telefoon]]…[[/telefoon]]' => 'Valt weg zonder telefoonnummer',
    '[[mobiel]]…[[/mobiel]]' => 'Valt weg zonder mobiel nummer',
];
?>
<section class="panel">
    <p><a href="index.php">← Terug naar overzicht</a></p>
    <h2><?= $editGroup['id'] === '' ? 'Nieuwe groep' : 'Groep: ' . $h($editGroup['name']) ?></h2>
    <?php if ($editGroup['id'] !== ''): ?>
        <p class="muted">Exchange-groep <code><?= $h($editGroup['exchange_group']) ?></code> · laatst gewijzigd
            <?= $h(angelia_format_datetime($editGroup['updated_at'] ?? null)) ?> door <?= $h($editGroup['updated_by'] ?? '') ?></p>
    <?php endif; ?>
    <form id="group-form" data-action="save_group" class="editor">
        <input type="hidden" name="id" value="<?= $h($editGroup['id']) ?>">
        <?php if (!$isAdmin): ?>
            <input type="hidden" name="company_id" value="<?= $h($editGroup['company_id']) ?>">
            <input type="hidden" name="text_color" value="<?= $h($editGroup['text_color']) ?>">
            <input type="hidden" name="banner_link" value="<?= $h($editGroup['banner_link']) ?>">
        <?php endif; ?>
        <div class="cols">
            <div>
                <label>Naam<input name="name" value="<?= $h($editGroup['name']) ?>" required<?= $ro ?>></label>
                <label>Bedrijf
                    <select name="company_id"<?= $ro ?>>
                        <?php foreach ($store['companies'] as $c): ?>
                            <option value="<?= $h($c['id']) ?>"<?= $c['id'] === $editGroup['company_id'] ? ' selected' : '' ?>><?= $h($c['name']) ?> (<?= $h($c['domain']) ?>)</option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="inline"><input type="checkbox" name="enabled" value="1"<?= !empty($editGroup['enabled']) ? ' checked' : '' ?><?= $ro ?>> Actief (transportregel aan)</label>
                <label>Tekstkleur <input type="color" name="text_color" value="<?= $h($editGroup['text_color']) ?>"<?= $ro ?>></label>
                <label>Link op de banner (optioneel)<input name="banner_link" type="url" placeholder="https://www.kvt.nl/actie" value="<?= $h($editGroup['banner_link']) ?>"<?= $ro ?>></label>
                <label>Leden (één e-mailadres per regel)
                    <textarea name="members" rows="8"<?= $ro ?>><?= $h(implode("\n", $editGroup['members'])) ?></textarea></label>
                <label>Shared mailboxes (statische handtekening, één per regel:<br><code>adres | naam | functie | telefoon | mobiel</code>)
                    <textarea name="shared_mailboxes" rows="4" placeholder="ict@kvt.nl | Afdeling ICT | Serviceteam | +31 78 123 45 67"<?= $ro ?>><?= $h(implode("\n", array_map(
                        static fn(array $m): string => rtrim(implode(' | ', [$m['email'], $m['name'], $m['title'], $m['phone'], $m['mobile']]), ' |'),
                        $editGroup['shared_mailboxes'] ?? []))) ?></textarea></label>
                <p class="muted">Een adres zit in hoogstens één groep. Opslaan haalt het uit andere groepen.
                    Shared mailboxes krijgen een eigen regel met <code>From</code> (niet in de groep zetten).</p>
            </div>
            <div>
                <label>Handtekening (HTML)
                    <textarea name="html" id="html" rows="22" spellcheck="false"<?= $roText ?>><?= $h($editGroup['html']) ?></textarea></label>
                <details class="placeholders"><summary>Placeholders</summary>
                    <ul><?php foreach ($placeholders as $code => $label): ?>
                        <li><button type="button" class="insert" data-insert="<?= $h($code) ?>"<?= $ro ?>><?= $h($code) ?></button> <?= $h($label) ?></li>
                    <?php endforeach; ?></ul>
                </details>
            </div>
        </div>
        <?php if ($isAdmin): ?>
            <p><button type="submit">Opslaan</button> <span class="muted">Opslaan zet de groep in de wachtrij voor Exchange.</span></p>
        <?php endif; ?>
    </form>

    <?php if ($isAdmin && $editGroup['id'] !== ''): ?>
        <form data-action="upload_banner" enctype="multipart/form-data" class="inline-form">
            <input type="hidden" name="id" value="<?= $h($editGroup['id']) ?>">
            <label>Banner (PNG/JPG/GIF, max 1 MB, ±400 px breed) <input type="file" name="banner" accept="image/png,image/jpeg,image/gif" required></label>
            <button>Uploaden</button>
            <span class="muted"><?= !empty($editGroup['banner_file'])
                ? 'Huidige banner: <a href="' . $h(angelia_banner_url($editGroup)) . '" target="_blank" rel="noopener">vaste URL</a>, geüpload ' . $h(angelia_format_datetime($editGroup['banner_updated_at'] ?? null))
                : 'Nog geen banner.' ?></span>
        </form>
        <form data-action="delete_group" data-confirm="Groep <?= $h($editGroup['name']) ?> verwijderen? De transportregels en de Exchange-groep worden bij de volgende sync verwijderd." data-redirect="index.php">
            <input type="hidden" name="id" value="<?= $h($editGroup['id']) ?>"><button class="danger">Groep verwijderen</button>
        </form>
    <?php endif; ?>

    <h3>Voorbeeld <select id="sample"><option value="">met telefoon en mobiel</option><option value="zonder_nummers">zonder nummers</option></select></h3>
    <div id="preview-problems"></div>
    <iframe id="preview" sandbox title="Voorbeeld handtekening"></iframe>
    <p class="muted" id="preview-length"></p>
</section>
