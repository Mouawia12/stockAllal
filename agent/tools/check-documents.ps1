<#
  Verification avant l'agent 1.0.7 (docs/roadmap.md, section 4) : comment cette base Gescomm range les ventes,
  les achats, les retours et les commandes, et si "stock initial + achats - ventes" redonne le stock de Gescomm.
  Les reponses decident comment l'agent 1.0.7 lira les documents et comment le site comptera les ventes.

  LECTURE SEULE : des SELECT et deux procedures de calcul de Gescomm (MONTANT_PROD = la "Fiche de Stock",
  ETATQTESTOCK = son controle du stock), dans une transaction READ ONLY : InterBase y refuse toute ecriture.
  Aucune requete ne parcourt une table plus d'une fois par question ; quelques secondes en tout.
  Le resultat contient des donnees de la branche (montants, codes des clients) : il est ecrit sur le Bureau,
  jamais dans le depot (public).

  A lancer sur le PC du Gescomm (pas besoin d'etre administrateur ; Gescomm peut rester ouvert) :
    powershell -ExecutionPolicy Bypass -File agent\tools\check-documents.ps1
    powershell -ExecutionPolicy Bypass -File agent\tools\check-documents.ps1 -Code 820Z,612Z -Database 'localhost:E:\Gescomm\LABASEN.IB'
#>
param(
    [string]$Database = 'localhost:D:\Gescomm\LABASEN.IB',
    [string]$Isql = 'C:\Program Files (x86)\Borland\InterBase\bin\isql.exe',
    [string]$User = 'SYSDBA',
    [string]$Password = 'masterkey',
    [string[]]$Code = @('820Z'),
    [string]$OutFile = (Join-Path ([Environment]::GetFolderPath('Desktop')) ('gescomm-check-' + (Get-Date -Format 'yyyyMMdd-HHmm') + '.txt'))
)

$ErrorActionPreference = 'Stop'
$Ansi = [Text.Encoding]::GetEncoding(1252)
$Utf8NoBom = New-Object Text.UTF8Encoding($false)
$Inv = [Globalization.CultureInfo]::InvariantCulture
$ReadOnlyTx = 'SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;'
$Tmp = Join-Path $env:TEMP ('gescomm-check-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $Tmp | Out-Null
$env:ISC_USER = $User
$env:ISC_PASSWORD = $Password
$Report = New-Object System.Collections.Generic.List[string]
$script:Step = 0

if (-not (Test-Path $Isql)) { throw "isql introuvable : $Isql (parametre -Isql)" }

# ---------- isql ----------

# Un script isql, la transaction en lecture seule d'abord. Rend la sortie et les erreurs sans s'arreter :
# une requete refusee (colonne absente dans une autre version de Gescomm...) n'empeche pas les suivantes.
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

# Des lignes pour les calculs de ce script ; en cas d'erreur, elle va dans le rapport et la liste est vide.
function Get-Rows([string]$Sql, [string[]]$Names) {
    $r = Invoke-Isql @($Sql) -List
    foreach ($e in $r.Errors) { $Report.Add('!! ' + $Sql + ' -> ' + $e) }
    @(ConvertFrom-IsqlList $r.Lines $Names)
}

function To-Num($v) { if ($null -eq $v -or $v -eq '') { return 0.0 }; return [double]::Parse($v, $Inv) }
function Fmt([double]$n) { return $n.ToString('0.00', $Inv) }
function Sql-Text([string]$s) { return "'" + $s.Replace("'", "''") + "'" }

# ---------- rapport ----------

function Add-Title([string]$Title, [string]$Why) {
    Write-Host "  $Title"
    $Report.Add('')
    $Report.Add('=' * 100)
    $Report.Add($Title)
    if ($Why) { $Report.Add($Why) }
    $Report.Add('=' * 100)
}

# Une partie du rapport : la question, puis les requetes et ce qu'isql repond, tel quel
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

Write-Host "Gescomm : verification en lecture seule ($Database)"
$Report.Add('GescomStock - verification des documents de Gescomm (agent\tools\check-documents.ps1), lecture seule')
$Report.Add('Date : ' + (Get-Date -Format 'yyyy-MM-dd HH:mm') + '   PC : ' + $env:COMPUTERNAME + '   Base : ' + $Database)
$Report.Add('Contient des donnees de la branche : a envoyer seulement au developpeur, jamais a publier.')

# ---------- 1. taille et periode ----------

Add-Section '1. Taille et periode de chaque table' 'Combien de lignes, de quand a quand : la taille de la premiere synchronisation.' @(
    "SELECT 'GC010 bons de vente' T, COUNT(*) N, MIN(DATES) DU, MAX(DATES) AU FROM GC010;",
    "SELECT 'GC011 lignes de vente' T, COUNT(*) N, MIN(DATES) DU, MAX(DATES) AU FROM GC011;",
    "SELECT 'GC007 bons d achat' T, COUNT(*) N, MIN(DATEBON) DU, MAX(DATEBON) AU FROM GC007;",
    "SELECT 'GC008 lignes d achat' T, COUNT(*) N, MIN(DATEBON) DU, MAX(DATEBON) AU FROM GC008;",
    "SELECT 'GC161 commandes' T, COUNT(*) N, MIN(DATES) DU, MAX(DATES) AU FROM GC161;",
    "SELECT 'GC162 lignes de commande' T, COUNT(*) N, MIN(DATEBON) DU, MAX(DATEBON) AU FROM GC162;",
    "SELECT 'GC62 versements clients' T, COUNT(*) N, MIN(DATEVER) DU, MAX(DATEVER) AU FROM GC62;",
    "SELECT 'GC60 versements fournisseurs' T, COUNT(*) N, MIN(DATEVER) DU, MAX(DATEVER) AU FROM GC60;",
    "SELECT 'GCC011 ventes comptoir' T, COUNT(*) N, MIN(DATEVENTE) DU, MAX(DATEVENTE) AU FROM GCC011;",
    "SELECT 'CT003 transferts' T, COUNT(*) N FROM CT003;",
    "SELECT 'CT005 receptions de transfert' T, COUNT(*) N FROM CT005;"
)

# ---------- 2. types de bons ----------

Add-Section '2. Valeurs de TYPEBON, GENRE et des reglements' 'Les types de bons (vente, retour...) et leurs montants : quel type compte comme une vente.' @(
    'SELECT TYPEBON, COUNT(*) BONS, SUM(MONTANT) MONTANT, MIN(MONTANT) MINI, MAX(MONTANT) MAXI FROM GC010 GROUP BY TYPEBON;',
    'SELECT TYPEBON, COUNT(*) LIGNES, SUM(QTE) QTE, MIN(QTE) MINI, MAX(QTE) MAXI, SUM(TOTAL) TOTAL FROM GC011 GROUP BY TYPEBON;',
    'SELECT TYPEBON, GENRE, COUNT(*) BONS, SUM(MONTANT) MONTANT, MIN(MONTANT) MINI, MAX(MONTANT) MAXI FROM GC007 GROUP BY TYPEBON, GENRE;',
    'SELECT TYPEBON, GENRE, COUNT(*) LIGNES, SUM(QTE) QTE, MIN(QTE) MINI, MAX(QTE) MAXI, SUM(TOTAL) TOTAL FROM GC008 GROUP BY TYPEBON, GENRE;',
    'SELECT REGLEMENT, REGLER, CREDITREGLER, COUNT(*) BONS, SUM(MONTANT) MONTANT FROM GC010 GROUP BY REGLEMENT, REGLER, CREDITREGLER;',
    'SELECT BONBLOQUE, COUNT(*) BONS FROM GC010 GROUP BY BONBLOQUE;',
    'SELECT COMMANDE, COMBINER, COUNT(*) BONS FROM GC010 GROUP BY COMMANDE, COMBINER;',
    'SELECT COUNT(*) BONS_FACTURES FROM GC010 WHERE NUMEROFACT IS NOT NULL AND NUMEROFACT <> 0;',
    'SELECT TYPEVER, COUNT(*) VERSEMENTS, SUM(MONTANT) MONTANT, MIN(MONTANT) MINI FROM GC62 GROUP BY TYPEVER;',
    'SELECT TYPEVER, COUNT(*) VERSEMENTS, SUM(MONTANT) MONTANT, MIN(MONTANT) MINI FROM GC60 GROUP BY TYPEVER;'
)

# ---------- 3. signe des quantites ----------

Add-Section '3. Signe des quantites selon le type du bon' 'Un retour : un bon de type a part, ou une quantite negative dans un bon de vente ?' @(
    'SELECT h.TYPEBON, COUNT(*) LIGNES_NEG, SUM(l.QTE) QTE, SUM(l.TOTAL) TOTAL FROM GC011 l JOIN GC010 h ON h.NUMEROS = l.NUMEROS WHERE l.QTE < 0 GROUP BY h.TYPEBON;',
    'SELECT h.TYPEBON, COUNT(*) LIGNES_POS, SUM(l.QTE) QTE, SUM(l.TOTAL) TOTAL FROM GC011 l JOIN GC010 h ON h.NUMEROS = l.NUMEROS WHERE l.QTE >= 0 GROUP BY h.TYPEBON;',
    'SELECT COUNT(*) LIGNE_ET_BON_DIFFERENTS FROM GC011 l JOIN GC010 h ON h.NUMEROS = l.NUMEROS WHERE l.TYPEBON <> h.TYPEBON;',
    'SELECT COUNT(*) LIGNES_SANS_BON FROM GC011 l WHERE NOT EXISTS (SELECT 1 FROM GC010 h WHERE h.NUMEROS = l.NUMEROS);',
    'SELECT COUNT(*) BONS_SANS_LIGNE FROM GC010 h WHERE NOT EXISTS (SELECT 1 FROM GC011 l WHERE l.NUMEROS = h.NUMEROS);',
    'SELECT h.TYPEBON, COUNT(*) LIGNES_NEG, SUM(l.QTE) QTE FROM GC008 l JOIN GC007 h ON h.NUMEROE = l.NUMEROE AND h.CODEF = l.CODEF WHERE l.QTE < 0 GROUP BY h.TYPEBON;',
    'SELECT COUNT(*) LIGNES_ACHAT_SANS_BON FROM GC008 l WHERE NOT EXISTS (SELECT 1 FROM GC007 h WHERE h.NUMEROE = l.NUMEROE AND h.CODEF = l.CODEF);',
    'SELECT COUNT(*) LIGNES_VENTE_ARTICLE_SUPPRIME FROM GC011 l WHERE NOT EXISTS (SELECT 1 FROM GC003 a WHERE a.CODEA = l.CODEA);'
)

Add-Section '4. Les bons a montant negatif' 'Les bons "Vente" a montant negatif vus dans la liste des bons : leur type et leurs lignes.' @(
    'SELECT h.NUMEROS, h.DATES, h.TYPEBON, h.MONTANT, h.MONTANTREMISE, h.CODEF, h.REGLEMENT, h.NUMEROFACT FROM GC010 h WHERE h.MONTANT < 0 ORDER BY h.DATES;',
    'SELECT l.NUMEROS, l.CODEA, l.TYPEBON, l.QTE, l.PU, l.TOTAL, l.PRIXACHAT FROM GC011 l WHERE l.NUMEROS IN (SELECT h.NUMEROS FROM GC010 h WHERE h.MONTANT < 0) ORDER BY l.NUMEROS;',
    'SELECT h.NUMEROE, h.CODEF, h.DATEBON, h.TYPEBON, h.GENRE, h.MONTANT FROM GC007 h WHERE h.MONTANT < 0 ORDER BY h.DATEBON;'
)

# ---------- 5. montant d'un bon et ses lignes ----------

Add-Title '5. Montant du bon et somme de ses lignes' 'Quel champ des lignes redonne MONTANT (remise par ligne, remise sur le bon) : pour les totaux de ventes.'
$heads = @(Get-Rows 'SELECT NUMEROS, TYPEBON, MONTANT, MONTANTREMISE, REMISEPARMONTANT, TOTALSANSREMISE FROM GC010;' @('NUMEROS', 'TYPEBON', 'MONTANT', 'MONTANTREMISE', 'REMISEPARMONTANT', 'TOTALSANSREMISE'))
$sums = @{}
foreach ($s in (Get-Rows 'SELECT NUMEROS, COUNT(*) N, SUM(TOTAL) T, SUM(TOTALLIGNEREMISE) TR, SUM(TOTALSANSREMISE) TS FROM GC011 GROUP BY NUMEROS;' @('NUMEROS', 'N', 'T', 'TR', 'TS'))) { $sums[$s.NUMEROS] = $s }
$fits = [ordered]@{ 'SUM(TOTAL)' = 0; 'SUM(TOTALLIGNEREMISE)' = 0; 'SUM(TOTALSANSREMISE)' = 0; 'SUM(TOTAL) - REMISEPARMONTANT' = 0; 'aucun' = 0 }
$odd = New-Object System.Collections.Generic.List[string]
foreach ($h in $heads) {
    $s = $sums[$h.NUMEROS]
    if (-not $s) { continue }
    $m = To-Num $h.MONTANT
    $hit = $false
    foreach ($pair in @(@('SUM(TOTAL)', (To-Num $s.T)), @('SUM(TOTALLIGNEREMISE)', (To-Num $s.TR)), @('SUM(TOTALSANSREMISE)', (To-Num $s.TS)),
                        @('SUM(TOTAL) - REMISEPARMONTANT', ((To-Num $s.T) - (To-Num $h.REMISEPARMONTANT))))) {
        if ([Math]::Abs($m - $pair[1]) -lt 0.005) { $fits[$pair[0]]++; $hit = $true }
    }
    if (-not $hit) {
        $fits['aucun']++
        if ($odd.Count -lt 12) {
            $odd.Add(('  bon {0} {1} : MONTANT {2}, remise {3}, remise/montant {4}, sans remise {5} | lignes {6} : TOTAL {7}, TOTALLIGNEREMISE {8}, TOTALSANSREMISE {9}' -f `
                $h.NUMEROS, $h.TYPEBON, $h.MONTANT, $h.MONTANTREMISE, $h.REMISEPARMONTANT, $h.TOTALSANSREMISE, $s.N, $s.T, $s.TR, $s.TS))
        }
    }
}
$Report.Add('Bons compares : ' + $heads.Count + '. Un bon peut correspondre a plusieurs calculs.')
foreach ($k in $fits.Keys) { $Report.Add(('  MONTANT = {0} : {1} bons' -f $k, $fits[$k])) }
if ($odd.Count) { $Report.Add('Exemples sans correspondance :'); foreach ($o in $odd) { $Report.Add($o) } }

# ---------- 6. commandes ----------

Add-Section '6. GC161 / GC162 : les commandes' 'Commandes aux fournisseurs ou des clients ? Elles ne touchent pas le stock (leurs triggers ne changent pas GC003).' @(
    'SELECT COUNT(*) CHEZ_FOURNISSEURS FROM GC161 c WHERE EXISTS (SELECT 1 FROM GC004 f WHERE f.CODEFOU = c.CODEF);',
    'SELECT COUNT(*) CHEZ_CLIENTS FROM GC161 c WHERE EXISTS (SELECT 1 FROM GC002 k WHERE k.CODECLI = c.CODEF);',
    'SELECT c.NUMEROS, c.DATES, c.CODEF, c.MONTANT, c.BONBLOQUE, (SELECT COUNT(*) FROM GC162 l WHERE l.NUMEROS = c.NUMEROS) LIGNES FROM GC161 c ORDER BY c.DATES;'
)

# ---------- 7. le stock : notre calcul et celui de Gescomm ----------

Add-Title '7. Stock : stock initial + achats - ventes = stock de Gescomm ?' 'Par article. Si un type de bon doit compter dans l autre sens (un retour), le changer doit faire monter le nombre d articles justes.'
$arts = @(Get-Rows 'SELECT CODEA, STKNFACTINIT, STOCKREEL FROM GC003;' @('CODEA', 'STKNFACTINIT', 'STOCKREEL'))
$sales = @(Get-Rows 'SELECT CODEA, TYPEBON, COUNT(*) N, SUM(QTE) Q FROM GC011 GROUP BY CODEA, TYPEBON;' @('CODEA', 'TYPEBON', 'N', 'Q'))
$buys = @(Get-Rows 'SELECT CODEA, TYPEBON, COUNT(*) N, SUM(QTE) Q FROM GC008 GROUP BY CODEA, TYPEBON;' @('CODEA', 'TYPEBON', 'N', 'Q'))
$move = @{}
$lineCount = @{}
function Add-Move($row, [string]$kind, [int]$sign) {
    $t = $row.TYPEBON
    if ($null -eq $t) { $t = '(vide)' }
    $key = $kind + ':' + $t
    if (-not $move.ContainsKey($row.CODEA)) { $move[$row.CODEA] = @{} }
    $move[$row.CODEA][$key] = $sign * (To-Num $row.Q)
    $lineCount[$row.CODEA] = [int]$lineCount[$row.CODEA] + [int](To-Num $row.N)
}
foreach ($r in $sales) { Add-Move $r 'vente' -1 }
foreach ($r in $buys) { Add-Move $r 'achat' 1 }
$keys = @($move.Values | ForEach-Object { $_.Keys } | Sort-Object -Unique)

# combien d'articles retombent juste si les lignes du type $flip comptent dans l'autre sens ('' = tel quel)
function Count-Exact([string]$flip) {
    $ok = 0
    foreach ($a in $arts) {
        $calc = To-Num $a.STKNFACTINIT
        $m = $move[$a.CODEA]
        if ($m) { foreach ($k in $m.Keys) { if ($k -eq $flip) { $calc -= $m[$k] } else { $calc += $m[$k] } } }
        if ([Math]::Abs($calc - (To-Num $a.STOCKREEL)) -lt 0.005) { $ok++ }
    }
    return $ok
}
$Report.Add('Articles : ' + $arts.Count + '. Types de lignes : ' + ($keys -join ', '))
$Report.Add('  tel quel (achats +, ventes -) : ' + (Count-Exact '') + ' articles justes')
foreach ($k in $keys) { $Report.Add(('  si "{0}" compte dans l autre sens : {1} articles justes' -f $k, (Count-Exact $k))) }
$wrong = New-Object System.Collections.Generic.List[string]
foreach ($a in $arts) {
    $calc = To-Num $a.STKNFACTINIT
    $m = $move[$a.CODEA]
    $detail = ''
    if ($m) { foreach ($k in $m.Keys) { $calc += $m[$k]; $detail += (' {0}={1}' -f $k, (Fmt $m[$k])) } }
    if ([Math]::Abs($calc - (To-Num $a.STOCKREEL)) -ge 0.005 -and $wrong.Count -lt 15) {
        $wrong.Add(('  {0} : initial {1}{2} => {3}, Gescomm {4}' -f $a.CODEA, $a.STKNFACTINIT, $detail, (Fmt $calc), $a.STOCKREEL))
        if ($wrong.Count -le 4) { $Code += $a.CODEA }
    }
}
if ($wrong.Count) { $Report.Add('Premiers articles differents (tel quel) :'); foreach ($w in $wrong) { $Report.Add($w) } }

Add-Title '8. Le controle du stock de Gescomm (procedure ETATQTESTOCK)' 'Son propre calcul : stock theorique, et ecart avec le stock de l ordinateur (QTEMICRO).'
$names = @('CODEA1', 'STINITI', 'NBVENTE', 'NBVENTECOMPTOIRE', 'NBVENTERETOUR', 'NBACHAT', 'NBACHATRETOUR', 'NBTRANSFERT', 'NBRECEP', 'STOCKTHERIQUE', 'QTEMICRO', 'DIFFERENCE')
$etat = @(Get-Rows ('SELECT ' + ($names -join ', ') + ' FROM ETATQTESTOCK;') $names)
if ($etat.Count) {
    $diff = @($etat | Where-Object { [Math]::Abs((To-Num $_.DIFFERENCE)) -ge 0.005 })
    $Report.Add('Lignes : ' + $etat.Count + '. Avec un ecart : ' + $diff.Count)
    $sum = @{}
    $cols = $names[1..($names.Count - 1)]
    foreach ($n in $cols) { $sum[$n] = 0.0 }
    foreach ($e in $etat) { foreach ($n in $cols) { $sum[$n] += (To-Num $e.$n) } }
    $Report.Add('Totaux : ' + (($cols | ForEach-Object { '{0}={1}' -f $_, (Fmt $sum[$_]) }) -join ' '))
    # notre calcul tel quel contre son stock theorique
    $same = 0
    $byCode = @{}
    foreach ($a in $arts) {
        $calc = To-Num $a.STKNFACTINIT
        $m = $move[$a.CODEA]
        if ($m) { foreach ($k in $m.Keys) { $calc += $m[$k] } }
        $byCode[$a.CODEA] = $calc
    }
    foreach ($e in $etat) { if ($byCode.ContainsKey($e.CODEA1) -and [Math]::Abs($byCode[$e.CODEA1] - (To-Num $e.STOCKTHERIQUE)) -lt 0.005) { $same++ } }
    $Report.Add('Notre calcul (tel quel) = son stock theorique pour ' + $same + ' articles.')
    foreach ($e in ($diff | Select-Object -First 10)) {
        $Report.Add('  ' + (($names | ForEach-Object { '{0}={1}' -f $_, $e.$_ }) -join ' '))
    }
}

# ---------- 9. quelques articles en detail ----------

# les articles demandes, ceux qui ont le plus de lignes de vente, et les premiers qui ne retombent pas juste
$top = @($lineCount.GetEnumerator() | Sort-Object Value -Descending | Select-Object -First 5 | ForEach-Object { $_.Key })
$detailCodes = @($Code + $top | Where-Object { $_ } | Select-Object -Unique)
foreach ($c in $detailCodes) {
    $lit = Sql-Text $c
    Add-Section ('9. Article ' + $c) 'La "Fiche de Stock" de Gescomm (MONTANT_PROD) a cote de nos sommes par type de bon.' @(
        "SELECT CODEA, STKNFACTINIT, STOCKREEL, STOCKALERT, STOCKMAX, CODEFAM, UNITMESURE FROM GC003 WHERE CODEA = $lit;",
        "EXECUTE PROCEDURE MONTANT_PROD $lit;",
        "SELECT TYPEBON, COUNT(*) LIGNES, SUM(QTE) QTE, SUM(TOTAL) TOTAL, MIN(DATES) DU, MAX(DATES) AU FROM GC011 WHERE CODEA = $lit GROUP BY TYPEBON;",
        "SELECT TYPEBON, GENRE, COUNT(*) LIGNES, SUM(QTE) QTE, SUM(TOTAL) TOTAL FROM GC008 WHERE CODEA = $lit GROUP BY TYPEBON, GENRE;",
        "SELECT COUNT(*) LIGNES_COMMANDE, SUM(QTE) QTE FROM GC162 WHERE CODEA = $lit;"
    )
}

$Report.Add('')
$Report.Add('Fin.')
[IO.File]::WriteAllLines($OutFile, [string[]]$Report, $Utf8NoBom)
Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
Write-Host ''
Write-Host "Termine. Envoyez ce fichier : $OutFile"
