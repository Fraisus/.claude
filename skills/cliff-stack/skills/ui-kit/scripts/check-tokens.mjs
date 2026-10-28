#!/usr/bin/env node
/**
 * check-tokens.mjs: gate on a Design System tokens.json before publishing it.
 * ESM, zero dependency, Node 18+. Calibrates color.mjs first.
 *
 * Usage:
 *   node check-tokens.mjs <tokens.json | -> [pairs.json | -] [--base <color>] [--allow-unpaired]
 *   ("-" reads stdin, for one of the two files only; --help or -h prints this help)
 *
 *   pairs.json   the contrast couples the kit DECLARES (read from stdin with "-"):
 *                [["text-default", "bg-canvas", 4.5], ...]
 *                or [{"fg": "...", "bg": "...", "min": 3, "themes": ["dark"], "under": "bg-x"}]
 *                min 4.5 for text, 3 for large text (24px+, or bold 19px+), borders of
 *                controls, focus rings, icons that carry meaning.
 *                "themes" limits a couple to some themes; "under" is the token a
 *                translucent background is composited on (default --base).
 *   --base       ground under translucent backgrounds when no "under" (default #ffffff)
 *   --allow-unpaired  exit 0 even when some color tokens sit in no couple (still listed)
 *
 * Checks, every theme, every value the page will read:
 *   - shape (tokens-shape.mjs): every top-level key except "type" and the metadata keys (meta,
 *     name, version, $schema, description) carries a LIST {tokens:[{name,value,usage}]}; a
 *     name-to-value map, flat or nested, is valid JSON the page cannot read (family shown empty);
 *     every theme has an id ^[a-z0-9-]+$, and every key of a color or shadow value given per
 *     theme is a declared id (any other key is never read, the first theme measured instead)
 *   - names: grammar ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$, unique across all families but type
 *     (one --name namespace, a duplicate drops), type style names unique
 *   - colors: parseable per theme; "{alias}" resolves (no missing target, cycle, >16 deep);
 *     var(), color-mix(), named colors are refused by the page
 *   - usage note on every token; caps (8 themes, 60 tokens per other family, 12 groups, 80 styles)
 *   - contrast of each declared couple in each theme, translucent colors composited
 *   - coverage: every color token that is neither fg nor bg of a couple is listed as NOT measured
 *
 * Exit code: 1 on any error or contrast failure; else 2 when no pairs are given (structure
 * checked, contrast NOT measured) or when a color token sits in no couple, unless
 * --allow-unpaired; else 0.
 */
import fs from 'node:fs';
import { exitOnHelp } from './cli.mjs';
import { calibrate, contrast, parseColor } from './color.mjs';
import { shapeErrors } from './tokens-shape.mjs';

const argv = process.argv.slice(2);
exitOnHelp(argv, import.meta.url);
const allowIdx = argv.indexOf('--allow-unpaired');
const allowUnpaired = allowIdx >= 0 && argv.splice(allowIdx, 1).length > 0;
const baseIdx = argv.indexOf('--base');
const base = baseIdx >= 0 ? argv.splice(baseIdx, 2)[1] : '#ffffff';
const [file, pairsArg] = argv;
if (!file) {
  console.error('usage : node check-tokens.mjs <tokens.json> [pairs.json | -] [--base <color>] [--allow-unpaired]');
  process.exit(2);
}
try {
  calibrate();
} catch (e) {
  console.error(e.message);
  process.exit(1);
}

if (file === '-' && pairsArg === '-') {
  console.error("stdin ne sert qu'une fois : tokens ou couples, pas les deux");
  process.exit(2);
}
const t = JSON.parse(fs.readFileSync(file === '-' ? 0 : file, 'utf8'));
const errors = shapeErrors(t);
const warnings = [];
const NAME = /^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/;

// Shape
const families = Object.entries(t).filter(([, v]) => v && typeof v === 'object' && !Array.isArray(v) && ('tokens' in v || 'themes' in v));
if (!t.color || !Array.isArray(t.color.themes) || !t.color.themes.length) errors.push('color.themes absent : au moins un thème {id, name}');
const themes = (t.color?.themes ?? []).map((x) => String(x.id));
if (themes.length > 8) warnings.push(`${themes.length} thèmes, la page en lit 8 au plus`);

