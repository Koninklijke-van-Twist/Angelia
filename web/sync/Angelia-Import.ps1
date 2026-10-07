<#
.SYNOPSIS
    Alleen-lezen overzicht van de handtekening-inrichting in Exchange Online (voor de Angelia-UI).
    Groepen Angelia-* en de legacy groep Handtekening-ServerSide (met leden) + alle transportregels
    met ApplyHtmlDisclaimer (incl. HTML, FromMemberOf, From en SenderDomainIs). Leden van groepen uit
    FromMemberOf worden ook opgehaald (distributie-/security-groepen en dynamische groepen), zodat
    Angelia bestaande handtekeningen kan overnemen. Verandert niets. Zelfde verbinding/mock-parameters als Angelia-Sync.ps1.
#>
param(
    [string]$MockStatePath,
    [string]$LegacyGroup = 'Handtekening-ServerSide'
)
$ErrorActionPreference = 'Stop'
if ($MockStatePath) {
    $S = if (Test-Path $MockStatePath) { Get-Content -Raw $MockStatePath | ConvertFrom-Json -AsHashtable } else { @{ groups = @{}; rules = @{} } }
    $groups = @($S.groups.Values | ForEach-Object { @{ name = $_.name; members = @($_.members) } })
    $rules = @($S.rules.Values | ForEach-Object {
        $mo = if ($_.from_address) { @() } else { @($_.from) }
        $fa = if ($_.from_address) { @($_.from) } else { @() }
        @{ name = $_.name; enabled = [bool]$_.enabled; from = $_.from; domain = $_.domain; html = "$($_.html)"
           from_member_of = $mo; from_addresses = $fa; domains = @($_.domain | Where-Object { $_ }) } })
    [pscustomobject]@{ mode = 'mock'; groups = $groups; rules = $rules } | ConvertTo-Json -Depth 6 -Compress
    return
}
Import-Module ExchangeOnlineManagement
$conn = @{ AppId = $env:ANGELIA_EXO_APPID; Organization = $env:ANGELIA_EXO_ORG; ShowBanner = $false }
if ($env:ANGELIA_EXO_CERTPATH) {
    $conn.CertificateFilePath = $env:ANGELIA_EXO_CERTPATH
    if ($env:ANGELIA_EXO_CERTPASS) { $conn.CertificatePassword = ConvertTo-SecureString $env:ANGELIA_EXO_CERTPASS -AsPlainText -Force }
} else { $conn.CertificateThumbprint = $env:ANGELIA_EXO_THUMBPRINT }
Connect-ExchangeOnline @conn | Out-Null
$addr = { param($r) "$($r.PrimarySmtpAddress)".ToLowerInvariant() }
$groups = @(Get-DistributionGroup -ResultSize Unlimited | Where-Object { $_.Name -like 'Angelia-*' -or $_.Name -eq $LegacyGroup } | ForEach-Object {
    @{ name = $_.Name; members = @(Get-DistributionGroupMember -Identity $_.Name -ResultSize Unlimited | ForEach-Object { & $addr $_ }) }
})
$seen = @{}; $groups | ForEach-Object { $seen[$_.name.ToLowerInvariant()] = $true }
$rules = @(Get-TransportRule -ResultSize Unlimited | Where-Object { $_.ApplyHtmlDisclaimerText } | ForEach-Object {
    $memberOf = @($_.FromMemberOf | ForEach-Object { "$_" } | Where-Object { $_ })
    foreach ($g in $memberOf) {
        if ($seen.ContainsKey($g.ToLowerInvariant())) { continue }
        $seen[$g.ToLowerInvariant()] = $true
        $members = $null
        $dg = Get-DistributionGroup -Identity $g -ErrorAction SilentlyContinue
        if ($dg) {
            $members = @(Get-DistributionGroupMember -Identity $dg.Identity -ResultSize Unlimited | ForEach-Object { & $addr $_ })
        } else {
            $ddg = Get-DynamicDistributionGroup -Identity $g -ErrorAction SilentlyContinue
            if ($ddg) {
                $members = @(Get-Recipient -RecipientPreviewFilter $ddg.RecipientFilter -OrganizationalUnit $ddg.RecipientContainer -ResultSize Unlimited | ForEach-Object { & $addr $_ })
            }
        }
        if ($null -ne $members) { $groups += @{ name = $g; members = $members } }
    }
    @{ name = $_.Name; enabled = ("$($_.State)" -eq 'Enabled'); from = "$(@($_.FromMemberOf) -join ', ')$(@($_.From) -join ', ')"; domain = "$(@($_.SenderDomainIs) -join ', ')"
       html = "$($_.ApplyHtmlDisclaimerText)"; from_member_of = $memberOf; from_addresses = @($_.From | ForEach-Object { "$_" } | Where-Object { $_ })
       domains = @($_.SenderDomainIs | ForEach-Object { "$_" } | Where-Object { $_ }) }
})
Disconnect-ExchangeOnline -Confirm:$false | Out-Null
[pscustomobject]@{ mode = 'live'; groups = $groups; rules = $rules } | ConvertTo-Json -Depth 6 -Compress
