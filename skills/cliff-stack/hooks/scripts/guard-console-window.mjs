// guard-console-window.mjs - PostToolUse (Write|Edit|MultiEdit): in a Python or Node file that DETACHES a process
// (DETACHED_PROCESS, detached: true), flags every child launch without a "no window" flag (creationflags= /
// windowsHide:): on Windows that child would get a fresh visible console (a Windows Terminal tab).
// Contract: ~/.claude/rules/processus-enfants.md. Skips files out of scope, missing, under node_modules/vendor/.git,
// over 2 MB, or without any detachment. Findings: stderr + exit 2 (feedback to the model). Rewrites nothing.
import { existsSync, readFileSync, statSync } from 'node:fs';
import { refuse, run } from './lib/hook.mjs';
import { B, W, rx } from './lib/regex.mjs';

const MAX_BYTES = 2 * 1024 * 1024;

const PY = {
  detach: rx(`DETACHED_PROCESS|CREATE_NEW_CONSOLE|${B}0x0*8${B}\\s*\\|`),
  call: new RegExp(`(?<![${W}.])(?:subprocess\\.)?(Popen|run|call|check_call|check_output)\\s*\\(`, 'gu'),
  covered: rx(`creationflags\\s*=|\\*\\*[${W}]+`),
  fix: 'creationflags=CREATE_NO_WINDOW (0x08000000), via la constante du projet (ex. SANS_FENETRE), {} hors Windows',
};
const JS = {
  detach: rx(`${B}detached\\s*:\\s*true${B}`),
  call: new RegExp(`(?<![${W}.])(?:child_process\\.|cp\\.)?(spawn|spawnSync|execFile|execFileSync|exec|execSync|fork)\\s*\\(`, 'gu'),
  covered: rx(`${B}windowsHide${B}|\\.\\.\\.[${W}]+`),
  fix: 'windowsHide: true, via la constante du projet, hors Windows sans effet',
};

// Text of the call arguments: balanced parentheses from the opening one (strings holding parentheses are rare here).
function argumentsOf(src, start) {
  let i = start;
  let depth = 1;
  while (i < src.length && depth > 0) {
    if (src[i] === '(') depth++;
    else if (src[i] === ')') depth--;
    i++;
  }
  return src.slice(start, Math.max(start, i - 1));
}

run((payload) => {
  const file = payload && payload.tool_input && payload.tool_input.file_path;
  if (!file || !/\.(py|js|mjs|cjs|ts)$/i.test(file)) return;
  if (/[\\/](node_modules|vendor|\.git)[\\/]/i.test(file) || !existsSync(file)) return;
  if (statSync(file).size > MAX_BYTES) return;

  const src = readFileSync(file, 'utf8').replace(/^﻿/, '');
  if (!src) return;
  const isPy = /\.py$/i.test(file);
  const lang = isPy ? PY : JS;
  // Without detachment the children inherit the (hidden) console of the parent: nothing to flag.
  if (!lang.detach.test(src)) return;

  // Bare run( / call( are ambiguous in Python: kept only when imported from subprocess.
  const bare = isPy && /from\s+subprocess\s+import/i.test(src);
  const findings = [];
  for (const m of src.matchAll(lang.call)) {
    if (isPy && !m[0].startsWith('subprocess.') && !bare) continue;
    if (lang.covered.test(argumentsOf(src, m.index + m[0].length))) continue;
    const line = src.slice(0, m.index).split('\n').length;
    findings.push(`  L${line}: ${m[1]}( sans drapeau sans fenêtre`);
  }
  if (findings.length === 0) return;

  refuse([
    `[guard-console-window] ${file} détache un processus : ses enfants console recevront une fenêtre Windows Terminal visible.`,
    findings.join('\n'),
    `  Correctif : ${lang.fix} ; fixer stdin/stdout/stderr explicitement. Contrat : ~/.claude/rules/processus-enfants.md`,
  ].join('\n'));
});
