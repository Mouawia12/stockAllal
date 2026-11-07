<#
  Installation de GescomAgent sur le serveur Gescomm d'une branche.
  Le plus simple : GescomAgent-Setup.exe (une fenetre, dans le meme dossier). En console, en Administrateur,
  depuis le dossier qui contient agent.ps1 :

    powershell -ExecutionPolicy Bypass -File .\install.ps1

  Le script trouve seul la base Gescomm et isql (registre, puis disques) et demande : l'adresse de l'API,
  le nom de la branche, le code d'installation (genere par le tableau de bord, valable une seule fois).

  Mettre a jour agent.ps1 sur une branche deja installee (la tache planifiee est reecrite aussi) :
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -UpdateOnly

  Voir ce que le script trouve, sans rien modifier (JSON) :
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -Detect
#>
param(
    [string]$ApiUrl,
    [string]$BranchName,
    [string]$InstallCode,
    [string]$Database,
    [string]$Isql,
    [string]$DbUser = 'SYSDBA',
    [string]$DbPassword = 'masterkey',
    [switch]$UpdateOnly,
    [switch]$Detect
)

$ErrorActionPreference = 'Stop'
$DefaultApiUrl = 'https://stockallal.souftech.com/api'
# Gescomm.exe ouvre toujours LaBaseN.ib dans son propre dossier (ExtractFilePath de l'exe + '\LaBaseN.ib')
$DbFileName = 'LABASEN.IB'
$TaskName = 'GescomAgent'
$Dir = Join-Path $env:ProgramData 'GescomAgent'
$ConfigPath = Join-Path $Dir 'config.json'
$AgentPath = Join-Path $Dir 'agent.ps1'
$Src = Join-Path $PSScriptRoot 'agent.ps1'

function Fail([string]$Message) {
    Write-Host "ERREUR : $Message" -ForegroundColor Red
    Read-Host 'Entree pour fermer' | Out-Null
    exit 1
}
function Step([string]$Message) { Write-Host "`n== $Message" -ForegroundColor Cyan }

# ---------- tache planifiee : le meme XML que GescomAgent-Setup.exe (Installer.TaskXml, compare par tests\run-tests.ps1) ----------
# Declencheurs : demarrage du PC (+1 min), retour de veille / d'hibernation / du demarrage rapide de Windows (+1 min ;
# demarrage rapide actif par defaut : "Arreter" = veille prolongee, le rallumage n'est pas un demarrage), et toutes les
# 5 minutes sans fin (sans effet si l'agent tourne deja : IgnoreNew ; le relance s'il s'est arrete).
$ResumeQuery = '<QueryList><Query Id="0" Path="System"><Select Path="System">' +
    "*[System[Provider[@Name='Microsoft-Windows-Power-Troubleshooter'] and EventID=1]]</Select></Query></QueryList>"
