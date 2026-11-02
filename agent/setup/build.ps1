<#
  Compile agent\GescomAgent-Setup.exe (fenetre d'installation de l'agent) avec le csc.exe de .NET Framework 4,
  present sur tout Windows : rien a installer.

    powershell -ExecutionPolicy Bypass -File .\agent\setup\build.ps1

  Ecrit aussi agent\setup\build.sha256 (empreinte des sources de ce dossier + de l'exe) : commiter les deux ensemble.
  Le test PHP AgentSetupTest echoue si une source de ce dossier change sans nouvelle compilation.
  agent.ps1 n'est pas dans l'exe (il copie celui de son dossier) : modifier l'agent ne demande pas de recompiler.

  -OutFile <exe> -AsInvoker : copie de test (sans demande d'elevation), ne touche ni a l'exe commite ni a build.sha256.
  -Check : verifie seulement que l'exe et build.sha256 correspondent aux sources (code de sortie 1 sinon).
#>
param(
    [string]$OutFile,
    [switch]$AsInvoker,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
$AgentDir = Split-Path $PSScriptRoot -Parent
$ReleaseExe = Join-Path $AgentDir 'GescomAgent-Setup.exe'
$HashFile = Join-Path $PSScriptRoot 'build.sha256'

function Get-Sha256Hex([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    return (-join ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') }))
}

# Meme calcul que tests/Feature/AgentSetupTest.php : fichiers du dossier (sauf build.sha256 et fichiers caches)
# par ordre ordinal, chacun precede de "== nom\n", sans les \r (le depot stocke en LF, Windows peut extraire en CRLF).
function Get-SourceHash {
    $names = [string[]]@(Get-ChildItem -LiteralPath $PSScriptRoot -File |
        Where-Object { $_.Name -ne 'build.sha256' -and -not $_.Name.StartsWith('.') } | ForEach-Object { $_.Name })
    [Array]::Sort($names, [StringComparer]::Ordinal)
    $latin1 = [Text.Encoding]::GetEncoding(28591)   # octet <-> caractere, sans conversion
    $sb = New-Object Text.StringBuilder
    foreach ($n in $names) {
        [void]$sb.Append("== $n`n")
        [void]$sb.Append($latin1.GetString([IO.File]::ReadAllBytes((Join-Path $PSScriptRoot $n))).Replace("`r", ''))
    }
    return Get-Sha256Hex ($latin1.GetBytes($sb.ToString()))
}

function Read-HashFile {
    $h = @{}
    if (Test-Path -LiteralPath $HashFile) {
        foreach ($line in [IO.File]::ReadAllLines($HashFile)) {
            $p = $line.Split(' ')
            if ($p.Count -eq 2) { $h[$p[0]] = $p[1] }
        }
    }
    return $h
}

if ($Check) {
    $h = Read-HashFile
    $problems = @()
    if ($h['source'] -ne (Get-SourceHash)) { $problems += 'les sources de agent\setup ont change depuis la derniere compilation' }
    if (-not (Test-Path -LiteralPath $ReleaseExe)) { $problems += 'agent\GescomAgent-Setup.exe absent' }
    elseif ($h['exe'] -ne (Get-Sha256Hex ([IO.File]::ReadAllBytes($ReleaseExe)))) { $problems += 'GescomAgent-Setup.exe ne correspond pas a build.sha256' }
    if ($problems.Count) {
        $problems | ForEach-Object { Write-Host "A RECOMPILER : $_" -ForegroundColor Red }
        Write-Host 'Lancez agent\setup\build.ps1 puis commitez GescomAgent-Setup.exe et build.sha256.'
        exit 1
    }
    Write-Host 'GescomAgent-Setup.exe est a jour.' -ForegroundColor Green
    exit 0
}

$csc = @(
    (Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'),
    (Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe')
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $csc) { throw 'csc.exe de .NET Framework 4 introuvable' }

$release = -not $OutFile
if ($release) { $OutFile = $ReleaseExe }
$manifest = Join-Path $PSScriptRoot 'app.manifest'
$tmpManifest = $null
if ($AsInvoker) {
    $tmpManifest = Join-Path $env:TEMP ('gescom-setup-' + [guid]::NewGuid().ToString('N') + '.manifest')
    [IO.File]::WriteAllText($tmpManifest, ([IO.File]::ReadAllText($manifest) -replace 'requireAdministrator', 'asInvoker'))
    $manifest = $tmpManifest
}

try {
    # /codepage:65001 : les textes arabes de la fenetre sont en UTF-8 dans le source
    $sources = @(Get-ChildItem -LiteralPath $PSScriptRoot -Filter '*.cs' | ForEach-Object { $_.FullName })
    $out = & $csc /nologo /target:winexe /platform:anycpu /optimize+ /codepage:65001 /warnaserror+ `
        "/out:$OutFile" "/win32manifest:$manifest" `
        /r:System.dll /r:System.Drawing.dll /r:System.Windows.Forms.dll /r:System.Web.Extensions.dll /r:System.ServiceProcess.dll `
        $sources
    $code = $LASTEXITCODE
} finally {
    if ($tmpManifest) { Remove-Item $tmpManifest -ErrorAction SilentlyContinue }
}
if ($out) { $out | ForEach-Object { Write-Host $_ } }
if ($code -ne 0) { throw "compilation echouee (csc code $code)" }

if ($release) {
    $text = "source $(Get-SourceHash)`nexe $(Get-Sha256Hex ([IO.File]::ReadAllBytes($OutFile)))`n"
    [IO.File]::WriteAllText($HashFile, $text, (New-Object Text.UTF8Encoding($false)))
    Write-Host "build.sha256 mis a jour."
}
Write-Host ('{0} ({1:N0} Ko)' -f $OutFile, ((Get-Item $OutFile).Length / 1KB)) -ForegroundColor Green
