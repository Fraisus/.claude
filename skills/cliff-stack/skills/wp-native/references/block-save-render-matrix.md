# Matrice save × render — comment Gutenberg enregistre et restitue un bloc

Référence universelle WordPress (vraie pour tout site/thème/plugin). Sert dès qu'une feature
touche le pipeline de rendu : variation responsive, post-traitement de markup, cache de rendu,
re-render serveur, sources d'attributs, rich-text. **Découvrir la valeur réelle par bloc** (cf.
plus bas « Localiser ») — ne jamais supposer qu'un bloc est statique ou dynamique.

> Handbook officiel : https://developer.wordpress.org/block-editor/getting-started/fundamentals/static-dynamic-rendering/

## Le modèle : 3 combos `save()` × `render_callback`

`is_dynamic()` d'un bloc = `is_callable( $render_callback )`
(`wp-includes/class-wp-block-type.php` (cœur WordPress) — `is_dynamic()`).
La présence d'un `render_callback` (ou d'une clé `render` pointant un `render.php` dans `block.json`)
est ce qui rend un bloc dynamique. Le `save()` JS et le `render_callback` PHP sont **orthogonaux** :

| Combo | `save()` JS | `render_callback` PHP | Ce qui est en BDD | Ce qui sort au front |
|---|---|---|---|---|
| **C1 statique** | renvoie du HTML | absent | le HTML figé, entre les délimiteurs `<!-- wp:… -->` | le HTML lu **tel quel** par le parser |
| **C2 dynamique** | renvoie `null` | présent | seulement les **attributs** (dans le délimiteur), aucun markup | le HTML **généré à chaque vue** par le callback |
| **C3 hybride** | renvoie du HTML | présent | le HTML du `save` | le callback reçoit ce HTML en `$content` et peut le **transformer** |

- `save: () => null` ⇒ stocke uniquement les attributs **et bypasse la validation de markup**
  (utile quand le rendu change souvent côté serveur). Le callback reçoit `( $attributes, $content, $block )`.
- C3 reçoit le `save` HTML dans `$content` : il choisit de le réutiliser ou de l'ignorer.

### Sous-distinction C3a / C3b (utile pour le re-render)

- **C3a** : le `render_callback` **transforme** le `$content` du save (cas WP core hybrides :
  injecte des classes/attributs, enveloppe, réordonne). Le save HTML compte.
- **C3b** : le `render_callback` **ignore** le save et **reconstruit from scratch**. Le `save()`
  n'existe que pour persister `<InnerBlocks.Content/>` (la structure des enfants). Côté pipeline,
  un C3b se traite comme un C2 (le HTML sortant ne dépend pas du save HTML, seulement des attrs + enfants).

## Le pipeline de restitution serveur (s'applique à TOUS les blocs, même C1)

Le combo save×`render_callback` ne décrit qu'**une partie** de « ce qui sort au front ». Tout bloc
rendu serveur traverse aussi `WP_Block::render()` (`wp-includes/class-wp-block.php` (cœur WordPress)),
qui applique des couches **universelles** — y compris aux blocs **statiques C1** que `render_callback` ne touche pas :

