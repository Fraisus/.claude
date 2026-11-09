# test-guard-root-hygiene.ps1 - behaviour tests for guard-root-hygiene.ps1 (no new file at a project root).
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-root-hygiene.ps1
# Each case feeds the hook a shell tool payload on stdin, against a throwaway project folder, and checks the exit
# code: 2 blocks, 0 allows. Nothing runs the commands themselves; blocking cases come first and calibrate the probe.

$hook = Join-Path (Split-Path $PSScriptRoot) 'scripts\guard-root-hygiene.ps1'
$project = Join-Path $env:TEMP 'claude-root-test-project'
New-Item -ItemType Directory -Force -Path $project | Out-Null
$env:CLAUDE_PROJECT_DIR = $project
$nl = "`n"

$script:n = 0; $script:ko = 0
function Assert-Root([string]$label, [int]$expected, [string]$tool, [string]$command) {
    $script:n++
    $payload = @{ tool_name = $tool; cwd = $project; tool_input = @{ command = $command } } | ConvertTo-Json -Compress
    $payload | powershell -NoProfile -ExecutionPolicy Bypass -File $hook 2>$null | Out-Null
    $code = $LASTEXITCODE
    if ($code -eq $expected) { Write-Host "  OK   $label" } else { $script:ko++; Write-Host "  KO   $label (exit $code, expected $expected)" }
}

# Calibration: real redirections to a new root file stay blocked.
Assert-Root 'redirection to a new root file: blocked' 2 'PowerShell' 'echo x > nouveau.md'
Assert-Root 'here-string then a real root redirection: blocked' 2 'PowerShell' ("Write-Output @'" + $nl + 'a <b@c.d>' + $nl + "'@ > nouveau.md")
Assert-Root 'bash heredoc then a real root redirection: blocked' 2 'Bash' ("cat <<'EOF' > nouveau.md" + $nl + 'x' + $nl + 'EOF')
# Chevrons inside a here-string are content, not redirections.
Assert-Root 'single-quoted here-string with <a@b.c>: allowed' 0 'PowerShell' ("git commit -q -m @'" + $nl + 'msg' + $nl + $nl + 'Co-Authored-By: X <noreply@example.com>' + $nl + "'@")
Assert-Root 'double-quoted here-string with a tag: allowed' 0 'PowerShell' ('$t = @"' + $nl + '<style a="b">x</style>' + $nl + '"@')

Write-Host "$($script:n - $script:ko)/$($script:n) OK"
Remove-Item -LiteralPath $project -Recurse -Force
if ($script:ko -eq 0) { exit 0 } else { exit 1 }
