# De la maquette au bloc

Une grille de maquette (12 colonnes, gouttières) est une aide au dessin, pas un plan de construction. Chaque section se décrit d'abord par ce qu'elle devient sur tablette et sur mobile, puis on choisit le bloc qui produit ce comportement. Les bornes d'écran du site se lisent dans `presets.php` (`viewport`, requêtes `@mobile` et `@tablet`), jamais supposées.

## 1. Comportement en mobile → bloc et réglages

Blocs du cœur, comportement établi par `wp-includes/block-supports/layout.php` et la feuille de `core/columns`, mesuré dans Chromium sur les feuilles servies à 1280, 700 et 390 px (2026-10, WP 7.1).

| Voulu en mobile | Bloc et réglages | Rendu desktop / tablette / mobile |
|---|---|---|
| Tuiles qui s'adaptent seules à la place | Groupe en Grille, mode Automatique, « Largeur minimale de colonne » (12rem par défaut) | 6 / 3 / 1 par ligne avec 6 tuiles |
| N colonnes en desktop, moins quand la place manque | Grille, « Colonnes » N **et** « Largeur minimale de colonne » | 3 / 2 / 1 pour N=3 et 14rem |
| N colonnes partout, 1 en mobile seulement | Grille « Colonnes » N + valeur Mobile du cœur (`style["@mobile"].layout.columnCount` = 1, inspecteur réglé sur Mobile) | 3 / 3 / 1 |
| Tout empilé dès la tablette | Colonnes, « Empiler sur mobile » (défaut) | 3 / 1 / 1 : seuil fixe 781 px |
| Deux zones de largeurs inégales, empilées en mobile | Colonnes, « Largeur » en % sur chaque Colonne (ignorée une fois empilé) ; ou Grille + « Fusion colonnes » sur l'enfant | |
| Éléments en ligne qui passent à la ligne | Groupe en Rangée, « Autoriser le passage sur plusieurs lignes », largeur Fixe des enfants pour provoquer la bascule | la Rangée par défaut (`flexWrap: nowrap`) ne passe jamais à la ligne |
| Pile verticale | Groupe en Empilement | 1 / 1 / 1 |
| Jamais empilé, les éléments rétrécissent | Colonnes sans « Empiler sur mobile », Grille mode Manuel sans largeur minimale, Rangée sans retour | 3 par ligne à 390 px |
| Tuiles à leur taille desktop, défilement horizontal au doigt | Bloc de défilement du thème ou d'une extension, s'il en existe un | |
| Carrousel avec flèches ou points, N par écran | Bloc carrousel du thème ou d'une extension, s'il en existe un | |

Les deux dernières lignes n'existent pas dans le cœur. Les trouver dans `inventory.php` (titre, catégorie, mots-clés, `description` avec `block=<nom>`), puis lire leur usage dans le manuel du thème : un carrousel a souvent un effet par défaut qui n'affiche qu'une diapositive, quel que soit le nombre par vue. Sans manuel, ouvrir le bloc dans l'éditeur et lire ses réglages, jamais deviner d'après ses attributs.

Chaque liste de tuiles de la maquette doit dire laquelle des trois elle est : grille qui s'empile, défilement, carrousel. Le plus fréquent pour une liste de cartes en mobile est le défilement ; c'est aussi le choix qui coûte le plus s'il est découvert après coup.

## 2. Composition synchronisée, blocs, ou bloc du thème

| Situation | Choix |
|---|---|
| Section répétée à l'identique sur plusieurs pages, dont seul le texte, l'image ou le lien change | Composition synchronisée avec surcharges : ne marche que si chaque champ variable est un attribut listé `overridable` dans `inventory.php` (contenu de quelques blocs). Une couleur, une mise en page ou un réglage qui varie d'une page à l'autre interdit la composition synchronisée |
| Section répétée comme point de départ, puis modifiée librement | Composition non synchronisée (ou composition du thème) : copiée à l'insertion, plus aucun lien |
| Section unique | Blocs posés dans la page |
| Comportement qu'aucun bloc du cœur n'a (défilement, carrousel, formulaire, carte) et qu'un bloc du thème fournit | Le bloc du thème, réglé par son inspecteur, d'après son manuel |
| Comportement qu'aucun bloc insérable n'a | Écart : à signaler, pas à coder dans la page |

