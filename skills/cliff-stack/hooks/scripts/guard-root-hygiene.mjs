// guard-root-hygiene.mjs - PreToolUse: no new entry (file or folder) at the project root, at any depth
// ("new\sub\file" creates the root folder "new"). Allowed: CLAUDE.md, .claude and the names listed in
// <project>\.claude\root-allow.txt (one per line, # = comment). Editing an existing root file and writing in a
// subfolder stay free. Channels: Write/Edit/MultiEdit/NotebookEdit file_path; Playwright MCP captures (a relative
// filename, bare name included, resolves from the project root); Bash/PowerShell deposit targets (redirections,
// tee/touch, writing cmdlets, mkdir, cp/mv) and npm/pnpm/yarn/bun install creating a package.json.
// Exit 2 blocks with the stderr message, exit 0 allows.
import { existsSync, lstatSync, readdirSync, readFileSync, realpathSync } from 'node:fs';
import { basename, join } from 'node:path';
import { checkInvalidPathChars, winFullPath } from './guard-private-read.mjs';
import { refuse, run } from './lib/hook.mjs';
import { B, rx, W } from './lib/regex.mjs';

// Long name of a segment given in 8.3 form, as Get-Item reports it: the junction itself, never its target.
function longSegment(parent, seg) {
  const full = `${parent.replace(/\\+$/, '')}\\${seg}`;
  const st = lstatSync(full, { bigint: true });
  if (!st.isSymbolicLink()) return basename(realpathSync.native(full));
  for (const name of readdirSync(parent)) {
    if (lstatSync(`${parent.replace(/\\+$/, '')}\\${name}`, { bigint: true }).ino === st.ino) return name;
  }
  return seg;
}

// Canonical form of a drive path, so that root and target compare alike: . and .. folded lexically, trailing periods
// and spaces dropped as Win32 does, then 8.3 names (CLIFFR~1) expanded through the closest existing ancestor.
// Lexical on the missing part: a wildcard or an invalid name stays judgeable.
function resolveCanonicalPath(path) {
  const parts = [];
  for (let seg of path.split('\\')) {
    if (seg !== '..' && parts.length > 0) seg = seg.replace(/[. ]+$/, '');
    if (seg === '.' || (seg === '' && parts.length > 0)) continue;
    if (seg === '..') {
      if (parts.length > 1) parts.pop();
      continue;
    }
    parts.push(seg);
  }
  let p = parts.join('\\');
  if (parts.length === 1) p += '\\';
  if (!p.includes('~')) return p;
  for (let n = parts.length; n >= 1; n--) {
    const head = parts.slice(0, n).join('\\') + (n === 1 ? '\\' : '');
    if (!existsSync(head)) continue;
    let long;
    try {
      long = `${parts[0].toUpperCase()}\\`;
      for (const seg of parts.slice(1, n)) long = `${long.replace(/\\+$/, '')}\\${seg.includes('~') ? longSegment(long, seg) : seg}`;
    } catch {
      return p;
    }
    const tail = parts.slice(n).join('\\');
    return tail ? `${long.replace(/\\+$/, '')}\\${tail}` : long;
  }
  return p;
}

