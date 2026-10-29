#!/usr/bin/env node
/**
 * wp-anchor.mjs: compare a UI kit to the presets a WordPress block theme SERVES, token by token.
 * ESM, zero dependency, Node 18+. Read-only: it never writes theme.json, the database or the kit;
 * which preset to reuse, adjust or declare stays the user's decision. Run it on request only.
 *
 * Usage:
 *   node wp-anchor.mjs <kit> <site.json> [--css <file>]... [--min-width <px>] [--max-width <px>]
 *                      [--rem <px>] [--tol <ratio>] [--tol-color <deltaE>] [--json]
 *   node wp-anchor.mjs --selftest
 *
 *   <kit>        tokens.json in the Design System format (families of {tokens:[{name,value,usage}]},
 *                type.groups[].styles[].fontSize, type.families), or a flat tokens.css of custom
 *                properties (--name: value; a "font" shorthand gives its size)
 *   <site.json>  output of wp-settings.php: wp eval-file <ui-kit>/scripts/wp-settings.php <site.json>
 *   --css        extra stylesheet defining custom properties that presets reference as var(--x)
 *                (the stylesheets and inline styles listed in site.json are read already)
 *   --min-width, --max-width  viewport range over which fluid values are evaluated (default:
 *                settings.typography.fluid min/maxViewportWidth, else 320 and layout.wideSize)
 *   --rem        root font size in px (default 16)
 *   --tol        relative gap under which a length still counts as anchored "proche" (default 0.05)
 *   --tol-color  OKLab distance under which a color still counts as anchored "proche" (default 0.01:
 *                light tints sit within 0.02 of a neutral while their hue is plainly visible)
 *   --json       structured output instead of the text report
 *   --selftest   calibration on a synthetic site: exact white, absent color, fluid range, var()
 *
 * Families compared (kit family -> served preset family):
 *   color -> color.palette        font size -> typography.fontSizes   font family -> fontFamilies
 *   spacing -> spacing.spacingSizes   radius -> border.radiusSizes   shadow -> shadow.presets
 *   width -> layout.contentSize, layout.wideSize, dimensions.dimensionSizes
 * Any other kit token (duration, easing, z-index, fixed heights) is listed as outside the preset
 * families. A kit token's family comes from its tokens.json family key, else from its value
 * (color), else from words in its name (space, gap, gutter, radius, shadow, layout, width...).
 *
 * Values: each preset is read from the served --wp--preset--<family>--<slug> declaration at :root
 * (fluid font sizes already computed by WordPress), var() resolved through site.json and --css
 * sheets, then evaluated as a CSS math expression (calc, clamp, min, max, var with fallback; px,
 * rem, em, vw, vi, cqi, cqw; container width taken equal to the viewport) at every width of the
 * range: a fluid preset is a [min, max] interval, never one number.
 *
 * Verdicts per kit token, best candidate first:
 *   exact   same constant value (lengths within 0.5px; colors same sRGB and alpha)
 *   borne   the kit value equals the min or the max of a fluid preset
 *   plage   the kit value lies inside a fluid preset's interval (width where it is reached given)
 *   proche  within --tol (lengths) or --tol-color (colors), gap given
 *   sans ancrage  nothing within tolerance; the nearest preset and its gap are given for reading
 * Then: presets no kit token anchors on, presets whose served value differs from the theme layer
 * (set in the database), presets redefined per block, values that could not be evaluated.
 *
 * Exit code: 0 report written, 1 bad input or selftest drift, 2 usage.
 */
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';
import { exitOnHelp } from './cli.mjs';
import { calibrate, hexToOklch, parseColor, rgbToHex } from './color.mjs';
import { shapeErrors } from './tokens-shape.mjs';

const FAMILY_LABEL = {
  color: 'couleur',
  'font-size': 'taille',
  'font-family': 'police',
  spacing: 'espacement',
  radius: 'rayon',
  shadow: 'ombre',
  width: 'largeur',
};
// served CSS family name -> compared family
const PRESET_FAMILY = {
  color: 'color',
  'font-size': 'font-size',
  'font-family': 'font-family',
  spacing: 'spacing',
  'border-radius': 'radius',
  shadow: 'shadow',
  dimension: 'width',
};
// settings path of each served family, for names, origins and the theme layer comparison
const SETTINGS_PATH = {
  color: ['color', 'palette', 'color'],
  'font-size': ['typography', 'fontSizes', 'size'],
  'font-family': ['typography', 'fontFamilies', 'fontFamily'],
  spacing: ['spacing', 'spacingSizes', 'size'],
  'border-radius': ['border', 'radiusSizes', 'size'],
  shadow: ['shadow', 'presets', 'shadow'],
  dimension: ['dimensions', 'dimensionSizes', 'size'],
};
const NAME_RULES = [
  [/shadow|elevation/i, 'shadow'],
  [/radius|radii|rounded|corner/i, 'radius'],
  [/(^|-)font(-family)?(-|$)|typeface|family/i, 'font-family'],
  [/font-size|(^|-)fs(-|$)|(^|-)(text|type)-/i, 'font-size'],
  [/space|spacing|gap|gutter|section|inset|padding|margin|stack/i, 'spacing'],
  [/layout|container|content|wide|prose|measure|max-width|(^|-)width/i, 'width'],
];

/* ---------- CSS custom properties ---------- */

