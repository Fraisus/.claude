// log-agents.mjs - SubagentStop: logs the end of every subagent. Never fails the hook.
//   critics.jsonl in ~/.claude/.critic-log: one line per finished critic (agent_type ending in independent-critic, bare
//   or prefixed by a plugin);
//   agents.jsonl in the project's equipe-log (lib/hook.mjs): one line per subagent of any type, to measure the teams,
//   with tokens, maximal context, model and effort read from its transcript (agent-usage.mjs). Global configuration
//   receives no project data.
// Never more of the content than a 400-character excerpt. Test variable: CLIFF_EQUIPE_LOG_DIR.
import { homedir } from 'node:os';
import { join } from 'node:path';
import { agentTranscript, totals } from './agent-usage.mjs';
import { appendJsonl, equipeLogDir, run, str } from './lib/hook.mjs';
import { rx } from './lib/regex.mjs';

const CRITIC = rx(String.raw`independent-critic(?=\n?$)`);

run((p) => {
  const type = str(p?.agent_type);
  if (!type) return;
  const excerpt = str(p.last_assistant_message).slice(0, 400);
  const head = { ts: new Date().toISOString(), session_id: str(p.session_id), cwd: str(p.cwd) };
  if (CRITIC.test(type)) appendJsonl(join(homedir(), '.claude', '.critic-log'), 'critics.jsonl', { ...head, agent_type: type, excerpt });
  const dir = equipeLogDir(p);
  if (!dir) return;
  const tasks = p.background_tasks;
  const agent = {
    ...head, agent_id: str(p.agent_id), agent_type: type, stop_hook_active: Boolean(p.stop_hook_active),
    fond: Array.isArray(tasks) ? tasks.length : tasks ? 1 : 0, excerpt,
  };
  try {
    const file = agentTranscript({ transcriptPath: str(p.transcript_path), agentTranscriptPath: str(p.agent_transcript_path), agentId: str(p.agent_id) });
    if (file) Object.assign(agent, totals(file));
  } catch {
    // an unreadable transcript only loses the token counts
  }
  appendJsonl(dir, 'agents.jsonl', agent);
});
