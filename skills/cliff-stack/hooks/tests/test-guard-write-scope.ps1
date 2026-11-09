# test-guard-write-scope.ps1 - behaviour tests for guard-write-scope.ps1 (PreToolUse, shell writes outside the zones).
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-write-scope.ps1
# Each case feeds the hook a PowerShell tool payload on stdin and checks the exit code: 2 blocks, 0 allows.
# Nothing runs the commands themselves; blocking cases come first and calibrate the probe.

$hook = Join-Path (Split-Path $PSScriptRoot) 'scripts\guard-write-scope.ps1'
$env:CLAUDE_PROJECT_DIR = Join-Path $env:TEMP 'claude-scope-test-project'
$inZone = Join-Path $env:LOCALAPPDATA 'Temp\claude\scope-test\probe.mjs'

$script:n = 0; $script:ko = 0
function Assert-Scope([string]$label, [int]$expected, [string]$command) {
    $script:n++
    $payload = @{ tool_name = 'PowerShell'; tool_input = @{ command = $command } } | ConvertTo-Json -Compress
    $payload | powershell -NoProfile -ExecutionPolicy Bypass -File $hook 2>$null | Out-Null
    $code = $LASTEXITCODE
    if ($code -eq $expected) { Write-Host "  OK   $label" } else { $script:ko++; Write-Host "  KO   $label (exit $code, expected $expected)" }
}

# Calibration: writes outside the zones stay blocked, with or without a URL in the same command.
Assert-Scope 'write outside the zones: blocked' 2 'Set-Content -LiteralPath D:\evil\x.txt -Value 1'
Assert-Scope 'file:/// string plus a write outside: blocked' 2 "Set-Content -LiteralPath D:\evil\x.txt -Value `"import('file:///`$pw')`""
Assert-Scope 'http URL plus a write outside: blocked' 2 'Set-Content -LiteralPath D:\evil\x.txt -Value http://localhost/x'
# URL fragments are not paths.
Assert-Scope 'file:/// string inside an in-zone write: allowed' 0 "Set-Content -LiteralPath `"$inZone`" -Value `"import('file:///`$pw')`""
Assert-Scope 'http URL inside an in-zone write: allowed' 0 "Set-Content -LiteralPath `"$inZone`" -Value http://localhost/site/"

Write-Host "$($script:n - $script:ko)/$($script:n) OK"
if ($script:ko -eq 0) { exit 0 } else { exit 1 }
