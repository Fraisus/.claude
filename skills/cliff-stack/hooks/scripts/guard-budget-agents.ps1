# guard-budget-agents.ps1 — Garde unique du budget d'agents (succède à guard-workflow-budget.ps1).
# Source des plafonds : ../budget-agents.json (une seule source ; tout chiffre ailleurs y renvoie).
# Conception et mesure : ~/.claude/suivi/equipes-agents/07-synthese.md.
#
# Un script, plusieurs événements, choisis par argument :
#   pre   PreToolUse  Workflow | Agent | SendMessage
#   post  PostToolUse Agent
#   stop  SubagentStop
#
# Workflow (comportement historique conservé, bloquant quel que soit le mode) :
#   - lancé par `name:` seul → refus (script jamais lu, fan-out inconnu) ;
#   - script sans `// agents-max: N` → refus ;
#   - N > total_par_plan sans `// agents-max-user-ok` → refus ;
#   - resumeFromRunId, scriptPath introuvable, erreur de parsing → passe.
#   Leçon du 19/07/2026 : un deep-research lancé par `name:` a déclenché 106 agents (~4,5M tokens).
#
# Agent (nouveau) : compte les agents vivants de la session (état ~/.claude/.equipe-log/etat/<session>.json,
#   sous mutex, TTL ttl_agent_min), classe le rôle (écrivain, lecteur, Playwright) d'après la définition de
#   l'agent, et applique : simultanes, playwright_simultanes, empreinte identique vivante, et les règles P2
#   (seulement si CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1 est dans l'environnement : sans le flag, un `name`
#   n'est qu'un sous-agent adressable et passe). En mode `journal`, rien n'est refusé sauf les règles P2 :
#   la décision est écrite `aurait_refuse` dans decisions.jsonl. Les règles de plan (écrivains par zone,
#   total par plan) viennent à l'étape 2 ; les lignes `plan:` et `zone:` du brief sont déjà relevées.
#
# Chaque décision laisse une ligne dans decisions.jsonl, avec les clés réellement reçues (tool_input,
# tool_response) : c'est la mesure des champs non documentés (M4, M5, M7).
#
# Où vivent l'état et les journaux : dans le PROJET, jamais dans ~/.claude (la configuration globale reste
# globale et ne reçoit aucune donnée d'un projet). Dossier <projet>/.claude/equipe-log/ (etat/, decisions.jsonl,
# agents.jsonl), projet = CLAUDE_PROJECT_DIR, sinon le cwd du payload ; le dossier se crée avec un .gitignore
# « * » qui l'exclut du dépôt sans toucher au .gitignore du projet. Sans projet résolvable, la garde ne compte
# rien et laisse passer.
#
# Robuste : toute erreur interne laisse passer (exit 0) et se journalise ; un faux positif figerait tout.
# Variables d'essai : CLIFF_EQUIPE_LOG_DIR (dossier des journaux et de l'état), CLIFF_EQUIPE_BUDGET (fichier
# de budget). Surcharges d'un plafond numérique : CLIFF_EQUIPE_<CLE> (ex. CLIFF_EQUIPE_SIMULTANES), bornées
# par plafond_harness.defaut_documente ; à poser seulement dans ~/.claude/settings.local.json (règle ask).

param([string]$Mode = 'pre')

$ErrorActionPreference = 'SilentlyContinue'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)

$Utf8 = New-Object System.Text.UTF8Encoding($false)
$script:LogDir = $null
$script:BudgetPath = if ($env:CLIFF_EQUIPE_BUDGET) { $env:CLIFF_EQUIPE_BUDGET } else { Join-Path $PSScriptRoot '..\budget-agents.json' }
$script:Sid = 'sans-session'
$script:Now = [System.DateTime]::UtcNow

