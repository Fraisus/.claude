# Phase 5 : gabarits de page sur canevas Design, vérifiés hors canevas

La méthode d'une maquette est la règle globale « Maquettes d'interface » ; elle commence par `impeccable shape` et la doctrine de mise en page vit dans `impeccable` (`shape`, `layout`, `critique`). Ce fichier ne couvre que ce que le kit ajoute : brancher le canevas sur le système, contourner ses limites, prouver le rendu.

## Sommaire

- Brancher le canevas sur le kit
- Limites du canevas
- Vérifier hors canevas
- Anti-motifs d'une maquette faite trop tôt en haute fidélité

## Brancher le canevas sur le kit

- Le canevas déclare le système publié (copie de `tokens.css` et `bundle.css` sous `ds/<namespace>/`) ; chaque artboard les lie et n'écrit aucune valeur brute : conteneurs, colonne de lecture, rythme et polices viennent des jetons.
- Polices hors kit interdites sur un artboard : une police nouvelle passe d'abord dans le kit (phase 3) ou n'apparaît pas.
- Un gabarit se construit avec les composants du kit. Un besoin qu'aucun composant ne couvre retourne à la phase 4 comme ajout intentionnel, il ne se dessine pas en local dans la page.
- Une direction de structure par artboard, basse fidélité d'abord ; la haute fidélité seulement sur la direction choisie par l'utilisateur. Nom d'une option stable d'un tour à l'autre ; justification et compromis dans la réponse, pas sur l'artboard.

## Limites du canevas

- Hauteur d'un artboard : 8 000 px au plus, coupé au-delà. Une page longue se découpe en artboards consécutifs (ouverture, corps, fin) ou par état. `render-local.mjs` signale chaque artboard déclaré plus haut.
- La hauteur `h` déclarée dans `canvas.json` suit la hauteur réelle du contenu à la largeur `w` : trop court, la page est coupée ; mieux vaut trop haut.
- Une page est fluide (`"expand": "fill"`, conteneur à `max-width`, grilles en `repeat(auto-fit, minmax(min(<min>px, 100%), 1fr))`), jamais une largeur racine fixe ; un héros prend une `min-height` en px ou rem, jamais en `vh`.
- Les propriétés d'artboard (`data-props`) se substituent dans le HTML par `{{clé}}` : un sélecteur de direction ou de largeur passe par là.
- Le type Design interdit de vérifier en cours de route : ni capture ni lecture du rendu depuis le canevas pendant la construction.

## Vérifier hors canevas

1. `node scripts/render-local.mjs <dossier du canevas> <dossier de rendu> --eager [--blobs map.json] [--set direction=<id>]` : HTML local par artboard, runtime retiré, propriétés substituées.
2. Servir le dossier de rendu par un serveur statique sur un port tiré par le système, dans le dossier de séance ; l'arrêter et effacer le rendu à la fin.
3. Lire le DOM et les styles calculés à trois largeurs (téléphone, tablette, bureau) par l'outil de rendu du projet : largeur réelle de la colonne de lecture en caractères, débordements horizontaux, couleur calculée des liens et boutons, police réellement appliquée, images chargées. Une capture seulement si l'aspect visuel est la question, une fois.
4. Signaux de `render-local.mjs` à lever avant de juger : artboard trop haut, espace insécable dans un attribut, `/_blob/` sans fichier local, placeholders sans valeur.
5. `impeccable critique` sur ce rendu local (pas sur le canevas), jusqu'à zéro défaut P0 ou P1 ; `design-auditor` en passe finale.
6. Une cible corrigée par tour ; reporter la correction dans le `.dc.html`, jamais dans le rendu local, puis relancer le rendu.

## Anti-motifs d'une maquette faite trop tôt en haute fidélité

Relevés sur une maquette écrite sans inventaire ni plan de sections, à chercher dans chaque artboard avant de le montrer :

- trois propositions qui sont le même squelette rhabillé (palette, police, rayon) ;
- contenu allégé par rapport à la source : moins de questions, de sections ou de chiffres que la page réelle ;
- tout dans le conteneur large, sans colonne de lecture ni sommaire ;
- espacements bruts et uniformes, colonnes en nombre figé, une seule largeur dessinée ;
- grille de cartes identiques à pastilles d'icônes là où une liste suffit ;
- surtitre en capitales espacées sur chaque section ;
- mot coloré dans un titre, numérotation 01, 02, 03 sans séquence réelle ;
- dégradés de recouvrement, chiffres géants sans source ;
- police chargée hors kit.
