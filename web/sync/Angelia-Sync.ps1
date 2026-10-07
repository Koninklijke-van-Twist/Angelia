<#
.SYNOPSIS
    Angelia-Sync.ps1 - zet het Angelia-plan (gewenste toestand) door naar Exchange Online.

.DESCRIPTION
    Leest het plan dat web/worker.php schrijft en maakt per groep idempotent aan/bij:
      - mail-enabled security group  (New-DistributionGroup -Type Security, verborgen in adreslijst)
      - lidmaatschap                 (Add-/Remove-DistributionGroupMember; Angelia is de bron)
      - per variant een Dynamic Distribution Group (alleen als de template [[telefoon]]/[[mobiel]] gebruikt),
        zelfde RecipientFilter-opzet als Set-KvtSignatures.ps1
      - per variant een transportregel  "Angelia - <groep-id> - <variant>"
        (SenderDomainIs + FromMemberOf, ApplyHtmlDisclaimer Append/Wrap, ExceptIfSubjectOrBodyContainsWords KVTSIG)
    Regels met prefix "Angelia - <groep-id> - " die niet meer in het plan staan worden verwijderd.
    Verwijderde groepen: regels, DDG's en de groep zelf worden verwijderd.
    Regels/groepen buiten het Angelia-prefix (bv. "KVT Handtekening - ...") worden NOOIT aangeraakt.

    Graph wordt hier bewust niet gebruikt: lidmaatschap van mail-enabled security groups is
    in Graph alleen-lezen en moet via Exchange.

.PARAMETER PlanPath   JSON-plan van worker.php.
.PARAMETER DryRun     Alleen tonen wat er zou gebeuren.
.PARAMETER MockStatePath  Gebruik een JSON-bestand als nep-Exchange (tests/zonder credentials).

    Live-verbinding (app-only, certificaat) via omgevingsvariabelen:
      ANGELIA_EXO_APPID, ANGELIA_EXO_ORG (bv. kvt.onmicrosoft.com),
      ANGELIA_EXO_CERTPATH (+ ANGELIA_EXO_CERTPASS) of ANGELIA_EXO_THUMBPRINT (alleen Windows).

    Uitvoer: één JSON-object op stdout: { mode, dry_run, actions: [ {action, target, detail} ], errors: [] }
#>
param(
    [Parameter(Mandatory)][string]$PlanPath,
    [switch]$DryRun,
    [string]$MockStatePath
)

$ErrorActionPreference = 'Stop'
$plan = Get-Content -Raw -Path $PlanPath | ConvertFrom-Json
$Mock = [bool]$MockStatePath
$Actions = [System.Collections.Generic.List[object]]::new()
$Errors = [System.Collections.Generic.List[string]]::new()

function Add-Action([string]$Action, [string]$Target, [string]$Detail = '') {
    $Actions.Add([pscustomobject]@{ action = $Action; target = $Target; detail = $Detail })
}

# ---------------------------------------------------------------- state-laag (mock of live)
if ($Mock) {
    if (Test-Path $MockStatePath) {
        $S = Get-Content -Raw $MockStatePath | ConvertFrom-Json -AsHashtable
    } else { $S = @{} }
    foreach ($k in 'groups', 'ddgs', 'rules') { if (-not $S.ContainsKey($k)) { $S[$k] = @{} } }
} else {
    Import-Module ExchangeOnlineManagement -ErrorAction Stop
    $conn = @{ AppId = $env:ANGELIA_EXO_APPID; Organization = $env:ANGELIA_EXO_ORG; ShowBanner = $false }
    if ($env:ANGELIA_EXO_CERTPATH) {
        $conn.CertificateFilePath = $env:ANGELIA_EXO_CERTPATH
        if ($env:ANGELIA_EXO_CERTPASS) {
            $conn.CertificatePassword = ConvertTo-SecureString $env:ANGELIA_EXO_CERTPASS -AsPlainText -Force
        }
    } else {
        $conn.CertificateThumbprint = $env:ANGELIA_EXO_THUMBPRINT
    }
    Connect-ExchangeOnline @conn | Out-Null
}

