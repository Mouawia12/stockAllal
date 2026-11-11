<#
  GescomAgent - envoie le stock Gescomm (InterBase 7.5) de cette branche vers l'API centrale.
  Lecture seule : chaque script isql commence par SET TRANSACTION READ ONLY.

  Utilisation :
    agent.ps1                                   boucle normale (lancee par le Planificateur de taches)
    agent.ps1 -TestCode 820AC                   lit un code dans la base et affiche le resultat
    agent.ps1 -Once [-DryRun]                   une synchro complete puis sortie (-DryRun : JSON dans outbox\)
    agent.ps1 -SelfTest [-SkipApi]              verifie la base, la table GC003, les tables des ventes... et l'API
    agent.ps1 -TestData [-Days 7]               lit ventes, achats, clients, caisse... des N derniers jours, sans rien envoyer
    agent.ps1 -Register -BranchName X -InstallCode Y   enregistre la branche aupres de l'API (utilise par install.ps1)
#>
param(
    [string]$ConfigPath = (Join-Path $env:ProgramData 'GescomAgent\config.json'),
    [string]$TestCode,
    [switch]$Once,
    [switch]$DryRun,
    [switch]$SelfTest,
    [switch]$TestData,
    [int]$Days = 7,
    [switch]$SkipApi,
    [switch]$Register,
    [string]$BranchName,
    [string]$InstallCode
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
# 1.0.9 : meme boucle que 1.0.8 ; l'installation et la mise a jour reecrivent la tache planifiee (retour de veille)
# 1.0.10 : la demande "data" (renvoyer maintenant les bons et versements d'une periode : bouton "re-verifier" du rapprochement)
# 1.0.11 : les clotures de Gescomm : la marque de la base avec chaque envoi, et les bases archivees (LaBaseN<nom>.ib)
$AgentVersion = '1.0.11'
$Table = 'GC003'
$Fields = @('CODEA', 'DESIGNATIONF2', 'STOCKREEL')
# Prix comme dans la liste des articles de Gescomm (colonne de GC003 = titre dans Gescomm => champ envoye a l'API).
# Facultatifs : une colonne absente (autre version de Gescomm) n'arrete pas la synchro, le prix part vide.
$PriceColumns = [ordered]@{
    PUGROS     = 'price'            # Pu Detail (prix de detail)
    PRIXVNFACT = 'price_wholesale'  # Prix Vente Gros
    PRIX       = 'price_buy'        # Pu Achat (le serveur ne le montre qu'aux administrateurs)
}
# Fiche de l'article (1.0.7), facultative comme les prix : seuil d'alerte et stock max (couleurs de Gescomm), famille, unite
$ItemColumns = [ordered]@{
    STOCKALERT = 'stock_alert'
    STOCKMAX   = 'stock_max'
    CODEFAM    = 'family'
    UNITMESURE = 'unit'
}
$ItemTextColumns = @('CODEFAM', 'UNITMESURE')
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
$script:Http = $null
$script:PriceCols = $null   # colonnes de prix presentes dans cette base, rempli par Test-Schema
$script:ItemCols = @()      # colonnes de la fiche presentes dans cette base, rempli par Test-Schema

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
        http_timeout_seconds = 60
        # ventes, achats, clients... (1.0.7)
        data_minutes        = 5
        data_recent_days    = 7
        data_window_minutes = 60
        data_window_days    = 60
        partners_minutes    = 15
        history_hour        = 22
        data_step_seconds   = 90
        # bases archivees de Gescomm (1.0.11), dans le dossier de la base : verifiees toutes les heures
        archive_pattern     = 'LaBaseN*.ib'
        archive_minutes     = 60
    }
    foreach ($p in $raw.PSObject.Properties) { $cfg[$p.Name] = $p.Value }
    return $cfg
}

function Save-Config {
    [IO.File]::WriteAllText($ConfigPath, (ConvertTo-Json $cfg -Depth 3), $Utf8NoBom)
}

# ---------- base InterBase (via isql, lecture seule) ----------

