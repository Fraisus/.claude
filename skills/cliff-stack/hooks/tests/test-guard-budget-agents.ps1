# test-guard-budget-agents.ps1 — Essais de comportement de guard-budget-agents.ps1 et log-agents.ps1.
# Lancement : powershell -NoProfile -ExecutionPolicy Bypass -File hooks\tests\test-guard-budget-agents.ps1
# Chaque essai nourrit le script par stdin avec un payload de hook et vérifie le code de retour et le journal.
# Tout se passe dans un dossier d'essai jetable hooks\tests\.essais\<horodatage>\ (auto-ignoré par git, effacé
# à la fin quand tout passe), avec un budget d'essai en mode bloquer puis en mode journal ; aucun journal réel
# n'est touché et aucune donnée de projet n'entre dans ~\.claude.

$ErrorActionPreference = 'Stop'
$racine = Split-Path $PSScriptRoot
$garde = Join-Path $racine 'scripts\guard-budget-agents.ps1'
$log = Join-Path $racine 'scripts\log-agents.ps1'
$essaisRacine = Join-Path $PSScriptRoot '.essais'
New-Item -ItemType Directory -Force -Path $essaisRacine | Out-Null
Set-Content -LiteralPath (Join-Path $essaisRacine '.gitignore') -Value '*' -Encoding Ascii
$essai = Join-Path $essaisRacine ([System.DateTime]::Now.ToString('yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force -Path $essai | Out-Null
$env:CLIFF_EQUIPE_LOG_DIR = $essai
$env:CLIFF_EQUIPE_BUDGET = Join-Path $essai 'budget.json'
$env:CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS = $null
$env:CLIFF_EQUIPE_SIMULTANES = $null
$projetDirInitial = $env:CLAUDE_PROJECT_DIR

function Budget([string]$mode, [int]$simult = 3) {
    @{ schema = 1; mode = $mode; simultanes = $simult; total_par_plan = 12; playwright_simultanes = 1; ttl_agent_min = 60
       plafond_harness = @{ defaut_documente = 20 } } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $env:CLIFF_EQUIPE_BUDGET -Encoding UTF8
}

$script:n = 0; $script:ko = 0
function Lancer([string]$mode, $payload) {
    $json = if ($null -eq $payload) { '' } else { $payload | ConvertTo-Json -Depth 6 -Compress }
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = 'powershell.exe'
    $psi.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$garde`" $mode"
    $psi.RedirectStandardInput = $true; $psi.RedirectStandardError = $true; $psi.RedirectStandardOutput = $true
    $psi.UseShellExecute = $false
    $proc = [System.Diagnostics.Process]::Start($psi)
    # .NET Framework n'a pas StandardInputEncoding : on écrit les octets UTF-8 nous-mêmes.
    $octets = [System.Text.Encoding]::UTF8.GetBytes([string]$json)
    $proc.StandardInput.BaseStream.Write($octets, 0, $octets.Length); $proc.StandardInput.Close()
    $err = $proc.StandardError.ReadToEnd(); $proc.WaitForExit()
    return @{ code = $proc.ExitCode; err = $err }
}
function Attendu([string]$titre, $res, [int]$code, [string]$motif = $null) {
    $script:n++
    $ok = ($res.code -eq $code) -and ((-not $motif) -or ($res.err -match $motif))
    if ($ok) { Write-Host ("  OK   " + $titre) } else { $script:ko++; Write-Host ("  KO   " + $titre + " (code " + $res.code + " attendu " + $code + ") " + $res.err) }
}
function Decisions() { Get-Content -LiteralPath (Join-Path $essai 'decisions.jsonl') -Encoding UTF8 | ForEach-Object { $_ | ConvertFrom-Json } }
function Agent([string]$type, [string]$desc, [string]$prompt, [string]$sid = 'sess-A', [string]$name = $null, [string]$agentId = $null) {
    $ti = [ordered]@{ subagent_type = $type; description = $desc; prompt = $prompt }
    if ($name) { $ti.name = $name }
    $p = [ordered]@{ session_id = $sid; prompt_id = 'p1'; hook_event_name = 'PreToolUse'; tool_name = 'Agent'; tool_input = $ti }
    if ($agentId) { $p.agent_id = $agentId }
    return $p
}

Write-Host "Workflow (comportement historique, bloquant)"
Budget 'journal'
Attendu 'workflow par name seul : refuse' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ name = 'deep-research' } }) 2 'aveugle'
Attendu 'reprise resumeFromRunId : passe' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ resumeFromRunId = 'r1' } }) 0
Attendu 'script sans declaration : refuse' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ script = "export const meta = {}`nagent('x')" } }) 2 'aucune declaration'
Attendu 'declaration 12 : passe' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ script = "// agents-max: 12`nagent('x')" } }) 0
Attendu 'declaration 13 : refuse' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ script = "// agents-max: 13`nagent('x')" } }) 2 'plafond 12'
Attendu 'declaration 13 avec user-ok : passe' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ script = "// agents-max: 13`n// agents-max-user-ok`nagent('x')" } }) 0