/** Every `--name: value` of a stylesheet with its selector; innermost rules, comments removed. */
export function parseCustomProps(css) {
  const out = [];
  const text = String(css).replace(/\/\*[\s\S]*?\*\//g, '');
  const rule = /([^{}]*)\{([^{}]*)\}/g;
  let m;
  while ((m = rule.exec(text))) {
    const selector = m[1].trim();
    for (const decl of splitDecls(m[2])) {
      const d = /^\s*(--[A-Za-z0-9_-]+)\s*:\s*([\s\S]*?)\s*$/.exec(decl);
      if (d) out.push({ selector, name: d[1], value: d[2].replace(/\s*!important$/i, '') });
    }
  }
  return out;
}

function splitDecls(body) {
  const parts = [];
  let depth = 0;
  let cur = '';
  let quote = '';
  for (const ch of body) {
    if (quote) {
      if (ch === quote) quote = '';
    } else if (ch === '"' || ch === "'") quote = ch;
    else if (ch === '(') depth++;
    else if (ch === ')') depth--;
    else if (ch === ';' && depth === 0) {
      parts.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  if (cur.trim()) parts.push(cur);
  return parts;
}

const ROOT_SELECTOR = /^(:root|html|body)$/i;
const isRootSelector = (sel) => sel.split(',').some((s) => ROOT_SELECTOR.test(s.trim()));

/** name -> value, a declaration at :root, html or body winning over any other selector. */
export function buildVarMap(sheets) {
  const root = new Map();
  const other = new Map();
  for (const css of sheets) {
    for (const d of parseCustomProps(css)) {
      const target = isRootSelector(d.selector) ? root : other;
      if (!target.has(d.name)) target.set(d.name, d.value);
    }
  }
  for (const [k, v] of other) if (!root.has(k)) root.set(k, v);
  return root;
}

/* ---------- CSS math evaluation ---------- */

class Unresolved extends Error {}

function tokenize(src) {
  const toks = [];
  const re = /\s*(?:(-?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)([a-z%]*)|(--[A-Za-z0-9_-]+)|([A-Za-z-]+)\(|([()+\-*/,]))/iy;
  let pos = 0;
  const s = String(src).trim();
  while (pos < s.length) {
    re.lastIndex = pos;
    const m = re.exec(s);
    if (!m) throw new Error(`expression illisible près de « ${s.slice(pos, pos + 20)} »`);
    pos = re.lastIndex;
    if (m[1] !== undefined) {
      const prev = toks.at(-1);
      // "a -2px" binary minus written without space before the digit: keep the minus as operator
      if (m[1].startsWith('-') && prev && (prev.t === 'num' || prev.t === ')')) {
        toks.push({ t: 'op', v: '-' }, { t: 'num', n: parseFloat(m[1].slice(1)), u: m[2].toLowerCase() });
      } else toks.push({ t: 'num', n: parseFloat(m[1]), u: m[2].toLowerCase() });
    } else if (m[3]) toks.push({ t: 'ident', v: m[3] });
    else if (m[4]) toks.push({ t: 'fn', v: m[4].toLowerCase() });
    else if (m[5] === '(' || m[5] === ')' || m[5] === ',') toks.push({ t: m[5] });
    else toks.push({ t: 'op', v: m[5] });
  }
  return toks;
}

/**
 * Evaluates a CSS length expression at viewport width w. Returns {n, len} (len: px length,
 * else unitless). Throws Unresolved on a var() with no definition and no fallback.
 */
export function evalLength(expr, ctx, w, depth = 0) {
  if (depth > 16) throw new Error('chaîne de var() de plus de 16');
  const toks = tokenize(expr);
  let i = 0;
  const peek = () => toks[i];
  const next = () => toks[i++];
  const expect = (t) => {
    const k = next();
    if (!k || k.t !== t) throw new Error(`« ${t} » attendu dans ${expr}`);
  };
  const unit = (n, u) => {
    switch (u) {
      case '': return { n, len: false };
      case 'px': return { n, len: true };
      case 'rem': case 'em': return { n: n * ctx.rem, len: true };
      case 'vw': case 'vi': case 'cqi': case 'cqw': case 'vmax': return { n: (n * w) / 100, len: true };
      default: throw new Error(`unité « ${u} » non évaluée`);
    }
  };
  const rawArg = () => {
    // raw text of a var() fallback, up to the matching close paren
    let level = 0;
    const start = i;
    while (i < toks.length) {
      const k = toks[i];
      if (k.t === '(' || k.t === 'fn') level++;
      else if (k.t === ')') {
        if (level === 0) break;
        level--;
      }
      i++;
    }
    return toks.slice(start, i);
  };
  const fromToks = (list) => list.map((k) => (k.t === 'num' ? `${k.n}${k.u}` : k.t === 'ident' ? k.v : k.t === 'fn' ? `${k.v}(` : k.t === 'op' ? ` ${k.v} ` : k.t)).join('');
  function sum() {
    let a = product();
    while (peek()?.t === 'op' && (peek().v === '+' || peek().v === '-')) {
      const op = next().v;
      const b = product();
      if (a.len !== b.len && a.n !== 0 && b.n !== 0) throw new Error(`addition de types différents dans ${expr}`);
      a = { n: op === '+' ? a.n + b.n : a.n - b.n, len: a.len || b.len };
    }
    return a;
  }
  function product() {
    let a = unary();
    while (peek()?.t === 'op' && (peek().v === '*' || peek().v === '/')) {
      const op = next().v;
      const b = unary();
      a = op === '*' ? { n: a.n * b.n, len: a.len || b.len } : { n: a.n / b.n, len: a.len && !b.len };
    }
    return a;
  }
  function unary() {
    if (peek()?.t === 'op' && peek().v === '-') {
      next();
      const v = unary();
      return { n: -v.n, len: v.len };
    }
    return primary();
  }
  function args() {
    const list = [sum()];
    while (peek()?.t === ',') {
      next();
      list.push(sum());
    }
    expect(')');
    return list;
  }
  function primary() {
    const k = next();
    if (!k) throw new Error(`expression incomplète : ${expr}`);
    if (k.t === 'num') return unit(k.n, k.u);
    if (k.t === '(') {
      const v = sum();
      expect(')');
      return v;
    }
    if (k.t === 'fn') {
      if (k.v === 'var') {
        const name = next();
        if (!name || name.t !== 'ident') throw new Error(`var() sans nom dans ${expr}`);
        let fallback = null;
        if (peek()?.t === ',') {
          next();
          fallback = fromToks(rawArg());
        }
        expect(')');
        const def = ctx.vars.get(name.v);
        if (def !== undefined && def.trim() !== '') return evalLength(def, ctx, w, depth + 1);
        if (fallback !== null && fallback.trim() !== '') return evalLength(fallback, ctx, w, depth + 1);
        throw new Unresolved(name.v);
      }
      if (k.v === 'calc') {
        const v = sum();
        expect(')');
        return v;
      }
      if (k.v === 'clamp') {
        const [lo, val, hi] = args();
        return { n: Math.max(lo.n, Math.min(val.n, hi.n)), len: lo.len || val.len || hi.len };
      }
      if (k.v === 'min' || k.v === 'max') {
        const list = args();
        return { n: Math[k.v](...list.map((x) => x.n)), len: list.some((x) => x.len) };
      }
      throw new Error(`fonction « ${k.v}() » non évaluée`);
    }
    throw new Error(`jeton inattendu dans ${expr}`);
  }
  const v = sum();
  if (i !== toks.length) throw new Error(`reste non lu dans ${expr}`);
  return v;
}

/** Interval of a length over the viewport range in px: {lo, hi, wLoEnd (last width still at lo), wHi (first width at hi), fluid}. */
export function lengthRange(expr, ctx) {
  let lo = Infinity;
  let hi = -Infinity;
  let wLoEnd = ctx.minW;
  let wHi = ctx.minW;
  const samples = [];
  for (let w = ctx.minW; w <= ctx.maxW; w++) {
    const v = evalLength(expr, ctx, w);
    if (!v.len && v.n !== 0) throw new Error(`pas une longueur : ${expr}`);
    samples.push([w, v.n]);
    if (v.n < lo) lo = v.n;
    if (v.n > hi) [hi, wHi] = [v.n, w];
  }
  for (const [w, n] of samples) if (n <= lo + 0.01) wLoEnd = w;
  return { lo, hi, wLoEnd, wHi, fluid: hi - lo > 0.01 };
}

/** First width of the range where the expression comes closest to px. */
function widthReaching(expr, ctx, px) {
  let best = { d: Infinity, w: ctx.minW };
  for (let w = ctx.minW; w <= ctx.maxW; w++) {
    const d = Math.abs(evalLength(expr, ctx, w).n - px);
    if (d < best.d - 1e-9) best = { d, w };
  }
  return best.w;
}

/* ---------- value kinds ---------- */

/** var() substituted textually (for colors, shadows, font stacks), fallback used when undefined. */
function substituteVars(value, vars, depth = 0) {
  if (depth > 16) throw new Error('chaîne de var() de plus de 16');
  let out = '';
  let s = String(value);
  for (;;) {
    const at = s.indexOf('var(');
    if (at < 0) return out + s;
    out += s.slice(0, at);
    let level = 0;
    let end = at + 4;
    for (; end < s.length; end++) {
      if (s[end] === '(') level++;
      else if (s[end] === ')') {
        if (level === 0) break;
        level--;
      }
    }
    const inner = s.slice(at + 4, end);
    const comma = topLevelComma(inner);
    const name = (comma < 0 ? inner : inner.slice(0, comma)).trim();
    const fallback = comma < 0 ? null : inner.slice(comma + 1).trim();
    const def = vars.get(name);
    if (def !== undefined && def.trim() !== '') out += substituteVars(def, vars, depth + 1);
    else if (fallback !== null) out += substituteVars(fallback, vars, depth + 1);
    else throw new Unresolved(name);
    s = s.slice(end + 1);
  }
}

function topLevelComma(s) {
  let level = 0;
  for (let i = 0; i < s.length; i++) {
    if (s[i] === '(') level++;
    else if (s[i] === ')') level--;
    else if (s[i] === ',' && level === 0) return i;
  }
  return -1;
}

function oklab(css) {
  const c = parseColor(css);
  if (!c) return null;
  const { L, C, H } = hexToOklch(rgbToHex(c.rgb));
  const h = ((Number.isFinite(H) ? H : 0) * Math.PI) / 180;
  return { L, a: C * Math.cos(h), b: C * Math.sin(h), alpha: c.alpha, hex: rgbToHex(c.rgb) };
}
const colorDistance = (x, y) => Math.hypot(x.L - y.L, x.a - y.a, x.b - y.b) + Math.abs(x.alpha - y.alpha);

const firstFamily = (stack) => String(stack).split(',')[0].trim().replace(/^["']|["']$/g, '').toLowerCase();
const familyList = (stack) => String(stack).split(',').map((f) => f.trim().replace(/^["']|["']$/g, '').toLowerCase());

/** Lengths of a box-shadow, colors and keywords dropped, layer by layer. */
function shadowGeometry(css) {
  const layers = [];
  let level = 0;
  let cur = '';
  for (const ch of String(css)) {
    if (ch === '(') level++;
    if (ch === ')') level--;
    if (ch === ',' && level === 0) {
      layers.push(cur);
      cur = '';
    } else cur += ch;
  }
  layers.push(cur);
  return layers.map((l) => {
    const flat = l.replace(/[a-z-]+\([^()]*(\([^()]*\)[^()]*)*\)/gi, ' ').replace(/#[0-9a-f]{3,8}/gi, ' ');
    return (flat.match(/-?(?:\d+\.?\d*|\.\d+)(?:px|rem|em)?/gi) ?? []).map((x) => parseFloat(x) * (/r?em$/i.test(x) ? 16 : 1));
  });
}
function geometryGap(a, b) {
  let gap = 0;
  const n = Math.max(a.length, b.length);
  for (let i = 0; i < n; i++) {
    const la = a[i] ?? [];
    const lb = b[i] ?? [];
    for (let j = 0; j < Math.max(la.length, lb.length); j++) gap += Math.abs((la[j] ?? 0) - (lb[j] ?? 0));
  }
  return gap;
}

const BORDER_STYLE = /\b(solid|dashed|dotted|double|groove|ridge|inset|outset)\b/i;

/** Size of a "font" shorthand (700 80px/1.05 family), or null. */
function fontShorthandSize(v) {
  const m = /(?:^|\s)((?:\d+\.?\d*|\.\d+)(?:px|rem|em)|(?:calc|clamp|min|max|var)\([^/]*?\))(?:\s*\/\s*[\d.]+[a-z%]*)?\s+["'A-Za-z]/i.exec(String(v));
  return m ? m[1] : null;
}

/* ---------- kit ---------- */

function classifyByName(name) {
  for (const [re, fam] of NAME_RULES) if (re.test(name)) return fam;
  return null;
}

/** Kit tokens as {name, family, value, source}. family null: outside the preset families. */
export function readKit(text, file = '') {
  const trimmed = text.trim();
  if (trimmed.startsWith('{')) return kitFromJson(JSON.parse(trimmed));
  return kitFromCss(text, file);
}

function kitFromJson(t) {
  const errors = shapeErrors(t);
  if (errors.length) throw new Error(`tokens.json refusé (forme) : ${errors.join(' ; ')}`);
  const tokens = [];
  const themes = (t.color?.themes ?? []).map((x) => x.id);
  const first = (v) => (v && typeof v === 'object' ? v[themes[0]] ?? Object.values(v)[0] : v);
  const all = new Map();
  for (const [key, fam] of Object.entries(t)) {
    if (!Array.isArray(fam?.tokens)) continue;
    for (const tok of fam.tokens) all.set(tok.name, first(tok.value));
  }
  const deref = (v, seen = []) => {
    const m = /^\{([^}]+)\}$/.exec(String(v).trim());
    if (!m || seen.includes(m[1])) return v;
    return deref(all.get(m[1]), [...seen, m[1]]);
  };
  for (const [key, fam] of Object.entries(t)) {
    if (!Array.isArray(fam?.tokens)) continue;
    const famByKey = key === 'color' ? 'color' : classifyByName(key);
    for (const tok of fam.tokens) {
      const value = String(deref(first(tok.value)));
      let family = famByKey;
      if (!family && parseColor(value)) family = 'color';
      if (!family) family = classifyByName(tok.name);
      tokens.push({ name: tok.name, family, value, source: key });
    }
  }
  for (const [key, stack] of Object.entries(t.type?.families ?? {})) tokens.push({ name: `font-${key}`, family: 'font-family', value: String(stack), source: 'type.families' });
  for (const g of t.type?.groups ?? []) {
    for (const s of g.styles ?? []) {
      if (s.fontSize === undefined) continue;
      const v = typeof s.fontSize === 'number' ? `${s.fontSize}px` : String(s.fontSize);
      tokens.push({ name: s.name, family: 'font-size', value: v, source: 'type.groups' });
    }
  }
  return tokens;
}

function kitFromCss(css) {
  const decls = parseCustomProps(css);
  if (!decls.length) throw new Error('aucune propriété personnalisée --nom: valeur dans le kit');
  const vars = buildVarMap([css]);
  const tokens = [];
  const seen = new Set();
  for (const d of decls) {
    if (seen.has(d.name)) continue;
    seen.add(d.name);
    const name = d.name.slice(2);
    let family = null;
    let resolved = d.value;
    try {
      resolved = substituteVars(d.value, vars);
    } catch {
      /* kept raw, reported as not evaluable */
    }
    const size = fontShorthandSize(resolved);
    if (parseColor(resolved)) family = 'color';
    else if (/shadow|elevation/i.test(name)) family = 'shadow';
    else if (size && !BORDER_STYLE.test(resolved)) {
      family = 'font-size';
      resolved = size;
    } else family = classifyByName(name);
    if (family === 'font-size' && !/^-?[\d.]|^(calc|clamp|min|max)\(/i.test(resolved.trim())) family = null;
    tokens.push({ name: d.name, family, value: resolved, source: 'css' });
  }
  return tokens;
}

/* ---------- site ---------- */

function getPath(o, path) {
  return path.reduce((a, k) => (a == null ? undefined : a[k]), o);
}
const kebab = (s) => String(s).replace(/([a-z0-9])([A-Z])/g, '$1-$2').replace(/([a-z])([0-9])/g, '$1-$2').replace(/[\s_]+/g, '-').toLowerCase();

/** Presets served at :root, with name, origin, theme layer value, block-scoped redefinitions. */
export function readSite(site, extraCss = []) {
  const variables = String(site.variables_css ?? '');
  const sheets = [variables, String(site.inline_css ?? '')];
  const missing = [];
  for (const p of site.stylesheets ?? []) {
    if (fs.existsSync(p)) sheets.push(fs.readFileSync(p, 'utf8'));
    else missing.push(p);
  }
  for (const p of extraCss) sheets.push(fs.readFileSync(p, 'utf8'));
  const vars = buildVarMap(sheets);
  const decls = parseCustomProps(variables);
  const presets = [];
  const blockScoped = new Map();
  for (const d of decls) {
    const m = /^--wp--preset--([a-z-]+?)--(.+)$/.exec(d.name);
    if (!m || !(m[1] in PRESET_FAMILY)) continue;
    if (!isRootSelector(d.selector)) {
      const key = d.selector;
      if (!blockScoped.has(key)) blockScoped.set(key, new Set());
      blockScoped.get(key).add(`${m[1]}/${m[2]}`);
      continue;
    }
    if (presets.some((p) => p.cssFamily === m[1] && p.slug === m[2])) continue;
    presets.push({ cssFamily: m[1], family: PRESET_FAMILY[m[1]], slug: m[2], value: d.value });
  }
  // names and origins from the merged settings; theme layer value for the comparison
  for (const p of presets) {
    const [group, key, field] = SETTINGS_PATH[p.cssFamily];
    const byOrigin = getPath(site.settings, [group, key]) ?? {};
    for (const origin of ['custom', 'theme', 'default']) {
      const hit = (byOrigin[origin] ?? []).find((x) => kebab(x.slug) === p.slug);
      if (hit && !p.origin) {
        p.origin = origin;
        p.label = hit.name;
      }
    }
    const themeList = getPath(site.theme_settings, [group, key]);
    const inTheme = (Array.isArray(themeList) ? themeList : themeList?.theme ?? []).find((x) => kebab(x.slug) === p.slug);
    const served = [...(byOrigin.custom ?? []), ...(byOrigin.theme ?? [])].find((x) => kebab(x.slug) === p.slug);
    if (inTheme && served && JSON.stringify(inTheme[field]) !== JSON.stringify(served[field])) p.themeValue = inTheme[field];
    if (byOrigin.custom?.some((x) => kebab(x.slug) === p.slug) && byOrigin.theme?.some((x) => kebab(x.slug) === p.slug)) p.overridden = true;
  }
  const layout = site.settings?.layout ?? {};
  for (const [k, slug] of [['contentSize', 'contentSize'], ['wideSize', 'wideSize']]) {
    if (!layout[k]) continue;
    const themeV = site.theme_settings?.layout?.[k];
    presets.push({ cssFamily: 'layout', family: 'width', slug, label: k === 'contentSize' ? 'largeur du contenu' : 'largeur large', value: String(layout[k]), origin: 'layout', themeValue: themeV && themeV !== layout[k] ? themeV : undefined });
  }
  return { presets, vars, blockScoped, missing };
}

/* ---------- comparison ---------- */

function presetCandidate(p, ctx) {
  if (p.ready) return p;
  p.ready = true;
  try {
    if (p.family === 'color') {
      p.lab = oklab(substituteVars(p.value, ctx.vars));
      if (!p.lab) p.error = `couleur non évaluable (${p.value})`;
    } else if (p.family === 'font-family') p.stack = substituteVars(p.value, ctx.vars);
    else if (p.family === 'shadow') {
      p.resolved = substituteVars(p.value, ctx.vars);
      p.geo = shadowGeometry(p.resolved);
    } else p.range = lengthRange(p.value, ctx);
  } catch (e) {
    p.error = e instanceof Unresolved ? `var(${e.message}) non définie : passer la feuille qui la définit par --css` : e.message;
  }
  return p;
}

const fmt = (n) => `${Math.round(n * 100) / 100}px`;
const rangeText = (r) => (r.fluid ? `${fmt(r.lo)} à ${fmt(r.hi)}` : fmt(r.lo));

function compareToken(tok, presets, ctx) {
  const cands = presets.filter((p) => p.family === tok.family).map((p) => presetCandidate(p, ctx)).filter((p) => !p.error);
  if (!cands.length) return { verdict: 'sans ancrage', why: `aucun preset servi de la famille ${FAMILY_LABEL[tok.family]}` };
  if (tok.family === 'color') {
    const lab = oklab(tok.value);
    if (!lab) return { verdict: 'non évaluable', why: `couleur illisible ${tok.value}` };
    const ranked = cands.map((p) => ({ p, d: colorDistance(lab, p.lab) })).sort((a, b) => a.d - b.d);
    const exact = ranked.filter((r) => r.d < 0.0005 && r.p.lab.hex === lab.hex);
    if (exact.length) return { verdict: 'exact', matches: exact.map((r) => r.p), detail: lab.alpha < 1 ? `alpha ${lab.alpha}` : '' };
    const best = ranked[0];
    const detail = `ΔE OKLab ${best.d.toFixed(3)} (${best.p.lab.hex})`;
    return best.d <= ctx.tolColor ? { verdict: 'proche', matches: [best.p], detail } : { verdict: 'sans ancrage', nearest: best.p, detail };
  }
  if (tok.family === 'font-family') {
    const first = firstFamily(tok.value);
    const exact = cands.filter((p) => firstFamily(p.stack) === first);
    if (exact.length) return { verdict: 'exact', matches: exact, detail: `famille « ${first} »` };
    const inStack = cands.filter((p) => familyList(tok.value).includes(firstFamily(p.stack)));
    if (inStack.length) return { verdict: 'proche', matches: inStack, detail: 'famille présente plus loin dans la pile du kit' };
    return { verdict: 'sans ancrage', why: `famille « ${first} » servie par aucun preset` };
  }
  if (tok.family === 'shadow') {
    const norm = (s) => String(s).replace(/\s+/g, ' ').replace(/\s*([(),])\s*/g, '$1').trim().toLowerCase();
    const exact = cands.filter((p) => norm(p.resolved) === norm(tok.value));
    if (exact.length) return { verdict: 'exact', matches: exact };
    const geo = shadowGeometry(tok.value);
    const ranked = cands.map((p) => ({ p, d: geometryGap(geo, p.geo) })).sort((a, b) => a.d - b.d);
    if (ranked[0].d < 0.5) return { verdict: 'proche', matches: [ranked[0].p], detail: 'même géométrie, couleur différente' };
    return { verdict: 'sans ancrage', nearest: ranked[0].p, detail: `géométrie à ${fmt(ranked[0].d)} (somme des écarts)` };
  }
  // lengths
  let kr;
  try {
    kr = lengthRange(tok.value, ctx);
  } catch (e) {
    return { verdict: 'non évaluable', why: e instanceof Unresolved ? `var(${e.message}) non définie dans le kit` : e.message };
  }
  const ranked = cands
    .map((p) => {
      const r = p.range;
      const d = Math.max(0, kr.lo - r.hi, r.lo - kr.hi);
      return { p, r, d, tie: Math.abs(kr.hi - r.hi) };
    })
    .sort((a, b) => a.d - b.d || a.tie - b.tie);
  const best = ranked[0];
  const v = kr.hi;
  const eq = (a, b) => Math.abs(a - b) <= 0.5;
  if (!kr.fluid) {
    const exact = ranked.filter((x) => !x.r.fluid && eq(x.r.lo, v));
    if (exact.length) return { verdict: 'exact', matches: exact.map((x) => x.p), detail: fmt(v) };
    const bound = ranked.filter((x) => x.r.fluid && (eq(x.r.lo, v) || eq(x.r.hi, v)));
    if (bound.length) {
      const which = (x) => (eq(x.r.hi, v) ? `maximum de ${x.p.slug} dès ${x.r.wHi}px` : `minimum de ${x.p.slug} jusqu'à ${x.r.wLoEnd}px`) + ` (plage ${rangeText(x.r)})`;
      return { verdict: 'borne', matches: bound.map((b) => b.p), detail: `${fmt(v)} = ${bound.map(which).join(' ; ')}` };
    }
    if (best.d === 0) return { verdict: 'plage', matches: [best.p], detail: `${fmt(v)} dans ${rangeText(best.r)}, atteint vers ${widthReaching(best.p.value, ctx, v)}px` };
  } else if (best.d === 0) {
    return { verdict: 'plage', matches: [best.p], detail: `kit ${rangeText(kr)} recoupe ${rangeText(best.r)}` };
  }
  const rel = best.d / Math.max(Math.abs(v), 1);
  const detail = `écart ${fmt(best.d)} (${(rel * 100).toFixed(1)} %), preset ${rangeText(best.r)}`;
  return rel <= ctx.tol ? { verdict: 'proche', matches: [best.p], detail } : { verdict: 'sans ancrage', nearest: best.p, detail };
}

export function anchor(kitTokens, site, opts = {}) {
  const { presets, vars, blockScoped, missing } = site;
  const settings = opts.settings ?? {};
  const fluid = settings.typography?.fluid;
  const wide = parseFloat(settings.layout?.wideSize);
  const ctx = {
    vars,
    rem: opts.rem ?? 16,
    minW: Math.round(opts.minW ?? parseFloat(fluid?.minViewportWidth) ?? 320) || 320,
    maxW: Math.round(opts.maxW ?? (parseFloat(fluid?.maxViewportWidth) || (/px$/.test(settings.layout?.wideSize ?? '') ? wide : 1600))),
    tol: opts.tol ?? 0.05,
    tolColor: opts.tolColor ?? 0.01,
  };
  if (!(ctx.maxW > ctx.minW)) throw new Error(`plage de largeurs vide (${ctx.minW} à ${ctx.maxW})`);
  const rows = kitTokens.map((tok) => ({ tok, ...(tok.family ? compareToken(tok, presets, ctx) : { verdict: 'hors familles' }) }));
  const used = new Set(rows.flatMap((r) => (r.verdict !== 'sans ancrage' ? r.matches ?? [] : [])).map((p) => `${p.cssFamily}/${p.slug}`));
  for (const p of presets) presetCandidate(p, ctx);
  return {
    ctx,
    rows,
    unusedPresets: presets.filter((p) => !used.has(`${p.cssFamily}/${p.slug}`)),
    dbOverrides: presets.filter((p) => p.themeValue !== undefined || p.overridden),
    blockScoped,
    presetErrors: presets.filter((p) => p.error),
    missing,
    presets,
  };
}

/* ---------- report ---------- */

const pName = (p) => `${p.cssFamily === 'layout' ? 'layout' : p.cssFamily}/${p.slug}${p.label && p.label !== p.slug ? ` « ${p.label} »` : ''}${p.origin === 'custom' ? ' [réglé en admin]' : ''}`;
const pValue = (p) => (p.range ? rangeText(p.range) : p.lab ? p.lab.hex : p.stack ?? p.resolved ?? p.value);

function textReport(res, meta, kitFile) {
  const out = [];
  const { ctx, rows } = res;
  const count = (v) => rows.filter((r) => r.verdict === v).length;
  out.push(`Kit ${kitFile} contre ${meta.theme ?? 'thème ?'} (WordPress ${meta.wp_version ?? '?'}, réglages de la base du ${meta.user_styles_modified ?? '?'})`);
  out.push(`Plage évaluée ${ctx.minW} à ${ctx.maxW}px, 1rem = ${ctx.rem}px, conteneur pris égal à la fenêtre ; tolérances ${(ctx.tol * 100).toFixed(0)} % (longueurs), ΔE ${ctx.tolColor} (couleurs)`);
  out.push(`${rows.length} jetons : ${['exact', 'borne', 'plage', 'proche'].map((v) => `${count(v)} ${v}`).join(', ')}, ${count('sans ancrage')} sans ancrage, ${count('hors familles')} hors familles de preset, ${count('non évaluable')} non évaluable(s)`);
  const sections = [
    ['Jetons ancrés', (r) => ['exact', 'borne', 'plage', 'proche'].includes(r.verdict)],
    ['Jetons sans ancrage', (r) => r.verdict === 'sans ancrage'],
    ['Jetons non évaluables', (r) => r.verdict === 'non évaluable'],
  ];
  for (const [title, keep] of sections) {
    const list = rows.filter(keep);
    out.push('', `== ${title} (${list.length})`);
    for (const r of list) {
      const head = `${FAMILY_LABEL[r.tok.family] ?? '?'}  ${r.tok.name}  ${r.tok.value}`;
      if (r.matches) out.push(`${head}  →  ${r.verdict} : ${r.matches.map(pName).join(', ')}${r.detail ? `  (${r.detail})` : ''}`);
      else if (r.nearest) out.push(`${head}  →  le plus proche : ${pName(r.nearest)} = ${pValue(r.nearest)}${r.detail ? `, ${r.detail}` : ''}`);
      else out.push(`${head}  →  ${r.why ?? ''}`);
    }
  }
  const outside = rows.filter((r) => r.verdict === 'hors familles');
  out.push('', `== Hors familles de preset (${outside.length}) : aucun réglage global de WordPress ne les porte`);
  if (outside.length) out.push(outside.map((r) => r.tok.name).join(', '));
  out.push('', `== Presets du site sans jeton (${res.unusedPresets.length})`);
  for (const p of res.unusedPresets) out.push(`${pName(p)} = ${p.error ? `non évaluable : ${p.error}` : pValue(p)}`);
  out.push('', `== Valeur servie différente de la couche du thème (${res.dbOverrides.length}) : la base fait foi`);
  for (const p of res.dbOverrides) out.push(`${pName(p)} : thème ${JSON.stringify(p.themeValue ?? '(redéclaré en admin)')}, servi ${p.value}`);
  if (res.blockScoped.size) {
    out.push('', `== Presets redéfinis pour un bloc (${res.blockScoped.size} sélecteurs), non comparés`);
    for (const [sel, set] of res.blockScoped) out.push(`${sel} : ${[...set].join(', ')}`);
  }
  if (res.presetErrors.length) {
    out.push('', `== Presets non évaluables (${res.presetErrors.length})`);
    for (const p of res.presetErrors) out.push(`${pName(p)} : ${p.error}`);
  }
  if (res.missing.length) out.push('', `Feuilles listées par site.json introuvables ici : ${res.missing.join(', ')}`);
  return out.join('\n');
}

function jsonReport(res, meta) {
  const p = (x) => ({ family: x.cssFamily, slug: x.slug, name: x.label, origin: x.origin, value: x.value, evaluated: x.range ?? x.lab?.hex ?? x.stack ?? x.resolved, error: x.error });
  return {
    meta,
    range: { minWidth: res.ctx.minW, maxWidth: res.ctx.maxW, rem: res.ctx.rem, tol: res.ctx.tol, tolColor: res.ctx.tolColor },
    tokens: res.rows.map((r) => ({ name: r.tok.name, family: r.tok.family, value: r.tok.value, verdict: r.verdict, matches: r.matches?.map(p), nearest: r.nearest ? p(r.nearest) : undefined, detail: r.detail ?? r.why })),
    presetsWithoutToken: res.unusedPresets.map(p),
    servedDiffersFromTheme: res.dbOverrides.map((x) => ({ ...p(x), theme: x.themeValue })),
    blockScoped: Object.fromEntries([...res.blockScoped].map(([k, v]) => [k, [...v]])),
  };
}

/* ---------- selftest ---------- */

function selftest() {
  calibrate();
  const site = {
    meta: { theme: 'selftest' },
    settings: {
      layout: { contentSize: '800px', wideSize: '1200px' },
      color: { palette: { theme: [{ slug: 'base', name: 'Base', color: '#FFFFFF' }, { slug: 'ink', name: 'Ink', color: '#111111' }], custom: [{ slug: 'ink', name: 'Ink', color: '#101010' }] } },
      spacing: { spacingSizes: { theme: [{ slug: 's1', name: 'S1', size: 'var(--x-s1)' }, { slug: 's2', name: 'S2', size: '24px' }] } },
      typography: { fontSizes: { theme: [{ slug: 'body', name: 'Body', size: '7px' }] }, fontFamilies: { theme: [{ slug: 'sans', name: 'Sans', fontFamily: '"Alpha Sans", sans-serif' }] } },
    },
    theme_settings: { color: { palette: [{ slug: 'base', color: '#FFFFFF' }, { slug: 'ink', color: '#111111' }] }, layout: { contentSize: '760px', wideSize: '1200px' } },
    variables_css: ':root{--wp--preset--color--base: #FFFFFF;--wp--preset--color--ink: #101010;--wp--preset--font-size--body: clamp(6px, 0.375rem + ((1vw - 3.2px) * 0.098), 7px);--wp--preset--font-family--sans: "Alpha Sans", sans-serif;--wp--preset--spacing--s1: var(--x-s1);--wp--preset--spacing--s2: 24px;--wp--preset--spacing--s3: var(--x-undefined);}.wp-block-x{--wp--preset--color--base: #000}',
    inline_css: '.other{--x-s1: 999px}:root{--x-s1: clamp(16px, 2vw, 32px)}',
    stylesheets: [],
  };
  const kit = ':root{--bg:#ffffff;--absent:#123456;--ink-like:#111111;--gap-a:24px;--gap-b:20px;--gap-c:24.8px;--gap-d:400px;--font-sans:"Alpha Sans", system-ui;--text-body:400 7px/1.5 var(--font-sans);--z-modal:400;--width-content:800px}';
  const res = anchor(readKit(kit), readSite(site), { settings: site.settings });
  const get = (n) => res.rows.find((r) => r.tok.name === n);
  const checks = [
    ['blanc exact sur base', get('--bg').verdict === 'exact' && get('--bg').matches[0].slug === 'base'],
    ['couleur absente sans ancrage', get('--absent').verdict === 'sans ancrage'],
    ['quasi-noir proche du preset réglé en base', get('--ink-like').verdict === 'proche' && get('--ink-like').matches[0].origin === 'custom'],
    ['24px exact sur s2', get('--gap-a').verdict === 'exact' && get('--gap-a').matches[0].slug === 's2'],
    ['20px dans la plage var() de s1', get('--gap-b').verdict === 'plage' && get('--gap-b').matches[0].slug === 's1'],
    ['24.8px proche (3,2 %)', get('--gap-c').verdict === 'proche'],
    ['400px sans ancrage', get('--gap-d').verdict === 'sans ancrage'],
    ['police exacte', get('--font-sans').verdict === 'exact'],
    ['7px = maximum du clamp fluide', get('--text-body').verdict === 'borne' && /maximum/.test(get('--text-body').detail)],
    ['z-index hors familles', get('--z-modal').verdict === 'hors familles'],
    ['largeur du contenu exacte', get('--width-content').verdict === 'exact' && get('--width-content').matches[0].slug === 'contentSize'],
    ['var() non définie signalée', res.presetErrors.some((p) => p.slug === 's3' && /--x-undefined/.test(p.error))],
    ['contenu écrasé en base signalé', res.dbOverrides.some((p) => p.slug === 'contentSize')],
    ['preset redéfini par bloc signalé', res.blockScoped.has('.wp-block-x')],
    ['clamp(10px, 1vw, 20px) borné : 10px à 320px, 20px à 2400px', ((c) => evalLength('clamp(10px, 1vw, 20px)', c, 320).n === 10 && evalLength('clamp(10px, 1vw, 20px)', c, 2400).n === 20)({ rem: 16, vars: new Map() })],
    ['clamp à 320px = 6px',Math.abs(evalLength('clamp(6px, 0.375rem + ((1vw - 3.2px) * 0.098), 7px)', { rem: 16, vars: new Map() }, 320).n - 6) < 1e-9],
  ];
  let failed = 0;
  for (const [label, ok] of checks) {
    if (!ok) failed++;
    console.log(`${ok ? 'ok   ' : 'ÉCHEC'} ${label}`);
  }
  console.log(failed ? `${failed} échec(s) d'étalonnage` : `étalonnage tenu (${checks.length} cas)`);
  return failed ? 1 : 0;
}

/* ---------- main ---------- */

function main(argv) {
  exitOnHelp(argv, import.meta.url);
  if (argv.includes('--selftest')) process.exit(selftest());
  const take = (n) => {
    const i = argv.indexOf(n);
    return i >= 0 ? argv.splice(i, 2)[1] : undefined;
  };
  const css = [];
  for (let c = take('--css'); c !== undefined; c = take('--css')) css.push(c);
  const num = (n) => {
    const v = take(n);
    return v === undefined ? undefined : Number(v);
  };
  const opts = { minW: num('--min-width'), maxW: num('--max-width'), rem: num('--rem'), tol: num('--tol'), tolColor: num('--tol-color') };
  const jsonIdx = argv.indexOf('--json');
  const asJson = jsonIdx >= 0 && argv.splice(jsonIdx, 1).length > 0;
  const [kitFile, siteFile] = argv;
  if (!kitFile || !siteFile) {
    console.error('usage : node wp-anchor.mjs <tokens.json | tokens.css> <site.json> [--css f] [--min-width px] [--max-width px] [--rem px] [--tol r] [--tol-color d] [--json] | --selftest');
    process.exit(2);
  }
  try {
    calibrate();
    const site = JSON.parse(fs.readFileSync(siteFile, 'utf8'));
    if (!site.settings || site.variables_css === undefined) throw new Error(`${siteFile} n'est pas une sortie de wp-settings.php (settings et variables_css attendus)`);
    const res = anchor(readKit(fs.readFileSync(kitFile, 'utf8'), kitFile), readSite(site, css), { ...opts, settings: site.settings });
    if (asJson) console.log(JSON.stringify(jsonReport(res, site.meta ?? {}), null, 2));
    else console.log(textReport(res, site.meta ?? {}, kitFile));
  } catch (e) {
    console.error(`ERREUR : ${e.message}`);
    process.exit(1);
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main(process.argv.slice(2));