# $Database : une autre base que celle de la config (la copie d'une base archivee, 1.0.11)
function Invoke-Isql([string[]]$Sql, [string]$Database) {
    if (-not $Database) { $Database = $cfg.database }
    $id = [guid]::NewGuid().ToString('N')
    $in = Join-Path $TmpDir "$id.sql"
    $out = Join-Path $TmpDir "$id.out"
    $err = Join-Path $TmpDir "$id.err"
    # fichier en Windows-1252 : la base est en charset NONE et certains codes contiennent des caracteres > 127
    [IO.File]::WriteAllLines($in, [string[]](@($ReadOnlyTx, 'SET LIST ON;') + $Sql), $Ansi)
    try {
        $isqlArgs = @('-q', '-i', "`"$in`"", '-o', "`"$out`"", "`"$Database`"")
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

# Float : isql peut ecrire un DOUBLE PRECISION en notation exponentielle (4.500000000000000e+01)
function ConvertTo-Number($Value) {
    if ($null -eq $Value -or $Value -eq '') { return $null }
    return [decimal]::Parse($Value, [Globalization.NumberStyles]::Float, $Invariant)
}

function ConvertTo-StockItem($Row) {
    $designation = $Row['DESIGNATIONF2']
    if ($null -ne $designation) { $designation = $designation.Trim() }
    $item = [ordered]@{ code = $Row['CODEA']; designation = $designation; stock = (ConvertTo-Number $Row['STOCKREEL']) }
    foreach ($col in $script:PriceCols) { $item[$PriceColumns[$col]] = ConvertTo-Number $Row[$col] }
    foreach ($col in $script:ItemCols) {
        if ($ItemTextColumns -contains $col) {
            $text = $Row[$col]
            if ($null -ne $text) { $text = $text.Trim() }
            $item[$ItemColumns[$col]] = $text
        } else {
            $item[$ItemColumns[$col]] = ConvertTo-Number $Row[$col]
        }
    }
    $item
}

function Test-Schema {
    $lines = Invoke-Isql @(('SELECT RDB$FIELD_NAME FROM RDB$RELATION_FIELDS WHERE RDB$RELATION_NAME = ''{0}'';' -f $Table))
    $found = @(ConvertFrom-IsqlList $lines @('RDB$FIELD_NAME') | ForEach-Object { $_['RDB$FIELD_NAME'] })
    $missing = @($Fields | Where-Object { $found -notcontains $_ })
    if ($missing.Count) {
        Write-Log ("table {0} : colonnes absentes : {1}" -f $Table, ($missing -join ', ')) -IsError
        return $false
    }
    $script:PriceCols = @($PriceColumns.Keys | Where-Object { $found -contains $_ })
    $script:ItemCols = @($ItemColumns.Keys | Where-Object { $found -contains $_ })
    $noPrice = @($PriceColumns.Keys | Where-Object { $found -notcontains $_ })
    if ($noPrice.Count) {
        Write-Log ("table {0} : colonnes de prix absentes ({1}) : stock envoye sans ces prix" -f $Table, ($noPrice -join ', '))
    }
    return $true
}

# Colonnes lues : les obligatoires, puis les prix et la fiche presents dans cette base
function Get-SelectColumns {
    if ($null -eq $script:PriceCols) { $null = Test-Schema }
    @($Fields) + @($script:PriceCols) + @($script:ItemCols)
}

function Get-AllStock {
    $cols = Get-SelectColumns
    $lines = Invoke-Isql @("SELECT $($cols -join ', ') FROM $Table;")
    ConvertFrom-IsqlList $lines $cols | ForEach-Object { ConvertTo-StockItem $_ }
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
    $cols = Get-SelectColumns
    $lines = Invoke-Isql @("SELECT $($cols -join ', ') FROM $Table WHERE UPPER(CODEA) = UPPER('$literal');")
    ConvertFrom-IsqlList $lines $cols | ForEach-Object { ConvertTo-StockItem $_ }
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

# -NotIdempotent : requete a ne jamais rejouer si elle a pu atteindre le serveur (enregistrement : code a usage unique).
# GET, synchro et resultat se rejouent sans effet de bord.
function Invoke-Api([string]$Method, [string]$Path, $Body, [switch]$NoAuth, [switch]$NotIdempotent) {
    $uri = ([string]$cfg.api_url).TrimEnd('/') + $Path
    $bytes = $null
    if ($null -ne $Body) { $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json $Body -Depth 10 -Compress)) }
    # connexion internet instable dans certaines branches (jusqu'a 1 connexion sur 2 qui echoue) : nouvel essai
    for ($attempt = 1; ; $attempt++) {
        try { return Send-ApiRequest $Method $uri $bytes (-not $NoAuth) }
        catch {
            if ($attempt -ge 4 -or -not (Test-Retryable $_ -NotIdempotent:$NotIdempotent)) { throw }
            Start-Sleep -Seconds (2 * $attempt)
        }
    }
}

# HttpClient plutot qu'Invoke-RestMethod : son Timeout borne TOUTE la requete (connexion, envoi, reponse).
# Avec Invoke-RestMethod, un envoi bloque sur une ligne qui perd des paquets pouvait attendre 5 a 10 minutes.
function Get-HttpClient {
    if (-not $script:Http) {
        $client = New-Object System.Net.Http.HttpClient
        $client.Timeout = [TimeSpan]::FromSeconds([double]$cfg.http_timeout_seconds)
        $client.DefaultRequestHeaders.Add('X-Agent-Version', $AgentVersion)
        $client.DefaultRequestHeaders.Accept.Add((New-Object System.Net.Http.Headers.MediaTypeWithQualityHeaderValue('application/json')))
        $script:Http = $client
    }
    $script:Http
}

function Send-ApiRequest([string]$Method, [string]$Uri, [byte[]]$Bytes, [bool]$Auth) {
    $msg = New-Object System.Net.Http.HttpRequestMessage((New-Object System.Net.Http.HttpMethod($Method)), $Uri)
    if ($Auth) { $msg.Headers.Authorization = New-Object System.Net.Http.Headers.AuthenticationHeaderValue('Bearer', [string]$cfg.token) }
    if ($null -ne $Bytes) {
        # octets UTF-8 : les designations et noms de branche gardent leurs accents / l'arabe
        $msg.Content = New-Object System.Net.Http.ByteArrayContent(, $Bytes)
        $msg.Content.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue('application/json')
        $msg.Content.Headers.ContentType.CharSet = 'utf-8'
    }
    $resp = $null
    try {
        $resp = (Get-HttpClient).SendAsync($msg).GetAwaiter().GetResult()
        $text = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if (-not $resp.IsSuccessStatusCode) {
            $ex = New-Object System.Net.Http.HttpRequestException(('HTTP {0} : {1}' -f [int]$resp.StatusCode, $text))
            $ex.Data['status'] = [int]$resp.StatusCode
            throw $ex
        }
        if ($text) { return ($text | ConvertFrom-Json) }
    } finally {
        if ($resp) { $resp.Dispose() }
        $msg.Dispose()
    }
}

function Get-ExceptionChain($ErrorRecord) {
    $e = $ErrorRecord.Exception
    while ($e) { $e; $e = $e.InnerException }
}

# 0 = pas de reponse HTTP (reseau, delai depasse)
function Get-HttpStatus($ErrorRecord) {
    foreach ($e in Get-ExceptionChain $ErrorRecord) {
        if ($e.Data -and $e.Data.Contains('status')) { return [int]$e.Data['status'] }
    }
    return 0
}

# Pas de nouvel essai si le serveur a repondu (erreur HTTP = vraie reponse).
# Requete non idempotente : seulement si elle n'est jamais partie (connexion ou DNS).
function Test-Retryable($ErrorRecord, [switch]$NotIdempotent) {
    if ((Get-HttpStatus $ErrorRecord) -ne 0) { return $false }
    if (-not $NotIdempotent) { return $true }
    foreach ($e in Get-ExceptionChain $ErrorRecord) {
        if ($e -is [Net.WebException] -and
            @([Net.WebExceptionStatus]::ConnectFailure, [Net.WebExceptionStatus]::NameResolutionFailure) -contains $e.Status) { return $true }
    }
    return $false
}

# Message lisible pour le journal (sans "Exception lors de l'appel de GetResult...")
function Get-ErrorText($ErrorRecord) {
    $chain = @(Get-ExceptionChain $ErrorRecord)
    if ($chain | Where-Object { $_ -is [Threading.Tasks.TaskCanceledException] }) {
        return ('API : pas de reponse en {0} s (connexion lente ou coupee)' -f $cfg.http_timeout_seconds)
    }
    $web = $chain | Where-Object { $_ -is [Net.WebException] } | Select-Object -First 1
    if ($web) { return "API : $($web.Message)" }
    return $chain[-1].Message
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
        return $items.Count
    }
    Invoke-Api 'POST' '/agent/sync' $payload | Out-Null
    Write-Log ('SYNC OK : {0} articles envoyes en {1:N1} s' -f $items.Count, ((Get-Date) - $t0).TotalSeconds)
    return $items.Count
}

# Bouton "synchroniser maintenant" de la page des branches : tout le stock part maintenant, puis l'agent confirme la demande.
function Invoke-SyncJob($Job) {
    $result = [ordered]@{ job_id = $Job.id; type = 'sync'; count = 0; error = $null; read_at = $null }
    try {
        $result.count = Send-FullSync
    } catch {
        $result.error = 'sync_failed'
        Write-Log ("demande {0} (synchro) : {1}" -f $Job.id, (Get-ErrorText $_)) -IsError
    }
    $result.read_at = Get-Now
    Invoke-Api 'POST' '/agent/result' $result | Out-Null
    if ($result.error) { $summary = $result.error } else { $summary = '{0} articles' -f $result.count }
    Write-Log ("DEMANDE {0} : synchro complete -> {1}" -f $Job.id, $summary)
}

