// regex.mjs - .NET regex semantics for hooks ported from PowerShell.
// In .NET, \w and \b see accented letters and -match ignores case; in JavaScript \w and \b are ASCII only,
// so a French message such as « a échoué » would not match without these classes.

// Word character as .NET sees it.
export const W = String.raw`\p{L}\p{Mn}\p{Nd}\p{Pc}`;
// Word boundary as .NET sees it.
export const B = `(?:(?<=[${W}])(?![${W}])|(?<![${W}])(?=[${W}]))`;
// Start of a line without the m flag.
export const BOL = String.raw`(?:^|(?<=\n))`;
// Case-insensitive Unicode regex, like PowerShell -match; extra flags (g, s, m) are added to i and u.
export const rx = (src, extra = '') => new RegExp(src, `iu${extra}`);