# Dossier d'état et de journaux du projet courant ; $null sans projet résolvable.
function Resolve-LogDir($payload) {
    if ($env:CLIFF_EQUIPE_LOG_DIR) { return $env:CLIFF_EQUIPE_LOG_DIR }
    $projet = $env:CLAUDE_PROJECT_DIR
    if (-not $projet -and $payload -and $payload.cwd) { $projet = [string]$payload.cwd }
    if (-not $projet -or -not (Test-Path -LiteralPath $projet)) { return $null }
    $dir = Join-Path $projet '.claude\equipe-log'
    if (-not (Test-Path -LiteralPath $dir)) {
        [System.IO.Directory]::CreateDirectory($dir) | Out-Null
        [System.IO.File]::WriteAllText((Join-Path $dir '.gitignore'), "*`n", $Utf8)
    }
    return $dir
}

function Append-Jsonl([string]$name, $entry) {
    if (-not $script:LogDir) { return }
    try {
        [System.IO.Directory]::CreateDirectory($script:LogDir) | Out-Null
        $line = ($entry | ConvertTo-Json -Compress -Depth 6) + "`n"
        [System.IO.File]::AppendAllText((Join-Path $script:LogDir $name), $line, $Utf8)
    } catch { }
}

function Decide([string]$tool, [hashtable]$champs) {
    $e = [ordered]@{ ts = $script:Now.ToString('o'); session_id = $script:Sid; mode = $Mode; tool = $tool }
    foreach ($k in $champs.Keys) { $e[$k] = $champs[$k] }
    Append-Jsonl 'decisions.jsonl' $e
}

function Read-Budget {
    $b = $null
    try { $b = [System.IO.File]::ReadAllText($script:BudgetPath, $Utf8) | ConvertFrom-Json } catch { $b = $null }
    if (-not $b) { return $null }
    # Surcharges CLIFF_EQUIPE_<CLE> sur les plafonds numériques de premier niveau, bornées par le harness.
    $plafond = 20
    try { if ($b.plafond_harness.defaut_documente) { $plafond = [int]$b.plafond_harness.defaut_documente } } catch { }
    foreach ($prop in @($b.PSObject.Properties)) {
        if ($prop.Value -isnot [int] -and $prop.Value -isnot [long] -and $prop.Value -isnot [double]) { continue }
        $env_name = 'CLIFF_EQUIPE_' + $prop.Name.ToUpperInvariant()
        $v = [System.Environment]::GetEnvironmentVariable($env_name)
        if ($v -and ($v -match '^\d+$')) {
            $n = [int]$v
            if ($n -gt $plafond) { $n = $plafond }
            $b.($prop.Name) = $n
        }
    }
    return $b
}

function Get-Empreinte($ti) {
    $s = [string]$ti.subagent_type + "`n" + [string]$ti.description + "`n" + [string]$ti.prompt
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $h = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($s))
    return (($h | ForEach-Object { $_.ToString('x2') }) -join '').Substring(0, 16)
}

# Rôle d'après la définition de l'agent : écrivain si sa définition est trouvée et que `tools:` est absent
# (hérite de tout) ou contient Edit ou Write ; Playwright si `tools:` cite mcp__playwright ; les types
# intégrés sans fichier sont lecteurs ; general-purpose et claude ne sont écrivains que dans un plan ;
# un type introuvable est lecteur (journalisé type_inconnu).
function Classer-Role([string]$type, [bool]$dansPlan) {
    $r = @{ ecrit = $false; playwright = $false; source = 'inconnu' }
    if (-not $type) { return $r }
    $nu = $type -replace '^[^:]+:', ''
    if ($nu -in @('Explore', 'Plan', 'claude-code-guide', 'statusline-setup')) { $r.source = 'integre-lecteur'; return $r }
    if ($nu -in @('general-purpose', 'claude')) { $r.ecrit = $dansPlan; $r.source = 'integre-generaliste'; return $r }
    $dossiers = @()
    if ($env:CLAUDE_PROJECT_DIR) { $dossiers += (Join-Path $env:CLAUDE_PROJECT_DIR '.claude\agents') }
    $dossiers += (Join-Path $PSScriptRoot '..\..\agents')
    $dossiers += (Join-Path $HOME '.claude\agents')
    foreach ($d in $dossiers) {
        $f = Join-Path $d ($nu + '.md')
        if (-not (Test-Path -LiteralPath $f)) { continue }
        $txt = [System.IO.File]::ReadAllText($f, $Utf8)
        $fm = $null
        if ($txt -match '(?s)^﻿?---\s*\r?\n(.*?)\r?\n---') { $fm = $Matches[1] }
        $tools = $null
        if ($fm -and ($fm -match '(?im)^tools\s*:\s*(.+)$')) { $tools = $Matches[1] }
        if (-not $tools) { $r.ecrit = $true } else { $r.ecrit = [bool]($tools -match '\b(Edit|Write|MultiEdit|NotebookEdit)\b') }
        if ($tools -and ($tools -match 'mcp__playwright')) { $r.playwright = $true }
        $r.source = $f
        return $r
    }
    return $r
}

