# guard-agent-resume.ps1 - hook PreToolUse (SendMessage) global, pour le modèle seul (jamais une question à l'utilisateur).
# Un sous-agent repris garde tout son historique : au-delà d'environ 150 000 jetons (transcription ≈ 10 octets/jeton),
# l'envoi est refusé avec sa raison, sauf si le message porte « #reprise » (code à moitié écrit après une coupure).
# Contrat : skill equipe, section « Coût ». Rien à juger (pas un agent de la session, fichier absent) -> exit 0.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$LIMIT = 1.5MB
try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $data = $raw | ConvertFrom-Json
    $to = [string]$data.tool_input.to
    if ($to -notmatch '^a[0-9a-f]{12,}$') { exit 0 }
    if ([string]$data.tool_input.message -match '#reprise') { exit 0 }
    $root = Join-Path $env:TEMP 'claude'
    if (-not (Test-Path -LiteralPath $root)) { exit 0 }
    $file = Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName (Join-Path $data.session_id (Join-Path 'tasks' "$to.output")) } |
        Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if (-not $file) { exit 0 }
    $size = (Get-Item -LiteralPath $file).Length
    if ($size -lt $LIMIT) { exit 0 }
    $tokens = [math]::Round($size / 10 / 1000) * 1000
    $reason = "Agent $to : environ $tokens jetons d'historique, tous rechargés à la reprise. Lance un agent neuf avec sa passation ; « #reprise » dans le message seulement pour du code à moitié écrit après une coupure."
    @{ hookSpecificOutput = @{ hookEventName = 'PreToolUse'; permissionDecision = 'deny'; permissionDecisionReason = $reason } } |
        ConvertTo-Json -Compress -Depth 4
    exit 0
} catch { exit 0 }
