<#
  Installation de GescomAgent sur le serveur Gescomm d'une branche.
  A lancer en Administrateur, depuis le dossier qui contient agent.ps1 :

    powershell -ExecutionPolicy Bypass -File .\install.ps1

  Le script demande : l'adresse de l'API, le nom de la branche, le code d'installation
  (genere par le tableau de bord, valable une seule fois).

  Mettre a jour agent.ps1 sur une branche deja installee :
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -UpdateOnly
#>
param(
    [string]$ApiUrl,
    [string]$BranchName,
    [string]$InstallCode,
    [string]$Database = 'localhost:D:\Gescomm\LABASEN.IB',
    [string]$Isql = 'C:\Program Files (x86)\Borland\InterBase\bin\isql.exe',
    [string]$DbUser = 'SYSDBA',
    [string]$DbPassword = 'masterkey',
    [switch]$UpdateOnly
)

$ErrorActionPreference = 'Stop'
$DefaultApiUrl = 'https://stockallal.souftech.com/api'
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
function Invoke-Agent([string[]]$AgentArgs) {
    # Out-Host : la sortie de l'agent s'affiche sans se melanger a la valeur de retour
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $AgentPath -ConfigPath $ConfigPath @AgentArgs | Out-Host
    return $LASTEXITCODE
}

$pr = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) { Fail 'lancez ce script en tant qu''Administrateur.' }
if (-not (Test-Path $Src)) { Fail "agent.ps1 introuvable a cote de install.ps1 ($Src)" }

# ---------- mise a jour seule ----------
if ($UpdateOnly) {
    if (-not (Test-Path $ConfigPath)) { Fail 'agent non installe sur ce PC (config.json absent).' }
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Copy-Item $Src $AgentPath -Force
    Start-ScheduledTask -TaskName $TaskName
    Write-Host 'agent.ps1 mis a jour et tache redemarree.' -ForegroundColor Green
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
if (-not (Test-Path $Isql)) { Fail "isql introuvable : $Isql" }
Write-Host "Branche : $BranchName"
Write-Host "API     : $ApiUrl"
Write-Host "Base    : $Database"

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
Step '6/6 Tache planifiee (demarrage du PC + surveillance toutes les 5 minutes)'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$AgentPath`""
$atBoot = New-ScheduledTaskTrigger -AtStartup
$atBoot.Delay = 'PT1M'   # laisse InterBase et le reseau demarrer
# relance toutes les 5 minutes : sans effet si l'agent tourne deja (IgnoreNew), le redemarre s'il s'est arrete
$watchdog = New-ScheduledTaskTrigger -Daily -At '00:00'
$watchdog.Repetition = (New-ScheduledTaskTrigger -Once -At '00:00' `
    -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($atBoot, $watchdog) -Principal $principal `
    -Settings $settings -Description 'Envoie le stock Gescomm de cette branche vers l''API centrale (lecture seule).' -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "`nInstallation terminee." -ForegroundColor Green
Write-Host "Branche     : $BranchName"
Write-Host "Journal     : $Dir\logs\agent-$(Get-Date -Format yyyyMMdd).log"
Write-Host "Etat tache  : Get-ScheduledTask $TaskName"
Write-Host "Tester code : powershell -ExecutionPolicy Bypass -File `"$AgentPath`" -TestCode 820AC"
Read-Host "`nEntree pour fermer" | Out-Null
