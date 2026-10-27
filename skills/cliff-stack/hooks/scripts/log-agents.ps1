# log-agents.ps1 — Journalise la fin de tout sous-agent (hook SubagentStop). Succède à log-critic.ps1.
# Deux journaux :
#   - critics.jsonl, inchangé, dans ~\.claude\.critic-log\ : une ligne par critique terminé (agent_type
#     finissant par « independent-critic », nom nu ou préfixé par un plugin) ;
#   - agents.jsonl, nouveau, dans le PROJET : <projet>\.claude\equipe-log\ (projet = CLAUDE_PROJECT_DIR, sinon
#     le cwd du payload ; dossier auto-ignoré par git, créé par guard-budget-agents.ps1 ou ici) : une ligne par
#     sous-agent terminé, tout type, pour mesurer les équipes (rôles, nombre, blocages, durée par recoupement
#     avec decisions.jsonl). La configuration globale ne reçoit aucune donnée d'un projet.
# Champs d'entrée utilisés : session_id, cwd, agent_id, agent_type, stop_hook_active, last_assistant_message,
# background_tasks. Jamais de contenu au-delà d'un extrait de 400 caractères.
# Non bloquant, n'échoue jamais le hook (toujours exit 0). Variable d'essai : CLIFF_EQUIPE_LOG_DIR.

$ErrorActionPreference = 'SilentlyContinue'
try {
    # Le harness envoie de l'UTF-8 ; sans ceci PowerShell 5.1 lit en page OEM et mutile les accents
    [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $raw = $raw.TrimStart([char]0xFEFF)
    $payload = $raw | ConvertFrom-Json
    $type = [string]$payload.agent_type
    if (-not $type) { exit 0 }
    $utf8 = New-Object System.Text.UTF8Encoding($false)

    $msg = [string]$payload.last_assistant_message
    if ($msg.Length -gt 400) { $msg = $msg.Substring(0, 400) }
    $ts = [System.DateTime]::Now.ToString('o')

    if ($type -match 'independent-critic$') {
        $entry = [ordered]@{
            ts         = $ts
            session_id = [string]$payload.session_id
            cwd        = [string]$payload.cwd
            agent_type = $type
            excerpt    = $msg
        }
        $dir = Join-Path $HOME '.claude\.critic-log'
        [System.IO.Directory]::CreateDirectory($dir) | Out-Null
        [System.IO.File]::AppendAllText((Join-Path $dir 'critics.jsonl'), (($entry | ConvertTo-Json -Compress) + "`n"), $utf8)
    }

    $fond = 0
    try { if ($payload.background_tasks) { $fond = @($payload.background_tasks).Count } } catch { }
    $agent = [ordered]@{
        ts               = $ts
        session_id       = [string]$payload.session_id
        cwd              = [string]$payload.cwd
        agent_id         = [string]$payload.agent_id
        agent_type       = $type
        stop_hook_active = [bool]$payload.stop_hook_active
        fond             = $fond
        excerpt          = $msg
    }
    $dir2 = $env:CLIFF_EQUIPE_LOG_DIR
    if (-not $dir2) {
        $projet = $env:CLAUDE_PROJECT_DIR
        if (-not $projet -and $payload.cwd) { $projet = [string]$payload.cwd }
        if (-not $projet -or -not (Test-Path -LiteralPath $projet)) { exit 0 }
        $dir2 = Join-Path $projet '.claude\equipe-log'
        if (-not (Test-Path -LiteralPath $dir2)) {
            [System.IO.Directory]::CreateDirectory($dir2) | Out-Null
            [System.IO.File]::WriteAllText((Join-Path $dir2 '.gitignore'), "*`n", $utf8)
        }
    }
    [System.IO.Directory]::CreateDirectory($dir2) | Out-Null
    [System.IO.File]::AppendAllText((Join-Path $dir2 'agents.jsonl'), (($agent | ConvertTo-Json -Compress) + "`n"), $utf8)
} catch { }
exit 0
