# test-small-hooks.ps1 - behaviour tests for turn-timer, play-sound, prune-session-temp, phpcs-wp-native, css-order,
# guard-console-window and guard-rules-style.
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-small-hooks.ps1 [-Measure]
# Each case arranges a fresh fixture, runs the .ps1 and then the .mjs on the same payload, and requires the same exit
# code, stdout, stderr and observed side effect (file written, folders left, sound process started). While the .ps1 sits
# beside the .mjs it is the oracle; once it is removed only the expected exit code of a case is checked.
# css-order: the .ps1 is a relay, the node side is css-order.mjs called directly with "--hook --fix".
# Payloads and fixtures live in hooks\tests\.payloads\small ({{SID}} and {{FILE}} are filled in per case); the scripts
# run from a mirror of the plugin tree in a temp folder, with a fake home, a fake phpcs and a silent sound.
# -Measure also times 10 calls of each side.
param([switch]$Measure)

$ErrorActionPreference = 'Stop'
$scripts = Join-Path (Split-Path $PSScriptRoot) 'scripts'
$small = Join-Path $PSScriptRoot '.payloads\small'
$token = "claude-small-hooks-$PID"
$work = Join-Path $env:TEMP $token
$mirror = Join-Path $work 'plugin'
$fakeHome = Join-Path $work 'home'
$fakeAppData = Join-Path $work 'appdata'
$mirrorScripts = Join-Path $mirror 'hooks\scripts'
Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
foreach ($d in @($mirrorScripts, (Join-Path $mirror 'sounds'), (Join-Path $mirror 'references'), $fakeHome, (Join-Path $fakeAppData 'Composer\vendor\bin'))) {
    New-Item -ItemType Directory -Force -Path $d | Out-Null
}
Copy-Item -Path (Join-Path $scripts '*') -Destination $mirrorScripts -Recurse -Force
Set-Content -LiteralPath (Join-Path $mirror 'phpcs-wp-native.xml') -Value '<ruleset name="stub"/>'
Copy-Item -LiteralPath (Join-Path $small 'fixtures\fake-phpcs.fixture') -Destination (Join-Path $fakeAppData 'Composer\vendor\bin\phpcs')

# Three seconds of silence: the detached player has time to be seen, and nothing is heard.
$wav = Join-Path $mirror 'sounds\notify.wav'
$pcm = New-Object byte[] 24000
for ($i = 0; $i -lt $pcm.Length; $i++) { $pcm[$i] = 128 }
$ms = New-Object System.IO.MemoryStream
$w = New-Object System.IO.BinaryWriter $ms
$w.Write([Text.Encoding]::ASCII.GetBytes('RIFF')); $w.Write([int](36 + $pcm.Length)); $w.Write([Text.Encoding]::ASCII.GetBytes('WAVEfmt '))
$w.Write([int]16); $w.Write([int16]1); $w.Write([int16]1); $w.Write([int]8000); $w.Write([int]8000); $w.Write([int16]1); $w.Write([int16]8)
$w.Write([Text.Encoding]::ASCII.GetBytes('data')); $w.Write([int]$pcm.Length); $w.Write($pcm); $w.Flush()
[IO.File]::WriteAllBytes($wav, $ms.ToArray())

$childEnv = @{ USERPROFILE = $fakeHome; APPDATA = $fakeAppData }

function Invoke-Proc([string]$exe, [string[]]$arguments, [string]$stdin) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo $exe
    $psi.Arguments = ($arguments | ForEach-Object { '"' + $_ + '"' }) -join ' '
    $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8; $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    foreach ($k in $childEnv.Keys) { $psi.EnvironmentVariables[$k] = $childEnv[$k] }
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $p = [System.Diagnostics.Process]::Start($psi)
    $bytes = (New-Object System.Text.UTF8Encoding $false).GetBytes($stdin)
    $p.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length); $p.StandardInput.Close()
    $err = $p.StandardError.ReadToEndAsync(); $out = $p.StandardOutput.ReadToEnd(); $p.WaitForExit()
    return @{ code = $p.ExitCode; out = $out; err = ($err.Result -replace "`r`n", "`n"); ms = $clock.ElapsedMilliseconds }
}

