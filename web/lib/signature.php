<?php

/**
 * Handtekening-templates: placeholders, optionele blokken, Exchange-varianten en weergave per gebruiker.
 *
 * Placeholders in de template (hoofdletterongevoelig):
 *   {{naam}} {{email}} {{telefoon}} {{mobiel}} {{functie}}   → per gebruiker (Exchange-token)
 *   {{bedrijf}} {{website}} {{kleur}} {{banner}} {{banner_url}} {{banner_link}} → vast per groep/bedrijf
 * Optionele blokken: [[telefoon]]…[[/telefoon]] en [[mobiel]]…[[/mobiel]] vallen weg als het nummer leeg is.
 */

const ANGELIA_MARKER = '<!-- KVTSIG -->';
const ANGELIA_MARKER_WORD = 'KVTSIG';
const ANGELIA_MAX_DISCLAIMER_CHARS = 5000;

/** Placeholder → Exchange-token (ApplyHtmlDisclaimerText). Zie wiki KVT User Onboarding §5.2. */
const ANGELIA_USER_TOKENS = [
    'naam' => '%%DisplayName%%',
    'email' => '%%WindowsEmailAddress%%',
    'telefoon' => '%%PhoneNumber%%',
    'mobiel' => '%%MobileNumber%%',
    'functie' => '%%Title%%',
];

const ANGELIA_STATIC_TOKENS = ['bedrijf', 'website', 'kleur', 'banner', 'banner_url', 'banner_link'];

/**
 * Varianten (zoals Set-KvtSignatures.ps1): welke optionele blokken getoond worden + DDG-filter.
 */
const ANGELIA_VARIANTS = [
    'Compleet' => ['telefoon' => true, 'mobiel' => true, 'filter' => "(Phone -ne \$null) -and (Phone -ne '') -and (MobilePhone -ne \$null) -and (MobilePhone -ne '')"],
    'AlleenTel' => ['telefoon' => true, 'mobiel' => false, 'filter' => "(Phone -ne \$null) -and (Phone -ne '') -and ((MobilePhone -eq \$null) -or (MobilePhone -eq ''))"],
    'AlleenMobiel' => ['telefoon' => false, 'mobiel' => true, 'filter' => "((Phone -eq \$null) -or (Phone -eq '')) -and (MobilePhone -ne \$null) -and (MobilePhone -ne '')"],
    'GeenTelMobiel' => ['telefoon' => false, 'mobiel' => false, 'filter' => "((Phone -eq \$null) -or (Phone -eq '')) -and ((MobilePhone -eq \$null) -or (MobilePhone -eq ''))"],
];

function angelia_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function angelia_default_template(): string
{
    // Overgenomen uit Get-SigHtml in Set-KvtSignatures.ps1 (entiteit NL), met Angelia-placeholders.
    return <<<'HTML'
<br /><br />
<table cellpadding="0" cellspacing="0" border="0" style="font-family: Verdana, Geneva, sans-serif; border-collapse: collapse; max-width: 600px;">
  <tr><td colspan="2" style="font-size: 11pt; color: {{kleur}}; padding-bottom: 12px;">Met vriendelijke groet,</td></tr>
  <tr><td colspan="2" style="font-size: 14pt; font-weight: bold; color: {{kleur}}; padding-bottom: 2px;">{{naam}}</td></tr>
  <tr><td colspan="2" style="font-size: 11pt; color: #0099CC; padding-bottom: 12px;">{{functie}}</td></tr>
  <tr><td colspan="2" style="padding-bottom: 12px;"><img src="https://sakvthandtekeningen.blob.core.windows.net/signatures/Logo.gif" alt="{{bedrijf}}" width="400" style="display:block; border:0;" /></td></tr>
  <tr>
    <td valign="top" style="font-size: 9pt; color: {{kleur}}; padding-right: 40px; padding-bottom: 12px; line-height: 1.6;">
      <strong>Bezoekadres</strong><br />Keerweer 62<br />3316KA Dordrecht<br />Nederland
    </td>
    <td valign="top" style="font-size: 9pt; color: {{kleur}}; padding-bottom: 12px; line-height: 1.6;">
      <table cellpadding="0" cellspacing="0" border="0" style="font-family: Verdana, Geneva, sans-serif; font-size: 9pt; color: {{kleur}}; border-collapse: collapse;">
        [[telefoon]]<tr><td style="padding-right:10px; padding-bottom:2px;"><strong>Telefoon</strong></td><td style="padding-bottom:2px;">{{telefoon}}</td></tr>[[/telefoon]]
        [[mobiel]]<tr><td style="padding-right:10px; padding-bottom:2px;"><strong>Mobiel</strong></td><td style="padding-bottom:2px;">{{mobiel}}</td></tr>[[/mobiel]]
        <tr><td style="padding-right:10px; padding-bottom:2px;"><strong>E-mail</strong></td><td style="padding-bottom:2px;"><a href="mailto:{{email}}" style="color:{{kleur}}; text-decoration:none;">{{email}}</a></td></tr>
        <tr><td style="padding-right:10px;"><strong>Web</strong></td><td><a href="{{website}}" style="color:{{kleur}}; text-decoration:none;">{{website}}</a></td></tr>
      </table>
    </td>
  </tr>
  <tr><td colspan="2" style="border-top: 1px solid {{kleur}}; padding-top: 12px;"></td></tr>
  <tr><td colspan="2" style="padding-top: 4px;">{{banner}}</td></tr>
</table>
HTML;
}

