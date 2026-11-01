<#
  Desinstallation de GescomAgent. A lancer en Administrateur :
    powershell -ExecutionPolicy Bypass -File .\uninstall.ps1             supprime la tache et C:\ProgramData\GescomAgent
    powershell -ExecutionPolicy Bypass -File .\uninstall.ps1 -KeepFiles  supprime la tache, garde config et journaux
#>
param([switch]$KeepFiles)

$ErrorActionPreference = 'Stop'
$TaskName = 'GescomAgent'
$Dir = Join-Path $env:ProgramData 'GescomAgent'

$pr = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) {
    Write-Host 'ERREUR : lancez ce script en tant qu''Administrateur.' -ForegroundColor Red
    exit 1
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host 'Tache planifiee supprimee.' -ForegroundColor Green
} else {
    Write-Host 'Aucune tache planifiee GescomAgent.'
}

if (-not $KeepFiles -and (Test-Path $Dir)) {
    Remove-Item $Dir -Recurse -Force
    Write-Host "Dossier $Dir supprime." -ForegroundColor Green
}

Write-Host 'Pensez a desactiver la branche dans le tableau de bord : son token reste valide jusque-la.' -ForegroundColor Yellow
