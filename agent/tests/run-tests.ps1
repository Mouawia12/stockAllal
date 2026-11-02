<#
  Tests automatiques de l'agent et de GescomAgent-Setup.exe, sur Windows (de preference le serveur Gescomm d'une branche) :

    powershell -ExecutionPolicy Bypass -File .\agent\tests\run-tests.ps1

  Ne modifie rien sur le PC et n'a pas besoin des droits Administrateur. La base Gescomm est seulement lue (isql, lecture seule).
  Sans Gescomm sur le PC, les tests qui en ont besoin sont sautes.
  Le test d'installation complet (tache planifiee, enregistrement aupres d'un Laravel local) : e2e-install.ps1, en Administrateur.
  Code de sortie : nombre de tests en echec.
#>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ui.ps1')

$AgentDir = Split-Path $PSScriptRoot -Parent
$Work = Join-Path $PSScriptRoot '.work'
$Pkg = Join-Path $Work 'pkg'
$Alone = Join-Path $Work 'alone'
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltinRole]::Administrator)
# dossier et tache de test : jamais ceux de l'agent installe
$TestArgs = '/dir:"{0}" /task:GescomAgentTest' -f (Join-Path $Work 'install')
foreach ($d in $Pkg, $Alone) { New-Item -ItemType Directory -Force -Path $d | Out-Null }

Write-Host "== Scripts de l'agent"
foreach ($f in @(Get-ChildItem $AgentDir -Filter '*.ps1') + @(Get-ChildItem (Join-Path $AgentDir 'setup'), $PSScriptRoot -Filter '*.ps1')) {
    Test-Case "$($f.Name) : ASCII seulement et sans erreur de syntaxe" {
        # PowerShell 5.1 lit un fichier sans BOM en ANSI : un caractere non ASCII y serait corrompu
        $bytes = [IO.File]::ReadAllBytes($f.FullName)
        $bad = @($bytes | Where-Object { $_ -gt 127 }).Count
        Assert ($bad -eq 0) "$bad octet(s) non ASCII"
        $errors = $null
        [void][Management.Automation.Language.Parser]::ParseFile($f.FullName, [ref]$null, [ref]$errors)
        Assert (@($errors).Count -eq 0) ($errors | ForEach-Object { $_.Message }) -join ' | '
    }
}

Write-Host "`n== Compilation"
Test-Case 'GescomAgent-Setup.exe commite correspond a ses sources (build.ps1 -Check)' {
    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $AgentDir 'setup\build.ps1') -Check
    Assert ($LASTEXITCODE -eq 0) ($out -join ' ')
}
$TestExe = Join-Path $Pkg 'GescomAgent-Setup.exe'
Test-Case 'copie de test compilee sans avertissement (sans demande d''elevation)' {
    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $AgentDir 'setup\build.ps1') -OutFile $TestExe -AsInvoker
    Assert ($LASTEXITCODE -eq 0) ($out -join ' ')
    Copy-Item (Join-Path $AgentDir 'agent.ps1') $Pkg -Force
}

Write-Host "`n== Detection (sans fenetre)"
$detected = $null
Test-Case 'l''exe et install.ps1 -Detect trouvent la meme base et le meme isql' {
    $json = Join-Path $Work 'detect.json'
    $p = Start-Process $TestExe -ArgumentList "/detect:`"$json`" $TestArgs" -PassThru -Wait
    Assert ($p.ExitCode -eq 0) "code de sortie $($p.ExitCode)"
    $script:detected = Get-Content $json -Raw -Encoding UTF8 | ConvertFrom-Json
    $ps = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $AgentDir 'install.ps1') -Detect | ConvertFrom-Json
    Assert ($script:detected.database -eq $ps.database) "base : exe '$($script:detected.database)', install.ps1 '$($ps.database)'"
    Assert ($script:detected.isql -eq $ps.isql) "isql : exe '$($script:detected.isql)', install.ps1 '$($ps.isql)'"
    Assert ($script:detected.package_version -match '^\d+\.\d+\.\d+$') "version du paquet : '$($script:detected.package_version)'"
    Assert ($null -eq $script:detected.installed) 'le dossier de test ne doit pas contenir d''agent installe'
}

$hasGescomm = $detected -and $detected.database
if ($hasGescomm) {
    Test-Case "lecture de la base ($($detected.database)), comme l'etape 3 de l'installation" {
        $json = Join-Path $Work 'testdb.json'
        $p = Start-Process $TestExe -ArgumentList "/testdb:`"$json`" $TestArgs" -PassThru -Wait
        $r = Get-Content $json -Raw -Encoding UTF8 | ConvertFrom-Json
        Assert ($p.ExitCode -eq 0 -and $r.db_ok) "echec : $($r.db_error)"
        Assert ($r.db_items -gt 0) 'aucun article dans GC003'
        Write-Host "        $($r.db_items) articles, $($r.db_fields) colonnes dans GC003"
    }
} else {
    Skip-Case 'lecture de la base' 'pas de Gescomm sur ce PC'
}

