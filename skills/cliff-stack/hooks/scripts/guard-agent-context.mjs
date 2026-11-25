// guard-agent-context.mjs - PostToolUse (every tool) and SubagentStop hook: caps a subagent's context.
// Past contexte_agent_max (budget-agents.json) the agent is told to hand over, once per contexte_palier band:
// a writer finishes its step and writes its handover, a reader returns a report marked partial. A PostToolUse runs
// after the tool, so nothing is cut mid-write. The native auto-compaction of a subagent fires near its window's end
// and keeps it going on a lossy summary without any handover; this cap fires far earlier and ends the agent instead.
// The main session (no agent_id) is never touched. SubagentStop removes the agent's band marker.
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { agentTranscript, lastContext, readBudget } from './agent-usage.mjs';

let p;
try {
  const raw = readFileSync(0, 'utf8').replace(/^﻿/, ''); // Windows PowerShell 5.1 pipes a BOM
  p = raw.trim() ? JSON.parse(raw) : null;
} catch {
  process.exit(0);
}
if (!p || !p.agent_id) process.exit(0);

const dir = join(tmpdir(), 'cliff-equipe-contexte');
const marker = join(dir, `${String(p.session_id || 'session').replace(/[^\w-]/g, '')}-${String(p.agent_id).replace(/[^\w-]/g, '')}`);

if (p.hook_event_name === 'SubagentStop') {
  rmSync(marker, { force: true });
  process.exit(0);
}

try {
  const file = agentTranscript({ transcriptPath: p.transcript_path, agentTranscriptPath: p.agent_transcript_path, agentId: p.agent_id });
  if (!file) process.exit(0);
  const { max, step } = readBudget();
  const context = lastContext(file);
  if (context < max) process.exit(0);
  const band = Math.floor((context - max) / step);
  let seen = -1;
  try {
    seen = Number(readFileSync(marker, 'utf8'));
  } catch {
    // first crossing for this agent
  }
  if (band <= seen) process.exit(0);
  mkdirSync(dir, { recursive: true });
  writeFileSync(marker, String(band));
  const k = Math.round(context / 1000);
  const reason =
    `Contexte de ${k} 000 jetons, au-delà du plafond de ${max / 1000} 000 (budget-agents.json, contexte_agent_max). ` +
    'Arrête-toi au point stable le plus proche. Si tu écris : finis seulement l\'étape en cours, commite si ton brief le permet, ' +
    'écris ta passation (fait, prouvé, reste, fichiers touchés) dans le fichier que ton brief nomme, sinon dans ton rapport, puis rends. ' +
    'Si tu lis ou vérifies : rends ton rapport marqué « partiel », avec ce qui est établi, sa preuve, et ce qui reste à vérifier. ' +
    'Un agent neuf reprendra depuis ta passation.';
  process.stdout.write(JSON.stringify({ decision: 'block', reason }));
} catch {
  // a guard that fails must never stop the agent
}