function Get-TaskXml {
    $agentArgs = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$AgentPath`" -ConfigPath `"$ConfigPath`""
    $resume = [Security.SecurityElement]::Escape($ResumeQuery)
    $action = [Security.SecurityElement]::Escape($agentArgs)
    # calendrier gregorien quelle que soit la langue de Windows
    $today = (Get-Date).ToString('yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
    return @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Envoie le stock Gescomm de cette branche vers l'API centrale (lecture seule).</Description></RegistrationInfo>
  <Triggers>
    <BootTrigger><Enabled>true</Enabled><Delay>PT1M</Delay></BootTrigger>
    <EventTrigger><Enabled>true</Enabled><Subscription>$resume</Subscription><Delay>PT1M</Delay></EventTrigger>
    <TimeTrigger>
      <Repetition><Interval>PT5M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition>
      <StartBoundary>${today}T00:00:00</StartBoundary>
      <Enabled>true</Enabled>
    </TimeTrigger>
  </Triggers>
  <Principals><Principal id="Author"><UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel></Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <StartWhenAvailable>true</StartWhenAvailable>
    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
  </Settings>
  <Actions Context="Author"><Exec><Command>powershell.exe</Command><Arguments>$action</Arguments></Exec></Actions>
</Task>
"@
}
# Cree ou reecrit la tache (compte SYSTEM) puis la lance : meme appel COM que l'exe
# (TASK_CREATE_OR_UPDATE = 6, TASK_LOGON_SERVICE_ACCOUNT = 5).
function Register-AgentTask {
    $svc = New-Object -ComObject Schedule.Service
    $svc.Connect()
    $task = $svc.GetFolder('\').RegisterTask($TaskName, (Get-TaskXml), 6, 'SYSTEM', $null, 5, $null)
    [void]$task.Run($null)
}
function Invoke-Agent([string[]]$AgentArgs) {
    # Out-Host : la sortie de l'agent s'affiche sans se melanger a la valeur de retour
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $AgentPath -ConfigPath $ConfigPath @AgentArgs | Out-Host
    return $LASTEXITCODE
}

# ---------- detection : Gescomm, sa base, isql (meme logique que GescomAgent-Setup.exe) ----------

# Dossiers ou Gescomm peut etre installe, du plus sur au moins sur.
function Get-GescommDirs {
    $keys = 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*',
            'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
    foreach ($e in @(Get-ItemProperty $keys -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like 'Gescom*' })) {
        if ($e.InstallLocation) { $e.InstallLocation }
        foreach ($s in @($e.DisplayIcon, $e.UninstallString)) {
            $m = [regex]::Match([string]$s, '[A-Za-z]:\\[^"]*?\.exe')
            if ($m.Success) { Split-Path -Parent $m.Value }
        }
    }
    foreach ($p in @(Get-Process -Name 'Gescomm' -ErrorAction SilentlyContinue)) {
        if ($p.Path) { Split-Path -Parent $p.Path }
    }
    foreach ($d in [IO.DriveInfo]::GetDrives()) {
        if ($d.DriveType -ne 'Fixed' -or -not $d.IsReady) { continue }
        foreach ($name in 'Gescomm', 'Gescom') { Join-Path $d.RootDirectory.FullName $name }
    }
}

function Find-GescommDb {
    foreach ($d in @(Get-GescommDirs)) {
        if (-not $d) { continue }
        $f = Join-Path ([string]$d).TrimEnd('\') $DbFileName
        if (Test-Path -LiteralPath $f -PathType Leaf) { return (Get-Item -LiteralPath $f) }
    }
    return $null
}

function Find-Isql {
    $roots = @()
    foreach ($k in 'HKLM:\SOFTWARE\WOW6432Node\Borland\InterBase', 'HKLM:\SOFTWARE\Borland\InterBase') {
        foreach ($sub in 'Servers', 'CurrentVersion') {
            $roots += @(Get-ItemProperty "$k\$sub" -ErrorAction SilentlyContinue | ForEach-Object { $_.RootDirectory })
            # une instance par sous-cle (Servers\gds_db) selon la version d'InterBase
            $roots += @(Get-ChildItem "$k\$sub" -ErrorAction SilentlyContinue | Get-ItemProperty -ErrorAction SilentlyContinue |
                ForEach-Object { $_.RootDirectory })
        }
    }
    $roots += @(${env:ProgramFiles(x86)}, $env:ProgramFiles | Where-Object { $_ } | ForEach-Object { Join-Path $_ 'Borland\InterBase' })
    foreach ($r in $roots) {
        if (-not $r) { continue }
        $f = Join-Path $r 'bin\isql.exe'
        if (Test-Path -LiteralPath $f -PathType Leaf) { return $f }
    }
    return $null
}

if ($Detect) {
    $db = Find-GescommDb
    $info = [ordered]@{
        database          = $null
        database_mtime    = $null
        isql              = Find-Isql
        interbase_running = [bool]@(Get-Service -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -match '^(IBS_|InterBaseServer)' -and $_.Status -eq 'Running' }).Count
        # la tache que l'installation creerait (rien n'est enregistre)
        task_xml          = Get-TaskXml
    }
    if ($db) {
        $info.database = $db.FullName
        $info.database_mtime = $db.LastWriteTime.ToString('yyyy-MM-dd HH:mm')
    }
    Write-Output (ConvertTo-Json $info -Compress)
    exit 0
}

$pr = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) { Fail 'lancez ce script en tant qu''Administrateur.' }
if (-not (Test-Path $Src)) { Fail "agent.ps1 introuvable a cote de install.ps1 ($Src)" }

# ---------- mise a jour seule ----------
if ($UpdateOnly) {
    if (-not (Test-Path $ConfigPath)) { Fail 'agent non installe sur ce PC (config.json absent).' }
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Copy-Item $Src $AgentPath -Force
    # tache toujours reecrite : une branche installee avant 1.0.9 recoit les declencheurs d'aujourd'hui
    Register-AgentTask
    Write-Host 'agent.ps1 mis a jour, tache planifiee reecrite (demarrage, retour de veille, toutes les 5 minutes) et redemarree.' -ForegroundColor Green
    exit 0
}

if (Test-Path $ConfigPath) {
    $old = Get-Content $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($old.token) { Fail "agent deja installe (branche '$($old.branch_name)'). Mise a jour : -UpdateOnly. Reinstallation : uninstall.ps1 d'abord." }
}

