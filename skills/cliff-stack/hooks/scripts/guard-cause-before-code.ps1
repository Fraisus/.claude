# guard-cause-before-code.ps1 <prompt|pre|stop> - portes (1) à (3) et (5) (references/portes.md), sur un défaut signalé.
# prompt : le rappel, dès le message qui signale un défaut. pre : aucune écriture de code tant que le tour n'a pas écrit
# « Cause : <fichier:ligne> » et « Niveau : ... », ou « Demande : ... » pour un comportement demandé sans cause à trouver.
# stop : pas d'annonce de résultat sans « Ailleurs : ... » et une recherche lancée ; sur un défaut qui revient, pas sans
# independent-critic après la dernière écriture. Le hook impose l'ordre et l'écrit, il ne juge pas la justesse : c'est le
# critique et l'utilisateur qui la jugent. Une panne du script ne bloque jamais (exit 0).
# Les déclarations se lisent dans le texte du tour ET dans les appels shell du tour (commande et description) : certains
# hôtes n'écrivent pas au journal le texte qui accompagne un appel d'outil (mesuré le 08/11/2026 dans l'extension VS Code :
# des textes portant « Cause : » absents du journal, leurs appels d'outils présents). Un `echo` des lignes reste visible de
# l'utilisateur et toujours enregistré.

param([string]$Mode = 'pre')
$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [System.Text.Encoding]::UTF8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$DEFECT = "(?i)\b(bugs?|bogues?|cass[ée]e?s?|plante|(ne|n')\s*\S+\s+(pas|plus)|marche pas|fonctionne pas|plus rien|se passe rien|erreurs?|[ée]chou[ée]e?s?|(?<!sans )probl[èe]mes?|soucis|d[ée]fauts?|r[ée]gressions?|pas normal|doesn't work|not working|broken)\b"
$AGAIN = "(?i)(toujours (le |la |les )?m[êe]mes?|toujours pas|marche toujours|encore (le |ce |un |une )?(bug|soucis|probl[èe]me|d[ée]faut)|[çc]a revient|est revenue?|m[êe]mes? (soucis|probl[èe]me|bug|d[ée]faut)|same (issue|bug)|still (broken|not))"
$EXT = '(py|js|mjs|cjs|ts|tsx|jsx|vue|svelte|astro|php|go|rs|java|kt|c|cpp|h|rb|swift|css|scss|html|ps1|sh|json)'
$CODE = "\.$EXT$"
$SCRATCH = '(?i)(tmp_essais|scratchpad|AppData[\\/]Local[\\/]Temp)'
# one command's segment that writes a code file, as far as a pattern can tell (a garde-fou, never a prison)
$SHELL_WRITE = "(?i)(\b(sed|perl)\s+-i[^|;&]*\.$EXT\b|(^|[^-=>])>>?\s*[`"']?[^\s`"'>|&;]+\.$EXT\b|\b(tee|cp|copy|mv|move|Copy-Item|Move-Item|Set-Content|Add-Content|Out-File)\b[^|;&]*\.$EXT\b|\bWriteAllText\b|\bgit\s+apply\b|\b(python3?|py|node)\s+-c\b.*(open\(|write|writeFile))"
$FIX_SCRIPT = '(?i)\b(python3?|py|node)\s+(-\w+\s+)*["'']?[^"''\r\n]*(tmp|temp|scratch)[^"''\r\n]*(fix|patch)\w*\.(py|js|mjs)'
$SEARCH = '(?i)(^|[|;&]\s*)(grep|rg|Select-String|git\s+grep)\b'
$WRITERS = '(?i)^(|general-purpose|fork|claude|.*ouvrier-zone|.*reactorm-interface)$'  # an agent that may write code: the turn answers for it
$CLAIM = '(?i)\b(corrig[ée]e?s?|r[ée]gl[ée]e?s?|r[ée]par[ée]e?s?|r[ée]solue?s?|(?<!pas )fini|termin[ée]e?s?|livr[ée]e?s?|en place|c''est fait|(marche|fonctionne) maintenant|fixed|done)\b'
$CAUSE = '(?m)^\W*Cause\b\W*:.*\S+\.\w+(:\d+|#L\d+)'
$LEVEL = '(?m)^\W*Niveau\b\W*:\s*\S'
$ASKED = '(?m)^\W*Demande\b\W*:\s*\S'
$ELSEWHERE = '(?m)^\W*Ailleurs\b\W*:\s*\S'
$CRITIC_AGENT = 'independent-critic'
$TAIL_BYTES = 8MB  # of the transcript's end, read at each code edit: a turn longer than this is judged on what it holds