function Get-AGroup([string]$Name) {
    if ($Mock) { return $S.groups[$Name] }
    $g = Get-DistributionGroup -Identity $Name -ErrorAction SilentlyContinue
    if (-not $g) { return $null }
    $members = @(Get-DistributionGroupMember -Identity $Name -ResultSize Unlimited |
        ForEach-Object { "$($_.PrimarySmtpAddress)".ToLowerInvariant() })
    return @{ name = $Name; dn = $g.DistinguishedName; display_name = $g.DisplayName; members = $members }
}
function New-AGroup([string]$Name, [string]$Display) {
    if ($Mock) { $S.groups[$Name] = @{ name = $Name; dn = "CN=$Name,OU=mock"; display_name = $Display; members = @() }; return }
    New-DistributionGroup -Name $Name -DisplayName $Display -Type Security -MemberDepartRestriction Closed | Out-Null
    Set-DistributionGroup -Identity $Name -HiddenFromAddressListsEnabled $true
}
function Add-AMember([string]$Group, [string]$Email) {
    if ($Mock) { $S.groups[$Group].members = @($S.groups[$Group].members) + $Email; return }
    Add-DistributionGroupMember -Identity $Group -Member $Email -BypassSecurityGroupManagerCheck
}
function Remove-AMember([string]$Group, [string]$Email) {
    if ($Mock) { $S.groups[$Group].members = @($S.groups[$Group].members | Where-Object { $_ -ne $Email }); return }
    Remove-DistributionGroupMember -Identity $Group -Member $Email -BypassSecurityGroupManagerCheck -Confirm:$false
}
function Remove-AGroup([string]$Name) {
    if ($Mock) { $S.groups.Remove($Name); return }
    Remove-DistributionGroup -Identity $Name -BypassSecurityGroupManagerCheck -Confirm:$false
}
function Get-ADdg([string]$Name) {
    if ($Mock) { return $S.ddgs[$Name] }
    $d = Get-DynamicDistributionGroup -Identity $Name -ErrorAction SilentlyContinue
    if (-not $d) { return $null }
    return @{ name = $Name; filter = "$($d.RecipientFilter)" }
}
function Set-ADdg([string]$Name, [string]$Filter, [bool]$Exists) {
    if ($Mock) { $S.ddgs[$Name] = @{ name = $Name; filter = $Filter }; return }
    if ($Exists) { Set-DynamicDistributionGroup -Identity $Name -RecipientFilter $Filter -Confirm:$false }
    else {
        New-DynamicDistributionGroup -Name $Name -RecipientFilter $Filter | Out-Null
        Set-DynamicDistributionGroup -Identity $Name -HiddenFromAddressListsEnabled $true
    }
}
function Remove-ADdg([string]$Name) {
    if ($Mock) { $S.ddgs.Remove($Name); return }
    Remove-DynamicDistributionGroup -Identity $Name -Confirm:$false
}
function Get-ARules([string]$Prefix) {
    if ($Mock) { return @($S.rules.Values | Where-Object { $_.name.StartsWith($Prefix) }) }
    return @(Get-TransportRule -ResultSize Unlimited | Where-Object { $_.Name.StartsWith($Prefix) } | ForEach-Object {
        @{ name = $_.Name; html = "$($_.ApplyHtmlDisclaimerText)"; from = "$(@($_.FromMemberOf)[0])$(@($_.From)[0])"
           domain = "$(@($_.SenderDomainIs)[0])"; enabled = ("$($_.State)" -eq 'Enabled') }
    })
}
function Set-ARule([hashtable]$Rule, [bool]$Exists) {
    if ($Mock) { $S.rules[$Rule.name] = $Rule; return }
    $p = @{
        SenderDomainIs = $Rule.domain
        ApplyHtmlDisclaimerLocation = 'Append'
        ApplyHtmlDisclaimerText = $Rule.html
        ApplyHtmlDisclaimerFallbackAction = 'Wrap'
        ExceptIfSubjectOrBodyContainsWords = $plan.marker_word
    }
    # Shared mailbox: From <adres> (statische inhoud); anders FromMemberOf <groep/DDG>.
    if ($Rule.from_address) { $p.From = $Rule.from } else { $p.FromMemberOf = $Rule.from }
    if ($Exists) { Set-TransportRule -Identity $Rule.name @p }
    else { New-TransportRule -Name $Rule.name @p -Enabled $Rule.enabled | Out-Null }
    if ($Exists) {
        if ($Rule.enabled) { Enable-TransportRule -Identity $Rule.name -Confirm:$false }
        else { Disable-TransportRule -Identity $Rule.name -Confirm:$false }
    }
}
function Remove-ARule([string]$Name) {
    if ($Mock) { $S.rules.Remove($Name); return }
    Remove-TransportRule -Identity $Name -Confirm:$false
}

# Exchange normaliseert RecipientFilter (extra haakjes, SystemMailbox-uitsluitingen) en geeft FromMemberOf
# soms als naam of DN terug. Vergelijk daarom genormaliseerd, anders zou live elke run alles bijwerken.
function Test-FilterMatch([string]$Current, [string]$Wanted) {
    $norm = { param($f) ($f -replace '[\s()]', '').ToLowerInvariant() }
    return (& $norm $Current).Contains((& $norm $Wanted))
}
function Test-MemberOfMatch([string]$Current, [string]$Wanted) {
    if (-not $Current) { return $false }
    return $Current -ieq $Wanted -or $Current -ilike "CN=$Wanted,*" -or $Current -ilike "*/$Wanted" -or $Current -ilike "*\$Wanted"
}

function Invoke-Change([string]$Action, [string]$Target, [string]$Detail, [scriptblock]$Do) {
    Add-Action $Action $Target $Detail
    if ($DryRun) { return }
    try { & $Do } catch { $Errors.Add("${Action} ${Target}: $($_.Exception.Message)") }
}

