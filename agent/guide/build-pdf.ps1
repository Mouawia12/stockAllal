<#
  Regenere agent/GescomAgent-Guide.pdf depuis agent/guide/guide.html avec Microsoft Edge (mode sans fenetre).
  Edge met en forme l'arabe correctement, ce que les generateurs PDF en Python ne font pas sans bibliotheques en plus.

    powershell -ExecutionPolicy Bypass -File .\agent\guide\build-pdf.ps1
#>
$ErrorActionPreference = 'Stop'
$edge = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'Microsoft Edge introuvable' }

$html = Join-Path $PSScriptRoot 'guide.html'
$pdf = Join-Path (Split-Path $PSScriptRoot -Parent) 'GescomAgent-Guide.pdf'
# profil separe : ne touche pas a l'Edge de l'utilisateur, meme s'il est ouvert
$profileDir = Join-Path $env:TEMP ('edge-pdf-' + [guid]::NewGuid().ToString('N'))
$url = 'file:///' + ($html -replace '\\', '/')

$p = Start-Process -FilePath $edge -PassThru -Wait -WindowStyle Hidden -ArgumentList @(
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-pdf-header-footer',
    "--user-data-dir=`"$profileDir`"", "--print-to-pdf=`"$pdf`"", "`"$url`""
)
Remove-Item $profileDir -Recurse -Force -ErrorAction SilentlyContinue
if (-not (Test-Path $pdf)) { throw "PDF non genere (code $($p.ExitCode))" }
Write-Host ("PDF : {0} ({1:N0} Ko)" -f $pdf, ((Get-Item $pdf).Length / 1KB))
