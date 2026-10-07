<?php

/**
 * Kopieer naar web/auth.php op de server en vul in. auth.php staat niet in git
 * en de FTP-deploy overschrijft hem niet.
 *
 * $allowedUsers  E-mailadressen die Angelia mogen openen (logincheck.php). Lokaal (php -S op
 *                127.0.0.1) logt Angelia automatisch in als het eerste adres.
 * $admins        Mogen bedrijven/groepen/handtekeningen wijzigen en banners uploaden.
 *                Iedereen anders in $allowedUsers ziet alles alleen-lezen. Leeg = niemand (fail-closed).
 * $apiKeys       Sleutels voor api.php (header X-API-Key). Scope 'read' = groepen/handtekening ophalen,
 *                'assign' = leden toevoegen/verwijderen (onboarding/offboarding).
 * $exchange      App-only certificaat-login voor Exchange Online (zie README). Leeg = mock-modus:
 *                de worker verandert dan niets in Exchange.
 * $blobContainerUrl / $blobSasToken
 *                Optioneel: Azure Blob-container voor banners (vaste URL per groep). Leeg = banners
 *                via banner.php op deze server.
 * $publicBaseUrl Publieke basis-URL van Angelia (voor banner.php-links).
 * $pwshPath      Pad naar PowerShell 7 (standaard 'pwsh').
 */

$allowedUsers = [
    'user@domain.nl',
];

$admins = [
    // 'tfalken@kvt.nl',
];

$apiKeys = [
    // 'lange-willekeurige-sleutel' => ['name' => 'onboarding', 'scopes' => ['read', 'assign']],
];

$exchange = [
    'app_id' => '',                       // Application (client) ID van de app-registratie
    'organization' => '',                 // bv. kvt.onmicrosoft.com
    'certificate_path' => '',             // .pfx in web/data/certs/ (niet via FTP, .htaccess deny)
    'certificate_password' => '',
    'certificate_thumbprint' => '',       // alleen op Windows (certificaatstore)
];

// $blobContainerUrl = 'https://sakvthandtekeningen.blob.core.windows.net/angelia';
// $blobSasToken     = 'sp=cw&…';         // SAS met alleen create/write op die container

$publicBaseUrl = 'https://sleutels.kvt.nl/angelia';
// $pwshPath = '/usr/bin/pwsh';
