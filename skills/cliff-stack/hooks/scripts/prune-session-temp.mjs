// prune-session-temp.mjs - SessionStart: deletes the session folders Claude Code leaves in <temp>/claude/<project>/<uuid>
// once inactive for -Days days (default 7). Only UUID-named folders at the second level go; anything else belongs
// to another owner. Silent (a SessionStart output enters the context) and bounded by -BudgetSeconds (default 3): a
// backlog drains over several starts. Options keep the PowerShell names: -Days, -BudgetSeconds, -Root.
import { lstatSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { run } from './lib/hook.mjs';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const DAY_MS = 86_400_000;

function options(argv) {
  const found = { days: 7, budgetSeconds: 3, root: join(tmpdir(), 'claude') };
  for (let i = 0; i < argv.length - 1; i++) {
    const name = argv[i].toLowerCase();
    if (name === '-days' && Number.isFinite(Number(argv[i + 1]))) found.days = Number(argv[++i]);
    else if (name === '-budgetseconds' && Number.isFinite(Number(argv[i + 1]))) found.budgetSeconds = Number(argv[++i]);
    else if (name === '-root') found.root = argv[++i];
  }
  return found;
}

function subfolders(dir) {
  try {
    return readdirSync(dir, { withFileTypes: true }).filter((e) => e.isDirectory()).map((e) => join(dir, e.name));
  } catch {
    return [];
  }
}

// Activity reads on the files: a long session writes in its subfolders without dating its own folder.
function hasFileNewerThan(folder, cutoff) {
  const pending = [folder];
  while (pending.length) {
    const dir = pending.pop();
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const path = join(dir, entry.name);
      if (entry.isDirectory()) pending.push(path);
      else if (lstatSync(path).mtimeMs >= cutoff) return true;
    }
  }
  return false;
}

run((payload) => {
  const { days, budgetSeconds, root } = options(process.argv.slice(2));
  const current = payload && payload.session_id ? String(payload.session_id).toLowerCase() : null;
  const cutoff = Date.now() - days * DAY_MS;
  const started = Date.now();

  const stale = [];
  for (const project of subfolders(root)) {
    for (const session of subfolders(project)) {
      const name = session.slice(project.length + 1);
      if (!UUID.test(name) || name.toLowerCase() === current) continue;
      const mtime = lstatSync(session).mtimeMs;
      if (mtime < cutoff) stale.push({ session, mtime });
    }
  }
  stale.sort((a, b) => a.mtime - b.mtime);

  for (const { session } of stale) {
    if (Date.now() - started >= budgetSeconds * 1000) break;
    try {
      if (!hasFileNewerThan(session, cutoff)) rmSync(session, { recursive: true, force: true });
    } catch {
      // a folder in use or unreadable stays for the next start
    }
  }
});
