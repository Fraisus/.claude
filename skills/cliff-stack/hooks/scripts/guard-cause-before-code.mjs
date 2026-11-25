// guard-cause-before-code.mjs <prompt|pre|stop> - gates (1) to (3) and (5) (references/portes.md), on a reported defect.
// prompt: the reminder, from the message that reports a defect. pre: no code write until the turn has written
// « Cause : <fichier:ligne> » and « Niveau : ... », or « Demande : ... » for a requested behaviour with no cause to find.
// stop: no announced result without « Ailleurs : ... » and a search run; on a defect that comes back, not without
// independent-critic after the last write. The hook enforces the order, it never judges correctness: the critic and the
// user do. A failure of the script never blocks (exit 0).
// Declarations are read in the turn's text AND in its shell calls (command and description): some hosts do not record
// the text that goes with a tool call in the transcript (VS Code extension, 08/11/2026). An `echo` of the lines stays
// visible to the user and is always recorded.
import { closeSync, existsSync, fstatSync, openSync, readSync } from 'node:fs';
import { launchWrites } from './lib/agents.mjs';
import { refuse, run, str } from './lib/hook.mjs';
import { B, BOL, W, rx } from './lib/regex.mjs';

// .NET \w, \W, \b, ^ (multiline) and . (no \n), whose behaviour this hook keeps, differ in JS: ASCII-only word
// characters (« échoué ») and \r counted as a line end.

const DEFECT = rx(String.raw`${B}(bugs?|bogues?|cass[ée]e?s?|plante|(ne|n')\s*\S+\s+(pas|plus)|marche pas|fonctionne pas|plus rien|se passe rien|erreurs?|[ée]chou[ée]e?s?|(?<!sans )probl[èe]mes?|soucis|d[ée]fauts?|r[ée]gressions?|pas normal|doesn't work|not working|broken)${B}`);
const AGAIN = rx(String.raw`(toujours (le |la |les )?m[êe]mes?|toujours pas|marche toujours|encore (le |ce |un |une )?(bug|soucis|probl[èe]me|d[ée]faut)|[çc]a revient|est revenue?|m[êe]mes? (soucis|probl[èe]me|bug|d[ée]faut)|same (issue|bug)|still (broken|not))`);
const EXT = '(py|js|mjs|cjs|ts|tsx|jsx|vue|svelte|astro|php|go|rs|java|kt|c|cpp|h|rb|swift|css|scss|html|ps1|sh|json)';
const CODE = rx(String.raw`\.${EXT}(?=\n?$)`);
const SCRATCH = rx(String.raw`(tmp_essais|scratchpad|AppData[\\/]Local[\\/]Temp)`);
// one command's segment that writes a code file, as far as a pattern can tell (a garde-fou, never a prison)
const SHELL_WRITE = rx(String.raw`(${B}(sed|perl)\s+-i[^|;&]*\.${EXT}${B}|(^|[^\-=>])>>?\s*["']?[^\s"'>|&;]+\.${EXT}${B}|${B}(tee|cp|copy|mv|move|Copy-Item|Move-Item|Set-Content|Add-Content|Out-File)${B}[^|;&]*\.${EXT}${B}|${B}WriteAllText${B}|${B}git\s+apply${B}|${B}(python3?|py|node)\s+-c${B}[^\n]*(open\(|write|writeFile))`);
const FIX_SCRIPT = rx(String.raw`${B}(python3?|py|node)\s+(-[${W}]+\s+)*["']?[^"'\r\n]*(tmp|temp|scratch)[^"'\r\n]*(fix|patch)[${W}]*\.(py|js|mjs)`);
const SEARCH = rx(String.raw`(^|[|;&]\s*)(grep|rg|Select-String|git\s+grep)${B}`);
const CLAIM = rx(String.raw`${B}(corrig[ée]e?s?|r[ée]gl[ée]e?s?|r[ée]par[ée]e?s?|r[ée]solue?s?|(?<!pas )fini|termin[ée]e?s?|livr[ée]e?s?|en place|c'est fait|(marche|fonctionne) maintenant|fixed|done)${B}`);
const declared = (word) => rx(String.raw`${BOL}[^${W}]*${word}${B}[^${W}]*:\s*\S`);
const CAUSE = rx(String.raw`${BOL}[^${W}]*Cause${B}[^${W}]*:[^\n]*\S+\.[${W}]+(:\p{Nd}+|#L\p{Nd}+)`);
const LEVEL = declared('Niveau');
const ASKED = declared('Demande');
const ELSEWHERE = declared('Ailleurs');
const CRITIC_AGENT = /independent-critic/iu;
const ECHO = new RegExp(String.raw`${BOL}\s*(echo|printf|Write-Output)\s+['"]?`, 'gu'); // case-sensitive, as [regex]::Replace
const TAIL_BYTES = 8 * 1024 * 1024; // of the transcript's end, read at each code edit: a turn longer than this is judged on what it holds

