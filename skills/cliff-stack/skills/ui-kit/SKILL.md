---
name: ui-kit
description: "Construit le kit UI/UX complet d'une marque depuis ses sources réelles : audit du site existant par une équipe d'agents, plan de jetons en trois niveaux soumis à framing-critic, échelles OKLCH et contrastes mesurés par script, composants avec aperçus vivants, gabarits de page prouvés par une maquette, publication en artefact Design System et table d'ancrage vers l'architecture du projet. Fournit les scripts : couleur et contraste WCAG, échelles 50 à 950, contrôle et compilation de tokens.json, faces Google Fonts, rendu local des artboards. Use when on demande « crée un kit UI », « design system de la marque », « charte et composants », « refonte UI kit », « kit UI/UX », « tokens et composants de la marque », « bibliothèque de composants pour la refonte ». NE PAS utiliser pour la maquette, le polish ou la critique d'un écran (impeccable), pour un rapport d'audit formel d'accessibilité, de dark patterns ou RGPD (design-auditor), ni pour fabriquer un skill (skill-builder)."
---

# ui-kit : construire le kit UI/UX d'une marque

Ce skill porte seul la méthode du kit. Ses invariants, chacun tenu par une phase :

- **Sources réelles, jamais la mémoire.** Site, dépôt, fichiers de marque. Un kit antérieur, même du même auteur, n'est ni un modèle ni une source ; ce skill ne contient aucun composant, aucune direction ni aucune maquette pré-écrits.
- **Audit d'abord** (phase 1) : ce qui est moderne, ce qui est obsolète, le contenu à garder et la forme à refaire, avant toute décision de jeton.
- **Jetons en trois niveaux nommés** (phase 2) : primitives (échelles brutes), rôles (seuls exposés et seuls lus par les composants), composants (à la seconde divergence réelle seulement).
- **Contraste de chaque rôle mesuré par script** (phase 3) : `scripts/check-tokens.mjs` à 0 échec, dans chaque thème, chaque jeton de couleur pris dans au moins un couple, jamais une estimation.
- **Ancrage** (phases 2 et 6) : chaque jeton et chaque composant nomme le mécanisme de l'architecture du projet qui le portera.
- **Publication en artefact Design System** (phase 6).
- **Gabarits de page prouvés par une maquette** (phase 5) qui suit `impeccable shape` et la règle globale « Maquettes d'interface ».

## Briques à réutiliser avant d'écrire

