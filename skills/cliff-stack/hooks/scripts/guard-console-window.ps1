# guard-console-window.ps1 - hook PostToolUse (Write|Edit|MultiEdit) global.
# Dans un fichier Python ou Node qui DÉTACHE un processus (DETACHED_PROCESS, detached: true), signale chaque
# lancement d'enfant console sans drapeau « sans fenêtre » (creationflags= / windowsHide:) : sous Windows, cet
# enfant recevrait une console neuve et visible (onglet Windows Terminal). Contrat : ~/.claude/rules/processus-enfants.md.
# Skip silencieux si : extension hors périmètre, fichier absent, node_modules/vendor, ou aucun détachement dans le fichier.
# Findings -> stderr + exit 2 (remontés à Claude comme feedback). Sinon exit 0. Ne réécrit rien.

$ErrorActionPreference = 'Stop'
try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $data = $raw | ConvertFrom-Json
    $file = $data.tool_input.file_path
    if (-not $file) { exit 0 }
    if ($file -notmatch '\.(py|js|mjs|cjs|ts)$') { exit 0 }
    if ($file -match '[\\/](node_modules|vendor|\.git)[\\/]') { exit 0 }
    if (-not (Test-Path -LiteralPath $file)) { exit 0 }
    if ((Get-Item -LiteralPath $file).Length -gt 2MB) { exit 0 }

    $src = Get-Content -LiteralPath $file -Raw -Encoding UTF8
    if (-not $src) { exit 0 }
    $isPy = $file -match '\.py$'

    # Le fichier détache-t-il ? Sinon les enfants héritent de la console (cachée) du parent : rien à signaler.
    $detach = if ($isPy) { 'DETACHED_PROCESS|CREATE_NEW_CONSOLE|\b0x0*8\b\s*\|' } else { '\bdetached\s*:\s*true\b' }
    if ($src -notmatch $detach) { exit 0 }

    # Appels de lancement. Node : lookbehind pour exclure `/re/.exec(` et autres méthodes homonymes.
    $callRx = if ($isPy) {
        '(?<![\w.])(?:subprocess\.)?(Popen|run|call|check_call|check_output)\s*\('
    } else {
        '(?<![\w.])(?:child_process\.|cp\.)?(spawn|spawnSync|execFile|execFileSync|exec|execSync|fork)\s*\('
    }
    # Un appel est couvert s'il porte le drapeau ou éclate un dictionnaire d'options (constante du projet).
    $okRx = if ($isPy) { 'creationflags\s*=|\*\*\w+' } else { '\bwindowsHide\b|\.\.\.\w+' }
    # En Python sans préfixe `subprocess.`, `run(`/`call(` sont ambigus : on les retient seulement si importés.
    $bare = $isPy -and ($src -match 'from\s+subprocess\s+import')

    $findings = New-Object System.Collections.Generic.List[string]
    foreach ($m in [regex]::Matches($src, $callRx)) {
        if ($isPy -and -not $m.Value.StartsWith('subprocess.') -and -not $bare) { continue }
        # Arguments : parenthèses équilibrées depuis l'ouvrante (les chaînes contenant des parenthèses sont rares ici).
        $i = $m.Index + $m.Length; $depth = 1
        while ($i -lt $src.Length -and $depth -gt 0) {
            $c = $src[$i]
            if ($c -eq '(') { $depth++ } elseif ($c -eq ')') { $depth-- }
            $i++
        }
        $args = $src.Substring($m.Index + $m.Length, [Math]::Max(0, $i - 1 - ($m.Index + $m.Length)))
        if ($args -match $okRx) { continue }
        $line = ($src.Substring(0, $m.Index) -split "`n").Count
        $name = $m.Groups[1].Value
        $findings.Add("  L${line}: ${name}( sans drapeau sans fenêtre")
    }
    if ($findings.Count -eq 0) { exit 0 }

    $flag = if ($isPy) { 'creationflags=CREATE_NO_WINDOW (0x08000000), via la constante du projet (ex. SANS_FENETRE), {} hors Windows' } else { 'windowsHide: true, via la constante du projet, hors Windows sans effet' }
    [Console]::Error.WriteLine("[guard-console-window] $file détache un processus : ses enfants console recevront une fenêtre Windows Terminal visible.")
    [Console]::Error.WriteLine(($findings -join "`n"))
    [Console]::Error.WriteLine("  Correctif : $flag ; fixer stdin/stdout/stderr explicitement. Contrat : ~/.claude/rules/processus-enfants.md")
    exit 2
}
catch {
    # Un hook ne doit jamais casser le flux.
    exit 0
}
