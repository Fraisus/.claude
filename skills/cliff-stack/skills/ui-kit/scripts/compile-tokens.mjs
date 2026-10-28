#!/usr/bin/env node
/**
 * compile-tokens.mjs: compile a Design System tokens.json into tokens.css, laid out like
 * the page compiles it, for previews, canvases and local renders before the first save.
 * ESM, zero dependency, Node 18+.
 *
 * Usage:
 *   node compile-tokens.mjs <tokens.json> [out.css] [--title "<system title>"]
 *                           [--fonts-base <prefix>] [--no-type] [--no-font-face]
 *
 *   out.css        written when given, else printed on stdout
 *   --title        first-line title (default tokens.json "name", else "Design system").
 *                  The page takes over only a tokens.css whose first comment ENDS with
 *                  "generated from tokens.json": keep that ending.
 *   --fonts-base   prefix for @font-face urls (default "": the file paths of type.fonts,
 *                  a bare name meaning fonts/<name>, relative to tokens.css)
 *   --no-type      skip the .<style> class per type style
 *   --no-font-face skip @font-face rules
 *   --help, -h     print this help
 *
 * Refuses (exit 1, nothing written) a tokens.json the page would read wrong, by the shape
 * rules of tokens-shape.mjs: a family without a "tokens" list, metadata keys apart (it would
 * vanish from the css), a theme without an id ^[a-z0-9-]+$ (it would compile to
 * [data-theme="undefined"]), a color or shadow value keyed by an undeclared theme id (the first
 * theme's value would be compiled in its place).
 *
 * Layout (format of the Design System type):
 *   :root, [data-theme="<first>"] { colors and shadows of the first theme }
 *   [data-theme="<id>"] { overrides and re-declared aliases } per further theme
 *   :root { every other {tokens:[...]} family, then --font-<key> per type family }
 *   .<style> { ... } per type style, @font-face per type.fonts entry
 * Aliases "{name}" become var(--name); a "." in a name is escaped (--space-1\.5).
 */
import fs from 'node:fs';
import { exitOnHelp } from './cli.mjs';
import { shapeErrors } from './tokens-shape.mjs';

const argv = process.argv.slice(2);
exitOnHelp(argv, import.meta.url);
const flag = (n) => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv.splice(i, 1) && true : false;
};
const option = (n, d) => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv.splice(i, 2)[1] : d;
};
const noType = flag('--no-type');
const noFontFace = flag('--no-font-face');
const fontsBase = option('--fonts-base', '');
const titleOpt = option('--title');
const [src, out] = argv;
if (!src) {
  console.error('usage : node compile-tokens.mjs <tokens.json> [out.css] [--title t] [--fonts-base p] [--no-type] [--no-font-face]');
  process.exit(2);
}

const t = JSON.parse(fs.readFileSync(src, 'utf8'));
const shape = shapeErrors(t);
if (shape.length) {
  for (const e of shape) console.error(`ERREUR : ${e}`);
  console.error('rien de compilé : corriger tokens.json (node check-tokens.mjs le détaille)');
  process.exit(1);
}
const themes = (t.color?.themes ?? []).map((x) => String(x.id));
if (!themes.length) {
  console.error('color.themes absent : rien à compiler');
  process.exit(1);
}
const first = themes[0];
const esc = (name) => String(name).replace(/^(--|\.)/, '').replace(/\./g, '\\.');
const cssValue = (v) => {
  const m = /^\{([^}]+)\}$/.exec(String(v).trim());
  return m ? `var(--${esc(m[1])})` : String(v);
};
const valueFor = (tok, theme) => (typeof tok.value === 'string' || typeof tok.value === 'number' ? tok.value : tok.value?.[theme] ?? tok.value?.[first]);
const isAlias = (v) => /^\{[^}]+\}$/.test(String(v).trim());
const themed = [...(t.color?.tokens ?? []), ...(t.shadow?.tokens ?? [])];

const title = titleOpt ?? (typeof t.name === 'string' ? t.name : 'Design system');
let css = `/* ${title}, generated from tokens.json */\n`;

css += `:root, [data-theme="${first}"] {\n`;
for (const tok of themed) css += `  --${esc(tok.name)}: ${cssValue(valueFor(tok, first))};\n`;
css += '}\n';
for (const th of themes.slice(1)) {
  let block = '';
  for (const tok of themed) {
    const v = valueFor(tok, th);
    if (v !== valueFor(tok, first) || isAlias(v)) block += `  --${esc(tok.name)}: ${cssValue(v)};\n`;
  }
  css += `[data-theme="${th}"] {\n${block}}\n`;
}

css += ':root {\n';
for (const [key, fam] of Object.entries(t)) {
  if (['color', 'shadow', 'type'].includes(key) || !Array.isArray(fam?.tokens)) continue;
  for (const tok of fam.tokens) css += `  --${esc(tok.name)}: ${cssValue(valueFor(tok, first))};\n`;
}
const families = t.type?.families ?? {};
for (const [k, v] of Object.entries(families)) css += `  --font-${esc(String(k).toLowerCase())}: ${v};\n`;
css += '}\n';

const len = (v) => (typeof v === 'number' ? `${v}px` : v);
if (!noType) {
  for (const g of t.type?.groups ?? []) {
    for (const s of g.styles ?? []) {
      const fam = s.family ?? g.family;
      const decl = [];
      if (fam) decl.push(`font-family: ${fam in families ? `var(--font-${esc(String(fam).toLowerCase())})` : fam}`);
      if (s.fontSize !== undefined) decl.push(`font-size: ${len(s.fontSize)}`);
      if (s.lineHeight !== undefined) decl.push(`line-height: ${s.lineHeight}`);
      if (s.fontWeight !== undefined) decl.push(`font-weight: ${s.fontWeight}`);
      if (s.letterSpacing !== undefined) decl.push(`letter-spacing: ${len(s.letterSpacing)}`);
      if (s.fontStyle !== undefined) decl.push(`font-style: ${s.fontStyle}`);
      if (s.opticalSize !== undefined) decl.push(`font-variation-settings: 'opsz' ${s.opticalSize}`);
      css += `.${esc(s.name)} { ${decl.join('; ')}; }\n`;
    }
  }
}
if (!noFontFace) {
  const formats = { woff2: 'woff2', woff: 'woff', ttf: 'truetype', otf: 'opentype' };
  for (const f of t.type?.fonts ?? []) {
    const file = String(f.file ?? '');
    const path = file.includes('/') ? file : `fonts/${file}`;
    const ext = path.split('.').pop().toLowerCase();
    css += `@font-face { font-family: "${f.family}"; src: url("${fontsBase}${path}") format("${formats[ext] ?? ext}"); font-weight: ${f.weight ?? 400}; font-style: ${f.style ?? 'normal'}; font-display: swap; }\n`;
  }
}

if (out) {
  fs.writeFileSync(out, css);
  console.error(`écrit ${out}, ${css.length} octets`);
} else process.stdout.write(css);