Un bloc listé `insertable: only inside: X` ne se pose que dans X ; `not_listed` dit pourquoi un bloc enregistré n'est pas proposé (non autorisé, `inserter:false`). Une composition synchronisée se pose par l'onglet Compositions, pas par l'inséreur de blocs.

## 3. Que des presets

L'ordre de préférence est la règle des crans de `SKILL.md` ; ici, comment l'appliquer à une valeur de maquette :

1. Chercher le preset de même famille dans `presets.php` (`presets.<famille>`, seulement `offered: true`).
2. Taille fluide : le preset dont le maximum (valeur desktop) est le plus proche de la taille desktop de la maquette ; si la maquette donne aussi la taille mobile, départager par le minimum le plus proche. Plusieurs plages contiennent souvent la même valeur : la plage seule ne décide pas. Valeur fixe : égalité, sinon le voisin le plus proche. Le décalage (desktop et mobile) est noté dans le rapport ; aucune approximation silencieuse.
3. Preset dont la valeur est une variable du thème ou un `clamp()` sans plage lisible : lire la définition de la variable dans la feuille du thème ou mesurer la valeur calculée au front aux bornes d'écran du site, puis choisir ; à défaut, par rôle (« espacement de section », « gouttière »), jamais par égalité de valeur.
4. Famille sans preset dans le cœur (hauteur de ligne, espacement des lettres, épaisseur et style de bordure) : valeur libre acceptée, posée de préférence en défaut de bloc ou d'élément dans les Styles globaux.
5. Aucun cran voisin acceptable : écart rendu à l'utilisateur, avec ce que la règle des crans permet (ajout en administration là où c'est créable, sinon décision de code).

`free_values` de `presets.php` dit ce que l'éditeur laisse taper hors presets (`color.custom`, `typography.customFontSize`, `spacing.customSpacingSize`) : qu'il le permette ne rend pas la valeur libre acceptable.

## 4. Défaut global ou réglage d'instance (règle des répétitions)

Lancer `audit-presets.php` sur l'ensemble des pages concernées (`type:page`) et lire `repetitions` (bloc, propriété, valeur, part des instances) :

| Constat | Où la valeur doit vivre |
|---|---|
| Même valeur sur la plupart des instances d'un bloc (`share` élevé) | Défaut du bloc dans les Styles globaux ; puis retirer la valeur des instances (elles deviennent `redundant`) |
| Même valeur sur toutes les instances d'un élément (titres d'un niveau, boutons, liens) | Défaut d'élément dans les Styles globaux |
| Une combinaison de plusieurs propriétés répétée sur un sous-ensemble | Style (variation) du bloc s'il en existe un à modifier dans les Styles globaux ; sinon composition ; créer une variation demande du code (écart) |
| Valeur propre à une instance | Réglage de l'instance |

Un défaut global ne s'applique qu'aux instances qui ne portent pas la valeur : une instance qui la porte déjà reste figée tant qu'on ne l'a pas retirée. Une couleur de texte posée sur un conteneur dans les Styles globaux n'atteint pas forcément ses textes : vérifier au front (piège de spécificité, [pieges.md](pieges.md)).

## 5. Largeurs

Le cœur a deux largeurs globales : contenu et large (`layout` de `presets.php`), plus pleine largeur. Une section de la maquette prend l'une des deux (règle des crans). Une troisième largeur s'obtient par un preset de dimension si le site en sert (`presets.dimension`), sinon par la « Largeur du contenu » d'un Groupe, valeur figée dans ce bloc : écart à signaler, jamais une habitude.
