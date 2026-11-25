// regex.mjs - .NET regex semantics for hooks ported from PowerShell.
// In .NET, \w, \d and \b see Unicode letters and digits, \s also matches U+0085, "." only refuses \n, and -match ignores
// case; in JavaScript \w, \d and \b are ASCII, \s misses U+0085 and "." also refuses \r, U+2028 and U+2029. A guard that
// kept the JavaScript meaning would let through a command its PowerShell version refused.

// Word character as .NET sees it.
export const W = String.raw`\p{L}\p{Mn}\p{Nd}\p{Pc}`;
// Word boundary as .NET sees it.
export const B = `(?:(?<=[${W}])(?![${W}])|(?<![${W}])(?=[${W}]))`;
// Start of a line without the m flag.
export const BOL = String.raw`(?:^|(?<=\n))`;

const OUTSIDE = { b: B, w: `[${W}]`, W: `[^${W}]`, d: String.raw`\p{Nd}`, D: String.raw`\P{Nd}`, s: String.raw`[\s\x85]`, S: String.raw`[^\s\x85]` };
const INSIDE = { w: W, d: String.raw`\p{Nd}`, s: String.raw`\s\x85` };

// Rewrites a .NET-style source into its JavaScript equivalent: escapes above, and "." outside a class as [^\n].
export function toNet(src) {
  let out = '';
  let inClass = false;
  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    if (c === '\\') {
      const n = src[i + 1] ?? '';
      const map = inClass ? INSIDE : OUTSIDE;
      if (map[n] !== undefined) {
        out += map[n];
        i++;
        continue;
      }
      // \p{..}, \P{..}, \u{..}, \k<..>: copy the whole escape, never translate inside it.
      const close = n === 'k' ? '>' : '}';
      if ('pPuk'.includes(n) && (src[i + 2] === '{' || src[i + 2] === '<')) {
        const end = src.indexOf(close, i + 2);
        out += src.slice(i, end + 1);
        i = end;
        continue;
      }
      out += c + n;
      i++;
      continue;
    }
    if (inClass) {
      if (c === ']') inClass = false;
      out += c;
    } else if (c === '[') {
      inClass = true;
      out += c;
      if (src[i + 1] === '^') out += src[++i];
    } else out += c === '.' ? String.raw`[^\n]` : c;
  }
  return out;
}

// Case-sensitive .NET regex, like [regex]::Match; flags are added to u.
export const net = (src, flags = '') => new RegExp(toNet(src), `u${flags}`);
// Case-insensitive .NET regex, like PowerShell -match; extra flags (g, s, m) are added to i and u.
export const rx = (src, extra = '') => net(src, `i${extra}`);
