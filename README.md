# Angelia

Beheer van de e-mailhandtekeningen van KVT, KVT Germany en HVT op sleutels.kvt.nl. `web/` is de page root en gaat via FTP naar de server (zie `.github/workflows/deploy-ftp.yml`, bij push op `master`).

## Wat het doet

- **Bedrijven** (KVT, KVT Germany, HVT, uitbreidbaar): naam, e-maildomein, website.
- **Groepen** per bedrijf: naam, leden (e-mailadressen), aan/uit, volledige handtekening-HTML, tekstkleur, banner + optionele link.
- **Editor** met live voorbeeld (met en zonder telefoonnummers), placeholders en lengtecontrole.
- **Sync** naar Exchange Online: per groep een mail-enabled security group, lidmaatschap en transportregel(s). Idempotent, met dry-run en mock-modus.
- **API** (`X-API-Key`) om groepen en handtekeningen op te halen en leden toe te wijzen (onboarding/offboarding).
- **Overzicht** van wat er nu in Exchange staat (groepen + alle handtekeningregels, ook de oude), alleen lezen.

## Architectuur

```
 UI (index.php) ──► web/data/angelia.json ──► wachtrij + versie
                                                 │  hourly.php (run-pages.sh, alleen bij wijzigingen)
                                                 ▼
                         plan.json (gewenste toestand) ──► pwsh sync/Angelia-Sync.ps1
                                                              │ app-only certificaat
                                                              ▼
                                         Exchange Online: groep, leden, DDG's, transportregels
```

**Gekozen: server-side transportregels** (`ApplyHtmlDisclaimerText`), zoals `Set-KvtSignatures.ps1` nu al doet:

- per groep `Angelia-<groep-id>` (mail-enabled security group, verborgen in de adreslijst);
- per variant een regel `Angelia - <groep-id> - <variant>` met `SenderDomainIs <bedrijfsdomein>` + `FromMemberOf`, `Append`, fallback `Wrap`, uitzondering `ExceptIfSubjectOrBodyContainsWords KVTSIG`;
- gebruikt de template `[[telefoon]]…[[/telefoon]]` of `[[mobiel]]…[[/mobiel]]`, dan maakt Angelia 4 varianten met elk een Dynamic Distribution Group (`Angelia-<groep-id>-Compleet/-AlleenTel/-AlleenMobiel/-GeenTelMobiel`). Dat is dezelfde filter als in `Set-KvtSignatures.ps1`, zodat een lege regel echt wegvalt (Exchange kent geen "verberg als leeg"). Zonder blokken komt er één regel `Standaard` direct op de groep.
- Angelia raakt alleen objecten met het prefix `Angelia-` / `Angelia - ` aan. De bestaande regels `KVT Handtekening - …` blijven staan tot ze bewust uitgezet worden (zie `docs/onboarding-aanpassing.md`).

Waarom niet client-side (`Set-MailboxMessageConfiguration -SignatureHtml` / roaming signatures):

| | Transportregel | Client-side |
| --- | --- | --- |
| Werkt in Outlook classic, nieuwe Outlook, OWA, mobiel, Mac, multifunctional/scanners | ja | per client verschillend; mobiel en apps niet |
| Wijziging direct actief | ja | per mailbox opnieuw zetten; roaming signatures zijn voor Outlook classic slecht beheersbaar via PowerShell |
| Gebruiker kan het overschrijven | nee | ja |
| Handtekening zichtbaar tijdens typen | nee | ja |
| Plaatsing bij replies | onderaan (na de geciteerde tekst bij Append); KVTSIG voorkomt stapelen | direct onder het antwoord |
| Data | Exchange-attributen (`%%DisplayName%%` e.d.) | elke waarde |

De transportregel past bij hoe KVT het nu al doet en is centraal te beheren. De API (`signature&format=html`) levert dezelfde handtekening als HTML per gebruiker, zodat client-side later alsnog kan als aanvulling.

### Placeholders

| Angelia | Exchange-token | Bron |
| --- | --- | --- |
| `{{naam}}` | `%%DisplayName%%` | Entra/AD |
| `{{functie}}` | `%%Title%%` | Entra/AD |
| `{{email}}` | `%%WindowsEmailAddress%%` | Entra/AD |
| `{{telefoon}}` | `%%PhoneNumber%%` | AD telephoneNumber (078) |
| `{{mobiel}}` | `%%MobileNumber%%` | AD mobile (06) |
| `{{bedrijf}}`, `{{website}}` | vast | bedrijf in Angelia (niet `%%Company%%`: het AD-veld is niet overal gevuld) |
| `{{kleur}}` | vast | tekstkleur van de groep |
| `{{banner}}`, `{{banner_url}}`, `{{banner_link}}` | vast | banner van de groep |

`%%Email%%` en `%%Phone%%` bestaan niet in Exchange. De disclaimer-HTML mag maximaal 5000 tekens zijn (de editor waarschuwt).

### Banners

Eén bestand per groep met een vaste naam (`<groep-id>.png|jpg|gif`):

