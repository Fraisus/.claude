// phpcs-wp-native.mjs - PostToolUse (Write|Edit|MultiEdit): runs phpcs with the high-signal WordPress ruleset
// (phpcs-wp-native.xml at the plugin root) on the edited .php file. Silent exit 0 when the file is not PHP, sits outside
// a WordPress tree, or phpcs, php or the ruleset is missing. Findings: stderr + exit 2 (feedback to the model).
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { delimiter, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { refuse, run } from './lib/hook.mjs';

const RULES = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'phpcs-wp-native.xml');
const WP_MARKERS = ['wp-load.php', 'wp-settings.php'];
const MAX_CLIMB = 8;

// A WordPress tree: a wp-content segment in the path, or a core file within MAX_CLIMB parents.
function inWordPress(file) {
  if (/[\\/]wp-content[\\/]/i.test(file)) return true;
  let dir = dirname(file);
  for (let i = 0; i < MAX_CLIMB; i++) {
    if (WP_MARKERS.some((marker) => existsSync(join(dir, marker)))) return true;
    const parent = dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return false;
}

function phpOnPath() {
  const extensions = (process.env.PATHEXT || '.EXE').split(';').filter((e) => /^\.(exe|com)$/i.test(e));
  for (const dir of (process.env.PATH || '').split(delimiter).filter(Boolean)) {
    for (const ext of extensions) if (existsSync(join(dir, `php${ext}`))) return join(dir, `php${ext}`);
  }
  return null;
}

// Newest php* folder of the local stack (MAMP_ROOT, MSYS style accepted, else C:\MAMP).
function phpInLocalStack() {
  const stack = process.env.MAMP_ROOT
    ? process.env.MAMP_ROOT.replace(/^\/([a-zA-Z])\//, '$1:\\').replaceAll('/', '\\')
    : 'C:\\MAMP';
  const base = join(stack, 'bin', 'php');
  try {
    const newest = readdirSync(base, { withFileTypes: true })
      .filter((e) => e.isDirectory() && /^php/i.test(e.name) && existsSync(join(base, e.name, 'php.exe')))
      .map((e) => e.name)
      .sort((a, b) => b.localeCompare(a))[0];
    return newest ? join(base, newest, 'php.exe') : null;
  } catch {
    return null;
  }
}

run((payload) => {
  const file = payload && payload.tool_input && payload.tool_input.file_path;
  if (!file || !/\.php$/i.test(file) || !existsSync(file) || !inWordPress(file)) return;

  const phpcs = join(process.env.APPDATA || '', 'Composer', 'vendor', 'bin', 'phpcs');
  if (!existsSync(phpcs)) return;
  const php = phpOnPath() || phpInLocalStack();
  if (!php || !existsSync(RULES)) return;

  const result = spawnSync(php, [phpcs, `--standard=${RULES}`, '--report=full', '--no-colors', '-q', file], {
    encoding: 'utf8',
    windowsHide: true,
    maxBuffer: 64 * 1024 * 1024,
  });
  // Findings are judged on stdout alone: a PHP notice on stderr must not silence them.
  if (result.error || result.status === 0) return;
  const lines = result.stdout.split(/\r?\n/);
  if (lines.at(-1) === '') lines.pop();
  if (lines.length === 0 || (lines.length === 1 && lines[0] === '')) return;
  refuse(`[wp-native/phpcs] WordPress security/correctness issues found in ${file} :\n${lines.join('\n')}`);
});
