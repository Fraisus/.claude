# Phase 6 : table d'ancrage vers l'architecture du projet

Le kit n'est utile que s'il dit, pour chaque jeton et chaque composant, ce qui le portera dans le projet. La table se remplit depuis le rapport de la zone du thème ou code local et la lecture du code, jamais depuis la mémoire d'un autre projet. Rien ne se code dans le dépôt sans demande : la table documente.

## Sommaire

- Source de vérité du rendu
- Table des jetons
- Table des composants
- Points d'extension
- Contrôle de la table
- Thème bloc WordPress (sur demande seulement)

## Source de vérité du rendu

Écrire d'abord, preuve à l'appui, où vit la valeur réellement servie : fichier de configuration, base de données (réglages globaux enregistrés), feuille compilée. Un fichier de configuration qui ne sert que de valeur de départ ne doit pas recevoir les jetons si la base fait foi. Une seule direction s'écrit dans la valeur servie ; les autres restent documentées, avec le mécanisme natif qui pourrait les porter, à vérifier dans la documentation à jour. Sur un thème bloc WordPress, la valeur servie se lit par le site lui-même, jamais dans `theme.json` : section « Thème bloc WordPress ».

## Table des jetons

| Jeton du kit | Mécanisme natif | Remarque |
|---|---|---|
| `<rôle ou groupe de rôles>` | `<slug, variable, préréglage, réglage>` | `<ce qui suit seul, ce qui casse, piège mesuré>` |

Une ligne par groupe homogène : fonds et textes de base, accent, surfaces inversées, statuts, styles de type, polices, espacements, rayons, ombres, durées et courbes, conteneurs, états d'écran. Chaque remarque dit au moins une de ces choses : ce qui suit automatiquement (une couche contextuelle qui inverse le texte), ce qu'il ne faut pas faire (renommer, doubler une échelle), le piège mesuré (préchargement de toutes les faces d'une origine, contraste automatique sur un aplat de marque).

## Table des composants

| Composant du kit | Brique existante ou source de données | Remarque |
|---|---|---|
| `<Comp>` | `<bloc, gabarit, part, fonction de données, brique archivée>` | `<à construire, à réveiller, décision à prendre>` |

- Un composant sans brique existante le dit (« à construire sur `<base native>` ») ; une brique dormante ou archivée se nomme comme piste.
- Données : la fonction qui les fournit et ses cas limites (valeur absente, état), pas seulement le nom du champ.
- Un motif interactif nomme le motif APG et l'élément natif retenu.

## Points d'extension

La liste des filtres, hooks, registres et réglages par lesquels le kit entrera, chacun avec le fichier où il est déclaré. Une variante nouvelle d'un composant passe par un style ou une composition déclarés à ces points, jamais par une copie du composant.

## Contrôle de la table

- Chaque rôle de `tokens.json` et chaque composant de l'inventaire a sa ligne : compter les deux listes contre la table.
- Chaque mécanisme cité existe : une recherche dans le dépôt le retrouve (commande et résultat gardés). Un mécanisme supposé se marque « à vérifier » avec la source à consulter.
- Les décisions laissées à l'utilisateur sont listées à part, avec leur coût, pas enfouies dans une remarque.

## Thème bloc WordPress (sur demande seulement)

S'applique quand le projet est un site WordPress à thème bloc et que l'utilisateur demande l'ancrage du kit dans ce site. Rien ne s'y déclenche d'office, rien n'est automatique : la section dit à quoi chaque jeton peut s'ancrer, le script compare, l'utilisateur choisit ce qui s'écrit et où.

### Valeurs servies, jamais `theme.json` seul

`theme.json` n'est que la couche du thème. Les réglages enregistrés en admin (article `wp_global_styles` de l'utilisateur) la recouvrent : une couleur, une taille ou une largeur peuvent y avoir une autre valeur que dans le fichier. Les valeurs à comparer sont celles que le site sert : `wp_get_global_settings()`, `wp_get_global_styles()` et la feuille de variables `wp_get_global_stylesheet(['variables'])`, où les tailles fluides sont déjà calculées. `scripts/wp-settings.php` les rassemble, en lecture seule.

### Ancrage par famille de jetons