// Windows PowerShell 5.1 ConvertTo-Json escapes these as \u00xx; the refusal is written as it did.
const psJson = (value) => JSON.stringify(value).replace(/[<>&']/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, '0')}`);
const reject = (reason) => refuse(psJson({ decision: 'block', reason }));

// Quoted, single-quoted or bare argument: the first group that matched.
const pick = (m, ...groups) => groups.map((g) => m[g]).find(Boolean) ?? '';

// Case-sensitive, as [regex]::Replace was.
const HEREDOC = new RegExp(String.raw`<<-?\s*['"]?([${W}]+)['"]?\r?\n[\s\S]*?\r?\n\s*\1${B}`, 'gu');
const HERE_STRING = /@(['"])[ \t]*\r?\n[\s\S]*?\r?\n\1@/g;
const INLINE_CODE = rx(
  String.raw`${B}(?:python3?|php|node|perl|ruby)\s+(?:-[${W}]+\s+)*-(?:c|r|e)\s+(?:"(?:[^"\\]|\\[\s\S])*"|'(?:[^'\\]|\\[\s\S])*')`,
  'g',
);
const CHEVRON = /(?<![0-9&=<>-])>{1,2}(?!=)/g;
const CHEVRON_TARGET = /^\s*(?:"([^"]+)"|'([^']+)'|([^\s;&|<>]+))/;
const TEE_TOUCH = rx(String.raw`${B}(tee|touch)\s+(?:-a\s+)?(?:"([^"]+)"|'([^']+)'|([^\s;&|<>-][^\s;&|<>]*))`, 'g');
const WRITING_CMDLET = rx(String.raw`${B}(Out-File|Set-Content|Add-Content|New-Item)${B}[^;&|\r\n]*`, 'g');
const PATH_PARAM = /-(File|Literal)?Path\s+(?:"([^"]+)"|'([^']+)'|([^\s;&|<>]+))/i;
const VALUE_PARAMS = /^\S+|\s-(ItemType|Type|Value|Name|Encoding|Width|InputObject)\s+(?:"[^"]*"|'[^']*'|\S+)/gi;
const POSITIONAL = /(?<!\S)(?:"([^"]+)"|'([^']+)'|([^\s;&|<>-][^\s;&|<>]*))/g;
const MKDIR = rx(String.raw`(?:^|\s)(mkdir|md)${B}(?<args>[^;&|\r\n]*)`, 'g');
const COPY_MOVE = rx(String.raw`${B}(cp|mv|copy|move|xcopy|robocopy|Copy-Item|Move-Item)${B}(?<args>[^;&|\r\n]*)`, 'g');
const COPY_ARG = /(?:"([^"]+)"|'([^']+)'|([^\s;&|<>-][^\s;&|<>]*))/g;
const CD = /^(cd|chdir|pushd|popd|set-location|sl|push-location|pop-location)(?=\s|$)([^\n]*)$/i;
const CD_ARG = /"([^"]*)"|'([^']*)'|(\S+)/g;
const INSTALL = rx(String.raw`^(npm|pnpm|yarn|bun)\s+(install|i|add|init)${B}`);
const SINK = /^(\/dev\/(null|stdout|stderr|tty)|\$null|nul)$/i;

// Splits a command line into simple commands in execution order: ; | && || & and newlines outside quotes. A & glued
// to a chevron (2>&1, &>f) is no separator. esc: the shell's escape character (\ in Bash, ` in PowerShell).
function splitShellSegments(s, esc) {
  const segs = [];
  let cur = '';
  let q = '';
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (c === esc && q !== "'" && i + 1 < s.length) {
      cur += c + s[++i];
      continue;
    }
    if (q) {
      if (c === q) q = '';
      cur += c;
      continue;
    }
    if (c === '"' || c === "'") {
      q = c;
      cur += c;
      continue;
    }
    let sep = c === ';' || c === '\n' || c === '|';
    if (c === '&') sep = s[i - 1] !== '>' && s[i - 1] !== '<' && s[i + 1] !== '>';
    if (sep) {
      segs.push(cur);
      cur = '';
      continue;
    }
    cur += c;
  }
  segs.push(cur);
  return segs;
}

// Same-length copy with the content of quoted strings masked: a chevron inside a string is content, not a redirection.
function hideQuotedContent(s, esc) {
  const out = s.split('');
  let q = '';
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (c === esc && q !== "'" && i + 1 < s.length) {
      if (q) out[i] = out[i + 1] = '_';
      i++;
      continue;
    }
    if (q) {
      if (c === q) q = '';
      else out[i] = '_';
      continue;
    }
    if (c === '"' || c === "'") q = c;
  }
  return out.join('');
}

// Deposit targets of a simple command: redirections, tee/touch, writing cmdlets, mkdir, cp/mv.
function getWriteTargets(seg, esc) {
  const targets = [];
  // Chevrons are looked for outside strings and outside language operators (=>, ->, <>, >=); the target is read in
  // the original text.
  for (const m of hideQuotedContent(seg, esc).matchAll(CHEVRON)) {
    const t = CHEVRON_TARGET.exec(seg.slice(m.index + m[0].length));
    if (t) targets.push(pick(t, 1, 2, 3));
  }
  for (const m of seg.matchAll(TEE_TOUCH)) targets.push(pick(m, 2, 3, 4));
  for (const m of seg.matchAll(WRITING_CMDLET)) {
    const v = m[0];
    const named = PATH_PARAM.exec(v);
    if (named) targets.push(pick(named, 2, 3, 4));
    else {
      const positional = v.replace(VALUE_PARAMS, ' ').matchAll(POSITIONAL).next().value;
      if (positional) targets.push(pick(positional, 1, 2, 3));
    }
  }
  for (const m of seg.matchAll(MKDIR)) for (const a of m.groups.args.matchAll(POSITIONAL)) targets.push(pick(a, 1, 2, 3));
  for (const m of seg.matchAll(COPY_MOVE)) {
    const args = [...m.groups.args.matchAll(COPY_ARG)].map((a) => pick(a, 1, 2, 3));
    if (args.length >= 2) targets.push(args.at(-1));
  }
  return targets;
}

