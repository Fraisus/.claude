/**
 * tokens-shape.mjs: shape errors of a Design System tokens.json that the page would read as
 * empty or wrong, shared by check-tokens.mjs and compile-tokens.mjs. ESM, zero dependency.
 *
 *   shapeErrors(t) -> string[]
 *     - every top-level key except "type" and the metadata keys (META_KEYS) is a family and
 *       carries a "tokens" list {tokens:[{name,value,usage}]}: a name-to-value map (DTCG,
 *       nested groups, flat) is read as empty by the page and vanishes from the css
 *     - every theme of color.themes has an id, a string matching ^[a-z0-9-]+$: ids are read
 *       lowercased and values are keyed by the id as written, so a missing, null or other id
 *       loses its values (the theme measured with the first one's, [data-theme="undefined"])
 *     - every key of a themed value (THEMED families, value as an object) is a declared theme
 *       id: any other key ("Dark" for "dark", a theme that does not exist) is never read, the
 *       first theme's value is measured and compiled in its place
 */
export const THEME_ID = /^[a-z0-9-]+$/;
export const META_KEYS = new Set(['meta', 'name', 'version', '$schema', 'description']);
export const THEMED = ['color', 'shadow'];

const isObject = (v) => Boolean(v) && typeof v === 'object' && !Array.isArray(v);
const quote = (list) => list.map((x) => `« ${x} »`).join(', ');

export function shapeErrors(t) {
  if (!isObject(t)) return ['tokens.json doit être un objet {color, type, <famille>: {tokens: [...]}}'];
  const errors = [];
  for (const [k, v] of Object.entries(t)) {
    if (k === 'type' || META_KEYS.has(k)) continue;
    if (isObject(v) && 'tokens' in v) {
      if (!Array.isArray(v.tokens)) errors.push(`famille « ${k} » : tokens doit être une liste`);
    } else {
      errors.push(`famille « ${k} » sans liste tokens : la page ne lit que {tokens:[{name,value,usage}]}, une carte nom → valeur (DTCG, groupes imbriqués ou plate) disparaît du css ; clés de métadonnées admises hors famille : ${quote([...META_KEYS])}`);
    }
  }
  const themes = Array.isArray(t.color?.themes) ? t.color.themes : [];
  const declared = new Set();
  themes.forEach((th, i) => {
    const id = th?.id;
    if (typeof id === 'string') declared.add(id);
    if (typeof id !== 'string' || !THEME_ID.test(id)) {
      const shown = id === undefined ? 'absent' : `« ${typeof id === 'string' ? id : JSON.stringify(id)} »`;
      errors.push(`thème ${i + 1}${th?.name ? ` (${th.name})` : ''} : identifiant ${shown}, obligatoire, chaîne de minuscules, chiffres et tirets ([a-z0-9-]), clés des valeurs comprises`);
    }
  });
  for (const fam of THEMED) {
    const list = Array.isArray(t[fam]?.tokens) ? t[fam].tokens : [];
    for (const tok of list) {
      const v = tok?.value;
      if (!v || typeof v !== 'object') continue;
      const stray = Object.keys(v).filter((key) => !declared.has(key));
      if (stray.length) errors.push(`jeton « ${tok.name} » (${fam}) : clé${stray.length > 1 ? 's' : ''} ${quote(stray)} hors des identifiants de thème déclarés (${quote([...declared]) || 'aucun'}) : valeur jamais lue, celle du premier thème prise à sa place`);
    }
  }
  return errors;
}
