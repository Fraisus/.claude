# test-agent-context.ps1 - Essais de agent-usage.mjs et de ses consommateurs : guard-agent-context.mjs (plafond de contexte),
# guard-agent-resume.ps1 (reprise), log-agents.ps1 (tokens journalisés), guard-git-destructive.ps1 (git de l'ouvrier de zone).
# Lancement : powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-agent-context.ps1
# Fausses transcriptions dans un dossier jetable hooks\tests\.essais\<horodatage>\, effacé quand tout passe.

$ErrorActionPreference = 'Stop'
$scripts = Join-Path (Split-Path $PSScriptRoot) 'scripts'
$essaisRacine = Join-Path $PSScriptRoot '.essais'
New-Item -ItemType Directory -Force -Path $essaisRacine | Out-Null
Set-Content -LiteralPath (Join-Path $essaisRacine '.gitignore') -Value '*' -Encoding Ascii
$essai = Join-Path $essaisRacine ('ctx-' + [System.DateTime]::Now.ToString('yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force -Path $essai | Out-Null
$env:CLIFF_EQUIPE_LOG_DIR = $essai
$env:CLIFF_EQUIPE_BUDGET = Join-Path $essai 'budget.json'
@{ schema = 1; mode = 'journal'; contexte_agent_max = 150000; contexte_palier = 50000 } | ConvertTo-Json | Set-Content -LiteralPath $env:CLIFF_EQUIPE_BUDGET -Encoding UTF8

$session = 'sess-ctx-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$principal = Join-Path $essai "$session.jsonl"
Set-Content -LiteralPath $principal -Value '' -Encoding Ascii
$sub = Join-Path $essai "$session\subagents"
New-Item -ItemType Directory -Force -Path $sub | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)

# Transcription d'agent : une ligne assistant par tour, contexte = cache_read (le reste à 0), chaque id écrit deux fois.
function Transcription([string]$id, [int[]]$contextes, [string]$type = 'general-purpose') {
    $lignes = foreach ($i in 0..($contextes.Count - 1)) {
        $u = @{ input_tokens = 0; cache_creation_input_tokens = 0; cache_read_input_tokens = $contextes[$i]; output_tokens = 10 }
        $l = @{ type = 'assistant'; agentId = $id; message = @{ id = "msg_$i"; model = 'claude-test'; usage = $u } } | ConvertTo-Json -Depth 5 -Compress
        $l; $l
    }
    [System.IO.File]::WriteAllText((Join-Path $sub "agent-$id.jsonl"), (($lignes -join "`n") + "`n"), $utf8)
    [System.IO.File]::WriteAllText((Join-Path $sub "agent-$id.meta.json"), (@{ agentType = $type; model = 'opus'; effort = 'high'; spawnDepth = 1 } | ConvertTo-Json -Compress), $utf8)
}

$script:n = 0; $script:ko = 0
function Lancer([string]$exe, [string]$arguments, $payload) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo $exe, $arguments
    $psi.RedirectStandardInput = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true; $psi.UseShellExecute = $false
    $proc = [System.Diagnostics.Process]::Start($psi)
    $octets = [System.Text.Encoding]::UTF8.GetBytes([string]($payload | ConvertTo-Json -Depth 6 -Compress))
    $proc.StandardInput.BaseStream.Write($octets, 0, $octets.Length); $proc.StandardInput.Close()
    $out = $proc.StandardOutput.ReadToEnd(); $proc.WaitForExit()
    return @{ code = $proc.ExitCode; out = $out }
}
function Verifier([string]$titre, [bool]$ok, [string]$detail = '') {
    $script:n++
    if ($ok) { Write-Host ("  OK   " + $titre) } else { $script:ko++; Write-Host ("  KO   " + $titre + ' ' + $detail) }
}
function Contexte($payload) { Lancer 'node' "`"$(Join-Path $scripts 'guard-agent-context.mjs')`"" $payload }
function Outil([string]$id, [string]$chemin = $principal) { @{ session_id = $session; hook_event_name = 'PostToolUse'; tool_name = 'Read'; agent_id = $id; transcript_path = $chemin } }

Write-Host 'guard-agent-context.mjs'
Transcription 'a1' @(56000, 120000)
$r = Contexte @{ session_id = $session; hook_event_name = 'PostToolUse'; tool_name = 'Read'; transcript_path = $principal }
Verifier 'session principale (sans agent_id) : rien' ($r.code -eq 0 -and -not $r.out.Trim()) $r.out
$r = Contexte (Outil 'a1')
Verifier 'sous le plafond : rien' ($r.code -eq 0 -and -not $r.out.Trim()) $r.out
Transcription 'a1' @(56000, 160000)
$r = Contexte (Outil 'a1')
Verifier 'au-dela du plafond : block avec passation' ($r.code -eq 0 -and $r.out -match '"decision":"block"' -and $r.out -match 'passation') $r.out
$r = Contexte (Outil 'a1')
Verifier 'meme palier : un seul rappel' (-not $r.out.Trim()) $r.out
Transcription 'a1' @(56000, 160000, 210000)
$r = Contexte (Outil 'a1')
Verifier 'palier suivant : nouveau rappel' ($r.out -match '"decision":"block"') $r.out
$null = Contexte @{ session_id = $session; hook_event_name = 'SubagentStop'; agent_id = 'a1'; transcript_path = $principal }
$r = Contexte (Outil 'a1')
Verifier 'SubagentStop retire le marqueur' ($r.out -match '"decision":"block"') $r.out
Transcription 'a2' @(170000)
$r = Contexte (Outil 'a2' (Join-Path $sub 'agent-a2.jsonl'))
Verifier 'transcript_path deja celui de l agent : lu' ($r.out -match '"decision":"block"') $r.out
$r = Contexte (Outil 'a-absent')
Verifier 'transcription introuvable : rien' ($r.code -eq 0 -and -not $r.out.Trim()) $r.out

Write-Host 'agent-usage.mjs totals'
$t = & node (Join-Path $scripts 'agent-usage.mjs') totals --agent a1 --transcript $principal | ConvertFrom-Json
Verifier 'totaux dedupliques par message.id' ($t.tours -eq 3 -and $t.cache_lu -eq 426000 -and $t.contexte_max -eq 210000 -and $t.modele_demande -eq 'opus' -and $t.effort -eq 'high') ($t | ConvertTo-Json -Compress)

Write-Host 'guard-agent-resume.ps1'
$resume = "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $scripts 'guard-agent-resume.ps1')`""
Transcription 'abcdef0123456' @(80000)
$r = Lancer 'powershell.exe' $resume @{ session_id = $session; transcript_path = $principal; tool_input = @{ to = 'abcdef0123456'; message = 'suite' } }
Verifier 'reprise sous max moins palier : passe' (-not $r.out.Trim()) $r.out
Transcription 'abcdef0123456' @(80000, 120000)
$r = Lancer 'powershell.exe' $resume @{ session_id = $session; transcript_path = $principal; tool_input = @{ to = 'abcdef0123456'; message = 'suite' } }
Verifier 'reprise au-dela de max moins palier : refus' ($r.out -match '"permissionDecision":"deny"') $r.out
$r = Lancer 'powershell.exe' $resume @{ session_id = $session; transcript_path = $principal; tool_input = @{ to = 'abcdef0123456'; message = 'suite #reprise' } }
Verifier '#reprise passe outre' (-not $r.out.Trim()) $r.out

Write-Host 'guard-git-destructive.ps1, ouvrier de zone'
$git = "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $scripts 'guard-git-destructive.ps1')`""
Transcription 'aouv' @(1000) 'cliff-stack:ouvrier-zone'
Transcription 'agen' @(1000) 'general-purpose'
function Git([string]$id, [string]$cmd, [string]$type = $null) {
    $pl = @{ session_id = $session; transcript_path = $principal; agent_id = $id; tool_name = 'Bash'; tool_input = @{ command = $cmd } }
    if ($type) { $pl.agent_type = $type }
    Lancer 'powershell.exe' $git $pl
}
Verifier 'ouvrier (type par le meta) : commit passe' (-not (Git 'aouv' 'git add a.rs && git commit -m "push --force dans le message"').out.Trim())
Verifier 'ouvrier : merge du tronc par -C passe' (-not (Git 'aouv' 'git -C C:/wt merge main').out.Trim())
Verifier 'ouvrier : push refuse' ((Git 'aouv' 'git push origin zone').out -match '"permissionDecision":"deny"')
Verifier 'ouvrier : branch -D refuse' ((Git 'aouv' 'git branch -D zone').out -match '"permissionDecision":"deny"')
Verifier 'ouvrier (type par le payload) : rebase refuse' ((Git 'aautre' 'git rebase main' 'cliff-stack:ouvrier-zone').out -match '"permissionDecision":"deny"')
Verifier 'autre sous-agent : push non touche par la regle de l ouvrier' (-not (Git 'agen' 'git push origin x').out.Trim())
foreach ($contournement in @("git add a`ngit commit -m x`ngit push", 'sh -c "git push"', 'git log $(git push)', 'git -C "C:/x y" push', 'git --work-tree . push', '"git" push')) {
    Verifier "ouvrier : contournement refuse ($($contournement -replace "`n", ' \n '))" ((Git 'aouv' $contournement).out -match '"permissionDecision":"deny"')
}
Verifier 'ouvrier : commit en heredoc dont le message cite git passe' (-not (Git 'aouv' "git commit -F - <<'EOF'`nGarde git de l'ouvrier`nEOF").out.Trim())
Verifier 'ouvrier : lectures branch --contains, remote --verbose, config get passent' (-not (Git 'aouv' "git branch --contains abc`ngit remote -v 2>&1`ngit remote --verbose`ngit config get user.name").out.Trim())
foreach ($b in @('git branch --d z', 'git branch -df z', 'git branch --dele z', 'git branch --forc main HEAD~3', 'git worktree add x', 'git stash drop', 'git remote add o u', 'git config user.name x', 'git reflog expire --all')) {
    Verifier "ouvrier : $b refuse" ((Git 'aouv' $b).out -match '"permissionDecision":"deny"')
}
Verifier 'ouvrier : lectures worktree, stash, remote, config, reflog passent' (-not (Git 'aouv' "git worktree list`ngit stash list`ngit remote -v`ngit config --get user.name`ngit reflog -5`ngit branch --show-current").out.Trim())
Verifier 'ouvrier : merge-base et rev-list passent' (-not (Git 'aouv' "git merge-base HEAD main`ngit rev-list --count main..HEAD").out.Trim())

Write-Host 'log-agents.ps1, tokens'
$r = Lancer 'powershell.exe' "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $scripts 'log-agents.ps1')`"" @{ session_id = $session; cwd = $essai; agent_id = 'a1'; agent_type = 'general-purpose'; transcript_path = $principal; last_assistant_message = 'fin' }
$la = Get-Content -LiteralPath (Join-Path $essai 'agents.jsonl') -Encoding UTF8 | Select-Object -Last 1 | ConvertFrom-Json
Verifier 'agents.jsonl porte contexte maximal, tours et modele' ($la.contexte_max -eq 210000 -and $la.tours -eq 3 -and $la.modele -eq 'claude-test') ($la | ConvertTo-Json -Compress)

Get-ChildItem -LiteralPath (Join-Path ([System.IO.Path]::GetTempPath()) 'cliff-equipe-contexte') -Filter "$session*" -ErrorAction SilentlyContinue | Remove-Item -Force
Write-Host ''
Write-Host ("Essais : " + $script:n + ", echecs : " + $script:ko + ", dossier : " + $essai)
if ($script:ko -gt 0) { exit 1 }
Remove-Item -Recurse -Force -LiteralPath $essai
exit 0
