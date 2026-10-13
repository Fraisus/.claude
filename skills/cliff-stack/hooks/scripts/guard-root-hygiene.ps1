# guard-root-hygiene.ps1 — Hygiène de la racine du projet (GLOBAL, multiprojets)
#
# Rôle : empêcher la pollution de la racine du projet par des artefacts de
# session (captures d'écran, .md de travail, fichiers de sortie, package.json
# accidentel). Règle unique et vérifiable :
#   AUCUN NOUVEL ÉLÉMENT (fichier ou dossier) À LA RACINE DU PROJET, quel qu'il soit,
#   y compris créé en profondeur (« nouveau\sous\fichier » crée le dossier racine « nouveau »).
# Exceptions : CLAUDE.md + noms listés dans <projet>\.claude\root-allow.txt
# (1 nom par ligne, # = commentaire). Éditer un fichier racine EXISTANT reste
# libre (wp-config.php, .htaccess…) ; écrire dans un sous-dossier reste libre.
#
# Couvre 3 canaux :
#   1. Write/Edit/MultiEdit/NotebookEdit → file_path à la racine
#   2. Captures Playwright MCP (take_screenshot / pdf_save) → un filename relatif,
#      nom nu compris, se résout depuis la racine du projet (mesuré) : jugé comme
#      toute écriture. Le serveur refuse tout chemin hors du projet : la cible
#      saine est son dossier de sortie, .playwright-mcp\
#   3. Bash/PowerShell → cibles de dépôt (redirections, tee/touch, Set-Content…,
#      cp/mv) résolvant à la racine + npm/pnpm/yarn install créant un package.json
#
# S'auto-adapte via $env:CLAUDE_PROJECT_DIR — rien en dur par projet.
# Behavior : exit 2 = block (stderr montrée à Claude). exit 0 = allow.

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$raw = [Console]::In.ReadToEnd()
if (-not $raw) { exit 0 }
try { $payload = $raw | ConvertFrom-Json } catch { exit 0 }

