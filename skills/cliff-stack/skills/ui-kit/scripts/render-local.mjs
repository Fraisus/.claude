#!/usr/bin/env node
/**
 * render-local.mjs: turn the .dc.html artboards of a Claude Design canvas into plain HTML
 * pages that open locally (file:// or any static server), so a layout can be checked
 * (DOM, computed styles, widths) outside the canvas, which forbids verifying in progress.
 * ESM, zero dependency, Node 18+.
 *
 * Usage:
 *   node render-local.mjs <canvas-dir> <out-dir> [--set key=value ...] [--blobs map.json]
 *                         [--assets <dir>] [--eager] [--only <name.dc.html>]
 *
 *   <canvas-dir>  folder holding the *.dc.html files (and canvas.json, ds/, assets/ ...);
 *                 copied whole into <out-dir>, which must not sit inside it
 *   --set         value for a {{key}} placeholder (repeatable); default: the "default" of
 *                 that key in the board's data-props, else left as is and reported
 *   --blobs       JSON {"/_blob/<id>": "<relative path>"}: uploads the canvas serves by id,
 *                 replaced with local files (unreplaced /_blob/ references are reported)
 *   --assets      folder copied into <out-dir>/assets
 *   --eager       loading="lazy" becomes loading="eager": lazy images stay blank in a probe
 *                 that loads the page in a hidden or off-screen iframe
 *   --only        render a single board
 *   --help, -h    print this help
 *
 * Per board: removes the runtime script (support.js), the <x-dc> and <helmet> wrappers
 * (their content stays), the data-dc-script block; substitutes {{key}}; writes <name>.html.
 * Reports, without failing: boards taller than 8000 px in canvas.json (the canvas clips an
 * artboard past that height), non-breaking spaces inside a tag (they break SVG attribute
 * values and selectors: apply them to text only), unresolved placeholders and blobs.
 */
import fs from 'node:fs';
import path from 'node:path';
import { exitOnHelp } from './cli.mjs';

const MAX_BOARD_HEIGHT = 8000;
const argv = process.argv.slice(2);
exitOnHelp(argv, import.meta.url);
const sets = {};
let blobsFile;
let assetsDir;
let eager = false;
let only;
const positional = [];
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a === '--set') {
    const [k, ...v] = argv[++i].split('=');
    sets[k] = v.join('=');
  } else if (a === '--blobs') blobsFile = argv[++i];
  else if (a === '--assets') assetsDir = argv[++i];
  else if (a === '--eager') eager = true;
  else if (a === '--only') only = argv[++i];
  else positional.push(a);
}
const [src, out] = positional;
if (!src || !out) {
  console.error('usage : node render-local.mjs <canvas-dir> <out-dir> [--set k=v] [--blobs map.json] [--assets dir] [--eager] [--only board.dc.html]');
  process.exit(2);
}
const absSrc = path.resolve(src);
const absOut = path.resolve(out);
if (absOut === absSrc || absOut.startsWith(absSrc + path.sep)) {
  console.error('le dossier de sortie ne doit pas être dans le dossier du canevas');
  process.exit(2);
}

const report = [];
fs.mkdirSync(absOut, { recursive: true });
fs.cpSync(absSrc, absOut, { recursive: true });
if (assetsDir) fs.cpSync(path.resolve(assetsDir), path.join(absOut, 'assets'), { recursive: true });
const blobs = blobsFile ? JSON.parse(fs.readFileSync(blobsFile, 'utf8')) : {};

const canvasJson = path.join(absSrc, 'canvas.json');
if (fs.existsSync(canvasJson)) {
  const c = JSON.parse(fs.readFileSync(canvasJson, 'utf8'));
  for (const [name, b] of Object.entries(c.boards ?? {})) {
    if (b.h > MAX_BOARD_HEIGHT) report.push(`${name} : h ${b.h} px dans canvas.json, le canevas coupe au-delà de ${MAX_BOARD_HEIGHT} px`);
  }
}

const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const boards = fs.readdirSync(absSrc).filter((f) => f.endsWith('.dc.html') && (!only || f === only));
if (!boards.length) {
  console.error(`aucun artboard .dc.html dans ${absSrc}${only ? ` pour ${only}` : ''}`);
  process.exit(1);
}

for (const f of boards) {
  let h = fs.readFileSync(path.join(absSrc, f), 'utf8');
  const defaults = {};
  const dc = /<script[^>]*data-dc-script[^>]*>[\s\S]*?<\/script>/.exec(h);
  if (dc) {
    const props = /data-props=(['"])([\s\S]*?)\1/.exec(dc[0]);
    if (props) {
      try {
        for (const [k, v] of Object.entries(JSON.parse(decode(props[2])))) if (v && typeof v === 'object' && 'default' in v) defaults[k] = v.default;
      } catch {
        report.push(`${f} : data-props illisible, valeurs par défaut ignorées`);
      }
    }
    h = h.replace(dc[0], '');
  }
  h = h.replace(/<script[^>]*src=["'][^"']*support\.js["'][^>]*>\s*<\/script>/g, '');
  h = h.replace(/<\/?x-dc[^>]*>/g, '').replace(/<\/?helmet[^>]*>/g, '');
  h = h.replace(/\{\{\s*([\w$.-]+)\s*\}\}/g, (m, k) => {
    if (k in sets) return sets[k];
    if (k in defaults) return String(defaults[k]);
    return m;
  });
  for (const [b, local] of Object.entries(blobs)) h = h.split(b).join(local);
  if (eager) h = h.replace(/loading=(["'])lazy\1/g, 'loading="eager"');

  const left = [...new Set([...h.matchAll(/\{\{\s*([\w$.-]+)\s*\}\}/g)].map((m) => m[1]))];
  if (left.length) report.push(`${f} : placeholders sans valeur ${left.join(', ')}`);
  const blobRefs = [...new Set(h.match(/\/_blob\/[0-9a-f]+/g) ?? [])];
  if (blobRefs.length) report.push(`${f} : ${blobRefs.length} /_blob/ sans fichier local (--blobs)`);
  const nbspInTags = (h.match(/<[^>]*(?: |&nbsp;|&#160;|&#xa0;)[^>]*>/gi) ?? []).length;
  if (nbspInTags) report.push(`${f} : ${nbspInTags} balise(s) avec espace insécable dans un attribut`);
  if (!eager && /loading=["']lazy["']/.test(h)) report.push(`${f} : images en loading="lazy" (sonde en iframe cachée : --eager)`);

  const target = path.join(absOut, f.replace(/\.dc\.html$/, '.html'));
  fs.writeFileSync(target, h);
  console.log(`écrit ${target}`);
}
for (const r of report) console.log(`signalé : ${r}`);