# État de session : liste des agents lancés, sous mutex, écriture atomique, purge TTL.
function Open-Etat {
    $dir = Join-Path $script:LogDir 'etat'
    [System.IO.Directory]::CreateDirectory($dir) | Out-Null
    $p = Join-Path $dir ($script:Sid + '.json')
    $etat = $null
    if (Test-Path -LiteralPath $p) { try { $etat = [System.IO.File]::ReadAllText($p, $Utf8) | ConvertFrom-Json } catch { $etat = $null } }
    if (-not $etat) { $etat = [pscustomobject]@{ session_id = $script:Sid; agents = @() } }
    if ($null -eq $etat.agents) { $etat | Add-Member -NotePropertyName agents -NotePropertyValue @() -Force }
    $etat.agents = @($etat.agents)
    return @{ chemin = $p; etat = $etat }
}

function Save-Etat($h) {
    $tmp = $h.chemin + '.tmp'
    [System.IO.File]::WriteAllText($tmp, ($h.etat | ConvertTo-Json -Depth 6), $Utf8)
    Move-Item -LiteralPath $tmp -Destination $h.chemin -Force
    # Purge des états de session de plus de 7 jours.
    try {
        Get-ChildItem -LiteralPath (Split-Path $h.chemin) -Filter '*.json' |
            Where-Object { $_.LastWriteTimeUtc -lt $script:Now.AddDays(-7) } |
            Remove-Item -Force -ErrorAction SilentlyContinue
    } catch { }
}

function Purge-Perimes($etat, [int]$ttlMin) {
    foreach ($a in $etat.agents) {
        if ($a.fini) { continue }
        $lance = [System.DateTime]::Parse($a.lance, $null, [System.Globalization.DateTimeStyles]::RoundtripKind).ToUniversalTime()
        if ($lance -lt $script:Now.AddMinutes(-1 * $ttlMin)) { $a.fini = 'perime' }
    }
}

function Refuser([string]$tool, [string]$raison) {
    [Console]::Error.WriteLine("BLOQUE par guard-budget-agents ($tool) : $raison")
    [Console]::Error.WriteLine("Plafonds : skills/cliff-stack/hooks/budget-agents.json. Ne contourne pas ce garde : rapporte le refus tel quel, attends la fin d'un agent, ou demande a l'utilisateur.")
    exit 2
}

