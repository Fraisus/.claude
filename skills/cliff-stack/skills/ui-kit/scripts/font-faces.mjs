#!/usr/bin/env node
/**
 * font-faces.mjs: list (and optionally download) the faces of one subset of a Google Fonts
 * css2 stylesheet, and print the matching tokens.json "type.fonts" entries.
 * ESM, zero dependency, Node 18+.
 *
 * Get the stylesheet first, with a browser user agent (without one Google serves ttf, not woff2):
 *   curl -sL -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36" \
 *     "https://fonts.googleapis.com/css2?family=<Family>:ital,wght@0,100..900;1,100..900&display=swap" -o <family>.css
 *
 * Usage:
 *   node font-faces.mjs <stylesheet.css | -> [--subset latin] [--download <dir>] [--json]
 *
 *   --subset     subset label of the comment that PRECEDES each @font-face (default "latin";
 *                "latin-ext" is a different face). Exact match on the label.
 *   --download   fetch each listed file into <dir> (only https://fonts.gstatic.com/ urls),
 *                named <family>-<style>[-<weight>].woff2; existing files are kept. A face
 *                refused (other host) or not fetched is left out of the output, exit code 1
 *   --json       print the type.fonts entries as JSON (file paths under fonts/)
 *   --help, -h   print this help
 *
 * Default output, one line per face: family|style|weight|url
 */
import fs from 'node:fs';
import path from 'node:path';
import { exitOnHelp } from './cli.mjs';

const argv = process.argv.slice(2);
exitOnHelp(argv, import.meta.url);
const option = (n, d) => {
  const i = argv.indexOf(n);
  return i >= 0 ? argv.splice(i, 2)[1] : d;
};
const jsonIdx = argv.indexOf('--json');
const asJson = jsonIdx >= 0 && argv.splice(jsonIdx, 1);
const subset = option('--subset', 'latin');
const dir = option('--download');
const [src] = argv;
if (!src) {
  console.error('usage : node font-faces.mjs <stylesheet.css | -> [--subset latin] [--download dir] [--json]');
  process.exit(2);
}

const css = fs.readFileSync(src === '-' ? 0 : src, 'utf8');
// The subset label sits in the comment BEFORE its @font-face: splitting on "@font-face"
// and testing each chunk for the label picks the previous face (latin-ext for latin).
const faces = [];
for (const m of css.matchAll(/\/\*\s*([\w-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/g)) {
  if (m[1] !== subset) continue;
  const body = m[2];
  const pick = (re) => (body.match(re) || [])[1]?.trim();
  faces.push({
    family: pick(/font-family:\s*['"]?([^;'"]+)['"]?\s*;/),
    style: pick(/font-style:\s*([^;]+);/) ?? 'normal',
    weight: pick(/font-weight:\s*([^;]+);/) ?? '400',
    url: pick(/url\(([^)]+)\)/)?.replace(/['"]/g, ''),
  });
}
if (!faces.length) {
  const labels = [...css.matchAll(/\/\*\s*([\w-]+)\s*\*\//g)].map((m) => m[1]);
  console.error(`aucune face « ${subset} » ; libellés présents : ${[...new Set(labels)].join(', ') || 'aucun (feuille vide ou pas une feuille css2 ?)'}`);
  process.exit(1);
}

const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const fileName = (f) => {
  const variable = /\s/.test(f.weight);
  const sameStyle = faces.filter((x) => x.family === f.family && x.style === f.style).length;
  return `${slug(f.family)}-${f.style}${variable || sameStyle === 1 ? '' : `-${slug(f.weight)}`}.${(f.url.split('.').pop() || 'woff2').toLowerCase()}`;
};

// File names are computed on the full subset, before any face is dropped.
const names = new Map(faces.map((f) => [f, fileName(f)]));
let listed = faces;
if (dir) {
  fs.mkdirSync(dir, { recursive: true });
  listed = [];
  for (const f of faces) {
    if (!f.url.startsWith('https://fonts.gstatic.com/')) {
      console.error(`refusé, hôte non autorisé : ${f.url}`);
      process.exitCode = 1;
      continue;
    }
    const target = path.join(dir, names.get(f));
    if (!fs.existsSync(target)) {
      const res = await fetch(f.url);
      if (!res.ok) {
        console.error(`échec ${res.status} : ${f.url}`);
        process.exitCode = 1;
        continue;
      }
      fs.writeFileSync(target, Buffer.from(await res.arrayBuffer()));
      console.error(`téléchargé ${target}`);
    }
    listed.push(f);
  }
}

if (asJson) {
  console.log(JSON.stringify(listed.map((f) => ({ family: f.family, file: `fonts/${names.get(f)}`, weight: f.weight, style: f.style })), null, 2));
} else {
  for (const f of listed) console.log([f.family, f.style, f.weight, f.url].join('|'));
}