function Invoke-PendingJobs {
    $r = Invoke-Api 'GET' '/agent/pending' $null
    foreach ($job in @($r.jobs)) {
        if ($null -eq $job) { continue }
        $type = [string]$job.type
        if ($type -eq 'sync') { Invoke-SyncJob $job; continue }
        if ($type -eq 'data') { Add-DataJob $job; continue }
        # demande d'une version plus recente du serveur : on le dit au lieu de la lire comme un code
        if ($type -and $type -ne 'code') {
            Invoke-Api 'POST' '/agent/result' ([ordered]@{ job_id = $job.id; type = $type; error = 'unsupported'; read_at = Get-Now }) | Out-Null
            Write-Log ("DEMANDE {0} : type '{1}' inconnu de l'agent {2} -> unsupported" -f $job.id, $type, $AgentVersion)
            continue
        }
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
                Write-Log ("demande {0} : {1}" -f $job.id, (Get-ErrorText $_)) -IsError
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

# ---------- ventes, achats, clients, fournisseurs, versements (1.0.7) ----------
#
# Chaque jeu = une table Gescomm (+ la table de ses lignes). Colonne Gescomm => champ envoye ; les valeurs partent
# telles qu'isql les ecrit (texte), le serveur les convertit. Une colonne absente d'une version de Gescomm part vide ;
# une table ou une cle absente arrete ce jeu seulement (signale au serveur), les autres continuent.
# Envoi en deux temps (agent/README.md) : l'empreinte de chaque groupe (un jour, ou un paquet de clients), puis
# seulement les groupes que le serveur demande, par pages de ~1000 lignes. Rien n'a change = rien n'est envoye.
$Datasets = @(
    @{
        name = 'sales'; table = 'GC010'; key = @('NUMEROS'); date = 'DATES'
        cols = [ordered]@{ NUMEROS = 'number'; HEURE = 'time'; CODEF = 'partner'; MONTANT = 'amount'; MONTANTREMISE = 'discount'
            TOTALSANSREMISE = 'gross'; REGLEMENT = 'payment'; AGENT = 'entered_by'; CODECOMMERC = 'served_by'
            NBJOURCREDIT = 'credit_days'; OBSERVATION = 'note' }
        lines = 'GC011'; lineKey = @('NUMEROS')
        lineCols = [ordered]@{ CODEA = 'code'; QTE = 'qty'; PU = 'price'; REMISE = 'discount'; TOTAL = 'total'
            PRIXACHAT = 'cost'; TVA = 'vat'; ORDERE = 'position' }
    },
    @{
        # numerote par fournisseur : la cle est numero + fournisseur ; GC007 n'a pas d'index sur DATEBON mais reste petite
        name = 'purchases'; table = 'GC007'; key = @('NUMEROE', 'CODEF'); date = 'DATEBON'
        cols = [ordered]@{ NUMEROE = 'number'; CODEF = 'partner'; HEURE = 'time'; MONTANT = 'amount'; MONTANTREMISE = 'discount'
            TOTALSANSREMISE = 'gross'; REGLEMENT = 'payment'; AGENT = 'entered_by'; OBSERVATION = 'note' }
        lines = 'GC008'; lineKey = @('NUMEROE', 'CODEF')
        lineCols = [ordered]@{ CODEA = 'code'; QTE = 'qty'; PU = 'price'; REMISE = 'discount'; TOTAL = 'total'
            TVA = 'vat'; ORDERE = 'position' }
    },
    @{
        name = 'customers'; table = 'GC002'; key = @('CODECLI'); date = $null
        cols = [ordered]@{ CODECLI = 'code'; MONCLIENT = 'name'; CLIENT = 'name_alt'; TEL = 'phone'; ADRESSE2 = 'address'
            ADRESSE = 'address_alt'; WILAYA = 'wilaya'; ACTIVITEE = 'activity'; TYPECLI = 'type'; SOLDEINIT = 'opening_balance'
            SOLDEMAX = 'credit_limit'; LIMITVERS = 'credit_days'; CLIBLOQUE = 'blocked'; NIF2 = 'nif'; NIS2 = 'nis'
            REGISTRE2 = 'rc'; NARTICLE2 = 'ai' }
    },
    @{
        name = 'suppliers'; table = 'GC004'; key = @('CODEFOU'); date = $null
        cols = [ordered]@{ CODEFOU = 'code'; MONFOURNISSEUR = 'name'; FOURNISSEUR = 'name_alt'; TEL = 'phone'; MOBIL = 'mobile'
            ADRESSE2 = 'address'; ADRESSE = 'address_alt'; WILAYA = 'wilaya'; PARSONNECONTACT = 'contact'
            SOLDEINIT = 'opening_balance'; FOURBLOQUE = 'blocked'; NIF2 = 'nif'; NIS2 = 'nis'; REGISTRE2 = 'rc'; NARTICLE2 = 'ai' }
    },
    @{
        # GC62.CODEFOU = le code du client (GC002.CODECLI), malgre son nom
        name = 'customer_payments'; table = 'GC62'; key = @('DATEVER', 'NUMEROTATION'); date = 'DATEVER'
        cols = [ordered]@{ NUMEROTATION = 'number'; HEURE = 'time'; CODEFOU = 'partner'; TYPEVER = 'type'; MONTANT = 'amount'
            NUMCHEQUE = 'cheque'; ETATCHEQUE = 'cheque_state'; DATEPAYE = 'paid_on'; NUMEROBON = 'document_number'
            OBSERVATION = 'note'; AGENT = 'entered_by' }
    },
    @{
        name = 'supplier_payments'; table = 'GC60'; key = @('DATEVER', 'NUMEROTATION'); date = 'DATEVER'
        cols = [ordered]@{ NUMEROTATION = 'number'; HEURE = 'time'; CODEFOU = 'partner'; TYPEVER = 'type'; MONTANT = 'amount'
            NUMCHEQUE = 'cheque'; ETATCHEQUE = 'cheque_state'; NUMEROBON = 'document_number'; OBSERVATION = 'note'; AGENT = 'entered_by' }
    },
    # la caisse (1.0.8) : ce qui y entre (C008 : versement d'un client, vente au comptoir, entree a la main) et ce qui en sort
    # (C002 : versement a un fournisseur, depense). Solde = somme des entrees - somme des sorties, comme MONTANTCAISSE de Gescomm.
    # ORDERE numerote toute la table ; NUMVER = le NUMEROTATION du versement (GC62 / GC60) qui a fait la ligne.
    @{
        name = 'cash_in'; table = 'C008'; key = @('DATEENTREE', 'ORDERE'); date = 'DATEENTREE'
        cols = [ordered]@{ ORDERE = 'number'; HEURE = 'time'; MONTANTENTREE = 'amount'; OBSERVATION2 = 'label'; OBSERVATION = 'note'
            PERSONNE = 'person'; TYPEENTREE = 'type'; NUMCHEQUE = 'cheque'; NUMVER = 'payment_number'; NUMEROBON = 'document_number'
            COMPTOIR = 'counter'; AGENT = 'entered_by' }
    },
    @{
        name = 'cash_out'; table = 'C002'; key = @('DATEC', 'ORDERE'); date = 'DATEC'
        cols = [ordered]@{ ORDERE = 'number'; HEURE = 'time'; MONTANT = 'amount'; CHARGE2 = 'label'; CHARGE = 'category'
            CODECHARGE = 'category_code'; UNECHARGE = 'expense'; OBSERVATION = 'note'; NUMVER = 'payment_number'
            NUMEROBON = 'document_number'; AGENT = 'entered_by' }
    }
)
# Jeux dates deja dans l'historique avant que state.json ne les nomme (1.0.7) : les autres le recoivent tout de suite
$HistorySets107 = @('sales', 'purchases', 'customer_payments', 'supplier_payments')
$Sep = [string][char]0x1F
$LineSep = [string][char]0x1E
$script:DataCols = @{}     # nom du jeu -> colonnes presentes (en-tete, lignes), rempli par Test-DatasetSchema
$script:DataQueue = New-Object System.Collections.ArrayList
$script:DataNext = @{}     # "jeu|palier" -> prochaine fois
$script:DataBroken = @{}   # nom du jeu -> pas avant (apres une erreur)
$script:HistoryRunning = $false
$script:HistoryFull = $false   # l'historique en cours est celui de la nuit (tous les jeux), pas seulement des nouveaux
$script:DataJobs = @{}     # demande "data" (1.0.10) -> etapes restantes, erreur
$script:State = $null

function Get-Today { (Get-Date).Date }
function Format-Day([datetime]$Day) { $Day.ToString('yyyy-MM-dd', $Invariant) }

# history_sets : les jeux dates dont tout l'historique est deja passe (un jeu ajoute par une mise a jour n'y est pas)
# archives (1.0.11) : nom du fichier -> taille, date, ce qui en est fait (done, ou ce que le serveur a repondu)
function Read-State {
    $file = Join-Path $Dir 'state.json'
    $state = @{ history_done_at = $null; history_sets = @(); archives = @{} }
    if (Test-Path $file) {
        try {
            $raw = Get-Content $file -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($raw.history_done_at) { $state.history_done_at = [string]$raw.history_done_at }
            if ($null -ne $raw.history_sets) { $state.history_sets = @($raw.history_sets | ForEach-Object { [string]$_ }) }
            elseif ($state.history_done_at) { $state.history_sets = $HistorySets107 }
            if ($raw.archives) {
                foreach ($p in $raw.archives.PSObject.Properties) {
                    $state.archives[$p.Name] = @{ size = [long]$p.Value.size; mtime = [string]$p.Value.mtime; status = [string]$p.Value.status; exercise = $p.Value.exercise }
                }
            }
        } catch { }
    }
    $state
}

function Save-State {
    try { [IO.File]::WriteAllText((Join-Path $Dir 'state.json'), (ConvertTo-Json $script:State -Depth 5), $Utf8NoBom) } catch { }
}

# Les colonnes de la table (une requete sur RDB$RELATION_FIELDS)
function Get-TableColumns([string]$Name, [string]$Db) {
    $lines = Invoke-Isql @(('SELECT RDB$FIELD_NAME FROM RDB$RELATION_FIELDS WHERE RDB$RELATION_NAME = ''{0}'';' -f $Name)) $Db
    @(ConvertFrom-IsqlList $lines @('RDB$FIELD_NAME') | ForEach-Object { ([string]$_['RDB$FIELD_NAME']).Trim() })
}

# Colonnes lues pour ce jeu : la cle et la date d'abord (obligatoires), puis celles de cols presentes dans cette base
# ($Db : une base archivee, d'une version de Gescomm peut-etre plus ancienne : ses colonnes a part)
function Test-DatasetSchema($Ds, [string]$Db) {
    $cacheKey = $Ds.name
    if ($Db) { $cacheKey = $Db + '|' + $Ds.name }
    if ($script:DataCols.ContainsKey($cacheKey)) { return $script:DataCols[$cacheKey] }
    $found = Get-TableColumns $Ds.table $Db
    # la 1re colonne ouvre chaque enregistrement dans la sortie d'isql : chaque colonne une seule fois (DATEVER est cle et date)
    $need = @($Ds.key)
    if ($Ds.date -and $need -notcontains $Ds.date) { $need += $Ds.date }
    $missing = @($need | Where-Object { $found -notcontains $_ })
    if ($missing.Count) { throw ("table {0} absente ou sans {1}" -f $Ds.table, ($missing -join ', ')) }
    $head = @($need) + @($Ds.cols.Keys | Where-Object { $found -contains $_ -and $need -notcontains $_ })
    $line = @()
    if ($Ds.lines) {
        $lineFound = Get-TableColumns $Ds.lines $Db
        $lineNeed = @($Ds.lineKey) + @('CODEA')
        $missing = @($lineNeed | Where-Object { $lineFound -notcontains $_ })
        if ($missing.Count) { throw ("table {0} absente ou sans {1}" -f $Ds.lines, ($missing -join ', ')) }
        $line = @($Ds.lineKey) + @($Ds.lineCols.Keys | Where-Object { $lineFound -contains $_ -and $Ds.lineKey -notcontains $_ })
    }
    $cols = @{ head = $head; line = $line }
    $script:DataCols[$cacheKey] = $cols
    $cols
}

# Un DATE d'isql : 2026-11-04 (dialecte 3) ou 4-NOV-2026 (dialecte 1) -> 2026-11-04 ; illisible -> $null
function ConvertTo-IsoDay($Value) {
    if ($null -eq $Value) { return $null }
    $v = ([string]$Value).Trim()
    $d = [datetime]::MinValue
    foreach ($f in @('yyyy-MM-dd', 'd-MMM-yyyy', 'dd-MMM-yyyy')) {
        if ([datetime]::TryParseExact($v, $f, $Invariant, [Globalization.DateTimeStyles]::None, [ref]$d)) { return (Format-Day $d) }
    }
    return $null
}

# Cle d'un enregistrement (colonnes jointes par |), la meme pour l'en-tete et ses lignes
function Get-RowKey($Row, [string[]]$Cols) {
    (@($Cols | ForEach-Object { ([string]$Row[$_]).Trim() }) -join '|')
}

# Empreinte d'un enregistrement : 12 chiffres hexadecimaux de SHA-1 (48 bits), la somme d'un groupe tient dans un Int64
function Get-Digest([string]$Text) {
    $sha = [Security.Cryptography.SHA1]::Create()
    try { $hash = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Text)) } finally { $sha.Dispose() }
    [Convert]::ToInt64((-join ($hash[0..5] | ForEach-Object { $_.ToString('x2') })), 16)
}

