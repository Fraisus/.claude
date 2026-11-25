# guard-git-destructive.ps1 — Barrière PreToolUse contre les commandes git DESTRUCTIVES
# pour le travail non commité. Force Claude à demander l'autorisation explicite de l'user
# avant tout revert (leçon : un `git checkout --` a effacé du travail non commité sans accord).
#
# Demande une validation humaine native (permissionDecision « ask ») pour :
#   - git checkout -- <path> / git checkout . / git checkout -f / git checkout <path>  (restaure, écrase les modifs)
#   - git restore <path>                                                                (idem, syntaxe moderne)
#   - git reset --hard                                                                  (jette working tree + index)
#   - git clean -f / -d / -x / -fd ...                                                  (supprime les fichiers non suivis)
#   - git stash drop / git stash clear                                                  (perte de stash)
#   - git checkout/switch -f vers une autre branche                                     (perte de modifs non commitées)
#
# Laisse passer (non destructif) :
#   status, diff, log, add, commit, fetch, pull, push, branch, checkout -b (création),
#   switch <branche> sans -f, stash (push), stash list/show/pop/apply.
#
# Lit le payload JSON sur stdin (tool_input.command). Silencieux et robuste : en cas de
# doute de parsing il NE bloque PAS (évite les faux positifs qui figeraient le travail légitime),
# mais matche largement les motifs destructifs connus.

$ErrorActionPreference = 'SilentlyContinue'
# Sans ceci PowerShell 5.1 écrit en page OEM et le harnais reçoit des accents abîmés.
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)

