// guard-write-scope.mjs - PreToolUse firewall for Bash and PowerShell commands, on every project.
// Blocks writes outside the project, c:\tmp, ~/.claude, the session scratchpad and the roots listed in
// ~/.claude/write-scope-extra-roots.txt; blocks system-wide destruction everywhere; lets MySQL writes reach
// only the project database (wp-config.php DB_NAME or .claude/allowed-db); asks before a shell command
// rewrites the security configuration. Reads stay free everywhere (secrets: guard-secrets-read).
// Exit 2 with a JSON reason on stderr blocks, exit 0 allows.
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { ask, projectDir, refuse, run } from './lib/hook.mjs';
import { B as NET_BOUNDARY } from './lib/regex.mjs';

// The patterns keep the .NET semantics of the PowerShell version: \b and \d see Unicode letters and digits,
// `$` also matches before a final newline, `.` is written [^\n].
const END = String.raw`(?=\n?$)`;
const BT = '`';
const net = (source, flags) => new RegExp(source.replaceAll(String.raw`\b`, NET_BOUNDARY).replaceAll(String.raw`\d`, String.raw`\p{Nd}`), `${flags}u`);
// PowerShell -match: case-insensitive, first match.
const match = (text, source) => net(source, 'i').exec(text);
// [regex]::Matches: case-sensitive unless the caller asks otherwise.
const matchAll = (text, source, flags = '') => [...text.matchAll(net(source, `g${flags}`))];

const NOT_PATH = String.raw`[^\s"'<>|;${BT}&]`;
const PATH_RX = String.raw`(?:[A-Za-z]:[\\/]${NOT_PATH}+|/[a-z]/${NOT_PATH}+|//[a-z]/${NOT_PATH}+|\\\\${NOT_PATH}+)`;
// Quoted paths may hold spaces; group 1 is the inside.
const QUOTED_PATH_RX = String.raw`["']((?:[A-Za-z]:[\\/]|//?[a-z]/|\\\\)[^"']*)["']`;

const SYSTEM_KILL = [
  [String.raw`\bformat(\.com)?\s+[a-z]:`, "format d'un volume"],
  [String.raw`\bdiskpart\b`, 'diskpart (gestion partitions)'],
  [String.raw`\bbcdedit\b`, 'bcdedit (boot)'],
  [String.raw`\bcipher\s+/w`, 'cipher /w (wipe disque)'],
  [String.raw`\bvssadmin\s+delete`, 'suppression shadow copies'],
  [String.raw`\bwmic\b[^\n]*\bshadowcopy\b[^\n]*\bdelete\b`, 'suppression shadow copies (wmic)'],
  [String.raw`\brm\s+(-[a-z]*\s+)*-?[a-z]*r[a-z]*f?[a-z]*\s+/\s*(${END}|[;&|])`, 'rm -rf /'],
  [String.raw`\brm\s+(-[a-z]*\s+)*-?[a-z]*[rf][a-z]*\s+/[a-z]/\s*(${END}|[;&|])`, "rm -rf d'une racine disque"],
  [String.raw`\brm\s+(-[a-z]*\s+)*-?[a-z]*[rf][a-z]*\s+~\s*(${END}|[;&|])`, 'rm -rf du home'],
  [String.raw`:\(\)\s*\{\s*:\|:&\s*\}\s*;:`, 'fork bomb'],
  [String.raw`\b(curl|wget|iwr|invoke-webrequest)\b[^\n|]*\|\s*(bash|sh|powershell|pwsh|iex|invoke-expression)\b`, 'pipe download->shell (RCE)'],
  [String.raw`\b(iex|invoke-expression)\b[^\n]*\b(downloadstring|invoke-webrequest|iwr)\b`, 'IEX download (RCE)'],
];

const PROTECTED_SYSTEM_PATHS = ['c:\\windows', 'c:\\program files', 'c:\\program files (x86)', 'c:\\programdata', 'c:\\boot', 'c:\\system volume information', 'c:\\users\\default', 'c:\\$recycle.bin'];

