<#
  GescomAgent - envoie le stock Gescomm (InterBase 7.5) de cette branche vers l'API centrale.
  Lecture seule : chaque script isql commence par SET TRANSACTION READ ONLY.

  Utilisation :
    agent.ps1                                   boucle normale (lancee par le Planificateur de taches)
    agent.ps1 -TestCode 820AC                   lit un code dans la base et affiche le resultat
    agent.ps1 -Once [-DryRun]                   une synchro complete puis sortie (-DryRun : JSON dans outbox\)
    agent.ps1 -SelfTest [-SkipApi]              verifie la base, la table GC003 et l'API
    agent.ps1 -Register -BranchName X -InstallCode Y   enregistre la branche aupres de l'API (utilise par install.ps1)
#>
param(
    [string]$ConfigPath = (Join-Path $env:ProgramData 'GescomAgent\config.json'),
    [string]$TestCode,
    [switch]$Once,
    [switch]$DryRun,
    [switch]$SelfTest,
    [switch]$SkipApi,
    [switch]$Register,
    [string]$BranchName,
    [string]$InstallCode
)

$ErrorActionPreference = 'Stop'
$AgentVersion = '1.0.1'
$Table = 'GC003'
$Fields = @('CODEA', 'DESIGNATIONF2', 'STOCKREEL')
$ReadOnlyTx = 'SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;'
$Ansi = [Text.Encoding]::GetEncoding(1252)
$Utf8NoBom = New-Object Text.UTF8Encoding($false)
$Invariant = [Globalization.CultureInfo]::InvariantCulture

$Dir = Split-Path -Parent $ConfigPath
$LogDir = Join-Path $Dir 'logs'
$TmpDir = Join-Path $Dir 'tmp'
$OutboxDir = Join-Path $Dir 'outbox'
$script:LastError = ''
$script:LastErrorAt = [datetime]::MinValue

# ---------- journal ----------

function Write-Log([string]$Message, [switch]$IsError) {
    if ($IsError) {
        # meme erreur repetee (ex : internet coupe) : une ligne toutes les 10 minutes seulement
        if ($Message -eq $script:LastError -and ((Get-Date) - $script:LastErrorAt).TotalMinutes -lt 10) { return }
        $script:LastError = $Message
        $script:LastErrorAt = Get-Date
        $Message = "ERREUR $Message"
    } else {
        $script:LastError = ''
    }
    $line = '{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $Message
    Write-Host $line
    try { Add-Content -Path (Join-Path $LogDir ('agent-{0:yyyyMMdd}.log' -f (Get-Date))) -Value $line -Encoding UTF8 } catch { }
}

function Remove-OldFiles {
    Get-ChildItem $LogDir -Filter 'agent-*.log' -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-14) } | Remove-Item -ErrorAction SilentlyContinue
    Get-ChildItem $OutboxDir -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-2) } | Remove-Item -ErrorAction SilentlyContinue
    Get-ChildItem $TmpDir -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-1) } | Remove-Item -ErrorAction SilentlyContinue
}

# ---------- configuration ----------

function Read-Config {
    if (-not (Test-Path $ConfigPath)) { throw "config introuvable : $ConfigPath" }
    $raw = Get-Content $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $cfg = [ordered]@{
        api_url           = ''
        token             = ''
        branch_name       = ''
        database          = 'localhost:D:\Gescomm\LABASEN.IB'
        isql              = 'C:\Program Files (x86)\Borland\InterBase\bin\isql.exe'
        db_user           = 'SYSDBA'
        db_password       = 'masterkey'
        poll_seconds      = 10
        full_sync_minutes = 5
    }
    foreach ($p in $raw.PSObject.Properties) { $cfg[$p.Name] = $p.Value }
    return $cfg
}

function Save-Config {
    [IO.File]::WriteAllText($ConfigPath, (ConvertTo-Json $cfg -Depth 3), $Utf8NoBom)
}

# ---------- base InterBase (via isql, lecture seule) ----------

