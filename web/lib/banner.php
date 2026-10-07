<?php

/**
 * Banners: één bestand per groep onder een vaste naam, zodat de URL in de transportregel
 * gelijk blijft en een nieuwe upload direct in alle (ook al verzonden) mails zichtbaar wordt.
 *
 * Opslag:
 *  - Azure Blob Storage als $blobContainerUrl + $blobSasToken in auth.php staan (aanbevolen),
 *    publieke URL = <container-url>/<groep-id>.<ext>
 *  - anders lokaal in web/data/banners/, publiek via banner.php?g=<groep-id> (zonder login).
 */

const ANGELIA_BANNER_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
const ANGELIA_BANNER_MAX_BYTES = 1048576;

function angelia_banner_dir(): string
{
    $dir = angelia_data_dir() . '/banners';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function angelia_config(string $name, mixed $default = null): mixed
{
    return $GLOBALS[$name] ?? $default;
}

/**
 * Vaste publieke URL van de banner van een groep ('' als er geen banner is).
 */
function angelia_banner_url(array $group): string
{
    $file = (string) ($group['banner_file'] ?? '');
    if ($file === '') {
        return '';
    }
    $container = rtrim((string) angelia_config('blobContainerUrl', ''), '/');
    if ($container !== '') {
        return $container . '/' . rawurlencode($file);
    }
    $base = rtrim((string) angelia_config('publicBaseUrl', 'https://sleutels.kvt.nl/angelia'), '/');
    return $base . '/banner.php?g=' . rawurlencode((string) $group['id']);
}

/**
 * Valideert een upload en geeft [bytes, mime, ext] terug.
 */
function angelia_validate_banner(string $tmpPath, int $size): array
{
    if ($size <= 0 || $size > ANGELIA_BANNER_MAX_BYTES) {
        throw new InvalidArgumentException('De banner mag maximaal 1 MB zijn.');
    }
    $info = @getimagesize($tmpPath);
    $mime = is_array($info) ? (string) $info['mime'] : '';
    if (!isset(ANGELIA_BANNER_TYPES[$mime])) {
        throw new InvalidArgumentException('Alleen PNG, JPG of GIF is toegestaan.');
    }
    return [(string) file_get_contents($tmpPath), $mime, ANGELIA_BANNER_TYPES[$mime], (int) $info[0], (int) $info[1]];
}

/**
 * Bewaart de banner lokaal en (indien geconfigureerd) in de blob. Geeft de bestandsnaam terug.
 */
function angelia_store_banner(string $groupId, string $bytes, string $mime, string $ext): string
{
    $file = $groupId . '.' . $ext;
    foreach (glob(angelia_banner_dir() . '/' . $groupId . '.*') ?: [] as $old) {
        unlink($old);
    }
    file_put_contents(angelia_banner_dir() . '/' . $file, $bytes);

    $container = rtrim((string) angelia_config('blobContainerUrl', ''), '/');
    $sas = ltrim((string) angelia_config('blobSasToken', ''), '?');
    if ($container !== '' && $sas !== '') {
        angelia_blob_put($container . '/' . rawurlencode($file) . '?' . $sas, $bytes, $mime);
    }
    return $file;
}

function angelia_blob_put(string $url, string $bytes, string $mime): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-ms-blob-type: BlockBlob',
            'x-ms-version: 2021-08-06',
            'Content-Type: ' . $mime,
            // Korte cache: ontvangers zien een nieuwe banner binnen ~5 minuten.
            'x-ms-blob-cache-control: public, max-age=300',
        ],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Upload naar Azure Blob mislukt (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ').');
    }
}