Write-Host "Agent, mode bloquer (simultanes = 3)"
Budget 'bloquer' 3
Attendu 'agent 1 : passe' (Lancer 'pre' (Agent 'Explore' 'a' 'cherche a')) 0
Attendu 'agent 2 : passe' (Lancer 'pre' (Agent 'Explore' 'b' 'cherche b')) 0
Attendu 'agent identique a 2 : refuse' (Lancer 'pre' (Agent 'Explore' 'b' 'cherche b')) 2 'identique'
Attendu 'agent 3 : passe' (Lancer 'pre' (Agent 'general-purpose' 'c' 'fais c')) 0
Attendu 'agent 4 : refuse (plafond simultanes)' (Lancer 'pre' (Agent 'Explore' 'd' 'cherche d')) 2 'plafond simultanes'
Attendu 'autre session : passe (etat par session)' (Lancer 'pre' (Agent 'Explore' 'd' 'cherche d' 'sess-B')) 0
Attendu 'post marque la fin de l agent b' (Lancer 'post' @{ session_id = 'sess-A'; tool_name = 'Agent'; tool_input = @{ subagent_type = 'Explore'; description = 'b'; prompt = 'cherche b' }; tool_response = @{ status = 'completed'; agentId = 'a-b' } }) 0
Attendu 'agent 4 apres une fin : passe' (Lancer 'pre' (Agent 'Explore' 'd' 'cherche d')) 0
Attendu 'post arriere-plan pose agent_id sans finir' (Lancer 'post' @{ session_id = 'sess-A'; tool_name = 'Agent'; tool_input = @{ subagent_type = 'Explore'; description = 'd'; prompt = 'cherche d' }; tool_response = @{ status = 'async_launched'; agentId = 'a-d' } }) 0
Attendu 'agent 5 : refuse (toujours 3 vivants)' (Lancer 'pre' (Agent 'Explore' 'e' 'cherche e')) 2 'plafond simultanes'
Attendu 'SubagentStop de a-d libere une place' (Lancer 'stop' @{ session_id = 'sess-A'; hook_event_name = 'SubagentStop'; agent_id = 'a-d'; agent_type = 'Explore' }) 0
Attendu 'agent 5 apres stop : passe' (Lancer 'pre' (Agent 'Explore' 'e' 'cherche e')) 0

Write-Host "Playwright et roles"
Budget 'bloquer' 10
$sidPw = 'sess-PW'
Attendu 'critique Playwright 1 : passe' (Lancer 'pre' (Agent 'cliff-stack:independent-critic' 'c1' 'refute x' $sidPw)) 0
Attendu 'critique Playwright 2 : refuse' (Lancer 'pre' (Agent 'cliff-stack:independent-critic' 'c2' 'refute y' $sidPw)) 2 'Playwright'
Attendu 'framing-critic (sans Playwright) : passe' (Lancer 'pre' (Agent 'cliff-stack:framing-critic' 'f' 'cadre z' $sidPw)) 0
$d = Decisions | Where-Object { $_.session_id -eq $sidPw -and $_.tool -eq 'Agent' }
$script:n++; if (($d | Where-Object { $_.type -eq 'cliff-stack:independent-critic' } | Select-Object -First 1).role -eq 'lecteur' -and ($d | Select-Object -First 1).playwright -eq $true) { Write-Host '  OK   independent-critic classe lecteur Playwright' } else { $script:ko++; Write-Host '  KO   classement independent-critic' }
$script:n++; if (($d | Where-Object { $_.type -eq 'cliff-stack:framing-critic' }).role -eq 'lecteur') { Write-Host '  OK   framing-critic classe lecteur' } else { $script:ko++; Write-Host '  KO   classement framing-critic' }
$dg = Decisions | Where-Object { $_.session_id -eq 'sess-A' -and $_.type -eq 'general-purpose' } | Select-Object -First 1
$script:n++; if ($dg.role -eq 'lecteur') { Write-Host '  OK   general-purpose hors plan classe lecteur' } else { $script:ko++; Write-Host '  KO   general-purpose hors plan' }
Attendu 'general-purpose dans un plan : passe, releve plan et zone' (Lancer 'pre' (Agent 'general-purpose' 'gp' "plan: C:\x\plan.json`nzone: z1`nfais" 'sess-PLAN')) 0
$dp = Decisions | Where-Object { $_.session_id -eq 'sess-PLAN' } | Select-Object -First 1
$script:n++; if ($dp.role -eq 'ecrivain' -and $dp.zone -eq 'z1' -and $dp.plan -eq 'C:\x\plan.json') { Write-Host '  OK   general-purpose dans un plan classe ecrivain, plan et zone releves' } else { $script:ko++; Write-Host "  KO   plan/zone : $($dp | ConvertTo-Json -Compress)" }

