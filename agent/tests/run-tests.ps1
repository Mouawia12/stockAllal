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
foreach ($f in @(Get-ChildItem $AgentDir -Filter '*.ps1') + @(Get-ChildItem (Join-Path $AgentDir 'setup'), (Join-Path $AgentDir 'tools'), $PSScriptRoot -Filter '*.ps1')) {
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
    $script:psDetected = $ps
    Assert ($script:detected.database -eq $ps.database) "base : exe '$($script:detected.database)', install.ps1 '$($ps.database)'"
    Assert ($script:detected.isql -eq $ps.isql) "isql : exe '$($script:detected.isql)', install.ps1 '$($ps.isql)'"
    Assert ($script:detected.package_version -match '^\d+\.\d+\.\d+$') "version du paquet : '$($script:detected.package_version)'"
    Assert ($null -eq $script:detected.installed) 'le dossier de test ne doit pas contenir d''agent installe'
}

Test-Case 'tache planifiee : l''exe et install.ps1 ecrivent la meme, et Windows l''accepte (rien n''est enregistre)' {
    $exeXml = [string]$script:detected.task_xml
    $psXml = [string]$script:psDetected.task_xml
    Assert ($exeXml -and $psXml) 'task_xml absent de /detect ou de install.ps1 -Detect'
    $nsUri = 'http://schemas.microsoft.com/windows/2004/02/mit/task'
    $docs = foreach ($x in $exeXml, $psXml) {
        $doc = New-Object Xml.XmlDocument
        $doc.LoadXml($x)
        $ns = New-Object Xml.XmlNamespaceManager($doc.NameTable)
        $ns.AddNamespace('t', $nsUri)
        , @($doc, $ns)
    }
    # memes declencheurs, compte et reglages (les chemins de l'action different : dossier de test pour l'exe)
    foreach ($part in 'Triggers', 'Principals', 'Settings') {
        $a = $docs[0][0].SelectSingleNode("/t:Task/t:$part", $docs[0][1]).OuterXml
        $b = $docs[1][0].SelectSingleNode("/t:Task/t:$part", $docs[1][1]).OuterXml
        Assert ($a -and $a -eq $b) "$part : exe et install.ps1 differents"
    }
    $doc, $ns = $docs[0]
    $kinds = @($doc.SelectNodes('/t:Task/t:Triggers/*', $ns) | ForEach-Object { $_.LocalName }) -join ','
    Assert ($kinds -eq 'BootTrigger,EventTrigger,TimeTrigger') "declencheurs : $kinds"
    $rep = $doc.SelectSingleNode('/t:Task/t:Triggers/t:TimeTrigger/t:Repetition', $ns)
    Assert ($rep.Interval -eq 'PT5M' -and -not $rep.SelectSingleNode('t:Duration', $ns)) 'surveillance : toutes les 5 minutes, sans fin'
    # le Planificateur valide chaque XML (definition en memoire, jamais enregistree)
    $svc = New-Object -ComObject Schedule.Service
    $svc.Connect()
    foreach ($x in $exeXml, $psXml) { $def = $svc.NewTask(0); $def.XmlText = $x }
    # retour de veille / demarrage rapide : la requete est valide, et trouve l'evenement si ce PC est deja sorti de veille
    $query = $doc.SelectSingleNode('/t:Task/t:Triggers/t:EventTrigger/t:Subscription', $ns).InnerText
    try {
        $e = Get-WinEvent -FilterXml ([xml]$query) -MaxEvents 1 -ErrorAction Stop
        Write-Host "        dernier retour de veille sur ce PC : $($e.TimeCreated)"
    } catch {
        Assert ($_.FullyQualifiedErrorId -like 'NoMatchingEventsFound*') "requete du retour de veille refusee : $($_.Exception.Message)"
    }
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
    # agent 1.0.7 : ventes, achats, clients, fournisseurs, versements ; 1.0.8 : la caisse. Lus comme le fait la boucle
    # (lecture seule, rien n'est envoye)
    Test-Case 'agent.ps1 -TestData : chaque jeu se lit, ses empreintes et ses groupes sont coherents' {
        $dataDir = Join-Path $Work 'testdata'
        New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
        $cfgFile = Join-Path $dataDir 'config.json'
        # la detection rend le chemin seul ; l'installation ecrit "localhost:" devant (Installer.cs), comme ici.
        # Sans lui, isql passe par la connexion locale, que le serveur InterBase refuse ("unavailable database").
        $db = $detected.database
        if ($db -notmatch '^[^\\]+:[A-Za-z]:\\') { $db = 'localhost:' + $db }
        $testCfg = [ordered]@{ database = $db; isql = $detected.isql }
        [IO.File]::WriteAllText($cfgFile, (ConvertTo-Json $testCfg), (New-Object Text.UTF8Encoding($false)))
        $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $AgentDir 'agent.ps1') -ConfigPath $cfgFile -TestData -Days 30
        $code = $LASTEXITCODE
        $out | ForEach-Object { Write-Host "        $_" }
        Assert ($code -eq 0) 'un jeu ne se lit pas (voir ci-dessus)'
        foreach ($name in 'sales', 'purchases', 'customers', 'suppliers', 'customer_payments', 'supplier_payments', 'cash_in', 'cash_out') {
            $file = Join-Path $dataDir "outbox\data-$name.json"
            Assert (Test-Path $file) "$name : pas de fichier $file"
            $d = Get-Content $file -Raw -Encoding UTF8 | ConvertFrom-Json
            foreach ($g in $d.groups.PSObject.Properties) {
                $records = @($g.Value)
                [long]$sum = 0
                foreach ($r in $records) {
                    Assert (-not [string]::IsNullOrEmpty([string]$r.key)) "$name / $($g.Name) : enregistrement sans cle"
                    Assert ([long]$r.digest -ge 0 -and [long]$r.digest -le 281474976710655) "$name / $($g.Name) : empreinte hors 48 bits"
                    $sum += [long]$r.digest
                }
                $expected = '{0}:{1}' -f $records.Count, $sum
                Assert ($d.digests.($g.Name) -eq $expected) "$name / $($g.Name) : empreinte du groupe $($d.digests.($g.Name)), attendu $expected"
                if ($name -notin 'customers', 'suppliers') {
                    Assert ($g.Name -match '^\d{4}-\d{2}-\d{2}$') "$name : groupe '$($g.Name)' n'est pas un jour"
                }
            }
            if ($name -in 'customers', 'suppliers') { Assert (@($d.groups.PSObject.Properties).Count -gt 0) "$name : aucun enregistrement" }
        }
        # memes totaux que Gescomm sur les memes 30 jours : lignes de vente, quantite, montant ; entrees et sorties de
        # la caisse (isql, lecture seule)
        $from = (Get-Date).Date.AddDays(-29).ToString('yyyy-MM-dd')
        $to = (Get-Date).Date.ToString('yyyy-MM-dd')
        $sqlFile = Join-Path $dataDir 'totals.sql'
        [IO.File]::WriteAllLines($sqlFile, [string[]]@('SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;', 'SET LIST ON;',
            "SELECT COUNT(*) AS N, SUM(L.QTE) AS Q, SUM(L.TOTAL) AS T FROM GC010 H JOIN GC011 L ON L.NUMEROS = H.NUMEROS WHERE H.DATES BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE);",
            "SELECT SUM(MONTANTENTREE) AS CI FROM C008 WHERE DATEENTREE BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE);",
            "SELECT SUM(MONTANT) AS CO FROM C002 WHERE DATEC BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE);"))
        $env:ISC_USER = 'SYSDBA'
        $env:ISC_PASSWORD = 'masterkey'
        # sortie dans un fichier, comme l'agent : isql ecrit "Rolling back work." sur stderr, et avec 2>&1
        # PowerShell 5.1 en fait une erreur qui arrete le test (ErrorActionPreference Stop)
        $isqlRes = Join-Path $dataDir 'totals.out'
        $isqlErr = Join-Path $dataDir 'totals.err'
        Start-Process -FilePath $detected.isql -ArgumentList @('-q', '-i', "`"$sqlFile`"", '-o', "`"$isqlRes`"", "`"$db`"") `
            -NoNewWindow -Wait -RedirectStandardError $isqlErr | Out-Null
        $isqlOut = @(Get-Content $isqlRes, $isqlErr -ErrorAction SilentlyContinue)
        $gc = @{}
        foreach ($l in $isqlOut) { if ([string]$l -match '^(N|Q|T|CI|CO)\s+(\S+)') { $gc[$Matches[1]] = $Matches[2] } }
        if ($gc.Count -eq 5) {
            $sales = Get-Content (Join-Path $dataDir 'outbox\data-sales.json') -Raw -Encoding UTF8 | ConvertFrom-Json
            $n = 0
            [decimal]$q = 0
            [decimal]$t = 0
            foreach ($grp in $sales.groups.PSObject.Properties) {
                foreach ($r in @($grp.Value)) {
                    foreach ($line in @($r.lines)) {
                        $n++
                        if ($line.qty) { $q += [decimal]::Parse($line.qty, [Globalization.CultureInfo]::InvariantCulture) }
                        if ($line.total) { $t += [decimal]::Parse($line.total, [Globalization.CultureInfo]::InvariantCulture) }
                    }
                }
            }
            $num = { param($v) if ($v -eq '<null>') { [decimal]0 } else { [decimal]::Parse($v, [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture) } }
            Assert ($n -eq [int]$gc.N) "lignes de vente : agent $n, Gescomm $($gc.N)"
            Assert ([math]::Abs($q - (& $num $gc.Q)) -lt 0.01) "quantite vendue : agent $q, Gescomm $($gc.Q)"
            Assert ([math]::Abs($t - (& $num $gc.T)) -lt 0.01) "montant : agent $t, Gescomm $($gc.T)"
            Write-Host "        30 jours : $n lignes de vente, quantite $q, montant $t = Gescomm"
            foreach ($c in @(@{ name = 'cash_in'; col = 'CI' }, @{ name = 'cash_out'; col = 'CO' })) {
                $cash = Get-Content (Join-Path $dataDir "outbox\data-$($c.name).json") -Raw -Encoding UTF8 | ConvertFrom-Json
                [decimal]$sum = 0
                foreach ($grp in $cash.groups.PSObject.Properties) {
                    foreach ($r in @($grp.Value)) { if ($r.amount) { $sum += [decimal]::Parse($r.amount, [Globalization.CultureInfo]::InvariantCulture) } }
                }
                Assert ([math]::Abs($sum - (& $num $gc[$c.col])) -lt 0.01) "$($c.name) : agent $sum, Gescomm $($gc[$c.col])"
                Write-Host "        30 jours : $($c.name) $sum = Gescomm"
            }
        } else {
            Write-Host "        (totaux Gescomm non lus avec SYSDBA / masterkey : comparaison sautee) $($isqlOut -join ' ')" -ForegroundColor Yellow
        }
        # les memes donnees relues donnent les memes empreintes (rien n'a change entre-temps dans les bons d'il y a 3 jours et plus)
        $first = Get-Content (Join-Path $dataDir 'outbox\data-customers.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        $null = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $AgentDir 'agent.ps1') -ConfigPath $cfgFile -TestData -Days 30
        $again = Get-Content (Join-Path $dataDir 'outbox\data-customers.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        Assert (($first.digests | ConvertTo-Json -Compress) -eq ($again.digests | ConvertTo-Json -Compress)) 'les empreintes des clients changent d''une lecture a l''autre'
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