# ---------------------------------------------------------------- plan uitvoeren
foreach ($g in $plan.groups) {
    $name = $g.exchange_group
    try {
        $current = Get-AGroup $name
        if (-not $current) {
            Invoke-Change 'groep_aanmaken' $name $g.display_name { New-AGroup $name $g.display_name }
            $current = if ($DryRun) { @{ dn = "CN=$name"; members = @() } } else { Get-AGroup $name }
        }
        $want = @($g.members | ForEach-Object { "$_".ToLowerInvariant() })
        $have = @($current.members)
        foreach ($m in $want | Where-Object { $have -notcontains $_ }) {
            Invoke-Change 'lid_toevoegen' $name $m { Add-AMember $name $m }
        }
        foreach ($m in $have | Where-Object { $want -notcontains $_ }) {
            Invoke-Change 'lid_verwijderen' $name $m { Remove-AMember $name $m }
        }

        $prefix = "$($plan.rule_prefix)$($g.id) - "
        $existingRules = @{}
        foreach ($r in Get-ARules $prefix) { $existingRules[$r.name] = $r }
        $wantedNames = @()
        foreach ($rule in $g.rules) {
            $wantedNames += $rule.name
            $from = $name
            if ($rule.ddg) {
                $filter = "(RecipientType -eq 'UserMailbox') -and (MemberOfGroup -eq '$($current.dn)') -and $($rule.ddg.filter)"
                $d = Get-ADdg $rule.ddg.name
                if (-not $d -or -not (Test-FilterMatch $d.filter $filter)) {
                    $exists = [bool]$d
                    Invoke-Change ($(if ($exists) { 'ddg_bijwerken' } else { 'ddg_aanmaken' })) $rule.ddg.name '' { Set-ADdg $rule.ddg.name $filter $exists }
                }
                $from = $rule.ddg.name
            }
            if ($rule.from_address) { $from = $rule.from_address }
            $desired = @{ name = $rule.name; html = $rule.html; from = $from; from_address = [bool]$rule.from_address; domain = $rule.sender_domain; enabled = [bool]$g.enabled }
            $cur = $existingRules[$rule.name]
            if (-not $cur) {
                Invoke-Change 'regel_aanmaken' $rule.name $rule.sender_domain { Set-ARule $desired $false }
            } elseif ($cur.html -ne $desired.html -or -not (Test-MemberOfMatch $cur.from $desired.from) -or $cur.domain -ne $desired.domain -or [bool]$cur.enabled -ne $desired.enabled) {
                Invoke-Change 'regel_bijwerken' $rule.name $(if ($desired.enabled) { 'aan' } else { 'uit' }) { Set-ARule $desired $true }
            }
        }
        foreach ($old in $existingRules.Keys | Where-Object { $wantedNames -notcontains $_ }) {
            Invoke-Change 'regel_verwijderen' $old 'variant niet meer nodig' { Remove-ARule $old }
        }
        # DDG's van varianten die niet meer bestaan
        $wantedDdgs = @($g.rules | Where-Object { $_.ddg } | ForEach-Object { $_.ddg.name })
        foreach ($v in 'Compleet', 'AlleenTel', 'AlleenMobiel', 'GeenTelMobiel') {
            $dn = "$name-$v"
            if ($wantedDdgs -notcontains $dn -and (Get-ADdg $dn)) {
                Invoke-Change 'ddg_verwijderen' $dn '' { Remove-ADdg $dn }
            }
        }
    } catch {
        $Errors.Add("Groep ${name}: $($_.Exception.Message)")
    }
}

foreach ($del in $plan.deleted) {
    $name = $del.exchange_group
    try {
        foreach ($r in Get-ARules "$($plan.rule_prefix)$($del.id) - ") {
            Invoke-Change 'regel_verwijderen' $r.name 'groep verwijderd' { Remove-ARule $r.name }
        }
        foreach ($v in 'Compleet', 'AlleenTel', 'AlleenMobiel', 'GeenTelMobiel') {
            if (Get-ADdg "$name-$v") { Invoke-Change 'ddg_verwijderen' "$name-$v" '' { Remove-ADdg "$name-$v" } }
        }
        if (Get-AGroup $name) { Invoke-Change 'groep_verwijderen' $name '' { Remove-AGroup $name } }
    } catch {
        $Errors.Add("Verwijderen ${name}: $($_.Exception.Message)")
    }
}

if ($Mock -and -not $DryRun) {
    $S | ConvertTo-Json -Depth 8 | Set-Content -Path $MockStatePath -Encoding utf8
}
if (-not $Mock) { Disconnect-ExchangeOnline -Confirm:$false | Out-Null }

[pscustomobject]@{
    mode = $(if ($Mock) { 'mock' } else { 'live' })
    dry_run = [bool]$DryRun
    actions = $Actions
    errors = $Errors
} | ConvertTo-Json -Depth 6 -Compress