Write-Host "Mode journal : rien n'est refuse, la decision est journalisee"
Budget 'journal' 1
$sidJ = 'sess-J'
Attendu 'agent 1 : passe' (Lancer 'pre' (Agent 'Explore' 'j1' 'x' $sidJ)) 0
Attendu 'agent 2 au-dela du plafond : passe quand meme' (Lancer 'pre' (Agent 'Explore' 'j2' 'y' $sidJ)) 0
$dj = Decisions | Where-Object { $_.session_id -eq $sidJ } | Select-Object -Last 1
$script:n++; if ($dj.decision -eq 'aurait_refuse' -and $dj.raison -match 'plafond simultanes') { Write-Host '  OK   decision aurait_refuse journalisee' } else { $script:ko++; Write-Host "  KO   journal : $($dj | ConvertTo-Json -Compress)" }
$script:n++; if (@($dj.cles_tool_input) -contains 'subagent_type') { Write-Host '  OK   cles de tool_input relevees (mesure M4)' } else { $script:ko++; Write-Host '  KO   cles_tool_input' }

Write-Host "Regles P2 : seulement avec le flag"
Budget 'journal' 10
Attendu 'name sans flag : passe (sous-agent adressable)' (Lancer 'pre' (Agent 'Explore' 'n' 'x' 'sess-N' 'chercheur')) 0
$env:CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS = '1'
Attendu 'name avec flag depuis le principal : refuse meme en mode journal' (Lancer 'pre' (Agent 'Explore' 'n2' 'x' 'sess-N' 'chercheur2')) 2 'etape 4'
Attendu 'name avec flag depuis un sous-agent : refuse' (Lancer 'pre' (Agent 'Explore' 'n3' 'x' 'sess-N' 'chercheur3' 'a-sub')) 2 'sous-agent ne cree pas'
Attendu 'sans name avec flag : passe' (Lancer 'pre' (Agent 'Explore' 'n4' 'x' 'sess-N')) 0
$env:CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS = $null

Write-Host "Surcharge et robustesse"
Budget 'bloquer' 1
$env:CLIFF_EQUIPE_SIMULTANES = '2'
Attendu 'surcharge simultanes=2, agent 1 : passe' (Lancer 'pre' (Agent 'Explore' 's1' 'x' 'sess-S')) 0
Attendu 'surcharge simultanes=2, agent 2 : passe' (Lancer 'pre' (Agent 'Explore' 's2' 'x' 'sess-S')) 0
Attendu 'surcharge simultanes=2, agent 3 : refuse' (Lancer 'pre' (Agent 'Explore' 's3' 'x' 'sess-S')) 2 'plafond'
$env:CLIFF_EQUIPE_SIMULTANES = '99'
Attendu 'surcharge 99 bornee a 20 : agent 3 passe' (Lancer 'pre' (Agent 'Explore' 's3' 'x' 'sess-S')) 0
$env:CLIFF_EQUIPE_SIMULTANES = $null
Set-Content -LiteralPath $env:CLIFF_EQUIPE_BUDGET -Value '{ pas du json' -Encoding UTF8
Attendu 'budget illisible : Agent passe' (Lancer 'pre' (Agent 'Explore' 'r' 'x' 'sess-R')) 0
Attendu 'budget illisible : Workflow garde le plafond 12' (Lancer 'pre' @{ session_id = 's'; tool_name = 'Workflow'; tool_input = @{ script = "// agents-max: 13`nagent('x')" } }) 2 'plafond 12'
Attendu 'stdin vide : passe' (Lancer 'pre' $null) 0
Attendu 'SendMessage : passe et journalise' (Lancer 'pre' @{ session_id = 'sess-A'; tool_name = 'SendMessage'; tool_input = @{ to = 'a-b'; message = 'suite' } }) 0
$dm = Decisions | Where-Object { $_.tool -eq 'SendMessage' } | Select-Object -Last 1
$script:n++; if ($dm.reprise -eq $true) { Write-Host '  OK   SendMessage vers un agent fini releve comme reprise' } else { $script:ko++; Write-Host "  KO   reprise : $($dm | ConvertTo-Json -Compress)" }

