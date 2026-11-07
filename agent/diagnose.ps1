<#
  Diagnostic de GescomAgent (lecture seule : ne modifie rien). A lancer en Administrateur :

    powershell -ExecutionPolicy Bypass -File .\diagnose.ps1

  Affiche l'etat de l'agent installe et ecrit le meme rapport sur le Bureau :
  GescomAgent-diagnostic.txt (jamais le token ni le mot de passe de la base).
#>
$ErrorActionPreference = 'Continue'
$Dir = Join-Path $env:ProgramData 'GescomAgent'
$Lines = New-Object System.Collections.Generic.List[string]
function Out-Line([string]$Text) { $Lines.Add($Text); Write-Host $Text }

Out-Line ('== Diagnostic GescomAgent {0:yyyy-MM-dd HH:mm:ss} sur {1}' -f (Get-Date), $env:COMPUTERNAME)
$pr = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin = $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
if (-not $isAdmin) {
    Out-Line 'ATTENTION : pas en Administrateur : fichiers, tache et journal non lisibles. Relancez en Administrateur.'
}

Out-Line "`n== Fichiers ($Dir)"
if (-not $isAdmin) { Out-Line '(ignore : pas en Administrateur)' } else {
$agentPath = Join-Path $Dir 'agent.ps1'
if (Test-Path $agentPath) {
    $v = Select-String -Path $agentPath -Pattern "AgentVersion = '([^']+)'" | Select-Object -First 1
    Out-Line ('agent.ps1   : present, version {0}' -f $v.Matches[0].Groups[1].Value)
} else {
    Out-Line 'agent.ps1   : ABSENT (supprime ou mis en quarantaine par l''antivirus ?)'
}
$cfgPath = Join-Path $Dir 'config.json'
if (Test-Path $cfgPath) {
    $cfg = Get-Content $cfgPath -Raw -Encoding UTF8 | ConvertFrom-Json
    Out-Line ('config.json : api_url={0} | branche={1} | base={2} | token={3}' -f $cfg.api_url, $cfg.branch_name, $cfg.database, $(if ($cfg.token) { 'present' } else { 'ABSENT' }))
} else {
    Out-Line 'config.json : ABSENT'
}

Out-Line "`n== Tache planifiee GescomAgent"
$task = Get-ScheduledTask -TaskName GescomAgent -ErrorAction SilentlyContinue
if ($task) {
    $info = $task | Get-ScheduledTaskInfo
    Out-Line ('etat={0} | dernier lancement={1} | resultat=0x{2:X} | prochain={3}' -f $task.State, $info.LastRunTime, $info.LastTaskResult, $info.NextRunTime)
    # les declencheurs, comme le diagnostic de l'exe : montre si la branche a ceux de 1.0.9
    $names = @{ Boot = 'demarrage'; Event = 'evenement'; Time = 'heure'; Daily = 'quotidien' }
    $resume = $false
    $endless = $false
    $parts = @(foreach ($t in @($task.Triggers)) {
        $kind = $t.CimClass.CimClassName -replace '^MSFT_Task', '' -replace 'Trigger$', ''
        $name = if ($names[$kind]) { $names[$kind] } else { $kind }
        if ($kind -eq 'Event' -and $t.Subscription -like '*Power-Troubleshooter*') { $name = 'retour de veille'; $resume = $true }
        if ($t.Repetition -and $t.Repetition.Interval) {
            if ($t.Repetition.Duration) { $name += " toutes les $($t.Repetition.Interval) pendant $($t.Repetition.Duration)" }
            else { $name += " toutes les $($t.Repetition.Interval) sans fin"; $endless = $true }
        }
        $name
    })
    $line = 'declencheurs : ' + ($parts -join ', ')
    if (-not ($resume -and $endless)) { $line += ' -> ANCIENNE TACHE : GescomAgent-Setup.exe > Mettre a jour l''agent' }
    Out-Line $line
} else {
    Out-Line 'tache ABSENTE'
}
# avec le demarrage rapide, "Arreter" met Windows en veille prolongee : le declencheur "retour de veille" relance l'agent
$hiberboot = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -ErrorAction SilentlyContinue).HiberbootEnabled
Out-Line ('demarrage rapide de Windows : {0}' -f $(if ($null -eq $hiberboot) { 'inconnu' } elseif ($hiberboot -eq 1) { 'actif (arreter le PC = veille prolongee)' } else { 'inactif' }))

Out-Line "`n== Processus de l'agent"
$procs = @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -match 'GescomAgent\\agent\.ps1' })
if ($procs.Count) {
    foreach ($p in $procs) { Out-Line ('pid {0} demarre {1}' -f $p.ProcessId, $p.CreationDate) }
} else {
    Out-Line 'aucun processus agent en cours'
}

Out-Line "`n== Journal (30 dernieres lignes)"
$log = Get-ChildItem (Join-Path $Dir 'logs') -Filter 'agent-*.log' -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
if ($log) {
    Out-Line ('fichier : {0}' -f $log.FullName)
    Get-Content $log.FullName -Tail 30 -Encoding UTF8 | ForEach-Object { Out-Line $_ }
} else {
    Out-Line 'aucun journal'
}
}   # fin des verifications reservees a l'Administrateur

Out-Line "`n== Connexion vers l'API"
$apiHost = 'stockallal.souftech.com'
if ($cfg -and $cfg.api_url) { $apiHost = ([Uri]$cfg.api_url).Host }
$fail = 0
for ($i = 1; $i -le 10; $i++) {
    $c = New-Object Net.Sockets.TcpClient
    $t = $c.ConnectAsync($apiHost, 443)
    if (-not $t.Wait(4000) -or -not $c.Connected) { $fail++ }
    $c.Close()
}
Out-Line ('TCP {0}:443 : {1} echecs sur 10' -f $apiHost, $fail)
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
for ($i = 1; $i -le 3; $i++) {
    try {
        $r = Invoke-WebRequest "https://$apiHost/up" -UseBasicParsing -TimeoutSec 30
        Out-Line ('HTTPS /up essai {0} : {1}' -f $i, $r.StatusCode)
    } catch {
        Out-Line ('HTTPS /up essai {0} : ERREUR {1}' -f $i, $_.Exception.Message)
    }
}

Out-Line "`n== Antivirus"
Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntiVirusProduct -ErrorAction SilentlyContinue |
    ForEach-Object { Out-Line $_.displayName }

$out = Join-Path ([Environment]::GetFolderPath('Desktop')) 'GescomAgent-diagnostic.txt'
[IO.File]::WriteAllLines($out, [string[]]$Lines, (New-Object Text.UTF8Encoding($true)))
Write-Host "`nRapport ecrit : $out" -ForegroundColor Green
