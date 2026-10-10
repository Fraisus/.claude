# Adaptateur `.claude/ux-e2e.json` et parcours

## Champs

| Champ | Cible | Rôle |
|---|---|---|
| `cible` | toutes | `"cdp"` (appli lancée qui ouvre son port de débogage) ou `"web"` (navigateur lancé par Playwright sur une URL) |
| `commande` | cdp | tableau `[exécutable, ...arguments]`, lancé depuis la racine du projet ; doit écrire `DevTools listening on ws://127.0.0.1:<port>/` sur sa sortie (standard ou d'erreur) et s'arrêter proprement quand son entrée standard se ferme |
| `env` | cdp | variables ajoutées à l'environnement de la commande |
| `attente_ms` | cdp | délai pour voir la ligne du port (60000 par défaut) |
| `url` | web | adresse ouverte dans la première page |
| `serveur` | web | facultatif : `{ "commande": [...], "pret": "<regex sur sa sortie>", "attente_ms": 60000, "env": {} }`, lancé avant et arrêté après |
| `headless` | web | `false` pour voir le navigateur (il prend alors une fenêtre : seulement à la demande de l'utilisateur) |
| `navigateur` | web | chemin d'un exécutable Chromium imposé ; sinon Playwright, puis Edge, puis Chrome déjà installés |
| `contexte` | web | options de `browser.newContext` (taille, langue, schéma de couleurs…) |
| `parcours` | toutes | dossier des parcours, relatif au projet ; un nom de fichier seul y est cherché |
| `sortie` | toutes | dossier des rapports, captures et traces (`.ux-e2e/` par défaut) ; le choisir dans un dossier non versionné du projet |

Arguments ajoutés à la commande pour un seul passage : `node ux.mjs <parcours> -- <args>`.

Sous Windows, `npm`, `npx`, `pnpm`, `yarn` et tout `.cmd` ou `.bat` passent par l'interpréteur de commandes, arguments mis entre guillemets ; un autre exécutable est lancé directement. La commande hérite de l'environnement d'origine (pas du dossier provisoire du passage).

## Exemples

Site local servi par le projet :
```json
{ "cible": "web", "url": "http://localhost:4321/", "serveur": { "commande": ["npm", "run", "dev"], "pret": "Local:\\s+http" }, "parcours": "tests/parcours", "sortie": "tmp/ux" }
```

Appli Electron dont le mode test ouvre le port (option du projet, jamais en usage normal) :
```json
{ "cible": "cdp", "commande": ["node", "outils/lancer_essai_ux.js"], "parcours": "outils/parcours", "sortie": "tmp_essais/ux" }
```
Le lanceur du projet prépare ce que l'essai exige (faux serveur, copie isolée, données d'essai), lance l'appli avec son option de test, redit la ligne `DevTools listening…`, et à la fermeture de son entrée standard arrête tout et efface ce qu'il a créé.

## Parcours

```js
// outils/parcours/message.mjs
export default async function ({ page, etape, pause, expect }) {
  const conv = await page({ contient: (p) => p.getByRole("textbox", { name: "Message" }) }, 40000);
  await etape("accueil refusé par « Plus tard »", async () => {
    const bouton = conv.getByRole("button", { name: "Plus tard" });
    await expect(bouton).toBeVisible({ timeout: 20000 });
    await pause();                 // geste humain, après les gardes de temps de l'écran
    await bouton.click();
    await expect(bouton).toBeHidden();
  });
  await etape("message envoyé", async () => {
    await conv.getByRole("textbox", { name: "Message" }).click();
    await conv.keyboard.type("Bonjour");
    await conv.keyboard.press("Enter");
    await expect(conv.getByRole("main")).toContainText("Bonjour");
  });
}
```

Une vue secondaire (confirmation, panneau) : `const conf = await page({ contient: (p) => p.getByRole("button", { name: "Valider" }) });` puis `await pause(); await conf.getByRole("button", { name: "Valider" }).click();`.

## Rapport

```json
{ "ok": true, "parcours": "...", "cible": "cdp", "navigateur": "chrome",
  "etapes": [{ "nom": "...", "ok": true, "ms": 932 }],
  "captures": ["<sortie>/page-1.png"], "trace": "<sortie>/trace.zip",
  "premier_plan_pris": [], "processus_arretes_de_force": [], "erreur": null, "duree_ms": 6509, "sortie": "..." }
```
`premier_plan_pris` non vide : un processus de l'essai a pris la fenêtre au premier plan de l'utilisateur, le passage échoue. `processus_arretes_de_force` non vide : la commande est partie en laissant des processus, arrêtés par le script ; son lanceur doit les arrêter lui-même.