function PromptOf($e) {
    # what the user wrote: a string, or the text blocks of a list; a tool's result, a system's note or a notification is none
    if ($e.isMeta -or $e.isCompactSummary) { return $null }
    $c = if ($e.type -eq 'attachment') { if ($e.attachment.type -eq 'queued_command' -and -not $e.attachment.isMeta) { $e.attachment.prompt } } else { $e.message.content }
    $text = if ($c -is [string]) { $c } else { (@($c) | Where-Object { $_.type -eq 'text' } | ForEach-Object { $_.text }) -join "`n" }
    if (-not $text) { return $null }
    $text = [regex]::Replace($text, '(?s)<system-reminder>.*?</system-reminder>', '')
    if ($text -match '^\s*<(task-notification|command-|local-command)' -or -not $text.Trim()) { return $null }
    return $text
}

function WritesCode($name, $inp) {
    if ($name -in 'Write', 'Edit', 'MultiEdit', 'NotebookEdit') { return $inp.file_path -match $CODE -and $inp.file_path -notmatch $SCRATCH }
    if ($name -in 'Bash', 'PowerShell') {
        if ($inp.command -match $FIX_SCRIPT) { return $true }
        # segment by segment: a write to a scratch file beside another command never hides a real one
        foreach ($part in ($inp.command -split '[;&|\r\n]+')) { if ($part -match $SHELL_WRITE -and $part -notmatch $SCRATCH) { return $true } }
        return $false
    }
    if ($name -eq 'Agent') { return "$($inp.subagent_type)" -match $WRITERS }
    return $false
}

# what the turn said: its text blocks, and its shell calls' command and description (a host may not record the text)
function SaidOf($blocks) {
    $parts = foreach ($b in @($blocks)) {
        if ($b.type -eq 'text') { $b.text }
        # an echoed line starts where its text starts: « echo "Cause : … » reads as « Cause : … »
        elseif ($b.type -eq 'tool_use' -and $b.name -in 'Bash', 'PowerShell') { "$($b.input.description)`n" + [regex]::Replace("$($b.input.command)", '(?m)^\s*(echo|printf|Write-Output)\s+[''"]?', '') }
    }
    return ($parts -join "`n")
}

# the turn, read from the transcript's end back to the last thing the user wrote: its texts and tool calls, oldest first
function TurnOf($path) {
    $stream = [System.IO.File]::Open($path, 'Open', 'Read', 'ReadWrite')
    try {
        $start = [Math]::Max(0, $stream.Length - $TAIL_BYTES)
        [void]$stream.Seek($start, 'Begin')
        $reader = New-Object System.IO.StreamReader($stream, [System.Text.Encoding]::UTF8)
        $lines = $reader.ReadToEnd() -split "`n"
    } finally { $stream.Dispose() }
    if ($start -gt 0 -and $lines.Count -gt 1) { $lines = $lines[1..($lines.Count - 1)] }  # the first one cut by the seek
    $prompt = $null
    $blocks = New-Object System.Collections.ArrayList
    for ($i = $lines.Count - 1; $i -ge 0; $i--) {
        $l = $lines[$i]
        $isUser = $l -match '"type"\s*:\s*"user"' -or $l -match '"queued_command"'
        if ($isUser -and $l -match '"tool_result"' -and $l -notmatch '"type"\s*:\s*"text"') { continue }
        if (-not $isUser -and $l -notmatch '"type"\s*:\s*"assistant"') { continue }
        try { $e = $l | ConvertFrom-Json } catch { continue }
        if ($e.type -in 'user', 'attachment') {
            $prompt = PromptOf $e
            if ($prompt) { break }
            continue
        }
        if ($e.type -ne 'assistant') { continue }
        $list = @($e.message.content)
        for ($j = $list.Count - 1; $j -ge 0; $j--) { [void]$blocks.Insert(0, $list[$j]) }
    }
    return @{ prompt = $prompt; blocks = $blocks }
}