- **Standaard (voor de start): zonder blob.** Banners staan in `web/data/banners/` en zijn publiek via `banner.php?g=<groep-id>` (geen login, 5 minuten cache).
- **Azure Blob** (optioneel, zelfde opslagaccount als nu: `sakvthandtekeningen`): `$blobContainerUrl` + `$blobSasToken` in `auth.php`. URL `…/angelia/<groep-id>.png`, `Cache-Control: public, max-age=300`.

De URL in de regel verandert niet bij een nieuwe upload, dus de regel hoeft niet aangepast te worden. Gevolg: ook **al verzonden** mails tonen de nieuwe banner zodra de ontvanger ze opnieuw opent (en het plaatje niet uit cache of via een proxy komt; Gmail en Outlook-proxy's cachen soms langer). Geen cache-busting via `?v=`, omdat dat juist de vaste URL zou breken; wie dat wel wil, uploadt een ander bestandstype of maakt een nieuwe groep.

## Rechten en eenmalige inrichting (Tim)

1. **App-registratie** in Entra, bv. *Angelia Exchange Sync*, single tenant, geen redirect URI.
2. **API-permissie** `Office 365 Exchange Online` → Application → `Exchange.ManageAsApp` + admin consent.
3. **Rol** voor de service principal van de app: *Exchange Administrator* (Entra-rollen → Exchange Administrator → toewijzen aan de app). Kleiner kan met een Exchange RBAC-rol(groep) met *Transport Rules*, *Distribution Groups* en *Mail Recipients*, maar dat vereist een eigen management-scope; voor v1 de ingebouwde rol.
4. **Certificaat**: self-signed (bv. 2 jaar), publieke `.cer` uploaden bij de app-registratie, `.pfx` met wachtwoord op de server in `web/data/certs/` (niet in git, niet via FTP, `.htaccess` deny). Op Linux werkt alleen `certificate_path`; `certificate_thumbprint` is voor de Windows-certificaatstore.
5. **Graph**: niet nodig voor v1. Het lidmaatschap van mail-enabled security groups is in Graph alleen-lezen, daarom gaat dat via Exchange (`Add-/Remove-DistributionGroupMember`). Een latere koppeling (gebruikers zoeken, attributen tonen) heeft `User.Read.All` nodig.
6. **Server** (Ubuntu 25.04): PowerShell 7 + `Install-Module ExchangeOnlineManagement -Scope AllUsers` (versie 3.x). PHP moet `proc_open` mogen gebruiken.
7. **Blob** (optioneel; zonder blob gaat alles via `banner.php`): container `angelia` met publieke leestoegang op blob-niveau in `sakvthandtekeningen`, SAS met alleen *create/write* op die container, met een verloopdatum.
8. **auth.php**: `web/auth_TEMPLATE.php` → `web/auth.php`, vul `$allowedUsers`, `$admins`, `$apiKeys`, `$exchange` en eventueel de blob-gegevens in.
9. **Sync**: geen eigen cron. `web/hourly.php` wordt elk uur aangeroepen door `run-pages.sh` zoals bij de andere apps (toegang alleen localhost of ingelogde sessie, via `logincheck.php`). Er gebeurt alleen iets als er sinds de laatste geslaagde sync iets in Angelia gewijzigd is (opslaan, assign/unassign, banner): `version` ≠ `synced_version` in `web/data/angelia.json`. Anders komt er direct `{"ok":true,"skipped":true,…}` terug. Een lock (`web/data/sync.lock`) voorkomt dat twee syncs tegelijk lopen. Wijzigingen staan dus binnen een uur in Exchange.

Eerste keer: `php web/worker.php --import` (overzicht) en `php web/worker.php --dry-run`, en pas daarna zonder `--dry-run`.

Zolang `$exchange` leeg is draait alles in **mock-modus** (`web/data/mock_exchange.json` speelt dan Exchange).

## Certificaatrotatie (jaarlijks)

`scripts/systemd/` bevat `angelia-cert-rotate.sh` + `.service` + `.timer` (`OnCalendar=yearly`, `Persistent=true`). De stappen:

1. Nieuw self-signed RSA-2048-certificaat maken, 2 jaar geldig.
2. Toevoegen aan de app-registratie met Graph `POST /applications/{object-id}/addKey`, met een proof-JWT die met het **huidige** certificaat ondertekend is. Het Graph-token komt ook van het huidige certificaat. Er zijn geen extra Graph-rechten nodig.
3. Exchange-login testen met het nieuwe certificaat (`Connect-ExchangeOnline`, 10 pogingen met 60 s ertussen, omdat een nieuwe key even tijd nodig heeft).
4. De `.pfx` in `web/data/certs/` atomair vervangen (eigenaar `www-data`, chmod 600, wachtwoord blijft gelijk).
5. Pas daarna `removeKey` voor de oude key.

Faalt een stap, dan blijft het oude certificaat in gebruik, wordt een al toegevoegde nieuwe key weer verwijderd en stopt het script met een fout. Is het huidige certificaat al verlopen, dan kan rotatie via een proof niet meer; dan moet het handmatig in Entra.

Angelia waarschuwt in de UI en in de JSON van `hourly.php` (`certificate_warning`) als het certificaat binnen 30 dagen verloopt of niet te lezen is.

Installatie (Tim, op de server; niets hiervan is al gedaan):

```sh
sudo apt install jq openssl
sudo install -m 750 scripts/systemd/angelia-cert-rotate.sh /usr/local/sbin/
sudo install -m 644 scripts/systemd/angelia-cert-rotate.{service,timer} /etc/systemd/system/
sudo install -d -m 700 /etc/angelia
sudoedit /etc/angelia/cert-rotate.env    # TENANT_ID, CLIENT_ID, APP_OBJECT_ID, EXO_ORGANIZATION, PFX_PATH, PFX_PASSWORD (chmod 600, root)
sudo systemctl daemon-reload
sudo systemctl enable --now angelia-cert-rotate.timer
sudo systemctl start angelia-cert-rotate.service && journalctl -u angelia-cert-rotate -n 50   # eenmalig testen
```

`PFX_PASSWORD` moet gelijk zijn aan `certificate_password` in `web/auth.php`. Test lokaal: `bash tests/cert_rotate_test.sh` (draait tegen een nep-Graph en een nep-pwsh).

## Worker (handmatig)

`hourly.php` doet de automatische sync. `worker.php` is voor handmatig gebruik op de server:

```sh
php web/worker.php --dry-run   # wat zou er veranderen (live of mock, verandert niets)
php web/worker.php             # sync als er iets gewijzigd is
php web/worker.php --force     # ook met lege wachtrij (drift herstellen)
php web/worker.php --mock      # nooit live
php web/worker.php --import    # alleen-lezen overzicht uit Exchange → web/data/exchange_snapshot.json
```

Per groep: groep aanmaken als die ontbreekt → leden toevoegen/verwijderen (Angelia is de bron) → DDG's per variant → regels aanmaken/bijwerken (alleen bij verschil in HTML, groep, domein of aan/uit) → regels en DDG's van varianten die niet meer nodig zijn verwijderen. Verwijderde groepen: regels, DDG's en groep weg. Mislukt iets, dan blijft de wachtrij staan en probeert de volgende run opnieuw.

**Hoogstens één groep per adres.** Opslaan in de UI en `assign` halen het adres uit alle andere Angelia-groepen (ook als shared mailbox). Staat het toch dubbel (handmatig bewerkte `angelia.json`), dan waarschuwen UI en worker en synct de worker het adres alleen in de laatst gewijzigde groep. Het Exchange-overzicht (`--import`) waarschuwt als een adres in Exchange in meerdere handtekening-groepen staat.

### Shared mailboxes

Per groep een lijst `adres | naam | functie | telefoon | mobiel`. Elke shared mailbox krijgt een eigen regel `Angelia - <groep-id> - Shared <adres>` met **`From <adres>`** + `SenderDomainIs <domein van het adres>` en **statische** inhoud (dezelfde template, placeholders ingevuld met de waarden van de shared mailbox). Dit volgt `Set-KvtSharedMailboxSignatures.ps1`: een transportregel kan niet door Send As heen naar de gebruiker kijken, dus `%%DisplayName%%` e.d. zouden de shared mailbox zelf of leeg geven. Zet een shared mailbox dus **niet** bij de leden (Angelia weigert het).

## API

Header `X-API-Key: <sleutel>` (zie `$apiKeys`). Scopes: `read`, `assign`.

| Methode | URL | Resultaat |
| --- | --- | --- |
| GET | `api.php?action=groups` | groepen met bedrijf, domein, Exchange-groep, aan/uit, aantal leden |
| GET | `api.php?action=signature&group=<id>` | JSON met `html` (placeholders leeg) |
| GET | `api.php?action=signature&email=<adres>&name=…&title=…&phone=…&mobile=…` | groep op basis van lidmaatschap, ingevulde HTML |
| GET | `…&format=html` | alleen de HTML |
| GET | `…&format=exchange` | de HTML-varianten zoals ze in de regels komen |
| POST | `api.php?action=assign` `{"email","group"}` | in die groep, uit alle andere Angelia-groepen (scope `assign`) |
| POST | `api.php?action=unassign` `{"email"}` | uit alle groepen (scope `assign`) |

Tijden in de API staan als Unix-tijd plus `…_text` (bv. `7 oktober 2026, 10:30`, Europe/Amsterdam).

Onboarding/offboarding: `scripts/Angelia.psm1` + `docs/onboarding-aanpassing.md`.

## auth.php

Niet in git. Kopieer `web/auth_TEMPLATE.php` naar `web/auth.php`. Iedereen in `$allowedUsers` kan kijken; alleen `$admins` kan wijzigen.

## Tests

```sh
php tests/angelia_test.php       # logica + worker in mock-modus (echte Angelia-Sync.ps1, pwsh nodig; anders overgeslagen)
bash tests/cert_rotate_test.sh   # certificaatrotatie tegen nep-Graph
php tests/api_access_test.php    # start php -S op een tijdelijke kopie van web/: UI, CSRF, API-sleutels, scopes
```