# ---------- 1. informations ----------
Step '1/6 Informations'
if (-not $ApiUrl) {
    $ApiUrl = Read-Host "Adresse de l'API [Entree = $DefaultApiUrl]"
    if (-not $ApiUrl.Trim()) { $ApiUrl = $DefaultApiUrl }
}
if (-not $BranchName) { $BranchName = Read-Host 'Nom de la branche' }
if (-not $InstallCode) { $InstallCode = Read-Host 'Code d''installation (tableau de bord)' }
$ApiUrl = $ApiUrl.Trim().TrimEnd('/')
$BranchName = $BranchName.Trim()
$InstallCode = $InstallCode.Trim()
if (-not $ApiUrl -or -not $BranchName -or -not $InstallCode) { Fail 'adresse API, nom de branche et code d''installation sont obligatoires.' }
if (-not $Isql) { $Isql = Find-Isql }
if (-not $Isql -or -not (Test-Path -LiteralPath $Isql)) { Fail "isql introuvable ($Isql). InterBase est-il installe sur ce PC ? Sinon : -Isql '...\isql.exe'" }
if (-not $Database) {
    $db = Find-GescommDb
    if ($db) { $Database = "localhost:$($db.FullName)" }
}
# autre branche, autre disque : si la base n'est pas trouvee, on demande ou elle est
$dbFile = [regex]::Match([string]$Database, '[A-Za-z]:\\.+$').Value
if (-not $dbFile -or -not (Test-Path -LiteralPath $dbFile)) {
    Write-Host "Base Gescomm ($DbFileName) introuvable sur ce PC. $dbFile" -ForegroundColor Yellow
    $typed = (Read-Host 'Chemin complet du fichier .IB de Gescomm (ex : E:\Gescomm\LABASEN.IB)').Trim().Trim('"')
    if (-not $typed -or -not (Test-Path -LiteralPath $typed)) { Fail "fichier introuvable : $typed" }
    $Database = "localhost:$typed"
}
Write-Host "Branche : $BranchName"
Write-Host "API     : $ApiUrl"
Write-Host "Base    : $Database"
Write-Host "isql    : $Isql"

# ---------- 2. dossier protege ----------
Step '2/6 Dossier C:\ProgramData\GescomAgent (acces : SYSTEM et Administrateurs seulement)'
New-Item -ItemType Directory -Force -Path $Dir | Out-Null
# SID au lieu des noms : fonctionne sur un Windows francais ("Administrateurs") comme anglais
& icacls.exe $Dir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { Fail 'icacls a echoue sur le dossier de l''agent.' }
Copy-Item $Src $AgentPath -Force
$cfg = [ordered]@{
    api_url           = $ApiUrl
    token             = ''
    branch_name       = $BranchName
    database          = $Database
    isql              = $Isql
    db_user           = $DbUser
    db_password       = $DbPassword
    poll_seconds      = 10
    full_sync_minutes = 5
}
[IO.File]::WriteAllText($ConfigPath, (ConvertTo-Json $cfg), (New-Object Text.UTF8Encoding($false)))
Write-Host 'OK'

# ---------- 3. test de la base (avant de consommer le code d'installation) ----------
Step '3/6 Test de la base Gescomm (lecture seule)'
if ((Invoke-Agent @('-SelfTest', '-SkipApi')) -ne 0) {
    Fail 'base inaccessible ou table GC003 absente. Mot de passe different ? Relancez avec -DbPassword ...'
}

# ---------- 4. enregistrement aupres de l'API ----------
Step '4/6 Enregistrement de la branche aupres de l''API'
if ((Invoke-Agent @('-Register', '-BranchName', $BranchName, '-InstallCode', $InstallCode)) -ne 0) {
    Fail 'enregistrement refuse : code d''installation invalide/expire, ou API injoignable.'
}

# ---------- 5. test complet ----------
Step '5/6 Test complet (base + API)'
if ((Invoke-Agent @('-SelfTest')) -ne 0) { Fail 'le test complet a echoue (voir le message ci-dessus).' }

# ---------- 6. tache planifiee ----------
Step '6/6 Tache planifiee (demarrage du PC, retour de veille + surveillance toutes les 5 minutes)'
try { Register-AgentTask } catch { Fail "tache planifiee : $($_.Exception.Message)" }

Write-Host "`nInstallation terminee." -ForegroundColor Green
Write-Host "Branche     : $BranchName"
Write-Host "Journal     : $Dir\logs\agent-$(Get-Date -Format yyyyMMdd).log"
Write-Host "Etat tache  : Get-ScheduledTask $TaskName"
Write-Host "Tester code : powershell -ExecutionPolicy Bypass -File `"$AgentPath`" -TestCode 820AC"
Read-Host "`nEntree pour fermer" | Out-Null
