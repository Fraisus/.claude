# guard-write-scope.ps1 — Firewall sortant Claude Code (GLOBAL, multiprojets)
#
# Rôle : seule vraie barrière de sécurité quand allow = Bash(*)/PowerShell(*).
# Empêche toute commande Bash/PowerShell d'écrire/modifier/détruire :
#   - en dehors du projet courant ($env:CLAUDE_PROJECT_DIR) + c:\tmp
#   - sur des chemins SYSTÈME Windows critiques (même via commande sans path)
#   - via des contournements (sous-shells, cd hors-projet, registre, format…)
#
# Lectures (cat, ls, head, grep, Get-Content) toujours autorisées partout
# (l'exfiltration de secrets est gérée par guard-secrets-read.ps1).
#
# MySQL : seuls les statements modificateurs ciblant la DB du wp-config.php du
# projet sont autorisés. SELECT/SHOW/DESCRIBE/EXPLAIN restent libres partout.
#
# S'auto-adapte à CHAQUE projet via $env:CLAUDE_PROJECT_DIR — aucune réécriture
# par projet nécessaire.
#
# Behavior : exit 2 = block (stderr montrée à Claude). exit 0 = allow.

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

# Lire JSON d'entrée
$raw = [Console]::In.ReadToEnd()
if (-not $raw) { exit 0 }
try { $payload = $raw | ConvertFrom-Json } catch { exit 0 }

$tool = $payload.tool_name
if ($tool -ne 'Bash' -and $tool -ne 'PowerShell') { exit 0 }

$cmd = $payload.tool_input.command
if (-not $cmd) { exit 0 }

