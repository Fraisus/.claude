---
name: wp-blocs-maquette
description: "Pre-sets a block-theme WordPress site's Global Styles from a UI kit, then builds pages from a mockup by stacking blocks, block settings, patterns and Global Styles, with no new code and everything editable by an administrator. Bundled scripts: read the site (insertable blocks and their settings, served presets and styles, presets-only audit of a page, grid gap probe), write Global Styles from a theme.json file (dry run by default, backup, core sanitize, read-back), lint block markup. Picks each block from its mobile behaviour, each value from existing presets. Use when the user asks, on a block-theme WordPress project, to build or audit a page from a mockup or to apply a kit to Global Styles: « monte cette page en blocs », « quel bloc pour cette section », « que des presets », « audit presets », « règle les Styles globaux depuis le kit », « valide ce balisage de blocs ». NOT for the kit method (ui-kit), coding (wp-native), a11y audit (design-auditor), or the mockup (impeccable)."
---

# Pré-régler un site WordPress et construire ses pages en blocs

But : un site dont les Styles globaux portent le kit, puis des pages qui ressemblent à la maquette, faites uniquement de blocs insérables, de leurs réglages, de compositions et des Styles globaux, que l'administrateur peut ensuite modifier sans développeur. Rien de nouveau dans le code du thème ou d'une extension : ce qu'aucun réglage n'offre est un écart à signaler et à faire trancher (retoucher la maquette, ou demander une fonction à `wp-native`), jamais du CSS glissé dans une classe.

Ce skill est générique : il ne connaît aucun bloc d'un thème précis. Tout ce qui est propre au site se découvre par les scripts, et l'usage des blocs du thème se lit dans le manuel du thème quand il en a un (`inventory.php` liste ses dossiers de documentation candidats).

## Règle des crans (vaut pour tout réglage du site)

Les presets du site (couleurs, tailles de police, polices, espacements, rayons, ombres, largeurs) sont des crans. Pour chaque valeur de la maquette ou du kit, dans cet ordre :

1. **Le preset existant** de même rôle. Une taille fluide prend le preset dont le maximum `fluid` (`presets.php`) est le plus proche de la taille desktop de la maquette, le minimum départageant ([reference/decision.md](reference/decision.md) § 3) ; une couleur, dans la palette ; une police, parmi les familles déjà servies ; une largeur, contenu ou large.
2. **Décaler la maquette vers le cran voisin** quand l'écart est acceptable, et noter le décalage dans le rapport.
3. **Ajouter un preset en administration** seulement là où l'administration sait le créer (couleur, taille de police, police installée par la Bibliothèque de polices, ombre) et quand aucun cran voisin n'est acceptable : décision de kit, dite à l'utilisateur. Changer la valeur d'une couleur de la palette ou d'une des deux largeurs relève du même cas : application du kit au site entier, sur option explicite (`allow_modify=1`), jamais pour une seule maquette.
4. **Jamais** un cran modifié pour un seul usage, un cran créé là où l'administration ne le permet pas (échelle d'espacement, rayons, troisième largeur : c'est du code), ni une variation de style nouvelle pour un seul usage.

Une valeur hors preset posée dans un bloc y est figée : changer l'échelle plus tard ne l'atteindra pas. Une valeur sans preset possible (hauteur de ligne, espacement des lettres) se pose une fois en défaut de bloc ou d'élément dans les Styles globaux.

## Autres invariants

- **Lire le site avant d'écrire**, par les scripts, sur le site réel, à chaque chantier. Jamais un catalogue recopié ni la mémoire d'un autre site.
- **Le bloc se choisit d'après ce que la section devient en mobile**, pas d'après le nombre de colonnes desktop : [reference/decision.md](reference/decision.md).
- **Défaut global d'abord, réglage d'instance ensuite** (règle des répétitions, même référence).
- **Rien d'écrit sans contrôle, rien de modifié sans sauvegarde, rien de prouvé hors du vrai éditeur et du front.**

## Méthode

Scripts en `scripts/`, tous lancés par `wp eval-file` depuis la racine du site, avec le PHP et le WP-CLI du projet (jamais `php` nu). Options en mots `clé=valeur` : [reference/lecture.md](reference/lecture.md).

1. **Lire le site.** Run `presets.php out=<f> flat=<f>` (presets offerts et servis avec leurs plages, valeurs libres permises, deux largeurs, bornes d'écran, survols permis, Styles globaux à plat, couche de l'administrateur), `inventory.php out=<f>` (blocs insérables, réglages offerts ou coupés, attributs ajoutés, styles, attributs surchargeables), `audit-presets.php type:page` (comment le site est réellement construit, ce qui devrait monter en défaut), `grid-gap-probe.php` si la maquette a des grilles à colonnes et largeur minimale.
2. **Pré-régler les Styles globaux depuis le kit** (une fois par site, avant toute page) : [reference/styles-globaux.md](reference/styles-globaux.md). Le kit est traduit en un `theme.json` d'entrée selon la règle des crans ; `global-styles.php` le passe en essai à blanc, l'utilisateur valide le diff, puis l'écriture se fait avec sauvegarde et relecture.
3. **Découper la maquette en sections** et écrire, pour chacune, son comportement en tablette et en mobile, puis choisir bloc et réglages : [reference/decision.md](reference/decision.md). Chaque liste de tuiles dit ce qu'elle est : grille qui s'empile, défilement, carrousel.
4. **Rendre l'inventaire des écarts** avant toute écriture de page : chaque valeur qui change de cran (et de combien), chaque effet sans réglage (survol, pseudo-élément décoratif, troisième largeur, zones de grille inégales). Ne pas les combler en silence.
5. **Écrire la page** en brouillon : markup dans un fichier, `wp-block-lint.php` à exit 0, `wp post create|update` depuis le fichier, sauvegarde du contenu précédent : [reference/ecriture.md](reference/ecriture.md).
6. **Relire par le script** : `audit-presets.php <id>` rend zéro `hard_values`, `dead_presets` et `redundant` hors écarts acceptés.
7. **Prouver dans le vrai éditeur et au front** par `testing-real-ux` : ouverture sans bloc invalide, vrai enregistrement, rendu aux bornes d'écran du site conforme au comportement écrit à l'étape 3.

## Pièges qui coûtent le plus

Détail, preuves et contournements : [reference/pieges.md](reference/pieges.md).

- Une grille du cœur a des pistes égales : zones inégales par fusion de colonnes sur l'enfant ; la colonne de départ n'est pas offerte dans l'interface.
- Colonnes « Empiler sur mobile » empile sous 782 px, tablette comprise, seuil fixe du cœur.
- Une grille en mode Manuel sans largeur minimale ne s'empile jamais.
- Un bloc ne porte qu'un style à la fois ; en créer un nouveau demande du code.
- Le changement de couleur au survol n'est réglable que là où `presets.php` le liste (`hover`).
- Une composition synchronisée ne surcharge par instance que les attributs `overridable` d'`inventory.php`.
- Un réglage global peut être enregistré et sans effet au front (spécificité d'une règle du thème) : toujours vérifier la propriété calculée.

## Hors de ce skill

La méthode du kit et ses jetons : `ui-kit` (ce skill en reçoit les décisions). Coder un bloc, un réglage, un preset ou une variation : `wp-native`. Dessiner ou critiquer la maquette : `impeccable`. Audit d'accessibilité : `design-auditor`. Le détail d'un vrai enregistrement dans l'éditeur : `testing-real-ux`.