# Paquet d'un client ou fournisseur : 32 paquets d'apres sa cle (une modification n'en renvoie qu'un)
function Get-Bucket([string]$Key) {
    $sha = [Security.Cryptography.SHA1]::Create()
    try { $hash = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Key)) } finally { $sha.Dispose() }
    'p{0:D2}' -f ($hash[0] % 32)
}

# Les enregistrements d'un jeu, par groupe : $From / $To (jours, inclus) pour un jeu date, toute la table sinon.
# $Db : la copie d'une base archivee (1.0.11), sinon la base de la config.
# Retour : dictionnaire groupe -> liste d'enregistrements (avec key, digest, champs, lines)
function Read-Dataset($Ds, [string]$From, [string]$To, [string]$Db) {
    $cols = Test-DatasetSchema $Ds $Db
    $where = ''
    if ($Ds.date) { $where = (" WHERE H.{0} BETWEEN CAST('{1}' AS DATE) AND CAST('{2}' AS DATE)" -f $Ds.date, $From, $To) }
    $sql = @(('SELECT {0} FROM {1} H{2};' -f (@($cols.head | ForEach-Object { "H.$_" }) -join ', '), $Ds.table, $where))
    $heads = @(ConvertFrom-IsqlList (Invoke-Isql $sql $Db) $cols.head)

    $linesByKey = @{}
    if ($Ds.lines) {
        $on = @($Ds.lineKey | ForEach-Object { "L.$_ = H.$_" }) -join ' AND '
        $sql = @(('SELECT {0} FROM {1} H JOIN {2} L ON {3}{4};' -f (@($cols.line | ForEach-Object { "L.$_" }) -join ', '), $Ds.table, $Ds.lines, $on, $where))
        foreach ($row in (ConvertFrom-IsqlList (Invoke-Isql $sql $Db) $cols.line)) {
            $k = Get-RowKey $row $Ds.lineKey
            if (-not $linesByKey.ContainsKey($k)) { $linesByKey[$k] = New-Object System.Collections.ArrayList }
            [void]$linesByKey[$k].Add($row)
        }
    }

    $groups = [ordered]@{}
    foreach ($row in $heads) {
        $key = Get-RowKey $row $Ds.key
        if ($Ds.date) {
            $group = ConvertTo-IsoDay $row[$Ds.date]
            if (-not $group) { continue }
        } else {
            $group = Get-Bucket $key
        }
        $rec = [ordered]@{ key = $key; digest = 0 }
        $text = New-Object Text.StringBuilder
        [void]$text.Append($key)
        foreach ($c in $cols.head) {
            $v = $row[$c]
            if ($Ds.cols.Contains($c)) {
                if ($c -eq $Ds.date -or ($Ds.cols[$c] -eq 'paid_on')) { $v = ConvertTo-IsoDay $v }
                $rec[$Ds.cols[$c]] = $v
            }
            [void]$text.Append($Sep).Append([string]$v)
        }
        if ($Ds.lines) {
            $list = @()
            if ($linesByKey.ContainsKey($key)) {
                $list = @($linesByKey[$key] | Sort-Object @{ e = { ConvertTo-Number $_['ORDERE'] } }, @{ e = { $_['CODEA'] } })
            }
            $out = New-Object System.Collections.ArrayList
            foreach ($l in $list) {
                $line = [ordered]@{}
                [void]$text.Append($LineSep)
                foreach ($c in $cols.line) {
                    if ($Ds.lineCols.Contains($c)) { $line[$Ds.lineCols[$c]] = $l[$c] }
                    [void]$text.Append($Sep).Append([string]$l[$c])
                }
                [void]$out.Add($line)
            }
            $rec.lines = $out.ToArray()
        }
        $rec.digest = Get-Digest $text.ToString()
        if (-not $groups.Contains($group)) { $groups[$group] = New-Object System.Collections.ArrayList }
        [void]$groups[$group].Add($rec)
    }
    $groups
}

# "nombre:somme des empreintes" de chaque groupe
function Get-GroupDigests($Groups) {
    $digests = [ordered]@{}
    foreach ($g in $Groups.Keys) {
        [long]$sum = 0
        foreach ($r in $Groups[$g]) { $sum += [long]$r.digest }
        $digests[$g] = '{0}:{1}' -f $Groups[$g].Count, $sum.ToString($Invariant)
    }
    $digests
}

function Get-RowCount($Records) {
    $n = 0
    foreach ($r in $Records) { $n += 1; if ($r.Contains('lines')) { $n += @($r.lines).Count } }
    $n
}

# ---------- exercices de Gescomm (1.0.11) ----------
#
# A la cloture, Gescomm copie sa base sous LaBaseN<nom>.ib (meme dossier), vide les bons et les versements (procedure
# CLOTURE1) et reporte le solde de chacun en un versement du jour "Solde Initial Client de l'exercice <nom>"
# (INITIALISERSOLDENEW). Ce jour et ce nom sont la marque de la base : envoyee avec chaque envoi de ces jeux, le serveur
# y voit la cloture et garde l'exercice qui se ferme. Les bases archivees partent une fois chacune (Add-DueArchive).
$ExerciseSets = @('sales', 'purchases', 'customer_payments', 'supplier_payments')
$MarkTables = [ordered]@{ sales = @('GC010', 'DATES'); purchases = @('GC007', 'DATEBON'); customer_payments = @('GC62', 'DATEVER'); supplier_payments = @('GC60', 'DATEVER') }
$ArchiveDir = Join-Path $Dir 'archives'
$script:Archive = $null                    # la base archivee en cours d'envoi
$script:ArchiveNext = [datetime]::MinValue

