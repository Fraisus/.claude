---
paths:
  - "**/*.scss"
  - "**/*.css"
---

# Invariants CSS (baseline du projet d'abord, zéro déclaration morte)

- **Jamais de Sass.** Un fichier `.scss` s'écrit en CSS natif, sans aucune syntaxe Sass. Ce que Sass faisait se fait en CSS moderne, à exploiter au maximum : tout ce que les navigateurs courants supportent aujourd'hui sans préfixe ni polyfill. Suivre la nomenclature du projet en cours, quelle qu'elle soit : le partage passe par les classes de couche, les custom properties et les listes de sélecteurs. Admis car CSS ou graphe de fichiers : imbrication et `&`, `@media`, `@use` en tête.
- **Avant TOUTE règle CSS : établir la baseline réelle du projet** — lire son reset (un reset à `all: unset` neutralise DÉJÀ bordure/fond/police/couleur/padding/outline des contrôles), ses tokens (custom properties), ses utilitaires/objets. Un reset défensif plaqué de mémoire (`border:0`, `background:none`, `font:inherit`, `color:inherit`, `padding:0`, `outline:none`) est un DÉFAUT s'il doublonne la baseline.
- **Chaque déclaration doit être prouvable** : si la barrer dans l'inspecteur ne change rien, elle n'a pas le droit d'exister. En cas de doute, tester — pas d'écriture « au cas où ».
- **Jamais de dimension figée quand un mécanisme intrinsèque existe** : `field-sizing`, `fit-content`, `min()/clamp()`, attribut HTML `size` (repli natif) avant toute `width` en dur — une largeur magique est une régression en attente.
- **Valeurs du projet, pas des littéraux** : tokens du projet pour couleurs/espacements/transitions ; fallback var(--x, littéral) seulement si le token peut être absent du contexte.
- **Moderne et non redondant** : `:is()/:where()` pour une définition unique par comportement partagé, propriétés logiques, `:focus-visible` (le focus ne se supprime jamais, il se style). Une valeur répétée 2× = une custom property.
- **Ordre des déclarations en sablier inversé** — dans un bloc, les valeurs se rangent par type : mot-clé pur, fonction, variable, valeur avec unité, chiffre nu. Les mots-clés **montent** par longueur de ligne croissante, tout le reste **descend** par longueur décroissante : le bloc dessine un losange. Deux déclarations qui se recouvrent (même propriété, raccourci et propriété détaillée, ou repli d'une même propriété) gardent leur ordre d'origine, la cascade prime sur la forme.
- **Pas de nesting quand une règle plate existe** — `&.modificateur { … }` s'écrit `.parent.modificateur { … }`. Le nesting ne se garde que s'il évite une répétition réelle (sélecteur parent long ou liste de sélecteurs) ou une imbrication structurelle (`@media`, `@supports`). Dans un bloc, les déclarations s'empilent une par ligne, sans ligne vide qui le coupe en deux.
- **Un commentaire appartient à sa déclaration** — sur sa propre ligne il documente celle qui le suit et voyage avec elle quand le bloc se réordonne ; en fin de ligne il suit la sienne et ne compte pas dans la longueur. Il reste **court** (1 à 3 lignes, cf. `code-style.md`) : dans un bloc trié, un pavé de cinq lignes noie la silhouette. Spec exécutable et vérification : `skills/cliff-stack/hooks/scripts/css-order.mjs` (`--check` / `--write` / `--audit`), branché en hook `PostToolUse`.
- **Le structurel s'arrête au mécanisme** : jamais inventer d'habillage ni d'affordance (bordures, couleurs, soulignés, alignements décoratifs) — le design appartient au projet/dev ; livrer le minimum fonctionnel et signaler ce qui reste à dessiner.
