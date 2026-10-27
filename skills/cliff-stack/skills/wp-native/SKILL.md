---
name: wp-native
description: "Use when implementing or modifying ANY WordPress feature on a WordPress project — Gutenberg blocks, block editor extensions (InspectorControls, HOC/filters), render_block/PHP hooks, theme.json, ACF blocks, REST, admin UI. Enforces WP-native practices: core blocks + block supports first, native filters/HOCs over custom, @wordpress/* React components (never raw React), official coding standards. Triggers on requests like \"ajoute/code une feature/un bloc/un réglage/un panneau/une option WP|Gutenberg|ACF\". Inclut l'IMPLÉMENTATION éditeur d'un upsell freemium une fois la stratégie posée (Placeholder Pro dans edit(), registerBlockVariation scope:['inserter'], Pro-badge sur contrôle désactivé) — la STRATÉGIE de placement/timing reste à in-product-upgrade-prompts, le gating de licence à la plateforme du projet (freemius si c'est elle). NOT la procédure de vrai save humain éditeur (clic Enregistrer réel pour déclencher cache/rest_after_insert/preSavePost) → testing-real-ux."
---

# wp-native : feature WordPress en natif

Objectif : toute feature WP est codée avec le maximum de natif WP et idiomatique au projet courant (auto-loader, render.php, JS inline `wp.*` ou build @wordpress/*, theme.json v2/v3, nommage). Ne suppose aucun chemin ni préfixe.

## 0. Avant de coder
1. Identifiants Context7 pour sourcer une API :
   - Patterns / « the right way » : `/wordpress/gutenberg`
   - Composants @wordpress/components (props) : `/websites/wp-gb` puis `/wordpress/gutenberg`
   - Fonctions/hooks/classes PHP core : `/websites/developer_wordpress_reference_functions`, `/websites/developer_wordpress_reference_hooks`, `/websites/developer_wordpress_reference_classes`
   - Standards exigés : `/websites/developer_wordpress_coding-standards`, `/wordpress/wpcs-docs`
   - Repli pour un composant non documenté : README GitHub `packages/components/src/<C>/README.md` (WebFetch) ou `node_modules/@wordpress/components/...` si installé (la version installée fait foi).
2. **Vérifie la version réelle** (slugs presets, blocs, supports) : `wp_get_global_settings()`, `WP_Block_Type_Registry`, code core installé (`wp-includes/`). Ne jamais inventer un slug/preset par défaut.

## 1. Hiérarchie de nativité (toujours du + natif au - natif)
1. **Bloc core + block supports** (spacing/color/border/typography/layout/dimensions) — préfère un réglage natif à du custom. Le contrôle d'alignement (`align` wide/full) **exige aussi `supports.layout`** (type `default` = sans max-width imposée) — sinon la toolbar reste vide ; WP l'injecte alors automatiquement, pas de control manuel.
2. **Extension par filtres/HOC natifs** sans nouveau bloc : `blocks.registerBlockType` (attributs), `editor.BlockEdit` / `editor.BlockListBlock` (HOC UI/preview), `render_block` / `render_block_data` (front), `register_block_type_args` (déclare l'attribut **côté serveur** si SSR/REST, sinon rejet « attributes invalides »).
3. **Variations / block styles / patterns** plutôt qu'un bloc dédié quand c'est cosmétique.
4. **Bloc custom** en dernier : `block.json` **apiVersion 3**, `useBlockProps()` / `useInnerBlocksProps()`, `render.php` pour le dynamique.

> **Références ciblées** (à lire selon le sujet) :
> - `references/editor-iframe.md` : script éditeur qui touche au DOM/window du canvas, styles de bloc absents dans l'éditeur.
> - `references/deprecations.md` : modification du `save()` ou des attributs d'un bloc déjà publié.
> - `references/perf-backend.md` : lenteur back-end, profilage, autoload.

> **Modes de save/render**, pipeline de restitution serveur, sources d'attributs, chemins de save non-REST et attributs synthétiques : **`references/block-save-render-matrix.md`**, à lire dès qu'une feature touche le pipeline de rendu (post-traitement de markup, cache, re-render serveur, variante de contenu, rich-text, block supports, ou un réglage maison ajouté à des blocs core par filtre/HOC).

## 2. React = 100 % WP natif (vaut pour toute UI admin/éditeur)
**RÈGLE NON NÉGOCIABLE : toute interface admin (page de réglages, meta box, panneau d'inspecteur, barre d'outils, modale, sidebar plugin) se construit avec les composants React natifs de WP.** Ni `<input>`/`<select>`/`<button>` HTML bruts stylés à la main, ni lib UI tierce (MUI, AntD, Bootstrap…), ni React importé hors `@wordpress/element`. Un contrôle natif existe quasi toujours : le chercher (Context7 `/websites/wp-gb`) avant d'en bricoler un.
- Briques : `@wordpress/element` (PAS React brut), `@wordpress/components` (ToggleControl, PanelBody/PanelRow, ToolsPanel/ToolsPanelItem, RangeControl, SelectControl, TextControl, ColorPalette/ColorPicker, ComboboxControl, Button, Notice, Spinner, Modal, Card…), `@wordpress/block-editor` (InspectorControls, BlockControls, useSettings…), `@wordpress/data` (useSelect/useDispatch), `@wordpress/core-data` (entités/réglages), `@wordpress/i18n` (`__`, `_x`), `@wordpress/hooks` (addFilter), `@wordpress/compose` (createHigherOrderComponent), `@wordpress/plugins` + `@wordpress/edit-post`/`edit-site` (PluginSidebar, PluginDocumentSettingPanel).
- **Deux contextes, deux réponses natives** :
  - **Écran qui charge déjà React/Gutenberg** (éditeur, site editor, ou page où l'on monte React volontairement pour de l'interactif riche) → `@wordpress/components` (ci-dessus), monté via `createRoot` (`@wordpress/element`) + `@wordpress/api-fetch` pour le REST.
  - **Admin basique sans React** (page d'options simple, meta box statique) → **ne pas charger React inutilement**. Utiliser le **markup & les classes admin natifs WP** (`.wrap`, `.form-table`, `.button`/`.button-primary`, `.notice`, dashicons, `.postbox`) + la **Settings API** (`register_setting`, `add_settings_section/field`, `settings_fields()`, `do_settings_sections()`). Zéro CSS de contrôle réinventé, zéro lib externe. (jQuery seulement si déjà la convention du projet et qu'aucune alternative native simple n'existe.)
- Respecte le **mode de livraison du projet** : si build (`src/*.js` + `@wordpress/scripts`/Vite) → imports ES + `wp-scripts` ; si pas de build → `wp_add_inline_script` avec `wp.*` global (mêmes APIs). Ne mélange pas les deux.
- `ToolsPanelItem` pour s'intégrer aux panneaux natifs ; `isShownByDefault` selon l'UX voulue.

## 3. Conventions PHP et sécurité
- **theme.json v3**, supports > CSS inline ; presets via `var(--wp--preset--…)` avec le slug passé par `_wp_to_kebab_case` (`3xl` → `3-xl`, cf. `rules/wordpress-php.md`).
- Post-traitement de markup : **`WP_HTML_Tag_Processor`** (jamais de regex sur du HTML).
- **i18n** systématique (`__()/esc_html__()` + textdomain, jamais avant `init`, cf. `rules/wordpress-php.md`).
- **Sécurité** : contrôles obligatoires par point d'entrée (REST, AJAX, admin-post, options, cron, uploads, HTTP sortant, désérialisation, chemins, SQL, données JS, sortie) → **`../../references/wp-security-controls.md`**, à lire avant d'écrire un handler ou une route.
- Hooks idiomatiques, priorités explicites ; pas de requête lourde non cachée (transient/cache si chaud).
- Pas de `__experimental`/`__unstable` si une API stable existe ; si inévitable, isole + commente la dette.

## 4. Sortie & validation
- **Signale tout risque de régression** (SSR/REST, ordre de hooks, cache, specificity CSS).
- **Baseline WP-natif avant de qualifier un « bug »** : avant d'imputer un comportement au code projet, vérifie que ce n'est pas le comportement de WP core **sans** le plugin (ex. un bloc image core fige aussi son `src` résolu dans `post_content` : la « péremption d'URL média » est WP-native, pas un bug du plugin ; REST refuse les meta `_*` protected : contrainte WP, pas un choix arbitraire). Si WP core fait pareil, comportement hérité, pas une régression à corriger.
- `php -l` sur le PHP touché. Lint projet si présent (PHPCS/ESLint), sinon le proposer.
- Comportement éditeur réel (UI éditeur, save humain, resize front) : parcours réel par les commandes du projet (testing-real-ux).

## Anti-patterns à refuser
- Raw React / lib UI tierce dans l'éditeur • regex sur HTML • slug/preset/chemin en dur quand découvrable • attribut custom non déclaré serveur sur bloc SSR • `register_block_type` sans `block.json` • sortie non échappée.