# Sortie "SET LIST ON" de requetes a une ligne : nom du champ -> valeur
function ConvertFrom-IsqlValues([string[]]$Lines) {
    $values = @{}
    foreach ($line in $Lines) {
        if ($line.Length -gt 32) { $label = $line.Substring(0, 32).TrimEnd(); $value = $line.Substring(32).TrimEnd() }
        else { $label = $line.TrimEnd(); $value = '' }
        if (-not $label -or $label -like '=*') { continue }
        if ($value -eq '<null>') { $value = $null }
        $values[$label] = $value
    }
    $values
}

# La marque d'une base : le jour de ses "Solde Initial" (clients, sinon fournisseurs) et l'exercice qu'ils reportent,
# le premier et le dernier jour de ses bons et versements ; -Counts (une archive) : combien dans chaque jeu.
function Get-BaseMark([string]$Db, [switch]$Counts) {
    $sql = New-Object System.Collections.Generic.List[string]
    foreach ($t in @('GC62', 'GC60')) {
        $sql.Add(("SELECT MIN(DATEVER) AS {0}DAY FROM {0} WHERE OBSERVATION STARTING WITH 'Solde Initial';" -f $t))
        $sql.Add(("SELECT MAX(OBSERVATION) AS {0}NOTE FROM {0} WHERE OBSERVATION STARTING WITH 'Solde Initial' AND DATEVER = (SELECT MIN(DATEVER) FROM {0} WHERE OBSERVATION STARTING WITH 'Solde Initial');" -f $t))
    }
    foreach ($name in $MarkTables.Keys) {
        $t = $MarkTables[$name]
        $alias = $name.ToUpper()
        $agg = 'MIN({0}) AS {1}_A, MAX({0}) AS {1}_B' -f $t[1], $alias
        if ($Counts) { $agg += ', COUNT(*) AS {0}_N' -f $alias }
        $sql.Add(('SELECT {0} FROM {1};' -f $agg, $t[0]))
    }
    $v = ConvertFrom-IsqlValues (Invoke-Isql ([string[]]$sql) $Db)

    $day = $null
    $note = $null
    foreach ($t in @('GC62', 'GC60')) {
        $d = ConvertTo-IsoDay $v[$t + 'DAY']
        if ($d -and (-not $day -or $d -lt $day)) { $day = $d; $note = $v[$t + 'NOTE'] }
    }
    $label = $null
    if ($note) {
        $m = [regex]::Match([string]$note, 'exercice\s+(.+)$', 'IgnoreCase')
        if ($m.Success) { $label = $m.Groups[1].Value.Trim() } else { $label = ([string]$note).Trim() }
        if ($label.Length -gt 40) { $label = $label.Substring(0, 40) }
    }
    $mark = [ordered]@{ opened_on = $day; label = $label; first_day = $null; last_day = $null; spans = @{} }
    foreach ($name in $MarkTables.Keys) {
        $alias = $name.ToUpper()
        $a = ConvertTo-IsoDay $v[$alias + '_A']
        $b = ConvertTo-IsoDay $v[$alias + '_B']
        if ($a -and (-not $mark.first_day -or $a -lt $mark.first_day)) { $mark.first_day = $a }
        if ($b -and (-not $mark.last_day -or $b -gt $mark.last_day)) { $mark.last_day = $b }
        $n = 0
        if ($Counts -and $v[$alias + '_N']) { $n = [int](ConvertTo-Number $v[$alias + '_N']) }
        $mark.spans[$name] = @{ from = $a; to = $b; n = $n }
    }
    $mark
}

function Get-DbPath {
    $m = [regex]::Match([string]$cfg.database, '[A-Za-z]:\\.+$')
    if ($m.Success) { return $m.Value }
    return $null
}

# Les bases archivees : LaBaseN*.ib (archive_pattern) dans le dossier de la base, sauf la base elle-meme
function Get-ArchiveFiles {
    $db = Get-DbPath
    if (-not $db) { return @() }
    @(Get-ChildItem -LiteralPath (Split-Path -Parent $db) -Filter '*.ib' -File -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -like [string]$cfg.archive_pattern -and $_.FullName -ne $db -and $_.Length -gt 0 } | Sort-Object LastWriteTime)
}

function Remove-ArchiveCopies {
    Get-ChildItem -LiteralPath $ArchiveDir -File -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
}

# Toutes les heures (archive_minutes), et seulement si aucune n'est en cours : la premiere base archivee nouvelle ou
# changee (taille, date) part. Une a la fois ; la suivante des que celle-ci a fini.
function Add-DueArchive {
    if ($script:Archive -or (Get-Date) -lt $script:ArchiveNext) { return }
    $script:ArchiveNext = (Get-Date).AddMinutes([double]$cfg.archive_minutes)
    foreach ($f in (Get-ArchiveFiles)) {
        $stamp = $f.LastWriteTimeUtc.ToString('o', $Invariant)
        $known = $script:State.archives[$f.Name]
        if ($known -and [long]$known.size -eq $f.Length -and [string]$known.mtime -eq $stamp) { continue }
        try {
            if (Start-Archive $f $stamp) { return }
        } catch {
            Write-Log ('archive {0} : {1}' -f $f.Name, (Get-ErrorText $_)) -IsError
            Remove-ArchiveCopies
        }
    }
}

# Une base archivee : copiee (InterBase n'ouvre jamais l'archive elle-meme), sa marque au serveur, qui repond son
# exercice ; puis ses ventes, achats et versements mois par mois (prio 5 : apres tout le reste). $true : envoi commence.
function Start-Archive($File, [string]$Stamp) {
    New-Item -ItemType Directory -Force -Path $ArchiveDir | Out-Null
    # un nom court : InterBase 7.5 refuse un chemin de base long (-924 "bad parameters on attach" a 140 caracteres)
    $copy = Join-Path $ArchiveDir 'archive.ib'
    if ($copy.Length -gt 100) { throw "chemin trop long pour InterBase : $copy" }
    Copy-Item -LiteralPath $File.FullName -Destination $copy -Force
    $db = 'localhost:' + $copy
    $mark = Get-BaseMark $db -Counts
    $counts = [ordered]@{}
    foreach ($n in $ExerciseSets) { $counts[$n] = $mark.spans[$n].n }
    $r = Invoke-Api 'POST' '/agent/archive' ([ordered]@{ file = $File.Name; opened_on = $mark.opened_on; label = $mark.label
        first_day = $mark.first_day; last_day = $mark.last_day; counts = $counts })
    if (-not $r.exercise_id) {
        Remove-Item -LiteralPath $copy -Force -ErrorAction SilentlyContinue
        # wait : la base en cours n'a pas encore donne sa marque (juste apres l'installation) : toutes les archives
        # attendraient de meme, on reessaie dans 5 minutes
        if ([string]$r.skip -eq 'wait') {
            $script:ArchiveNext = (Get-Date).AddMinutes(5)
            Write-Log ('ARCHIVE {0} : en attente de la marque de la base en cours' -f $File.Name)
            return $true
        }
        $script:State.archives[$File.Name] = @{ size = $File.Length; mtime = $Stamp; status = [string]$r.skip; exercise = $null }
        Save-State
        Write-Log ('ARCHIVE {0} : rien a envoyer ({1})' -f $File.Name, $r.skip)
        return $false
    }
    $steps = 0
    foreach ($ds in @($Datasets | Where-Object { $ExerciseSets -contains $_.name })) {
        $span = $mark.spans[$ds.name]
        if (-not $span.from -or -not $span.to -or -not $span.n) { continue }
        $m = [datetime]::ParseExact($span.from.Substring(0, 7) + '-01', 'yyyy-MM-dd', $Invariant)
        $end = [datetime]::ParseExact($span.to, 'yyyy-MM-dd', $Invariant)
        while ($m -le $end) {
            [void]$script:DataQueue.Add(@{ ds = $ds; tier = 'archive'; prio = 5; from = (Format-Day $m); to = (Format-Day $m.AddMonths(1).AddDays(-1))
                db = $db; exercise = [int]$r.exercise_id })
            $steps++
            $m = $m.AddMonths(1)
        }
    }
    $script:Archive = @{ name = $File.Name; copy = $copy; exercise = [int]$r.exercise_id; left = $steps; size = $File.Length; mtime = $Stamp; t0 = Get-Date }
    Write-Log ('ARCHIVE {0} : exercice {1} du {2} au {3}, {4} etape(s)' -f $File.Name, $r.name, $mark.first_day, $mark.last_day, $steps)
    if (-not $steps) { Complete-ArchiveStep $null }
    return $true
}

