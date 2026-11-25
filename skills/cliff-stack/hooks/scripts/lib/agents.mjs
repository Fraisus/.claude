// agents.mjs - what an agent type may do, read from its definition, never from a list of names.
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { oneOf } from './hook.mjs';
import { B, BOL, rx } from './regex.mjs';

const PLUGIN_AGENTS = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'agents');
const FRONT_MATTER = /^﻿?---\s*\r?\n([\s\S]*?)\r?\n---/u;
const TOOLS = rx(String.raw`${BOL}tools\s*:\s*([^\n]+)(?=\n|$)`);
const WRITE_TOOLS = rx(String.raw`${B}(Edit|Write|MultiEdit|NotebookEdit)${B}`);
const BUILTIN_READERS = ['Explore', 'Plan', 'claude-code-guide', 'statusline-setup'];
const GENERALISTS = ['general-purpose', 'claude'];

// Type without its plugin prefix (cliff-stack:ouvrier-zone -> ouvrier-zone).
export const bare = (type) => String(type ?? '').replace(/^[^:]+:/u, '');

// Writer when its definition is found and `tools:` is absent (inherits all) or lists Edit or Write; Playwright when
// `tools:` cites mcp__playwright; built-in types without a file read; general-purpose and claude write only in a plan;
// an unknown type reads. Definitions: the project's .claude/agents, the plugin's agents, ~/.claude/agents.
export function classify(type, inPlan) {
  const role = { ecrit: false, playwright: false, source: 'inconnu' };
  if (!type) return role;
  const nu = bare(type);
  if (oneOf(nu, BUILTIN_READERS)) return { ...role, source: 'integre-lecteur' };
  if (oneOf(nu, GENERALISTS)) return { ...role, ecrit: inPlan, source: 'integre-generaliste' };
  const dirs = [];
  if (process.env.CLAUDE_PROJECT_DIR) dirs.push(join(process.env.CLAUDE_PROJECT_DIR, '.claude', 'agents'));
  dirs.push(PLUGIN_AGENTS, join(homedir(), '.claude', 'agents'));
  for (const d of dirs) {
    const f = join(d, `${nu}.md`);
    if (!existsSync(f)) continue;
    const fm = FRONT_MATTER.exec(readFileSync(f, 'utf8'))?.[1];
    const tools = fm ? TOOLS.exec(fm)?.[1] : undefined;
    return { ecrit: !tools || WRITE_TOOLS.test(tools), playwright: Boolean(tools) && /mcp__playwright/iu.test(tools), source: f };
  }
  return role;
}

// Whether launching this agent type may write code, plan or not: an omitted type is general-purpose, a fork inherits
// every tool.
export const launchWrites = (type) => oneOf(bare(type), ['', 'general-purpose', 'claude', 'fork']) || classify(type, false).ecrit;
