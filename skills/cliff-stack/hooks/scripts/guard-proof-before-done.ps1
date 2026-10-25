# guard-proof-before-done.ps1 - hook Stop global : porte (8) des « Huit portes » (~/.claude/CLAUDE.md).
# Bloque la fin d'un tour qui annonce un résultat (corrigé, fini, livré…) quand ce tour a modifié du code sans lancer
# de vérification après la dernière modification. Une fois par tour (stop_hook_active), jamais en boucle.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$CODE = '\.(py|js|mjs|cjs|ts|tsx|jsx|php|css|scss|html|ps1|sh|json)$'
$CHECK = '(test_|_test\.|tests?/|pytest|node --check|py_compile|check_|faux_hote|bench|playwright|ux-e2e|--verifier|phpcs|npm (run )?test|vitest|jest)'
$CLAIM = '(?i)\b(corrig[ée]e?s?|r[ée]gl[ée]e?s?|r[ée]solue?s?|fini|termin[ée]e?s?|livr[ée]e?s?|en place|c''est fait|fixed|done)\b'
try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $in = $raw | ConvertFrom-Json
    if ($in.stop_hook_active) { exit 0 }
    $path = $in.transcript_path
    if (-not $path -or -not (Test-Path -LiteralPath $path)) { exit 0 }
    $lines = Get-Content -LiteralPath $path -Tail 400 -Encoding UTF8
    $edits, $checks, $text = -1, -1, ''
    $i = 0
    foreach ($l in $lines) {
        $i++
        try { $e = $l | ConvertFrom-Json } catch { continue }
        $c = $e.message.content
        if ($e.type -eq 'user' -and $c -is [string]) { $edits, $checks, $text = -1, -1, ''; continue }  # a new request: a new turn
        if ($e.type -ne 'assistant' -or -not $c) { continue }
        foreach ($b in $c) {
            if ($b.type -eq 'text') { $text = $b.text }
            if ($b.type -ne 'tool_use') { continue }
            $n, $p = $b.name, $b.input
            if ($n -in 'Write', 'Edit', 'MultiEdit' -and $p.file_path -match $CODE) { $edits = $i }
            if ($n -in 'Bash', 'PowerShell' -and $p.command -match $CHECK) { $checks = $i }
        }
    }
    if ($edits -lt 0 -or $checks -gt $edits -or $text -notmatch $CLAIM) { exit 0 }
    [Console]::Error.WriteLine("Porte (8) : ce tour a modifié du code sans vérification lancée après la dernière modification. Lancer les essais et contrôles des fichiers touchés, ou dire que ce n'est pas vérifié, avant d'annoncer un résultat.")
    exit 2
} catch { exit 0 }
