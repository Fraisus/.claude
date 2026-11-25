# test-guard-cause-before-code.ps1 - behaviour tests for guard-cause-before-code.mjs (prompt, pre, stop), compared with
# guard-cause-before-code.ps1 on the same payloads while the PowerShell version is still there.
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-cause-before-code.ps1
# Each case writes a one-turn transcript into a throwaway folder hooks\tests\.essais\<timestamp>\ (git-ignored, deleted
# when every case passes) and checks the exit code, then that stdout and stderr say the expected thing.

$ErrorActionPreference = 'Stop'
$scripts = Join-Path (Split-Path $PSScriptRoot) 'scripts'
$mjs = Join-Path $scripts 'guard-cause-before-code.mjs'
$ps1 = Join-Path $scripts 'guard-cause-before-code.ps1'
$root = Join-Path $PSScriptRoot '.essais'
New-Item -ItemType Directory -Force -Path $root | Out-Null
Set-Content -LiteralPath (Join-Path $root '.gitignore') -Value '*' -Encoding Ascii
$dir = Join-Path $root ('cause-' + [System.DateTime]::Now.ToString('yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Force -Path $dir | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding $false

function Step-User([string]$text) { @{ type = 'user'; message = @{ content = $text } } }
function Step-Edit([string]$path) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'tool_use'; name = 'Edit'; input = @{ file_path = $path } }) } } }
function Step-Tool([string]$name, $inp) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'tool_use'; name = $name; input = $inp }) } } }
function Step-Text([string]$text) { @{ type = 'assistant'; message = @{ content = @(@{ type = 'text'; text = $text }) } } }

function Launch([string]$exe, [string]$arguments, [string]$payload) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo $exe, $arguments
    $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true; $psi.UseShellExecute = $false
    $psi.StandardOutputEncoding = $utf8; $psi.StandardErrorEncoding = $utf8
    $proc = [System.Diagnostics.Process]::Start($psi)
    $bytes = $utf8.GetBytes($payload)
    $proc.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length); $proc.StandardInput.Close()
    $errTask = $proc.StandardError.ReadToEndAsync()
    $out = $proc.StandardOutput.ReadToEnd(); $proc.WaitForExit()
    # a BOM or a CRLF end of line is no difference for the harness
    return @{ code = $proc.ExitCode; out = $out.TrimStart([char]0xFEFF).Trim(); err = $errTask.Result.TrimStart([char]0xFEFF).Trim() }
}

$script:n = 0; $script:ko = 0
# $steps: the transcript (a user message first); $extra: payload fields; $expect: the text stdout or stderr must hold.
function Assert-Hook([string]$label, [string]$mode, [int]$code, [string]$expect, [array]$steps, [hashtable]$extra = @{}) {
    $script:n++
    $payload = @{} + $extra
    if ($steps) {
        $transcript = Join-Path $dir "t$($script:n).jsonl"
        $lines = $steps | ForEach-Object { $_ | ConvertTo-Json -Depth 8 -Compress }
        [System.IO.File]::WriteAllLines($transcript, [string[]]$lines, $utf8)
        $payload.transcript_path = $transcript
    }
    $json = $payload | ConvertTo-Json -Depth 6 -Compress
    $r = Launch 'node' "`"$mjs`" $mode" $json
    $said = if ($code -eq 2) { $r.err } else { $r.out }
    $ok = $r.code -eq $code -and ($(if ($expect) { $said.Contains($expect) } else { -not $said }))
    $detail = "exit $($r.code), out [$($r.out)], err [$($r.err)]"
    if ($ok -and (Test-Path -LiteralPath $ps1)) {
        $o = Launch 'powershell.exe' "-NoProfile -ExecutionPolicy Bypass -File `"$ps1`" $mode" $json
        $ok = $o.code -eq $r.code -and $o.out -ceq $r.out -and $o.err -ceq $r.err
        if (-not $ok) { $detail = "differs from the .ps1: exit $($o.code), out [$($o.out)], err [$($o.err)]" }
    }
    if ($ok) { Write-Host "  OK   $label" } else { $script:ko++; Write-Host "  KO   $label ($detail)" }
}

# Windows PowerShell 5.1 reads this file as ANSI: accented letters are built from their code, never typed.
$e = [char]0xE9
$ts = 'C:/proj/src/menu.ts'
$broken = "Le menu est cass$e."
Assert-Hook 'prompt: a defect with an accent is reminded' 'prompt' 0 'Si ce message signale un d' $null @{ prompt = "L'export a ${e}chou$e hier." }
Assert-Hook 'prompt: a request with no defect says nothing' 'prompt' 0 '' $null @{ prompt = 'Ajoute un bouton de partage.' }
Assert-Hook 'pre: code edit after a reported defect, no cause: refused' 'pre' 2 'Portes (1)' @((Step-User $broken), (Step-Text 'Je regarde.')) @{ tool_name = 'Edit'; tool_input = @{ file_path = $ts } }
Assert-Hook 'pre: Cause and Niveau echoed by the shell: allowed' 'pre' 0 '' @((Step-User $broken), (Step-Tool 'Bash' @{ command = "echo `"Cause : src/menu.ts:42, le clic`"`necho `"Niveau : composant`""; description = "D${e}clare" })) @{ tool_name = 'Edit'; tool_input = @{ file_path = $ts } }
Assert-Hook 'stop: claim without a search nor Ailleurs: refused' 'stop' 2 'Porte (5)' @((Step-User $broken), (Step-Edit $ts), (Step-Text "C'est corrig$e.")) @{}
Assert-Hook 'stop: defect that comes back, critic after the last write: allowed' 'stop' 0 '' @((Step-User 'Toujours le meme bug sur le menu.'), (Step-Tool 'Grep' @{ pattern = 'menu' }), (Step-Edit $ts), (Step-Tool 'Agent' @{ subagent_type = 'cliff-stack:independent-critic' }), (Step-Text "Ailleurs : grep menu, 1 occurrence, corrig${e}e.`nC'est r${e}gl$e.")) @{}

Write-Host "$($script:n - $script:ko)/$($script:n) OK"
if ($script:ko -eq 0) { Remove-Item -LiteralPath $dir -Recurse -Force; exit 0 } else { Write-Host "Transcripts kept in $dir"; exit 1 }
