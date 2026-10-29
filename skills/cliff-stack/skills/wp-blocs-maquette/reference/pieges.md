# Pièges prouvés

Chaque piège dit ce qui se passe, d'où ça vient, et quoi faire. Ce qui dépend de la version porte sa date de mesure : le revérifier sur le site (script ou éditeur) avant de l'affirmer.

## Mise en page

**Grille à pistes égales.** Le Groupe en Grille du cœur produit `repeat(N, minmax(0, 1fr))` ou `repeat(auto-fill, minmax(...))` : toutes les colonnes ont la même largeur (`wp-includes/block-supports/layout.php`). Deux zones inégales (7 + 5 sur 12) : grille de 12 colonnes et « Fusion colonnes » sur chaque enfant ; avec une largeur minimale de colonne, le cœur retire la fusion quand la grille a moins de colonnes que la portée, et les zones s'empilent proprement. Sinon Colonnes avec des largeurs en %.

**Colonne de départ non offerte.** Le serveur sait rendre `columnStart` / `rowStart` d'un enfant de grille, l'interface ne les propose pas (option expérimentale coupée, constaté WP 7.1, 2026-10). Un élément décalé dans la grille de la maquette est un écart, ou une cellule vide.

**Colonnes empile dès la tablette.** « Empiler sur mobile » applique `flex-basis: 100%` sous 782 px (`@media (max-width: 781px)` dans la feuille de `core/columns`), seuil écrit en dur, indépendant des bornes du site, sans distinction tablette et mobile. Pour « 2 en tablette, 1 en mobile », une Grille.

**Grille Manuel sans largeur minimale ne s'empile jamais** (3 colonnes de 117 px à 390 px). Ajouter une largeur minimale, ou une valeur Mobile.

**Rangée par défaut sans retour à la ligne.** La variation Rangée pose `flexWrap: nowrap` : à 390 px les éléments rétrécissent, ils ne passent pas à la ligne.

**Défaut Gutenberg #82747** (grille « Colonnes » + « Largeur minimale de colonne » + espacement global en preset) : sur les versions touchées le front montre moins de colonnes que l'éditeur. `scripts/grid-gap-probe.php` le teste sur le site ; contournement : poser l'espacement sur le bloc grille. Non reproduit sur WP 7.1.4 sans l'extension Gutenberg (2026-10-29).

**Deux largeurs globales seulement** : contenu et large. Une troisième largeur est une valeur de bloc (figée) sauf preset de dimension servi par le site.

## Réglages par écran

**Canal du cœur `@tablet` / `@mobile`** (WP 7.1) : l'inspecteur réglé sur un écran écrit `style["@mobile"]...` ; la règle sort dans une requête média aux bornes du site (`presets.php` `viewport`), y compris pour la mise en page (`layout.columnCount`, orientation, retour à la ligne). Une valeur par écran est une valeur d'instance : la règle des presets s'y applique aussi (`audit-presets.php` les contrôle dans `per_screen`).

