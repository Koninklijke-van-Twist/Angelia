<#
.SYNOPSIS
    Kleine client voor de Angelia-API, voor New-KvtUser.ps1 en Offboard-User.ps1.
    Zet naast de scripts en laad met:  Import-Module (Join-Path $PSScriptRoot 'Angelia.psm1')

    De API-sleutel komt NIET in het script: zet hem in de omgevingsvariabele ANGELIA_API_KEY
    (of geef -ApiKey mee). Sleutel heeft scope 'read' + 'assign' nodig (zie $apiKeys in auth.php).
#>

$script:AngeliaBase = if ($env:ANGELIA_URL) { $env:ANGELIA_URL } else { 'https://sleutels.kvt.nl/angelia' }

function Invoke-AngeliaApi {
    param([string]$Action, [string]$Method = 'GET', [hashtable]$Query = @{}, [hashtable]$Body, [string]$ApiKey = $env:ANGELIA_API_KEY)
    if (-not $ApiKey) { throw 'ANGELIA_API_KEY ontbreekt.' }
    $qs = (@("action=$([uri]::EscapeDataString($Action))") + ($Query.GetEnumerator() | ForEach-Object {
        "$($_.Key)=$([uri]::EscapeDataString([string]$_.Value))" })) -join '&'
    $p = @{ Uri = "$script:AngeliaBase/api.php?$qs"; Method = $Method; Headers = @{ 'X-API-Key' = $ApiKey }; ErrorAction = 'Stop' }
    if ($Body) { $p.Body = ($Body | ConvertTo-Json -Compress); $p.ContentType = 'application/json' }
    Invoke-RestMethod @p
}

function Get-AngeliaGroups {
    <# Groepen, optioneel alleen voor één e-maildomein (bv. kvt.nl). #>
    param([string]$Domain)
    $groups = (Invoke-AngeliaApi -Action groups).groups
    if ($Domain) { $groups = $groups | Where-Object { $_.domain -eq $Domain } }
    @($groups)
}

function Set-AngeliaMember {
    <# Zet een gebruiker in precies één handtekening-groep (haalt hem uit andere Angelia-groepen). #>
    param([Parameter(Mandatory)][string]$Email, [Parameter(Mandatory)][string]$Group)
    Invoke-AngeliaApi -Action assign -Method POST -Body @{ email = $Email; group = $Group }
}

function Remove-AngeliaMember {
    <# Haalt een gebruiker uit alle handtekening-groepen (offboarding). #>
    param([Parameter(Mandatory)][string]$Email)
    Invoke-AngeliaApi -Action unassign -Method POST -Body @{ email = $Email }
}

function Get-AngeliaSignature {
    <# HTML-handtekening voor één gebruiker (bv. als client-side terugval of ter controle). #>
    param([Parameter(Mandatory)][string]$Email, [string]$Group, [string]$Name, [string]$Title, [string]$Phone, [string]$Mobile)
    $q = @{ email = $Email; name = $Name; title = $Title; phone = $Phone; mobile = $Mobile }
    if ($Group) { $q.group = $Group }
    Invoke-AngeliaApi -Action signature -Query $q
}

Export-ModuleMember -Function Get-AngeliaGroups, Set-AngeliaMember, Remove-AngeliaMember, Get-AngeliaSignature