# Une etape de la base archivee faite ($ErrorText : elle a echoue, l'envoi s'arrete et reprendra au prochain passage).
# La derniere le dit au serveur, retient le fichier (taille, date) et efface la copie.
function Complete-ArchiveStep($ErrorText) {
    $a = $script:Archive
    if (-not $a) { return }
    $failed = [bool]$ErrorText
    if ($failed) {
        foreach ($s in @($script:DataQueue | Where-Object { $_.tier -eq 'archive' })) { $script:DataQueue.Remove($s) }
        Write-Log ('ARCHIVE {0} : arretee ({1}), reprise au prochain passage' -f $a.name, $ErrorText) -IsError
        $a.left = 0
    } else {
        $a.left = $a.left - 1
    }
    if ($a.left -gt 0) { return }
    if (-not $failed) {
        try {
            Invoke-Api 'POST' '/agent/archive' ([ordered]@{ file = $a.name; done = $true; exercise_id = $a.exercise }) | Out-Null
            $script:State.archives[$a.name] = @{ size = $a.size; mtime = $a.mtime; status = 'done'; exercise = $a.exercise }
            Save-State
            Write-Log ('ARCHIVE {0} : envoyee en {1:N0} s' -f $a.name, ((Get-Date) - $a.t0).TotalSeconds)
        } catch {
            Write-Log (Get-ErrorText $_) -IsError
        }
        # la suivante tout de suite
        $script:ArchiveNext = [datetime]::MinValue
    }
    Remove-Item -LiteralPath $a.copy -Force -ErrorAction SilentlyContinue
    $script:Archive = $null
}

# Une etape : lire l'intervalle, comparer les empreintes, envoyer les groupes demandes par pages.
# Entre deux pages, les demandes en direct (actualiser maintenant) passent d'abord. Au-dela de data_step_seconds, l'etape
# s'arrete : les groupes pas encore envoyes different toujours, la prochaine fois reprend la ou elle s'est arretee.
# Retour : $true si tout ce que le serveur demandait est parti.
function Invoke-DataStep($Step) {
    $ds = $Step.ds
    $t0 = Get-Date
    # l'exercice : celui d'une base archivee (exercise_id), ou la marque de la base lue AVANT ses donnees : une cloture
    # entre les deux fait au pire retirer ici des jours de l'exercice qui se ferme (son archive les rendra), jamais
    # entrer l'ancien exercice dans le nouveau
    $extra = [ordered]@{}
    if ($Step.exercise) {
        $extra.exercise_id = [int]$Step.exercise
    } elseif ($ExerciseSets -contains $ds.name) {
        try {
            $mark = Get-BaseMark $null
            $extra.exercise = [ordered]@{ opened_on = $mark.opened_on; label = $mark.label; first_day = $mark.first_day }
        } catch {
            Write-Log ('marque de la base : ' + (Get-ErrorText $_)) -IsError
        }
    }
    $groups = Read-Dataset $ds $Step.from $Step.to $Step.db
    $body = [ordered]@{ dataset = $ds.name; from = $null; to = $null; groups = (Get-GroupDigests $groups) }
    if ($ds.date) { $body.from = $Step.from; $body.to = $Step.to }
    foreach ($k in $extra.Keys) { $body[$k] = $extra[$k] }
    $r = Invoke-Api 'POST' '/agent/digest' $body
    $send = @($r.send | Where-Object { $_ })
    if (-not $send.Count) { return $true }
    $pageRows = 1000
    if ($r.page_rows) { $pageRows = [int]$r.page_rows }

    $sent = 0
    $page = [ordered]@{}
    $rows = 0
    for ($i = 0; $i -le $send.Count; $i++) {
        $records = @()
        if ($i -lt $send.Count -and $groups.Contains([string]$send[$i])) { $records = $groups[[string]$send[$i]].ToArray() }
        $n = Get-RowCount $records
        # page pleine (un jour plus grand qu'une page part seul), ou fin de la liste
        if ($page.Count -and ($i -eq $send.Count -or $rows + $n -gt $pageRows)) {
            $post = [ordered]@{ dataset = $ds.name; groups = $page }
            foreach ($k in $extra.Keys) { $post[$k] = $extra[$k] }
            $res = Invoke-Api 'POST' '/agent/records' $post
            $sent += $page.Count
            $what = 'DONNEES'
            if ($Step.exercise) { $what = 'ARCHIVE' }
            Write-Log ('{0} {1} : {2} groupe(s), {3} ligne(s) -> {4} ecrit(s), {5} retire(s)' -f $what, $ds.name, $page.Count, $rows, $res.written, $res.removed)
            $page = [ordered]@{}
            $rows = 0
            try { Invoke-PendingJobs } catch { Write-Log (Get-ErrorText $_) -IsError }
            if ($i -lt $send.Count -and ((Get-Date) - $t0).TotalSeconds -gt [double]$cfg.data_step_seconds) {
                Write-Log ('DONNEES {0} : {1} groupe(s) sur {2} envoye(s), la suite au prochain passage' -f $ds.name, $sent, $send.Count)
                return $false
            }
        }
        if ($i -lt $send.Count) {
            $page[[string]$send[$i]] = $records
            $rows += $n
        }
    }
    return $true
}

# Les etapes dues : 7 derniers jours toutes les 5 min, 60 derniers jours toutes les heures, clients toutes les 15 min,
# et une fois par jour (le soir, ou des que possible si la derniere date de plus de 2 jours) tout l'historique, mois par mois.
# Un jeu qui n'a jamais eu son historique (la caisse apres la mise a jour en 1.0.8) l'a tout de suite : son solde
# compte tous les jours depuis le debut, pas seulement les 60 derniers.
function Add-DueDataSteps {
    $now = Get-Date
    $today = Get-Today
    foreach ($ds in $Datasets) {
        if ($script:DataBroken.ContainsKey($ds.name) -and $now -lt $script:DataBroken[$ds.name]) { continue }
        $tiers = @()
        if ($ds.date) {
            $tiers += @{ tier = 'recent'; prio = 1; every = [double]$cfg.data_minutes; from = $today.AddDays(1 - [int]$cfg.data_recent_days) }
            $tiers += @{ tier = 'window'; prio = 3; every = [double]$cfg.data_window_minutes; from = $today.AddDays(1 - [int]$cfg.data_window_days) }
        } else {
            $tiers += @{ tier = 'all'; prio = 2; every = [double]$cfg.partners_minutes; from = $null }
        }
        foreach ($t in $tiers) {
            $id = '{0}|{1}' -f $ds.name, $t.tier
            if ($script:DataNext.ContainsKey($id) -and $now -lt $script:DataNext[$id]) { continue }
            $script:DataNext[$id] = $now.AddMinutes($t.every)
            $step = @{ ds = $ds; tier = $t.tier; prio = $t.prio; from = $null; to = $null }
            if ($t.from) { $step.from = Format-Day $t.from; $step.to = Format-Day $today }
            [void]$script:DataQueue.Add($step)
        }
    }

    if ($script:HistoryRunning) { return }
    $last = [datetime]::MinValue
    if ($script:State.history_done_at) { try { $last = [datetime]::Parse($script:State.history_done_at, $Invariant) } catch { } }
    $hours = ($now - $last).TotalHours
    $quiet = $now.Hour -ge [int]$cfg.history_hour -or $now.Hour -lt 6
    $full = $last -eq [datetime]::MinValue -or ($hours -ge 20 -and $quiet) -or $hours -ge 44
    $sets = @($Datasets | Where-Object { $_.date })
    if (-not $full) { $sets = @($sets | Where-Object { $script:State.history_sets -notcontains $_.name }) }
    if (-not $sets.Count) { return }
    foreach ($ds in $sets) {
        if ($script:DataBroken.ContainsKey($ds.name) -and $now -lt $script:DataBroken[$ds.name]) { continue }
        try {
            $null = Test-DatasetSchema $ds
            $span = @(ConvertFrom-IsqlList (Invoke-Isql @(('SELECT MIN({0}) AS FIRSTDAY, MAX({0}) AS LASTDAY FROM {1};' -f $ds.date, $ds.table))) @('FIRSTDAY', 'LASTDAY'))
        } catch {
            Set-DatasetBroken $ds $_
            continue
        }
        $first = ConvertTo-IsoDay $span[0]['FIRSTDAY']
        if (-not $first) { continue }
        $end = $today
        $lastDay = ConvertTo-IsoDay $span[0]['LASTDAY']
        if ($lastDay -and [datetime]::ParseExact($lastDay, 'yyyy-MM-dd', $Invariant) -gt $end) { $end = [datetime]::ParseExact($lastDay, 'yyyy-MM-dd', $Invariant) }
        $m = [datetime]::ParseExact($first.Substring(0, 7) + '-01', 'yyyy-MM-dd', $Invariant)
        while ($m -le $end) {
            [void]$script:DataQueue.Add(@{ ds = $ds; tier = 'history'; prio = 4; from = (Format-Day $m); to = (Format-Day $m.AddMonths(1).AddDays(-1)) })
            $m = $m.AddMonths(1)
        }
    }
    $script:HistoryRunning = $true
    $script:HistoryFull = $full
    if ($full) { Write-Log 'DONNEES : verification de tout l''historique, mois par mois' }
    else { Write-Log ('DONNEES : tout l''historique de {0}, mois par mois' -f (@($sets | ForEach-Object { $_.name }) -join ', ')) }
}

