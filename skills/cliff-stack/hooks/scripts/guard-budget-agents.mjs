// guard-budget-agents.mjs <pre|post|stop> - the single guard of the agent budget; caps come from ../budget-agents.json only.
//   pre   PreToolUse  Workflow | Agent | SendMessage
//   post  PostToolUse Agent
//   stop  SubagentStop
// Workflow (blocking whatever the mode): refused when launched by `name:` alone (script never read, fan-out unknown),
// without `// agents-max: N`, or with N > total_par_plan and no `// agents-max-user-ok`; a resume, a missing scriptPath
// or a parse error lets it through. A deep-research launched by name once started 106 agents.
// Agent: counts the session's live agents (state <equipe-log>/etat/<session>.json, TTL ttl_agent_min), classifies the
// role from the agent's definition, applies simultanes, playwright_simultanes, an identical live launch, the P2 rules
// (only with CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1) and nesting (a subagent launches readers only, never a workflow).
// In `journal` mode only P2 and nesting refuse; the other refusals are logged `aurait_refuse`. Every decision leaves a
// line in decisions.jsonl with the keys really received (tool_input, tool_response).
// Any internal error lets the tool through and is logged. Test variables: CLIFF_EQUIPE_LOG_DIR, CLIFF_EQUIPE_BUDGET;
// CLIFF_EQUIPE_<KEY> overrides a top-level numeric cap, bounded by plafond_harness.defaut_documente.
import { createHash } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync, readdirSync, readFileSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { budgetPath } from './agent-usage.mjs';
import { classify, launchWrites } from './lib/agents.mjs';
import { appendJsonl, equipeLogDir, oneOf, refuse, run, same, str } from './lib/hook.mjs';
import { B, BOL, rx } from './lib/regex.mjs';

const MODE = process.argv[2] || 'pre';
const NOW = new Date();
const TS = NOW.toISOString();
const HERE = dirname(fileURLToPath(import.meta.url));
const BUDGET = budgetPath();
const HINT = "Plafonds : skills/cliff-stack/hooks/budget-agents.json. Ne contourne pas ce garde : rapporte le refus tel quel, attends la fin d'un agent, ou demande a l'utilisateur.";

const DECLARED = rx(String.raw`${BOL}\s*//\s*agents-max\s*:\s*(\p{Nd}+)`);
const USER_OK = rx(String.raw`${BOL}\s*//\s*agents-max-user-ok${B}`);
const DIGITS = /^\p{Nd}+(?=\n?$)/u;

let SID = 'sans-session';
let LOG = null;

// PowerShell [int]: what is not a number or overflows Int32 throws, and the guard then lets the tool through.
function int(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || Math.abs(n) > 2147483647) throw new Error(`[int] ${v}`);
  return Math.round(n);
}
const readJson = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^﻿/u, ''));
const launcherOf = (p) => (p.agent_id ? String(p.agent_id) : 'principal');
const alive = (agents) => agents.filter((a) => !a?.fini);
const briefLine = (prompt, key) => rx(String.raw`${BOL}${key}:\s*(\S[^\n]*?)\s*(?=\n|$)`).exec(prompt)?.[1] ?? null;
const fingerprint = (ti) =>
  createHash('sha256').update(`${str(ti.subagent_type)}\n${str(ti.description)}\n${str(ti.prompt)}`, 'utf8').digest('hex').slice(0, 16);

const decide = (tool, fields) => appendJsonl(LOG, 'decisions.jsonl', { ts: TS, session_id: SID, mode: MODE, tool, ...fields });
const refuseAs = (tool, reason) => refuse(`BLOQUE par guard-budget-agents (${tool}) : ${reason}\n${HINT}`);

function readBudget() {
  let b;
  try {
    b = readJson(BUDGET);
  } catch {
    return null;
  }
  if (!b || typeof b !== 'object') return null;
  let cap = 20;
  try {
    if (b.plafond_harness?.defaut_documente) cap = int(b.plafond_harness.defaut_documente);
  } catch {
    // keep the documented harness default
  }
  for (const [key, value] of Object.entries(b)) {
    if (!Number.isInteger(value)) continue; // PowerShell 5.1 reads JSON decimals as Decimal, which it never overrode
    const v = process.env[`CLIFF_EQUIPE_${key.toUpperCase()}`];
    if (v && DIGITS.test(v)) b[key] = Math.min(int(v), cap);
  }
  return b;
}

