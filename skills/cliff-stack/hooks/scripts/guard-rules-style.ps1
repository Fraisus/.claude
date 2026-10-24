# guard-rules-style.ps1 - hook PostToolUse (Write|Edit|MultiEdit) global.
# Fichiers d'instructions lus par le modèle (CLAUDE.md, .claude/rules/*.md, SKILL.md, définitions d'agents) :
# signale une taille hors cible, une emphase répétée, une citation ou un lien. Contrat : règle « Fichiers de règles »
# de ~/.claude/CLAUDE.md. Informe seulement : findings -> stderr + exit 2 (feedback à Claude), sinon exit 0.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $file = ($raw | ConvertFrom-Json).tool_input.file_path
    if (-not $file -or -not (Test-Path -LiteralPath $file)) { exit 0 }
    $p = $file -replace '\\', '/'
    $kind = if ($p -match '/CLAUDE\.md$') { 'claude' }
            elseif ($p -match '/SKILL\.md$') { 'skill' }
            elseif ($p -match '/\.claude/rules/[^/]+\.md$' -or $p -match '/\.claude/agents/[^/]+\.md$' -or $p -match '/skills/[^/]+/agents/[^/]+\.md$') { 'rule' }
            else { $null }
    if (-not $kind) { exit 0 }
    if ($p -match '/(tmp_essais|node_modules|\.git)/') { exit 0 }

    $src = Get-Content -LiteralPath $file -Raw -Encoding UTF8
    if (-not $src) { exit 0 }
    $lines = ($src -split "`n").Count
    $max = @{ claude = 200; skill = 500; rule = 300 }[$kind]
    $findings = New-Object System.Collections.Generic.List[string]
    if ($lines -gt $max) { $findings.Add("$lines lignes (cible : $max au plus) : déplacer le détail vers une rule à paths: ou un skill") }
    $emph = [regex]::Matches($src, '\b(IMPORTANT|CRITICAL|YOU MUST|MUST NOT|NEVER|ALWAYS)\b').Count
    if ($emph -gt 1) { $findings.Add("$emph emphases (IMPORTANT, MUST…) : une seule, sur la ligne qui serait ignorée") }
    $links = [regex]::Matches($src, 'https?://(?!claude\.ai/artifact)').Count
    if ($links -gt 0 -and $kind -ne 'skill') { $findings.Add("$links lien(s) web : une règle guide le modèle, elle ne cite pas de source") }
    $quotes = [regex]::Matches($src, '[«“"][^»”"\r\n]{40,}[»”"]').Count
    if ($quotes -gt 3) { $findings.Add("$quotes longues citations : une consigne se dit, elle ne se cite pas") }

    if ($findings.Count -eq 0) { exit 0 }
    [Console]::Error.WriteLine("Fichier d'instructions $file :`n- " + ($findings -join "`n- ") + "`n(Règle « Fichiers de règles » ; seulement un avertissement.)")
    exit 2
} catch { exit 0 }