function Invoke-Isql([string[]]$Sql) {
    $id = [guid]::NewGuid().ToString('N')
    $in = Join-Path $TmpDir "$id.sql"
    $out = Join-Path $TmpDir "$id.out"
    $err = Join-Path $TmpDir "$id.err"
    # fichier en Windows-1252 : la base est en charset NONE et certains codes contiennent des caracteres > 127
    [IO.File]::WriteAllLines($in, [string[]](@($ReadOnlyTx, 'SET LIST ON;') + $Sql), $Ansi)
    try {
        $isqlArgs = @('-q', '-i', "`"$in`"", '-o', "`"$out`"", "`"$($cfg.database)`"")
        $p = Start-Process -FilePath $cfg.isql -ArgumentList $isqlArgs -NoNewWindow -PassThru -RedirectStandardError $err
        if (-not $p.WaitForExit(120000)) {
            try { $p.Kill() } catch { }
            throw 'isql : delai depasse (120 s)'
        }
        # "Rolling back work." = fin normale de la transaction par defaut d'isql, pas une erreur
        $errLines = @(Get-Content $err -ErrorAction SilentlyContinue |
            Where-Object { $_.Trim() -and $_.Trim() -ne 'Rolling back work.' })
        if ($errLines.Count) { throw ('isql : ' + ($errLines -join ' | ')) }
        if (-not (Test-Path $out)) { return }
        [IO.File]::ReadAllLines($out, $Ansi)
    } finally {
        Remove-Item $in, $out, $err -ErrorAction SilentlyContinue
    }
}

# Sortie "SET LIST ON" : une ligne par champ, nom sur 32 colonnes puis la valeur ; le 1er nom ouvre un enregistrement.
function ConvertFrom-IsqlList([string[]]$Lines, [string[]]$Names) {
    $cur = $null
    foreach ($line in $Lines) {
        if ($line.Length -gt 32) { $label = $line.Substring(0, 32).TrimEnd() } else { $label = $line.TrimEnd() }
        if ($Names -notcontains $label) { continue }
        if ($line.Length -gt 32) { $value = $line.Substring(32).TrimEnd() } else { $value = '' }
        if ($value -eq '<null>') { $value = $null }
        if ($label -eq $Names[0]) {
            if ($cur) { $cur }
            $cur = [ordered]@{}
        }
        if ($cur) { $cur[$label] = $value }
    }
    if ($cur) { $cur }
}

function ConvertTo-StockItem($Row) {
    $stock = $null
    if ($null -ne $Row['STOCKREEL']) { $stock = [decimal]::Parse($Row['STOCKREEL'], $Invariant) }
    $designation = $Row['DESIGNATIONF2']
    if ($null -ne $designation) { $designation = $designation.Trim() }
    [ordered]@{ code = $Row['CODEA']; designation = $designation; stock = $stock }
}

function Test-Schema {
    $lines = Invoke-Isql @(('SELECT RDB$FIELD_NAME FROM RDB$RELATION_FIELDS WHERE RDB$RELATION_NAME = ''{0}'';' -f $Table))
    $found = @(ConvertFrom-IsqlList $lines @('RDB$FIELD_NAME') | ForEach-Object { $_['RDB$FIELD_NAME'] })
    $missing = @($Fields | Where-Object { $found -notcontains $_ })
    if ($missing.Count) {
        Write-Log ("table {0} : colonnes absentes : {1}" -f $Table, ($missing -join ', ')) -IsError
        return $false
    }
    return $true
}

function Get-AllStock {
    $lines = Invoke-Isql @("SELECT CODEA, DESIGNATIONF2, STOCKREEL FROM $Table;")
    ConvertFrom-IsqlList $lines $Fields | ForEach-Object { ConvertTo-StockItem $_ }
}

# Un code arrive de l'API : on n'accepte qu'un code (1 a 15 caracteres, pas de controle ni de ';'),
# representable en Windows-1252, et on double les apostrophes. Jamais de SQL venant du serveur.
function Test-Code([string]$Code) {
    if ([string]::IsNullOrEmpty($Code) -or $Code.Length -gt 15) { return $false }
    if ($Code -match '[\x00-\x1F\x7F;]') { return $false }
    return ($Ansi.GetString($Ansi.GetBytes($Code)) -eq $Code)
}

function Get-StockByCode([string]$Code) {
    if (-not (Test-Code $Code)) { throw "code refuse : '$Code'" }
    $literal = $Code.Replace("'", "''")
    $lines = Invoke-Isql @("SELECT CODEA, DESIGNATIONF2, STOCKREEL FROM $Table WHERE UPPER(CODEA) = UPPER('$literal');")
    ConvertFrom-IsqlList $lines $Fields | ForEach-Object { ConvertTo-StockItem $_ }
}

function Get-DbFileTime {
    $m = [regex]::Match([string]$cfg.database, '[A-Za-z]:\\.+$')
    if ($m.Success -and (Test-Path -LiteralPath $m.Value)) {
        return (Get-Item -LiteralPath $m.Value).LastWriteTime.ToString('yyyy-MM-ddTHH:mm:sszzz')
    }
    return $null
}