function stateDir() {
  const dir = join(LOG, 'etat');
  mkdirSync(dir, { recursive: true });
  return dir;
}

function openState(dir) {
  const path = join(dir, `${SID}.json`);
  let etat = null;
  if (existsSync(path)) {
    try {
      etat = readJson(path);
    } catch {
      etat = null;
    }
  }
  if (!etat || typeof etat !== 'object' || Array.isArray(etat)) etat = { session_id: SID, agents: [] };
  if (!Array.isArray(etat.agents)) etat.agents = etat.agents == null ? [] : [etat.agents];
  return { path, etat };
}

function saveState({ path, etat }) {
  writeFileSync(`${path}.tmp`, JSON.stringify(etat, null, 2));
  try {
    renameSync(`${path}.tmp`, path);
  } catch {
    // a replace that fails keeps the previous state; the decision still stands
  }
  try {
    const dir = dirname(path);
    const old = NOW.getTime() - 7 * 86400000;
    for (const name of readdirSync(dir)) {
      if (!/\.json$/iu.test(name)) continue;
      const f = join(dir, name);
      if (statSync(f).mtimeMs < old) rmSync(f, { force: true });
    }
  } catch {
    // purge of old sessions is best effort
  }
}

function expire(etat, ttlMin) {
  const limit = NOW.getTime() - ttlMin * 60000;
  for (const a of etat.agents) {
    if (a?.fini) continue;
    const launched = Date.parse(a?.lance);
    if (Number.isNaN(launched)) throw new Error(`lance illisible : ${a?.lance}`);
    if (launched < limit) a.fini = 'perime';
  }
}

const sleep = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);

// Node has no named mutex: an exclusive lock file next to the state stands in. Like the mutex wait, it gives up after
// 3 s and goes on; unlike a mutex a file outlives a killed holder, so the late comer takes it over and removes it.
function withLock(dir, body) {
  const lock = join(dir, `${SID}.lock`);
  const end = Date.now() + 3000;
  let held = false;
  for (;;) {
    try {
      closeSync(openSync(lock, 'wx'));
      held = true;
      break;
    } catch (e) {
      if (e.code !== 'EEXIST' && e.code !== 'EPERM') break;
      if (Date.now() >= end) {
        held = true;
        break;
      }
      sleep(25);
    }
  }
  try {
    return body();
  } finally {
    if (held) rmSync(lock, { force: true });
  }
}

