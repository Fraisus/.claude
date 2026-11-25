# test-guard-private-read.ps1 - behaviour tests for guard-private-read.mjs (paths listed in .claude/private-paths.txt).
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-private-read.ps1
# Each case feeds the hook a payload on stdin, against a throwaway project folder, and checks the exit code: 2 blocks,
# 0 allows. While the PowerShell hook it replaces still sits beside it, the case also requires the same exit code,
# stdout and stderr from both.

$scripts = Join-Path (Split-Path $PSScriptRoot) 'scripts'
$hook = Join-Path $scripts 'guard-private-read.mjs'
$legacy = Join-Path $scripts 'guard-private-read.ps1'
$project = Join-Path $env:TEMP 'claude-private-test-project'
New-Item -ItemType Directory -Force -Path (Join-Path $project '.claude') | Out-Null
Set-Content -LiteralPath (Join-Path $project '.claude\private-paths.txt') -Value @('# private', 'secret/', 'keys/*.key') -Encoding UTF8
$env:CLAUDE_PROJECT_DIR = $project

function Invoke-Hook([string]$file, [string[]]$arguments, [string]$payload) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo $file
    $psi.Arguments = ($arguments | ForEach-Object { '"' + $_ + '"' }) -join ' '
    $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8; $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    $p = [System.Diagnostics.Process]::Start($psi)
    $bytes = (New-Object System.Text.UTF8Encoding $false).GetBytes($payload)
    $p.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length); $p.StandardInput.Close()
    $err = $p.StandardError.ReadToEndAsync(); $out = $p.StandardOutput.ReadToEnd(); $p.WaitForExit()
    return @{ code = $p.ExitCode; out = $out; err = ($err.Result -replace "`r`n", "`n") }
}

$script:n = 0; $script:ko = 0
function Assert-Private([string]$label, [int]$expected, [string]$tool, [hashtable]$toolInput) {
    $script:n++
    $payload = @{ tool_name = $tool; cwd = $project; tool_input = $toolInput } | ConvertTo-Json -Compress
    $r = Invoke-Hook 'node' @($hook) $payload
    $why = if ($r.code -ne $expected) { "exit $($r.code), expected $expected" }
    if (-not $why -and (Test-Path -LiteralPath $legacy)) {
        $l = Invoke-Hook 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $legacy) $payload
        if ($l.code -ne $r.code -or $l.out -ne $r.out -or $l.err -ne $r.err) { $why = "differs from the .ps1: [$($l.code) $($l.out) $($l.err)] vs [$($r.code) $($r.out) $($r.err)]" }
    }
    if (-not $why) { Write-Host "  OK   $label" } else { $script:ko++; Write-Host "  KO   $label ($why)" }
}

# Calibration: a private folder stays closed to Read, to the shell, and through a trailing period Win32 drops.
Assert-Private 'Read in a private folder: blocked' 2 'Read' @{ file_path = (Join-Path $project 'secret\a.txt') }
Assert-Private 'Read through a trailing period: blocked' 2 'Read' @{ file_path = (Join-Path $project 'secret.\a.txt') }
Assert-Private 'cd into a private folder: blocked' 2 'Bash' @{ command = 'cd secret && ls' }
Assert-Private 'Glob on a private wildcard: blocked' 2 'Glob' @{ pattern = 'keys/*.key' }
# A name that only starts like a private one, and commands that name none, stay free.
Assert-Private 'Read of a look-alike name: allowed' 0 'Read' @{ file_path = (Join-Path $project 'secretive.txt') }
Assert-Private 'command naming no private path: allowed' 0 'Bash' @{ command = 'echo hello' }

Write-Host "$($script:n - $script:ko)/$($script:n) OK"
Remove-Item -LiteralPath $project -Recurse -Force
if ($script:ko -eq 0) { exit 0 } else { exit 1 }
