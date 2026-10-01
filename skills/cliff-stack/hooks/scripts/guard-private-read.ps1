# guard-private-read.ps1 — Chemins privés d'un projet (GLOBAL, données par projet)
#
# Un projet déclare dans <racine>/.claude/private-paths.txt les chemins qu'aucun agent
# ne doit lire ni modifier (un par ligne, relatifs à la racine ; « dossier/ » pour un
# dossier, jokers * ? admis ; # = commentaire). Sans ce fichier, le hook ne fait rien.
# La règle tient même si l'agent est manipulé : elle ne dépend pas de sa consigne.
#
# Couvre : Read, Edit, Write, MultiEdit, NotebookEdit (chemin cible), Grep et Glob
# (dossier ou motif visant un chemin privé), Bash et PowerShell (chemin privé cité
# dans la commande). Limite connue : une recherche récursive lancée par le shell
# depuis la racine (grep -r .) n'est pas vue ; Grep, outil natif, ignore déjà les
# fichiers exclus par .gitignore.
#
# exit 2 = block, exit 0 = allow.

$ErrorActionPreference = 'Stop'
$raw = [Console]::In.ReadToEnd()
if (-not $raw) { exit 0 }
try { $payload = $raw | ConvertFrom-Json } catch { exit 0 }

$root = $env:CLAUDE_PROJECT_DIR
if (-not $root) { $root = $payload.cwd }
if (-not $root) { exit 0 }
$list = Join-Path $root '.claude/private-paths.txt'
if (-not (Test-Path -LiteralPath $list)) { exit 0 }

$patterns = @(Get-Content -LiteralPath $list -Encoding UTF8 |
    ForEach-Object { $_.Trim() -replace '\\', '/' } |
    Where-Object { $_ -and -not $_.StartsWith('#') })
if (-not $patterns) { exit 0 }

$rootN = ([IO.Path]::GetFullPath($root) -replace '\\', '/').TrimEnd('/').ToLowerInvariant()

# Chemin relatif à la racine, en minuscules et avec des « / » ; $null s'il est hors du projet.
function Get-Rel([string]$p) {
    if (-not $p) { return $null }
    if (-not [IO.Path]::IsPathRooted($p)) { $p = Join-Path $root $p }
    try { $full = ([IO.Path]::GetFullPath($p) -replace '\\', '/').ToLowerInvariant() } catch { return $null }
    if ($full -eq $rootN) { return '' }
    if ($full.StartsWith("$rootN/")) { return $full.Substring($rootN.Length + 1) }
    return $null
}

function Test-Private([string]$rel) {
    if ($null -eq $rel -or $rel -eq '') { return $false }
    foreach ($pat in $patterns) {
        $p = $pat.ToLowerInvariant()
        if ($p.EndsWith('/')) {
            $d = $p.TrimEnd('/')
            if ($rel -like $d -or $rel -like "$d/*") { return $true }
        } elseif ($rel -like $p -or $rel -like "$p/*") { return $true }
    }
    return $false
}

# Un chemin privé cité dans un texte libre (commande shell, motif de Glob ou de Grep).
function Test-Mentions([string]$text) {
    if (-not $text) { return $false }
    foreach ($pat in $patterns) {
        $p = [regex]::Escape($pat.TrimEnd('/')) -replace '\\\*', '[^\s/\\"'']*' -replace '\\\?', '.'
        $edge = '(^|[\s"''=:/\\(])'
        if ($pat.EndsWith('/')) {
            if ($text -match "(?i)$edge$p[\\/]" -or $text -match "(?i)\bcd\s+[""']?([^\s""']*[\\/])?$p([""'\s;&|)]|$)") { return $true }
        } elseif ($text -match "(?i)$edge$p([""'\s;&|)]|$)") { return $true }
    }
    return $false
}

$tool = $payload.tool_name
$in = $payload.tool_input
$hit = $false
switch -Regex ($tool) {
    '^(Read|Edit|Write|MultiEdit)$' { $hit = Test-Private (Get-Rel $in.file_path) }
    '^NotebookEdit$'               { $hit = Test-Private (Get-Rel $in.notebook_path) }
    '^(Grep|Glob)$' {
        $hit = (Test-Private (Get-Rel $in.path)) -or (Test-Mentions $in.glob) -or
               ($tool -eq 'Glob' -and (Test-Mentions $in.pattern))
    }
    '^(Bash|PowerShell)$' { $hit = Test-Mentions $in.command }
}

if ($hit) {
    [Console]::Error.WriteLine("BLOQUE : chemin privé du projet (.claude/private-paths.txt). Ne pas contourner ; seul l'utilisateur peut lever cette règle, en modifiant ce fichier lui-même.")
    exit 2
}
exit 0