try {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    $in = $raw | ConvertFrom-Json
    if ($in.agent_id) { exit 0 }  # a sub-agent works under the turn that declared

    if ($Mode -eq 'prompt') {
        $said = [string]$in.prompt
        if ($said -notmatch $DEFECT -and $said -notmatch $AGAIN) { exit 0 }
        $note = 'Si ce message signale un défaut observé : portes (1) à (3) avant toute écriture : le reproduire dans l''état que décrit l''utilisateur, jamais seulement sur un état plus simple ; localiser la cause et son niveau d''après ses usages (grep). Le code ne s''écrit qu''après les lignes « Cause : fichier:ligne » et « Niveau : ... » dans le texte du tour, ou « Demande : ... » pour un comportement demandé. Cause non établie : le dire et s''arrêter, jamais un correctif de ce qu''on mesure à la place.'
        if ($said -match $AGAIN) { $note += ' Un correctif précédent n''a pas suffi : son diagnostic est faux ou incomplet, remonter d''un niveau et le requestionner avant tout code.' }
        Write-Output $note
        exit 0
    }

    $path = $in.transcript_path
    if (-not $path -or -not (Test-Path -LiteralPath $path)) { exit 0 }

    if ($Mode -eq 'pre') {
        if ($in.tool_name -eq 'Agent' -or -not (WritesCode $in.tool_name $in.tool_input)) { exit 0 }
        $turn = TurnOf $path
        if (-not $turn.prompt -or ($turn.prompt -notmatch $DEFECT -and $turn.prompt -notmatch $AGAIN)) { exit 0 }
        $text = SaidOf $turn.blocks
        if ($text -match $ASKED -or ($text -match $CAUSE -and $text -match $LEVEL)) { exit 0 }
        [Console]::Error.WriteLine('Portes (1) à (3) : défaut signalé, aucune écriture de code avant d''avoir écrit dans le texte du tour « Cause : fichier:ligne, preuve » et « Niveau : composant, page, config, framework, d''après ses usages », ou « Demande : ... » pour un comportement que l''utilisateur a demandé. Le texte déjà écrit n''est pas au journal : l''écrire aussi par un echo du shell, chaque ligne en tête de ligne. Cause non établie : le dire à l''utilisateur et s''arrêter.')
        exit 2
    }

    if ($Mode -eq 'stop') {
        if ($in.stop_hook_active) { exit 0 }
        $turn = TurnOf $path
        if (-not $turn.prompt -or ($turn.prompt -notmatch $DEFECT -and $turn.prompt -notmatch $AGAIN)) { exit 0 }
        $wrote, $searched, $critic, $text = -1, $false, -1, ''
        $k = 0
        foreach ($b in @($turn.blocks)) {
            $k++
            if ($b.type -eq 'text') { $text = $b.text; continue }
            if ($b.type -ne 'tool_use') { continue }
            if (WritesCode $b.name $b.input) { $wrote = $k }
            if ($b.name -eq 'Grep' -or ($b.name -in 'Bash', 'PowerShell' -and $b.input.command -match $SEARCH)) { $searched = $true }
            if ($b.name -eq 'Agent' -and "$($b.input.subagent_type)" -match $CRITIC_AGENT) { $critic = $k }
            # Resuming the critic launched in this turn re-submits the new lot to it.
            elseif ($b.name -eq 'SendMessage' -and $critic -ge 0) { $critic = $k }
        }
        if ($wrote -lt 0 -or $text -notmatch $CLAIM) { exit 0 }
        $all = SaidOf $turn.blocks
        if (-not $searched -or $all -notmatch $ELSEWHERE) {
            [Console]::Error.WriteLine('Porte (5) : même question ailleurs. Chercher le même motif dans le code, puis écrire « Ailleurs : la recherche, n occurrences, ce qui en est fait » avant d''annoncer un résultat.')
            exit 2
        }
        if ($turn.prompt -match $AGAIN -and $critic -lt $wrote) {
            [Console]::Error.WriteLine('Défaut qui revient : le lot passe par independent-critic après la dernière écriture, avec l''artefact et le critère, avant d''annoncer un résultat.')
            exit 2
        }
    }
    exit 0
} catch { exit 0 }