# Demande "data" (1.0.10, bouton "re-verifier" du rapprochement entre branches) : renvoyer maintenant les jeux demandes (sets) sur la
# periode from..to, mois par mois, avant tout le reste (prio 0) ; seuls les groupes qui different partent. Les demandes en
# direct passent toujours entre deux pages. La reponse part quand la derniere etape est faite (Complete-DataJobStep).
function Add-DataJob($Job) {
    $id = [string]$Job.id
    try {
        $from = [datetime]::ParseExact([string]$Job.from, 'yyyy-MM-dd', $Invariant)
        $to = [datetime]::ParseExact([string]$Job.to, 'yyyy-MM-dd', $Invariant)
    } catch {
        $from = $null
        $to = $null
    }
    $sets = @($Datasets | Where-Object { @($Job.sets) -contains $_.name })
    $steps = 0
    if ($from -and $to -and $from -le $to -and $sets.Count) {
        foreach ($ds in $sets) {
            if (-not $ds.date) {
                [void]$script:DataQueue.Add(@{ ds = $ds; tier = 'job'; prio = 0; job = $id; from = $null; to = $null })
                $steps++
                continue
            }
            $m = New-Object DateTime ($from.Year, $from.Month, 1)
            while ($m -le $to) {
                $end = $m.AddMonths(1).AddDays(-1)
                $a = $m
                if ($a -lt $from) { $a = $from }
                if ($end -gt $to) { $end = $to }
                [void]$script:DataQueue.Add(@{ ds = $ds; tier = 'job'; prio = 0; job = $id; from = (Format-Day $a); to = (Format-Day $end) })
                $steps++
                $m = $m.AddMonths(1)
            }
        }
    }
    if (-not $steps) {
        Invoke-Api 'POST' '/agent/result' ([ordered]@{ job_id = $Job.id; type = 'data'; error = 'invalid_range'; read_at = Get-Now }) | Out-Null
        Write-Log ("DEMANDE {0} : donnees -> periode ou jeux invalides" -f $id)
        return
    }
    $script:DataJobs[$id] = @{ left = $steps; error = $null; t0 = Get-Date }
    Write-Log ("DEMANDE {0} : renvoyer {1} du {2} au {3} ({4} etape(s))" -f $id, (@($sets | ForEach-Object { $_.name }) -join ', '), $Job.from, $Job.to, $steps)
}

# Une etape d'une demande "data" faite ($ErrorCode : elle a echoue, la demande s'arrete). La derniere repond au serveur.
function Complete-DataJobStep([string]$Id, $ErrorCode) {
    if (-not $script:DataJobs.ContainsKey($Id)) { return }
    $job = $script:DataJobs[$Id]
    if ($ErrorCode) {
        $job.error = $ErrorCode
        $job.left = 0
        $rest = @($script:DataQueue | Where-Object { $_.job -eq $Id })
        foreach ($s in $rest) { $script:DataQueue.Remove($s) }
    } else {
        $job.left = $job.left - 1
    }
    if ($job.left -gt 0) { return }
    $script:DataJobs.Remove($Id)
    try {
        Invoke-Api 'POST' '/agent/result' ([ordered]@{ job_id = [int]$Id; type = 'data'; error = $job.error; read_at = Get-Now }) | Out-Null
    } catch {
        Write-Log (Get-ErrorText $_) -IsError
    }
    $summary = 'OK'
    if ($job.error) { $summary = $job.error }
    Write-Log ("DEMANDE {0} : donnees renvoyees en {1:N0} s -> {2}" -f $Id, ((Get-Date) - $job.t0).TotalSeconds, $summary)
}

# Une tache qui echoue s'arrete 5 minutes (sa table manque : 6 heures), le serveur le note, les autres continuent.
# Une erreur de l'API n'est pas renvoyee a l'API : le serveur note lui-meme ce qu'il refuse.
function Set-DatasetBroken($Ds, $ErrorRecord) {
    $text = Get-ErrorText $ErrorRecord
    $code = 'db_error'
    $wait = 5
    $api = (Get-HttpStatus $ErrorRecord) -ne 0 -or @(Get-ExceptionChain $ErrorRecord | Where-Object {
        $_ -is [Net.Http.HttpRequestException] -or $_ -is [Threading.Tasks.TaskCanceledException] -or $_ -is [Net.WebException] }).Count
    if ($api) { $code = 'api_error' }
    elseif ($text -like '*absente ou sans*') { $code = 'missing_table'; $wait = 360 }
    # serveur d'avant 1.0.7 (pas de /agent/digest) : on reessaie dans une heure, le stock continue
    if ((Get-HttpStatus $ErrorRecord) -eq 404) { $wait = 60 }
    $script:DataBroken[$Ds.name] = (Get-Date).AddMinutes($wait)
    Write-Log ('donnees {0} : {1}' -f $Ds.name, $text) -IsError
    if ($code -ne 'api_error') {
        try { Invoke-Api 'POST' '/agent/digest' ([ordered]@{ dataset = $Ds.name; error = $code; message = $text }) | Out-Null } catch { }
    }
    $script:DataCols.Remove($Ds.name)
}