Un thème peut ajouter son propre canal par écran (attributs dupliqués par écran, aperçu de l'éditeur). Les deux canaux coexistent : lire le manuel du thème pour savoir lequel utiliser, et ne jamais régler la même propriété dans les deux.

## Presets et Styles globaux

**Valeur hors preset = figée.** Un `style.spacing.padding: "1.2em"` reste 1.2em quand l'échelle change ; un `var:preset|spacing|x` suit. C'est le premier constat de `audit-presets.php` (`hard_values`).

**Preset servi n'est pas preset offert.** Le cœur sert ses presets `default` en variables CSS même quand le thème les retire des sélecteurs (`default*: false`) : un markup qui les cite rend, mais l'auteur ne peut plus les rechoisir (`served_not_offered`, `hidden_presets`).

**Preset mort.** Un slug que le site ne sert plus ne rend rien, sans erreur (`dead_presets`).

**Presets propres à un bloc.** Un bloc peut déclarer ses propres listes (`settings.blocks.<bloc>`, y compris dans le `theme.json` du cœur) : dans l'éditeur elles remplacent les listes globales pour ce bloc, et leurs variables ne sont servies que dans ce bloc. Les scripts indexent les presets par bloc ; un slug valide dans un bloc peut être mort dans un autre.

**Ratio enregistré en valeur.** Le sélecteur de ratio de l'éditeur enregistre la valeur du preset choisi (`3/2`), pas `var:preset|aspect-ratio|<slug>` : les scripts la reconnaissent comme un preset (`value_families` de `scripts/lib.php`). Les rayons et les espacements, eux, sont enregistrés en `var:preset`.

**Listes tenues à la main.** Ce que le cœur n'expose pas (familles éditables en administration, interrupteurs de valeur libre, attributs à slug, supports sans réglage) vit dans `wbmaq_known()` de `scripts/lib.php`. Après une mise à jour de WordPress, `drift-check.php` doit sortir à 0 ; à 1 il liste ce que le cœur a ajouté ou retiré, à reporter dans ces listes avant de se fier aux autres scripts.

**Échelle d'espacement et rayons non réglables dans l'administration** : les Styles globaux permettent de choisir un preset par bloc ou par type de bloc, pas de modifier l'échelle elle-même (constaté WP 7.1, 2026-10). Une échelle qui ne convient pas est une décision de kit et de code.

**Spécificité du thème contre Styles globaux.** Une règle CSS du thème plus spécifique que celle des Styles globaux rend un réglage global sans effet visible (couleur de lien, de titre, de texte d'un conteneur). Le réglage est enregistré, rien ne change au front. Toujours vérifier un défaut global au front, sur la propriété calculée.

**Défaut global contre instance.** Un défaut posé dans les Styles globaux n'atteint pas une instance qui porte déjà la valeur : retirer les valeurs devenues `redundant`.

## Styles, survol, compositions

**Une variation par bloc.** Un bloc porte un seul `is-style-*` à la fois ; choisir un style remplace le précédent. Les Styles globaux modifient une variation existante, ils n'en créent pas (constaté WP 7.1, 2026-10) : une variation nouvelle demande du code (`register_block_style`, `theme.json`).

**Survol.** Les Styles globaux n'acceptent `:hover` que sur les éléments lien et bouton et sur les blocs listés par le cœur (`presets.php` `hover`, constante `VALID_BLOCK_PSEUDO_SELECTORS`). Un changement de couleur au survol d'une carte, d'une image ou d'un groupe n'est pas réglable par le cœur : chercher un réglage d'effet de survol ajouté par le thème (`attributes.added_by_extension_supports`, manuel), sinon écart. Un pseudo-élément décoratif (`::before`, `::after`) n'a aucun réglage.

**Surcharges de composition synchronisée = contenu de quelques blocs.** Seuls les attributs de `get_block_bindings_supported_attributes()` se surchargent par instance (au cœur 7.1 : contenu de paragraphe, titre, élément de liste ; image ; texte et lien de bouton ; date ; lien de navigation ; extensible par le filtre `block_bindings_supported_attributes`). `inventory.php` les lit sur le site (`overridable`). Couleurs, mise en page, styles : communs à toutes les instances.

**Contrôles coupés par réglage.** `inventory.php` `controls` : un support présent dans le `block.json` peut être coupé par un réglage du thème (`color.link: false`, `position.sticky: false`...). La maquette ne peut pas compter dessus.

## Écriture

**Un bloc statique valide au lint peut être invalide dans l'éditeur** si son HTML ne correspond pas à sa sauvegarde : seul l'éditeur réel le prouve.

**Boucle de requête sans nombre par page** : la pagination rejoue la première page ; le lint la refuse.

**Attribut d'éditeur seulement** : un attribut absent du registre serveur (`unknown_attrs`, avertissement du lint) peut être lu par l'éditeur ; le retirer sans preuve casse un réglage.

**Block Runner sans jetons** : fichier `--theme-json` absent ou au format fusionné, zéro jeton, aucun message ([ecriture.md](ecriture.md)).