$tool = $payload.tool_name
$projectRoot = $env:CLAUDE_PROJECT_DIR
if (-not $projectRoot) { exit 0 }
$projectRootNorm = ($projectRoot -replace '/','\').TrimEnd('\')

# Forme canonique d'un chemin disque, pour que racine et cible se comparent sous la même
# forme : segments . et .. repliés lexicalement et points/espaces finaux retirés (comme le
# fait Win32), puis noms courts 8.3 (CLIFFR~1) développés en forme longue via l'ancêtre
# existant le plus proche. Purement lexicale sur la partie inexistante : un joker ou un nom
# invalide reste jugeable au lieu de faire échouer la résolution.
# Même logique que dans guard-write-scope.ps1 (chaque hook est lancé seul par -File).
function Resolve-CanonicalPath([string]$p) {
    $parts = New-Object System.Collections.Generic.List[string]
    foreach ($seg in $p.Split('\')) {
        if ($seg -ne '..' -and $parts.Count -gt 0) { $seg = $seg.TrimEnd('.', ' ') }
        if ($seg -eq '.' -or ($seg -eq '' -and $parts.Count -gt 0)) { continue }
        if ($seg -eq '..') { if ($parts.Count -gt 1) { $parts.RemoveAt($parts.Count - 1) }; continue }
        $parts.Add($seg)
    }
    $p = ($parts -join '\')
    if ($parts.Count -eq 1) { $p += '\' }
    if ($p -notmatch '~') { return $p }
    for ($n = $parts.Count; $n -ge 1; $n--) {
        $head = ($parts.GetRange(0, $n) -join '\')
        if ($n -eq 1) { $head += '\' }
        if ([System.IO.Directory]::Exists($head) -or [System.IO.File]::Exists($head)) {
            try { $long = (Get-Item -LiteralPath $head -Force).FullName } catch { return $p }
            $tail = ($parts.GetRange($n, $parts.Count - $n) -join '\')
            if (-not $tail) { return $long }
            return $long.TrimEnd('\') + '\' + $tail
        }
    }
    return $p
}

# Racine comparée sous forme canonique : un CLAUDE_PROJECT_DIR en nom court 8.3 doit
# reconnaître les cibles en nom long, et inversement.
$projectRootLower = $projectRootNorm
if ($projectRootLower -match '^[A-Za-z]:\\') { $projectRootLower = Resolve-CanonicalPath $projectRootLower }
$projectRootLower = $projectRootLower.TrimEnd('\').ToLower()

function Reject([string]$reason) {
    $msg = @{ decision = 'block'; reason = $reason } | ConvertTo-Json -Compress
    [Console]::Error.WriteLine($msg)
    exit 2
}

# Whitelist des NOUVEAUX fichiers autorisés à la racine
$rootAllow = @('claude.md')
$allowFile = Join-Path $projectRootNorm '.claude\root-allow.txt'
if (Test-Path $allowFile) {
    foreach ($line in (Get-Content $allowFile -ErrorAction SilentlyContinue)) {
        $line = "$line".Trim()
        if ($line -and -not $line.StartsWith('#')) { $rootAllow += $line.ToLower() }
    }
}

# Chemin absolu canonique d'un chemin écrit en forme Windows, MSYS (/c/…) ou ~, un
# relatif étant résolu contre $base. $null si non résolvable (relatif sans base connue).
function ConvertTo-AbsolutePath([string]$path, [string]$base) {
    if (-not $path) { return $null }
    $p = $path.Trim('"',"'")
    if ($p -match '^/([a-zA-Z])(/.*)?$') { $p = "$($matches[1]):\$($matches[2])" }
    if ($p -match '^~(?=[\\/]|$)' -and $env:USERPROFILE) { $p = $env:USERPROFILE + $p.Substring(1) }
    $p = $p -replace '/','\'
    if ($p -notmatch '^[a-zA-Z]:' -and -not $p.StartsWith('\\')) {
        if (-not $base) { return $null }
        $p = $base.TrimEnd('\') + '\' + $p
    }
    if ($p -match '^[A-Za-z]:\\') { return Resolve-CanonicalPath $p }
    # Chemin réseau : pas de nom court à développer, normalisation Win32 suffisante.
    try { return [System.IO.Path]::GetFullPath($p) } catch { return $null }
}

# L'élément de la racine du projet par lequel passe un chemin (absolu, ou relatif à $base),
# à toute profondeur : un « Rob\Pictures\… » né d'un chemin coupé à son espace crée le
# dossier racine « Rob ». $null hors du projet ou sur la racine elle-même.
function Resolve-IfRootLevel([string]$path, [string]$base) {
    $p = ConvertTo-AbsolutePath $path $base
    if (-not $p) { return $null }
    $p = $p.TrimEnd('\')
    if (-not $p.ToLower().StartsWith($projectRootLower + '\')) { return $null }
    $first = $p.Substring($projectRootLower.Length + 1).Split('\')[0]
    if (-not $first) { return $null }
    return $p.Substring(0, $projectRootLower.Length + 1 + $first.Length)
}

# $base : dossier contre lequel un chemin relatif se résout. Write/Edit et captures
# Playwright se résolvent depuis la racine du projet.
function Assert-RootTarget([string]$path, [string]$channel, [string]$base = $projectRootNorm) {
    $resolved = Resolve-IfRootLevel $path $base
    if (-not $resolved) { return }
    # Existence du nom LITTÉRAL : Test-Path sans -LiteralPath lit * ? [ ] comme des jokers
    # (un « [b].txt » neuf passait pour existant dès qu'un b.txt existe).
    if (Test-Path -LiteralPath $resolved) { return }   # édition/écrasement d'un fichier racine existant : libre
    $base = (Split-Path $resolved -Leaf).ToLower()
    if ($rootAllow -contains $base) { return }
    Reject ("BLOQUÉ (hygiène racine, $channel) : création de '" + (Split-Path $resolved -Leaf) + "' à la racine du projet interdite (fichier ou dossier, à toute profondeur). " +
        "Un nom inattendu vient souvent d'un chemin à espace non cité : citer chaque chemin entre guillemets. " +
        "Captures navigateur : filename '.playwright-mcp\<nom>.png', dossier de sortie du serveur MCP (il refuse tout chemin hors du projet) ; fichiers temporaires → scratchpad de session ; docs → un sous-dossier du projet. " +
        "Si ce fichier racine est vraiment légitime (demande explicite de l'utilisateur), ajouter son nom dans .claude\root-allow.txt du projet.")
}

# ==============================================================================
# 1. Write / Edit / MultiEdit / NotebookEdit
# ==============================================================================
if ($tool -in @('Write','Edit','MultiEdit','NotebookEdit')) {
    $fp = $payload.tool_input.file_path
    if (-not $fp) { $fp = $payload.tool_input.notebook_path }
    if ($fp) { Assert-RootTarget $fp $tool }
    exit 0
}

# ==============================================================================
# 2. Captures Playwright MCP — le serveur résout un filename relatif depuis la
#    racine de l'espace de travail : un nom nu y crée le fichier (mesuré le
#    18/09/2026). Sans filename, la capture part dans le dossier de sortie du
#    serveur, un sous-dossier : libre.
# ==============================================================================
if ($tool -match '^mcp__playwright__browser_(take_screenshot|pdf_save)$') {
    $fn = $payload.tool_input.filename
    if ($fn) { Assert-RootTarget $fn 'capture Playwright' }
    exit 0
}

# ==============================================================================
# 3. Bash / PowerShell — cibles de dépôt résolvant à la racine du projet
# ==============================================================================
if ($tool -ne 'Bash' -and $tool -ne 'PowerShell') { exit 0 }
$cmd = $payload.tool_input.command
if (-not $cmd) { exit 0 }

# Ce qui n'est pas du shell est retiré AVANT d'y chercher des redirections : corps des
# heredocs et code passé en ligne (python -c, php -r, node -e…). Sinon un `=>`, un `<>`
# ou une balise `<style …>` DANS le contenu écrit passe pour une redirection et bloque
# une commande légitime.
$scan = $cmd
$scan = [regex]::Replace($scan, '(?s)<<-?\s*[\x27"]?(\w+)[\x27"]?\r?\n.*?\r?\n\s*\1\b', ' ')
$scan = [regex]::Replace($scan, '(?is)\b(?:python3?|php|node|perl|ruby)\s+(?:-\w+\s+)*-(?:c|r|e)\s+(?:"(?:[^"\\]|\\.)*"|\x27(?:[^\x27\\]|\\.)*\x27)', ' ')

# Découpe une ligne de commande en commandes simples, dans l'ordre d'exécution : ; | && ||
# & et retours à la ligne hors guillemets. Un & collé à un chevron (2>&1, &>f) n'est pas un
# séparateur. $esc : caractère d'échappement du shell (\ en Bash, ` en PowerShell).
function Split-ShellSegments([string]$s, [string]$esc) {
    $segs = New-Object System.Collections.Generic.List[string]
    $sb = New-Object System.Text.StringBuilder
    $q = ''
    for ($i = 0; $i -lt $s.Length; $i++) {
        $c = [string]$s[$i]
        if ($c -eq $esc -and $q -ne "'" -and $i + 1 -lt $s.Length) { [void]$sb.Append($c).Append($s[$i + 1]); $i++; continue }
        if ($q) { if ($c -eq $q) { $q = '' }; [void]$sb.Append($c); continue }
        if ($c -eq '"' -or $c -eq "'") { $q = $c; [void]$sb.Append($c); continue }
        $sep = ($c -eq ';' -or $c -eq "`n" -or $c -eq '|')
        if ($c -eq '&') {
            $prev = if ($i -gt 0) { [string]$s[$i - 1] } else { '' }
            $next = if ($i + 1 -lt $s.Length) { [string]$s[$i + 1] } else { '' }
            $sep = ($prev -ne '>' -and $prev -ne '<' -and $next -ne '>')
        }
        if ($sep) { $segs.Add($sb.ToString()); [void]$sb.Clear(); continue }
        [void]$sb.Append($c)
    }
    $segs.Add($sb.ToString())
    return ,$segs
}

# Cibles de dépôt d'une commande simple : redirections, tee/touch, cmdlets écrivains, cp/mv.
function Get-WriteTargets([string]$seg) {
    $targets = @()
    # Redirections > / >> (cible quotée ou nue), hors flux (&1, /dev/null…) et hors opérateurs
    # de langage qui contiennent un chevron (`=>`, `->`, `<>`, `>=`) : les prendre pour une
    # redirection bloquait des commandes légitimes.
    foreach ($m in [regex]::Matches($seg, '(?<![0-9&=<>\-])>{1,2}(?!=)\s*(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>]+))')) {
        $targets += if ($m.Groups[1].Value) { $m.Groups[1].Value } elseif ($m.Groups[2].Value) { $m.Groups[2].Value } else { $m.Groups[3].Value }
    }
    # tee / touch
    foreach ($m in [regex]::Matches($seg, '(?i)\b(tee|touch)\s+(?:-a\s+)?(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>-][^\s;&|<>]*))')) {
        $targets += if ($m.Groups[2].Value) { $m.Groups[2].Value } elseif ($m.Groups[3].Value) { $m.Groups[3].Value } else { $m.Groups[4].Value }
    }
    # Cmdlets PowerShell écrivains : -Path/-FilePath/-LiteralPath ou 1er argument positionnel
    foreach ($m in [regex]::Matches($seg, '(?i)\b(Out-File|Set-Content|Add-Content|New-Item)\b[^;&|\r\n]*')) {
        $v = $m.Value
        if ($v -match '(?i)-(File|Literal)?Path\s+(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>]+))') {
            $targets += if ($matches[2]) { $matches[2] } elseif ($matches[3]) { $matches[3] } else { $matches[4] }
        } else {
            # 1er argument positionnel, une fois retirés les paramètres qui prennent une valeur (-ItemType Directory…)
            $pos = [regex]::Replace($v, '(?i)^\S+|\s-(ItemType|Type|Value|Name|Encoding|Width|InputObject)\s+(?:"[^"]*"|''[^'']*''|\S+)', ' ')
            if ($pos -match '(?<!\S)(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>-][^\s;&|<>]*))') {
                $targets += if ($matches[1]) { $matches[1] } elseif ($matches[2]) { $matches[2] } else { $matches[3] }
            }
        }
    }
    # mkdir / md : chaque argument non optionnel est un dossier créé
    foreach ($m in [regex]::Matches($seg, '(?i)(?:^|\s)(mkdir|md)\b(?<args>[^;&|\r\n]*)')) {
        foreach ($pm in [regex]::Matches($m.Groups['args'].Value, '(?<!\S)(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>-][^\s;&|<>]*))')) {
            $targets += if ($pm.Groups[1].Value) { $pm.Groups[1].Value } elseif ($pm.Groups[2].Value) { $pm.Groups[2].Value } else { $pm.Groups[3].Value }
        }
    }
    # cp/mv & équivalents : dernière cible de la commande = dépôt
    foreach ($m in [regex]::Matches($seg, '(?i)\b(cp|mv|copy|move|xcopy|robocopy|Copy-Item|Move-Item)\b(?<args>[^;&|\r\n]*)')) {
        $segT = @()
        foreach ($pm in [regex]::Matches($m.Groups['args'].Value, '(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>-][^\s;&|<>]*))')) {
            $segT += if ($pm.Groups[1].Value) { $pm.Groups[1].Value } elseif ($pm.Groups[2].Value) { $pm.Groups[2].Value } else { $pm.Groups[3].Value }
        }
        if ($segT.Count -ge 2) { $targets += $segT[-1] }
    }
    return $targets
}

# Nouveau dossier courant après un cd/pushd/Set-Location, $null s'il n'est pas déterminable
# (variable, cd -, joker) : les chemins relatifs qui suivent ne sont alors plus jugés, les
# absolus le restent.
function Get-CdTarget([string]$verb, [string]$rest, [string]$cur, [bool]$isBash) {
    $arg = $null
    foreach ($tm in [regex]::Matches($rest, '"([^"]*)"|''([^'']*)''|(\S+)')) {
        $tok = if ($tm.Groups[1].Success) { $tm.Groups[1].Value } elseif ($tm.Groups[2].Success) { $tm.Groups[2].Value } else { $tm.Groups[3].Value }
        if (-not $tm.Groups[1].Success -and -not $tm.Groups[2].Success -and ($tok -match '^-.' -or $tok -match '^/d$')) { continue }
        $arg = $tok; break
    }
    if ($null -eq $arg) {
        if ($isBash -and $verb -in @('cd','chdir')) { return $env:USERPROFILE }
        if ($isBash) { return $null }   # pushd sans argument : échange de pile
        return $cur                      # Set-Location sans argument (PowerShell 5.1) : inchangé
    }
    if ($arg -eq '-' -or $arg -match '[\$`%*?]') { return $null }
    return ConvertTo-AbsolutePath $arg $cur
}

$isBash = ($tool -eq 'Bash')
$esc = if ($isBash) { '\' } else { '`' }
$hasRootPackage = Test-Path -LiteralPath (Join-Path $projectRootNorm 'package.json')

# Dossier courant suivi commande après commande : il part du cwd du harnais (racine du
# projet à défaut), puis chaque cd/pushd/popd le déplace. Toute cible relative se résout
# contre lui : un « cd <racine> && echo x > nouveau.md » écrit bien à la racine.
$cur = $projectRootNorm
if ($payload.cwd) { $cur = ConvertTo-AbsolutePath "$($payload.cwd)" $projectRootNorm }
$dirStack = New-Object System.Collections.Generic.Stack[object]
$subStack = New-Object System.Collections.Generic.Stack[object]

foreach ($seg in (Split-ShellSegments $scan $esc)) {
    $s = $seg.Trim()
    # Sous-shell Bash ( … ) : un cd à l'intérieur ne survit pas à la parenthèse fermante.
    if ($isBash) { while ($s.StartsWith('(')) { $subStack.Push($cur); $s = $s.Substring(1).TrimStart() } }
    $closes = 0
    if ($isBash) { while ($s.EndsWith(')') -and $subStack.Count -gt $closes) { $closes++; $s = $s.Substring(0, $s.Length - 1).TrimEnd() } }
    if (-not $s) { for ($k = 0; $k -lt $closes; $k++) { $cur = $subStack.Pop() }; continue }

    if ($s -match '(?i)^(cd|chdir|pushd|popd|set-location|sl|push-location|pop-location)(?=\s|$)(.*)$') {
        $verb = $matches[1].ToLower(); $rest = $matches[2]
        if ($verb -in @('popd','pop-location')) {
            $cur = if ($dirStack.Count -gt 0) { $dirStack.Pop() } else { $null }
        } else {
            if ($verb -in @('pushd','push-location')) { $dirStack.Push($cur) }
            $cur = Get-CdTarget $verb $rest $cur $isBash
        }
    } else {
        # npm/pnpm/yarn/bun install|init lancé À la racine sans package.json → en créerait un
        if (-not $hasRootPackage -and $cur -and $cur.TrimEnd('\').ToLower() -eq $projectRootLower -and
            $s -match '(?i)^(npm|pnpm|yarn|bun)\s+(install|i|add|init)\b') {
            Reject "BLOQUÉ (hygiène racine) : ce '$($matches[1]) $($matches[2])' créerait un package.json à la racine du projet (aucun n'y existe). Lance-le dans le sous-dossier outillé (ex. cd .vite) ou dans le scratchpad."
        }
        foreach ($t in (Get-WriteTargets $s)) {
            if (-not $t) { continue }
            if ($t -match '^(/dev/(null|stdout|stderr|tty)|\$null|nul)$') { continue }
            if ($t.StartsWith('-') -or $t.StartsWith('$')) { continue }
            Assert-RootTarget $t $tool $cur
        }
    }
    for ($k = 0; $k -lt $closes; $k++) { $cur = $subStack.Pop() }
}

exit 0