- **Filtre `render_block`** (+ `render_block_{name}`) — post-traitement **universel** appliqué à **tout**
  bloc rendu, distinct du `render_callback` (qui est par-bloc et optionnel). C'est l'autre moitié du
  pipeline : même un C1 « lu tel quel par le parser » passe par `render_block` avant la sortie front.
  En amont, **`render_block_data` / `pre_render_block` / `render_block_context`** filtrent le `parsed_block`
  **avant** rendu (mutation d'attributs/innerHTML, ou court-circuit du rendu).
- **Block supports** (`spacing`, `color`, `layout`, `border`, `typography`, `dimensions`, `position`,
  `align`, `elements`…, ~20+ fichiers `wp-includes/block-supports/` (cœur WordPress)) —
  génèrent classes/styles de **wrapper HORS `save()`**, au render, via `get_block_wrapper_attributes()` →
  `WP_Block_Supports::apply_block_supports()`. **Principale source de divergence save↔front** pour les
  blocs statiques : le HTML en BDD n'a pas ces classes, elles sont injectées au rendu.
- **`apiVersion` (block.json) et le wrapper** — en apiVersion 2/3, le wrapper porte une **classe unique** +
  les classes de supports : `useBlockProps()` / `useBlockProps.save()` côté JS (ce qui atterrit en BDD au save),
  `get_block_wrapper_attributes()` côté serveur (au render). Tous les blocs **core 7.0 sont apiVersion 3**.
- **Block Bindings au render** — `WP_Block::process_block_bindings()` résout les attributs liés **avant**
  le `render_callback` et **réécrit le markup via l'HTML API** (`replace_html`), pour tout bloc portant
  `metadata.bindings` (sources core : `post-meta` — successeur de `source: meta` —, `pattern-overrides`,
  `post-data`, `term-data` ; `register_block_bindings_source`). C'est désormais **la** façon de réécrire
  un attribut au render hors `render_callback`.
- **HTML API** (`WP_HTML_Tag_Processor` / `WP_HTML_Processor`) — outil **natif et canonique** pour
  transformer le markup au render (utilisé par le core pour les supports et les bindings). À utiliser pour
  toute transformation C3a idempotente — **jamais** de regex sur du HTML (cf. SKILL.md §3).
- *(Plus marginal)* **Interactivity API** : traitement **serveur** via `wp_interactivity_process_directives()`
  dans `WP_Block::render()`, pas seulement des directives `data-wp-*` sérialisées au save. **Deprecations/migration**
  de blocs (`deprecated[]`/`isEligible`/`migrate`) : logique **JS éditeur** (hors core PHP) qui réécrit le
  markup persisté au prochain save d'un bloc invalide → explique qu'« ce qui est en BDD » puisse muter sans
  édition de contenu.

> **Ancres de ligne** : les n° exacts dans `class-wp-block.php` (`render_block`, `process_block_bindings`,
> `wp_interactivity_process_directives`) **varient selon la version** (ex. `render_block` ≈ l.584 en 6.7.1,
> l.648 en 7.0). Les localiser sur la build cible plutôt que de les figer.

## Sources d'attributs (`block.json` → `attributes[].source`) — l'axe rich-text

C'est l'autre façon dont un bloc « enregistre » : d'où l'attribut est **lu/écrit** dans le markup du save.

| `source` | Lecture | Incidence save/front |
|---|---|---|
| (aucun) | depuis le délimiteur de commentaire (JSON) | attribut pur, ne touche pas le HTML |
| `html` | `innerHTML` d'un nœud | le contenu **vit dans le markup** du save (C1/C3) |
| `rich-text` | comme `html` mais préserve les **formats inline** (gras, lien…) | sérialisé par `<RichText.Content>` au save ; à restituer via JS (`getBlockContent`/`serialize`), **jamais ré-implémenté en PHP** (fragile) |
| `attribute` | un **attribut DOM** d'un nœud (`href`, `src`, `alt`, `datetime`…) | valeur dans le HTML du save |
| `meta` (déprécié → Block Bindings) | post meta | hors markup ; suivre Block Bindings désormais |
| `query` | tableau de sous-valeurs (ex. liste de liens) | structure répétée dans le HTML |

**Conséquence rich-text/`html`/`attribute` :** quand une feature doit produire une variante du
contenu (par viewport, par condition…), pour un attribut à `source` il faut **régénérer le markup**
(pas juste muter un attribut JSON). Le moyen robuste est `wp.blocks.getBlockContent( block )` côté JS
(qui passe par `RichText.Content`), pas une réécriture HTML serveur.

### Attribut SYNTHÉTIQUE (injecté à l'exécution) — angle mort de classification

Un attribut peut **ne pas exister dans le `block.json`** : ajouté à la volée par un filtre
`blocks.registerBlockType` (ou un HOC `editor.BlockEdit`) sur des blocs core qu'on étend sans les
forker. Exemple typique : un plugin qui ajoute un contrôle « ordre / visibilité / réglage maison »
à tous les blocs supportés via `attrs.<x> = { type: …, default: null }` en JS uniquement.

**Le piège** : `WP_Block_Type_Registry::get_instance()->get_registered($name)->attributes` ne
contient **pas** cet attribut côté PHP (il n'a ni `source` ni `attribute` HTML — il vit seulement
dans le délimiteur JSON et la mémoire de l'éditeur). Tout code de pipeline qui classe un attribut
par `$bt->attributes[$base]` fait alors `?? null` → `continue`/skip → l'attribut synthétique est
**invisible** à la classification (gating, filtrage, normalisation). Symétriquement, le jumeau JS
qui lit `getBlockType(name).attributes[k].source` le voit **sans `source`** → le classe
« structurel/libre » par défaut.

**Conséquence** : un traitement censé reconnaître/filtrer cet attribut (ex. le réserver, le router,
le neutraliser) **ne se déclenche jamais** — fuite silencieuse côté serveur,
écriture autorisée à tort côté éditeur. Le bug ne se voit que quand l'attribut existe **et** qu'on
attend qu'il soit traité (jamais sur un post de test qui n'en a pas).

**Correctif** : classer aussi par **nom de base d'attribut**, en repli quand `$bt->attributes[$base]`
est null, depuis la source de déclaration que le projet tient déjà pour ses attributs (découvrir
celle du projet), et le brancher **identiquement** aux points PHP (rendu et toute normalisation)
**et** au jumeau JS, sinon le serveur et l'éditeur divergent. Aucun nom d'attribut en dur dans la
décision : un futur attribut synthétique se déclare en une entrée de cette source.

## Compositions (blocs imbriqués)

Mécanismes WP : `<InnerBlocks/>` (edit) · `<InnerBlocks.Content/>` (save) · `$content`
(param du `render_callback`) · `inner_content` (tableau côté parser) · `do_blocks()` walk récursif
(`wp-includes/class-wp-block.php` (cœur WordPress) — méthode `render()`).

Invariants à respecter pour toute transformation du pipeline multi-niveaux :

- **Idempotence** — appliquer la transformation 2× = 1× (ajout d'attrs `style`/`class`/`data-*`,
  bake inline, wrap). Sinon doublons (`order:-1;order:-1;`, classes répétées).
- **Pas de double re-render** — un bloc atteint par 2 chemins (lui-même + en tant qu'inner d'un
  ancêtre re-rendu) ne doit pas subir 2× le pipeline. Court-circuiter au niveau le plus haut atteint.
- **Préservation des marqueurs d'enfants** — un parent re-rendu doit conserver les `data-*`/IDs
  posés sur ses descendants, sinon perte de découvrabilité au runtime JS.
- **Variants asymétriques** — un parent variabilisé peut contenir des enfants non variabilisés (et
  l'inverse) ; les deux cas doivent fonctionner indépendamment.
- **Wrapper vide vs modifié** — un conteneur sans classe utilitaire peut être un passthrough CSS
  invisible, mais sa présence DOM reste nécessaire à la structure.

## Cas spéciaux WP core (génériques, pas projet-spécifiques)

- **`core/embed`** : C1 strict, MAIS l'URL du save est transformée en iframe par
  `$wp_embed->autoembed()` accroché à `the_content` — **pas** un `render_callback`. Un pipeline qui
  ne re-déclenche pas `the_content` rate cette transformation.
- **`core/image`** : C3a ; certains thèmes strippent le `<figure>` **après** le `render_callback`
  (filtre sur le markup). Les attributs visés peuvent migrer du `<figure>` vers le `<img>`.
- **`core/query` + `post-template`** : in-loop. Le rendu dépend du post courant de la boucle ; ne pas
  servir un cache « hôte » global, scoper par `get_the_ID()`. Pagination pages 2+ avec
  `enhancedPagination` : le routeur Interactivity fait un `fetch` GET de la page HTML complète, la parse
  (`DOMParser`) et remplace la région `data-wp-router-region` = markup injecté après coup, sans rechargement
  de page → un `MutationObserver` est nécessaire pour le rattraper.
- **`core/block`** (référence de bloc réutilisable `wp_block`) : `do_blocks` scopé sur l'ID du
  `wp_block`, pas du post hôte.
- **`core/page-list-item`** : ni `save.js` ni `render_callback` — sous-composant géré par le parent.

## Chemins de save : REST vs non-REST — **angle mort vérifié**

Tous les chemins d'écriture ne déclenchent pas les mêmes hooks. Vérifié sur le core
(`do_action` dans `wp-includes/`) :

- **`rest_after_insert_{post_type}`** est émis par les **contrôleurs REST de post types**
  (posts, attachments, templates… — `wp-includes/rest-api/endpoints/`), **jamais** par `wp_insert_post()`.
  C'est donc **strictement REST-only** : un écrit non-REST ne le déclenche pas. (À ne pas réduire au seul
  posts-controller : d'autres contrôleurs émettent leur propre `rest_after_insert_*`.)
  Idem un filtre JS `editor.preSavePost` : il ne vit que dans le navigateur de l'éditeur.
- **`save_post`** est émis **inconditionnellement** dans `wp_insert_post()`
  (`wp-includes/post.php` (cœur WordPress) — `do_action('save_post', …)`), **tronc commun de toutes les écritures**.
- **`wp_after_insert_post`** est émis dans le **même** `wp_insert_post()`, mais **gardé par `if ($fire_after_hooks)`**
  (3ᵉ argument de `wp_insert_post`/`wp_update_post`, défaut `true`). Un appelant qui passe
  `$fire_after_hooks = false` (ex. `wp_update_post($arr, $err, false)`) déclenche **`save_post` MAIS PAS**
  `wp_after_insert_post`. Les deux ne sont donc **PAS interchangeables**.

| Chemin d'écriture | REST | `editor.preSavePost` | `rest_after_insert_*` | `save_post` (inconditionnel) | `wp_after_insert_post` (sauf `fire_after_hooks=false`) |
|---|:--:|:--:|:--:|:--:|:--:|
| Éditeur Gutenberg (clic Enregistrer) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `wp_update_post()` programmatique (défaut) | ❌ | ❌ | ❌ | ✅ | ✅ |
| `wp_update_post($arr, $err, false)` | ❌ | ❌ | ❌ | ✅ | ❌ |
| `wp_publish_post()` (transition de statut) | ❌ | ❌ | ❌ | ✅ | ✅ |
| Quick Edit (`wp_ajax_inline_save` → `wp_update_post`) | ❌ | ❌ | ❌ | ✅ | ✅ |
| Restauration de révision (→ `wp_update_post`) | ❌ | ❌ | ❌ | ✅ | ✅ |
| Import WXR / `wp post update` (CLI) | ❌ | ❌ | ❌ | ✅ | ✅ |

**Doctrine.** Un traitement qui doit valoir pour **tout** contenu (cache de rendu, normalisation,
génération de variantes persistées) s'accroche à **`save_post`** — **le seul hook véritablement universel
et inconditionnel** (filet ultime) —, jamais à `rest_after_insert_*` ni à un filtre JS éditeur seul.
Préférer **`wp_after_insert_post`** quand on a besoin de `$post_before` ou de l'**état post-meta complet** :
sur le chemin REST/éditeur, le posts-controller appelle `wp_insert_post(…, false)` puis émet lui-même
`wp_after_insert_post` + `rest_after_insert_*` **après** l'écriture des meta/terms — donc `save_post`
tire **avant** que les meta REST soient persistées, `wp_after_insert_post` **après**. C'est la raison de
fond de préférer ce dernier — sans jamais le croire universel (cf. `fire_after_hooks=false`).
**Symptôme typique de l'angle mort** : un bug *intermittent* qui n'apparaît qu'après une édition rapide,
une révision, un import ou une mise à jour programmatique — exactement les chemins que le flux REST/éditeur
n'exerce jamais.

## Localiser le combo d'un bloc (rien en dur, à la demande)

- **Core** : `wp-includes/blocks/<name>.php` (présence d'un `render_callback`) +
  `wp-includes/blocks/<name>/block.json` (clé `render`). Source amont :
  `packages/block-library/src/<name>/save.js` du dépôt Gutenberg.
- **Bloc custom** : son `block.json` (clé `render`) + le `save` dans son `index.js`/`edit`.
- **À l'exécution** : `WP_Block_Type_Registry::get_instance()->get_registered( $name )->is_dynamic()`.
- Pour les `source` d'attributs : la clé `attributes` du `block.json` du bloc.
