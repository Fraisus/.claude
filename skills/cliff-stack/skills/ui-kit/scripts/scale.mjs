#!/usr/bin/env node
/**
 * scale.mjs: a 50 to 950 scale at fixed hue from one anchor color, with contrast ratios.
 * ESM, zero dependency, Node 18+. Calibrates color.mjs before printing anything.
 *
 * Usage:
 *   node scale.mjs <anchor> [options]
 *
 * Options:
 *   --name <prefix>        token prefix in the output (default "scale")
 *   --at <step>            step that receives the anchor exactly (default: the step whose
 *                          target lightness is nearest to the anchor's)
 *   --ladder <s:L,...>     target OKLCH lightness per step (default below); steps are taken
 *                          from this list, so a shorter or longer scale is possible
 *   --chroma <C>           fixed chroma for every step except the anchor (tinted neutrals,
 *                          e.g. 0.005); default: the anchor chroma shaped per step
 *   --hue <H>              hue override (default: the anchor hue)
 *   --on <c1,c2,...>       grounds to measure each step against (default "#ffffff")
 *   --json                 print {"<prefix>-<step>": "<hex>"} instead of the table
 *   --help, -h             print this help
 *
 * Output: step, hex, L, C, ratio on each ground, then the steps that hold 4.5:1 as text on
 * the first ground (light ground: the lightest step that holds, darker ones hold too; dark
 * ground, darker than mid grey by WCAG luminance: the darkest step that holds, lighter ones
 * hold too) and the steps on which that ground holds 4.5:1 as text (label on a filled button). The anchor stays exact, every other step is fitted to
 * the sRGB gamut by reducing chroma, never by shifting hue.
 */
import { exitOnHelp } from './cli.mjs';
import { calibrate, contrast, fitGamut, hexToOklch, composite } from './color.mjs';

const DEFAULT_LADDER = '50:0.975,100:0.94,200:0.88,300:0.80,400:0.71,500:0.62,600:0.55,700:0.48,800:0.41,900:0.34,950:0.26';
// Chroma relative to the anchor: pale and very dark steps carry less chroma.
const CHROMA_SHAPE = { 50: 0.08, 100: 0.18, 200: 0.35, 300: 0.6, 400: 0.85, 500: 1, 600: 1, 700: 0.92, 800: 0.8, 900: 0.65, 950: 0.5 };

function args(argv) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const key = a.slice(2);
      out[key] = key === 'json' ? true : argv[++i];
    } else out._.push(a);
  }
  return out;
}

exitOnHelp(process.argv.slice(2), import.meta.url);
const opt = args(process.argv.slice(2));
if (!opt._[0]) {
  console.error('usage : node scale.mjs <anchor> [--name p] [--at step] [--ladder s:L,...] [--chroma C] [--hue H] [--on c1,c2] [--json]');
  process.exit(2);
}
try {
  calibrate();
} catch (e) {
  console.error(e.message);
  process.exit(1);
}

const anchor = composite(opt._[0]);
const a = hexToOklch(anchor);
const hue = opt.hue !== undefined ? Number(opt.hue) : a.H;
const ladder = (opt.ladder || DEFAULT_LADDER).split(',').map((p) => {
  const [s, L] = p.split(':');
  return { step: s.trim(), L: Number(L) };
});
if (ladder.some((x) => !x.step || Number.isNaN(x.L))) {
  console.error('--ladder illisible, forme attendue 50:0.97,100:0.93,...');
  process.exit(2);
}
const at = opt.at ?? ladder.reduce((best, x) => (Math.abs(x.L - a.L) < Math.abs(best.L - a.L) ? x : best)).step;
if (!ladder.find((x) => x.step === String(at))) {
  console.error(`--at ${at} absent de l'échelle`);
  process.exit(2);
}
const grounds = (opt.on || '#ffffff').split(',').map((c) => composite(c.trim()));
const name = opt.name || 'scale';

const steps = ladder.map(({ step, L }) => {
  if (step === String(at)) return { step, hex: anchor, L: a.L, C: a.C, anchor: true };
  const target = opt.chroma !== undefined ? Number(opt.chroma) : a.C * (CHROMA_SHAPE[step] ?? 0.6);
  const f = fitGamut(L, target, hue);
  return { step, hex: f.hex, L, C: f.C };
});

// Lightness must fall from step to step, or the anchor was forced onto the wrong step.
const warnings = [];
for (let i = 1; i < steps.length; i++) {
  if (steps[i].L >= steps[i - 1].L) warnings.push(`luminosité non décroissante entre ${steps[i - 1].step} et ${steps[i].step} : vérifier --at ou --ladder`);
}

if (opt.json) {
  console.log(JSON.stringify(Object.fromEntries(steps.map((s) => [`${name}-${s.step}`, s.hex])), null, 2));
} else {
  console.log(`ancre ${anchor} : L ${a.L.toFixed(3)} C ${a.C.toFixed(3)} H ${a.H.toFixed(1)}, posée au pas ${at}, teinte ${hue.toFixed(1)}`);
  console.log(['pas', 'hex', 'L', 'C', ...grounds.map((g) => `sur ${g}`)].join('\t'));
  for (const s of steps) {
    console.log([`${name}-${s.step}${s.anchor ? '*' : ''}`, s.hex, s.L.toFixed(3), s.C.toFixed(3), ...grounds.map((g) => contrast(s.hex, g).toFixed(2))].join('\t'));
  }
  const g = grounds[0];
  // Light ground: text is darker, the first holding step from the light end bounds it.
  // Dark ground: text is lighter, the first holding step from the dark end bounds it.
  const darkGround = contrast(g, '#ffffff') > contrast(g, '#000000');
  const gL = hexToOklch(g).L;
  const fromEnd = darkGround ? [...steps].reverse() : steps;
  const bound = fromEnd.find((s) => (darkGround ? s.L > gL : s.L < gL) && contrast(s.hex, g) >= 4.5);
  const fills = steps.filter((s) => contrast(g, s.hex) >= 4.5);
  const where = bound ? `${darkGround ? "jusqu'à" : 'à partir de'} ${name}-${bound.step} (pas plus ${darkGround ? 'clairs' : 'sombres'} compris)` : 'aucun pas';
  console.log(`texte sur ${g} à 4,5:1 : ${where}`);
  console.log(`${g} en texte sur aplat à 4,5:1 : ${fills.length ? fills.map((s) => s.step).join(', ') : 'aucun pas'}`);
  console.log('* ancre exacte, jamais retouchée');
}
for (const w of warnings) console.error(`AVERTISSEMENT ${w}`);
