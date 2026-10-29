# Lire le site avant d'écrire

Les scripts de lecture (tous sauf `global-styles.php`, qui écrit sur option : [styles-globaux.md](styles-globaux.md)) s'exécutent dans WordPress par `wp eval-file`, depuis la racine du site, avec le PHP et le WP-CLI que le projet déclare (jamais `php` nu : extensions manquantes, échecs silencieux). Leur sortie est générée à l'usage, gardée dans le dossier temporaire de la session, jamais commitée : elle décrit l'état du site à cet instant.

## Lancer

```
wp eval-file <ce skill>/scripts/presets.php out=<tmp>/presets.json flat=<tmp>/theme-flat.json
wp eval-file <ce skill>/scripts/inventory.php out=<tmp>/inventory.json [post_type=page]
wp eval-file <ce skill>/scripts/inventory.php block=<nom/du-bloc>
wp eval-file <ce skill>/scripts/audit-presets.php <id[,id]|type:page|fichier.html> out=<tmp>/audit.json [min=3]
wp eval-file <ce skill>/scripts/grid-gap-probe.php [columns=3] [min=14rem]
wp eval-file <ce skill>/scripts/drift-check.php [reviewed=<x.y>] [out=<tmp>/drift.json]
```

- `wp eval-file` refuse les options `--x` : chaque option est un mot `clé=valeur`.
- Sur Windows, un lanceur `.bat` de WP-CLI peut perdre les guillemets d'un argument : passer tout PHP par un fichier (`eval-file`), jamais par `wp eval "<code>"`, et tout contenu par un fichier. Un chemin de script avec une espace : d'abord le nom court Windows (8.3) du dossier, sinon le chemin cité par le shell appelant, sinon une copie du script dans le dossier temporaire.
- `lib.php` est chargé par les autres scripts : ne pas le lancer seul.

## presets.php

| Clé | Ce qu'on en tire |
|---|---|
| `presets.<famille>` | chaque preset : `slug`, `name`, `value`, `origin` (default, theme, custom), `offered`, `css_var`. Les familles viennent de `WP_Theme_JSON::PRESETS_METADATA`, aucune n'est écrite à la main |
| `served_not_offered` | presets servis en variables CSS mais absents des sélecteurs de l'éditeur (presets `default` du cœur quand le thème coupe `default*`) : ne pas les écrire dans une page, l'auteur ne pourrait plus les choisir |
| `free_values` | interrupteurs de valeur libre et de contrôles (`color.custom`, `typography.customFontSize`, `spacing.customSpacingSize`, `spacing.units`, `color.link`...) |
| `layout` | les deux largeurs globales |
| `viewport` | requêtes média du canal par écran du cœur (`@mobile`, `@tablet`), absent sur un cœur qui ne l'a pas |
| `hover` | éléments et blocs dont les Styles globaux acceptent `:hover` (constantes du cœur) |
| `styles.root / elements / blocks` | Styles globaux fusionnés, à plat (`chemin => valeur`) : les défauts que la page hérite |
| `user_layer` | ce que l'administrateur a changé (article `wp_global_styles`) : valeurs qui écrasent le thème |

`flat=` écrit un `theme.json` minimal (`settings` en listes simples, presets offerts seulement) : c'est le format que Block Runner lit avec `--token-resolver file --theme-json` ([ecriture.md](ecriture.md)).

## inventory.php

| Clé | Lecture |
|---|---|
| `allowed`, `not_listed` | blocs autorisés par `allowed_block_types_all` pour le type de contenu, et pourquoi les autres ne sont pas listés |
| `blocks.<nom>.insertable` | `true`, ou `only inside: <parent>` / `only under: <ancêtre>` |
| `origin` | `core`, ou l'espace de noms du thème ou de l'extension |
| `controls` | chaque support du cœur croisé avec les réglages fusionnés du bloc : `offered` (avec `presets` et `free_value`), `cut by setting X=false`, `cut: no preset offered and no free value`, `layout panel hidden` |
| `attributes.own` | attributs du `block.json` (retrouvé dans le cœur, le thème actif et les extensions actives) |
| `attributes.from_supports` | attributs que créent les supports du cœur (`style`, `backgroundColor`...) |
| `attributes.added_by_extension_supports` | attributs qu'un thème ou une extension ajoute à tous les blocs éligibles (opacités, animations, conditions d'affichage...) : leurs libellés et leur effet se lisent dans le manuel du thème |
| `attributes.added` | attributs propres à ce bloc, ajoutés par un filtre d'enregistrement |
| `supports_diff` | supports retirés ou ajoutés par un filtre par rapport au `block.json` |
| `styles` | styles du bloc (`block.json` et `register_block_style`) |
| `variations` | variations déclarées côté serveur |
| `overridable` | attributs qu'une composition synchronisée peut surcharger par instance |
| `hover` | états que les Styles globaux acceptent sur ce bloc |
| `doc_folders` | dossiers du thème qui contiennent le plus de `.md` : candidats pour son manuel |

