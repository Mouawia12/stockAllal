<#
  Documente la structure de la base Gescomm (InterBase 7.5) dans docs/gescomm/schema.md et docs/gescomm/schema.json :
  tables, vues, colonnes et types, cles, index, triggers (et les tables qu'ils touchent), procedures et leurs
  parametres, generateurs, nombre de lignes, et les titres des colonnes dans les ecrans de Gescomm (Grid*.txt, Consul*).

  LECTURE SEULE : uniquement des SELECT sur les tables systeme RDB$ et des COUNT(*), dans une transaction READ ONLY.
  Aucune donnee d'article ou de client n'est extraite. Le code source des triggers et procedures (code du fournisseur
  de Gescomm) n'est pas extrait non plus : le depot est public.

  A lancer sur un PC qui a Gescomm et InterBase (pas besoin d'etre administrateur) :
    powershell -ExecutionPolicy Bypass -File agent\tools\dump-schema.ps1
    powershell -ExecutionPolicy Bypass -File agent\tools\dump-schema.ps1 -Database 'localhost:E:\Gescomm\LABASEN.IB' -GescommDir 'E:\Gescomm'
#>
param(
    [string]$Database = 'localhost:D:\Gescomm\LABASEN.IB',
    [string]$Isql = 'C:\Program Files (x86)\Borland\InterBase\bin\isql.exe',
    [string]$User = 'SYSDBA',
    [string]$Password = 'masterkey',
    [string]$GescommDir = 'D:\Gescomm',
    [string]$OutDir = (Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'docs\gescomm')
)

$ErrorActionPreference = 'Stop'
$Ansi = [Text.Encoding]::GetEncoding(1252)
$Utf8NoBom = New-Object Text.UTF8Encoding($false)
$ReadOnlyTx = 'SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;'
$Tmp = Join-Path $env:TEMP ('gescomm-schema-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $Tmp, $OutDir | Out-Null
$env:ISC_USER = $User
$env:ISC_PASSWORD = $Password
$NotSystem = '(RDB$SYSTEM_FLAG = 0 OR RDB$SYSTEM_FLAG IS NULL)'

# ---------- isql ----------

function Invoke-Isql([string[]]$Sql, [string]$Name) {
    $in = Join-Path $Tmp "$Name.sql"
    $out = Join-Path $Tmp "$Name.out"
    $err = Join-Path $Tmp "$Name.err"
    [IO.File]::WriteAllLines($in, [string[]](@($ReadOnlyTx, 'SET LIST ON;') + $Sql), $Ansi)
    Start-Process -FilePath $Isql -ArgumentList @('-q', '-i', "`"$in`"", '-o', "`"$out`"", "`"$Database`"") `
        -NoNewWindow -Wait -RedirectStandardError $err | Out-Null
    $errLines = @(Get-Content $err -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and $_.Trim() -ne 'Rolling back work.' })
    if ($errLines.Count) { throw ("isql ($Name) : " + ($errLines -join ' | ')) }
    [IO.File]::ReadAllLines($out, $Ansi)
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

function Get-Rows([string]$Name, [string]$Sql, [string[]]$Names) {
    Write-Host "  $Name"
    @(ConvertFrom-IsqlList (Invoke-Isql @($Sql) $Name) $Names)
}

function To-Int($v) { if ($null -eq $v -or $v -eq '') { return $null }; return [int]$v }

# Type SQL lisible, comme dans isql "SHOW TABLE"
function Format-Type($c) {
    $t = To-Int $c.FTYPE
    $scale = To-Int $c.FSCALE
    $sub = To-Int $c.SUBTYPE
    $prec = To-Int $c.FPREC
    $len = To-Int $c.CLEN
    if ($null -eq $len) { $len = To-Int $c.FLEN }
    if ($scale -lt 0 -and @(7, 8, 16, 27) -contains $t) {
        $defaults = @{ 7 = 4; 8 = 9; 16 = 18; 27 = 15 }
        if ($prec -gt 0) { $p = $prec } else { $p = $defaults[$t] }
        if ($sub -eq 2) { $kind = 'DECIMAL' } else { $kind = 'NUMERIC' }
        return '{0}({1},{2})' -f $kind, $p, (-$scale)
    }
    switch ($t) {
        7 { return 'SMALLINT' }
        8 { return 'INTEGER' }
        9 { return 'QUAD' }
        10 { return 'FLOAT' }
        11 { return 'D_FLOAT' }
        12 { return 'DATE' }
        13 { return 'TIME' }
        14 { return "CHAR($len)" }
        16 { return 'BIGINT' }
        27 { return 'DOUBLE PRECISION' }
        35 { return 'TIMESTAMP' }
        37 { return "VARCHAR($len)" }
        40 { return "CSTRING($len)" }
        261 { if ($sub -eq 1) { return 'BLOB TEXT' } else { return "BLOB SUB_TYPE $sub" } }
        default { return "type $t" }
    }
}

# ---------- ecrans de Gescomm : titres des colonnes ----------

# Valeur d'une propriete dans un fichier Delphi binaire (TPF0) : 6 = chaine courte, 12 = longue, 18 = UTF-16, 20 = UTF-8
function Read-DfmString([byte[]]$Bytes, [int]$Pos) {
    $t = $Bytes[$Pos]
    $Pos++
    if ($t -eq 6) { return $Ansi.GetString($Bytes, $Pos + 1, $Bytes[$Pos]) }
    if ($t -eq 12) { return $Ansi.GetString($Bytes, $Pos + 4, [BitConverter]::ToInt32($Bytes, $Pos)) }
    if ($t -eq 18) { return [Text.Encoding]::Unicode.GetString($Bytes, $Pos + 4, 2 * [BitConverter]::ToInt32($Bytes, $Pos)) }
    if ($t -eq 20) { return [Text.Encoding]::UTF8.GetString($Bytes, $Pos + 4, [BitConverter]::ToInt32($Bytes, $Pos)) }
    return $null
}

function Read-Screen([IO.FileInfo]$File) {
    $bytes = [IO.File]::ReadAllBytes($File.FullName)
    $text = $Ansi.GetString($bytes)   # 1 octet = 1 caractere : les positions du texte sont celles des octets
    $cols = New-Object System.Collections.Generic.List[object]
    if ($text.StartsWith('TPF0')) {
        # grille Delphi (GridArt.txt, GridClient.txt) : FieldName puis Title.Caption pour chaque colonne
        $cur = $null
        foreach ($m in [regex]::Matches($text, 'FieldName|Title\.Caption')) {
            $value = Read-DfmString $bytes ($m.Index + $m.Length)
            if ($m.Value -eq 'FieldName') { $cur = [ordered]@{ field = $value; caption = $null }; $cols.Add($cur) }
            elseif ($cur -and -not $cur.caption) { $cur.caption = $value }
        }
    } else {
        # ecran de consultation (Consul*) : "rang~ CHAMP~ Titre~ largeur" par ligne
        foreach ($line in ($text -split "`r?`n")) {
            $parts = $line -split '~'
            if ($parts.Count -ge 3 -and $parts[1].Trim()) { $cols.Add([ordered]@{ field = $parts[1].Trim(); caption = $parts[2].Trim() }) }
        }
    }
    [ordered]@{ file = $File.Name; columns = @($cols | ForEach-Object { [pscustomobject]$_ }) }
}

# ---------- extraction ----------

Write-Host "Base : $Database"
$dialectLine = (Invoke-Isql @('SHOW SQL DIALECT;') 'dialect') | Where-Object { $_ -match 'dialect' } | Select-Object -First 1
$db = Get-Rows 'database' 'SELECT RDB$CHARACTER_SET_NAME AS CS FROM RDB$DATABASE;' @('CS')

$relations = Get-Rows 'relations' ("SELECT RDB`$RELATION_NAME AS REL, RDB`$VIEW_BLR AS VBLR FROM RDB`$RELATIONS WHERE $NotSystem ORDER BY 1;") @('REL', 'VBLR')
$views = @($relations | Where-Object { $_.VBLR } | ForEach-Object { $_.REL })
$tables = @($relations | Where-Object { -not $_.VBLR } | ForEach-Object { $_.REL })

$columns = Get-Rows 'columns' @"
SELECT rf.RDB`$RELATION_NAME AS TBL, rf.RDB`$FIELD_NAME AS COL, rf.RDB`$FIELD_POSITION AS POS, rf.RDB`$FIELD_SOURCE AS DOM,
 rf.RDB`$NULL_FLAG AS NN1, f.RDB`$NULL_FLAG AS NN2, f.RDB`$FIELD_TYPE AS FTYPE, f.RDB`$FIELD_SUB_TYPE AS SUBTYPE,
 f.RDB`$FIELD_LENGTH AS FLEN, f.RDB`$FIELD_SCALE AS FSCALE, f.RDB`$FIELD_PRECISION AS FPREC, f.RDB`$CHARACTER_LENGTH AS CLEN
FROM RDB`$RELATION_FIELDS rf
JOIN RDB`$FIELDS f ON f.RDB`$FIELD_NAME = rf.RDB`$FIELD_SOURCE
JOIN RDB`$RELATIONS r ON r.RDB`$RELATION_NAME = rf.RDB`$RELATION_NAME
WHERE (r.RDB`$SYSTEM_FLAG = 0 OR r.RDB`$SYSTEM_FLAG IS NULL)
ORDER BY 1, 3;
"@ @('TBL', 'COL', 'POS', 'DOM', 'NN1', 'NN2', 'FTYPE', 'SUBTYPE', 'FLEN', 'FSCALE', 'FPREC', 'CLEN')

$computed = Get-Rows 'computed' @"
SELECT rf.RDB`$RELATION_NAME AS TBL, rf.RDB`$FIELD_NAME AS COL
FROM RDB`$RELATION_FIELDS rf JOIN RDB`$FIELDS f ON f.RDB`$FIELD_NAME = rf.RDB`$FIELD_SOURCE
WHERE f.RDB`$COMPUTED_BLR IS NOT NULL;
"@ @('TBL', 'COL')
$computedSet = @{}
foreach ($c in $computed) { $computedSet["$($c.TBL).$($c.COL)"] = $true }

$constraints = Get-Rows 'constraints' @"
SELECT rc.RDB`$RELATION_NAME AS TBL, rc.RDB`$CONSTRAINT_NAME AS CNAME, rc.RDB`$CONSTRAINT_TYPE AS CTYPE,
 s.RDB`$FIELD_NAME AS COL, s.RDB`$FIELD_POSITION AS POS
FROM RDB`$RELATION_CONSTRAINTS rc JOIN RDB`$INDEX_SEGMENTS s ON s.RDB`$INDEX_NAME = rc.RDB`$INDEX_NAME
WHERE rc.RDB`$CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')
ORDER BY 1, 2, 5;
"@ @('TBL', 'CNAME', 'CTYPE', 'COL', 'POS')

$fkTargets = Get-Rows 'foreign-keys' @"
SELECT rf.RDB`$CONSTRAINT_NAME AS CNAME, uq.RDB`$RELATION_NAME AS REFTBL
FROM RDB`$REF_CONSTRAINTS rf JOIN RDB`$RELATION_CONSTRAINTS uq ON uq.RDB`$CONSTRAINT_NAME = rf.RDB`$CONST_NAME_UQ;
"@ @('CNAME', 'REFTBL')

$indexes = Get-Rows 'indexes' @"
SELECT i.RDB`$RELATION_NAME AS TBL, i.RDB`$INDEX_NAME AS IDX, i.RDB`$UNIQUE_FLAG AS UNIQ, i.RDB`$INDEX_TYPE AS DESCN,
 i.RDB`$INDEX_INACTIVE AS INACT, s.RDB`$FIELD_NAME AS COL, s.RDB`$FIELD_POSITION AS POS
FROM RDB`$INDICES i JOIN RDB`$INDEX_SEGMENTS s ON s.RDB`$INDEX_NAME = i.RDB`$INDEX_NAME
WHERE (i.RDB`$SYSTEM_FLAG = 0 OR i.RDB`$SYSTEM_FLAG IS NULL)
ORDER BY 1, 2, 7;
"@ @('TBL', 'IDX', 'UNIQ', 'DESCN', 'INACT', 'COL', 'POS')

# (INACTIVE est un mot reserve d'InterBase : alias INACT)
$triggers = Get-Rows 'triggers' ("SELECT RDB`$TRIGGER_NAME AS TRG, RDB`$RELATION_NAME AS TBL, RDB`$TRIGGER_TYPE AS TTYPE, RDB`$TRIGGER_SEQUENCE AS SEQ, RDB`$TRIGGER_INACTIVE AS INACT FROM RDB`$TRIGGERS WHERE $NotSystem ORDER BY 2, 3, 4, 1;") @('TRG', 'TBL', 'TTYPE', 'SEQ', 'INACT')

# tables et colonnes lues ou ecrites par chaque trigger (RDB$DEPENDENCIES ne distingue pas lecture et ecriture)
$trgDeps = Get-Rows 'trigger-deps' 'SELECT RDB$DEPENDENT_NAME AS TRG, RDB$DEPENDED_ON_NAME AS OBJ, RDB$FIELD_NAME AS COL, RDB$DEPENDED_ON_TYPE AS OTYPE FROM RDB$DEPENDENCIES WHERE RDB$DEPENDENT_TYPE = 2 ORDER BY 1, 2, 3;' @('TRG', 'OBJ', 'COL', 'OTYPE')

$procParams = Get-Rows 'procedures' @"
SELECT p.RDB`$PROCEDURE_NAME AS PRC, pp.RDB`$PARAMETER_NAME AS PARAM, pp.RDB`$PARAMETER_TYPE AS PTYPE, pp.RDB`$PARAMETER_NUMBER AS PNUM,
 f.RDB`$FIELD_TYPE AS FTYPE, f.RDB`$FIELD_SUB_TYPE AS SUBTYPE, f.RDB`$FIELD_LENGTH AS FLEN, f.RDB`$FIELD_SCALE AS FSCALE,
 f.RDB`$FIELD_PRECISION AS FPREC, f.RDB`$CHARACTER_LENGTH AS CLEN
FROM RDB`$PROCEDURES p
LEFT JOIN RDB`$PROCEDURE_PARAMETERS pp ON pp.RDB`$PROCEDURE_NAME = p.RDB`$PROCEDURE_NAME
LEFT JOIN RDB`$FIELDS f ON f.RDB`$FIELD_NAME = pp.RDB`$FIELD_SOURCE
ORDER BY 1, 3, 4;
"@ @('PRC', 'PARAM', 'PTYPE', 'PNUM', 'FTYPE', 'SUBTYPE', 'FLEN', 'FSCALE', 'FPREC', 'CLEN')

$procDeps = Get-Rows 'procedure-deps' 'SELECT DISTINCT RDB$DEPENDENT_NAME AS PRC, RDB$DEPENDED_ON_NAME AS OBJ FROM RDB$DEPENDENCIES WHERE RDB$DEPENDENT_TYPE = 5 AND RDB$DEPENDED_ON_TYPE IN (0, 1) ORDER BY 1, 2;' @('PRC', 'OBJ')

$generators = Get-Rows 'generators' ("SELECT RDB`$GENERATOR_NAME AS GEN FROM RDB`$GENERATORS WHERE $NotSystem ORDER BY 1;") @('GEN')

Write-Host '  row counts'
$countSql = @($tables | ForEach-Object { 'SELECT ''{0}'' AS TBL, COUNT(*) AS N FROM "{0}";' -f $_ })
$counts = @{}
foreach ($r in @(ConvertFrom-IsqlList (Invoke-Isql $countSql 'counts') @('TBL', 'N'))) { $counts[$r.TBL] = [int]$r.N }

Write-Host '  screens'
$screens = @(Get-ChildItem $GescommDir -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like 'Grid*.txt' -or $_.Name -like 'Consul*' } | Sort-Object Name | ForEach-Object { Read-Screen $_ })

# ---------- assemblage ----------

$colsByTable = $columns | Group-Object TBL -AsHashTable -AsString
$consByTable = $constraints | Group-Object TBL -AsHashTable -AsString
$idxByTable = $indexes | Group-Object TBL -AsHashTable -AsString
$trgByTable = $triggers | Group-Object TBL -AsHashTable -AsString
$depsByTrg = $trgDeps | Group-Object TRG -AsHashTable -AsString
$fkTarget = @{}
foreach ($f in $fkTargets) { $fkTarget[$f.CNAME] = $f.REFTBL }
$events = @{ 1 = 'BEFORE INSERT'; 2 = 'AFTER INSERT'; 3 = 'BEFORE UPDATE'; 4 = 'AFTER UPDATE'; 5 = 'BEFORE DELETE'; 6 = 'AFTER DELETE' }

# Ecran -> table : la table qui contient le plus de champs de l'ecran (au moins 3), "CHAMP1" compte comme "CHAMP"
foreach ($s in $screens) {
    $fields = @($s.columns | ForEach-Object { $_.field.ToUpper() })
    $best = $null; $bestScore = 2
    foreach ($t in $tables) {
        $names = @($colsByTable[$t] | ForEach-Object { $_.COL })
        $score = @($fields | Where-Object { $names -contains $_ -or $names -contains ($_ -replace '1$', '') }).Count
        if ($score -gt $bestScore) { $best = $t; $bestScore = $score }
    }
    $s['table'] = $best
}

function Get-Caption([string]$Table, [string]$Column) {
    foreach ($s in $screens) {
        if ($s.table -ne $Table) { continue }
        foreach ($c in $s.columns) {
            if ($c.field.ToUpper() -eq $Column -or ($c.field.ToUpper() -replace '1$', '') -eq $Column) {
                if ($c.caption) { return '{0} ({1})' -f $c.caption, $s.file }
            }
        }
    }
    return $null
}

$model = [ordered]@{
    generated_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    generator    = 'agent/tools/dump-schema.ps1'
    database     = $Database
    host         = $env:COMPUTERNAME
    engine       = 'Borland InterBase 7.5'
    dialect      = ($dialectLine -replace '.*database SQL dialect is:\s*', '').Trim()
    charset      = $(if ($db[0].CS) { $db[0].CS } else { 'NONE (bytes are Windows-1252)' })
    counts       = [ordered]@{
        tables = $tables.Count; views = $views.Count; columns = $columns.Count; triggers = $triggers.Count
        procedures = @($procParams | Select-Object -ExpandProperty PRC -Unique).Count; generators = $generators.Count
        indexes = @($indexes | Select-Object -ExpandProperty IDX -Unique).Count
        foreign_keys = @($constraints | Where-Object { $_.CTYPE -eq 'FOREIGN KEY' } | Select-Object -ExpandProperty CNAME -Unique).Count
    }
    tables       = @()
    procedures   = @()
    generators   = @($generators | ForEach-Object { $_.GEN })
    screens      = $screens
}

foreach ($t in @($tables + $views)) {
    $cons = @($consByTable[$t])
    $entry = [ordered]@{
        name     = $t
        kind     = $(if ($views -contains $t) { 'view' } else { 'table' })
        rows     = $counts[$t]
        columns  = @($colsByTable[$t] | ForEach-Object {
            [ordered]@{
                name     = $_.COL
                position = (To-Int $_.POS)
                type     = (Format-Type $_)
                not_null = ($_.NN1 -eq '1' -or $_.NN2 -eq '1')
                computed = [bool]$computedSet["$t.$($_.COL)"]
                domain   = $(if ($_.DOM -notmatch '^RDB\$') { $_.DOM } else { $null })
                caption  = (Get-Caption $t $_.COL)
            }
        })
        primary_key = @($cons | Where-Object { $_.CTYPE -eq 'PRIMARY KEY' } | ForEach-Object { $_.COL })
        unique      = @($cons | Where-Object { $_.CTYPE -eq 'UNIQUE' } | Group-Object CNAME | ForEach-Object { , @($_.Group | ForEach-Object { $_.COL }) })
        foreign_keys = @($cons | Where-Object { $_.CTYPE -eq 'FOREIGN KEY' } | Group-Object CNAME | ForEach-Object {
            [ordered]@{ name = $_.Name; columns = @($_.Group | ForEach-Object { $_.COL }); references = $fkTarget[$_.Name] } })
        indexes     = @($idxByTable[$t] | Group-Object IDX | ForEach-Object {
            $first = $_.Group[0]
            [ordered]@{ name = $_.Name; columns = @($_.Group | ForEach-Object { $_.COL }); unique = ($first.UNIQ -eq '1'); descending = ($first.DESCN -eq '1'); active = ($first.INACT -ne '1') } })
        triggers    = @($trgByTable[$t] | Where-Object { $_ } | ForEach-Object {
            $deps = @($depsByTrg[$_.TRG] | Where-Object { $_ -and ($_.OTYPE -eq '0' -or $_.OTYPE -eq '1') })
            [ordered]@{
                name     = $_.TRG
                event    = $(if ($events[(To-Int $_.TTYPE)]) { $events[(To-Int $_.TTYPE)] } else { 'type ' + $_.TTYPE })
                sequence = (To-Int $_.SEQ)
                active   = ($_.INACT -ne '1')
                touches  = @($deps | Group-Object OBJ | ForEach-Object {
                    [ordered]@{ table = $_.Name; columns = @($_.Group | Where-Object { $_.COL } | ForEach-Object { $_.COL } | Sort-Object -Unique) } })
            }
        })
    }
    $model.tables += [pscustomobject]$entry
}

$depsByProc = $procDeps | Group-Object PRC -AsHashTable -AsString
$model.procedures = @($procParams | Group-Object PRC | ForEach-Object {
    $params = @($_.Group | Where-Object { $_.PARAM })
    [ordered]@{
        name    = $_.Name
        inputs  = @($params | Where-Object { $_.PTYPE -eq '0' } | ForEach-Object { '{0} {1}' -f $_.PARAM, (Format-Type $_) })
        outputs = @($params | Where-Object { $_.PTYPE -eq '1' } | ForEach-Object { '{0} {1}' -f $_.PARAM, (Format-Type $_) })
        tables  = @($depsByProc[$_.Name] | Where-Object { $_ } | ForEach-Object { $_.OBJ })
    }
})

# ---------- schema.json ----------

# compact : l'indentation de PowerShell 5.1 triple la taille du fichier ; schema.md est la version a lire
[IO.File]::WriteAllText((Join-Path $OutDir 'schema.json'), (ConvertTo-Json $model -Depth 8 -Compress), $Utf8NoBom)

# ---------- schema.md ----------

$md = New-Object Text.StringBuilder
function L([string]$Line = '') { [void]$md.AppendLine($Line) }
function Cell($v) { if ($null -eq $v -or "$v" -eq '') { return '' }; return ("$v" -replace '\|', '\|') }

L '# Gescomm database structure'
L ''
L ('> Generated by `{0}` on {1} from `{2}` ({3}). Do not edit by hand: run the script again on a PC that has Gescomm.' -f $model.generator, $model.generated_at, $Database, $env:COMPUTERNAME)
L '> Read-only extraction: system tables and row counts only. No business data, no trigger or procedure source code (vendor code, public repo).'
L '> Same data, machine-readable: `schema.json`. Arabic overview: `README.md`.'
L ''
L '## Summary'
L ''
L '| Item | Value |'
L '|---|---|'
L ('| Engine | {0}, SQL dialect {1} |' -f $model.engine, $model.dialect)
L ('| Character set | {0} |' -f $model.charset)
foreach ($k in $model.counts.Keys) { L ('| {0} | {1} |' -f $k, $model.counts[$k]) }
L ''
L 'No foreign keys are declared: tables are linked by column names only (see "Shared columns"). Column names often do not match'
L 'their meaning: always check the screen caption (last column of each table) before using a field.'
L ''

L '## Shared columns (likely join keys)'
L ''
L 'Columns that appear in 3 tables or more.'
L ''
L '| Column | Tables |'
L '|---|---|'
$shared = $columns | Where-Object { $tables -contains $_.TBL } | Group-Object COL | Where-Object { $_.Count -ge 3 } | Sort-Object Count -Descending
foreach ($g in $shared) {
    $names = @($g.Group | ForEach-Object { $_.TBL } | Sort-Object -Unique)
    L ('| `{0}` | {1} ({2}) |' -f $g.Name, (($names | ForEach-Object { '`' + $_ + '`' }) -join ', '), $names.Count)
}
L ''

L '## Tables'
L ''
L '| Table | Rows | Columns | Primary key | Triggers | Screen |'
L '|---|---:|---:|---|---:|---|'
foreach ($t in $model.tables) {
    $screen = @($screens | Where-Object { $_.table -eq $t.name } | ForEach-Object { $_.file }) -join ', '
    $kind = ''
    if ($t.kind -eq 'view') { $kind = ' (view)' }
    L ('| [`{0}`](#{1}){2} | {3} | {4} | {5} | {6} | {7} |' -f $t.name, $t.name.ToLower(), $kind, $t.rows, $t.columns.Count, ($t.primary_key -join ', '), $t.triggers.Count, $screen)
}
L ''

foreach ($t in $model.tables) {
    L ('### {0}' -f $t.name)
    L ''
    $rows = ''
    if ($null -ne $t.rows) { $rows = ', {0} rows' -f $t.rows }
    L ('{0}{1}. Primary key: {2}.' -f $t.kind, $rows, $(if ($t.primary_key.Count) { '`' + ($t.primary_key -join ', ') + '`' } else { 'none' }))
    L ''
    L '| # | Column | Type | Not null | Screen caption |'
    L '|---:|---|---|:---:|---|'
    foreach ($c in $t.columns) {
        $type = $c.type
        if ($c.computed) { $type += ' (computed)' }
        L ('| {0} | `{1}` | {2} | {3} | {4} |' -f $c.position, $c.name, $type, $(if ($c.not_null) { 'yes' } else { '' }), (Cell $c.caption))
    }
    L ''
    foreach ($u in $t.unique) { L ('- Unique: `{0}`' -f ($u -join ', ')) }
    foreach ($f in $t.foreign_keys) { L ('- Foreign key `{0}`: `{1}` -> `{2}`' -f $f.name, ($f.columns -join ', '), $f.references) }
    foreach ($i in $t.indexes) {
        $flags = @()
        if ($i.unique) { $flags += 'unique' }
        if ($i.descending) { $flags += 'descending' }
        if (-not $i.active) { $flags += 'INACTIVE' }
        $suffix = ''
        if ($flags.Count) { $suffix = ' (' + ($flags -join ', ') + ')' }
        L ('- Index `{0}`: `{1}`{2}' -f $i.name, ($i.columns -join ', '), $suffix)
    }
    foreach ($tr in $t.triggers) {
        $state = ''
        if (-not $tr.active) { $state = ', INACTIVE' }
        $touch = @($tr.touches | Where-Object { $_.table -ne $t.name } | ForEach-Object {
            if ($_.columns.Count) { '`{0}`({1})' -f $_.table, ($_.columns -join ', ') } else { '`' + $_.table + '`' } })
        $touchText = ''
        if ($touch.Count) { $touchText = ' - uses ' + ($touch -join '; ') }
        L ('- Trigger `{0}`: {1}, position {2}{3}{4}' -f $tr.name, $tr.event, $tr.sequence, $state, $touchText)
    }
    L ''
}

L '## Stored procedures'
L ''
L ('{0} procedures. Inputs -> outputs, then the tables each one reads or writes.' -f $model.procedures.Count)
L ''
L '<details><summary>Show the list</summary>'
L ''
foreach ($p in $model.procedures) {
    $uses = ''
    if ($p.tables.Count) { $uses = ' - uses ' + (($p.tables | ForEach-Object { '`' + $_ + '`' }) -join ', ') }
    L ('- `{0}`({1}) -> ({2}){3}' -f $p.name, ($p.inputs -join ', '), ($p.outputs -join ', '), $uses)
}
L ''
L '</details>'
L ''
L '## Generators'
L ''
L (($model.generators | ForEach-Object { '`' + $_ + '`' }) -join ', ')
L ''
L '## Gescomm screens (column captions)'
L ''
L ('Read from the layout files in `{0}`. "Table" = the table that holds most of the screen''s fields (a guess when fields are query aliases).' -f $GescommDir)
L ''
foreach ($s in $screens) {
    L ('### {0}' -f $s.file)
    L ''
    L ('Table: {0}' -f $(if ($s.table) { '`' + $s.table + '`' } else { 'unknown (query aliases)' }))
    L ''
    L '| Field | Caption |'
    L '|---|---|'
    foreach ($c in $s.columns) { L ('| `{0}` | {1} |' -f $c.field, (Cell $c.caption)) }
    L ''
}

[IO.File]::WriteAllText((Join-Path $OutDir 'schema.md'), $md.ToString(), $Utf8NoBom)
Remove-Item $Tmp -Recurse -Force -ErrorAction SilentlyContinue
Write-Host ("OK : {0} tables, {1} vues, {2} colonnes -> {3}" -f $tables.Count, $views.Count, $columns.Count, $OutDir) -ForegroundColor Green