const isIn = (name, ...names) => names.includes(str(name).toLowerCase());
const asList = (v) => (Array.isArray(v) ? v : [v]);
const reportsDefect = (said) => DEFECT.test(said) || AGAIN.test(said);

// what the user wrote: a string, or the text blocks of a list; a tool's result, a system's note or a notification is none
function promptOf(e) {
  if (e.isMeta || e.isCompactSummary) return null;
  const c = isIn(e.type, 'attachment')
    ? isIn(e.attachment?.type, 'queued_command') && !e.attachment.isMeta ? e.attachment.prompt : undefined
    : e.message?.content;
  let text = typeof c === 'string' ? c : (c == null ? [] : asList(c)).filter((b) => isIn(b?.type, 'text')).map((b) => str(b.text)).join('\n');
  if (!text) return null;
  text = text.replace(/<system-reminder>[\s\S]*?<\/system-reminder>/gu, '');
  if (/^\s*<(task-notification|command-|local-command)/iu.test(text) || !text.trim()) return null;
  return text;
}

function writesCode(name, inp) {
  if (isIn(name, 'write', 'edit', 'multiedit', 'notebookedit')) return CODE.test(str(inp?.file_path)) && !SCRATCH.test(str(inp?.file_path));
  if (isIn(name, 'bash', 'powershell')) {
    const cmd = str(inp?.command);
    if (FIX_SCRIPT.test(cmd)) return true;
    // segment by segment: a write to a scratch file beside another command never hides a real one
    return cmd.split(/[;&|\r\n]+/u).some((part) => SHELL_WRITE.test(part) && !SCRATCH.test(part));
  }
  if (isIn(name, 'agent')) return launchWrites(str(inp?.subagent_type)); // the turn answers for an agent that may write code
  return false;
}

// what the turn said: its text blocks, and its shell calls' command and description (a host may not record the text)
function saidOf(blocks) {
  const parts = [];
  for (const b of blocks) {
    if (isIn(b?.type, 'text')) parts.push(str(b.text));
    // an echoed line starts where its text starts: « echo "Cause : … » reads as « Cause : … »
    else if (isIn(b?.type, 'tool_use') && isIn(b.name, 'bash', 'powershell')) parts.push(`${str(b.input?.description)}\n${str(b.input?.command).replace(ECHO, '')}`);
  }
  return parts.join('\n');
}

function tailText(path) {
  const fd = openSync(path, 'r');
  try {
    const size = fstatSync(fd).size;
    const start = Math.max(0, size - TAIL_BYTES);
    const buf = Buffer.alloc(size - start);
    readSync(fd, buf, 0, buf.length, start);
    return { start, text: buf.toString('utf8').replace(/^﻿/u, '') };
  } finally {
    closeSync(fd);
  }
}

// the turn, read from the transcript's end back to the last thing the user wrote: its texts and tool calls, oldest first
function turnOf(path) {
  const { start, text } = tailText(path);
  let lines = text.split('\n');
  if (start > 0 && lines.length > 1) lines = lines.slice(1); // the first one cut by the seek
  let prompt = null;
  const blocks = [];
  for (let i = lines.length - 1; i >= 0; i--) {
    const l = lines[i];
    const isUser = /"type"\s*:\s*"user"/iu.test(l) || /"queued_command"/iu.test(l);
    if (isUser && /"tool_result"/iu.test(l) && !/"type"\s*:\s*"text"/iu.test(l)) continue;
    if (!isUser && !/"type"\s*:\s*"assistant"/iu.test(l)) continue;
    let e;
    try {
      e = JSON.parse(l);
    } catch {
      continue;
    }
    if (e == null) continue;
    if (isIn(e.type, 'user', 'attachment')) {
      prompt = promptOf(e);
      if (prompt) break;
      continue;
    }
    if (!isIn(e.type, 'assistant')) continue;
    blocks.unshift(...asList(e.message?.content));
  }
  return { prompt, blocks };
}

