# Voorstel: onboarding/offboarding koppelen aan Angelia

De scripts staan niet op GitHub; dit is daarom een beschrijving + codefragmenten in plaats van een PR.
Geanalyseerd: `Set-KvtSignatures.ps1`, `New-KvtUser.ps1`, `Offboard-User.ps1`, `Offboard-CloudPhase.ps1`
(versie die Tim op 7 oktober 2026 aanleverde) en de wiki-pagina *KVT User Onboarding* (§5, §6).

## Hoe het nu werkt

| Onderdeel | Nu |
| --- | --- |
| Methode | **Server-side**, Exchange-transportregels (`ApplyHtmlDisclaimerText`, Append, fallback Wrap). Geen client-side handtekeningen. |
| Wie | Leden van de mail-enabled security group `Handtekening-ServerSide` (alleen nieuwe gebruikers; bestaande houden hun Outlook-handtekening). |
| Varianten | 4 Dynamic Distribution Groups (`Handtekening-Compleet/-AlleenTel/-AlleenMobiel/-GeenTelMobiel`), filter op `MemberOfGroup` + `Phone`/`MobilePhone` gevuld. 3 entiteiten x 4 = 12 regels, scope `SenderDomainIs` + `FromMemberOf <DDG>`. |
| Tokens | `%%DisplayName%%`, `%%Title%%`, `%%PhoneNumber%%`, `%%MobileNumber%%`, `%%WindowsEmailAddress%%`. Adres, groet, logo, banner en web statisch per entiteit. |
| Dubbel voorkomen | `<!-- KVTSIG -->` in de HTML + `ExceptIfSubjectOrBodyContainsWords KVTSIG`. |
| Banners/logo's | `sakvthandtekeningen.blob.core.windows.net/signatures/…` (BE nog placeholders, DE zonder banner). |
| Shared mailboxes | Aparte statische regels (`Set-KvtSharedMailboxSignatures.ps1`, scope `From <adres>`). Niet in Angelia v1. |
| Onboarding | `Invoke-ExoSteps` in `New-KvtUser.ps1`: interactieve `Add-DistributionGroupMember -Identity $Cfg.SignatureGroup` (vereist mailbox, anders later `-ExchangeOnly`). |
| Offboarding | **Haalt de gebruiker niet uit de handtekening-groep.** `Offboard-CloudPhase.ps1` verwijdert via Graph alleen licentiegroepen (of alles met `-RemoveGroups`), en Graph kan lidmaatschap van mail-enabled security groups niet wijzigen. Gevolg: uitgeschakelde accounts blijven lid. |

Angelia neemt exact dit model over (zelfde marker, tokens, varianten en DDG-filters), maar per **Angelia-groep**
(`Angelia-<groep-id>`) in plaats van per entiteit, met de template in de UI in plaats van in het script.

## Aanpassing New-KvtUser.ps1

1. `Angelia.psm1` (uit deze repo, `scripts/`) naast de scripts zetten. API-sleutel in de omgeving
   (`setx ANGELIA_API_KEY …`), **niet** in het script.
2. In `$Cfg` toevoegen:

```powershell
    UseAngelia        = $true                     # handtekening-groep via Angelia i.p.v. Handtekening-ServerSide
```

3. In `Invoke-ExoSteps` het blok `# --- E-mailhandtekening: ...` vervangen door:

```powershell
    # --- E-mailhandtekening via Angelia (sleutels.kvt.nl/angelia) ---
    if ($Cfg.UseAngelia) {
        try {
            Import-Module (Join-Path $PSScriptRoot 'Angelia.psm1') -Force
            $domain = ($Mail -split '@')[1]
            $groups = @(Get-AngeliaGroups -Domain $domain | Where-Object enabled)
            if ($groups.Count -eq 0) {
                Write-Warning "  Geen actieve Angelia-handtekeninggroep voor $domain."
            } else {
                for ($i = 0; $i -lt $groups.Count; $i++) { Write-Host ("  [{0}] {1}" -f ($i + 1), $groups[$i].name) }
                $pick = Read-Host "Handtekening-groep (nummer, leeg = overslaan)"
                if ($pick -match '^\d+$' -and [int]$pick -ge 1 -and [int]$pick -le $groups.Count) {
                    $g = $groups[[int]$pick - 1]
                    if ($DryRun) { Write-Host "  [DryRun] $Mail -> Angelia-groep $($g.id)" -ForegroundColor Yellow }
                    else {
                        Set-AngeliaMember -Email $Mail -Group $g.id | Out-Null
                        Write-Host "  + $Mail in Angelia-groep '$($g.name)' (Exchange volgt bij de volgende sync)." -ForegroundColor Green
                    }
                }
            }
        } catch {
            Write-Warning "  Angelia niet bereikbaar: $($_.Exception.Message). Later opnieuw met -ExchangeOnly."
        }
    } elseif (Ask-YesNo "Gebruiker toevoegen aan handtekening-groep '$($Cfg.SignatureGroup)'?") {
        # ... bestaand blok ongewijzigd (legacy) ...
    }
```

Voordelen: geen mailbox nodig op het moment van onboarden (Angelia's sync voegt toe zodra de recipient bestaat;
tot die tijd meldt de sync een fout en probeert het bij de volgende run opnieuw), geen Exchange-rechten nodig voor
de beheerder die onboardt, en de keuze voor de groep (bv. Monteurs vs Kantoor) staat centraal.

> **Niet** óók in `Handtekening-ServerSide` zetten: dan matchen de oude 12 regels én de Angelia-regel en krijgt
> de gebruiker twee handtekeningen (of de eerste wint via KVTSIG; niet op vertrouwen).

## Aanpassing Offboard-User.ps1

Na de cloud-fase (na `$cloudResult = Invoke-CloudPhase ...`) toevoegen:

```powershell
    Write-Step "Angelia: uit handtekening-groepen"
    try {
        Import-Module (Join-Path $PSScriptRoot 'Angelia.psm1') -Force
        $r = Remove-AngeliaMember -Email $resolvedUpn
        if ($r.changed.Count) { Write-Ok "Verwijderd uit: $($r.changed -join ', ')" } else { Write-Ok "Was geen lid van een Angelia-groep." }
    } catch {
        Write-Warn "Angelia niet bereikbaar: $($_.Exception.Message) - haal $resolvedUpn handmatig uit de groep in Angelia."
    }
```

Los daarvan (ook zonder Angelia): de offboarding haalt nu niemand uit `Handtekening-ServerSide`. Dat kan alleen via
Exchange (`Remove-DistributionGroupMember -BypassSecurityGroupManagerCheck`), niet via Graph.

## Set-KvtSignatures.ps1 na migratie

1. Per entiteit een Angelia-groep maken (bv. *KVT – Algemeen*, *KVT Germany – Algemeen*, *HVT – Algemeen*) met de
   standaardtemplate (= `Get-SigHtml` NL; DE/BE: groet, adres, labels, logo aanpassen in de editor).
2. Leden van `Handtekening-ServerSide` overnemen (Angelia → *Ophalen uit Exchange* toont ze) en in de juiste groep zetten.
3. Proefrun, sync, testmail per domein.
4. De 12 regels `KVT Handtekening - … / Hunter Handtekening - …` uitschakelen (`Disable-TransportRule`), daarna de
   gebruikers uit `Handtekening-ServerSide` halen. `Set-KvtSignatures.ps1` niet meer draaien (anders worden de
   regels weer bijgewerkt; ze blijven wel uit, het script zet `Enabled` niet).
