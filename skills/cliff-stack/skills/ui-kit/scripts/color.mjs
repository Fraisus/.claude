#!/usr/bin/env node
/**
 * color.mjs: color math for a UI kit. ESM, zero dependency, Node 18+.
 *
 * Library (import from the other scripts):
 *   parseColor(css)            -> { rgb: [r,g,b] 0..255, alpha 0..1 } | null
 *   composite(css, underCss)   -> opaque hex of css painted over underCss
 *   contrast(fg, bg, base)     -> WCAG 2.x ratio; bg composited on base, then fg on bg
 *   hexToOklch(css)            -> { L, C, H }
 *   oklchToHex(L, C, H)        -> { hex, inGamut }
 *   fitGamut(L, C, H)          -> { hex, C } with chroma reduced until in sRGB gamut
 *   calibrate()                -> throws if the math drifts from known values
 *
 * CLI:
 *   node color.mjs contrast <fg> <bg> [base]   ratio, fg/bg composited when they carry alpha
 *   node color.mjs to-oklch <color>            L C H
 *   node color.mjs from-oklch <L> <C> <H>      hex, gamut flag, gamut-fitted hex
 *   node color.mjs blend <fg> <alpha> <bg>     flat hex of fg at alpha over bg
 *   node color.mjs selftest                    calibration on known values, exit 1 on drift
 *   node color.mjs --help | -h                 this help
 *
 * Accepted colors: #rgb #rgba #rrggbb #rrggbbaa, rgb()/rgba(), hsl()/hsla(), oklch(),
 * comma or space syntax, optional "/ alpha". Thresholds compare the raw ratio:
 * 4.499 fails 4.5 (WCAG forbids rounding up).
 */

import { pathToFileURL } from 'node:url';
import { exitOnHelp } from './cli.mjs';

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

export function rgbToHex(rgb) {
  return '#' + rgb.map((v) => Math.round(clamp(v, 0, 255)).toString(16).padStart(2, '0')).join('');
}

function num(token, scaleIfPercent) {
  const s = String(token).trim();
  if (s.endsWith('%')) return (parseFloat(s) / 100) * scaleIfPercent;
  return parseFloat(s);
}

function splitArgs(inner) {
  const [main, alphaPart] = inner.split('/');
  const parts = main.trim().split(/[\s,]+/).filter(Boolean);
  let alpha = 1;
  if (alphaPart !== undefined) alpha = num(alphaPart, 1);
  else if (parts.length === 4) alpha = num(parts.pop(), 1);
  return { parts, alpha };
}

function hslToRgb(h, s, l) {
  const k = (n) => (n + h / 30) % 12;
  const a = s * Math.min(l, 1 - l);
  const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
  return [f(0) * 255, f(8) * 255, f(4) * 255];
}

export function parseColor(css) {
  if (typeof css !== 'string') return null;
  const s = css.trim().toLowerCase();
  let m = /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/.exec(s);
  if (m) {
    let h = m[1];
    if (h.length <= 4) h = h.split('').map((c) => c + c).join('');
    const rgb = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
    const alpha = h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1;
    return { rgb, alpha };
  }
  m = /^(rgba?|hsla?|oklch)\(([^()]*)\)$/.exec(s);
  if (!m) return null;
  const { parts, alpha } = splitArgs(m[2]);
  if (parts.length !== 3 || [...parts, alpha].some((p) => Number.isNaN(num(p, 1)))) return null;
  if (m[1].startsWith('rgb')) return { rgb: parts.map((p) => num(p, 255)), alpha };
  if (m[1].startsWith('hsl')) {
    return { rgb: hslToRgb(parseFloat(parts[0]), num(parts[1], 1) / (parts[1].endsWith('%') ? 1 : 100), num(parts[2], 1) / (parts[2].endsWith('%') ? 1 : 100)), alpha };
  }
  const L = num(parts[0], 1);
  const C = num(parts[1], 0.4);
  const H = parseFloat(parts[2]);
  return { rgb: oklchToRgb(L, C, H), alpha };
}

function must(css) {
  const c = parseColor(css);
  if (!c) throw new Error(`couleur illisible : ${css}`);
  return c;
}

export function composite(css, underCss = '#ffffff') {
  const f = must(css);
  if (f.alpha >= 1) return rgbToHex(f.rgb);
  const b = must(composite(underCss));
  return rgbToHex(f.rgb.map((v, i) => v * f.alpha + b.rgb[i] * (1 - f.alpha)));
}