const mode = process.argv[2] || 'pre';

run((p) => {
  if (!p || p.agent_id) return; // a sub-agent works under the turn that declared

  if (mode.toLowerCase() === 'prompt') {
    const said = str(p.prompt);
    if (!reportsDefect(said)) return;
    let note = "Si ce message signale un défaut observé : portes (1) à (3) avant toute écriture : le reproduire dans l'état que décrit l'utilisateur, jamais seulement sur un état plus simple ; localiser la cause et son niveau d'après ses usages (grep). Le code ne s'écrit qu'après les lignes « Cause : fichier:ligne » et « Niveau : ... » dans le texte du tour, ou « Demande : ... » pour un comportement demandé. Cause non établie : le dire et s'arrêter, jamais un correctif de ce qu'on mesure à la place.";
    if (AGAIN.test(said)) note += " Un correctif précédent n'a pas suffi : son diagnostic est faux ou incomplet, remonter d'un niveau et le requestionner avant tout code.";
    process.stdout.write(`${note}\n`);
    return;
  }

  const path = p.transcript_path;
  if (!path || !existsSync(path)) return;

  if (mode.toLowerCase() === 'pre') {
    if (isIn(p.tool_name, 'agent') || !writesCode(p.tool_name, p.tool_input)) return;
    const turn = turnOf(path);
    if (!turn.prompt || !reportsDefect(turn.prompt)) return;
    const text = saidOf(turn.blocks);
    if (ASKED.test(text) || (CAUSE.test(text) && LEVEL.test(text))) return;
    refuse("Portes (1) à (3) : défaut signalé, aucune écriture de code avant d'avoir écrit dans le texte du tour « Cause : fichier:ligne, preuve » et « Niveau : composant, page, config, framework, d'après ses usages », ou « Demande : ... » pour un comportement que l'utilisateur a demandé. Le texte déjà écrit n'est pas au journal : l'écrire aussi par un echo du shell, chaque ligne en tête de ligne. Cause non établie : le dire à l'utilisateur et s'arrêter.");
  }

  if (mode.toLowerCase() === 'stop') {
    if (p.stop_hook_active) return;
    const turn = turnOf(path);
    if (!turn.prompt || !reportsDefect(turn.prompt)) return;
    let wrote = -1;
    let searched = false;
    let critic = -1;
    let text = '';
    turn.blocks.forEach((b, idx) => {
      const k = idx + 1;
      if (isIn(b?.type, 'text')) {
        text = b.text;
        return;
      }
      if (!isIn(b?.type, 'tool_use')) return;
      if (writesCode(b.name, b.input)) wrote = k;
      if (isIn(b.name, 'grep') || (isIn(b.name, 'bash', 'powershell') && SEARCH.test(str(b.input?.command)))) searched = true;
      if (isIn(b.name, 'agent') && CRITIC_AGENT.test(str(b.input?.subagent_type))) critic = k;
      // Resuming the critic launched in this turn re-submits the new lot to it.
      else if (isIn(b.name, 'sendmessage') && critic >= 0) critic = k;
    });
    if (wrote < 0 || !CLAIM.test(str(text))) return;
    if (!searched || !ELSEWHERE.test(saidOf(turn.blocks))) {
      refuse("Porte (5) : même question ailleurs. Chercher le même motif dans le code, puis écrire « Ailleurs : la recherche, n occurrences, ce qui en est fait » avant d'annoncer un résultat.");
    }
    if (AGAIN.test(turn.prompt) && critic < wrote) {
      refuse("Défaut qui revient : le lot passe par independent-critic après la dernière écriture, avec l'artefact et le critère, avant d'annoncer un résultat.");
    }
  }
});
