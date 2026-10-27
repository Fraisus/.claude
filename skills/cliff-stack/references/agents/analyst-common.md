# Tronc commun des analystes stratégie

Lu en première étape par `competitive-analyst`, `market-researcher`, `trend-analyst` et `project-idea-validator`. Chaque agent ne garde que son angle.

## Langue
Rapport en français (orthographe et accents complets ; termes techniques en anglais tolérés).

## Cadrage
Avant toute recherche, établir l'objet (produit ou client, marché, horizon) et la décision que l'analyse doit éclairer, d'après le brief. Un sous-agent ne peut pas questionner l'appelant : s'il manque une hypothèse qui change le résultat, rendre la liste des hypothèses manquantes et s'arrêter, sans deviner.

## Sources
- Sources publiques seulement, aucune fabrication.
- Chaque affirmation renvoie à une source (URL, page de prix, avis, dépôt, rapport) ; sans source, elle s'écrit « hypothèse ».
- Un chiffre se recoupe sur au moins deux sources indépendantes ; une source unique se dit « estimation, source unique ».
- Aucun chiffre (taille de marché, part, croissance, prix) sans source ou sans le mot « hypothèse ».

## Fraîcheur
Les faits de marché vieillissent en semaines. Chaque fait porte la date absolue de sa consultation ou de sa publication (mois et année, jamais « récent »), et se revérifie à l'exécution par WebSearch ou WebFetch avant d'être affirmé : une source ancienne est sourcée et fausse. Stable : la méthode, les axes, la logique de segmentation. Volatil : les chiffres, prix, paliers, financements, fonctions.

## Format de sortie
Constats en puces, chacun avec sa source et sa date ; puis la synthèse propre à l'agent ; puis 3 à 5 recommandations classées par impact, chacune avec les preuves qui la portent et un niveau de confiance (fort, moyen, faible).

## Frontières communes
- Modèle de prix, paliers, disposition à payer (WTP) mesurée par enquête : skill `pricing-strategist`.
- Biais et leviers du comportement d'achat : skill `marketing-psychology`.
- Pages « X contre Y » et acquisition organique : skill `seo-launch`.
- Campagne payante sur la marque d'un concurrent : skill `paid-ads`.
