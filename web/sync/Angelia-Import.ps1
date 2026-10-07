<#
.SYNOPSIS
    Alleen-lezen overzicht van de handtekening-inrichting in Exchange Online (voor de Angelia-UI).
    Groepen Angelia-* en de legacy groep Handtekening-ServerSide (met leden) + alle transportregels
    met ApplyHtmlDisclaimer. Verandert niets. Zelfde verbinding/mock-parameters als Angelia-Sync.ps1.
#>
param(
    [string]$MockStatePath,
    [string]$LegacyGroup = 'Handtekening-ServerSide'
)
$ErrorActionPreference = 'Stop'
if ($MockStatePath) {
    $S = if (Test-Path $MockStatePath) { Get-Content -Raw $MockStatePath | ConvertFrom-Json -AsHashtable } else { @{ groups = @{}; rules = @{} } }
    $groups = @($S.groups.Values | ForEach-Object { @{ name = $_.name; members = @($_.members) } })
    $rules = @($S.rules.Values | ForEach-Object { @{ name = $_.name; enabled = [bool]$_.enabled; from = $_.from; domain = $_.domain } })
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
$groups = @(Get-DistributionGroup -ResultSize Unlimited | Where-Object { $_.Name -like 'Angelia-*' -or $_.Name -eq $LegacyGroup } | ForEach-Object {
    @{ name = $_.Name; members = @(Get-DistributionGroupMember -Identity $_.Name -ResultSize Unlimited | ForEach-Object { "$($_.PrimarySmtpAddress)".ToLowerInvariant() }) }
})
$rules = @(Get-TransportRule -ResultSize Unlimited | Where-Object { $_.ApplyHtmlDisclaimerText } | ForEach-Object {
    @{ name = $_.Name; enabled = ("$($_.State)" -eq 'Enabled'); from = "$(@($_.FromMemberOf) -join ', ')$(@($_.From) -join ', ')"; domain = "$(@($_.SenderDomainIs) -join ', ')" }
})
Disconnect-ExchangeOnline -Confirm:$false | Out-Null
[pscustomobject]@{ mode = 'live'; groups = $groups; rules = $rules } | ConvertTo-Json -Depth 6 -Compress
