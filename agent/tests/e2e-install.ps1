<#
  Test d'installation complet de GescomAgent-Setup.exe, a lancer EN ADMINISTRATEUR sur un PC Windows avec Gescomm :

    powershell -ExecutionPolicy Bypass -File .\agent\tests\e2e-install.ps1

  Ce que fait le test, de bout en bout, avec la fenetre de l'installateur (agent\GescomAgent-Setup.exe, celui du zip) :
    - lance ce Laravel en local (php artisan serve, base SQLite temporaire) et cree un code d'installation ;
    - installe l'agent : base Gescomm lue en lecture seule, branche enregistree, tache planifiee SYSTEM creee ;
    - attend que l'agent de test envoie le stock au Laravel local ;
    - mise a jour, diagnostic, puis desinstallation (tache et dossier supprimes).
  Ne touche pas a l'agent installe : dossier C:\ProgramData\GescomAgentE2E et tache GescomAgentE2E, toujours supprimes a la fin.
  La branche de test n'existe que dans la base SQLite temporaire, jamais sur le vrai tableau de bord.
  Resultat aussi dans agent\tests\.work\e2e-result.txt. Code de sortie : nombre de tests en echec.
#>
param(
    [string]$Php,
    [int]$Port = 8765
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ui.ps1')

$AgentDir = Split-Path $PSScriptRoot -Parent
$Repo = Split-Path $AgentDir -Parent
$Work = Join-Path $PSScriptRoot '.work'
New-Item -ItemType Directory -Force -Path $Work | Out-Null
$TaskName = 'GescomAgentE2E'
$InstallDir = Join-Path $env:ProgramData 'GescomAgentE2E'
$Sqlite = Join-Path $Work 'e2e.sqlite'
$Api = "http://127.0.0.1:$Port/api"
$Exe = Join-Path $AgentDir 'GescomAgent-Setup.exe'
$Report = Join-Path ([Environment]::GetFolderPath('Desktop')) 'GescomAgent-diagnostic.txt'
$ReportBackup = Join-Path $Work 'diagnostic-avant-test.txt'

Start-Transcript -Path (Join-Path $Work 'e2e-result.txt') -Force | Out-Null
$failed = 1
$server = $null
$p = $null
try {
    $pr = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) { throw 'lancez ce test en Administrateur.' }
    if (-not $Php) {
        $cmd = Get-Command php -ErrorAction SilentlyContinue
        if ($cmd) { $Php = $cmd.Source } else { $Php = Join-Path $Repo '..\tools\php\php.exe' }
    }
    if (-not (Test-Path $Php)) { throw "php introuvable ($Php). Passez -Php <chemin de php.exe>." }
    if (Test-Path (Join-Path $Repo 'bootstrap\cache\config.php')) { throw 'configuration Laravel en cache : php artisan config:clear d''abord.' }

    # restes d'un test interrompu (toujours les notres : nom de tache et dossier propres au test)
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false }
    if (Test-Path $InstallDir) { Remove-Item $InstallDir -Recurse -Force }

    # l'agent reellement installe ne doit pas bouger
    $realConfig = Join-Path $env:ProgramData 'GescomAgent\config.json'
    $realBefore = $null
    if (Test-Path $realConfig) { $realBefore = (Get-FileHash $realConfig).Hash }
    $realTask = Get-ScheduledTask -TaskName 'GescomAgent' -ErrorAction SilentlyContinue

    Write-Host '== Laravel local'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $Sqlite
    if (Test-Path $Sqlite) { Remove-Item $Sqlite -Force }
    New-Item -ItemType File -Path $Sqlite | Out-Null
    Push-Location $Repo
    try {
        & $Php artisan migrate --force --no-interaction | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'php artisan migrate a echoue' }
        $codeOut = (& $Php artisan agent:install-code) -join "`n"
        $installCode = [regex]::Match($codeOut, '[A-Z0-9]{3}-[A-Z0-9]{3}-[A-Z0-9]{3}').Value
        if (-not $installCode) { throw "pas de code dans : $codeOut" }
    } finally { Pop-Location }
    $server = Start-Process $Php -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', "--port=$Port" -WorkingDirectory $Repo -PassThru -WindowStyle Hidden
    $deadline = (Get-Date).AddSeconds(30)
    while ($true) {
        try { if ((Invoke-WebRequest "http://127.0.0.1:$Port/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200) { break } } catch { }
        if ((Get-Date) -gt $deadline) { throw 'le Laravel local ne repond pas' }
        Start-Sleep -Milliseconds 500
    }
    Write-Host "  $Api, code $installCode"

    # requete SQL sur la base temporaire, par PHP (pas de sqlite3.exe sur Windows)
    $queryPhp = Join-Path $Work 'e2e-query.php'
    [IO.File]::WriteAllText($queryPhp, '<?php $db = new PDO("sqlite:" . $argv[1]); echo json_encode($db->query($argv[2])->fetchAll(PDO::FETCH_ASSOC));')
    function Invoke-Sql([string]$Sql) { (& $Php $queryPhp $Sqlite $Sql) | ConvertFrom-Json }

    # demarrage, retour de veille / demarrage rapide, toutes les 5 minutes sans fin (1.0.9)
    function Assert-AgentTriggers($Task) {
        $kinds = @($Task.Triggers | ForEach-Object { $_.CimClass.CimClassName }) -join ','
        Assert ($kinds -eq 'MSFT_TaskBootTrigger,MSFT_TaskEventTrigger,MSFT_TaskTimeTrigger') "declencheurs : $kinds"
        Assert ($Task.Triggers[1].Subscription -like '*Microsoft-Windows-Power-Troubleshooter*') "retour de veille : $($Task.Triggers[1].Subscription)"
        $rep = $Task.Triggers[2].Repetition
        Assert ($rep.Interval -eq 'PT5M' -and -not $rep.Duration) "surveillance : toutes les $($rep.Interval) pendant '$($rep.Duration)'"
    }

    $branchName = 'Test E2E ' + (Get-Date -Format 'HHmmss')
    Write-Host "`n== Installation par la fenetre"
    $p = Start-Setup $Exe "/lang:fr /dir:`"$InstallDir`" /task:$TaskName /api:$Api"

    Test-Case 'installation complete (6 etapes)' {
        [void](Wait-Element $script:p '^Installer$')
        Set-Field $script:p 'Nom de la succursale' $branchName
        Set-Field $script:p 'Code d''installation' $installCode
        Click-Button $script:p 'Installer'
        [void](Wait-Element $script:p '^Installation termin.e' 240)
        [void](Wait-Element $script:p '^L''agent est install. sur ce PC' 30)
    }
    Test-Case 'tache planifiee SYSTEM qui lance l''agent du dossier de test' {
        $t = Get-ScheduledTask -TaskName $TaskName
        Assert ($t.Principal.UserId -match 'SYSTEM|S-1-5-18') "compte : $($t.Principal.UserId)"
        Assert ($t.Actions[0].Arguments -like "*$InstallDir\agent.ps1*") "arguments : $($t.Actions[0].Arguments)"
        Assert-AgentTriggers $t
        Assert ($t.Settings.MultipleInstances -eq 'IgnoreNew') "instances multiples : $($t.Settings.MultipleInstances)"
    }
    Test-Case 'dossier reserve a SYSTEM et aux Administrateurs, config avec token' {
        $acl = Get-Acl $InstallDir
        Assert $acl.AreAccessRulesProtected 'heritage des droits non coupe'
        $sids = @($acl.Access | ForEach-Object { $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } | Sort-Object -Unique)
        Assert (($sids -join ',') -eq 'S-1-5-18,S-1-5-32-544') "droits : $($sids -join ', ')"
        $cfg = Get-Content (Join-Path $InstallDir 'config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        Assert (([string]$cfg.token).Length -gt 20) 'token absent'
        Assert ($cfg.api_url -eq $Api) "api_url : $($cfg.api_url)"
        Assert ($cfg.database -like 'localhost:*LABASEN.IB') "database : $($cfg.database)"
    }
    Test-Case 'le tableau de bord local recoit le stock envoye par l''agent' {
        $deadline = (Get-Date).AddSeconds(180)
        do {
            Start-Sleep -Seconds 5
            $rows = @(Invoke-Sql 'select b.name, b.hostname, b.agent_version, (select count(*) from branch_stock s where s.branch_id = b.id) as items from branches b')
        } while ((-not $rows.Count -or $rows[0].items -eq 0) -and (Get-Date) -lt $deadline)
        Assert ($rows.Count -eq 1) "$($rows.Count) branche(s)"
        Assert ($rows[0].name -eq $branchName) "nom : $($rows[0].name)"
        Assert ($rows[0].hostname -eq $env:COMPUTERNAME) "machine : $($rows[0].hostname)"
        Assert ([int]$rows[0].items -gt 0) 'aucun article recu en 3 minutes (voir le journal dans le dossier de test)'
        Write-Host "        $($rows[0].items) articles recus, agent $($rows[0].agent_version)"
    }
    Test-Case 'agent 1.0.7 : les ventes, achats et clients arrivent, sans erreur de tache' {
        $deadline = (Get-Date).AddSeconds(240)
        do {
            Start-Sleep -Seconds 10
            $counts = @(Invoke-Sql "select (select count(*) from documents where kind = 'sale') as sales, (select count(*) from documents where kind = 'purchase') as purchases, (select count(*) from partners where kind = 'customer') as customers, (select count(*) from document_lines) as lines")
        } while (([int]$counts[0].sales -eq 0 -or [int]$counts[0].customers -eq 0) -and (Get-Date) -lt $deadline)
        Assert ([int]$counts[0].customers -gt 0) 'aucun client recu en 4 minutes (voir le journal dans le dossier de test)'
        Assert ([int]$counts[0].sales -gt 0) 'aucun bon de vente recu en 4 minutes'
        $failing = @(Invoke-Sql 'select task, last_error from branch_tasks where last_error_at is not null and (last_ok_at is null or last_error_at > last_ok_at)')
        Assert ($failing.Count -eq 0) ('taches en erreur : ' + (@($failing | ForEach-Object { "$($_.task) ($($_.last_error))" }) -join ', '))
        Write-Host "        $($counts[0].sales) bons de vente, $($counts[0].purchases) d'achat, $($counts[0].lines) lignes, $($counts[0].customers) clients"
    }
    Test-Case 'mise a jour de l''agent : la tache d''une ancienne installation est reecrite' {
        # comme une branche installee avant 1.0.9 : un seul declencheur de demarrage
        [void](Set-ScheduledTask -TaskName $TaskName -Trigger (New-ScheduledTaskTrigger -AtStartup))
        Assert (@((Get-ScheduledTask -TaskName $TaskName).Triggers).Count -eq 1) 'ancienne tache non simulee'
        Click-Element (Wait-Element $script:p '^Mettre . jour l''agent$')
        [void](Wait-Element $script:p '^Agent mis . jour' 60)
        $deadline = (Get-Date).AddSeconds(20)
        while ((Get-ScheduledTask -TaskName $TaskName).State -ne 'Running' -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }
        $t = Get-ScheduledTask -TaskName $TaskName
        Assert ($t.State -eq 'Running') 'la tache ne tourne pas apres la mise a jour'
        Assert-AgentTriggers $t
        Assert ($t.Principal.UserId -match 'SYSTEM|S-1-5-18') "compte apres la mise a jour : $($t.Principal.UserId)"
    }
    Test-Case 'diagnostic : rapport sur le Bureau, sans token ni mot de passe' {
        if (Test-Path $Report) { Move-Item $Report $ReportBackup -Force }
        Click-Button $script:p 'Diagnostic'
        [void](Wait-Element $script:p '^Rapport enregistr' 120)
        $text = Get-Content $Report -Raw -Encoding UTF8
        $cfg = Get-Content (Join-Path $InstallDir 'config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        Assert ($text -match "Tache planifiee $TaskName") 'section tache absente'
        Assert ($text -match 'token=present') 'token non signale'
        Assert (-not $text.Contains([string]$cfg.token)) 'le token apparait dans le rapport'
        Assert (-not $text.Contains('masterkey')) 'le mot de passe apparait dans le rapport'
    }
    Test-Case 'desinstallation : tache et dossier supprimes' {
        Click-Button $script:p ('D' + [char]0xE9 + 'sinstaller')
        # boite de confirmation : bouton Oui = identifiant 6 (IDYES), quelle que soit la langue de Windows
        $deadline = (Get-Date).AddSeconds(15)
        $yes = $null
        while (-not $yes -and (Get-Date) -lt $deadline) {
            $pidCond = New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ProcessIdProperty, $script:p.Id)
            foreach ($w in [Windows.Automation.AutomationElement]::RootElement.FindAll([Windows.Automation.TreeScope]::Children, $pidCond)) {
                $idCond = New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::AutomationIdProperty, '6')
                $b = $w.FindFirst([Windows.Automation.TreeScope]::Descendants, $idCond)
                if ($b) { $yes = $b }
            }
            Start-Sleep -Milliseconds 300
        }
        Assert ($null -ne $yes) 'boite de confirmation introuvable'
        Click-Element $yes
        [void](Wait-Element $script:p '^Agent d.sinstall' 90)
        Assert (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) 'la tache existe encore'
        Assert (-not (Test-Path $InstallDir)) 'le dossier existe encore'
        [void](Wait-Element $script:p '^Installer$' 20)
    }
    Test-Case 'l''agent reellement installe sur ce PC n''a pas change' {
        if ($realBefore) { Assert ((Get-FileHash $realConfig).Hash -eq $realBefore) 'config.json de l''agent installe modifie' }
        $after = Get-ScheduledTask -TaskName 'GescomAgent' -ErrorAction SilentlyContinue
        Assert (($null -eq $realTask) -eq ($null -eq $after)) 'tache GescomAgent creee ou supprimee'
    }
    $failed = Write-Summary
} catch {
    Write-Host "ERREUR : $($_.Exception.Message)" -ForegroundColor Red
} finally {
    Stop-Setup $p
    if ($server) { & taskkill.exe /PID $server.Id /T /F 2>&1 | Out-Null }
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    }
    if (Test-Path $InstallDir) { Remove-Item $InstallDir -Recurse -Force -ErrorAction SilentlyContinue }
    if (Test-Path $ReportBackup) { Move-Item $ReportBackup $Report -Force }
    elseif (Test-Path $Report) { Remove-Item $Report -Force }
    Remove-Item $Sqlite -ErrorAction SilentlyContinue
    Stop-Transcript | Out-Null
}
exit $failed