`editor_only` rappelle la limite : le serveur ne voit pas ce que les scripts de l'éditeur ajoutent ou retirent (variations de Groupe Rangée, Empilement, Grille déclarées en JS ; style retiré par `unregisterBlockStyle` ; contrôle masqué ; attribut déclaré en JS seulement). Un style listé ici peut être absent de l'éditeur. La preuve est l'éditeur réel : console de l'éditeur `wp.blocks.getBlockType('<nom>')`, `wp.blocks.getBlockVariations('<nom>')` et `wp.data.select('core/blocks').getBlockStyles('<nom>')`, ou l'inséreur lui-même, par `testing-real-ux`.

L'état `offered` est déduit des supports et des réglages ; un script d'éditeur peut encore masquer le contrôle. Le libellé exact d'un réglage se lit dans l'éditeur, pas dans ce rapport.

## audit-presets.php

Résumé (`summary`) puis détail (`findings`) :

| Constat | Sens | Action |
|---|---|---|
| `hard_values` | valeur libre dans une famille qui a des presets | remplacer par le preset de même rôle |
| `dead_presets` | slug qui n'est plus servi : la propriété ne rend rien | remplacer, puis chercher si d'autres pages l'utilisent |
| `hidden_presets` | preset servi mais non offert | remplacer par un preset offert |
| `redundant` | valeur égale au défaut des Styles globaux pour ce bloc ou cet élément | retirer de l'instance |
| `no_preset_family` | valeur libre sans preset possible | regrouper en défaut global si elle se répète |
| `repetitions` | (bloc, propriété, valeur) répétée au moins `min` fois, avec `share` sur les instances du bloc | règle des répétitions, [decision.md](decision.md) |
| `per_screen` | valeurs des états `@tablet` / `@mobile` du cœur | vérifier qu'elles servent le comportement voulu |
| `classes` | classes CSS additionnelles | dépendance à une feuille de style, à justifier |
| `unknown_attrs` | attributs que le registre serveur ne déclare pas | attribut d'éditeur seulement ou reste d'une fonction retirée : à prouver dans l'éditeur avant tout nettoyage |

Le zéro (`0`, `0px`) n'est jamais compté comme valeur en dur ; il entre dans les répétitions.

## grid-gap-probe.php

Teste sur le site le défaut Gutenberg #82747 (ouvert au 2026-10-29) : une Grille avec « Colonnes » et « Largeur minimale de colonne » calcule ses colonnes avec l'espacement ; sur les versions touchées, l'espacement global écrit en preset est jeté au profit de 0.5em et le front montre moins de colonnes que l'éditeur. Le script rend la formule réellement émise, avec et sans espacement sur le bloc, et un verdict : `DEFECT PRESENT` (poser l'espacement sur chaque grille de ce type), `not affected`, ou `not exercised` (l'espacement global n'est pas un preset, le défaut ne peut pas apparaître). Mesuré `not affected` sur WP 7.1.4 sans l'extension Gutenberg, espacement global forcé en preset (2026-10-29) : la version installée décide, relancer à chaque mise à jour.

## drift-check.php

Les scripts lisent dans le cœur tout ce qu'il expose (familles de presets, chemins de réglages et de styles, supports des blocs enregistrés). Ce que le cœur n'expose pas (quelles familles l'administration sait créer, les deux largeurs, les clés de mise en page) reste écrit dans `wbmaq_known()` de `lib.php`, daté d'une version du cœur. Ce script compare ces listes au cœur installé et au registre des blocs ; code 0 sans écart, code 1 avec la liste des nouveautés à trancher. À lancer à chaque mise à jour de WordPress, avant tout chantier. Trois sortes de constats : `core_changed` (une constante lue a changé depuis la version relue), `unhandled` (une famille, un chemin ou un support qu'aucune liste ne classe), `vanished` (un nom gardé que le cœur n'a plus). Après revue : mettre à jour `wbmaq_known()`, puis sa version relue. `reviewed=7.0` sur un cœur 7.1 doit lister ce que la 7.1 a ajouté : c'est l'étalonnage du contrôle.