# ---------- API centrale ----------

function Assert-ApiUrl {
    if (-not $cfg.api_url) { throw 'api_url vide dans config.json' }
    $u = [Uri]$cfg.api_url
    if ($u.Scheme -ne 'https' -and @('localhost', '127.0.0.1') -notcontains $u.Host) {
        throw 'api_url doit commencer par https:// (http seulement pour un test local)'
    }
}

function Invoke-Api([string]$Method, [string]$Path, $Body, [switch]$NoAuth) {
    $params = @{
        Method          = $Method
        Uri             = ([string]$cfg.api_url).TrimEnd('/') + $Path
        TimeoutSec      = 60
        UseBasicParsing = $true
        Headers         = @{ 'X-Agent-Version' = $AgentVersion; 'Accept' = 'application/json' }
    }
    if (-not $NoAuth) { $params.Headers['Authorization'] = 'Bearer ' + $cfg.token }
    if ($null -ne $Body) {
        # envoye en octets UTF-8 : PowerShell 5.1 abime les accents si on passe une chaine
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json $Body -Depth 6 -Compress))
    }
    # connexion internet instable dans certaines branches (jusqu'a 1 connexion sur 2 qui echoue) : nouvel essai
    for ($attempt = 1; ; $attempt++) {
        try { return Invoke-RestMethod @params }
        catch {
            if ($attempt -ge 4 -or -not (Test-Retryable $_ $Method)) { throw }
            Start-Sleep -Seconds (2 * $attempt)
        }
    }
}

# Pas de nouvel essai si le serveur a repondu (erreur HTTP = vraie reponse).
# POST : seulement si la requete n'est jamais partie (connexion ou DNS), pour ne jamais l'envoyer deux fois.
function Test-Retryable($ErrorRecord, [string]$Method) {
    $ex = $ErrorRecord.Exception
    if (-not ($ex -is [Net.WebException]) -or $ex.Response) { return $false }
    if ($Method -eq 'GET') { return $true }
    return @([Net.WebExceptionStatus]::ConnectFailure, [Net.WebExceptionStatus]::NameResolutionFailure) -contains $ex.Status
}

function Get-HttpStatus($ErrorRecord) {
    $resp = $ErrorRecord.Exception.Response
    if ($resp -and $resp.StatusCode) { return [int]$resp.StatusCode }
    return 0
}

