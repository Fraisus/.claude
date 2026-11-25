// guard-private-read.mjs - PreToolUse: private paths of a project (global hook, per-project data).
// A project lists in <root>/.claude/private-paths.txt the paths no agent may read or change (one per line, relative to
// the root; "folder/" for a folder, * ? wildcards; # = comment). Without that file the hook does nothing.
// Covers Read, Edit, Write, MultiEdit, NotebookEdit (target path), Grep and Glob (folder or pattern naming a private
// path), Bash and PowerShell (private path named in the command). Known limit: a recursive search started by the shell
// from the root (grep -r .) is not seen. Exit 2 blocks with the stderr message, exit 0 allows.
// Also exports the .NET path semantics the other path guards share (invalid characters, Path.GetFullPath).
import { existsSync, readFileSync } from 'node:fs';
import { join, win32 } from 'node:path';
import { projectDir, refuse, run } from './lib/hook.mjs';
import { B, rx } from './lib/regex.mjs';

const ILLEGAL_PATH_CHARS = /["<>|\x00-\x1f]/;

// Throws where .NET Framework path APIs (IsPathRooted, Test-Path -LiteralPath) throw: the PowerShell hooks then
// died and let the tool through, which run() reproduces.
export function checkInvalidPathChars(p) {
  if (ILLEGAL_PATH_CHARS.test(p)) throw new Error('Illegal characters in path.');
}

// Path.IsPathRooted of .NET Framework.
function isPathRooted(p) {
  checkInvalidPathChars(p);
  return p[0] === '\\' || p[0] === '/' || p[1] === ':';
}

// Path.GetFullPath of .NET Framework (Win32 GetFullPathName): throws where it throws, and trims like Win32 (a single
// trailing period of an inner segment, every trailing period and space of the last one), which a lookup by name must
// follow since "secret." opens "secret".
export function winFullPath(input) {
  if (input.startsWith('\\\\?\\')) return input;
  checkInvalidPathChars(input);
  if (/[*?]/.test(input)) throw new Error('Illegal characters in path.');
  if (input.indexOf(':', 2) !== -1) throw new Error("The given path's format is not supported.");
  let p = input.replace(/\//g, '\\');
  if (/^[A-Za-z]:(?!\\)/.test(p)) p = `${win32.resolve(p.slice(0, 2))}\\${p.slice(2)}`;
  else if (p.startsWith('\\') && !p.startsWith('\\\\')) p = win32.parse(process.cwd()).root + p.slice(1);
  else if (!p.startsWith('\\\\') && p[1] !== ':') p = `${process.cwd()}\\${p}`;
  const m = /^([A-Za-z]:|\\\\[^\\]+\\[^\\]+)(.*)$/s.exec(p);
  if (!m) throw new Error('The UNC path should be of the form \\\\server\\share.');
  const [, prefix, rest] = m;
  const segs = rest.split('\\');
  const parts = [];
  let trailing = segs.at(-1) === '';
  segs.forEach((raw, i) => {
    if (raw === '' || raw === '.') return;
    if (raw === '..') {
      parts.pop();
      return;
    }
    const seg = i === segs.length - 1 ? raw.replace(/[. ]+$/, '') : raw.replace(/(?<=[^.])\.$/, '');
    if (seg) parts.push(seg);
    else trailing = true;
  });
  if (!parts.length) return prefix.startsWith('\\\\') && !rest ? prefix : `${prefix}\\`;
  return `${prefix}\\${parts.join('\\')}${trailing ? '\\' : ''}`;
}

// PowerShell -like: * ? [set] with ranges (no negation, a leading ] is literal), ` escapes, case-insensitive.
function likeToRegex(pattern) {
  const lit = (c) => (/[\\^$.*+?()[\]{}|/-]/.test(c) ? `\\${c}` : c);
  let out = '';
  for (let i = 0; i < pattern.length; i++) {
    const c = pattern[i];
    if (c === '`' && i + 1 < pattern.length) out += lit(pattern[++i]);
    else if (c === '*') out += '[\\s\\S]*';
    else if (c === '?') out += '[\\s\\S]';
    else if (c === '[') {
      let set = '';
      let j = i + 1;
      for (; j < pattern.length && (pattern[j] !== ']' || j === i + 1); j++) {
        let ch = pattern[j];
        if (ch === '`' && j + 1 < pattern.length) ch = pattern[++j];
        if (pattern[j + 1] === '-' && j + 2 < pattern.length && pattern[j + 2] !== ']') {
          set += `${lit(ch)}-${lit(pattern[j + 2])}`;
          j += 2;
        } else set += lit(ch);
      }
      if (j >= pattern.length) throw new Error(`The specified wildcard character pattern is not valid: ${pattern}`);
      out += `[${set}]`;
      i = j;
    } else out += lit(c);
  }
  return new RegExp(`^${out}$`, 'i');
}

const like = (text, pattern) => likeToRegex(pattern).test(text);

function guard(payload) {
  if (!payload) return;
  const root = projectDir(payload);
  if (!root) return;
  const list = join(root, '.claude/private-paths.txt');
  if (!existsSync(list)) return;
  const patterns = readFileSync(list, 'utf8')
    .replace(/^﻿/, '')
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim().replace(/\\/g, '/'))
    .filter((line) => line && !line.startsWith('#'));
  if (!patterns.length) return;

  const rootN = winFullPath(root).replace(/\\/g, '/').replace(/\/+$/, '').toLowerCase();

  // Path relative to the root, lowercased with "/"; null outside the project.
  const getRel = (value) => {
    if (!value) return null;
    let p = String(value);
    if (!isPathRooted(p)) p = `${root}\\${p}`;
    let full;
    try {
      full = winFullPath(p).replace(/\\/g, '/').toLowerCase();
    } catch {
      return null;
    }
    if (full === rootN) return '';
    if (full.startsWith(`${rootN}/`)) return full.slice(rootN.length + 1);
    return null;
  };

  const isPrivate = (rel) => {
    if (rel === null || rel === '') return false;
    for (const pat of patterns) {
      const p = pat.toLowerCase();
      const d = p.endsWith('/') ? p.replace(/\/+$/, '') : p;
      if (like(rel, d) || like(rel, `${d}/*`)) return true;
    }
    return false;
  };

  // A private path named in free text (shell command, Glob or Grep pattern).
  const mentions = (text) => {
    if (!text) return false;
    const s = String(text);
    const edge = String.raw`(^|[\s"'=:/\\(])`;
    const end = String.raw`(["'\s;&|)]|$)`;
    for (const pat of patterns) {
      const p = [...pat.replace(/\/+$/, '')]
        .map((c) => (c === '*' ? String.raw`[^\s/\\"']*` : c === '?' ? '[^\\n]' : /[\\^$.+()[\]{}|/]/.test(c) ? `\\${c}` : c))
        .join('');
      const test = (source) => rx(source).test(s);
      if (pat.endsWith('/')) {
        if (test(`${edge}${p}[\\\\/]`) || test(`${B}cd` + String.raw`\s+["']?([^\s"']*[\\/])?` + p + end)) return true;
      } else if (test(`${edge}${p}${end}`)) return true;
    }
    return false;
  };

  const tool = String(payload.tool_name ?? '');
  const input = payload.tool_input ?? {};
  let hit = false;
  if (/^(Read|Edit|Write|MultiEdit)$/i.test(tool)) hit = isPrivate(getRel(input.file_path));
  else if (/^NotebookEdit$/i.test(tool)) hit = isPrivate(getRel(input.notebook_path));
  else if (/^(Grep|Glob)$/i.test(tool)) {
    hit = isPrivate(getRel(input.path)) || mentions(input.glob) || (/^Glob$/i.test(tool) && mentions(input.pattern));
  } else if (/^(Bash|PowerShell)$/i.test(tool)) hit = mentions(input.command);

  if (hit) {
    refuse("BLOQUE : chemin privé du projet (.claude/private-paths.txt). Ne pas contourner ; seul l'utilisateur peut lever cette règle, en modifiant ce fichier lui-même.");
  }
}

if (import.meta.main) run(guard);