Write-Host "Emplacement des journaux : dans le projet, jamais dans ~\.claude"
Budget 'bloquer' 1
$env:CLIFF_EQUIPE_LOG_DIR = $null
$env:CLAUDE_PROJECT_DIR = $null
Attendu 'sans projet ni cwd : passe sans compter' (Lancer 'pre' (Agent 'Explore' 'np' 'x' 'sess-NP')) 0
Attendu 'sans projet ni cwd, 2e agent : passe aussi (rien compte)' (Lancer 'pre' (Agent 'Explore' 'np2' 'x' 'sess-NP')) 0
$faux = Join-Path $essai 'projet-fictif'
New-Item -ItemType Directory -Force -Path $faux | Out-Null
$pc = Agent 'Explore' 'cw' 'x' 'sess-CW'; $pc.cwd = $faux
Attendu 'projet par cwd : passe et cree .claude\equipe-log' (Lancer 'pre' $pc) 0
$script:n++; if ((Test-Path (Join-Path $faux '.claude\equipe-log\decisions.jsonl')) -and ((Get-Content (Join-Path $faux '.claude\equipe-log\.gitignore')) -eq '*')) { Write-Host '  OK   journal dans <projet>\.claude\equipe-log, auto-ignore par git' } else { $script:ko++; Write-Host '  KO   emplacement du journal' }
$pc2 = Agent 'Explore' 'cw2' 'y' 'sess-CW'; $pc2.cwd = $faux
Attendu 'projet par cwd, 2e agent : refuse (plafond 1, etat dans le projet)' (Lancer 'pre' $pc2) 2 'plafond'
$env:CLAUDE_PROJECT_DIR = $faux
$pc3 = Agent 'Explore' 'cw3' 'z' 'sess-CW'; $pc3.cwd = 'C:\ailleurs'
Attendu 'CLAUDE_PROJECT_DIR prime sur cwd : meme etat, refuse' (Lancer 'pre' $pc3) 2 'plafond'
$env:CLAUDE_PROJECT_DIR = $projetDirInitial
$env:CLIFF_EQUIPE_LOG_DIR = $essai

Write-Host "log-agents.ps1"
$payload = @{ session_id = 'sess-L'; cwd = 'C:\p'; agent_id = 'a-l'; agent_type = 'cliff-stack:framing-critic'; stop_hook_active = $false; last_assistant_message = ('x' * 500); background_tasks = @(@{ id = 1 }) } | ConvertTo-Json -Depth 4 -Compress
$psi = New-Object System.Diagnostics.ProcessStartInfo 'powershell.exe', "-NoProfile -ExecutionPolicy Bypass -File `"$log`""
$psi.RedirectStandardInput = $true; $psi.UseShellExecute = $false
$proc = [System.Diagnostics.Process]::Start($psi)
$octets = [System.Text.Encoding]::UTF8.GetBytes([string]$payload)
$proc.StandardInput.BaseStream.Write($octets, 0, $octets.Length); $proc.StandardInput.Close(); $proc.WaitForExit()
$la = Get-Content -LiteralPath (Join-Path $essai 'agents.jsonl') -Encoding UTF8 | Select-Object -Last 1 | ConvertFrom-Json
$script:n++; if ($proc.ExitCode -eq 0 -and $la.agent_type -eq 'cliff-stack:framing-critic' -and $la.excerpt.Length -eq 400 -and $la.fond -eq 1) { Write-Host '  OK   agents.jsonl : ligne complete, extrait borne a 400, fond compte' } else { $script:ko++; Write-Host "  KO   agents.jsonl : $($la | ConvertTo-Json -Compress)" }

Write-Host ""
Write-Host ("Essais : " + $script:n + ", echecs : " + $script:ko + ", dossier : " + $essai)
if ($script:ko -gt 0) { exit 1 }
Remove-Item -Recurse -Force -LiteralPath $essai
exit 0