# ---------------------------------------------------------------------------------------------------
try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    # Un BOM en tête (flux relayé par un autre PowerShell) ferait échouer ConvertFrom-Json.
    $raw = $raw.TrimStart([char]0xFEFF)
    if (-not $raw.Trim()) { exit 0 }
    $p = $raw | ConvertFrom-Json
    if ($p.session_id) { $script:Sid = [string]$p.session_id }
    $tool = [string]$p.tool_name
    $ti = $p.tool_input
    $script:LogDir = Resolve-LogDir $p
    $B = Read-Budget
    $modeBudget = if ($B -and $B.mode) { [string]$B.mode } else { 'journal' }
    $flagP2 = ([System.Environment]::GetEnvironmentVariable('CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS') -eq '1')

    # ------------------------------------------------------------------ Workflow (historique) ------
    if ($Mode -eq 'pre' -and $tool -eq 'Workflow') {
        $MAX = 12
        if ($B -and $B.total_par_plan) { $MAX = [int]$B.total_par_plan }
        if (-not $ti) { exit 0 }
        if ($ti.resumeFromRunId) { Decide 'Workflow' @{ decision = 'passe'; raison = 'reprise' }; exit 0 }
        # Un plan d'équipe (étape 2) peut seulement abaisser le plafond.
        try {
            if ($ti.args -and $ti.args.plan -and (Test-Path -LiteralPath ([string]$ti.args.plan))) {
                $plan = [System.IO.File]::ReadAllText([string]$ti.args.plan, $Utf8) | ConvertFrom-Json
                if ($plan.budget.agents_total -and ([int]$plan.budget.agents_total -lt $MAX)) { $MAX = [int]$plan.budget.agents_total }
            }
        } catch { }
        $scriptText = $null
        if ($ti.scriptPath) {
            if (Test-Path -LiteralPath $ti.scriptPath) { $scriptText = [System.IO.File]::ReadAllText($ti.scriptPath) } else { exit 0 }
        } elseif ($ti.script) { $scriptText = [string]$ti.script }
        if (-not $scriptText) {
            if ($ti.name) {
                Decide 'Workflow' @{ decision = 'refuse'; raison = 'name_sans_script'; name = [string]$ti.name }
                [Console]::Error.WriteLine("BLOQUE par guard-budget-agents : workflow nomme '$($ti.name)' lance a l'aveugle (script jamais lu, fan-out inconnu).")
                [Console]::Error.WriteLine("Procedure obligatoire : (1) recupere le script du workflow (fichier .claude/workflows/, ou re-auteur un script equivalent d'apres les phases du skill) ; (2) CALCULE son fan-out mecanique (somme des agent() par phase, ex. recherches x fetch x claims x votes) ; (3) si > $MAX, EDITE le script pour reduire ; (4) declare '// agents-max: N' en tete de script et relance via script:/scriptPath:. Un plafond en prose dans args ne borne RIEN. Ne contourne pas ce garde.")
                exit 2
            }
            exit 0
        }
        if ($scriptText -match '(?im)^\s*//\s*agents-max\s*:\s*(\d+)') {
            $declared = [int]$Matches[1]
            if ($declared -le $MAX) { Decide 'Workflow' @{ decision = 'passe'; declare = $declared; plafond = $MAX }; exit 0 }
            if ($scriptText -match '(?im)^\s*//\s*agents-max-user-ok\b') { Decide 'Workflow' @{ decision = 'passe'; raison = 'user_ok'; declare = $declared; plafond = $MAX }; exit 0 }
            Decide 'Workflow' @{ decision = 'refuse'; raison = 'au_dela_du_plafond'; declare = $declared; plafond = $MAX }
            [Console]::Error.WriteLine("BLOQUE par guard-budget-agents : fan-out declare agents-max: $declared > plafond $MAX (budget-agents.json, total_par_plan).")
            [Console]::Error.WriteLine("Deux issues : (a) reduis le fan-out du script sous $MAX (moins de sources/claims/votes, ou plusieurs runs sequentiels cibles) ; (b) si l'echelle est vraiment necessaire, demande une validation EXPLICITE a l'utilisateur via AskUserQuestion (cout estime inclus), puis ajoute '// agents-max-user-ok' sous la declaration. Ne contourne pas ce garde.")
            exit 2
        }
        Decide 'Workflow' @{ decision = 'refuse'; raison = 'sans_declaration' }
        [Console]::Error.WriteLine("BLOQUE par guard-budget-agents : aucune declaration '// agents-max: N' en tete de script.")
        [Console]::Error.WriteLine("Avant de lancer un Workflow : LIS le script, CALCULE son fan-out mecanique (somme des agent() par phase, multiplicateurs inclus : items x stages x votes), puis declare '// agents-max: N' en tete de script. Plafond : $MAX (au-dela, validation explicite requise + '// agents-max-user-ok'). Ne contourne pas ce garde.")
        exit 2
    }

    # Sans projet résolvable, aucun état ni journal : les règles d'Agent ne s'appliquent pas (on laisse passer).
    if (-not $script:LogDir -and $tool -ne 'Workflow') { exit 0 }

    # ------------------------------------------------------------------ Agent, lancement ----------
    if ($Mode -eq 'pre' -and $tool -eq 'Agent') {
        if (-not $ti) { exit 0 }
        $cles = @($ti.PSObject.Properties.Name)
        $type = [string]$ti.subagent_type
        $prompt = [string]$ti.prompt
        $planChemin = $null; $zone = $null
        if ($prompt -match '(?m)^plan:\s*(\S.*?)\s*$') { $planChemin = $Matches[1] }
        if ($prompt -match '(?m)^zone:\s*(\S.*?)\s*$') { $zone = $Matches[1] }
        $role = Classer-Role $type ([bool]$planChemin)
        $nom = if ($cles -contains 'name') { [string]$ti.name } else { $null }
        $lanceur = if ($p.agent_id) { [string]$p.agent_id } else { 'principal' }
        $empreinte = Get-Empreinte $ti

        $mutex = New-Object System.Threading.Mutex($false, ('Global\cliff-equipe-' + $script:Sid))
        $pris = $false
        try { $pris = $mutex.WaitOne(3000) } catch { $pris = $false }
        try {
            $h = Open-Etat
            $ttl = if ($B -and $B.ttl_agent_min) { [int]$B.ttl_agent_min } else { 60 }
            Purge-Perimes $h.etat $ttl
            $vivants = @($h.etat.agents | Where-Object { -not $_.fini })
            $simult = if ($B -and $B.simultanes) { [int]$B.simultanes } else { 5 }
            $pw = if ($B -and $B.playwright_simultanes) { [int]$B.playwright_simultanes } else { 1 }
            $vivantsPw = @($vivants | Where-Object { $_.playwright }).Count

            $raison = $null; $p2 = $false
            if ($flagP2 -and $nom -and ($lanceur -ne 'principal')) { $raison = 'un sous-agent ne cree pas de coequipier : rends un signal au thread principal'; $p2 = $true }
            elseif ($flagP2 -and $nom) { $raison = "coequipier (name='$nom') refuse : la garde ne prend pas encore en charge le palier P2 (etape 4) ; lance sans name"; $p2 = $true }
            elseif ($vivants.Count -ge $simult) { $raison = "plafond simultanes atteint ($($vivants.Count)/$simult agents vivants) : attends la fin d'un agent" }
            elseif ($role.playwright -and ($vivantsPw -ge $pw)) { $raison = "un seul agent Playwright a la fois ($vivantsPw vivant) : la session navigateur est partagee" }
            elseif (@($vivants | Where-Object { $_.empreinte -eq $empreinte }).Count -gt 0) { $raison = 'lancement identique (meme type, description et prompt) deja en cours' }

            $decision = 'passe'
            if ($raison) { if ($p2 -or ($modeBudget -eq 'bloquer')) { $decision = 'refuse' } else { $decision = 'aurait_refuse' } }

            if ($decision -ne 'refuse') {
                $h.etat.agents += [pscustomobject]@{
                    empreinte = $empreinte; type = $type; nom = $nom; ecrit = $role.ecrit; playwright = $role.playwright
                    plan = $planChemin; zone = $zone; lance = $script:Now.ToString('o'); prompt_id = [string]$p.prompt_id
                    lanceur = $lanceur; agent_id = $null; fini = $null
                }
                Save-Etat $h
            }
            Decide 'Agent' @{
                decision = $decision; raison = $raison; type = $type; role = $(if ($role.ecrit) { 'ecrivain' } else { 'lecteur' })
                role_source = $role.source; playwright = $role.playwright; name = $nom; lanceur = $lanceur; plan = $planChemin; zone = $zone
                vivants = $vivants.Count; simultanes = $simult; mode_budget = $modeBudget; flag_p2 = $flagP2
                cles_tool_input = $cles; agent_id_present = [bool]$p.agent_id; prompt_id_present = [bool]$p.prompt_id; empreinte = $empreinte
            }
            if ($decision -eq 'refuse') { Refuser 'Agent' $raison }
        } finally { if ($pris) { try { $mutex.ReleaseMutex() } catch { } } ; $mutex.Dispose() }
        exit 0
    }

    # ------------------------------------------------------------------ SendMessage (journal) -----
    if ($Mode -eq 'pre' -and $tool -eq 'SendMessage') {
        $cible = if ($ti) { [string]$ti.to } else { $null }
        $reprise = $false
        try {
            $h = Open-Etat
            $reprise = @($h.etat.agents | Where-Object { $_.fini -and (($_.nom -and $_.nom -eq $cible) -or ($_.agent_id -and $_.agent_id -eq $cible)) }).Count -gt 0
        } catch { }
        Decide 'SendMessage' @{ decision = 'passe'; to = $cible; reprise = $reprise; lanceur = $(if ($p.agent_id) { [string]$p.agent_id } else { 'principal' }) }
        exit 0
    }

    # ------------------------------------------------------------------ Agent, résultat -----------
    if ($Mode -eq 'post' -and $tool -eq 'Agent') {
        $tr = $p.tool_response
        $clesTr = if ($tr) { @($tr.PSObject.Properties.Name) } else { @() }
        $empreinte = if ($ti) { Get-Empreinte $ti } else { $null }
        $statut = if ($tr) { [string]$tr.status } else { $null }
        $aid = if ($tr -and $tr.agentId) { [string]$tr.agentId } else { $null }
        $mutex = New-Object System.Threading.Mutex($false, ('Global\cliff-equipe-' + $script:Sid))
        $pris = $false
        try { $pris = $mutex.WaitOne(3000) } catch { $pris = $false }
        try {
            $h = Open-Etat
            $cible = $h.etat.agents | Where-Object { $_.empreinte -eq $empreinte -and -not $_.fini } | Select-Object -Last 1
            if ($cible) {
                if ($aid) { $cible.agent_id = $aid }
                if ($statut -eq 'completed') { $cible.fini = $script:Now.ToString('o') }
                Save-Etat $h
            }
            Decide 'Agent' @{ decision = 'resultat'; statut = $statut; agent_id = $aid; retrouve = [bool]$cible; cles_tool_response = $clesTr; empreinte = $empreinte }
        } finally { if ($pris) { try { $mutex.ReleaseMutex() } catch { } } ; $mutex.Dispose() }
        exit 0
    }

    # ------------------------------------------------------------------ fin d'un sous-agent -------
    if ($Mode -eq 'stop') {
        $aid = [string]$p.agent_id
        $mutex = New-Object System.Threading.Mutex($false, ('Global\cliff-equipe-' + $script:Sid))
        $pris = $false
        try { $pris = $mutex.WaitOne(3000) } catch { $pris = $false }
        try {
            $h = Open-Etat
            $cible = $h.etat.agents | Where-Object { $_.agent_id -eq $aid -and -not $_.fini } | Select-Object -Last 1
            if ($cible) { $cible.fini = $script:Now.ToString('o'); Save-Etat $h }
            Decide 'SubagentStop' @{ decision = 'fin'; agent_id = $aid; agent_type = [string]$p.agent_type; retrouve = [bool]$cible; vivants = @($h.etat.agents | Where-Object { -not $_.fini }).Count }
        } finally { if ($pris) { try { $mutex.ReleaseMutex() } catch { } } ; $mutex.Dispose() }
        exit 0
    }

    exit 0
} catch {
    try { Decide 'erreur' @{ decision = 'passe'; raison = ('erreur interne : ' + $_.Exception.Message) } } catch { }
    exit 0
}
exit 0