function angelia_template_has_block(string $html, string $block): bool
{
    return (bool) preg_match('/\[\[' . preg_quote($block, '/') . '\]\]/i', $html);
}

/**
 * Controleert een template op fouten die Exchange of de weergave breken.
 * @return string[] Nederlandstalige meldingen
 */
function angelia_template_problems(string $html): array
{
    $problems = [];
    if (trim($html) === '') {
        return ['De handtekening is leeg.'];
    }
    foreach (['telefoon', 'mobiel'] as $block) {
        $open = preg_match_all('/\[\[' . $block . '\]\]/i', $html);
        $close = preg_match_all('/\[\[\/' . $block . '\]\]/i', $html);
        if ($open !== $close) {
            $problems[] = "Blok [[{$block}]] is niet goed afgesloten met [[/{$block}]].";
        }
    }
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $html, $m);
    $known = array_merge(array_keys(ANGELIA_USER_TOKENS), ANGELIA_STATIC_TOKENS);
    foreach (array_unique(array_map('strtolower', $m[1])) as $name) {
        if (!in_array($name, $known, true)) {
            $problems[] = "Onbekende placeholder {{{$name}}}.";
        }
    }
    if (preg_match('/<script\b/i', $html)) {
        $problems[] = 'Scripts zijn niet toegestaan in een handtekening.';
    }
    return $problems;
}

/**
 * Houdt of verwijdert de optionele blokken.
 */
function angelia_apply_blocks(string $html, array $show): string
{
    foreach (['telefoon', 'mobiel'] as $block) {
        $pattern = '/\[\[' . $block . '\]\](.*?)\[\[\/' . $block . '\]\]/is';
        $html = preg_replace($pattern, !empty($show[$block]) ? '$1' : '', $html) ?? $html;
    }
    return $html;
}

/**
 * Vaste waarden per groep (bedrijf, kleur, banner).
 */
function angelia_static_values(array $group, array $company, string $bannerUrl): array
{
    $banner = '';
    if (!empty($group['banner_file']) && $bannerUrl !== '') {
        $img = '<img src="' . angelia_h($bannerUrl) . '" alt="' . angelia_h($company['name']) . '" width="400" style="display:block; border:0; max-width:400px;">';
        $banner = ($group['banner_link'] ?? '') !== ''
            ? '<a href="' . angelia_h($group['banner_link']) . '">' . $img . '</a>'
            : $img;
    }
    return [
        'bedrijf' => angelia_h($company['name']),
        'website' => angelia_h($company['website'] ?? ''),
        'kleur' => angelia_h($group['text_color'] ?? '#00529B'),
        'banner' => $banner,
        'banner_url' => angelia_h($bannerUrl),
        'banner_link' => angelia_h($group['banner_link'] ?? ''),
    ];
}

function angelia_replace_placeholders(string $html, array $values): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', static function (array $m) use ($values): string {
        $key = strtolower($m[1]);
        return array_key_exists($key, $values) ? (string) $values[$key] : $m[0];
    }, $html) ?? $html;
}

/**
 * Exchange-varianten voor een groep. Zonder optionele blokken: één variant 'Standaard' (geen DDG nodig).
 * @return array<string, array{html: string, filter: ?string}>
 */
function angelia_exchange_variants(array $group, array $company, string $bannerUrl): array
{
    $template = (string) $group['html'];
    $static = angelia_static_values($group, $company, $bannerUrl);
    $tokens = ANGELIA_USER_TOKENS;
    $usesBlocks = angelia_template_has_block($template, 'telefoon') || angelia_template_has_block($template, 'mobiel');

    $variants = $usesBlocks ? ANGELIA_VARIANTS : ['Standaard' => ['telefoon' => true, 'mobiel' => true, 'filter' => null]];
    $out = [];
    foreach ($variants as $name => $variant) {
        $html = angelia_apply_blocks($template, $variant);
        $html = angelia_replace_placeholders($html, $static + $tokens);
        $out[$name] = ['html' => ANGELIA_MARKER . "\n" . $html, 'filter' => $variant['filter']];
    }
    return $out;
}

/**
 * Handtekening voor één gebruiker (API, onboarding-script, preview).
 * $user: name, email, phone, mobile, title
 */
function angelia_render_for_user(array $group, array $company, string $bannerUrl, array $user): string
{
    $html = angelia_apply_blocks((string) $group['html'], [
        'telefoon' => trim((string) ($user['phone'] ?? '')) !== '',
        'mobiel' => trim((string) ($user['mobile'] ?? '')) !== '',
    ]);
    $values = angelia_static_values($group, $company, $bannerUrl) + [
        'naam' => angelia_h($user['name'] ?? ''),
        'email' => angelia_h($user['email'] ?? ''),
        'telefoon' => angelia_h($user['phone'] ?? ''),
        'mobiel' => angelia_h($user['mobile'] ?? ''),
        'functie' => angelia_h($user['title'] ?? ''),
    ];
    return ANGELIA_MARKER . "\n" . angelia_replace_placeholders($html, $values);
}

function angelia_sample_user(array $company): array
{
    return [
        'name' => 'Jan de Vries',
        'email' => 'jdevries@' . $company['domain'],
        'phone' => '+31 78 123 45 67',
        'mobile' => '+31 6 12 34 56 78',
        'title' => 'Projectleider',
    ];
}

/**
 * Leesbare Nederlandse datum/tijd in Europe/Amsterdam, bv. "7 oktober 2026, 10:30".
 */
function angelia_format_datetime(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return 'nooit';
    }
    static $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Amsterdam'));
    return $dt->format('j') . ' ' . $months[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y, H:i');
}