# What each side runs. css-order: the .ps1 relays, the node side is the engine itself.
$commands = @{
    'turn-timer'         = @{ ps1 = @('turn-timer.ps1'); mjs = @('turn-timer.mjs') }
    'play-sound'         = @{ ps1 = @('play-sound.ps1'); mjs = @('play-sound.mjs') }
    'prune-session-temp' = @{ ps1 = @('prune-session-temp.ps1'); mjs = @('prune-session-temp.mjs') }
    'phpcs-wp-native'    = @{ ps1 = @('phpcs-wp-native.ps1'); mjs = @('phpcs-wp-native.mjs') }
    'css-order'          = @{ ps1 = @('css-order.ps1'); mjs = @('css-order.mjs', '--hook', '--fix') }
    'guard-console-window' = @{ ps1 = @('guard-console-window.ps1'); mjs = @('guard-console-window.mjs') }
    'guard-rules-style'  = @{ ps1 = @('guard-rules-style.ps1'); mjs = @('guard-rules-style.mjs') }
}

function Invoke-Impl([string]$impl, [string]$hook, [string[]]$extra, [string]$stdin) {
    $cmd = $commands[$hook][$impl]
    $first = Join-Path $mirrorScripts $cmd[0]
    $rest = @($cmd | Select-Object -Skip 1) + $extra
    if ($impl -eq 'ps1') { return Invoke-Proc 'powershell.exe' (@('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $first) + $rest) $stdin }
    return Invoke-Proc 'node' (@($first) + $rest) $stdin
}

# Payload template with {{NAME}} placeholders, values JSON-escaped.
function Get-Payload([string]$name, [hashtable]$values = @{}) {
    $text = [IO.File]::ReadAllText((Join-Path $small $name))
    foreach ($k in $values.Keys) { $text = $text.Replace("{{$k}}", ([string]$values[$k]).Replace('\', '\\')) }
    return $text
}

$script:n = 0; $script:ko = 0
# $arrange runs before each side and returns @{ payload = ...; extra = ...; ctx = ... }; $observe sees that context
# after the run and returns the side effect as a string; $code is the exit code both sides must give.
function Test-Case([string]$label, [string]$hook, [int]$code, [scriptblock]$arrange, [scriptblock]$observe = { '' }) {
    $script:n++
    $results = @{}
    $legacyPresent = Test-Path -LiteralPath (Join-Path $mirrorScripts $commands[$hook]['ps1'][0])
    $impls = if ($legacyPresent) { @('ps1', 'mjs') } else { @('mjs') }
    foreach ($impl in $impls) {
        $a = @(& $arrange)[-1]
        $extra = if ($a.extra) { [string[]]$a.extra } else { [string[]]@() }
        $r = Invoke-Impl $impl $hook $extra ([string]$a.payload)
        $r.seen = [string](& $observe $a.ctx)
        $results[$impl] = $r
    }
    $m = $results['mjs']
    $why = if ($m.code -ne $code) { "exit $($m.code), expected $code ($($m.err))" }
    if (-not $why -and $legacyPresent) {
        $l = $results['ps1']
        foreach ($field in @('code', 'out', 'err', 'seen')) {
            if ($l[$field] -ne $m[$field]) { $why = "$field differs: .ps1 [$($l[$field])] vs .mjs [$($m[$field])]"; break }
        }
    }
    $note = if ($m.seen) { " [$($m.seen)]" } else { '' }
    if (-not $why) { Write-Host "  OK   $hook : $label$note" } else { $script:ko++; Write-Host "  KO   $hook : $label ($why)" }
}

function New-Dir([string]$path) { New-Item -ItemType Directory -Force -Path $path | Out-Null }
function Set-Age([string]$path, [double]$days) {
    $when = (Get-Date).AddDays(-$days)
    foreach ($i in @(Get-ChildItem -LiteralPath $path -Recurse -Force | Sort-Object { $_.FullName.Length } -Descending)) { $i.LastWriteTime = $when }
    (Get-Item -LiteralPath $path -Force).LastWriteTime = $when
}

# ---------------------------------------------------------------------------------------------------- turn-timer
$timing = Join-Path $fakeHome '.claude\.turn-timing'
function Clear-Timing { Remove-Item -LiteralPath $timing -Recurse -Force -ErrorAction SilentlyContinue }
function Read-Marker([string]$sid) {
    $f = Join-Path $timing "$sid.txt"
    if (-not (Test-Path -LiteralPath $f)) { return 'absent' }
    $ticks = [long][IO.File]::ReadAllText($f)
    $gap = ([DateTime]::UtcNow.Ticks - $ticks) / 10000000.0
    if ($gap -ge 0 -and $gap -lt 30) { return 'fresh' }
    return "odd($ticks)"
}
Write-Host 'turn-timer'
Test-Case 'session id names the marker' 'turn-timer' 0 { Clear-Timing; @{ payload = (Get-Payload 'session.json' @{ SID = 'sid-a' }) } } { Read-Marker 'sid-a' }
Test-Case 'no session id: default marker' 'turn-timer' 0 { Clear-Timing; @{ payload = (Get-Payload 'no-session.json') } } { Read-Marker 'default' }
Test-Case 'empty stdin: default marker' 'turn-timer' 0 { Clear-Timing; @{ payload = '' } } { Read-Marker 'default' }
Test-Case 'a second turn overwrites the marker' 'turn-timer' 0 {
    Clear-Timing; New-Dir $timing; [IO.File]::WriteAllText((Join-Path $timing 'sid-b.txt'), '1')
    @{ payload = (Get-Payload 'session.json' @{ SID = 'sid-b' }) } } { Read-Marker 'sid-b' }

# ---------------------------------------------------------------------------------------------------- play-sound
function Wait-NoSound {
    for ($i = 0; $i -lt 60; $i++) {
        if (-not @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -like "*SoundPlayer*$token*" }).Count) { return }
        Start-Sleep -Milliseconds 250
    }
}
# True when a detached player of the mirror's sound shows up after the hook has returned.
function Test-SoundStarted {
    $deadline = (Get-Date).AddMilliseconds(1800)
    do {
        if (@(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -like "*SoundPlayer*$token*" }).Count) { return $true }
    } while ((Get-Date) -lt $deadline)
    return $false
}
function Set-Marker([string]$sid, [string]$text) { New-Dir $timing; [IO.File]::WriteAllText((Join-Path $timing "$sid.txt"), $text) }
function Get-SoundState([string]$sid) { "sound=$(Test-SoundStarted) marker=$(if (Test-Path -LiteralPath (Join-Path $timing "$sid.txt")) { 'kept' } else { 'gone' })" }
function New-SoundCase([string]$sid, [string]$marker) {
    Wait-NoSound; Clear-Timing; if (-not (Test-Path -LiteralPath $wav)) { [IO.File]::WriteAllBytes($wav, $ms.ToArray()) }
    if ($marker) { Set-Marker $sid $marker }
    @{ payload = (Get-Payload 'session.json' @{ SID = $sid }) }
}
$ago = { param([int]$sec) [string]([DateTime]::UtcNow.Ticks - [long]$sec * 10000000) }
Write-Host 'play-sound'
Test-Case 'always: rings, detached and already returned' 'play-sound' 0 { $c = New-SoundCase 'snd' ''; $c.extra = @('always'); $c } { Get-SoundState 'snd' }
Test-Case 'no mode: rings like always' 'play-sound' 0 { New-SoundCase 'snd' '' } { Get-SoundState 'snd' }
Test-Case 'stop without marker: silent' 'play-sound' 0 { $c = New-SoundCase 'snd' ''; $c.extra = @('stop'); $c } { Get-SoundState 'snd' }
Test-Case 'stop after 20 s: rings, marker consumed' 'play-sound' 0 { $c = New-SoundCase 'snd' (& $ago 20); $c.extra = @('stop'); $c } { Get-SoundState 'snd' }
Test-Case 'STOP in capitals after 20 s: rings' 'play-sound' 0 { $c = New-SoundCase 'snd' (& $ago 20); $c.extra = @('STOP'); $c } { Get-SoundState 'snd' }
Test-Case 'stop after 3 s: silent, marker consumed' 'play-sound' 0 { $c = New-SoundCase 'snd' (& $ago 3); $c.extra = @('stop'); $c } { Get-SoundState 'snd' }
Test-Case 'stop with an unreadable marker: silent, marker kept' 'play-sound' 0 { $c = New-SoundCase 'snd' 'garbage'; $c.extra = @('stop'); $c } { Get-SoundState 'snd' }
Test-Case 'stop, other session marker untouched' 'play-sound' 0 {
    Wait-NoSound; Clear-Timing; Set-Marker 'other' (& $ago 20)
    @{ payload = (Get-Payload 'session.json' @{ SID = 'snd' }); extra = @('stop') } } { Get-SoundState 'other' }
Test-Case 'sound file missing: silent' 'play-sound' 0 { $c = New-SoundCase 'snd' ''; Remove-Item -LiteralPath $wav; $c } { Get-SoundState 'snd' }
Wait-NoSound
[IO.File]::WriteAllBytes($wav, $ms.ToArray())

# ---------------------------------------------------------------------------------------------------- prune-session-temp
$pruneRoot = Join-Path $work 'prune-root'
$uuids = @{ old = '11111111-1111-1111-1111-111111111111'; recentFile = '22222222-2222-2222-2222-222222222222'; fresh = '33333333-3333-3333-3333-333333333333'
            current = '44444444-4444-4444-4444-444444444444'; upper = 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA' }
function New-PruneRoot {
    Remove-Item -LiteralPath $pruneRoot -Recurse -Force -ErrorAction SilentlyContinue
    New-Dir "$pruneRoot\proj1\$($uuids.old)\sub\deep"; Set-Content "$pruneRoot\proj1\$($uuids.old)\sub\deep\a.txt" 'x'
    New-Dir "$pruneRoot\proj1\$($uuids.recentFile)\sub\deep"; Set-Content "$pruneRoot\proj1\$($uuids.recentFile)\sub\deep\a.txt" 'x'
    New-Dir "$pruneRoot\proj1\$($uuids.fresh)"
    New-Dir "$pruneRoot\proj2\$($uuids.current)"; Set-Content "$pruneRoot\proj2\$($uuids.current)\a.txt" 'x'
    New-Dir "$pruneRoot\proj2\$($uuids.upper)"; Set-Content "$pruneRoot\proj2\$($uuids.upper)\a.txt" 'x'
    New-Dir "$pruneRoot\proj2\not-a-uuid"; Set-Content "$pruneRoot\proj2\not-a-uuid\a.txt" 'x'
    Set-Content "$pruneRoot\proj2\loose.txt" 'x'; Set-Content "$pruneRoot\rootfile.txt" 'x'
    foreach ($p in @("proj1\$($uuids.old)", "proj2\$($uuids.current)", "proj2\$($uuids.upper)", 'proj2\not-a-uuid')) { Set-Age "$pruneRoot\$p" 10 }
    # Old folder, one file written 1 day ago deep inside: still in use.
    Set-Age "$pruneRoot\proj1\$($uuids.recentFile)" 10
    (Get-Item "$pruneRoot\proj1\$($uuids.recentFile)\sub\deep\a.txt").LastWriteTime = (Get-Date).AddDays(-1)
    (Get-Item "$pruneRoot\proj1\$($uuids.recentFile)" -Force).LastWriteTime = (Get-Date).AddDays(-10)
}
function Get-Leftovers { (Get-ChildItem -LiteralPath $pruneRoot -Recurse -Force | ForEach-Object { $_.FullName.Substring($pruneRoot.Length) } | Sort-Object) -join ' ' }
function New-PruneCase([string[]]$options, [string]$payloadName = 'session.json') {
    New-PruneRoot
    @{ payload = (Get-Payload $payloadName @{ SID = $uuids.current }); extra = (@('-Root', $pruneRoot) + $options) }
}
Write-Host 'prune-session-temp'
Test-Case 'old inactive sessions go, the others stay' 'prune-session-temp' 0 { New-PruneCase @('-Days', '7') } { Get-Leftovers }
Test-Case 'a longer retention keeps them all' 'prune-session-temp' 0 { New-PruneCase @('-Days', '30') } { Get-Leftovers }
Test-Case 'a zero time budget deletes nothing' 'prune-session-temp' 0 { New-PruneCase @('-Days', '7', '-BudgetSeconds', '0') } { Get-Leftovers }
Test-Case 'no session id on stdin' 'prune-session-temp' 0 { New-PruneCase @('-Days', '7') 'no-session.json' } { Get-Leftovers }
Test-Case 'empty stdin' 'prune-session-temp' 0 { $c = New-PruneCase @('-Days', '7'); $c.payload = ''; $c } { Get-Leftovers }
Test-Case 'missing root' 'prune-session-temp' 0 { @{ payload = (Get-Payload 'session.json' @{ SID = $uuids.current }); extra = @('-Root', (Join-Path $work 'nowhere')) } } { Test-Path -LiteralPath (Join-Path $work 'nowhere') }

# ---------------------------------------------------------------------------------------------------- phpcs-wp-native
$phpFound = (Test-Path 'C:\MAMP\bin\php') -or [bool](Get-Command php -ErrorAction SilentlyContinue)
$wp = Join-Path $work 'wp'
function New-WpFile([string]$relative, [string]$content, [string]$payloadName = 'file.json') {
    $f = Join-Path $wp $relative
    New-Dir (Split-Path -Parent $f); [IO.File]::WriteAllText($f, $content)
    @{ payload = (Get-Payload $payloadName @{ FILE = $f }) }
}
Write-Host 'phpcs-wp-native'
if (-not $phpFound) { Write-Host '  SKIP no php found (PATH or C:\MAMP\bin\php)' } else {
    Test-Case 'findings inside wp-content: reported' 'phpcs-wp-native' 2 { New-WpFile 'wp-content\plugins\x\bad.php' '<?php ISSUE' }
    Test-Case 'clean file inside wp-content' 'phpcs-wp-native' 0 { New-WpFile 'wp-content\plugins\x\ok.php' '<?php CLEAN' }
    Test-Case 'uppercase extension and path segment' 'phpcs-wp-native' 2 { New-WpFile 'WP-Content\plugins\x\BAD.PHP' '<?php ISSUE' }
    Test-Case 'wp-load.php two folders up marks a WordPress tree' 'phpcs-wp-native' 2 {
        $c = New-WpFile 'site\wp-load.php' '<?php'; New-WpFile 'site\a\b\bad.php' '<?php ISSUE' }
    Test-Case 'php outside any WordPress tree' 'phpcs-wp-native' 0 { New-WpFile 'plain\bad.php' '<?php ISSUE' }
    Test-Case 'not a php file' 'phpcs-wp-native' 0 { New-WpFile 'wp-content\plugins\x\notes.txt' 'ISSUE' }
    Test-Case 'a PHP notice on stderr does not silence the findings' 'phpcs-wp-native' 2 { New-WpFile 'wp-content\plugins\x\noisy.php' '<?php ISSUE STDERR' }
    Test-Case 'file missing on disk' 'phpcs-wp-native' 0 { @{ payload = (Get-Payload 'file.json' @{ FILE = (Join-Path $wp 'wp-content\gone.php') }) } }
    Test-Case 'no file_path' 'phpcs-wp-native' 0 { @{ payload = (Get-Payload 'no-file.json') } }
    Test-Case 'empty stdin' 'phpcs-wp-native' 0 { @{ payload = '' } }
    Test-Case 'no phpcs installed' 'phpcs-wp-native' 0 {
        Rename-Item (Join-Path $fakeAppData 'Composer\vendor\bin\phpcs') 'phpcs.off' -ErrorAction SilentlyContinue
        $c = New-WpFile 'wp-content\plugins\x\bad.php' '<?php ISSUE'; $c } { Rename-Item (Join-Path $fakeAppData 'Composer\vendor\bin\phpcs.off') 'phpcs' -ErrorAction SilentlyContinue; '' }
}

# ---------------------------------------------------------------------------------------------------- css-order
$cssDir = Join-Path $work 'css'
function New-CssFile([string]$name, [string]$fixture, [string]$payloadName = 'file.json') {
    $f = Join-Path $cssDir $name
    New-Dir (Split-Path -Parent $f)
    if ($fixture) { Copy-Item -LiteralPath (Join-Path $small "fixtures\$fixture.fixture") -Destination $f -Force }
    @{ payload = (Get-Payload $payloadName @{ FILE = $f }); ctx = $f }
}
function Get-FileText($f) { if (Test-Path -LiteralPath $f) { [IO.File]::ReadAllText($f) -replace "`r`n", '|' -replace "`n", '|' } else { 'absent' } }
Write-Host 'css-order'
Test-Case 'unordered declarations are reordered in place' 'css-order' 0 { New-CssFile 'a.css' 'css-unordered' } { param($f) Get-FileText $f }
Test-Case 'a convention gap is reported' 'css-order' 2 { New-CssFile 'b.css' 'css-note' } { param($f) Get-FileText $f }
Test-Case 'ordered file untouched' 'css-order' 0 { New-CssFile 'c.css' 'css-ordered' } { param($f) Get-FileText $f }
Test-Case 'scss extension is in scope' 'css-order' 0 { New-CssFile 'd.scss' 'css-unordered' } { param($f) Get-FileText $f }
Test-Case 'other extension is left alone' 'css-order' 0 { New-CssFile 'e.txt' 'css-unordered' } { param($f) Get-FileText $f }
Test-Case 'minified css is excluded' 'css-order' 0 { New-CssFile 'f.min.css' 'css-unordered' } { param($f) Get-FileText $f }
Test-Case 'file missing on disk' 'css-order' 0 { New-CssFile 'gone.css' '' } { param($f) Get-FileText $f }
Test-Case 'no file_path' 'css-order' 0 { @{ payload = (Get-Payload 'no-file.json') } }
Test-Case 'empty stdin' 'css-order' 0 { @{ payload = '' } }
Test-Case 'payload that is not json' 'css-order' 0 { @{ payload = [IO.File]::ReadAllText((Join-Path $small 'garbage.txt')) } }

# ---------------------------------------------------------------------------------------------------- guard-console-window
$cwDir = Join-Path $work 'cw'
function New-CwFile([string]$relative, [string]$fixture, [switch]$Crlf, [switch]$Bom) {
    $f = Join-Path $cwDir $relative
    New-Dir (Split-Path -Parent $f)
    $text = if ($fixture) { [IO.File]::ReadAllText((Join-Path $small "fixtures\$fixture.fixture")) } else { '' }
    if ($Crlf) { $text = $text -replace "`r?`n", "`r`n" }
    if ($fixture) { [IO.File]::WriteAllText($f, $text, (New-Object Text.UTF8Encoding ([bool]$Bom))) }
    @{ payload = (Get-Payload 'file.json' @{ FILE = $f }) }
}
Write-Host 'guard-console-window'
Test-Case 'node: detached spawn without windowsHide' 'guard-console-window' 2 { New-CwFile 'a.js' 'console-js-bad' }
Test-Case 'node: same file as .mjs with a BOM and CRLF' 'guard-console-window' 2 { New-CwFile 'a.mjs' 'console-js-bad' -Crlf -Bom }
Test-Case 'node: windowsHide or spread options cover every call' 'guard-console-window' 0 { New-CwFile 'b.mjs' 'console-js-ok' }
Test-Case 'node: nothing detached' 'guard-console-window' 0 { New-CwFile 'c.mjs' 'console-js-nodetach' }
Test-Case 'python: calls without creationflags' 'guard-console-window' 2 { New-CwFile 'a.py' 'console-py-bad' }
Test-Case 'python: CRLF file' 'guard-console-window' 2 { New-CwFile 'b.py' 'console-py-bad' -Crlf }
Test-Case 'python: bare run( and Popen( imported from subprocess' 'guard-console-window' 2 { New-CwFile 'c.py' 'console-py-bare' }
Test-Case 'python: nothing detached' 'guard-console-window' 0 { New-CwFile 'd.py' 'console-py-nodetach' }
Test-Case 'extension out of scope' 'guard-console-window' 0 { New-CwFile 'e.txt' 'console-js-bad' }
Test-Case 'inside node_modules' 'guard-console-window' 0 { New-CwFile 'node_modules\pkg\f.js' 'console-js-bad' }
Test-Case 'file over 2 MB' 'guard-console-window' 0 {
    $c = New-CwFile 'big.py' 'console-py-bad'; $f = Join-Path $cwDir 'big.py'
    [IO.File]::AppendAllText($f, "`n# " + ('x' * (2MB + 10))); $c }
Test-Case 'empty file' 'guard-console-window' 0 { $c = New-CwFile 'g.py' ''; [IO.File]::WriteAllText((Join-Path $cwDir 'g.py'), ''); $c }
Test-Case 'file missing on disk' 'guard-console-window' 0 { @{ payload = (Get-Payload 'file.json' @{ FILE = (Join-Path $cwDir 'gone.py') }) } }
Test-Case 'no file_path' 'guard-console-window' 0 { @{ payload = (Get-Payload 'no-file.json') } }
Test-Case 'empty stdin' 'guard-console-window' 0 { @{ payload = '' } }

# ---------------------------------------------------------------------------------------------------- guard-rules-style
$rsDir = Join-Path $work 'rs'
function New-Lines([int]$count) { (1..$count | ForEach-Object { "line $_" }) -join "`n" }
function New-RulesFile([string]$relative, [string]$content, [string]$fixture = '') {
    $f = Join-Path $rsDir $relative
    New-Dir (Split-Path -Parent $f)
    if ($fixture) { $content = [IO.File]::ReadAllText((Join-Path $small "fixtures\$fixture.fixture")) }
    [IO.File]::WriteAllText($f, $content)
    @{ payload = (Get-Payload 'file.json' @{ FILE = $f }) }
}
Write-Host 'guard-rules-style'
Test-Case 'CLAUDE.md over 200 lines' 'guard-rules-style' 2 { New-RulesFile 'p\CLAUDE.md' (New-Lines 250) }
Test-Case 'claude.md in lower case' 'guard-rules-style' 2 { New-RulesFile 'p\claude.md' (New-Lines 250) }
Test-Case 'CLAUDE.md within 200 lines, clean' 'guard-rules-style' 0 { New-RulesFile 'p\CLAUDE.md' '' 'rules-clean' }
Test-Case 'the injected method file is a claude-kind file' 'guard-rules-style' 2 { New-RulesFile '..\plugin\references\portes.md' (New-Lines 250) }
Test-Case 'SKILL.md allows 250 lines' 'guard-rules-style' 0 { New-RulesFile 'p\skills\foo\SKILL.md' (New-Lines 250) }
Test-Case 'SKILL.md over 500 lines' 'guard-rules-style' 2 { New-RulesFile 'p\skills\foo\SKILL.md' (New-Lines 520) }
Test-Case 'SKILL.md may carry links' 'guard-rules-style' 0 { New-RulesFile 'p\skills\foo\SKILL.md' '' 'rules-link' }
Test-Case 'rule with repeated emphasis' 'guard-rules-style' 2 { New-RulesFile 'p\.claude\rules\r.md' '' 'rules-emphasis' }
Test-Case 'rule with web links' 'guard-rules-style' 2 { New-RulesFile 'p\.claude\rules\r.md' '' 'rules-link' }
Test-Case 'agent definition with long quotations' 'guard-rules-style' 2 { New-RulesFile 'p\.claude\agents\a.md' '' 'rules-quotes' }
Test-Case 'plugin agent over 300 lines' 'guard-rules-style' 2 { New-RulesFile 'p\skills\s\agents\a.md' (New-Lines 310) }
Test-Case 'plain markdown is out of scope' 'guard-rules-style' 0 { New-RulesFile 'p\notes.md' (New-Lines 400) }
Test-Case 'inside node_modules' 'guard-rules-style' 0 { New-RulesFile 'p\node_modules\x\CLAUDE.md' (New-Lines 250) }
Test-Case 'inside tmp_essais' 'guard-rules-style' 0 { New-RulesFile 'p\tmp_essais\CLAUDE.md' (New-Lines 250) }
Test-Case 'empty file' 'guard-rules-style' 0 { New-RulesFile 'p\CLAUDE.md' '' }
Test-Case 'file missing on disk' 'guard-rules-style' 0 { @{ payload = (Get-Payload 'file.json' @{ FILE = (Join-Path $rsDir 'gone\CLAUDE.md') }) } }
Test-Case 'no file_path' 'guard-rules-style' 0 { @{ payload = (Get-Payload 'no-file.json') } }
Test-Case 'empty stdin' 'guard-rules-style' 0 { @{ payload = '' } }

# ---------------------------------------------------------------------------------------------------- measure
if ($Measure) {
    Write-Host 'durations: mean of 10 calls, ms (.ps1 vs .mjs)'
    $null = New-WpFile 'wp-content\plugins\x\ok.php' '<?php CLEAN'
    $null = New-CssFile 'm.css' 'css-ordered'
    $null = New-CwFile 'b.mjs' 'console-js-ok'
    $null = New-RulesFile 'p\CLAUDE.md' '' 'rules-clean'
    New-PruneRoot
    $samples = @(
        @('turn-timer', @(), (Get-Payload 'session.json' @{ SID = 'measure' })),
        @('play-sound', @('stop'), (Get-Payload 'session.json' @{ SID = 'measure' })),
        @('prune-session-temp', @('-Days', '3650', '-Root', $pruneRoot), (Get-Payload 'session.json' @{ SID = 'measure' })),
        @('phpcs-wp-native', @(), (Get-Payload 'file.json' @{ FILE = (Join-Path $wp 'wp-content\plugins\x\ok.php') })),
        @('css-order', @(), (Get-Payload 'file.json' @{ FILE = (Join-Path $cssDir 'm.css') })),
        @('guard-console-window', @(), (Get-Payload 'file.json' @{ FILE = (Join-Path $cwDir 'b.mjs') })),
        @('guard-rules-style', @(), (Get-Payload 'file.json' @{ FILE = (Join-Path $rsDir 'p\CLAUDE.md') }))
    )
    foreach ($s in $samples) {
        $mean = @{}
        foreach ($impl in @('ps1', 'mjs')) {
            $total = 0
            1..10 | ForEach-Object { $total += (Invoke-Impl $impl $s[0] ([string[]]$s[1]) $s[2]).ms }
            $mean[$impl] = [math]::Round($total / 10)
        }
        Write-Host ("  {0,-22} {1,5} -> {2,4}" -f $s[0], $mean['ps1'], $mean['mjs'])
    }
}

Wait-NoSound
Write-Host "$($script:n - $script:ko)/$($script:n) OK"
Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
if ($script:ko -eq 0) { exit 0 } else { exit 1 }