- Le projet a déjà des jetons ou des composants dans son code : la zone du thème ou code local de l'audit les relève et le plan de jetons les aliase (`reference/tokens.md`, alias vers le projet), sans les réécrire. `impeccable extract`, qui consolide et migre le code du projet, n'intervient qu'à l'implémentation, sur demande.
- `impeccable document` et `impeccable init` écrivent à la racine du projet : seulement si les règles du projet autorisent ces fichiers et que l'utilisateur le demande. Sinon le kit lit ce qui existe (DESIGN.md, PRODUCT.md, règles du projet) comme source.
- Les briques du projet (blocs, gabarits, fonctions de données, points d'extension) relevées par la zone du thème ou code local se réutilisent ; la table d'ancrage les nomme.

## Avant la phase 1

1. Écrire le résultat attendu en une phrase vérifiable, celle que la publication prouvera. Forme : « Un artefact Design System `<nom>` dont chaque rôle de texte tient 4,5:1 sur les fonds que sa note nomme, dans chaque thème (contrôle à 0 échec), dont chaque composant de l'inventaire a un aperçu vivant et une fiche, dont chaque jeton et composant nomme son mécanisme dans le projet, et dont les gabarits `<liste>` sont prouvés par une maquette validée. »
2. Réunir les sources et noter ce qui manque : URL du site, dépôt du projet (thème, configuration des styles, composants existants, règles), logo et fichiers de marque réels, captures ou tickets qui montrent la cible. Logo absent : le nom en texte et l'absence notée, jamais un logo redessiné.
3. Lire les règles du projet (`CLAUDE.md`, `.claude/rules/`) : elles disent où le kit a le droit d'écrire, l'outil de rendu, les conventions de nommage. Le kit vit hors du dépôt (scratchpad, artefacts) tant que l'utilisateur n'a pas demandé l'implémentation.

## Phases et critères de sortie

| Phase | Produit | Sortie vérifiable |
|---|---|---|
| 1. Audit | un rapport par zone retenue, zone sans objet notée avec sa raison, puis une synthèse « moderne, obsolète, contenu à garder » | chaque rapport suit la structure du brief ; chaque constat cite URL, sélecteur ou ligne ; chaque absence donne sa commande et son périmètre |
| 2. Plan de jetons | plan écrit, passé à `framing-critic` | objections tenues traitées dans le plan ; aucune décision de nommage ou d'alias laissée ouverte |
| 3. Jetons | `tokens.json`, fichier de couples, `tokens.css` | `check-tokens.mjs` rend 0 doublon, 0 erreur, 0 échec, avec un couple par rôle de texte et par fond que sa note nomme ; un jeton hors de tout couple seulement avec sa raison écrite (`--allow-unpaired`) |
| 4. Composants | `components/<Comp>/preview.html` et `README.md`, `bundle.css` | inventaire complet construit ; aucune valeur brute hors exceptions listées ; chaque aperçu se charge sans erreur |
| 5. Gabarits | maquette par la méthode d'`impeccable shape`, sur canevas Design | structure choisie par l'utilisateur ; DOM du rendu local lu à trois largeurs ; `impeccable critique` à zéro P0 ou P1 ; haute fidélité validée par l'utilisateur |
| 6. Publication | artefact Design System, table d'ancrage | artefact publié qui s'ouvre ; README en règles d'usage ; table d'ancrage sans ligne vide |

Une phase ne commence qu'à la sortie de la précédente, sauf la 4 et la 5 qui peuvent se chevaucher une fois le plan de la 2 tenu.

### 1. Audit du site existant (fan-out)

Ce skill donne les zones candidates, `equipe` décide de la composition (nombre d'agents, zones regroupées) : charger `equipe` avant de lancer plus de deux agents. Les zones se découpent d'après les familles de pages réellement relevées (plans de site, gabarits) ; la zone du thème ou code local est obligatoire quand un projet existe, la veille conseillée. Un brief commun, des zones disjointes, un rapport par zone retenue au même format. Zones candidates, gabarit du brief et structure des rapports : `reference/audit.md`.

La synthèse classe chaque famille de pages en moderne, obsolète ou mixte, avec sa preuve, et sépare le contenu à garder de la forme à refaire. Elle devient une section du système publié.

### 2. Synthèse et plan de jetons

Le plan fixe, avant tout fichier : les niveaux (primitifs, rôles, composants), la liste des rôles exposés, l'alias de chaque rôle vers ce que le projet a déjà, les axes des directions s'il y en a, l'inventaire des composants et le mécanisme d'ancrage de chacun. Contenu attendu et règles de nommage : `reference/tokens.md`. Le plan passe à `framing-critic` (il crée la frontière entre le kit et le projet) ; une objection tenue fait revoir son seul point.

### 3. Jetons et contrastes

1. Échelles : `node scripts/scale.mjs <couleur d'ancrage> --name <famille>` par couleur de marque, `--chroma` faible pour des neutres teintés. L'ancre reste exacte ; la sortie dit à partir de quel pas un texte tient 4,5:1.
2. Écrire `tokens.json` (format : `reference/design-system-format.md`) et le fichier de couples qui déclare chaque rôle de texte sur chacun de ses fonds, chaque bordure de contrôle, anneau de focus et icône porteuse de sens à 3:1.
3. `node scripts/check-tokens.mjs tokens.json pairs.json` : relancer jusqu'à 0 doublon, 0 erreur, 0 échec. Un échec se corrige dans le rôle, jamais en retouchant la couleur de marque.
4. `node scripts/compile-tokens.mjs tokens.json tokens.css` pour les aperçus et le canevas avant la première sauvegarde de la page.
5. Polices : `node scripts/font-faces.mjs <feuille>.css --download fonts --json` donne les fichiers et les entrées `type.fonts`.

### 4. Composants et aperçus

L'inventaire vient des sources et de l'audit : exactement les familles que la marque et le projet emploient, plus les ajouts intentionnels listés avec leur raison. Le recenser en entier avant d'en construire un ; suivre l'avancement contre cette liste ; s'arrêter en disant ce qui reste, jamais en silence.

Chaque composant ne lit que des rôles. Un jeton de composant n'existe qu'à la seconde divergence réelle (deux directions, deux contextes). Accessibilité de chaque motif interactif d'après l'APG W3C (disclosure, onglets, dialogue natif, `details name`), jamais `role="menu"` pour une navigation. Contrat d'un aperçu : `reference/design-system-format.md`.

### 5. Gabarits de page

Un gabarit se prouve par une maquette dans un canevas Design branché sur le système, selon la règle globale « Maquettes d'interface » : `impeccable shape` (brief et inventaire du contenu réel complet), plans de sections en basse fidélité, un choisi par l'utilisateur, puis la haute fidélité. Vérification de la haute fidélité, dans cet ordre : rendu local du canevas par `scripts/render-local.mjs`, lecture du DOM à trois largeurs, puis `impeccable critique` sur ce rendu jusqu'à zéro défaut P0 ou P1, puis `design-auditor` en passe finale. Ce que le canevas impose et le détail du rendu local : `reference/canvas.md`.

### 6. Publication et ancrage

Publier l'artefact Design System (fichiers, README en règles d'usage, couverture en dernier) : `reference/design-system-format.md`. Puis la table d'ancrage : chaque rôle, chaque échelle et chaque composant vers le mécanisme natif du projet qui le portera, avec les points d'extension nommés : `reference/anchoring.md`. Aucune ligne de code dans le dépôt sans demande.

## Scripts (exécuter, ne pas lire)

Tous en ESM Node sans dépendance, aide en tête de fichier (`--help` ou `-h` l'imprime), entrées en arguments. Chemins depuis ce dossier : `scripts/`. `cli.mjs` (aide) et `tokens-shape.mjs` (forme de `tokens.json`, lue par le contrôle et la compilation) sont des modules partagés, pas des commandes.

| Script | Rôle | Étalonnage |
|---|---|---|
| `color.mjs` | bibliothèque et CLI : OKLCH vers sRGB et retour, contraste WCAG 2, composition alpha, mise au gamut | `node scripts/color.mjs selftest` : #767676 sur blanc 4,54:1, #6B7280 sur blanc 4,83:1 |
| `scale.mjs` | échelle 50 à 950 à teinte fixe depuis une ancre, ratios sur les fonds donnés | étalonne `color.mjs` avant d'écrire |
| `check-tokens.mjs` | forme du fichier, noms, alias, contraste de chaque couple déclaré par thème | idem ; code 0 seulement sans erreur ni échec, 2 si aucun couple ou si un jeton de couleur n'est dans aucun couple (liste « NON mesuré »), sauf `--allow-unpaired` |
| `compile-tokens.mjs` | `tokens.json` vers `tokens.css`, disposition de la page ; refuse la forme que le contrôle refuse | toutes les variables d'un `tokens.css` compilé par la page s'y retrouvent |
| `font-faces.mjs` | faces d'un sous-ensemble d'une feuille Google Fonts, téléchargement, entrées `type.fonts` | une feuille css2 réelle : la face `latin` est celle qui suit le commentaire `/* latin */` |
| `render-local.mjs` | artboards `.dc.html` vers HTML local pour la vérification hors canevas | signale artboard trop haut, insécable dans un attribut, images lazy, blobs non résolus |

## Pièges vécus

- **Un seul dialecte.** La palette exposée est faite de rôles qui sont des alias des slugs et variables déjà servis par le projet. Renommer la palette du projet casse chaque contenu publié qui cite un slug : chercher ces usages avant tout nommage.
- **Le rouge de marque passe rarement 4,5:1 en texte sur blanc.** Deux rôles : `accent` (aplat, icône, focus, texte de 24 px et plus) et `accent-strong` (texte courant, bouton plein à texte clair), mesurés par `scale.mjs`. La couleur de marque reste exacte, seul son rôle se restreint.
- **Une variante qui ne change que l'habillage n'est pas une direction.** Palette, police ou rayon seuls donnent des copies recolorées. Une direction diffère par la structure : modèle de navigation, système de colonnes et de marges, densité, rapport texte et image, place du geste signature.
- **Un lien générique écrase les boutons.** `a { color }` dans une feuille de page bat la couleur d'un bouton rendu en `<a>` dès que sa spécificité égale ou dépasse celle du composant. Écrire les règles génériques sous `:where()` (spécificité nulle).
- **Le canevas Design coupe un artboard au-delà de 8 000 px de haut.** Une page longue se découpe en plusieurs artboards ; la hauteur déclarée suit le contenu.
- **Le type Design interdit la vérification en cours de route.** La preuve passe par `render-local.mjs` puis une lecture du DOM et des styles calculés à plusieurs largeurs, pas par une capture.
- **Espaces insécables au texte seulement.** Un remplacement global les pose aussi dans `viewBox`, `d`, `points` ou une classe, et le SVG ne se dessine plus. Les appliquer aux nœuds texte.
- **Une image lazy reste vide dans une sonde en iframe cachée.** Rendre avec `--eager` ou faire défiler avant de conclure qu'une image manque.
- **Feuille Google Fonts : le libellé du sous-ensemble précède sa face.** Découper la feuille sur `@font-face` et chercher `latin` dans le morceau rend la face précédente (`latin-ext`), qui n'a pas les lettres de base. `font-faces.mjs` lit le bon couple.
- **Une carte nom vers valeur (DTCG) est du JSON valide que la page ne lit pas.** Chaque famille est une liste `{name, value, usage}` ; `check-tokens.mjs` et `compile-tokens.mjs` refusent une carte à toute profondeur, et un identifiant de thème hors `[a-z0-9-]`, dont les valeurs seraient perdues.

## Ce que ce skill ne fait pas

- La maquette, la critique ou le polish d'un écran : `impeccable` (`shape`, `craft`, `critique`, `polish`). Ce skill y renvoie à la phase 5.
- Un DESIGN.md seul, ou l'extraction de motifs vers un système déjà en place, sans kit à construire : `impeccable document`, `impeccable extract`.
- Le rapport d'audit formel d'accessibilité, de dark patterns ou RGPD : `design-auditor`, qui sert aussi de passe finale.
- La composition de l'équipe d'audit : `equipe`. Le mouvement : `animate`.

## Références

- `reference/audit.md` : brief commun, zones candidates, structure d'un rapport, synthèse.
- `reference/tokens.md` : trois niveaux, rôles, alias, échelles, couples, directions.
- `reference/design-system-format.md` : ce que l'artefact Design System exige pour réussir du premier coup.
- `reference/canvas.md` : gabarits sur canevas Design, rendu local, anti-motifs d'une maquette.
- `reference/anchoring.md` : table d'ancrage vers l'architecture du projet.
- `reference/evals.md` : scénarios d'évaluation du skill (maintenance).
