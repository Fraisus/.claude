// guard-git-destructive.mjs - PreToolUse guard against git commands that destroy uncommitted work: the human is
// asked (permissionDecision "ask") before checkout -- / . / -f / <path>, restore, reset --hard, clean -f, stash
// drop/clear. Lets through status, diff, log, add, commit, fetch, pull, push, branch, checkout -b, switch without -f,
// stash push/list/show/pop/apply. Quoted text (commit messages, heredocs) is removed before the analysis, and a parse
// doubt lets the command through: a false positive would freeze legitimate work.
import { agentTranscript, readMeta } from './agent-usage.mjs';
import { ask, deny, run } from './lib/hook.mjs';
import { B, W, rx } from './lib/regex.mjs';

// .NET \w and \b, whose behaviour this guard keeps, are Unicode-aware; the JS ones are ASCII-only.

const PERMIS = /^(status|diff|log|show|add|commit|merge|branch|mv|rm|rev-parse|rev-list|merge-base|ls-files|ls-tree|blame|grep|show-ref|describe|cat-file|diff-tree|shortlog|name-rev|for-each-ref|help)$/iu;
const VAL = String.raw`(?:"[^"]*"|'[^']*'|[^\s"']+)`;
const OPT = String.raw`(?:(?:-C|-c|--git-dir|--work-tree|--namespace|--config-env)\s+${VAL}|--[${W}-]+(?:=${VAL})?|-[A-Za-z]+)`;
const GIT_CALL = String.raw`(?<![${W}.-])["']?git(?:\.exe)?["']?((?:\s+${OPT})*)\s+([a-z][${W}-]*)([^\r\n;&|()$\x60]*)`;
// A heredoc body (the harness's commit message) is text, not commands.
const HEREDOC = rx(String.raw`<<-?\s*['"]?([${W}]+)['"]?[\s\S]*?\r?\n\1${B}`, 'g');

const READS = {
  worktree: (rest) => rx(String.raw`^\s*list${B}`).test(rest),
  stash: (rest) => rx(String.raw`^\s*(list|show)${B}`).test(rest),
  remote: (rest) => !rx(String.raw`${B}(add|remove|rm|rename|set-url|set-head|set-branches|prune|update)${B}`).test(rest),
  config: (rest) => rx(String.raw`^\s*(--get(-all|-regexp)?|--list|-l|get|list)${B}`).test(rest),
  reflog: (rest) => !rx(String.raw`${B}(expire|delete|drop)${B}`).test(rest),
};
// Unique prefixes only (--d, --mo, --cop, --forc): --co and --fo also name --contains, --format.
const BRANCH_WRITE = rx(String.raw`(^|\s)(-[a-zA-Z]*[dDmMcCf][a-zA-Z]*${B}|--(d|mo|cop|forc)[a-z-]*)`);

function agentType(p) {
  const type = String(p.agent_type ?? '');
  if (type) return type;
  try {
    const file = agentTranscript({ transcriptPath: String(p.transcript_path ?? ''), agentId: String(p.agent_id) });
    return String((file && readMeta(file)?.agentType) ?? '');
  } catch {
    return '';
  }
}

// Zone worker (agents/ouvrier-zone.md): a closed list of subcommands, refused without a prompt (no human waits on a
// subagent). Every git of the raw command is read (several lines, sh -c "...", $(...)), heredoc bodies aside; a -m
// message whose punctuation restarts the reading (« ; git x ») may be refused: the worker rephrases. A net against a
// good-faith agent's mistake, not a barrier: a hidden subcommand (quotes, variable, xargs, eval) escapes a reading of
// the text; what forbids the push to the worker is its contract (agents/ouvrier-zone.md).
function workerRefusal(cmd) {
  for (const m of cmd.replace(HEREDOC, ' ').matchAll(rx(GIT_CALL, 'g'))) {
    const sub = m[2].toLowerCase();
    const rest = m[3];
    const read = Object.hasOwn(READS, sub) && READS[sub](rest);
    if ((!PERMIS.test(sub) && !read) || (sub === 'branch' && BRANCH_WRITE.test(rest))) {
      return `Ouvrier de zone : git ${sub} refusé. Permis : add et commit de tes fichiers, merge du tronc si le brief le demande, lectures (status, diff, log, show). Rends un signal au thread principal pour le reste.`;
    }
  }
  return null;
}

const DESTRUCTIVE = [
  [rx(String.raw`${B}git${B}.*${B}reset${B}.*--hard`), "git reset --hard (jette le working tree et l'index)"],
  [rx(String.raw`${B}git${B}.*${B}clean${B}.*(?:-[a-z]*f|--force)`), 'git clean -f/-d/-x (supprime des fichiers non suivis)'],
  [rx(String.raw`${B}git${B}.*${B}stash${B}.*${B}(drop|clear)${B}`), 'git stash drop/clear (perte de stash)'],
  [rx(String.raw`${B}git${B}.*${B}restore${B}`), 'git restore (écrase des modifications non commitées)'],
];
const CHECKOUT = rx(String.raw`${B}git${B}.*${B}checkout${B}`);
const CHECKOUT_BRANCH = rx(String.raw`${B}checkout${B}\s+-b${B}`);
const CHECKOUT_FORCE = rx(String.raw`${B}checkout${B}\s+(--|\.|-f${B}|--force${B})`);
const CHECKOUT_PATH = [rx(String.raw`${B}checkout${B}\s+[^ ]*[\\/][^ ]*`), rx(String.raw`${B}checkout${B}\s+[^ ]+\.[a-z0-9]+${B}`)];

function destructiveReason(cmd) {
  const stripped = cmd.replace(rx(`<<'?EOF'?[\\s\\S]*?EOF`, 'g'), ' ').replace(/"[^"]*"/gu, ' ').replace(/'[^']*'/gu, ' ');
  // A call may chain several commands (&&, ;, |): each segment is tested.
  for (const seg of stripped.replace(/\s+/gu, ' ').split(/(?:&&|\|\||;|\|)/u)) {
    const s = seg.trim();
    if (!rx(String.raw`${B}git${B}`).test(s)) continue;
    const hit = DESTRUCTIVE.find(([re]) => re.test(s));
    if (hit) return hit[1];
    if (!CHECKOUT.test(s) || CHECKOUT_BRANCH.test(s)) continue;
    if (CHECKOUT_FORCE.test(s)) return 'git checkout -- / . / -f (restaure et écrase des modifications non commitées)';
    if (CHECKOUT_PATH.some((re) => re.test(s))) return 'git checkout <fichier> (restaure et écrase une version non commitée)';
  }
  return null;
}

run((p) => {
  const cmd = String(p?.tool_input?.command ?? '');
  if (!cmd) return;
  if (p.agent_id && /git/iu.test(cmd) && agentType(p).replace(/^[^:]+:/u, '').toLowerCase() === 'ouvrier-zone') {
    const why = workerRefusal(cmd);
    if (why) return deny(why);
  }
  const reason = destructiveReason(cmd);
  // "ask": the consent comes from the harness's permission prompt, never from a file the model writes.
  if (reason) ask(`Git destructif pour du travail non commité : ${reason}. Validation humaine requise.`);
});
