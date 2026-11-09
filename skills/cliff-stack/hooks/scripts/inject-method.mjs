// inject-method.mjs - SessionStart and SubagentStart hook: puts the working method (references/portes.md) in context.
// The method lives in the plugin, not in a CLAUDE.md, so it travels with the plugin; SubagentStart is wired too because
// a SessionStart context reaches the main session only. A missing or unreadable file fails visibly (stderr, exit 1):
// a silent exit would drop the method without anyone noticing.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const METHOD = fileURLToPath(new URL('../../references/portes.md', import.meta.url));

let event = 'SessionStart';
try {
  const raw = readFileSync(0, 'utf8').replace(/^﻿/, ''); // Windows PowerShell 5.1 pipes a BOM
  if (raw.trim()) event = JSON.parse(raw).hook_event_name || event;
} catch {
  // no or unparsable stdin: keep the default event name
}

let text;
try {
  text = readFileSync(METHOD, 'utf8');
} catch (err) {
  process.stderr.write(`inject-method: method file unreadable (${METHOD}): ${err.message}\n`);
  process.exit(1);
}

process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: event, additionalContext: text } }));
