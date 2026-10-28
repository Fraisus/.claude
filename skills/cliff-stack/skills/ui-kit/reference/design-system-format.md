# Publier en artefact Design System : ce qui fait réussir du premier coup

Le type d'artefact Design System charge ses propres références (`format.md`, `craft.md`, `cover.md`) : elles font foi et se lisent au moment de publier. Ce fichier ne garde que ce qui fait échouer un premier envoi ou rend une famille vide sans erreur.

## Sommaire

- Fichiers et emplacements
- tokens.json
- tokens.css
- Aperçus
- README et fiches
- Couverture
- Avant d'envoyer

## Fichiers et emplacements

- `README.md` (le livre de marque), `tokens.json`, `components/<Comp>/preview.html` et `README.md`, `components/bundle.css`, `fonts/<fichier>`, `assets/<Groupe>/<fichier>`, `components/Cover/preview.html`. Tout autre `*.md` hors `components/` devient une section, titrée par son premier `#` : l'audit et l'architecture y vont.
- `components/<Comp>` : un identifiant JS ASCII (`ProductCard`, pas `carte-produit`).
- Refusés à tout niveau : segments commençant par un point, `node_modules`, `package.json`, fichiers de verrou, `CLAUDE.md`, `AGENTS.md`, et les chemins réservés `index.html`, `design-system.json`, `SKILL.md`. `manifest.json` est généré : ne pas l'écrire.
- Polices dans `fonts/`, jamais dans `assets/` (là elles ne sont que des spécimens) ; chacune listée dans `type.fonts`, sinon pas de `@font-face`.

## tokens.json

- Chaque clé de premier niveau sauf `type` et les métadonnées (`meta`, `name`, `version`, `$schema`, `description`) est une famille, une LISTE `{"tokens": [{"name", "value", "usage"}]}`. Une carte nom vers valeur (DTCG, W3C, plate ou imbriquée) est lue comme vide et disparaît à la première édition sur la page. `check-tokens.mjs` et `compile-tokens.mjs` la refusent.
- `color` porte `themes: [{id, name}]` et une liste plate ; `value` est une chaîne (tous les thèmes) ou un objet par id de thème. Un thème absent de l'objet hérite du premier. Chaque thème a un id, en minuscules, chiffres et tirets (`[a-z0-9-]`) ; chaque clé d'une valeur par thème (couleur, ombre) est l'un de ces ids, à la lettre près. Les scripts refusent toute autre forme : la valeur serait perdue et celle du premier thème prise à sa place.
- Valeurs de couleur : hex, `rgb()`, `hsl()`, `oklch()`… à arguments numériques, ou un alias `"{autre-token}"`. Refusés : `var()`, `color-mix()`, `url()`, noms de couleur.
- Un seul espace de noms `--name` pour toutes les familles hors `type` : un doublon disparaît. Noms : `^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`.
- `type` : `{fonts, families, groups}` ; la vue Typographie lit tailles, graisses et interlignes dans `groups[].styles` seulement. Une pile de `families` sans fonction ni point-virgule.
- Familles supplémentaires (`spacing`, `radius`, `zIndex`…) : valeurs CSS simples, pas de composite. Pas de famille de mouvement exposée : durées et courbes vont dans une famille libre (une section par clé) ou dans le README.
- Plafonds : 8 thèmes, 12 groupes de type, 80 styles, 60 tokens par famille hors couleur, note 400 caractères, usage 1000.

## tokens.css

- La page n'écrit `tokens.css` qu'à la première sauvegarde. Avant, l'écrire soi-même par `compile-tokens.mjs`.
- Sa première ligne doit finir par « generated from tokens.json » : la page ne reprend qu'un fichier ainsi marqué.
- Une valeur portée par `tokens.json` sort des feuilles que les aperçus chargent : sinon une modification sur la page ne gagne pas. Une feuille d'une personne se met de côté entière dans `archived/` avant d'en retirer des valeurs.

## Aperçus

- Ligne 1, exactement : `<!-- @dsCard group="<groupe>" height=<px> subtitle="<sous-titre>" -->`.
- Un document HTML complet par composant, quelques états ; le cadre a déjà chargé `tokens.css`, `bundle.css`, `bundle.js` et posé `data-theme` sur la racine du premier thème.
- Interdits : `<iframe>`, `<object>`, `<embed>`, `<noscript>`. Images : chemin relatif (`../../assets/...`), `/_blob/<id>` ou `data:`. Une URL de CDN épinglée à une version exacte, jamais une plage ni `@latest`.
- Chaque aperçu se lit dans chaque thème : basculer `data-theme` et vérifier les couples peints par l'aperçu lui-même.

## README et fiches

- Le README est un mode d'emploi pour l'agent qui consommera le système : phrases impératives qui nomment jetons, styles et ressources. Pas de titre, pas de provenance ni de « prochaines étapes » (dans la réponse, pas dans le fichier).
- Fiche de composant : ce que le consommateur fournit (props, enfants, conteneur, données), quand l'employer, à faire et à éviter, et son ancrage dans le projet. La première phrase sert de résumé.
- Une note d'usage sur chaque jeton et chaque ressource ; un SVG à une encre nomme son encre (un `<img>` n'hérite pas de `currentColor`).
- Inventaire : exactement les familles que la source définit, ajouts intentionnels listés avec leur raison.

## Couverture

- `components/Cover/preview.html`, écrite en dernier, dossier sans README ni `.d.ts` (sinon c'est un composant).
- Marqueur `<!-- @dsCard height=288 -->` (240 à 360), une mise en page fixe à 960 px de large, aucune requête de largeur.
- Trois parties : blocs de couleur à pleine valeur pondérés par l'identité, un seul motif tiré de ce que le système dit de lui-même, le nom dans la face de titre en bas à gauche avec une ligne de sous-titre. Chaque remplissage et chaque rayon lié à un jeton par une classe ; vérifier qu'aucun bloc ne disparaît dans un thème sombre.

## Avant d'envoyer

1. `node scripts/check-tokens.mjs tokens.json pairs.json` : 0 doublon, 0 erreur, 0 échec.
2. `node scripts/compile-tokens.mjs tokens.json tokens.css` puis ouvrir deux aperçus dans chaque thème.
3. Recherche de valeurs brutes dans `bundle.css` (`#[0-9a-fA-F]{3,8}`, `rgb(`, `px` hors jetons) : seules les exceptions listées dans l'architecture restent.
4. Chaque composant de l'inventaire a son dossier, son aperçu et sa fiche ; la liste contre les dossiers, comptée.
