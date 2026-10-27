---
name: minify-assets
description: "Minifie les JS/CSS d'un projet (n'importe lequel) en fichiers .min via esbuild (npx, zéro dépendance projet), avec validation 3 niveaux (syntaxe, AST, équivalence fonctionnelle) et rapport de gains. À utiliser quand l'utilisateur demande de minifier/compacter/optimiser des assets JS ou CSS, de générer des .min, ou de préparer un dist. Ne branche jamais les .min dans le code (enqueues/imports/HTML) sans demande explicite. NE PAS utiliser pour des assets que le bundler du projet (Vite, webpack, script npm build) produit déjà : son build prime."
---

# Minification JS/CSS universelle

Outils : **Node + npx globaux** (vérifier `node --version`). Aucun ajout aux dépendances du projet — npx met l'outil dans son cache global.

## 1. Découverte (jamais de liste en dur)

- Lister les sources : `*.js` / `*.css` du périmètre demandé, en **excluant** `*.min.*`, sourcemaps, les dossiers ignorés par git et la sortie du bundler du projet.
- Si le projet a déjà un bundler configuré (`vite.config.*`, `webpack.config.*`, script npm `build`), le signaler : son build officiel prime ; ce skill sert pour les assets HORS pipeline (plugins WP, libs standalone, snippets).
- Destination : demander si ambigu — à côté des sources (`x.js` → `x.min.js`) ou dossier dédié (`public/`, `dist/`). Défaut raisonnable : dossier dédié si plusieurs fichiers, sinon à côté.

## 2. Minification (esbuild, JS et CSS)

```bash
cd <racine-cible> && npx -y esbuild src/a.js src/b.css --minify --charset=utf8 --outdir=<dest> "--out-extension:.js=.min.js" "--out-extension:.css=.min.css"
```

Pièges connus :
- **`--charset=utf8` obligatoire** sur du contenu accentué : par défaut esbuild échappe les accents latins en `\xXX` (Latin-1, ex. `café`→`caf\xE9`) et le hors-Latin-1 en `\uXXXX` (ex. `€`→`\u20AC`) → fichiers plus gros et moins lisibles.
- Ne jamais re-minifier un `*.min.*` (gain nul, risque de casse).
- Compat vieux navigateurs requise → ajouter `--target=es2017` (ou la cible du projet). Par défaut esbuild ne transpile pas.
- Fichiers destinés à `<script>` classique : esbuild préserve les IIFE — pas de `--format` à forcer. Pour des ES modules : `--format=esm`.
- Sourcemaps souhaitées → `--sourcemap` (fichiers `.map` à côté).
- CSS moderne avec nesting/custom-media à transpiler → préférer `npx -y lightningcss-cli --minify --bundle -o out.min.css in.css`.

## 3. Validation (pyramide, dans l'ordre)

1. **Syntaxe** : `node --check <f.min.js>` pour chaque JS. CSS : esbuild échoue déjà sur CSS invalide.
2. **AST strict** (JS) : `npx -y acorn --ecma2020 --silent <f.min.js>`.
3. **Équivalence fonctionnelle** (la seule preuve réelle) :
   - Si le JS expose des fonctions testables (`window.x`, exports) : harness Node qui charge **source et min dans des environnements mockés identiques** et compare les sorties sur des entrées réelles. Logique : source ≡ min ∧ source validée en réel ⟹ min sûr. (Si le projet possède déjà un harness d'équivalence, le réutiliser plutôt qu'en recréer un.)
   - Sinon : parcours réel dans le navigateur par testing-real-ux : charger la page avec le `.min` injecté et vérifier le comportement observable.

## 4. Rapport

Tableau tailles avant/après + % de gain par fichier + total. Signaler tout fichier au gain anormal (<10 % = probablement déjà compact ; >85 % = vérifier qu'il reste fonctionnel).

## Interdits

- Ne pas modifier les enqueues/imports/HTML pour pointer vers les `.min` sans demande explicite (proposer le pattern `SCRIPT_DEBUG` pour WordPress le cas échéant).
- Ne pas écraser une source. Ne pas committer.