export function blend(fgCss, alpha, bgCss) {
  const f = must(fgCss);
  return composite(`rgba(${f.rgb.join(',')},${alpha})`, bgCss);
}

const lin = (c) => {
  c /= 255;
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
};

export function luminance(css) {
  const [r, g, b] = must(composite(css)).rgb.map(lin);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(fg, bg, base = '#ffffff') {
  const flatBg = composite(bg, base);
  const flatFg = composite(fg, flatBg);
  const a = luminance(flatFg);
  const b = luminance(flatBg);
  const [hi, lo] = a > b ? [a, b] : [b, a];
  return (hi + 0.05) / (lo + 0.05);
}

export function oklchToRgb(L, C, H) {
  const h = (H * Math.PI) / 180;
  const a = C * Math.cos(h);
  const b = C * Math.sin(h);
  const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
  const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
  const s_ = L - 0.0894841775 * a - 1.291485548 * b;
  const l = l_ ** 3;
  const m = m_ ** 3;
  const s = s_ ** 3;
  const R = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
  const G = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
  const B = -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s;
  const g = (c) => (c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(Math.max(c, 0), 1 / 2.4) - 0.055);
  return [R, G, B].map((c) => g(c) * 255);
}

export function oklchToHex(L, C, H) {
  const rgb = oklchToRgb(L, C, H);
  return { hex: rgbToHex(rgb), inGamut: rgb.every((v) => v >= -0.5 && v <= 255.5) };
}

export function fitGamut(L, C, H) {
  if (oklchToHex(L, C, H).inGamut) return { hex: oklchToHex(L, C, H).hex, C };
  let lo = 0;
  let hi = C;
  for (let i = 0; i < 30; i++) {
    const mid = (lo + hi) / 2;
    if (oklchToHex(L, mid, H).inGamut) lo = mid;
    else hi = mid;
  }
  return { hex: oklchToHex(L, lo, H).hex, C: lo };
}

export function hexToOklch(css) {
  const [r, g, b] = must(composite(css)).rgb.map(lin);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  return { L, C: Math.hypot(A, B), H: ((Math.atan2(B, A) * 180) / Math.PI + 360) % 360 };
}

export function calibrate() {
  const checks = [
    ['#767676 sur blanc', contrast('#767676', '#ffffff').toFixed(2), '4.54'],
    ['#6B7280 sur blanc', contrast('#6B7280', '#ffffff').toFixed(2), '4.83'],
    ['noir sur blanc', contrast('#000', '#fff').toFixed(2), '21.00'],
    ['rgba noir 50 % sur blanc', composite('rgba(0,0,0,0.5)', '#ffffff'), '#808080'],
    ['blanc en OKLCH', hexToOklch('#ffffff').L.toFixed(3), '1.000'],
    ['aller-retour OKLCH de #3366cc', (() => { const o = hexToOklch('#3366cc'); return oklchToHex(o.L, o.C, o.H).hex; })(), '#3366cc'],
    ['oklch() lu', composite('oklch(1 0 0)'), '#ffffff'],
  ];
  const bad = checks.filter(([, got, want]) => got !== want);
  if (bad.length) throw new Error('étalonnage faux : ' + bad.map(([n, g, w]) => `${n} = ${g}, attendu ${w}`).join(' ; '));
  return checks;
}

const isMain = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href;
if (isMain) {
  exitOnHelp(process.argv.slice(2), import.meta.url);
  const [cmd, ...args] = process.argv.slice(2);
  try {
    if (cmd === 'contrast' && args.length >= 2) console.log(contrast(args[0], args[1], args[2]).toFixed(2));
    else if (cmd === 'to-oklch' && args.length === 1) { const o = hexToOklch(args[0]); console.log(`L ${o.L.toFixed(4)} C ${o.C.toFixed(4)} H ${o.H.toFixed(2)}`); }
    else if (cmd === 'from-oklch' && args.length === 3) {
      const [L, C, H] = args.map(Number);
      const o = oklchToHex(L, C, H);
      const f = fitGamut(L, C, H);
      console.log(`${o.hex} ${o.inGamut ? 'dans le gamut' : `hors gamut, ramené à ${f.hex} (C ${f.C.toFixed(4)})`}`);
    } else if (cmd === 'blend' && args.length === 3) console.log(blend(args[0], Number(args[1]), args[2]));
    else if (cmd === 'selftest') { for (const [n, g] of calibrate()) console.log(`ok  ${n} = ${g}`); }
    else { console.error('usage : node color.mjs contrast|to-oklch|from-oklch|blend|selftest ... (voir l\'en-tête du fichier)'); process.exit(2); }
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
}
