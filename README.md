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
 UI (index.php) ──► web/data/angelia.json ──► wachtrij
                                                 │  php worker.php (cron, voorstel)
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

- **Azure Blob** (aanbevolen, zelfde opslagaccount als nu: `sakvthandtekeningen`): `$blobContainerUrl` + `$blobSasToken` in `auth.php`. URL `…/angelia/<groep-id>.png`, `Cache-Control: public, max-age=300`.
- Anders lokaal in `web/data/banners/`, publiek via `banner.php?g=<groep-id>` (geen login, 5 minuten cache).

De URL in de regel verandert niet bij een nieuwe upload, dus de regel hoeft niet aangepast te worden. Gevolg: ook **al verzonden** mails tonen de nieuwe banner zodra de ontvanger ze opnieuw opent (en het plaatje niet uit cache of via een proxy komt; Gmail en Outlook-proxy's cachen soms langer). Geen cache-busting via `?v=`, omdat dat juist de vaste URL zou breken; wie dat wel wil, uploadt een ander bestandstype of maakt een nieuwe groep.

## Rechten en eenmalige inrichting (Tim)

1. **App-registratie** in Entra, bv. *Angelia Exchange Sync*, single tenant, geen redirect URI.
2. **API-permissie** `Office 365 Exchange Online` → Application → `Exchange.ManageAsApp` + admin consent.
3. **Rol** voor de service principal van de app: *Exchange Administrator* (Entra-rollen → Exchange Administrator → toewijzen aan de app). Kleiner kan met een Exchange RBAC-rol(groep) met *Transport Rules*, *Distribution Groups* en *Mail Recipients*, maar dat vereist een eigen management-scope; voor v1 de ingebouwde rol.
4. **Certificaat**: self-signed (bv. 2 jaar), publieke `.cer` uploaden bij de app-registratie, `.pfx` met wachtwoord op de server in `web/data/certs/` (niet in git, niet via FTP, `.htaccess` deny). Op Linux werkt alleen `certificate_path`; `certificate_thumbprint` is voor de Windows-certificaatstore.
5. **Graph**: niet nodig voor v1. Het lidmaatschap van mail-enabled security groups is in Graph alleen-lezen, daarom gaat dat via Exchange (`Add-/Remove-DistributionGroupMember`). Een latere koppeling (gebruikers zoeken, attributen tonen) heeft `User.Read.All` nodig.
6. **Server** (Ubuntu 25.04): PowerShell 7 + `Install-Module ExchangeOnlineManagement -Scope AllUsers` (versie 3.x). PHP moet `proc_open` mogen gebruiken.
7. **Blob** (optioneel): container `angelia` met publieke leestoegang op blob-niveau in `sakvthandtekeningen`, SAS met alleen *create/write* op die container, met een verloopdatum.
8. **auth.php**: `web/auth_TEMPLATE.php` → `web/auth.php`, vul `$allowedUsers`, `$admins`, `$apiKeys`, `$exchange` en eventueel de blob-gegevens in.
9. **Cron** (voorstel, niet ingericht): `*/5 * * * * php /…/angelia/web/worker.php >> /…/angelia/web/data/worker.log 2>&1` (paden: de page root `web/` op de server). Het draait alleen als er iets in de wachtrij staat; `--force` 's nachts herstelt handmatige wijzigingen in Exchange (drift).

Eerste keer: `php web/worker.php --import` (overzicht) en `php web/worker.php --dry-run`, en pas daarna zonder `--dry-run`.

Zolang `$exchange` leeg is draait alles in **mock-modus** (`web/data/mock_exchange.json` speelt dan Exchange).

## Worker

```sh
php web/worker.php --dry-run   # wat zou er veranderen (live of mock, verandert niets)
php web/worker.php             # wachtrij verwerken
php web/worker.php --force     # ook met lege wachtrij (drift herstellen)
php web/worker.php --mock      # nooit live
php web/worker.php --import    # alleen-lezen overzicht uit Exchange → web/data/exchange_snapshot.json
```

Per groep: groep aanmaken als die ontbreekt → leden toevoegen/verwijderen (Angelia is de bron) → DDG's per variant → regels aanmaken/bijwerken (alleen bij verschil in HTML, groep, domein of aan/uit) → regels en DDG's van varianten die niet meer nodig zijn verwijderen. Verwijderde groepen: regels, DDG's en groep weg. Mislukt iets, dan blijft de wachtrij staan en probeert de volgende run opnieuw.

Een adres in twee actieve groepen geeft een waarschuwing (twee handtekeningen).

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
php tests/api_access_test.php    # start php -S op een tijdelijke kopie van web/: UI, CSRF, API-sleutels, scopes
```
