/**
 * cli.mjs: shared command-line helpers of the ui-kit scripts. ESM, zero dependency, Node 18+.
 *
 *   exitOnHelp(argv, moduleUrl)  when argv holds --help or -h, prints the first doc comment of
 *                                the module (its help) on stdout and exits 0
 */
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

export function exitOnHelp(argv, moduleUrl) {
  if (!argv.includes('--help') && !argv.includes('-h')) return;
  const source = fs.readFileSync(fileURLToPath(moduleUrl), 'utf8');
  const doc = /\/\*\*([\s\S]*?)\*\//.exec(source)?.[1] ?? '';
  console.log(doc.split('\n').map((l) => l.replace(/^\s*\* ?/, '')).join('\n').trim());
  process.exit(0);
}