Write-Host "`n== Fenetre"
$p = $null
try {
    Test-Case 'ouverture en francais et affichage de ce qui a ete trouve' {
        $script:p = Start-Setup $TestExe "/lang:fr $TestArgs"
        [void](Wait-Element $script:p '^Installation de l''agent de stock$')
        if ($hasGescomm) { [void](Wait-Element $script:p ('^' + $OkMark + '  ' + [regex]::Escape($detected.database))) }
        else { [void](Wait-Element $script:p ('^' + $NoMark + '  introuvable$')) }
        $install = Wait-Element $script:p '^Installer$'
        Assert (Test-Enabled $install) 'le bouton Installer est desactive'
    }
    Test-Case 'champs vides : message, rien n''est lance' {
        Click-Button $script:p 'Installer'
        [void](Wait-Element $script:p '^Saisissez le nom de la succursale et le code d''installation\.$' 10)
    }
    if (-not $isAdmin) {
        Test-Case 'sans droits Administrateur : refuse avant toute modification' {
            Set-Field $script:p 'Nom de la succursale' 'Test automatique'
            Set-Field $script:p 'Code d''installation' 'ABC-DEF-GHJ'
            Click-Button $script:p 'Installer'
            [void](Wait-Element $script:p '^Lancez l''installateur en tant qu''administrateur\.$' 15)
            [void](Wait-Element $script:p 'ERREUR : lancez l''installateur en tant qu''Administrateur' 5)
            Assert (-not (Test-Path (Join-Path $Work 'install'))) 'un dossier d''installation a ete cree'
        }
    } else {
        Skip-Case 'sans droits Administrateur : refuse' 'lance en Administrateur (voir e2e-install.ps1)'
    }
} finally {
    Stop-Setup $p
}

$p = $null
try {
    Test-Case 'arabe : textes arabes, fenetre de droite a gauche' {
        $script:p = Start-Setup $TestExe "/lang:fr $TestArgs"
        $en = Wait-Element $script:p '^EN$'
        $win = (Get-Root $script:p).Current.BoundingRectangle
        $mid = $win.Left + $win.Width / 2
        Assert ($en.Current.BoundingRectangle.Left -gt $mid) ("en francais, EN doit etre a droite (EN a {0}, milieu {1})" -f $en.Current.BoundingRectangle.Left, $mid)
        Click-Button $script:p (U '\u0639\u0631\u0628\u064a')
        [void](Wait-Element $script:p ('^' + (U '\u062a\u0631\u0643\u064a\u0628 \u0648\u0643\u064a\u0644 \u0627\u0644\u0645\u062e\u0632\u0648\u0646') + '$') 10)
        [void](Wait-Element $script:p ('^' + (U '\u0627\u0633\u0645 \u0627\u0644\u0641\u0631\u0639') + '$'))
        $en = Wait-Element $script:p '^EN$'
        Assert ($en.Current.BoundingRectangle.Right -lt $mid) ("en arabe, EN doit etre a gauche (EN a {0}, milieu {1})" -f $en.Current.BoundingRectangle.Right, $mid)
        Click-Button $script:p 'EN'
        [void](Wait-Element $script:p '^Stock agent setup$' 10)
    }
} finally {
    Stop-Setup $p
}

$p = $null
try {
    Test-Case 'exe seul (ouvert depuis le zip sans extraire) : message et installation impossible' {
        Copy-Item $TestExe $Alone -Force
        $script:p = Start-Setup (Join-Path $Alone 'GescomAgent-Setup.exe') "/lang:fr $TestArgs"
        [void](Wait-Element $script:p '^Les fichiers de l''agent ne sont pas . c.t. de l''installateur' 10)
        Assert (-not (Test-Enabled (Wait-Element $script:p '^Installer$'))) 'le bouton Installer doit etre desactive'
    }
} finally {
    Stop-Setup $p
}

exit (Write-Summary)