const DESTRUCTIVE = [
  String.raw`\brm\s+`, String.raw`\brmdir\s+`, String.raw`\bmv\s+`, String.raw`\bcp\s+`, String.raw`\btee\s+`,
  String.raw`\bmkdir\s+`, String.raw`\btouch\s+`, String.raw`\bdd\s+`, String.raw`\bchmod\s+`, String.raw`\bchown\s+`, String.raw`\bln\s+`,
  String.raw`\bshred\s+`, String.raw`\btruncate\s+`,
  // cmd.exe builtins and PowerShell aliases of Remove-Item, purging robocopy
  String.raw`\bdel\s+`, String.raw`\berase\s+`, String.raw`\brd\s+`, String.raw`\brobocopy\b[^\n]*\s/(mir|purge)\b`,
  // redirection to a file, not to a standard stream
  String.raw`(?<![0-9&])>{1,2}\s+(?!/dev/null|&\d|/dev/stderr|/dev/stdout)(?=["'A-Za-z\./~$\\])`,
  String.raw`\bRemove-Item\b`, String.raw`\bDelete-Item\b`, String.raw`\bOut-File\b`, String.raw`\bSet-Content\b`,
  String.raw`\bAdd-Content\b`, String.raw`\bClear-Content\b`, String.raw`\bNew-Item\b`, String.raw`\bCopy-Item\b`,
  String.raw`\bMove-Item\b`, String.raw`\bRename-Item\b`, String.raw`\bWrite-Host\b\s+[^\n]*\s+>`,
];

// Families that mutate in place: every path of their own segment is a target. cp and mv only deposit on their last path.
const IN_PLACE_RX = String.raw`\b(rm|rmdir|del|erase|rd|shred|truncate|chmod|chown|dd|touch|mkdir|ln|Remove-Item|Delete-Item|Clear-Content|Set-Content|Add-Content|Out-File|New-Item|Rename-Item)\b(?<args>[^;&|\r\n]*)`;

const head = (cmd) => cmd.slice(0, 160);
const trimEnd = (text, chars) => text.replace(new RegExp(`[${chars}]+$`), '');
const trimBoth = (text, chars) => trimEnd(text.replace(new RegExp(`^[${chars}]+`), ''), chars);
const joinWin = (dir, child) => `${trimEnd(dir, '\\\\/')}\\${child}`;
// Get-Content: lines split on any newline, BOM dropped.
const readLines = (file) => readFileSync(file, 'utf8').replace(/^\uFEFF/, '').split(/\r\n|\r|\n/);