try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }

    $payload = $raw | ConvertFrom-Json
    $cmd = [string]$payload.tool_input.command
    if (-not $cmd) { exit 0 }

    # Retire les portions CITÉES (messages de commit -m "...", heredocs) AVANT analyse :
    # sinon un message qui mentionne "reset --hard" serait bloqué à tort (faux positif).
    $stripped = $cmd
    $stripped = $stripped -replace "(?s)<<'?EOF'?.*?EOF", ' '   # heredocs (<<EOF ... EOF)
    $stripped = $stripped -replace '"[^"]*"', ' '               # chaînes double-quote
    $stripped = $stripped -replace "'[^']*'", ' '               # chaînes simple-quote

    # Normalise : espaces multiples → simple, pour des regex stables
    $c = $stripped -replace '\s+', ' '

    # Un même appel peut chaîner plusieurs commandes (&&, ;, |). On teste chaque segment.
    $segments = $c -split '(?:&&|\|\||;|\|)'

    $blockedReason = $null

    # Ouvrier de zone (agents/ouvrier-zone.md) : liste fermée de sous-commandes, refus sans invite (aucun humain
    # n'attend un sous-agent). Type lu dans le payload, sinon dans le meta de sa transcription (agent-usage.mjs).
    # Chaque git de la commande BRUTE est lu (plusieurs lignes, sh -c "...", $(...)) ; un « git push » cité dans
    # un message de commit est donc refusé aussi : refus sûr, l'ouvrier reformule. Filet contre l'erreur d'un agent
    # de bonne foi, pas barrière : une sous-commande cachée (guillemets, variable, xargs, eval) échappe à une lecture
    # du texte ; ce qui interdit le push à l'ouvrier est son contrat (agents/ouvrier-zone.md).
    if ($payload.agent_id -and ($cmd -match '(?i)git')) {
        $type = [string]$payload.agent_type
        if (-not $type) {
            $meta = & node (Join-Path $PSScriptRoot 'agent-usage.mjs') meta --agent ([string]$payload.agent_id) --transcript ([string]$payload.transcript_path) | ConvertFrom-Json
            if ($meta) { $type = [string]$meta.agentType }
        }
        if (($type -replace '^[^:]+:', '') -eq 'ouvrier-zone') {
            $permis = '^(status|diff|log|show|add|commit|merge|branch|mv|rm|rev-parse|rev-list|merge-base|ls-files|ls-tree|blame|grep|show-ref|describe|cat-file|diff-tree|shortlog|name-rev|for-each-ref|help)$'
            $val = '(?:"[^"]*"|''[^'']*''|[^\s"'']+)'
            $opt = "(?:(?:-C|-c|--git-dir|--work-tree|--namespace|--config-env)\s+$val|--[\w-]+(?:=$val)?|-[A-Za-z]+)"
            $rx = "(?i)(?<![\w.-])[""']?git(?:\.exe)?[""']?((?:\s+$opt)*)\s+([a-z][\w-]*)([^\r\n;&|()$``]*)"
            foreach ($m in [regex]::Matches($cmd, $rx)) {
                $sous = $m.Groups[2].Value.ToLowerInvariant(); $reste = $m.Groups[3].Value
                # Lectures seules de sous-commandes qui savent aussi écrire ; branch : options groupées (-df) et
                # préfixes d'options longues (--dele) compris, comme le parseur de git les accepte.
                $lecture = (($sous -eq 'worktree') -and ($reste -match '^\s*list\b')) -or
                    (($sous -eq 'stash') -and ($reste -match '^\s*(list|show)\b')) -or
                    (($sous -eq 'remote') -and ($reste -match '^\s*(-v\s*$|-v\s+show\b|show\b|get-url\b|$)')) -or
                    (($sous -eq 'config') -and ($reste -match '^\s*(--get(-all|-regexp)?|--list|-l)\b')) -or
                    (($sous -eq 'reflog') -and ($reste -notmatch '\b(expire|delete|drop)\b'))
                $branche = ($sous -eq 'branch') -and ($reste -match '(^|\s)(-[a-zA-Z]*[dDmMcCf][a-zA-Z]*\b|--(de|mo|co|fo)[a-z-]*)')
                $refus = (($sous -notmatch $permis) -and -not $lecture) -or $branche
                if ($refus) {
                    $why = "Ouvrier de zone : git $sous refusé. Permis : add et commit de tes fichiers, merge du tronc si le brief le demande, lectures (status, diff, log, show). Rends un signal au thread principal pour le reste."
                    @{ hookSpecificOutput = @{ hookEventName = 'PreToolUse'; permissionDecision = 'deny'; permissionDecisionReason = $why } } | ConvertTo-Json -Compress -Depth 4
                    exit 0
                }
            }
        }
    }

    foreach ($seg in $segments) {
        $s = $seg.Trim()
        if ($s -notmatch '(?i)\bgit\b') { continue }

        # --- git reset --hard ---
        if ($s -match '(?i)\bgit\b.*\breset\b.*--hard') {
            $blockedReason = 'git reset --hard (jette le working tree et l''index)'
            break
        }
        # --- git clean -f/-d/-x (toute forme : -f, -fd, -xdf, --force) ---
        if ($s -match '(?i)\bgit\b.*\bclean\b.*(?:-[a-z]*f|--force)') {
            $blockedReason = 'git clean -f/-d/-x (supprime des fichiers non suivis)'
            break
        }
        # --- git stash drop / clear ---
        if ($s -match '(?i)\bgit\b.*\bstash\b.*\b(drop|clear)\b') {
            $blockedReason = 'git stash drop/clear (perte de stash)'
            break
        }
        # --- git restore <path> (syntaxe moderne, écrase les modifs) ---
        if ($s -match '(?i)\bgit\b.*\brestore\b') {
            $blockedReason = 'git restore (écrase des modifications non commitées)'
            break
        }
        # --- git checkout DESTRUCTIF ---
        # On bloque checkout quand il restaure des fichiers ou force, mais PAS la création de branche (-b)
        # ni un simple switch de branche sans perte.
        if ($s -match '(?i)\bgit\b.*\bcheckout\b') {
            # Autoriser explicitement la création de branche : git checkout -b <name>
            if ($s -match '(?i)\bcheckout\b\s+-b\b') { continue }
            # Bloquer : checkout -- , checkout . , checkout -f , checkout <path/fichier>
            if ($s -match '(?i)\bcheckout\b\s+(--|\.|-f\b|--force\b)') {
                $blockedReason = 'git checkout -- / . / -f (restaure et écrase des modifications non commitées)'
                break
            }
            # checkout suivi d'un chemin/fichier explicite (contient un / ou un . ou une extension) → restauration de fichier
            if ($s -match '(?i)\bcheckout\b\s+[^ ]*[\\/][^ ]*' -or $s -match '(?i)\bcheckout\b\s+[^ ]+\.[a-z0-9]+\b') {
                $blockedReason = 'git checkout <fichier> (restaure et écrase une version non commitée)'
                break
            }
        }
    }

    if ($blockedReason) {
        # « ask » : l'accord vient de l'invite de permission du harnais, jamais d'un fichier écrit par le modèle.
        @{
            hookSpecificOutput = @{
                hookEventName            = 'PreToolUse'
                permissionDecision       = 'ask'
                permissionDecisionReason = "Git destructif pour du travail non commité : $blockedReason. Validation humaine requise."
            }
        } | ConvertTo-Json -Compress -Depth 4
        exit 0
    }
} catch {
    # En cas d'erreur de parsing : ne pas bloquer (faux positif pire qu'un blocage manqué ici).
    exit 0
}
exit 0
