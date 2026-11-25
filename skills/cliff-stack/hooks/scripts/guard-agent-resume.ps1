# guard-agent-resume.ps1 - hook PreToolUse (SendMessage) global, pour le modèle seul (jamais une question à l'utilisateur).
# Un sous-agent repris recharge tout son historique : la reprise est refusée quand son contexte réel (dernier tour de sa
# transcription, lu par agent-usage.mjs) dépasse contexte_agent_max moins contexte_palier (budget-agents.json), pour qu'il
# ne soit pas arrêté par guard-agent-context dès son premier outil ; « #reprise » dans le message passe outre (code à moitié
# écrit après une coupure). Contrat : skill equipe, section « Coût ». Rien à juger (pas un agent, fichier absent) -> exit 0.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
try {
    [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $data = $raw.TrimStart([char]0xFEFF) | ConvertFrom-Json
    $to = [string]$data.tool_input.to
    if ($to -notmatch '^a[0-9a-f]{12,}$') { exit 0 }
    if ([string]$data.tool_input.message -match '#reprise') { exit 0 }
    $usage = Join-Path $PSScriptRoot 'agent-usage.mjs'
    $out = & node $usage context --agent $to --transcript ([string]$data.transcript_path) | ConvertFrom-Json
    if (-not $out -or -not $out.contexte) { exit 0 }
    $seuil = [int]$out.max - [int]$out.step
    if ([int]$out.contexte -lt $seuil) { exit 0 }
    $k = [math]::Round([int]$out.contexte / 1000)
    $reason = "Agent $to : contexte de $k 000 jetons, au-delà de la reprise permise ($($seuil / 1000) 000, budget-agents.json). Lance un agent neuf avec sa passation ; « #reprise » dans le message seulement pour du code à moitié écrit après une coupure."
    @{ hookSpecificOutput = @{ hookEventName = 'PreToolUse'; permissionDecision = 'deny'; permissionDecisionReason = $reason } } |
        ConvertTo-Json -Compress -Depth 4
    exit 0
} catch { exit 0 }
