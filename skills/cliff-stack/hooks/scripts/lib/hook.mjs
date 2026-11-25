// hook.mjs - shared input and output of every node hook of the plugin.
// A hook never fails the tool on its own error: callers wrap their body in run(), which exits 0 on any throw.
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// Hook payload from stdin; Windows PowerShell 5.1 relays may prepend a BOM. Null when empty or unparsable.
export function readPayload() {
  try {
    const raw = readFileSync(0, 'utf8').replace(/^﻿/, '');
    const payload = raw.trim() ? JSON.parse(raw) : null;
    // PowerShell splits words on U+0085 and JavaScript's \s misses it: a guard reading the command must see a space.
    if (typeof payload?.tool_input?.command === 'string') payload.tool_input.command = payload.tool_input.command.replaceAll('\u0085', ' ');
    return payload;
  } catch {
    return null;
  }
}

// Project root: CLAUDE_PROJECT_DIR, else the payload's cwd; null when neither exists on disk.
export function projectDir(payload) {
  const dir = process.env.CLAUDE_PROJECT_DIR || (payload && payload.cwd) || null;
  return dir && existsSync(dir) ? dir : null;
}

// <project>/.claude/equipe-log, created with a "*" .gitignore; CLIFF_EQUIPE_LOG_DIR wins (tests).
export function equipeLogDir(payload) {
  if (process.env.CLIFF_EQUIPE_LOG_DIR) return process.env.CLIFF_EQUIPE_LOG_DIR;
  const root = projectDir(payload);
  if (!root) return null;
  const dir = join(root, '.claude', 'equipe-log');
  if (!existsSync(dir)) {
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, '.gitignore'), '*\n');
  }
  return dir;
}

export function appendJsonl(dir, name, entry) {
  if (!dir) return;
  try {
    mkdirSync(dir, { recursive: true });
    appendFileSync(join(dir, name), `${JSON.stringify(entry)}\n`);
  } catch {
    // a journal that cannot be written never blocks the tool
  }
}

// A payload field as text: missing or null becomes an empty string.
export const str = (v) => (v == null ? '' : String(v));
// PowerShell -eq and -in on strings: comparison ignores case, a null left side never matches.
export const same = (a, b) => a != null && String(a).toLowerCase() === str(b).toLowerCase();
export const oneOf = (v, list) => list.some((x) => same(x, v));

const emit = (obj) => process.stdout.write(JSON.stringify(obj));

// PreToolUse decisions read by the harness from stdout, exit 0.
export const deny = (reason) => emit({ hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason } });
export const ask = (reason) => emit({ hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'ask', permissionDecisionReason: reason } });
// PostToolUse or Stop: the reason goes back to the model.
export const block = (reason) => emit({ decision: 'block', reason });
// Extra context for the model on any event that accepts it.
export const context = (event, text) => emit({ hookSpecificOutput: { hookEventName: event, additionalContext: text } });

// Exit-2 refusal: stderr goes back to the model (the convention of the PowerShell hooks this replaces).
export function refuse(message) {
  process.stderr.write(`${message}\n`);
  process.exit(2);
}

// Runs a hook body; any throw lets the tool through, as the PowerShell hooks did with exit 0.
export function run(body) {
  try {
    body(readPayload());
  } catch {
    process.exitCode = 0;
  }
}