run((payload) => {
  if (!payload) return;
  const tool = String(payload.tool_name ?? '');
  const toolLower = tool.toLowerCase();
  const projectRoot = process.env.CLAUDE_PROJECT_DIR;
  if (!projectRoot) return;
  const rootNorm = projectRoot.replace(/\//g, '\\').replace(/\\+$/, '');
  // Compared in canonical form: an 8.3 CLAUDE_PROJECT_DIR must recognise long-name targets, and the reverse.
  const rootLower = (/^[A-Za-z]:\\/.test(rootNorm) ? resolveCanonicalPath(rootNorm) : rootNorm).replace(/\\+$/, '').toLowerCase();

  // .claude carries root-allow.txt: without it a new project could never declare its first exception.
  const rootAllow = ['claude.md', '.claude'];
  const allowFile = join(rootNorm, '.claude\\root-allow.txt');
  if (existsSync(allowFile)) {
    try {
      for (const raw of readFileSync(allowFile, 'utf8').replace(/^﻿/, '').split(/\r\n|\r|\n/)) {
        const line = raw.trim();
        if (line && !line.startsWith('#')) rootAllow.push(line.toLowerCase());
      }
    } catch {
      // unreadable list: the defaults stand
    }
  }

  // Absolute canonical path of a Windows, MSYS (/c/...) or ~ path, a relative one resolved against base; null when
  // not resolvable.
  const toAbsolute = (path, base) => {
    if (!path) return null;
    let p = path.replace(/^["']+|["']+$/g, '');
    const msys = /^\/([a-zA-Z])(\/.*)?$/.exec(p);
    if (msys) p = `${msys[1]}:\\${msys[2] ?? ''}`;
    if (/^~(?=[\\/]|$)/.test(p) && process.env.USERPROFILE) p = process.env.USERPROFILE + p.slice(1);
    p = p.replace(/\//g, '\\');
    // A rooted path without a drive letter (a remote "/root/kit", PowerShell "\x") is absolute elsewhere.
    if (/^\\(?!\\)/.test(p)) return null;
    if (!/^[a-zA-Z]:/.test(p) && !p.startsWith('\\\\')) {
      if (!base) return null;
      p = `${base.replace(/\\+$/, '')}\\${p}`;
    }
    if (/^[A-Za-z]:\\/.test(p)) return resolveCanonicalPath(p);
    try {
      return winFullPath(p);
    } catch {
      return null;
    }
  };

  // The root entry a path goes through, at any depth; null outside the project or on the root itself.
  const rootLevel = (path, base) => {
    let p = toAbsolute(path, base);
    if (!p) return null;
    p = p.replace(/\\+$/, '');
    if (!p.toLowerCase().startsWith(`${rootLower}\\`)) return null;
    const first = p.slice(rootLower.length + 1).split('\\')[0];
    if (!first) return null;
    return { resolved: p.slice(0, rootLower.length + 1 + first.length), leaf: first };
  };

  // Write/Edit and Playwright captures resolve from the project root.
  const assertRootTarget = (path, channel, base = rootNorm) => {
    const hit = rootLevel(String(path), base);
    if (!hit) return;
    checkInvalidPathChars(hit.resolved);
    if (existsSync(hit.resolved)) return;
    if (rootAllow.includes(hit.leaf.toLowerCase())) return;
    reject(
      `BLOQUÉ (hygiène racine, ${channel}) : création de '${hit.leaf}' à la racine du projet interdite (fichier ou dossier, à toute profondeur). ` +
        "Un nom inattendu vient souvent d'un chemin à espace non cité : citer chaque chemin entre guillemets. " +
        "Captures navigateur : filename '.playwright-mcp\\<nom>.png', dossier de sortie du serveur MCP (il refuse tout chemin hors du projet) ; fichiers temporaires → scratchpad de session ; docs → un sous-dossier du projet. " +
        "Si ce fichier racine est vraiment légitime (demande explicite de l'utilisateur), ajouter son nom dans .claude\\root-allow.txt du projet.",
    );
  };

  const input = payload.tool_input ?? {};
  if (['write', 'edit', 'multiedit', 'notebookedit'].includes(toolLower)) {
    const fp = input.file_path || input.notebook_path;
    if (fp) assertRootTarget(fp, tool);
    return;
  }
  // The server resolves a relative filename from the workspace root; without one the capture goes to its output folder.
  if (/^mcp__playwright__browser_(take_screenshot|pdf_save)$/i.test(tool)) {
    if (input.filename) assertRootTarget(input.filename, 'capture Playwright');
    return;
  }
  if (toolLower !== 'bash' && toolLower !== 'powershell') return;
  const cmd = input.command;
  if (!cmd) return;

  // What is not shell goes before looking for redirections: heredoc bodies, PowerShell here-strings and inline code
  // (python -c, php -r, node -e...), whose =>, <>, <a@b.c> or <style> would pass for redirections.
  const scan = String(cmd).replace(HEREDOC, ' ').replace(HERE_STRING, ' ').replace(INLINE_CODE, ' ');

  const isBash = toolLower === 'bash';
  const esc = isBash ? '\\' : '`';
  const hasRootPackage = existsSync(join(rootNorm, 'package.json'));

  // New current folder after cd/pushd/Set-Location; null when not knowable (variable, cd -, wildcard): relative paths
  // that follow are no longer judged, absolute ones still are.
  const cdTarget = (verb, rest, cur) => {
    let arg = null;
    for (const tm of rest.matchAll(CD_ARG)) {
      const quoted = tm[1] !== undefined || tm[2] !== undefined;
      const tok = tm[1] ?? tm[2] ?? tm[3];
      if (!quoted && (/^-./.test(tok) || /^\/d$/i.test(tok))) continue;
      arg = tok;
      break;
    }
    if (arg === null) {
      if (isBash && (verb === 'cd' || verb === 'chdir')) return process.env.USERPROFILE || null;
      if (isBash) return null; // pushd without argument swaps the stack
      return cur; // Set-Location without argument (PowerShell 5.1) stays put
    }
    if (arg === '-' || /[$`%*?]/.test(arg)) return null;
    return toAbsolute(arg, cur);
  };

  // Current folder followed command after command, from the harness cwd (project root by default).
  let cur = payload.cwd ? toAbsolute(String(payload.cwd), rootNorm) : rootNorm;
  const dirStack = [];
  const subStack = [];

  for (const seg of splitShellSegments(scan, esc)) {
    let s = seg.trim();
    // A cd inside a Bash subshell ( ... ) does not survive its closing parenthesis.
    if (isBash) {
      while (s.startsWith('(')) {
        subStack.push(cur);
        s = s.slice(1).trimStart();
      }
    }
    let closes = 0;
    if (isBash) {
      while (s.endsWith(')') && subStack.length > closes) {
        closes++;
        s = s.slice(0, -1).trimEnd();
      }
    }
    if (s) {
      const cd = CD.exec(s);
      if (cd) {
        const verb = cd[1].toLowerCase();
        if (verb === 'popd' || verb === 'pop-location') cur = dirStack.length ? dirStack.pop() : null;
        else {
          if (verb === 'pushd' || verb === 'push-location') dirStack.push(cur);
          cur = cdTarget(verb, cd[2], cur);
        }
      } else {
        const install = !hasRootPackage && cur && cur.replace(/\\+$/, '').toLowerCase() === rootLower ? INSTALL.exec(s) : null;
        if (install) {
          reject(
            `BLOQUÉ (hygiène racine) : ce '${install[1]} ${install[2]}' créerait un package.json à la racine du projet (aucun n'y existe). Lance-le dans le sous-dossier outillé (ex. cd .vite) ou dans le scratchpad.`,
          );
        }
        for (const t of getWriteTargets(s, esc)) {
          if (!t || SINK.test(t) || t.startsWith('-') || t.startsWith('$')) continue;
          assertRootTarget(t, tool, cur);
        }
      }
    }
    for (let k = 0; k < closes; k++) cur = subStack.pop();
  }
});