# Au plus une etape par tour de boucle, la plus urgente : les 7 derniers jours, puis les clients, puis le reste ; les
# bases archivees (1.0.11) en dernier
function Invoke-NextDataStep {
    Add-DueDataSteps
    Add-DueArchive
    $now = Get-Date
    $next = $null
    foreach ($s in $script:DataQueue) {
        # une table en erreur dans la base en cours n'arrete pas la lecture d'une archive (autre fichier)
        if ($s.tier -ne 'archive' -and $script:DataBroken.ContainsKey($s.ds.name) -and $now -lt $script:DataBroken[$s.ds.name]) { continue }
        if ($null -eq $next -or $s.prio -lt $next.prio) { $next = $s }
    }
    if ($null -eq $next) {
        # les etapes d'une table en erreur sont abandonnees : la nuit suivante les refera ; une demande "data" qui en
        # attendait repond en echec
        foreach ($id in @($script:DataJobs.Keys)) { Complete-DataJobStep $id 'data_failed' }
        $script:DataQueue.Clear()
        if ($script:HistoryRunning) {
            $script:HistoryRunning = $false
            if ($script:HistoryFull) { $script:State.history_done_at = (Get-Date).ToString('o', $Invariant) }
            $script:State.history_sets = @($Datasets | Where-Object { $_.date } | ForEach-Object { $_.name })
            Save-State
            Write-Log 'DONNEES : historique verifie'
        }
        return
    }
    $script:DataQueue.Remove($next)
    try {
        $done = Invoke-DataStep $next
        # etape coupee par sa limite de temps : l'historique, une demande "data" et une archive la refont tout de suite,
        # les autres a leur prochain passage
        if (-not $done -and ($next.tier -eq 'history' -or $next.tier -eq 'job' -or $next.tier -eq 'archive')) { [void]$script:DataQueue.Add($next) }
        elseif ($next.job) { Complete-DataJobStep $next.job $null }
        elseif ($next.tier -eq 'archive') { Complete-ArchiveStep $null }
    } catch {
        if ($next.tier -eq 'archive') {
            Complete-ArchiveStep (Get-ErrorText $_)
        } else {
            Set-DatasetBroken $next.ds $_
            if ($next.job) { Complete-DataJobStep $next.job 'data_failed' }
        }
    }
}

# -TestData : lit chaque jeu (les N derniers jours, ou toute la table) sans rien envoyer ; resultat dans outbox\
function Test-Datasets([int]$LastDays) {
    $ok = $true
    $today = Get-Today
    foreach ($ds in $Datasets) {
        try {
            $t0 = Get-Date
            $from = $null
            $to = $null
            if ($ds.date) { $from = Format-Day $today.AddDays(1 - $LastDays); $to = Format-Day $today }
            $groups = Read-Dataset $ds $from $to
            $records = 0
            $lines = 0
            foreach ($g in $groups.Keys) { $records += $groups[$g].Count; $lines += (Get-RowCount $groups[$g]) - $groups[$g].Count }
            $file = Join-Path $OutboxDir ('data-{0}.json' -f $ds.name)
            [IO.File]::WriteAllText($file, (ConvertTo-Json ([ordered]@{ dataset = $ds.name; from = $from; to = $to; digests = (Get-GroupDigests $groups); groups = $groups }) -Depth 10), $Utf8NoBom)
            $what = 'toute la table'
            if ($ds.date) { $what = "du $from au $to" }
            Write-Host ('{0,-18}: OK, {1} enregistrement(s), {2} ligne(s), {3} groupe(s) {4} en {5:N1} s -> {6}' -f $ds.name, $records, $lines, $groups.Count, $what, ((Get-Date) - $t0).TotalSeconds, $file) -ForegroundColor Green
        } catch {
            $ok = $false
            Write-Host ('{0,-18}: ERREUR {1}' -f $ds.name, (Get-ErrorText $_)) -ForegroundColor Red
        }
    }
    # l'exercice de la base (1.0.11) et les bases archivees qui partiront (sans les lire ici)
    try {
        $mark = Get-BaseMark $null
        if ($mark.opened_on) { Write-Host ('{0,-18}: Solde Initial du {1} (exercice {2}), donnees du {3} au {4}' -f 'exercice', $mark.opened_on, $mark.label, $mark.first_day, $mark.last_day) -ForegroundColor Green }
        else { Write-Host ('{0,-18}: pas de Solde Initial, donnees du {1} au {2}' -f 'exercice', $mark.first_day, $mark.last_day) -ForegroundColor Green }
    } catch {
        $ok = $false
        Write-Host ('{0,-18}: ERREUR {1}' -f 'exercice', (Get-ErrorText $_)) -ForegroundColor Red
    }
    foreach ($f in (Get-ArchiveFiles)) {
        Write-Host ('{0,-18}: {1} ({2:N0} Ko, {3:yyyy-MM-dd HH:mm})' -f 'archive', $f.Name, ($f.Length / 1KB), $f.LastWriteTime)
    }
    $ok
}

function Start-AgentLoop {
    # un verrou par fichier de config : empeche deux boucles du meme agent (tache + lancement manuel),
    # sans bloquer un autre agent du meme PC (ex : agent de test local a cote de l'agent installe)
    $sha = [Security.Cryptography.SHA1]::Create()
    $id = -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes((Resolve-Path $ConfigPath).Path.ToLowerInvariant())) |
        Select-Object -First 8 | ForEach-Object { $_.ToString('x2') })
    try { $mutex = New-Object Threading.Mutex($false, "Global\GescomAgent-$id") }
    catch { $mutex = New-Object Threading.Mutex($false, "Local\GescomAgent-$id") }
    try { $owned = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $owned = $true }
    if (-not $owned) { Write-Log 'une autre instance tourne deja, sortie.'; return }

    Write-Log ("demarrage agent {0} - branche '{1}' - base {2}" -f $AgentVersion, $cfg.branch_name, $cfg.database)
    # une copie d'archive laissee par un arret au milieu d'un envoi : elle sera refaite
    Remove-ArchiveCopies
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
                    $null = Send-FullSync
                    $nextSync = (Get-Date).AddMinutes([double]$cfg.full_sync_minutes)
                }
                if (-not $DryRun) {
                    Invoke-PendingJobs
                    # ventes, achats, clients... : une etape au plus, apres les demandes en direct
                    Invoke-NextDataStep
                    # des etapes en attente (premier envoi de l'historique) : la suivante dans 2 s, pas 10
                    if ($script:DataQueue.Count) { $sleep = 2 }
                }
            }
        } catch {
            if ((Get-HttpStatus $_) -eq 401) {
                Write-Log 'token refuse par l''API (401) : branche desactivee ou config.json incorrect' -IsError
                $sleep = 300
            } else {
                Write-Log (Get-ErrorText $_) -IsError
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
    $script:State = Read-State

    if ($TestCode) {
        $items = @(Get-StockByCode $TestCode)
        if ($items.Count) { $items | ForEach-Object { [pscustomobject]$_ } | Format-Table -AutoSize | Out-String | Write-Host }
        else { Write-Host "Code $TestCode introuvable" }
        exit 0
    }

    if ($TestData) {
        if (Test-Datasets $Days) { exit 0 } else { exit 1 }
    }

    if ($Register) {
        if (-not $BranchName -or -not $InstallCode) { throw '-BranchName et -InstallCode sont obligatoires' }
        Assert-ApiUrl
        $body = [ordered]@{ install_code = $InstallCode; branch_name = $BranchName; hostname = $env:COMPUTERNAME; agent_version = $AgentVersion }
        $r = Invoke-Api 'POST' '/agents/register' $body -NoAuth -NotIdempotent
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
            if ($script:PriceCols.Count) { Write-Host ("Prix      : {0}" -f (@($script:PriceCols | ForEach-Object { '{0} -> {1}' -f $_, $PriceColumns[$_] }) -join ', ')) }
            else { Write-Host 'Prix      : aucune colonne de prix trouvee (stock envoye sans prix)' -ForegroundColor Yellow }
            if ($script:ItemCols.Count) { Write-Host ("Fiche     : {0}" -f (@($script:ItemCols | ForEach-Object { '{0} -> {1}' -f $_, $ItemColumns[$_] }) -join ', ')) }
            $mtime = Get-DbFileTime
            if ($mtime) { Write-Host "Fichier   : derniere ecriture $mtime" }
            # ventes, achats, clients... : tables et colonnes seulement (agent.ps1 -TestData pour une vraie lecture)
            foreach ($ds in $Datasets) {
                try {
                    $c = Test-DatasetSchema $ds
                    $what = $ds.table
                    if ($ds.lines) { $what += ' + ' + $ds.lines }
                    Write-Host ('Donnees   : {0} ({1}) OK, {2} colonne(s)' -f $ds.name, $what, ($c.head.Count + $c.line.Count))
                } catch {
                    Write-Host ('Donnees   : {0} : {1} (le stock n''est pas concerne)' -f $ds.name, (Get-ErrorText $_)) -ForegroundColor Yellow
                }
            }
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
        if (Test-Schema) { $null = Send-FullSync; exit 0 } else { exit 1 }
    }
    Start-AgentLoop
} catch {
    Write-Log (Get-ErrorText $_) -IsError
    exit 1
}
