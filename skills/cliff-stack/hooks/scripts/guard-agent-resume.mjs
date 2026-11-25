// guard-agent-resume.mjs - PreToolUse (SendMessage), for the model only (never a question to the user).
// A resumed subagent reloads its whole history: the resume is denied when its real context (last turn of its transcript)
// is past contexte_agent_max minus contexte_palier (budget-agents.json), lest guard-agent-context stop it at its first
// tool; « #reprise » in the message overrides it (code half-written after a cut). Contract: skill equipe, section « Coût ».
// Nothing to judge (not an agent, no transcript, an agent still running: a message to it reloads nothing) -> exit 0.
import { agentTranscript, lastContext, readBudget, readMeta } from './agent-usage.mjs';
import { deny, run } from './lib/hook.mjs';
import { rx } from './lib/regex.mjs';

const AGENT_ID = rx(String.raw`^a[0-9a-f]{12,}(?=\n?$)`);
const RESUME = rx('#reprise');
// Half to even, as the [math]::Round of the PowerShell guard.
const roundEven = (x) => {
  const r = Math.round(x);
  return r - x === 0.5 && r % 2 !== 0 ? r - 1 : r;
};

run((p) => {
  const to = String(p?.tool_input?.to ?? '');
  if (!AGENT_ID.test(to)) return;
  if (RESUME.test(String(p.tool_input.message ?? ''))) return;
  const file = agentTranscript({ transcriptPath: String(p.transcript_path ?? ''), agentId: to });
  // The harness writes "finished": true in the agent's meta once it has handed back.
  if (!file || readMeta(file)?.finished !== true) return;
  const context = lastContext(file);
  if (!context) return;
  const { max, step } = readBudget();
  const limit = max - step;
  if (context < limit) return;
  deny(
    `Agent ${to} : contexte de ${roundEven(context / 1000)} 000 jetons, au-delà de la reprise permise (${limit / 1000} 000, budget-agents.json). ` +
      'Lance un agent neuf avec sa passation ; « #reprise » dans le message seulement pour du code à moitié écrit après une coupure.',
  );
});
