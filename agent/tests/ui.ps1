<#
  Outils communs aux tests de GescomAgent-Setup.exe (charge par run-tests.ps1 et e2e-install.ps1 avec ". .\ui.ps1").
  La fenetre est pilotee par UI Automation (lecture des textes) et par messages Windows (saisie, clics) :
  les controles WinForms n'exposent ni ValuePattern ni InvokePattern fiables sous .NET Framework.
#>
Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class GaWin32 {
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern IntPtr SendMessage(IntPtr h, uint msg, IntPtr w, string l);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr h, uint msg, IntPtr w, IntPtr l);
    [DllImport("user32.dll")] public static extern bool IsWindowEnabled(IntPtr h);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr h);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetClassName(IntPtr h, System.Text.StringBuilder s, int max);
    public static string ClassOf(IntPtr h) { var s = new System.Text.StringBuilder(256); GetClassName(h, s, 256); return s.ToString(); }
}
'@

$script:Results = New-Object System.Collections.Generic.List[object]

# Ces scripts restent en ASCII (PowerShell 5.1 lit un fichier sans BOM en ANSI) : texte non ASCII ecrit en \uXXXX
function U([string]$Escaped) { [regex]::Unescape($Escaped) }
$OkMark = U '\u2713'
$NoMark = U '\u2717'

function Test-Case([string]$Name, [scriptblock]$Body) {
    try {
        & $Body
        $script:Results.Add([pscustomobject]@{ Test = $Name; Result = 'OK'; Detail = '' })
        Write-Host "  OK    $Name" -ForegroundColor Green
    } catch {
        $script:Results.Add([pscustomobject]@{ Test = $Name; Result = 'ECHEC'; Detail = $_.Exception.Message })
        Write-Host "  ECHEC $Name : $($_.Exception.Message)" -ForegroundColor Red
    }
}

function Skip-Case([string]$Name, [string]$Why) {
    $script:Results.Add([pscustomobject]@{ Test = $Name; Result = 'saute'; Detail = $Why })
    Write-Host "  saute $Name ($Why)" -ForegroundColor Yellow
}

function Assert([bool]$Condition, [string]$Message) { if (-not $Condition) { throw $Message } }

function Write-Summary {
    $failed = @($script:Results | Where-Object { $_.Result -eq 'ECHEC' }).Count
    $ok = @($script:Results | Where-Object { $_.Result -eq 'OK' }).Count
    $skipped = @($script:Results | Where-Object { $_.Result -eq 'saute' }).Count
    Write-Host ''
    $color = 'Green'; if ($failed) { $color = 'Red' }
    Write-Host ("{0} OK, {1} echec(s), {2} saute(s)" -f $ok, $failed, $skipped) -ForegroundColor $color
    return $failed
}

# ---------- fenetre de l'installateur ----------

function Start-Setup([string]$Exe, [string]$Arguments) {
    $p = Start-Process -FilePath $Exe -ArgumentList $Arguments -PassThru
    $deadline = (Get-Date).AddSeconds(30)
    while ($p.MainWindowHandle -eq 0 -and -not $p.HasExited -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 200; $p.Refresh() }
    if ($p.MainWindowHandle -eq 0) { throw "la fenetre de $Exe ne s'ouvre pas" }
    return $p
}

function Stop-Setup($Process) {
    if ($Process -and -not $Process.HasExited) { $Process.Kill(); $Process.WaitForExit(5000) | Out-Null }
}

function Get-Root($Process) {
    # juste apres une compilation, l'antivirus (AVG) instrumente le premier lancement : UI Automation peut echouer un moment
    for ($i = 1; ; $i++) {
        try { return [Windows.Automation.AutomationElement]::FromHandle($Process.MainWindowHandle) }
        catch { if ($i -ge 20) { throw }; Start-Sleep -Milliseconds 500; $Process.Refresh() }
    }
}

function Get-Elements($Process) {
    (Get-Root $Process).FindAll([Windows.Automation.TreeScope]::Descendants, [Windows.Automation.Condition]::TrueCondition)
}

# Premier element dont le nom (texte du libelle, du bouton, ou nom accessible d'un champ) correspond au motif
function Find-Element($Process, [string]$Pattern) {
    foreach ($e in Get-Elements $Process) {
        try { if ($e.Current.Name -match $Pattern) { return $e } } catch { }
    }
    return $null
}

function Wait-Element($Process, [string]$Pattern, [int]$Seconds = 20) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    do {
        $e = Find-Element $Process $Pattern
        if ($e) { return $e }
        Start-Sleep -Milliseconds 300
    } while ((Get-Date) -lt $deadline)
    $names = @(Get-Elements $Process | ForEach-Object { $_.Current.Name } | Where-Object { $_ }) -join ' | '
    throw "introuvable apres $Seconds s : /$Pattern/. Textes de la fenetre : $names"
}

function Get-Hwnd($Element) { [IntPtr]$Element.Current.NativeWindowHandle }

# Champ de saisie sur la meme ligne que son libelle (les zones de texte WinForms n'exposent que leur contenu)
function Find-Field($Process, [string]$Label) {
    $lbl = Wait-Element $Process ('^' + [regex]::Escape($Label) + '$') 10
    $r = $lbl.Current.BoundingRectangle
    $middle = $r.Top + $r.Height / 2
    foreach ($e in Get-Elements $Process) {
        $h = Get-Hwnd $e
        if ($h -eq [IntPtr]::Zero -or [GaWin32]::ClassOf($h) -notmatch '\.EDIT\.') { continue }
        $b = $e.Current.BoundingRectangle
        if ($middle -ge $b.Top -and $middle -le $b.Bottom) { return $e }
    }
    throw "aucun champ sur la ligne de '$Label'"
}

function Set-Field($Process, [string]$Label, [string]$Value) {
    [void][GaWin32]::SendMessage((Get-Hwnd (Find-Field $Process $Label)), 0x000C, [IntPtr]::Zero, $Value)   # WM_SETTEXT
}

# PostMessage : un clic qui ouvre une boite de dialogue ne bloque pas le test
function Click-Element($Element) {
    [void][GaWin32]::PostMessage((Get-Hwnd $Element), 0x00F5, [IntPtr]::Zero, [IntPtr]::Zero)   # BM_CLICK
}

function Click-Button($Process, [string]$Text) {
    Click-Element (Wait-Element $Process ('^' + [regex]::Escape($Text) + '$') 10)
}

function Test-Enabled($Element) { [GaWin32]::IsWindowEnabled((Get-Hwnd $Element)) }
