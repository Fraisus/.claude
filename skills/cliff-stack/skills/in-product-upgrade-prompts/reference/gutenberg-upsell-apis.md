# API d'éditeur Gutenberg pour l'upsell in-product — vérité terrain

> Source : developer.wordpress.org. **[API officielle]** = confirmé doc ; **[pattern communautaire]** = répandu mais pas une API ; **[à éviter]** = risque guideline ou n'existe pas.

## Sommaire
- [1. Faire apparaître un teaser dans l'inserter](#1-faire-apparaître-un-teaser-dans-linserter)
- [2. Rendre l'upsell dans le bloc : Placeholder](#2-rendre-lupsell-dans-le-bloc--placeholder)
- [3. SlotFills d'éditeur](#3-slotfills-déditeur)
- [4. Notice / Button — props réelles](#4-notice--button--props-réelles)
- [5. Ce qui N'EXISTE PAS](#5-ce-qui-nexiste-pas)
- [6. Guidelines WordPress.org — interdit vs toléré](#6-guidelines-wordpressorg--interdit-vs-toléré)
- [7. Patterns freemium observés](#7-patterns-freemium-observés)
- [8. Sources](#8-sources)

## 1. Faire apparaître un teaser dans l'inserter
**`registerBlockVariation`** **[API officielle]** — `wp.blocks.registerBlockVariation(blockName, variation)`. La prop **`scope`** accepte exactement : `'block'`, `'inserter'`, `'transform'`. → `scope: ['inserter']` place l'entrée dans l'inserter. Shape : `name, title, description, category, keywords, icon, attributes, innerBlocks, example, scope, isDefault, isActive`. `isActive` = fonction ou tableau d'attributs.
Usage upsell : enregistrer une variation teaser dont le rendu `edit` montre un placeholder d'upsell (le gating réel reste côté plateforme de licence du projet / `wp-native`).

## 2. Rendre l'upsell dans le bloc : Placeholder
**`Placeholder`** **[API officielle]** — `import { Placeholder } from '@wordpress/components';`. Props : `label`, `instructions`, `icon`, `className`, `isColumnLayout`, `preview`, `notices`, `withIllustration`. Composant canonique pour un état « fonction non débloquée » dans le `edit()` d'un bloc, avec un CTA d'upgrade.

## 3. SlotFills d'éditeur
**[API officielle]** — enregistrés via `registerPlugin` (`@wordpress/plugins`). Pertinents pour un upsell contextuel :
- `PluginSidebar` — panneau dédié du plugin (zone légitime « Pro »).
- `PluginDocumentSettingPanel` — panneau dans les réglages du document.
- `PluginPrePublishPanel` / `PluginPostPublishPanel` — au moment de publier.
- `PluginBlockSettingsMenuItem`, `PluginMoreMenuItem`.
Canonique 2024+ = package **`@wordpress/editor`** (l'import depuis `@wordpress/edit-post` est l'ancienne voie, en dépréciation). Vérifier au build via Context7.

## 4. Notice / Button — props réelles
- **`Button`** **[API officielle]** — `import { Button } from '@wordpress/components';`. `variant` ∈ `'primary' | 'secondary' | 'tertiary' | 'link'`. CTA upgrade → `variant="primary"`.
- **`Notice`** **[API officielle]** — props : `status` ∈ `'warning' | 'success' | 'error' | 'info'` (défaut `info`), `isDismissible` (défaut `true`), `onRemove`, `actions` (`[{ label, url?, onClick?, variant? }]`), `children` (requis), `politeness`, `spokenMessage`. → un upsell via `Notice` DOIT rester `isDismissible: true` (guideline 11).

## 5. Ce qui N'EXISTE PAS
- ❌ **Pas d'API native de « premium pattern verrouillé / preview-bloqué dans l'inserter ».** Le comportement « verrouillé non prévisualisable » observé sur certains patterns = **bug Gutenberg #55469**, pas une feature. Le **Block Locking API** (`templateLock`, `lock: {move, remove}`) verrouille des blocs **déjà insérés** dans un pattern — ce n'est pas un gate d'aperçu premium.
- ❌ **`allowedBlocks`** = restriction des blocs insérables dans un `InnerBlocks`. Pas un mécanisme d'upsell.
- ❌ **Block Bindings** = liaison d'attributs à des sources (méta…). Aucun rapport avec le gating premium.
- ❌ Inventer `scope: 'premium'` ou un `status` de `Notice` hors des 4 valeurs.

## 6. Guidelines WordPress.org — interdit vs toléré
- **Block Directory** (soumission mono-bloc) : **« No form of payment is permitted for the use of a Block Plugin »** → tout paywall = exclusion. Et « must not display alerts, dashboard notifications, or similar obtrusive messages unrelated to the block ».
- **Plugin Directory** (repo principal) : freemium autorisé. C'est là qu'un plugin freemium se soumet.
- **Guideline 5 (Trialware)**, règle principale : « Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade. » Puis : « Functionality may not be disabled after a trial period or quota is met. » ; « We recommend the use of add-on plugins, hosted outside of WordPress.org, in order to exclude the premium code. » ; « Attempting to upsell the user on ad-hoc products and features is acceptable, provided it falls within bounds of guideline 11. » → Contrôle désactivé + « Pro » conforme **seulement si le code premium est absent du build gratuit** ; un code présent mais verrouillé par licence tombe sous la règle principale.
- **Guideline 11 (Hijacking admin)** : upgrade prompts/notices « must be limited in scope and used sparingly, be that contextually or only on the plugin's setting page » ; notices site-wide « must be dismissible or self-dismiss » ; pub dashboard « should be avoided ».
→ **Toléré** : upsell contextuel au point d'usage ou sur la page de réglages. **À éviter** : nags globaux répétés, non-dismissibles, hors-sujet, pub dashboard.

## 7. Patterns freemium observés
**[pattern communautaire — conventions UX, pas des API]** :
- **Blocs Pro absents tant que le plugin Pro n'est pas installé** (bibliothèques de blocs et constructeurs grand public) — le plus conservateur.
- **Badge « PRO » + contrôle grisé/inerte** dans les réglages, débloqué à l'achat (bibliothèques de blocs grand public).
- **Preview live + templates/layouts Pro marqués** (plugins de galerie).
- **Modal au clic / lien upgrade** (courant).
Les présenter comme conventions, pas comme fonctionnalités natives de Gutenberg.

## 8. Sources
- Block Variations API — `https://developer.wordpress.org/block-editor/reference-guides/block-api/block-variations/`
- Placeholder — `https://developer.wordpress.org/block-editor/reference-guides/components/placeholder/`
- Button — `https://developer.wordpress.org/block-editor/reference-guides/components/button/`
- Notice — `https://developer.wordpress.org/block-editor/reference-guides/components/notice/`
- SlotFills — `https://developer.wordpress.org/block-editor/reference-guides/slotfills/`
- Block Locking — `https://developer.wordpress.org/block-editor/how-to-guides/curating-the-editor-experience/block-locking/`
- Detailed Plugin Guidelines — `https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`
- Block-Specific Plugin Guidelines — `https://developer.wordpress.org/plugins/wordpress-org/block-specific-plugin-guidelines/`
- Bug patterns verrouillés — `https://github.com/WordPress/gutenberg/issues/55469`