# --- Zones autorisées ----------------------------------------------------------
$projectRoot = $env:CLAUDE_PROJECT_DIR
$hasProject  = [bool]$projectRoot
if ($hasProject) {
    $projectRoot = ($projectRoot -replace '/','\').TrimEnd('\').ToLower()
}
$allowedRoots = @()
if ($hasProject) { $allowedRoots += $projectRoot }
$allowedRoots += 'c:\tmp'
# Dossier de config Claude (déjà dans additionalDirectories) : skills, hooks,
# memory, sorties MCP (ex. playwright-output). Écriture shell autorisée ici.
if ($env:USERPROFILE) { $allowedRoots += (Join-Path $env:USERPROFILE '.claude').ToLower() }
# Scratchpad de session Claude Code (zone temporaire officielle du harnais).
if ($env:LOCALAPPDATA) { $allowedRoots += (Join-Path $env:LOCALAPPDATA 'Temp\claude').ToLower() }
# Zones ADDITIONNELLES optionnelles (~/.claude/write-scope-extra-roots.txt : 1 chemin par ligne,
# lignes # = commentaires). Étend le périmètre SANS modifier ce hook — pour les répertoires de
# travail légitimes hors racine web explicitement demandés par l'utilisateur (ex. un projet sur
# le Bureau). Sémantique = préfixe de chaîne, comme les autres roots. Fichier absent = aucun effet.
if ($env:USERPROFILE) {
    $extraRootsFile = Join-Path $env:USERPROFILE '.claude\write-scope-extra-roots.txt'
    if (Test-Path $extraRootsFile) {
        foreach ($extraLine in (Get-Content $extraRootsFile -ErrorAction SilentlyContinue)) {
            $extraLine = "$extraLine".Trim()
            if ($extraLine -and -not $extraLine.StartsWith('#')) {
                $allowedRoots += (($extraLine -replace '/','\').TrimEnd('\').ToLower())
            }
        }
    }
}

# Détection DB MySQL projet (lecture wp-config.php)
$projectDb = $null
if ($hasProject) {
    $wpConfig = Join-Path $env:CLAUDE_PROJECT_DIR 'wp-config.php'
    if (Test-Path $wpConfig) {
        $cfg = Get-Content $wpConfig -Raw
        if ($cfg -match "define\(\s*['""]DB_NAME['""]\s*,\s*['""]([^'""]+)['""]") {
            $projectDb = $matches[1].ToLower()
        }
    }
    # Projets NON-WordPress (Astro/Node avec DB) : opt-in EXPLICITE par projet via
    # .claude/allowed-db (1 nom de DB par ligne, commentaires # ignorés). Même garantie
    # que wp-config : seule LA DB déclarée du projet est modifiable, rien d'autre.
    if (-not $projectDb) {
        $allowedDbFile = Join-Path $env:CLAUDE_PROJECT_DIR '.claude\allowed-db'
        if (Test-Path $allowedDbFile) {
            $line = (Get-Content $allowedDbFile | Where-Object { $_ -match '\S' -and $_ -notmatch '^\s*#' } | Select-Object -First 1)
            if ($line) { $projectDb = $line.Trim().ToLower() }
        }
    }
}

# --- Helpers -------------------------------------------------------------------
function Reject([string]$reason) {
    $msg = @{
        decision = 'block'
        reason   = $reason
    } | ConvertTo-Json -Compress
    [Console]::Error.WriteLine($msg)
    exit 2
}

function Normalize-Path([string]$path) {
    $p = $path.Trim('"',"'")
    if ($p -match '^/([a-z])/(.*)$') { $p = "$($matches[1]):\$($matches[2] -replace '/','\')" }
    $isUnc = $p.StartsWith('\\')
    $p = $p -replace '/','\'
    $p = $p -replace '\\{2,}','\'
    # Préserver le préfixe UNC \\serveur\share : sans lui, Is-PathInProject prendrait
    # le chemin réseau pour un relatif et l'autoriserait d'office.
    if ($isUnc) { $p = '\' + $p }
    if ($p -match '^[A-Za-z]:\\') { $p = Resolve-CanonicalPath $p }
    return $p.ToLower()
}

# Forme canonique d'un chemin disque, pour que la comparaison de préfixe juge la cible
# réelle : segments . et .. repliés lexicalement (comme le fait Win32), puis noms courts
# 8.3 (CLIFFR~1) développés en forme longue via l'ancêtre existant le plus proche. La
# partie inexistante reste telle quelle : un nom court qui n'existe pas n'aliase rien.
function Resolve-CanonicalPath([string]$p) {
    $parts = New-Object System.Collections.Generic.List[string]
    foreach ($seg in $p.Split('\')) {
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

function Is-PathInProject([string]$path) {
    if (-not $path) { return $true }
    $p = Normalize-Path $path
    # path relatif sans drive → relatif au cwd Claude = projet par défaut
    if ($p -notmatch '^[a-z]:' -and -not $p.StartsWith('\\')) { return $true }
    foreach ($root in $allowedRoots) {
        # Frontière de segment : c:\tmp couvre c:\tmp\x, pas c:\tmpevil.
        if ($p -eq $root -or $p.StartsWith($root.TrimEnd('\') + '\')) { return $true }
    }
    return $false
}

# Zones et cibles passent par la même forme canonique (une zone déclarée en nom court
# ou via un CLAUDE_PROJECT_DIR court doit couvrir ses chemins longs, et inversement).
$allowedRoots = @($allowedRoots | ForEach-Object { (Normalize-Path $_).TrimEnd('\') })

# ==============================================================================
# 0. INTERDITS ABSOLUS SYSTÈME — bloqués partout, même dans un projet, même
#    sans path explicite. Ce sont des opérations qui ne devraient JAMAIS tourner.
# ==============================================================================
$systemKillPatterns = @(
    # Effacement disque / partition / boot
    @{ rx = '(?i)\bformat(\.com)?\s+[a-z]:';                 why = 'format d''un volume' },
    @{ rx = '(?i)\bdiskpart\b';                              why = 'diskpart (gestion partitions)' },
    @{ rx = '(?i)\bbcdedit\b';                               why = 'bcdedit (boot)' },
    @{ rx = '(?i)\bcipher\s+/w';                             why = 'cipher /w (wipe disque)' },
    @{ rx = '(?i)\bvssadmin\s+delete';                       why = 'suppression shadow copies' },
    @{ rx = '(?i)\bwmic\b.*\bshadowcopy\b.*\bdelete\b';      why = 'suppression shadow copies (wmic)' },
    # rm -rf racine (toutes formes)
    @{ rx = '\brm\s+(-[a-z]*\s+)*-?[a-z]*r[a-z]*f?[a-z]*\s+/\s*($|[;&|])';  why = 'rm -rf /' },
    @{ rx = '\brm\s+(-[a-z]*\s+)*-?[a-z]*[rf][a-z]*\s+/[a-z]/\s*($|[;&|])'; why = 'rm -rf d''une racine disque' },
    @{ rx = '\brm\s+(-[a-z]*\s+)*-?[a-z]*[rf][a-z]*\s+~\s*($|[;&|])';       why = 'rm -rf du home' },
    # Fork bomb
    @{ rx = ':\(\)\s*\{\s*:\|:&\s*\}\s*;:';                  why = 'fork bomb' },
    # Pipe-to-shell d'un téléchargement distant (exécution de code arbitraire)
    @{ rx = '(?i)\b(curl|wget|iwr|invoke-webrequest)\b[^\n|]*\|\s*(bash|sh|powershell|pwsh|iex|invoke-expression)\b'; why = 'pipe download->shell (RCE)' },
    @{ rx = '(?i)\b(iex|invoke-expression)\b.*\b(downloadstring|invoke-webrequest|iwr)\b'; why = 'IEX download (RCE)' }
)
foreach ($k in $systemKillPatterns) {
    if ($cmd -match $k.rx) { Reject "BLOQUÉ (interdit système absolu : $($k.why)) — $($cmd.Substring(0,[Math]::Min(160,$cmd.Length)))" }
}

# Chemins système Windows protégés (écriture/suppression interdite partout)
$protectedSystemPaths = @(
    'c:\windows', 'c:\program files', 'c:\program files (x86)',
    'c:\programdata', 'c:\boot', 'c:\system volume information',
    'c:\users\default', 'c:\$recycle.bin'
)

# Registre destructeur
if ($cmd -match '(?i)\breg(\.exe)?\s+(delete|add)\s+' -or
    $cmd -match '(?i)\b(Remove-Item|Set-ItemProperty|New-ItemProperty|Remove-ItemProperty)\b.*\bHK(LM|CU|CR|U|CC):') {
    if ($cmd -match '(?i)HK(LM|CR):\\?SYSTEM' -or $cmd -match '(?i)HKEY_LOCAL_MACHINE' -or $cmd -match '(?i)reg(\.exe)?\s+delete') {
        Reject "BLOQUÉ (modification du registre Windows) — $($cmd.Substring(0,[Math]::Min(160,$cmd.Length)))"
    }
}

# git config --global / npm config set -g : altèrent l'environnement global
if ($cmd -match '(?i)\bgit\s+config\s+(--global|--system)\b' -and $cmd -notmatch '(?i)\bgit\s+config\s+(--global|--system)\s+(--get|--list|-l)\b') {
    Reject "BLOQUÉ (git config global/system en écriture — affecte tous les repos). Utilise --local."
}

# Désactivation de défenses Windows
if ($cmd -match '(?i)\b(Set-MpPreference|Disable-WindowsOptionalFeature)\b' -or
    $cmd -match '(?i)netsh\s+advfirewall\s+set.*\boff\b' -or
    $cmd -match '(?i)\bSet-ExecutionPolicy\b.*\b(Unrestricted|Bypass)\b.*\b(-Scope\s+)?(LocalMachine|CurrentUser)\b') {
    Reject "BLOQUÉ (désactivation d'une protection système) — $($cmd.Substring(0,[Math]::Min(160,$cmd.Length)))"
}

# --- 1. Détection MySQL (avant scan paths) -------------------------------------
if ($cmd -match '\b(mysql|mysqldump|mariadb)(\.exe)?\b') {
    $targetDb = $null
    if ($cmd -match '--database[=\s]+(\S+)')                 { $targetDb = $matches[1] }
    elseif ($cmd -match '\s-D\s*(\S+)')                      { $targetDb = $matches[1] }
    elseif ($cmd -match "USE\s+``?([a-zA-Z0-9_\-]+)``?")     { $targetDb = $matches[1] }
    # Forme POSITIONNELLE (la plus courante : `mysql -u root -proot NOMDB -e "..."`) :
    # le nom de DB nu juste avant -e/--execute (jamais précédé de -u/-h/-P, dont les
    # valeurs collent à l'option ou sont capturées par le lookbehind négatif).
    elseif ($cmd -match "(?<!-[a-zA-Z]|--[a-z\-]{2,20})\s([a-zA-Z0-9_$\-]+)\s+(?:-[NB]\s+)*(-e|--execute)\b") { $targetDb = $matches[1] }
    $sqlPayload = ''
    if ($cmd -match "(-e|--execute)\s+[`"']([^`"']+)[`"']") { $sqlPayload = $matches[2] }
    if ($cmd -match "(?i)\b(DROP|CREATE|INSERT|UPDATE|DELETE|TRUNCATE|ALTER|GRANT|REVOKE|RENAME)\b") {
        if ($targetDb) { $targetDb = $targetDb.ToLower().Trim('`','"',"'") }
        if (-not $projectDb) {
            Reject "MySQL write bloqué : pas de DB projet déclarée (wp-config.php DB_NAME ou .claude/allowed-db). cmd: $($cmd.Substring(0,[Math]::Min(160,$cmd.Length)))"
        }
        if ($targetDb -and $targetDb -ne $projectDb) {
            Reject "MySQL write bloqué : DB cible '$targetDb' != DB projet '$projectDb'"
        }
        # FAIL-CLOSED : un write dont la DB cible est indéterminable (ni option, ni USE,
        # ni positionnelle) peut viser n'importe quelle base (ex. table qualifiée
        # `autre_db.table`) → bloqué plutôt que présumé sain.
        if (-not $targetDb) {
            Reject "MySQL write bloqué : DB cible indéterminable (utilise `mysql <db> -e ...`, -D ou USE). cmd: $($cmd.Substring(0,[Math]::Min(160,$cmd.Length)))"
        }
    }
}

# --- 2. Détection commandes destructives + extraction paths --------------------
# Paths NON quotés (s'arrêtent au 1er espace/séparateur)
$pathRegex = '(?:[A-Za-z]:[\\/][^\s"''<>|;`&]+|/[a-z]/[^\s"''<>|;`&]+|//[a-z]/[^\s"''<>|;`&]+|\\\\[^\s"''<>|;`&]+)'
# Paths QUOTÉS (peuvent contenir des espaces) : "C:\a b\c", '/c/a b/c', '\\srv\share'. Capture l'intérieur.
$quotedPathRegex = '["'']((?:[A-Za-z]:[\\/]|//?[a-z]/|\\\\)[^"'']*)["'']'

$destructivePatterns = @(
    # Bash
    '\brm\s+', '\brmdir\s+', '\bmv\s+', '\bcp\s+', '\btee\s+',
    '\bmkdir\s+', '\btouch\s+', '\bdd\s+', '\bchmod\s+', '\bchown\s+', '\bln\s+',
    '\bshred\s+', '\btruncate\s+',
    # Builtins cmd.exe / alias PowerShell natifs de Remove-Item (utilisables sans \bRemove-Item\b) + robocopy purgeant
    '\bdel\s+', '\berase\s+', '\brd\s+', '(?i)\brobocopy\b.*\s/(mir|purge)\b',
    # Redirection shell vers FICHIER (pas stdout/stderr/dev-null) — accepte path quoté
    '(?<![0-9&])>{1,2}\s+(?!/dev/null|&\d|/dev/stderr|/dev/stdout)(?=["''A-Za-z\./~$\\])',
    # PowerShell
    '\bRemove-Item\b', '\bDelete-Item\b', '\bOut-File\b', '\bSet-Content\b',
    '\bAdd-Content\b', '\bClear-Content\b', '\bNew-Item\b', '\bCopy-Item\b',
    '\bMove-Item\b', '\bRename-Item\b', '\bWrite-Host\b\s+.*\s+>'
)

$isDestructive = $false
foreach ($pat in $destructivePatterns) {
    if ($cmd -match $pat) { $isDestructive = $true; break }
}

# --- 2a. Configuration de sécurité Claude : validation HUMAINE native ---------
# ~/.claude est une zone d'écriture libre ; sans ce contrôle, une commande shell peut
# réécrire les barrières elles-mêmes (settings, extra-roots, scripts de hooks, sentinelle).
# Décision « ask » = invite de permission du harnais, jamais un fichier écrit par le modèle.
# Détection par NOM (chemins ~, $HOME, relatifs compris) : au mieux, un filtrage par
# chaîne ne voit pas une écriture indirecte ; la barrière primaire reste les règles
# ask Edit/Write de settings.json.
$configNames = '(?i)(settings(\.local)?\.json|write-scope-extra-roots|hooks[\\/](scripts|hooks\.json)|hooks\.json|git-destructive-authorized)'
$mutating = $isDestructive -or
    $cmd -match '(?i)\b(sed|perl)\s+(-\S+\s+)*-[a-z]*i' -or
    $cmd -match '(?i)\b(python\d?|php|node|perl|ruby|pwsh|powershell)(\.exe)?\s+(-\S+\s+)*(-c|-r|-e|-Command)\b'
if ($mutating -and $cmd -match $configNames) {
    @{
        hookSpecificOutput = @{
            hookEventName            = 'PreToolUse'
            permissionDecision       = 'ask'
            permissionDecisionReason = "Commande shell qui peut modifier la configuration de sécurité Claude ($($matches[1])). Validation humaine requise."
        }
    } | ConvertTo-Json -Compress -Depth 4
    exit 0
}

if (-not $isDestructive) { exit 0 }

# --- Collecte unifiée des paths (quotés + non quotés) -------------------------
# IMPORTANT : on capture d'abord les chemins QUOTÉS (qui peuvent contenir des
# espaces, ex. "C:\Users\Cliff Rob\..."), puis on NEUTRALISE ces portions quotées
# avant d'appliquer la regex non quotée. Sinon $pathRegex s'arrête au 1er espace
# et extrait un fragment tronqué ("C:\Users\Cliff") qui échoue à tort la
# vérification de zone. Fix universel pour tout chemin contenant une espace.
$collectedPaths = @()
$cmdNoQuoted = $cmd
foreach ($m in [regex]::Matches($cmd, $quotedPathRegex)) {
    $collectedPaths += $m.Groups[1].Value
    $cmdNoQuoted = $cmdNoQuoted.Replace($m.Value, ' ')
}
foreach ($m in [regex]::Matches($cmdNoQuoted, $pathRegex)) { $collectedPaths += $m.Value }

# --- 2b. Protection chemins système (sur destructeurs) -------------------------
foreach ($pv in $collectedPaths) {
    $np = Normalize-Path $pv
    foreach ($sys in $protectedSystemPaths) {
        if ($np.StartsWith($sys)) {
            Reject "BLOQUÉ (écriture/suppression sur chemin système protégé : '$pv')"
        }
    }
}

# --- 2c. Anti-contournement : cd hors-projet, sous-shells dangereux -----------
# `cd <ailleurs> && <destructif>` : si on change de répertoire vers un path
# absolu hors-projet juste avant une commande destructrice, on bloque.
if ($cmd -match '(?i)\b(cd|Set-Location|chdir|pushd)\s+(?:"([^"]+)"|''([^'']+)''|([^\s"''&|;]+))') {
    # Cible quote-aware : entre guillemets doubles, simples, ou nue — un chemin
    # avec espaces type "C:\Users\Cliff Rob\..." se capture en entier.
    $cdTarget = if ($matches[2]) { $matches[2] } elseif ($matches[3]) { $matches[3] } else { $matches[4] }
    # Ne juger que les chemins ABSOLUS (C:\... ou /c/...), comme avant : un cd
    # relatif reste dans le répertoire courant déjà contrôlé.
    if ($cdTarget -match '^(?:[A-Za-z]:[\\/]|/[a-z]/)') {
        if (-not (Is-PathInProject $cdTarget)) {
            Reject "BLOQUÉ (cd vers '$cdTarget' hors projet avant une commande destructrice). Lance ce dossier comme projet Claude."
        }
    }
}
# rm récursif/forcé dont la CIBLE est une substitution non résolue (cible non vérifiable).
# On isole chaque invocation `rm` et on ne cherche la substitution que DANS SES PROPRES
# arguments (jusqu'au prochain séparateur ; && || |). Évite le faux positif d'un `$(...)`
# situé sur une autre commande de la même ligne (ex: PID=$(...) ; ... ; rm -f /chemin/explicite).
foreach ($rmMatch in [regex]::Matches($cmd, '\brm\b(?<args>[^;&|\r\n]*)')) {
    $rmArgs = $rmMatch.Groups['args'].Value
    if ($rmArgs -match '(^|\s)-[a-zA-Z]*[rf]' -and $rmArgs -match '\$\(|`[^`]+`|\$\{') {
        Reject "BLOQUÉ (rm récursif/forcé avec substitution comme cible — non vérifiable). Explicite le chemin."
    }
}

# --- 2d. Cibles de DÉPÔT (cp/mv/redirection) : dernier path de chaque sous-commande
# de copie/déplacement + cible des redirections. Un exécutable en CIBLE de dépôt ne
# doit PAS bénéficier de l'exemption « exécutables » de l'étape 3 (sinon on peut
# déposer un .exe/.ps1 hors zone, ex. dans un dossier Startup).
$depositTargets = @()
foreach ($m in [regex]::Matches($cmd, '(?i)\b(cp|mv|copy|xcopy|robocopy|copy-item|move-item)\b(?<args>[^;&|\r\n]*)')) {
    $argsSeg = $m.Groups['args'].Value
    $segPaths = @()
    $argsNoQ = $argsSeg
    foreach ($pm in [regex]::Matches($argsSeg, $quotedPathRegex)) {
        $segPaths += @{ v = $pm.Groups[1].Value; i = $pm.Index }
        $argsNoQ = $argsNoQ.Remove($pm.Index, $pm.Length).Insert($pm.Index, (' ' * $pm.Length))
    }
    foreach ($pm in [regex]::Matches($argsNoQ, $pathRegex)) { $segPaths += @{ v = $pm.Value; i = $pm.Index } }
    if ($segPaths.Count -ge 1) { $depositTargets += ($segPaths | Sort-Object { $_.i } | Select-Object -Last 1).v }
}
foreach ($m in [regex]::Matches($cmd, '(?<![0-9&])>{1,2}\s*(?:"([^"]+)"|''([^'']+)''|([^\s;&|<>]+))')) {
    $t = if ($m.Groups[1].Value) { $m.Groups[1].Value } elseif ($m.Groups[2].Value) { $m.Groups[2].Value } else { $m.Groups[3].Value }
    $depositTargets += $t
}

# --- 2e. Ensemble des CIBLES d'écriture/destruction ---------------------------
# Un chemin hors zone n'est une menace que s'il est une CIBLE (déposé/muté/créé),
# pas une simple SOURCE DE LECTURE. Ex. `unzip src.zip > c:\tmp\x` ou
# `cp /c/Users/.../src /c/tmp/dst` : lecture hors zone, écriture EN zone → licite,
# conformément à « lectures autorisées partout » (exfil de secrets = guard-secrets-read).
# FAIL-CLOSED : si AUCUNE cible n'est identifiable sur une commande destructive, on
# retombe au comportement strict (tout chemin hors zone bloque), cf. §3.
$writeTargets = @()
foreach ($t in $depositTargets) { $writeTargets += (Normalize-Path $t) }
# Familles qui MUTENT/DÉTRUISENT/CRÉENT en place : chaque chemin de leur propre
# segment est une cible. NE PAS inclure cp/mv/copy/move (déjà en dernier-arg via
# depositTargets) : sinon leur SOURCE de lecture serait prise pour une cible.
$inPlaceRx = '(?i)\b(rm|rmdir|del|erase|rd|shred|truncate|chmod|chown|dd|touch|mkdir|ln|Remove-Item|Delete-Item|Clear-Content|Set-Content|Add-Content|Out-File|New-Item|Rename-Item)\b(?<args>[^;&|\r\n]*)'
foreach ($m in [regex]::Matches($cmd, $inPlaceRx)) {
    $seg = $m.Groups['args'].Value
    $segNoQ = $seg
    foreach ($pm in [regex]::Matches($seg, $quotedPathRegex)) {
        $writeTargets += (Normalize-Path $pm.Groups[1].Value)
        $segNoQ = $segNoQ.Remove($pm.Index, $pm.Length).Insert($pm.Index, (' ' * $pm.Length))
    }
    foreach ($pm in [regex]::Matches($segNoQ, $pathRegex)) { $writeTargets += (Normalize-Path $pm.Value) }
}
$anyWriteTarget = ($writeTargets.Count -gt 0)

# --- 3. Vérifier chaque path absolu trouvé (quotés + non quotés) --------------
foreach ($p in $collectedPaths) {
    if ($p -match '\.(exe|bat|cmd|sh|ps1|py|pl|rb|jar|phar)$') {
        # Exemption exécutables (read+exec) SAUF si ce path est une cible de dépôt.
        $isDeposit = $false
        $pNorm = Normalize-Path $p
        foreach ($t in $depositTargets) { if ((Normalize-Path $t) -eq $pNorm) { $isDeposit = $true; break } }
        if (-not $isDeposit) { continue }
    }
    if ($p -match '/dev/(null|stdout|stderr|tty)') { continue }
    if ($p -match '^[a-z]:/+(localhost|[a-z0-9.-]+\.[a-z]{2,})(:\d+)?(/|$)') { continue }  # fragments d'URL (host[:port] avec ou sans chemin)
    if ($p -match '^[a-z]:///') { continue }  # fragment d'URL file:/// (autorité vide) : jamais la forme d'un chemin Windows
    if (-not (Is-PathInProject $p)) {
        # Hors zone : ne bloquer que si c'est une CIBLE d'écriture/destruction.
        # Si des cibles ont été identifiées et que ce chemin n'en est pas une → SOURCE
        # DE LECTURE, autorisée. Si AUCUNE cible identifiable → fail-closed, on bloque.
        if ($anyWriteTarget) {
            $pN = Normalize-Path $p
            $isTgt = $false
            foreach ($t in $writeTargets) { if ($t -eq $pN) { $isTgt = $true; break } }
            if (-not $isTgt) { continue }
        }
        Reject "BLOQUÉ (écriture/destruction hors projet : '$p'). Zones autorisées : $($allowedRoots -join ', ')"
    }
}

# --- 4. Destructeurs sans path lisible mais clairement racine -----------------
if ($cmd -match '\brm\s+-rf?\s+/\s*$' -or
    $cmd -match '\brm\s+-rf?\s+/c/\s*$' -or
    $cmd -match '\bRemove-Item\s+.*-Recurse.*[Cc]:\\?\s*$') {
    Reject "BLOQUÉ (opération destructrice sur racine) : $cmd"
}

exit 0
