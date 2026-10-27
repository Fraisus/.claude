---
name: astro-native
description: "Use when implementing or modifying ANY Astro feature on an Astro project — .astro components, islands, client/server directives, Content Collections, Actions, view transitions, SSR adapters, i18n, middleware. Enforces Astro best practices: zero-JS-by-default, static-first then minimal hydration, Content Collections over raw fs, perf/LCP as a hard guardrail. Triggers: « ajoute/code une feature/un composant/une page/une island/une collection Astro », « astro.config », « server island », « client:visible », « Content Collection », « Action Astro ». NE PAS utiliser pour un projet non Astro (WordPress → wp-native), ni pour la direction visuelle ou le polish d'une interface (→ impeccable), ni pour un audit d'accessibilité (→ design-auditor)."
---

# astro-native — feature Astro en natif, perf-first

Objectif : toute feature Astro est codée **avec le maximum de natif Astro** et **perf-first** (zero JS par défaut).

## 0. Avant de coder (obligatoire, dans l'ordre)
1. **Lis la config du projet** : `astro.config.mjs` (valeur de `output`, adapter, intégrations), structure `src/` (pages/components/layouts/content), framework UI éventuel (React/Svelte/Vue/Preact), TS strict ou non, conventions de nommage.
2. **IDs Context7 de la doc Astro** :
   - Doc canonique / patterns / référence : `/withastro/docs` (officiel)
   - Variante site / exemples : `/websites/astro_build_en`
   - Version épinglée (vérité terrain) : `/withastro/astro` (ex. astro_6.x)
   - Fallback : `node_modules/astro` du projet (version réelle installée) ou WebFetch docs.astro.build.
3. **Vérifie la version réelle** du projet (`package.json` → astro 5 / 6) : les features récentes (Server Islands, Actions, Sessions) dépendent de la version. Ne jamais supposer qu'une feature existe.

## 1. Hiérarchie de nativité / perf (toujours du + statique au + coûteux en JS)
1. **Composant .astro pur (zéro JS)** — rendu serveur, aucune hydratation. Le défaut absolu : si c'est du contenu/markup, ça reste ici.
2. **Server Island** (`server:defer`) — contenu dynamique/perso rendu côté serveur, différé, SANS envoyer de JS au client. Préfère ça à une island client quand l'interactivité n'est pas requise (ex. bloc personnalisé, données fraîches).
3. **Island client, hydratation par ordre de coût croissant** — n'hydrate QUE le strictement interactif, et choisis la directive la moins chère qui marche :
   - `client:visible` (défaut recommandé : hydrate à l'entrée dans le viewport) — idéal hors-écran/lourd.
   - `client:idle` (basse priorité, après load, via `requestIdleCallback`).
   - `client:media={QUERY}` (seulement si le média matche).
   - `client:load` (RÉSERVÉ à l'interactif immédiatement visible et critique — coûte le plus, justifier).
   - `client:only={FRAMEWORK}` (pas de SSR ; à éviter pour le LCP/SEO, justifier).
4. **Content Collections** (`src/content/`, schémas Zod) plutôt que lecture fs/markdown brute — typage + validation natifs.
5. **Actions** (form/server actions, Astro 5+) plutôt que routes API maison quand c'est une mutation typée ; **middleware** pour le transversal (auth, i18n) ; **view transitions** natives plutôt qu'une lib SPA.

## 2. Perf / LCP = garde-fou dur (critère de refus, pas une option)
- **Zero JS by default** : toute hydratation est une dette à justifier. Pas de `client:load` « par confort ».
- **Images via `<Image/>`/`<Picture/>` (`astro:assets`)** — jamais `<img>` brut non optimisé ; dimensions explicites (anti-CLS).
- LCP : pas d'island client sur l'élément LCP ; contenu critique en .astro/SSR.
- `prefetch` natif pour la navigation ; éviter les intégrations UI lourdes si une island Astro / `<script>` vanilla suffit.

## 3. Conventions (sécurité + idiomatique)
- **Schémas Zod** pour Content Collections et entrées d'Actions (validation = sécurité).
- Échappe par défaut (Astro échappe les expressions ; `set:html` UNIQUEMENT sur contenu assaini — jamais d'input brut).
- Variables d'env via `astro:env` / `import.meta.env` (jamais de secret en dur ; `PUBLIC_` = exposé client, vigilance).
- Pas d'API `experimental` si une stable existe ; si inévitable, isole + commente la dette + flag dans `astro.config`.
- Respecte `output` du projet (`static`, où une page sort du prérendu par `export const prerender = false`, ou `server` ; `hybrid` n'existe plus depuis Astro 5) : un `server:defer` / Action exige un adapter — vérifier avant de proposer.

## 4. Sortie & validation
- **Signale tout risque** : hydratation injustifiée, CLS, secret exposé `PUBLIC_`, feature exigeant un adapter absent.
- `astro check` (types) sur le code touché ; lint projet si présent (ESLint/Prettier) — sinon le proposer.
- Comportement réel (hydratation effective, view transitions, LCP) par testing-real-ux quand pertinent.

## Anti-patterns à refuser
- `client:load` par défaut / hydrater ce qui pourrait être statique ou server island • `<img>` brut au lieu de `astro:assets` • lecture fs au lieu de Content Collections • `set:html` sur input non assaini • secret en `PUBLIC_` ou en dur • route API maison là où une Action typée convient • feature server (server:defer / Action) sans vérifier l'adapter/output.
