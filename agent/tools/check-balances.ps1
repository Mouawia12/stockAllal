<#
  Verification avant le solde des clients et des fournisseurs (docs/roadmap.md, phase 3) : comment Gescomm compte
  ce qu'un client doit. GC002 garde DU, VERSER et RESTE (lus par sa procedure CREDIT_UNCLIENT), ETATVERSCLISIMP
  donne l'etat de tous les clients et ETAT_CLI "l'Etat d'un Client" (chaque bon, chaque versement, le solde apres).
  On compare, client par client, ces soldes a chaque calcul possible depuis les bons (GC010), les versements (GC62)
  et le solde initial : quel calcul redonne Gescomm, et ce que veut dire un versement "Autre" (en general negatif).
  Pareil pour les fournisseurs (GC004, GC007, GC60, ETAT_FOU).

  Premier passage (05/11/2026) : DU, VERSER, RESTE et SOLDEINIT sont a zero pour tous les clients et fournisseurs ;
  Gescomm calcule donc le solde a l'ecran. La reference est son Etat d'un Client (ETAT_CLI, ETAT_FOU) : le solde
  apres sa derniere operation, et de combien chaque sorte d'operation fait bouger le solde.
  InterBase 7.5 n'a pas ABS (c'est une UDF) : les ecarts se comparent avec BETWEEN.

  LECTURE SEULE : des SELECT et des procedures de consultation de Gescomm, dans une transaction READ ONLY :
  InterBase y refuse toute ecriture (une procedure qui voudrait ecrire echoue, le rapport le dit).
  Chaque table est lue une fois ; quelques secondes en tout.
  Le resultat contient des donnees de la branche (codes, noms, montants) : il est ecrit sur le Bureau,
  jamais dans le depot (public).

  A lancer sur le PC du Gescomm (pas besoin d'etre administrateur ; Gescomm peut rester ouvert) :
    powershell -ExecutionPolicy Bypass -File agent\tools\check-balances.ps1
    powershell -ExecutionPolicy Bypass -File agent\tools\check-balances.ps1 -Client C001,C017 -Database 'localhost:E:\Gescomm\LABASEN.IB'
#>
param(
    [string]$Database = 'localhost:D:\Gescomm\LABASEN.IB',
    [string]$Isql = 'C:\Program Files (x86)\Borland\InterBase\bin\isql.exe',
    [string]$User = 'SYSDBA',
    [string]$Password = 'masterkey',
    [string[]]$Client = @(),
    [string]$OutFile = (Join-Path ([Environment]::GetFolderPath('Desktop')) ('gescomm-balances-' + (Get-Date -Format 'yyyyMMdd-HHmm') + '.txt'))
)

$ErrorActionPreference = 'Stop'
$Ansi = [Text.Encoding]::GetEncoding(1252)
$Utf8NoBom = New-Object Text.UTF8Encoding($false)
$Inv = [Globalization.CultureInfo]::InvariantCulture
$ReadOnlyTx = 'SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;'
$Tmp = Join-Path $env:TEMP ('gescomm-balances-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $Tmp | Out-Null
$env:ISC_USER = $User
$env:ISC_PASSWORD = $Password
$Report = New-Object System.Collections.Generic.List[string]
$script:Step = 0

if (-not (Test-Path $Isql)) { throw "isql introuvable : $Isql (parametre -Isql)" }

# ---------- isql (comme agent\tools\check-documents.ps1) ----------

# Un script isql, la transaction en lecture seule d'abord. Rend la sortie et les erreurs sans s'arreter.
function Invoke-Isql([string[]]$Sql, [switch]$List) {
    $script:Step++
    $in = Join-Path $Tmp "$($script:Step).sql"
    $out = Join-Path $Tmp "$($script:Step).out"
    $err = Join-Path $Tmp "$($script:Step).err"
    if ($List) { $head = @($ReadOnlyTx, 'SET LIST ON;') } else { $head = @($ReadOnlyTx, 'SET ECHO ON;') }
    [IO.File]::WriteAllLines($in, [string[]]($head + $Sql), $Ansi)
    Start-Process -FilePath $Isql -ArgumentList @('-q', '-i', "`"$in`"", '-o', "`"$out`"", "`"$Database`"") `
        -NoNewWindow -Wait -RedirectStandardError $err | Out-Null
    $lines = @()
    if (Test-Path $out) { $lines = @([IO.File]::ReadAllLines($out, $Ansi)) }
    $errors = @(Get-Content $err -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and $_.Trim() -ne 'Rolling back work.' })
    [pscustomobject]@{ Lines = $lines; Errors = $errors }
}

# Sortie "SET LIST ON" : nom du champ sur 32 colonnes puis la valeur ; le 1er nom ouvre un enregistrement.
function ConvertFrom-IsqlList([string[]]$Lines, [string[]]$Names) {
    $cur = $null
    foreach ($line in $Lines) {
        if ($line.Length -gt 32) { $label = $line.Substring(0, 32).TrimEnd() } else { $label = $line.TrimEnd() }
        if ($Names -notcontains $label) { continue }
        if ($line.Length -gt 32) { $value = $line.Substring(32).TrimEnd() } else { $value = '' }
        if ($value -eq '<null>') { $value = $null }
        if ($label -eq $Names[0]) {
            if ($cur) { [pscustomobject]$cur }
            $cur = [ordered]@{}
        }
        if ($cur) { $cur[$label] = $value }
    }
    if ($cur) { [pscustomobject]$cur }
}

function Get-Rows([string]$Sql, [string[]]$Names) {
    $r = Invoke-Isql @($Sql) -List
    foreach ($e in $r.Errors) { $Report.Add('!! ' + $Sql + ' -> ' + $e) }
    @(ConvertFrom-IsqlList $r.Lines $Names)
}

function To-Num($v) { if ($null -eq $v -or $v -eq '') { return 0.0 }; return [double]::Parse($v, $Inv) }
function Fmt([double]$n) { return $n.ToString('0.00', $Inv) }
function Sql-Text([string]$s) { return "'" + $s.Replace("'", "''") + "'" }
function Same([double]$a, [double]$b) { return [Math]::Abs($a - $b) -lt 0.005 }

function Add-Title([string]$Title, [string]$Why) {
    Write-Host "  $Title"
    $Report.Add('')
    $Report.Add('=' * 100)
    $Report.Add($Title)
    if ($Why) { $Report.Add($Why) }
    $Report.Add('=' * 100)
}

function Add-Section([string]$Title, [string]$Why, [string[]]$Sql) {
    Add-Title $Title $Why
    $r = Invoke-Isql $Sql
    $blank = $false
    foreach ($l in $r.Lines) {
        $t = $l.TrimEnd()
        if ($t -eq '' -and $blank) { continue }
        $blank = ($t -eq '')
        $Report.Add($t)
    }
    foreach ($e in $r.Errors) { $Report.Add('!! ' + $e) }
}

Write-Host "Gescomm : verification des soldes en lecture seule ($Database)"
$Report.Add('GescomStock - verification des soldes clients et fournisseurs de Gescomm (agent\tools\check-balances.ps1), lecture seule')
$Report.Add('Date : ' + (Get-Date -Format 'yyyy-MM-dd HH:mm') + '   PC : ' + $env:COMPUTERNAME + '   Base : ' + $Database)
$Report.Add('Contient des donnees de la branche : a envoyer seulement au developpeur, jamais a publier.')

# ---------- 1. ce que Gescomm garde ----------

Add-Section '1. Les colonnes de solde de GC002 et GC004' 'DU, VERSER, RESTE, SOLDEINIT : remplies ? leurs totaux ; RESTE = DU - VERSER ?' @(
    'SELECT COUNT(*) CLIENTS, SUM(SOLDEINIT) SOLDEINIT, SUM(DU) DU, SUM(VERSER) VERSER, SUM(RESTE) RESTE, SUM(CASE WHEN DU IS NULL THEN 1 ELSE 0 END) DU_VIDE FROM GC002;',
    'SELECT COUNT(*) RESTE_EGAL_DU_MOINS_VERSER FROM GC002 WHERE (COALESCE(RESTE, 0) - (COALESCE(DU, 0) - COALESCE(VERSER, 0))) BETWEEN -0.005 AND 0.005;',
    'SELECT COUNT(*) FOURNISSEURS, SUM(SOLDEINIT) SOLDEINIT, SUM(DU) DU, SUM(VERSER) VERSER, SUM(RESTE) RESTE FROM GC004;',
    'SELECT COUNT(*) RESTE_EGAL_DU_MOINS_VERSER FROM GC004 WHERE (COALESCE(RESTE, 0) - (COALESCE(DU, 0) - COALESCE(VERSER, 0))) BETWEEN -0.005 AND 0.005;',
    'SELECT COMBINER, COUNT(*) CLIENTS FROM GC002 GROUP BY COMBINER;'
)

Add-Section '2. Les versements par type et par signe' 'TYPEVER (Espece, Autre...) et TYPEBON : combien, quel total, positifs ou negatifs ; le lien a un bon (NUMEROBON).' @(
    'SELECT TYPEVER, TYPEBON, COUNT(*) N, SUM(MONTANT) MONTANT, SUM(CASE WHEN MONTANT < 0 THEN 1 ELSE 0 END) NEGATIFS, SUM(CASE WHEN NUMEROBON IS NOT NULL AND NUMEROBON <> 0 THEN 1 ELSE 0 END) AVEC_BON FROM GC62 GROUP BY TYPEVER, TYPEBON;',
    'SELECT TYPEVER, TYPEBON, COUNT(*) N, SUM(MONTANT) MONTANT, SUM(CASE WHEN MONTANT < 0 THEN 1 ELSE 0 END) NEGATIFS FROM GC60 GROUP BY TYPEVER, TYPEBON;',
    'SELECT VERSBLOQUE, ETATCHEQUE, COUNT(*) N, SUM(MONTANT) MONTANT FROM GC62 GROUP BY VERSBLOQUE, ETATCHEQUE;'
)

Add-Section '3. Des versements "Autre"' 'Leur observation, leur bon et le solde ecrit avec eux (SOLDE) : ce que Gescomm y range.' @(
    "SELECT DATEVER, NUMEROTATION, CODEFOU, MONTANT, SOLDE, TYPEBON, NUMEROBON, AGENT, OBSERVATION FROM GC62 WHERE TYPEVER = 'Autre' ORDER BY MONTANT;",
    "SELECT DATEVER, NUMEROTATION, CODEFOU, MONTANT, SOLDE, TYPEBON, NUMEROBON, AGENT, OBSERVATION FROM GC60 WHERE TYPEVER = 'Autre';"
)

# ---------- 4. client par client : quel calcul redonne le solde de Gescomm ----------

# $who : code -> solde initial, total des bons et versements par type. $refs : nom -> (code -> solde de Gescomm).
# Chaque calcul : solde initial (ou pas) + bons - versements, un type de versement ignore ou compte dans l'autre sens.
function Test-Formulas([string]$Title, $who, $types, $refs) {
    $candidates = [ordered]@{}
    foreach ($init in @($true, $false)) {
        $base = 'bons - versements'
        if ($init) { $base = 'initial + ' + $base }
        $candidates[$base] = @{ init = $init; skip = ''; flip = '' }
        foreach ($t in $types) {
            $candidates[$base + ' (sans "' + $t + '")'] = @{ init = $init; skip = $t; flip = '' }
            $candidates[$base + ' ("' + $t + '" ajoute)'] = @{ init = $init; skip = ''; flip = $t }
        }
    }
    Add-Title $Title ('Codes : ' + $who.Count + '. Types de versement : ' + ($types -join ', ') + '. Pour chaque calcul, le nombre de codes ou il redonne chaque solde de Gescomm.')
    $calc = @{}
    foreach ($name in $candidates.Keys) {
        $c = $candidates[$name]
        $calc[$name] = @{}
        foreach ($code in $who.Keys) {
            $w = $who[$code]
            $v = $w.bons
            if ($c.init) { $v += $w.init }
            foreach ($t in $w.paid.Keys) {
                if ($t -eq $c.skip) { continue }
                if ($t -eq $c.flip) { $v += $w.paid[$t] } else { $v -= $w.paid[$t] }
            }
            $calc[$name][$code] = $v
        }
    }
    foreach ($ref in $refs.Keys) {
        $Report.Add('')
        $Report.Add('Solde de Gescomm : ' + $ref + ' (' + $refs[$ref].Count + ' codes)')
        foreach ($name in $candidates.Keys) {
            $ok = 0
            foreach ($code in $refs[$ref].Keys) { if ($calc[$name].ContainsKey($code) -and (Same $calc[$name][$code] $refs[$ref][$code])) { $ok++ } }
            $Report.Add(('  {0,-60} {1}' -f $name, $ok))
        }
    }
    # les premiers codes ou aucun calcul ne redonne le premier solde de reference
    $first = @($refs.Keys)[0]
    $odd = New-Object System.Collections.Generic.List[string]
    if ($first) {
        foreach ($code in $refs[$first].Keys) {
            $hit = $false
            foreach ($name in $candidates.Keys) { if ($calc[$name].ContainsKey($code) -and (Same $calc[$name][$code] $refs[$first][$code])) { $hit = $true; break } }
            if (-not $hit -and $who.ContainsKey($code) -and $odd.Count -lt 12) {
                $w = $who[$code]
                $paid = ($w.paid.Keys | ForEach-Object { '{0}={1}' -f $_, (Fmt $w.paid[$_]) }) -join ' '
                $odd.Add(('  {0} : initial {1}, bons {2} ({3}), versements {4} | {5} {6}' -f $code, (Fmt $w.init), (Fmt $w.bons), $w.n, $paid, $first, (Fmt $refs[$first][$code])))
            }
        }
    }
    if ($odd.Count) { $Report.Add(''); $Report.Add('Codes sans calcul juste (' + $first + ') :'); foreach ($o in $odd) { $Report.Add($o) } }
    return $calc
}

function Read-Partners([string]$Table, [string]$Key, [string]$Docs, [string]$DocKey, [string]$Payments) {
    $who = @{}
    foreach ($p in (Get-Rows "SELECT $Key, SOLDEINIT, DU, VERSER, RESTE FROM $Table;" @($Key, 'SOLDEINIT', 'DU', 'VERSER', 'RESTE'))) {
        $who[$p.$Key] = @{ init = (To-Num $p.SOLDEINIT); du = $p.DU; verser = $p.VERSER; reste = $p.RESTE; bons = 0.0; n = 0; paid = @{} }
    }
    foreach ($s in (Get-Rows "SELECT $DocKey, COUNT(*) N, SUM(MONTANT) M FROM $Docs GROUP BY $DocKey;" @($DocKey, 'N', 'M'))) {
        if ($s.$DocKey -and $who.ContainsKey($s.$DocKey)) { $who[$s.$DocKey].bons = To-Num $s.M; $who[$s.$DocKey].n = [int](To-Num $s.N) }
    }
    $types = New-Object System.Collections.Generic.List[string]
    foreach ($v in (Get-Rows "SELECT CODEFOU, TYPEVER, COUNT(*) N, SUM(MONTANT) M FROM $Payments GROUP BY CODEFOU, TYPEVER;" @('CODEFOU', 'TYPEVER', 'N', 'M'))) {
        $t = $v.TYPEVER
        if ($null -eq $t) { $t = '(vide)' }
        if (-not $types.Contains($t)) { $types.Add($t) }
        if ($v.CODEFOU -and $who.ContainsKey($v.CODEFOU)) { $who[$v.CODEFOU].paid[$t] = To-Num $v.M }
    }
    return @{ who = $who; types = @($types) }
}

# L'Etat d'un Client de Gescomm pour des codes choisis, en un seul isql : un repere (le code) avant chaque appel.
# Rend le solde apres la derniere operation de chaque code, et pour chaque sorte d'operation (OPERATION, TYPEBON11,
# TYPEVER11) combien de fois le solde a bouge du montant (TOTAL1) en plus, en moins, pas du tout, ou d'autre chose.
function Read-Statements([string]$Proc, [string[]]$Codes) {
    $sql = New-Object System.Collections.Generic.List[string]
    foreach ($c in $Codes) {
        $lit = Sql-Text $c
        $sql.Add("SELECT $lit AS REPERE FROM RDB`$DATABASE;")
        $sql.Add("SELECT OPERATION, TYPEBON11, TYPEVER11, TOTAL1, LESOLDE1 FROM $Proc($lit);")
    }
    $r = Invoke-Isql ([string[]]$sql) -List
    foreach ($e in @($r.Errors | Select-Object -First 5)) { $Report.Add('!! ' + $Proc + ' -> ' + $e) }
    $rows = New-Object System.Collections.Generic.List[object]
    $code = $null
    $cur = $null
    foreach ($line in $r.Lines) {
        if ($line.Length -gt 32) { $label = $line.Substring(0, 32).TrimEnd(); $value = $line.Substring(32).TrimEnd() } else { $label = $line.TrimEnd(); $value = '' }
        if ($value -eq '<null>') { $value = $null }
        if ($label -eq 'REPERE') { $code = $value; $cur = $null; continue }
        if ($label -eq 'OPERATION') { $cur = @{ code = $code; OPERATION = $value }; $rows.Add($cur); continue }
        if ($cur -and @('TYPEBON11', 'TYPEVER11', 'TOTAL1', 'LESOLDE1') -contains $label) { $cur[$label] = $value }
    }
    $final = @{}
    $moves = [ordered]@{}
    $prevCode = $null
    $prev = 0.0
    foreach ($row in $rows) {
        if ($row.code -ne $prevCode) { $prevCode = $row.code; $prev = 0.0 }
        $total = To-Num $row['TOTAL1']
        $solde = To-Num $row['LESOLDE1']
        $delta = $solde - $prev
        $kind = 'autre'
        if (Same $delta $total) { $kind = 'plus' } elseif (Same $delta (-$total)) { $kind = 'moins' } elseif (Same $delta 0) { $kind = 'rien' }
        $key = ('{0} / {1} / {2}' -f $row['OPERATION'], $row['TYPEBON11'], $row['TYPEVER11'])
        if (-not $moves.Contains($key)) { $moves[$key] = @{ plus = 0; moins = 0; rien = 0; autre = 0; n = 0 } }
        $moves[$key][$kind]++
        $moves[$key].n++
        $prev = $solde
        if ($row.code) { $final[$row.code] = $solde }
    }
    return @{ final = $final; moves = $moves; rows = $rows.Count }
}

function Add-Moves([string]$Proc, $St) {
    $Report.Add('')
    $Report.Add($Proc + ' : ' + $St.final.Count + ' codes, ' + $St.rows + ' operations. Le solde bouge de : +montant / -montant / rien / autre')
    foreach ($k in $St.moves.Keys) {
        $m = $St.moves[$k]
        $Report.Add(('  {0,-45} {1,6} operations : {2,6} / {3,6} / {4,6} / {5,6}' -f $k, $m.n, $m.plus, $m.moins, $m.rien, $m.autre))
    }
}

# des codes avec de l'activite : ceux demandes, ceux qui ont des versements "Autre" (les plus negatifs d'abord), puis les plus actifs
function Pick-Codes($who, [int]$Max) {
    $list = New-Object System.Collections.Generic.List[string]
    foreach ($c in $Client) { if ($c -and $who.ContainsKey($c) -and -not $list.Contains($c)) { $list.Add($c) } }
    foreach ($e in @($who.GetEnumerator() | Where-Object { $_.Value.paid.ContainsKey('Autre') } | Sort-Object { $_.Value.paid['Autre'] })) {
        if ($list.Count -ge [int]($Max * 2 / 3)) { break }
        if (-not $list.Contains($e.Key)) { $list.Add($e.Key) }
    }
    foreach ($e in @($who.GetEnumerator() | Where-Object { $_.Value.n -gt 0 -or $_.Value.paid.Count -gt 0 } | Sort-Object { $_.Value.n } -Descending)) {
        if ($list.Count -ge $Max) { break }
        if (-not $list.Contains($e.Key)) { $list.Add($e.Key) }
    }
    return @($list)
}

$cli = Read-Partners 'GC002' 'CODECLI' 'GC010' 'CODEF' 'GC62'
$cliCodes = Pick-Codes $cli.who 120
Add-Title '4. Clients : comment chaque operation fait bouger le solde (Etat d un Client de Gescomm)' ('Codes lus : ' + $cliCodes.Count + '. Pour chaque sorte d operation, le solde apres moins le solde avant, compare a son montant.')
$refs = [ordered]@{}
foreach ($proc in @('ETAT_CLI', 'ETAT_CLI_NOUV')) {
    $st = Read-Statements $proc $cliCodes
    Add-Moves $proc $st
    if ($st.final.Count) { $refs[$proc + ' (dernier solde)'] = $st.final }
}
if ($refs.Count) {
    $null = Test-Formulas '5. Clients : initial + bons - versements = dernier solde de l Etat d un Client ?' $cli.who $cli.types $refs
}

$fou = Read-Partners 'GC004' 'CODEFOU' 'GC007' 'CODEF' 'GC60'
$fouCodes = Pick-Codes $fou.who 91
Add-Title '6. Fournisseurs : comment chaque operation fait bouger le solde (ETAT_FOU)' ('Codes lus : ' + $fouCodes.Count + '.')
$st = Read-Statements 'ETAT_FOU' $fouCodes
Add-Moves 'ETAT_FOU' $st
if ($st.final.Count) {
    $null = Test-Formulas '6b. Fournisseurs : initial + achats - versements = dernier solde de ETAT_FOU ?' $fou.who $fou.types ([ordered]@{ 'ETAT_FOU (dernier solde)' = $st.final })
}

# ---------- 7. l'Etat d'un Client de Gescomm, ligne par ligne ----------

# les clients demandes, celui qui a le plus de versements "Autre", et deux clients avec 5 a 40 operations
$picked = New-Object System.Collections.Generic.List[string]
foreach ($c in $Client) { if ($c -and -not $picked.Contains($c)) { $picked.Add($c) } }
$autre = @($cli.who.GetEnumerator() | Where-Object { $_.Value.paid.ContainsKey('Autre') } | Sort-Object { $_.Value.paid['Autre'] } | Select-Object -First 1)
foreach ($a in $autre) { if (-not $picked.Contains($a.Key)) { $picked.Add($a.Key) } }
foreach ($m in @($cli.who.GetEnumerator() | Where-Object { $_.Value.n -ge 5 -and $_.Value.n -le 40 } | Sort-Object { $_.Value.n } -Descending | Select-Object -First 2)) {
    if (-not $picked.Contains($m.Key)) { $picked.Add($m.Key) }
}
foreach ($c in $picked) {
    $lit = Sql-Text $c
    Add-Section ('7. Client ' + $c) 'ETAT_CLI = l Etat d un Client de Gescomm (solde apres chaque operation), puis ses versements et ses bons tels quels.' @(
        "SELECT CODECLI, MONCLIENT, SOLDEINIT, DU, VERSER, RESTE, COMBINER, CODEFOU FROM GC002 WHERE CODECLI = $lit;",
        "EXECUTE PROCEDURE CREDIT_UNCLIENT $lit;",
        "SELECT SOLDEINITIAL, SOLDEACTUEL, SOLDEFOURNISSEUR, DERNIERVERSEMENT, DATEDERNIERVERSEMENT, MONTANTGLOBALVENTES FROM ETATDUCLIENT($lit);",
        "SELECT * FROM ETAT_CLI($lit);",
        "SELECT DATEVER, NUMEROTATION, HEURE, TYPEVER, MONTANT, SOLDE, TYPEBON, NUMEROBON, OBSERVATION FROM GC62 WHERE CODEFOU = $lit ORDER BY DATEVER, HEURE;",
        "SELECT NUMEROS, DATES, HEURE, TYPEBON, MONTANT, NBJOURCREDIT FROM GC010 WHERE CODEF = $lit ORDER BY DATES, HEURE;"
    )
}

# un fournisseur avec un versement "Autre", sinon celui qui a le plus d'achats
$f = @($fou.who.GetEnumerator() | Where-Object { $_.Value.paid.ContainsKey('Autre') } | Select-Object -First 1)
if (-not $f.Count) { $f = @($fou.who.GetEnumerator() | Sort-Object { $_.Value.n } -Descending | Select-Object -First 1) }
foreach ($x in $f) {
    $lit = Sql-Text $x.Key
    Add-Section ('8. Fournisseur ' + $x.Key) 'ETAT_FOU = l Etat d un Fournisseur, puis ses versements tels quels.' @(
        "SELECT CODEFOU, MONFOURNISSEUR, SOLDEINIT, DU, VERSER, RESTE FROM GC004 WHERE CODEFOU = $lit;",
        "SELECT * FROM ETAT_FOU($lit);",
        "SELECT DATEVER, NUMEROTATION, TYPEVER, MONTANT, SOLDE, TYPEBON, NUMEROBON, OBSERVATION FROM GC60 WHERE CODEFOU = $lit ORDER BY DATEVER, HEURE;"
    )
}

$Report.Add('')
$Report.Add('Fin.')
[IO.File]::WriteAllLines($OutFile, [string[]]$Report, $Utf8NoBom)
Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
Write-Host ''
Write-Host "Termine. Envoyez ce fichier : $OutFile"
