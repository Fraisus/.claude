// agent-usage.mjs - reads a subagent's context size and token use from its transcript.
// One reader for three consumers: guard-agent-context.mjs (live cap), guard-agent-resume.ps1 and log-agents.ps1
// (through the CLI below). Context of a turn = input + cache_creation + cache_read tokens of its `message.usage`;
// one API message spans several transcript lines, so totals are deduplicated by `message.id`.
import { closeSync, existsSync, fstatSync, openSync, readdirSync, readFileSync, readSync } from 'node:fs';
import { basename, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BUDGET = fileURLToPath(new URL('../budget-agents.json', import.meta.url));
const DEFAULTS = { contexte_agent_max: 150000, contexte_palier: 50000 };

export function readBudget() {
  const path = process.env.CLIFF_EQUIPE_BUDGET || BUDGET;
  try {
    const b = JSON.parse(readFileSync(path, 'utf8').replace(/^﻿/, ''));
    return {
      max: Number(b.contexte_agent_max) || DEFAULTS.contexte_agent_max,
      step: Number(b.contexte_palier) || DEFAULTS.contexte_palier,
    };
  } catch {
    return { max: DEFAULTS.contexte_agent_max, step: DEFAULTS.contexte_palier };
  }
}

// The hook payload's transcript_path may already be the agent's file or the parent session's; both are accepted.
export function agentTranscript({ transcriptPath, agentTranscriptPath, agentId }) {
  if (agentTranscriptPath && existsSync(agentTranscriptPath)) return agentTranscriptPath;
  if (!transcriptPath || !agentId) return null;
  const own = `agent-${agentId}.jsonl`;
  if (basename(transcriptPath) === own) return existsSync(transcriptPath) ? transcriptPath : null;
  const subagents = join(transcriptPath.replace(/\.jsonl$/i, ''), 'subagents');
  const derived = join(subagents, own);
  if (existsSync(derived)) return derived;
  // Agents launched by a Workflow live under subagents/workflows/<run>/.
  try {
    for (const run of readdirSync(join(subagents, 'workflows'))) {
      const p = join(subagents, 'workflows', run, own);
      if (existsSync(p)) return p;
    }
  } catch {
    // no workflow ran in this session
  }
  return null;
}

const contextOf = (u) => (u.input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.cache_read_input_tokens || 0);

function assistantUsage(line) {
  if (!line.includes('"usage"')) return null;
  try {
    const o = JSON.parse(line);
    return o.type === 'assistant' && o.message && o.message.usage ? o.message : null;
  } catch {
    return null;
  }
}

function tail(file, bytes) {
  const fd = openSync(file, 'r');
  try {
    const size = fstatSync(fd).size;
    const len = Math.min(size, bytes);
    const buf = Buffer.alloc(len);
    readSync(fd, buf, 0, len, size - len);
    return { text: buf.toString('utf8'), whole: len === size };
  } finally {
    closeSync(fd);
  }
}

// Context of the last turn, reading only the end of the file; widens when one line is longer than the window.
export function lastContext(file) {
  for (const bytes of [256 * 1024, 4 * 1024 * 1024, Number.MAX_SAFE_INTEGER]) {
    const { text, whole } = tail(file, bytes);
    const lines = text.split('\n');
    if (!whole) lines.shift();
    for (let i = lines.length - 1; i >= 0; i--) {
      const m = assistantUsage(lines[i]);
      if (m) return contextOf(m.usage);
    }
    if (whole) return 0;
  }
  return 0;
}

export function totals(file) {
  const byId = new Map();
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const m = assistantUsage(line);
    if (m) byId.set(m.id || byId.size, m);
  }
  const t = { tours: byId.size, entree: 0, cache_lu: 0, cache_cree: 0, sortie: 0, contexte_max: 0, modele: null };
  for (const m of byId.values()) {
    const u = m.usage;
    t.entree += u.input_tokens || 0;
    t.cache_lu += u.cache_read_input_tokens || 0;
    t.cache_cree += u.cache_creation_input_tokens || 0;
    t.sortie += u.output_tokens || 0;
    t.contexte_max = Math.max(t.contexte_max, contextOf(u));
    if (m.model) t.modele = m.model;
  }
  const mj = readMeta(file);
  if (mj) {
    t.modele_demande = mj.model ?? null;
    t.effort = mj.effort ?? null;
    t.profondeur = mj.spawnDepth ?? null;
  }
  return t;
}

// The harness writes agent-<id>.meta.json next to the transcript (agentType, requested model, effort, spawnDepth).
export function readMeta(file) {
  try {
    return JSON.parse(readFileSync(file.replace(/\.jsonl$/i, '.meta.json'), 'utf8'));
  } catch {
    return null;
  }
}

// CLI: node agent-usage.mjs <context|totals|meta> --agent <id> [--transcript <path>] [--agent-transcript <path>]
// Prints one JSON object; prints {} when the transcript is not found. Never fails the calling hook.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const [cmd, ...rest] = process.argv.slice(2);
  const arg = (name) => {
    const i = rest.indexOf(name);
    return i >= 0 ? rest[i + 1] : undefined;
  };
  let out = {};
  try {
    const file = agentTranscript({
      transcriptPath: arg('--transcript'),
      agentTranscriptPath: arg('--agent-transcript'),
      agentId: arg('--agent'),
    });
    if (file && cmd === 'context') out = { fichier: file, contexte: lastContext(file), ...readBudget() };
    if (file && cmd === 'totals') out = totals(file);
    if (file && cmd === 'meta') out = readMeta(file) || {};
  } catch {
    out = {};
  }
  process.stdout.write(JSON.stringify(out));
}