function workflow(p, ti, budget) {
  if (p.agent_id) {
    decide('Workflow', { decision: 'refuse', raison: 'imbrique', lanceur: String(p.agent_id) });
    refuseAs('Workflow', 'un sous-agent ne lance pas de workflow : rends un signal au thread principal');
  }
  let max = budget?.total_par_plan ? int(budget.total_par_plan) : 12;
  if (!ti) return;
  if (ti.resumeFromRunId) {
    decide('Workflow', { decision: 'passe', raison: 'reprise' });
    return;
  }
  // A team plan may only lower the cap.
  try {
    const planPath = ti.args?.plan;
    if (planPath && existsSync(String(planPath))) {
      const total = readJson(String(planPath))?.budget?.agents_total;
      if (total && int(total) < max) max = int(total);
    }
  } catch {
    // an unreadable plan keeps the budget's cap
  }
  let text = null;
  if (ti.scriptPath) {
    if (!existsSync(String(ti.scriptPath))) return;
    text = readFileSync(String(ti.scriptPath), 'utf8').replace(/^﻿/u, '');
  } else if (ti.script) {
    text = String(ti.script);
  }
  if (!text) {
    if (!ti.name) return;
    decide('Workflow', { decision: 'refuse', raison: 'name_sans_script', name: String(ti.name) });
    refuse(
      `BLOQUE par guard-budget-agents : workflow nomme '${str(ti.name)}' lance a l'aveugle (script jamais lu, fan-out inconnu).\n` +
        "Procedure obligatoire : (1) recupere le script du workflow (fichier .claude/workflows/, ou re-auteur un script equivalent d'apres les phases du skill) ; " +
        '(2) CALCULE son fan-out mecanique (somme des agent() par phase, ex. recherches x fetch x claims x votes) ; ' +
        `(3) si > ${max}, EDITE le script pour reduire ; (4) declare '// agents-max: N' en tete de script et relance via script:/scriptPath:. ` +
        'Un plafond en prose dans args ne borne RIEN. Ne contourne pas ce garde.',
    );
  }
  const m = DECLARED.exec(text);
  if (m) {
    const declared = int(m[1]);
    if (declared <= max) {
      decide('Workflow', { decision: 'passe', declare: declared, plafond: max });
      return;
    }
    if (USER_OK.test(text)) {
      decide('Workflow', { decision: 'passe', raison: 'user_ok', declare: declared, plafond: max });
      return;
    }
    decide('Workflow', { decision: 'refuse', raison: 'au_dela_du_plafond', declare: declared, plafond: max });
    refuse(
      `BLOQUE par guard-budget-agents : fan-out declare agents-max: ${declared} > plafond ${max} (budget-agents.json, total_par_plan).\n` +
        `Deux issues : (a) reduis le fan-out du script sous ${max} (moins de sources/claims/votes, ou plusieurs runs sequentiels cibles) ; ` +
        "(b) si l'echelle est vraiment necessaire, demande une validation EXPLICITE a l'utilisateur via AskUserQuestion (cout estime inclus), " +
        "puis ajoute '// agents-max-user-ok' sous la declaration. Ne contourne pas ce garde.",
    );
  }
  decide('Workflow', { decision: 'refuse', raison: 'sans_declaration' });
  refuse(
    "BLOQUE par guard-budget-agents : aucune declaration '// agents-max: N' en tete de script.\n" +
      'Avant de lancer un Workflow : LIS le script, CALCULE son fan-out mecanique (somme des agent() par phase, multiplicateurs inclus : items x stages x votes), ' +
      `puis declare '// agents-max: N' en tete de script. Plafond : ${max} (au-dela, validation explicite requise + '// agents-max-user-ok'). Ne contourne pas ce garde.`,
  );
}

function launch(p, ti, budget, modeBudget, flagP2) {
  if (!ti) return;
  const keys = Object.keys(ti);
  const type = str(ti.subagent_type);
  const prompt = str(ti.prompt);
  const plan = briefLine(prompt, 'plan');
  const zone = briefLine(prompt, 'zone');
  const role = classify(type, Boolean(plan));
  const name = Object.hasOwn(ti, 'name') ? str(ti.name) : null;
  const launcher = launcherOf(p);
  const nested = !same(launcher, 'principal');
  const print = fingerprint(ti);
  const dir = stateDir();
  const verdict = withLock(dir, () => {
    const h = openState(dir);
    expire(h.etat, budget?.ttl_agent_min ? int(budget.ttl_agent_min) : 60);
    const live = alive(h.etat.agents);
    const simult = budget?.simultanes ? int(budget.simultanes) : 5;
    const pw = budget?.playwright_simultanes ? int(budget.playwright_simultanes) : 1;
    const livePw = live.filter((a) => a?.playwright).length;
    // A generalist (omitted type included) or a fork writes.
    const writes = launchWrites(type);
    let reason = null;
    let p2 = false;
    if (nested && writes) {
      reason = `un sous-agent ne lance pas d'agent qui ecrit (${type || 'general-purpose'}) : rends un signal au thread principal`;
      p2 = true;
    } else if (flagP2 && name && nested) {
      reason = 'un sous-agent ne cree pas de coequipier : rends un signal au thread principal';
      p2 = true;
    } else if (flagP2 && name) {
      reason = `coequipier (name='${name}') refuse : la garde ne prend pas encore en charge le palier P2 (etape 4) ; lance sans name`;
      p2 = true;
    } else if (live.length >= simult) {
      reason = `plafond simultanes atteint (${live.length}/${simult} agents vivants) : attends la fin d'un agent`;
    } else if (role.playwright && livePw >= pw) {
      reason = `un seul agent Playwright a la fois (${livePw} vivant) : la session navigateur est partagee`;
    } else if (live.some((a) => same(a?.empreinte, print))) {
      reason = 'lancement identique (meme type, description et prompt) deja en cours';
    }
    let decision = 'passe';
    if (reason) decision = p2 || same(modeBudget, 'bloquer') ? 'refuse' : 'aurait_refuse';
    if (decision !== 'refuse') {
      h.etat.agents.push({
        empreinte: print, type, nom: name, ecrit: role.ecrit, playwright: role.playwright,
        plan, zone, lance: TS, prompt_id: str(p.prompt_id), lanceur: launcher, agent_id: null, fini: null,
      });
      saveState(h);
    }
    decide('Agent', {
      decision, raison: reason, type, role: role.ecrit ? 'ecrivain' : 'lecteur', role_source: role.source,
      playwright: role.playwright, name, lanceur: launcher, plan, zone, vivants: live.length, simultanes: simult,
      mode_budget: modeBudget, flag_p2: flagP2, cles_tool_input: keys, agent_id_present: Boolean(p.agent_id),
      prompt_id_present: Boolean(p.prompt_id), empreinte: print,
    });
    return { decision, reason };
  });
  if (verdict.decision === 'refuse') refuseAs('Agent', verdict.reason);
}

