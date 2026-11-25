// guard-rules-style.mjs - PostToolUse (Write|Edit|MultiEdit): instruction files read by the model (CLAUDE.md, the method
// injected by the plugin, .claude/rules/**.md, SKILL.md, agent definitions): flags an off-target size, repeated emphasis,
// a long quotation or a web link. Contract: the "Fichiers de règles" rule of ~/.claude/CLAUDE.md.
// Informs only: findings go to stderr with exit 2 (feedback to the model), otherwise exit 0.
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { refuse, run } from './lib/hook.mjs';
import { B } from './lib/regex.mjs';

const METHOD = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'references', 'portes.md');
const MAX_LINES = { claude: 200, skill: 500, rule: 300 };
const EMPHASIS = new RegExp(`${B}(IMPORTANT|CRITICAL|YOU MUST|MUST NOT|NEVER|ALWAYS)${B}`, 'gu');
const WEB_LINK = /https?:\/\/(?!claude\.ai\/artifact)/g;
const IGNORED = /\/(tmp_essais|node_modules|\.git)\//i;
const LONG_QUOTE = /[«“"][^»”"\r\n]{40,}[»”"]/g;

function kindOf(file) {
  const p = file.replaceAll('\\', '/');
  if (/\/CLAUDE\.md$/i.test(p) || resolve(file).toLowerCase() === METHOD.toLowerCase()) return 'claude';
  if (/\/SKILL\.md$/i.test(p)) return 'skill';
  if (/\/\.claude\/rules\/.+\.md$/i.test(p) || /\/\.claude\/agents\/[^/]+\.md$/i.test(p) || /\/skills\/[^/]+\/agents\/[^/]+\.md$/i.test(p)) return 'rule';
  return null;
}

const count = (re, src) => src.match(re)?.length ?? 0;

run((payload) => {
  const file = payload && payload.tool_input && payload.tool_input.file_path;
  if (!file || !existsSync(file)) return;
  const kind = kindOf(file);
  if (!kind || IGNORED.test(file.replaceAll('\\', '/'))) return;

  const src = readFileSync(file, 'utf8').replace(/^﻿/, '');
  if (!src) return;
  const lines = src.split('\n').length;
  const max = MAX_LINES[kind];
  const findings = [];
  if (lines > max) findings.push(`${lines} lignes (cible : ${max} au plus) : déplacer le détail vers une rule à paths: ou un skill`);
  const emphases = count(EMPHASIS, src);
  if (emphases > 1) findings.push(`${emphases} emphases (IMPORTANT, MUST…) : une seule, sur la ligne qui serait ignorée`);
  const links = count(WEB_LINK, src);
  if (links > 0 && kind !== 'skill') findings.push(`${links} lien(s) web : une règle guide le modèle, elle ne cite pas de source`);
  const quotes = count(LONG_QUOTE, src);
  if (quotes > 3) findings.push(`${quotes} longues citations : une consigne se dit, elle ne se cite pas`);
  if (findings.length === 0) return;

  refuse(`Fichier d'instructions ${file} :\n- ${findings.join('\n- ')}\n(Règle « Fichiers de règles » ; seulement un avertissement.)`);
});
