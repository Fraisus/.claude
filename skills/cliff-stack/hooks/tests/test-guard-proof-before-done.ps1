# test-guard-proof-before-done.ps1 - behaviour tests for guard-proof-before-done.ps1 (Stop hook, gate 8).
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-proof-before-done.ps1
# Each case writes a one-turn transcript into a throwaway folder hooks\tests\.essais\<timestamp>\ (git-ignored,
# deleted when every case passes), feeds the hook its path on stdin and checks the exit code: 2 blocks the claim,
# 0 lets it through. Known-blocking cases calibrate the probe before the accepted ones count.

$ErrorActionPreference = 'Stop'
$hook = Join-Path (Split-Path $PSScriptRoot) 'scripts\guard-proof-before-done.ps1'
$root = Join-Path $PSScriptRoot '.essais'
New-Item -ItemType Directory -Force -Path $root | Out-Null
Set-Content -LiteralPath (Join-Path $root '.gitignore') -Value '*' -Encoding Ascii
$dir = Join-Path $root ([System.DateTime]::Now.ToString('yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Force -Path $dir | Out-Null

$CLAIM = "C'est fait."

function Step-Edit([string]$path) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'tool_use'; name = 'Edit'; input = @{ file_path = $path } }) } } }
function Step-Shell([string]$cmd) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'tool_use'; name = 'PowerShell'; input = @{ command = $cmd } }) } } }
function Step-Text([string]$text) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'text'; text = $text }) } } }

$script:n = 0; $script:ko = 0
function Assert-Hook([string]$label, [int]$expected, [array]$steps, [switch]$active) {
    $script:n++
    $transcript = Join-Path $dir "t$($script:n).jsonl"
    $lines = @(@{ type = 'user'; message = @{ content = 'request' } }) + $steps | ForEach-Object { $_ | ConvertTo-Json -Depth 8 -Compress }
    [System.IO.File]::WriteAllLines($transcript, [string[]]$lines, (New-Object System.Text.UTF8Encoding $false))
    $payload = @{ transcript_path = $transcript; stop_hook_active = [bool]$active } | ConvertTo-Json -Compress
    $ErrorActionPreference = 'Continue'  # Windows PowerShell 5.1 turns a native stderr line into a terminating error under Stop
    $payload | powershell -NoProfile -ExecutionPolicy Bypass -File $hook 2>$null | Out-Null
    $code = $LASTEXITCODE
    if ($code -eq $expected) { Write-Host "  OK   $label" } else { $script:ko++; Write-Host "  KO   $label (exit $code, expected $expected)" }
}

$php = 'C:/site/wp-content/themes/t/functions.php'
# Calibration: cases the hook must block.
Assert-Hook 'edit then claim, no probe: blocked' 2 @((Step-Edit $php), (Step-Text $CLAIM))
Assert-Hook 'probe before the last edit: blocked' 2 @((Step-Shell 'node --check a.mjs'), (Step-Edit $php), (Step-Text $CLAIM))
Assert-Hook 'read-back is not a probe: blocked' 2 @((Step-Edit $php), (Step-Shell "Get-Content $php"), (Step-Text $CLAIM))
Assert-Hook 'download from a remote host: blocked' 2 @((Step-Edit $php), (Step-Shell 'curl.exe -s https://example.com/lib.js -o lib.js'), (Step-Text $CLAIM))
Assert-Hook 'remote host starting with localhost: blocked' 2 @((Step-Edit $php), (Step-Shell 'curl.exe -s http://localhost.example.com/x'), (Step-Text $CLAIM))
Assert-Hook 'remote host containing .test: blocked' 2 @((Step-Edit $php), (Step-Shell 'curl.exe -s https://site.test.example.com/'), (Step-Text $CLAIM))
# Cases the hook must let through.
Assert-Hook 'no claim: allowed' 0 @((Step-Edit $php), (Step-Text 'Voici le diff.'))
Assert-Hook 'second stop of the turn: allowed' 0 @((Step-Edit $php), (Step-Text $CLAIM)) -active
Assert-Hook 'php -l through the MAMP binary' 0 @((Step-Edit $php), (Step-Shell "C:\MAMP\bin\php\php8.2.14\php.exe -c C:\MAMP\bin\php\php8.2.14\php.ini -l $php"), (Step-Text $CLAIM))
Assert-Hook 'stored rung: wp eval-file' 0 @((Step-Edit $php), (Step-Shell 'C:\MAMP\bin\php\wp.bat eval-file C:\tmp\probe.php'), (Step-Text $CLAIM))
Assert-Hook 'served rung: curl on localhost' 0 @((Step-Edit $php), (Step-Shell 'curl.exe -s http://localhost/site/ -o C:\tmp\home.html'), (Step-Text $CLAIM))
Assert-Hook 'served rung: curl on a .test host' 0 @((Step-Edit $php), (Step-Shell 'curl.exe -sI http://site.test/'), (Step-Text $CLAIM))
Assert-Hook 'JSON parse after a JSON edit' 0 @((Step-Edit 'C:/x/hooks.json'), (Step-Shell "node -e `"JSON.parse(require('fs').readFileSync('hooks.json','utf8'))`""), (Step-Text $CLAIM))
Assert-Hook 'test script under a backslash tests folder' 0 @((Step-Edit $php), (Step-Shell 'powershell -File hooks\tests\test-x.ps1'), (Step-Text $CLAIM))

Write-Host "$($script:n - $script:ko)/$($script:n) OK"
if ($script:ko -eq 0) { Remove-Item -LiteralPath $dir -Recurse -Force; exit 0 } else { Write-Host "Transcripts kept in $dir"; exit 1 }