function message(p, ti) {
  const target = ti ? str(ti.to) : null;
  let reprise = false;
  try {
    const { etat } = openState(stateDir());
    reprise = etat.agents.some((a) => a?.fini && ((a.nom && same(a.nom, target)) || (a.agent_id && same(a.agent_id, target))));
  } catch {
    // an unreadable state only loses the resume flag
  }
  decide('SendMessage', { decision: 'passe', to: target, reprise, lanceur: launcherOf(p) });
}

function result(p, ti) {
  const tr = p.tool_response;
  const keys = tr && typeof tr === 'object' ? Object.keys(tr) : [];
  const print = ti ? fingerprint(ti) : null;
  const status = tr ? str(tr.status) : null;
  const aid = tr?.agentId ? String(tr.agentId) : null;
  const dir = stateDir();
  withLock(dir, () => {
    const h = openState(dir);
    const target = h.etat.agents.findLast((a) => same(a?.empreinte, print) && !a.fini);
    if (target) {
      if (aid) target.agent_id = aid;
      if (same(status, 'completed')) target.fini = TS;
      saveState(h);
    }
    decide('Agent', { decision: 'resultat', statut: status, agent_id: aid, retrouve: Boolean(target), cles_tool_response: keys, empreinte: print });
  });
}

function stop(p) {
  const aid = str(p.agent_id);
  const dir = stateDir();
  withLock(dir, () => {
    const h = openState(dir);
    const target = h.etat.agents.findLast((a) => same(a?.agent_id, aid) && !a.fini);
    if (target) {
      target.fini = TS;
      saveState(h);
    }
    decide('SubagentStop', { decision: 'fin', agent_id: aid, agent_type: str(p.agent_type), retrouve: Boolean(target), vivants: alive(h.etat.agents).length });
  });
}

function main(p) {
  if (p.session_id) SID = String(p.session_id);
  const tool = str(p.tool_name);
  const ti = p.tool_input;
  LOG = equipeLogDir(p);
  const budget = readBudget();
  const modeBudget = budget?.mode ? String(budget.mode) : 'journal';
  const flagP2 = process.env.CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS === '1';
  const pre = same(MODE, 'pre');
  if (pre && same(tool, 'Workflow')) return workflow(p, ti, budget);
  // Without a resolvable project there is no state nor journal: the Agent rules do not apply.
  if (!LOG) return;
  if (pre && same(tool, 'Agent')) return launch(p, ti, budget, modeBudget, flagP2);
  if (pre && same(tool, 'SendMessage')) return message(p, ti);
  if (same(MODE, 'post') && same(tool, 'Agent')) return result(p, ti);
  if (same(MODE, 'stop')) return stop(p);
}

run((p) => {
  if (!p) return;
  try {
    main(p);
  } catch (e) {
    decide('erreur', { decision: 'passe', raison: `erreur interne : ${e.message}` });
  }
});