// Names
const seen = new Map();
for (const [k, v] of families) {
  const list = Array.isArray(v.tokens) ? v.tokens : [];
  if (k !== 'color' && list.length > 60) warnings.push(`famille « ${k} » : ${list.length} tokens, la page en lit 60 au plus`);
  for (const tok of list) {
    if (!NAME.test(tok.name ?? '')) errors.push(`nom illisible « ${tok.name} » (famille ${k})`);
    if (seen.has(tok.name)) errors.push(`doublon « ${tok.name} » (familles ${seen.get(tok.name)} et ${k}) : le second disparaît`);
    else seen.set(tok.name, k);
    if (!tok.usage) warnings.push(`« ${tok.name} » sans note d'usage`);
  }
}
const groups = t.type?.groups ?? [];
if (!Array.isArray(groups)) errors.push('type.groups doit être une liste');
else {
  if (groups.length > 12) warnings.push(`${groups.length} groupes de type, 12 au plus`);
  const styles = groups.flatMap((g) => g.styles ?? []);
  if (styles.length > 80) warnings.push(`${styles.length} styles de type, 80 au plus`);
  const sn = new Set();
  for (const s of styles) {
    if (!NAME.test(s.name ?? '')) errors.push(`style de type illisible « ${s.name} »`);
    if (sn.has(s.name)) errors.push(`style de type en double « ${s.name} »`);
    sn.add(s.name);
    if (s.fontSize === undefined) errors.push(`style « ${s.name} » sans fontSize : la page le retire`);
  }
}
for (const [key, stack] of Object.entries(t.type?.families ?? {})) {
  if (/[;{}<>\\()]/.test(stack)) errors.push(`famille de police « ${key} » : caractère interdit (fonction, accolade, point-virgule)`);
}

// Colors
const colorTokens = Array.isArray(t.color?.tokens) ? t.color.tokens : [];
const byName = new Map(colorTokens.map((x) => [x.name, x]));
function raw(name, theme) {
  const tok = byName.get(name);
  if (!tok) return undefined;
  if (typeof tok.value === 'string') return tok.value;
  return tok.value?.[theme] ?? tok.value?.[themes[0]] ?? Object.values(tok.value ?? {})[0];
}
function resolve(name, theme, chain = []) {
  if (chain.includes(name)) throw new Error(`alias en boucle : ${[...chain, name].join(' → ')}`);
  if (chain.length > 16) throw new Error(`chaîne d'alias de plus de 16 : ${chain.join(' → ')}`);
  const v = raw(name, theme);
  if (v === undefined) throw new Error(`token absent « ${name} »${chain.length ? ` (alias depuis ${chain.at(-1)})` : ''}`);
  const m = /^\{([^}]+)\}$/.exec(v.trim());
  if (m) return resolve(m[1], theme, [...chain, name]);
  if (!parseColor(v)) throw new Error(`« ${name} » (${theme}) : valeur « ${v} » illisible par la page (pas de var(), color-mix() ni nom de couleur)`);
  return v;
}
for (const tok of colorTokens) {
  for (const th of themes) {
    try {
      resolve(tok.name, th);
    } catch (e) {
      errors.push(e.message);
      break;
    }
  }
}

// Contrast
let pairs = [];
if (pairsArg) {
  const text = pairsArg === '-' ? fs.readFileSync(0, 'utf8') : fs.readFileSync(pairsArg, 'utf8');
  pairs = JSON.parse(text).map((p) => (Array.isArray(p) ? { fg: p[0], bg: p[1], min: p[2] } : p));
}
let measured = 0;
let fails = 0;
for (const p of pairs) {
  if (!p.fg || !p.bg || !(p.min > 0)) {
    errors.push(`couple illisible ${JSON.stringify(p)} : fg, bg et min attendus`);
    continue;
  }
  for (const th of p.themes ?? themes) {
    try {
      const under = p.under ? resolve(p.under, th) : base;
      const bg = resolve(p.bg, th);
      const fg = resolve(p.fg, th);
      const r = contrast(fg, bg, under);
      measured++;
      if (r < p.min) {
        fails++;
        console.log(`ÉCHEC ${th} : ${p.fg} sur ${p.bg} = ${r.toFixed(2)}:1 (minimum ${p.min})`);
      }
    } catch (e) {
      errors.push(`couple ${p.fg} / ${p.bg} (${th}) : ${e.message}`);
    }
  }
}

for (const w of warnings) console.log(`avertissement : ${w}`);
for (const e of errors) console.log(`ERREUR : ${e}`);
const dups = errors.filter((e) => e.startsWith('doublon')).length;
console.log(`${seen.size} tokens, ${dups} doublon(s), ${errors.length} erreur(s), ${warnings.length} avertissement(s)`);
if (!pairs.length) {
  console.log('aucun couple déclaré : contraste NON mesuré');
  process.exit(errors.length ? 1 : 2);
}
console.log(`${measured} couples mesurés sur ${themes.length} thème(s), ${fails} échec(s)`);
const paired = new Set(pairs.flatMap((p) => [p.fg, p.bg]));
const unpaired = colorTokens.map((x) => x.name).filter((n) => !paired.has(n));
if (unpaired.length) console.log(`${unpaired.length} jeton(s) de couleur dans aucun couple, contraste NON mesuré : ${unpaired.join(', ')}`);
if (errors.length || fails) process.exit(1);
process.exit(unpaired.length && !allowUnpaired ? 2 : 0);
