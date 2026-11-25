// guard-proof-before-done.mjs - global Stop hook: gate (8) of the « Huit portes » (references/portes.md).
// Blocks the end of a turn that announces a result (corrigé, fini, livré...) when that turn edited code without running
// a verification after the last edit. Once per turn (stop_hook_active), never in a loop.
import { closeSync, existsSync, fstatSync, openSync, readSync } from 'node:fs';
import { refuse, run, str } from './lib/hook.mjs';
import { B, W } from './lib/regex.mjs';

// .NET \w and \b, whose behaviour this hook keeps, are Unicode-aware; the JS ones are ASCII-only (« corrigé »).

const LINES = 400;
const CODE = /\.(py|js|mjs|cjs|ts|tsx|jsx|php|css|scss|html|ps1|sh|json)(?=\n?$)/iu;
// A verification is a shell command, run after the last edit, that executes one of these probes. Grouped by the
// method's levels and rungs (references/portes.md, skill probing-by-levels): a read-only probe of the running app is
// often the most direct proof, and refusing it would push towards heavier probes.
const CHECKS = [
  // level 0, static: syntax, lint, types, JSON parse
  'node --check', 'py_compile', String.raw`php(\.exe)?${B}[^|;&]*\s-l${B}`, 'phpcs', String.raw`${B}tsc${B}`, 'eslint', 'stylelint',
  'ConvertFrom-Json', String.raw`JSON\.parse`, String.raw`json\.tool`,
  // level 1, tests
  'test_', String.raw`_test\.`, String.raw`(^|[\s/\\"'])tests?[/\\]`, 'pytest', 'npm (run )?test', 'vitest', 'jest', 'check_', '--verifier', 'faux_hote', 'bench',
  // stored rung: tooled read of the running application
  String.raw`wp(\.bat)? eval-file`,
  // served rung: a request to a local server only (reserved names: localhost RFC 6761, .test RFC 2606, .local RFC 6762);
  // a request elsewhere is a download, not a probe of the code just edited
  String.raw`(curl(\.exe)?|Invoke-WebRequest|iwr)${B}[^|;&]*https?://(localhost|127\.0\.0\.1|\[::1\]|[${W}.-]+\.(localhost|test|local))(?=[:/\s"']|$)`,
  // computed rung and real gesture
  'playwright', 'ux-e2e',
];
const CHECK = new RegExp(`(${CHECKS.join('|')})`, 'iu');
const CLAIM = new RegExp(String.raw`${B}(corrig[ée]e?s?|r[ée]gl[ée]e?s?|r[ée]solue?s?|fini|termin[ée]e?s?|livr[ée]e?s?|en place|c'est fait|fixed|done)${B}`, 'iu');
const isIn = (name, ...names) => names.includes(String(name ?? '').toLowerCase());

// The last `count` lines of a file, reading only its end; the window widens until it holds them or the whole file.
function lastLines(path, count) {
  const fd = openSync(path, 'r');
  try {
    const size = fstatSync(fd).size;
    for (let bytes = 1024 * 1024; ; bytes *= 4) {
      const len = Math.min(size, bytes);
      const buf = Buffer.alloc(len);
      readSync(fd, buf, 0, len, size - len);
      const lines = buf.toString('utf8').replace(/^﻿/u, '').split('\n');
      if (lines.at(-1) === '') lines.pop();
      if (len < size) lines.shift(); // cut by the window
      if (len === size || lines.length >= count) return lines.slice(-count).map((l) => l.replace(/\r$/u, ''));
    }
  } finally {
    closeSync(fd);
  }
}

run((p) => {
  if (!p || p.stop_hook_active) return;
  const path = p.transcript_path;
  if (!path || !existsSync(path)) return;
  let edits = -1;
  let checks = -1;
  let text = '';
  lastLines(path, LINES).forEach((line, idx) => {
    const i = idx + 1;
    let e;
    try {
      e = JSON.parse(line);
    } catch {
      return;
    }
    const c = e?.message?.content;
    if (isIn(e?.type, 'user') && typeof c === 'string') {
      // a new request: a new turn
      edits = -1;
      checks = -1;
      text = '';
      return;
    }
    if (!isIn(e?.type, 'assistant') || !c) return;
    for (const b of Array.isArray(c) ? c : [c]) {
      if (isIn(b?.type, 'text')) text = b.text;
      if (!isIn(b?.type, 'tool_use')) continue;
      if (isIn(b.name, 'write', 'edit', 'multiedit') && CODE.test(str(b.input?.file_path))) edits = i;
      if (isIn(b.name, 'bash', 'powershell') && CHECK.test(str(b.input?.command))) checks = i;
    }
  });
  if (edits < 0 || checks > edits || !CLAIM.test(str(text))) return;
  refuse("Porte (8) : ce tour a modifié du code sans vérification lancée après la dernière modification. Lancer les essais et contrôles des fichiers touchés, ou dire que ce n'est pas vérifié, avant d'annoncer un résultat.");
});