// The model reads this stderr as text: it keeps the escapes of Windows PowerShell's ConvertTo-Json.
function reject(reason) {
  refuse(JSON.stringify({ decision: 'block', reason }).replace(/[<>&']/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, '0')}`));
}

// Long form of an existing 8.3 path: node has no GetLongPathName, and realpath would also follow junctions.
const longNames = new Map();
function longPath(path) {
  if (!longNames.has(path)) {
    const out = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', '[Console]::OutputEncoding = [Text.Encoding]::UTF8; (Get-Item -LiteralPath $env:GUARD_SCOPE_PATH -Force).FullName'], {
      encoding: 'utf8',
      env: { ...process.env, GUARD_SCOPE_PATH: path },
      windowsHide: true,
    });
    const long = out.status === 0 ? out.stdout.replace(/\r?\n$/, '') : '';
    longNames.set(path, long || null);
  }
  return longNames.get(path);
}

// Canonical disk path, so that the prefix test judges the real target: . and .. folded lexically as Win32 does,
// then 8.3 names expanded through the nearest existing ancestor. A short name that does not exist aliases nothing.
function resolveCanonicalPath(path) {
  const parts = [];
  for (const seg of path.split('\\')) {
    if (seg === '.' || (seg === '' && parts.length > 0)) continue;
    if (seg === '..') {
      if (parts.length > 1) parts.pop();
      continue;
    }
    parts.push(seg);
  }
  const joined = (n) => parts.slice(0, n).join('\\') + (n === 1 ? '\\' : '');
  const canonical = joined(parts.length);
  if (!canonical.includes('~')) return canonical;
  for (let n = parts.length; n >= 1; n--) {
    const ancestor = joined(n);
    if (existsSync(ancestor)) {
      const long = longPath(ancestor);
      if (!long) return canonical;
      const tail = parts.slice(n).join('\\');
      return tail ? `${trimEnd(long, '\\\\')}\\${tail}` : long;
    }
  }
  return canonical;
}

function normalizePath(path) {
  let p = trimBoth(path, `"'`);
  const msys = match(p, String.raw`^/([a-z])/([^\n]*)${END}`);
  if (msys) p = `${msys[1]}:\\${msys[2].replaceAll('/', '\\')}`;
  // Keep the UNC prefix \\server\share: without it the network path would pass for a relative one.
  const isUnc = p.startsWith('\\\\');
  p = p.replaceAll('/', '\\').replace(/\\{2,}/g, '\\');
  if (isUnc) p = `\\${p}`;
  if (match(p, String.raw`^[A-Za-z]:\\`)) p = resolveCanonicalPath(p);
  return p.toLowerCase();
}

function isPathInZone(path, roots) {
  if (!path) return true;
  const p = normalizePath(path);
  // relative to the Claude cwd, the project by default
  if (!match(p, '^[a-z]:') && !p.startsWith('\\\\')) return true;
  // segment boundary: c:\tmp covers c:\tmp\x, not c:\tmpevil
  return roots.some((root) => p === root || p.startsWith(`${trimEnd(root, '\\\\')}\\`));
}

function allowedRoots(project) {
  const roots = [];
  if (project) roots.push(trimEnd(project.replaceAll('/', '\\'), '\\\\').toLowerCase());
  roots.push('c:\\tmp');
  const home = process.env.USERPROFILE;
  if (home) roots.push(joinWin(home, '.claude').toLowerCase());
  if (process.env.LOCALAPPDATA) roots.push(joinWin(process.env.LOCALAPPDATA, 'Temp\\claude').toLowerCase());
  if (home) {
    const extraRootsFile = joinWin(home, '.claude\\write-scope-extra-roots.txt');
    if (existsSync(extraRootsFile)) {
      let lines = [];
      try {
        lines = readLines(extraRootsFile);
      } catch {
        // unreadable extra roots: no extra zone
      }
      for (const raw of lines) {
        const line = raw.trim();
        if (line && !line.startsWith('#')) roots.push(trimEnd(line.replaceAll('/', '\\'), '\\\\').toLowerCase());
      }
    }
  }
  // Zones and targets share the canonical form: a zone given by its short name covers its long paths, and back.
  return roots.map((root) => trimEnd(normalizePath(root), '\\\\'));
}

function projectDatabase(project) {
  if (!project) return null;
  const wpConfig = joinWin(project, 'wp-config.php');
  if (existsSync(wpConfig)) {
    const m = match(readFileSync(wpConfig, 'utf8'), String.raw`define\(\s*['"]DB_NAME['"]\s*,\s*['"]([^'"]+)['"]`);
    if (m) return m[1].toLowerCase();
  }
  // Projects without WordPress opt in explicitly: the first line of .claude/allowed-db names their database.
  const allowedDb = joinWin(project, '.claude\\allowed-db');
  if (existsSync(allowedDb)) {
    const line = readLines(allowedDb).find((l) => match(l, String.raw`\S`) && !match(l, String.raw`^\s*#`));
    if (line) return line.trim().toLowerCase();
  }
  return null;
}

function guardMysql(cmd, projectDb) {
  if (!match(cmd, String.raw`\b(mysql|mysqldump|mariadb)(\.exe)?\b`)) return;
  const target =
    match(cmd, String.raw`--database[=\s]+(\S+)`) ||
    match(cmd, String.raw`\s-D\s*(\S+)`) ||
    match(cmd, String.raw`USE\s+${BT}?([a-zA-Z0-9_\-]+)${BT}?`) ||
    // positional form, the bare name right before -e (never the value of -u, -h or -P)
    match(cmd, String.raw`(?<!-[a-zA-Z]|--[a-z\-]{2,20})\s([a-zA-Z0-9_$\-]+)\s+(?:-[NB]\s+)*(-e|--execute)\b`);
  if (!match(cmd, String.raw`\b(DROP|CREATE|INSERT|UPDATE|DELETE|TRUNCATE|ALTER|GRANT|REVOKE|RENAME)\b`)) return;
  const targetDb = target ? trimBoth(target[1].toLowerCase(), `${BT}"'`) : '';
  if (!projectDb) reject(`MySQL write bloqué : pas de DB projet déclarée (wp-config.php DB_NAME ou .claude/allowed-db). cmd: ${head(cmd)}`);
  if (targetDb && targetDb !== projectDb) reject(`MySQL write bloqué : DB cible '${targetDb}' != DB projet '${projectDb}'`);
  // fail closed: an undeterminable database may be any base (a qualified other_db.table)
  if (!targetDb) reject(`MySQL write bloqué : DB cible indéterminable (utilise mysql <db> -e ..., -D ou USE). cmd: ${head(cmd)}`);
}

// Paths of one command segment with their index, quoted ones first and blanked before the unquoted scan.
function segmentPaths(segment) {
  const found = [];
  let rest = segment;
  for (const m of matchAll(segment, QUOTED_PATH_RX)) {
    found.push({ value: m[1], index: m.index });
    rest = rest.slice(0, m.index) + ' '.repeat(m[0].length) + rest.slice(m.index + m[0].length);
  }
  for (const m of matchAll(rest, PATH_RX)) found.push({ value: m[0], index: m.index });
  return found;
}

run((payload) => {
  const tool = String(payload?.tool_name ?? '').toLowerCase();
  if (tool !== 'bash' && tool !== 'powershell') return;
  const raw = payload.tool_input?.command;
  if (!raw) return;
  const cmd = String(raw);

  const project = projectDir(payload);
  const roots = allowedRoots(project);
  const projectDb = projectDatabase(project);

  // 0. System-wide destruction, blocked everywhere, even without a path.
  for (const [rx, why] of SYSTEM_KILL) {
    if (match(cmd, rx)) reject(`BLOQUÉ (interdit système absolu : ${why}) — ${head(cmd)}`);
  }
  if (match(cmd, String.raw`\breg(\.exe)?\s+(delete|add)\s+`) || match(cmd, String.raw`\b(Remove-Item|Set-ItemProperty|New-ItemProperty|Remove-ItemProperty)\b[^\n]*\bHK(LM|CU|CR|U|CC):`)) {
    if (match(cmd, String.raw`HK(LM|CR):\\?SYSTEM`) || match(cmd, 'HKEY_LOCAL_MACHINE') || match(cmd, String.raw`reg(\.exe)?\s+delete`)) {
      reject(`BLOQUÉ (modification du registre Windows) — ${head(cmd)}`);
    }
  }
  if (match(cmd, String.raw`\bgit\s+config\s+(--global|--system)\b`) && !match(cmd, String.raw`\bgit\s+config\s+(--global|--system)\s+(--get|--list|-l)\b`)) {
    reject('BLOQUÉ (git config global/system en écriture — affecte tous les repos). Utilise --local.');
  }
  if (
    match(cmd, String.raw`\b(Set-MpPreference|Disable-WindowsOptionalFeature)\b`) ||
    match(cmd, String.raw`netsh\s+advfirewall\s+set[^\n]*\boff\b`) ||
    match(cmd, String.raw`\bSet-ExecutionPolicy\b[^\n]*\b(Unrestricted|Bypass)\b[^\n]*\b(-Scope\s+)?(LocalMachine|CurrentUser)\b`)
  ) {
    reject(`BLOQUÉ (désactivation d'une protection système) — ${head(cmd)}`);
  }

  // 1. MySQL, before the path scan.
  guardMysql(cmd, projectDb);

  // 2a. A shell write to the security configuration asks a human (the harness prompt, never a file the model writes).
  // Detection by name only: an indirect write slips through, the primary barrier stays the Edit/Write ask rules.
  const isDestructive = DESTRUCTIVE.some((rx) => match(cmd, rx));
  const mutating =
    isDestructive ||
    match(cmd, String.raw`\b(sed|perl)\s+(-\S+\s+)*-[a-z]*i`) ||
    match(cmd, String.raw`\b(python\d?|php|node|perl|ruby|pwsh|powershell)(\.exe)?\s+(-\S+\s+)*(-c|-r|-e|-Command)\b`);
  const config = mutating && match(cmd, String.raw`(settings(\.local)?\.json|write-scope-extra-roots|hooks[\\/](scripts|hooks\.json)|hooks\.json|git-destructive-authorized)`);
  if (config) {
    ask(`Commande shell qui peut modifier la configuration de sécurité Claude (${config[1]}). Validation humaine requise.`);
    return;
  }
  if (!isDestructive) return;

  // Quoted paths first, then blanked, so that a path with spaces is never cut at its first space.
  const collected = [];
  let cmdNoQuoted = cmd;
  for (const m of matchAll(cmd, QUOTED_PATH_RX)) {
    collected.push(m[1]);
    cmdNoQuoted = cmdNoQuoted.replaceAll(m[0], ' ');
  }
  for (const m of matchAll(cmdNoQuoted, PATH_RX)) collected.push(m[0]);

  // 2b. Protected system paths.
  for (const pv of collected) {
    const np = normalizePath(pv);
    if (PROTECTED_SYSTEM_PATHS.some((sys) => np.startsWith(sys))) reject(`BLOQUÉ (écriture/suppression sur chemin système protégé : '${pv}')`);
  }

  // 2c. cd to an absolute path outside the zones before a destructive command; a relative cd stays in the checked cwd.
  const cd = match(cmd, String.raw`\b(cd|Set-Location|chdir|pushd)\s+(?:"([^"]+)"|'([^']+)'|([^\s"'&|;]+))`);
  if (cd) {
    const cdTarget = cd[2] || cd[3] || cd[4];
    if (match(cdTarget, String.raw`^(?:[A-Za-z]:[\\/]|/[a-z]/)`) && !isPathInZone(cdTarget, roots)) {
      reject(`BLOQUÉ (cd vers '${cdTarget}' hors projet avant une commande destructrice). Lance ce dossier comme projet Claude.`);
    }
  }
  // Recursive or forced rm whose target is an unresolved substitution, looked for in that rm's own arguments only.
  for (const m of matchAll(cmd, String.raw`\brm\b(?<args>[^;&|\r\n]*)`)) {
    const rmArgs = m.groups.args;
    if (match(rmArgs, String.raw`(^|\s)-[a-zA-Z]*[rf]`) && match(rmArgs, String.raw`\$\(|${BT}[^${BT}]+${BT}|\$\{`)) {
      reject('BLOQUÉ (rm récursif/forcé avec substitution comme cible — non vérifiable). Explicite le chemin.');
    }
  }

  // 2d. Deposit targets: last path of each copy or move, and each redirection target. An executable deposited
  // there loses the executable exemption of step 3 (no .exe dropped in a Startup folder).
  const deposits = [];
  for (const m of matchAll(cmd, String.raw`\b(cp|mv|copy|xcopy|robocopy|copy-item|move-item)\b(?<args>[^;&|\r\n]*)`, 'i')) {
    const found = segmentPaths(m.groups.args);
    if (found.length > 0) deposits.push(found.reduce((last, p) => (p.index > last.index ? p : last)).value);
  }
  for (const m of matchAll(cmd, String.raw`(?<![0-9&])>{1,2}\s*(?:"([^"]+)"|'([^']+)'|([^\s;&|<>]+))`)) deposits.push(m[1] || m[2] || m[3]);

  // 2e. A path outside the zones is a threat only as a write target, never as a read source.
  const writeTargets = deposits.map(normalizePath);
  for (const m of matchAll(cmd, IN_PLACE_RX, 'i')) {
    for (const { value } of segmentPaths(m.groups.args)) writeTargets.push(normalizePath(value));
  }

  // 3. Every absolute path found. Without any identifiable target, any path outside the zones blocks (fail closed).
  for (const p of collected) {
    if (match(p, String.raw`\.(exe|bat|cmd|sh|ps1|py|pl|rb|jar|phar)${END}`)) {
      const pNorm = normalizePath(p);
      if (!deposits.some((t) => normalizePath(t) === pNorm)) continue;
    }
    if (match(p, '/dev/(null|stdout|stderr|tty)')) continue;
    // URL fragments: host[:port] with or without a path, and file:/// with its empty authority
    if (match(p, String.raw`^[a-z]:/+(localhost|[a-z0-9.-]+\.[a-z]{2,})(:\d+)?(/|${END})`)) continue;
    if (match(p, '^[a-z]:///')) continue;
    if (!isPathInZone(p, roots)) {
      if (writeTargets.length > 0 && !writeTargets.includes(normalizePath(p))) continue;
      reject(`BLOQUÉ (écriture/destruction hors projet : '${p}'). Zones autorisées : ${roots.join(', ')}`);
    }
  }

  // 4. Destruction of a root without a readable path.
  if (match(cmd, String.raw`\brm\s+-rf?\s+/\s*${END}`) || match(cmd, String.raw`\brm\s+-rf?\s+/c/\s*${END}`) || match(cmd, String.raw`\bRemove-Item\s+[^\n]*-Recurse[^\n]*[Cc]:\\?\s*${END}`)) {
    reject(`BLOQUÉ (opération destructrice sur racine) : ${cmd}`);
  }
});