function Get-Now { (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') }

# ---------- travail ----------

function Send-FullSync {
    $t0 = Get-Date
    $items = @(Get-AllStock)
    $payload = [ordered]@{
        generated_at  = Get-Now
        agent_version = $AgentVersion
        hostname      = $env:COMPUTERNAME
        db_file_mtime = Get-DbFileTime
        items         = $items
    }
    if ($DryRun) {
        $file = Join-Path $OutboxDir ('sync-{0:yyyyMMdd-HHmmss}.json' -f (Get-Date))
        [IO.File]::WriteAllText($file, (ConvertTo-Json $payload -Depth 6), $Utf8NoBom)
        Write-Log ('DRY-RUN : {0} articles ecrits dans {1}' -f $items.Count, $file)
        return
    }
    Invoke-Api 'POST' '/agent/sync' $payload | Out-Null
    Write-Log ('SYNC OK : {0} articles envoyes en {1:N1} s' -f $items.Count, ((Get-Date) - $t0).TotalSeconds)
}

function Invoke-PendingJobs {
    $r = Invoke-Api 'GET' '/agent/pending' $null
    foreach ($job in @($r.jobs)) {
        if ($null -eq $job) { continue }
        $code = [string]$job.code
        $result = [ordered]@{ job_id = $job.id; code = $code; found = $false; items = @(); error = $null; read_at = $null }
        if (-not (Test-Code $code)) {
            $result.error = 'invalid_code'
        } else {
            try {
                $items = @(Get-StockByCode $code)
                $result.items = $items
                $result.found = ($items.Count -gt 0)
            } catch {
                $result.error = 'db_error'
                Write-Log ("demande {0} : {1}" -f $job.id, $_.Exception.Message) -IsError
            }
        }
        $result.read_at = Get-Now
        Invoke-Api 'POST' '/agent/result' $result | Out-Null
        if ($result.error) { $summary = $result.error }
        elseif ($result.found) { $summary = (@($result.items | ForEach-Object { '{0}={1}' -f $_.code, $_.stock }) -join ', ') }
        else { $summary = 'introuvable' }
        Write-Log ("DEMANDE {0} : '{1}' -> {2}" -f $job.id, $code, $summary)
    }
}

function Start-AgentLoop {
    try { $mutex = New-Object Threading.Mutex($false, 'Global\GescomAgent') }
    catch { $mutex = New-Object Threading.Mutex($false, 'Local\GescomAgent') }
    try { $owned = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $owned = $true }
    if (-not $owned) { Write-Log 'une autre instance tourne deja, sortie.'; return }

    Write-Log ("demarrage agent {0} - branche '{1}' - base {2}" -f $AgentVersion, $cfg.branch_name, $cfg.database)
    $nextSync = [datetime]::MinValue
    $nextClean = [datetime]::MinValue
    $schemaOk = $false
    while ($true) {
        $sleep = [int]$cfg.poll_seconds
        try {
            if (-not $schemaOk) { $schemaOk = Test-Schema }
            if (-not $schemaOk) {
                $sleep = 300
            } else {
                if ((Get-Date) -ge $nextSync) {
                    $nextSync = (Get-Date).AddMinutes(1)   # en cas d'echec : nouvel essai dans 1 minute
                    Send-FullSync
                    $nextSync = (Get-Date).AddMinutes([double]$cfg.full_sync_minutes)
                }
                if (-not $DryRun) { Invoke-PendingJobs }
            }
        } catch {
            if ((Get-HttpStatus $_) -eq 401) {
                Write-Log 'token refuse par l''API (401) : branche desactivee ou config.json incorrect' -IsError
                $sleep = 300
            } else {
                Write-Log $_.Exception.Message -IsError
            }
        }
        if ((Get-Date) -ge $nextClean) { Remove-OldFiles; $nextClean = (Get-Date).AddHours(6) }
        Start-Sleep -Seconds $sleep
    }
}

# ---------- point d'entree ----------

try {
    $cfg = Read-Config
    foreach ($d in @($LogDir, $TmpDir, $OutboxDir)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
    $env:ISC_USER = $cfg.db_user
    $env:ISC_PASSWORD = $cfg.db_password
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

    if ($TestCode) {
        $items = @(Get-StockByCode $TestCode)
        if ($items.Count) { $items | ForEach-Object { [pscustomobject]$_ } | Format-Table -AutoSize | Out-String | Write-Host }
        else { Write-Host "Code $TestCode introuvable" }
        exit 0
    }

    if ($Register) {
        if (-not $BranchName -or -not $InstallCode) { throw '-BranchName et -InstallCode sont obligatoires' }
        Assert-ApiUrl
        $body = [ordered]@{ install_code = $InstallCode; branch_name = $BranchName; hostname = $env:COMPUTERNAME; agent_version = $AgentVersion }
        $r = Invoke-Api 'POST' '/agents/register' $body -NoAuth
        if (-not $r.token) { throw 'reponse de l''API sans token' }
        $cfg.token = [string]$r.token
        $cfg.branch_name = [string]$r.branch_name
        Save-Config
        Write-Log ("branche enregistree : '{0}' (id {1})" -f $r.branch_name, $r.branch_id)
        exit 0
    }

    if ($SelfTest) {
        $ok = $true
        Write-Host "Base      : $($cfg.database)"
        if (Test-Schema) {
            $n = @(Get-AllStock).Count
            Write-Host "Table     : $Table OK ($n articles)" -ForegroundColor Green
            $mtime = Get-DbFileTime
            if ($mtime) { Write-Host "Fichier   : derniere ecriture $mtime" }
        } else {
            Write-Host "Table     : $Table ou colonnes absentes" -ForegroundColor Red
            $ok = $false
        }
        if (-not $SkipApi) {
            Assert-ApiUrl
            $r = Invoke-Api 'GET' '/agent/ping' $null
            Write-Host ("API       : OK, branche '{0}' (id {1})" -f $r.branch_name, $r.branch_id) -ForegroundColor Green
        }
        if ($ok) { exit 0 } else { exit 1 }
    }

    if (-not $DryRun) { Assert-ApiUrl }
    if ($Once) {
        if (Test-Schema) { Send-FullSync; exit 0 } else { exit 1 }
    }
    Start-AgentLoop
} catch {
    $status = Get-HttpStatus $_
    if ($status) { Write-Log ("HTTP {0} : {1}" -f $status, $_.Exception.Message) -IsError }
    else { Write-Log $_.Exception.Message -IsError }
    exit 1
}