| Jeton du kit | Mécanisme natif | Réglable en admin | À savoir |
|---|---|---|---|
| couleurs | palette `settings.color.palette`, variable `--wp--preset--color--<slug>` | valeur des couleurs existantes, ajout de couleurs personnalisées | les slugs se fixent en code et le contenu publié les cite : ne jamais les renommer. Une couleur translucide ou une valeur par thème (sombre) n'a pas de preset : un thème sombre passe par une variation de style globale ou un style de section |
| tailles de texte | `settings.typography.fontSizes`, fluides (`fluid` min et max, `clamp()` calculé par le cœur entre deux largeurs d'écran) | oui | une taille de kit fixe se compare aux bornes du preset fluide, jamais à un seul nombre ; la valeur de bureau du kit tombe en général sur la borne haute |
| polices | `settings.typography.fontFamilies` et leurs faces | oui (bibliothèque de polices) | comparer la première famille de la pile |
| espacements | `settings.spacing.spacingSizes` ou `spacingScale` | non : l'administrateur choisit un preset par bloc, il ne modifie pas l'échelle | l'échelle se fige au départ, en code ; une valeur hors preset posée dans un bloc y reste figée et ne suit plus le kit |
| rayons | `settings.border.radiusSizes` quand la version du cœur l'offre (à vérifier par Context7 sur la version du projet) | non, même raison | idem espacements |
| ombres | `settings.shadow.presets` | oui | une ombre de preset peut dépendre d'une variable du thème (couleur de texte courante) : comparer la géométrie, puis la couleur |
| largeurs | `settings.layout.contentSize` et `wideSize`, deux seulement | oui (Styles, mise en page) | une troisième largeur n'a pas de réglage de mise en page : preset `settings.dimensions.dimensionSizes` déclaré en code (supports de largeur), ou valeur figée par bloc |
| styles de texte des éléments (titres h1 à h6, lien, bouton, légende, citation) | `styles.elements.<élément>` | oui | un rôle du kit qui ne correspond à aucun élément ne s'y ancre pas |
| styles de texte hors éléments (chapô, surtitre, chiffre clé…) | variation de style de bloc (`register_block_style` avec `style_data`, ou fichier de variation du thème) | modifier une variation existante, pas en créer | à déclarer une fois en code ; une seule variation active par bloc |
| états (survol, focus, actif) | `styles.elements.link` et `styles.elements.button` (`:hover`, `:focus`, `:active`) | oui | aucun autre bloc n'a d'état réglable ; un état de composant du kit hors lien et bouton reste dans la feuille du thème |
| valeurs par écran | styles d'instance par écran (`@tablet`, `@mobile`) quand la version du cœur les offre (à vérifier par Context7), sinon le mécanisme du thème | sur chaque instance | une règle globale par écran n'existe pas dans les réglages : elle vit dans le code |
| durées, courbes, z-index, hauteurs fixes | `settings.custom` (variables `--wp--custom--*`) ou feuille du thème | non | aucune famille de preset ne les porte |

### Ce qui n'a pas d'ancrage dans les réglages

Constaté sur WordPress 7.1 (2026-10, mesures au navigateur réfutées par un critique) ; à revérifier par Context7 sur une autre version.

- Pseudo-éléments décoratifs (`::before`, `::after`) : aucun réglage, ils vivent dans la feuille du thème ou d'une extension.
- Une troisième largeur de mise en page (au-delà de contenu et large) : voir la ligne « largeurs ».
- Une grille de 12 colonnes nommée : la disposition en grille du cœur fait des pistes égales, un enfant peut s'étendre sur plusieurs colonnes, mais aucune ligne nommée n'existe et la colonne de départ n'est offerte dans l'interface que derrière une option expérimentale. La grille du kit reste une aide de maquette ; le bloc se choisit d'après ce que la section devient sur tablette et mobile.

### Comparer le kit aux presets servis (sur demande)

1. Depuis la racine du site, par la commande WP-CLI du projet (le PHP toujours par fichier) : `wp eval-file <ui-kit>/scripts/wp-settings.php <scratchpad>/site.json`. Lecture seule, rien n'est écrit en base ni dans le thème.
2. `node <ui-kit>/scripts/wp-anchor.mjs <tokens.json ou tokens.css> <scratchpad>/site.json` (`--json` pour une sortie structurée, `--help` pour les options). Étalonnage d'abord, une fois : `node scripts/wp-anchor.mjs --selftest`.
3. Lire la sortie, jeton par jeton :
   - `exact`, `borne`, `plage` : le slug devient le mécanisme de la ligne dans la table des jetons ; pour `borne` et `plage`, la largeur d'écran où la valeur est atteinte entre dans la remarque.
   - `proche` : décision à prendre, ajuster le jeton ou le preset, listée avec son écart.
   - `sans ancrage` : décision à prendre, preset à déclarer en code, couleur à ajouter en admin, ou valeur gardée dans la feuille du thème ; le preset le plus proche est donné pour lecture seulement.
   - « Presets du site sans jeton », « valeur servie différente de la couche du thème » et « presets redéfinis pour un bloc » vont dans les décisions, jamais corrigés en silence.
4. Rien ne s'écrit dans `theme.json`, la base ou le kit sans choix de l'utilisateur.

Limites de la comparaison, à dire dans le rapport : un jeton par thème est lu dans le premier thème du kit ; la largeur de conteneur (`cqi`) est prise égale à la fenêtre ; une variable définie par une feuille que le front ne charge pas se passe par `--css`, sinon le preset sort « non évaluable » ; les presets redéfinis pour un bloc sont listés, pas comparés.
