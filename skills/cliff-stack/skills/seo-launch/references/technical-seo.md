# SEO technique on-page + anti-patterns/pénalités

## Core Web Vitals (seuils confirmés web.dev, 75e percentile, mobile & desktop séparés)
| Métrique | Good | À surveiller | Poor |
|---|---|---|---|
| **LCP** (chargement) | ≤ 2,5 s | 2,5-4 s | > 4 s |
| **INP** (réactivité) | ≤ 200 ms | 200-500 ms | > 500 ms |
| **CLS** (stabilité) | ≤ 0,1 | 0,1-0,25 | > 0,25 |

- **INP** a remplacé FID le 12 mars 2024. C'est la CWV **la plus souvent échouée** (~43 % des sites) → point de vigilance n°1.
- Mythe à ne pas encoder : « Google a resserré le LCP à 2,0 s » → **non confirmé** par web.dev, le seuil reste 2,5 s.
- Causes CLS fréquentes : images/embeds/ads sans dimensions réservées.

## Données structurées — vivant vs mort (2026)
**Encore des rich results** : `Product`, `Review`/`AggregateRating` (règles qualité durcies, avis authentiques), `Article`, `Organization`, `BreadcrumbList`, `Video`, `LocalBusiness`.
**MORTS** (plus de rich result) : `FAQPage` (retiré 2026), `HowTo` (retiré 2023-2025), + 7 autres types retirés juin 2025.

**Pour un éditeur logiciel** : `SoftwareApplication` (nom, OS, catégorie, `offers`/prix, `aggregateRating`) + `Organization` (entité, sameAs) + `BreadcrumbList`.

> Le schema **n'est pas un facteur de ranking direct**. Il aide la compréhension machine / la citation IA. « Garantit les rich snippets » = faux. Garder FAQ markup uniquement pour la machine-readability, jamais pour un snippet.

## On-page
- 1 seul **H1**, hiérarchie Hn propre.
- **Title** : porte l'intention + le terme, sans bourrage.
- **Meta description** : pas un facteur de ranking, influe sur le CTR, **réécrite ~70 % du temps** par Google (étude Portent). Rédiger pour le clic, sans illusion.
- **Meta keywords** : totalement obsolètes, ignorées.
- Sitemap XML à jour, canonical propres, robots.txt sain, HTTPS.

## Anti-patterns & pénalités (sourcés)
- **Scaled content abuse** (politique spam 2024, algorithmique 2025-2026) : beaucoup de pages à faible valeur pour manipuler le ranking → pertes 60-90 %. Critère = **volume sans valeur proportionnelle** (l'IA n'est pas pénalisée en soi ; le contenu IA non revu publié en masse l'est). Seuil de risque évoqué : < 30-40 % d'unicité réelle.
- **Programmatic SEO** : seulement si chaque page a une **valeur unique vérifiable** (donnée réelle, FAQ spécifique). Sinon = doorway pages démotées par SpamBrain.
- **Site reputation abuse / parasite SEO** : pénalisé (Forbes/WSJ/CNN nov. 2024), algorithmique août 2025.
- **Link schemes** : achat/échange de liens, guest posts/PBN en masse → link spam updates juin/déc. 2024, action manuelle dure à lever.
- **Keyword density / stuffing / exact-match en masse** : facteur mort depuis >10 ans (confirmé Mueller), bascule en spam.
- **Helpful content** : contenu exhaustif mais impersonnel, sans expérience de première main → déclassé.
- Cargo cult à bannir : LSI keywords « magiques », EMD comme levier, schema = boost ranking, « publier tous les jours » pour la fréquence, mythe de la « pénalité duplicate content » automatique (c'est dilution/canonicalisation, pas une sanction).

## Sources
web.dev (INP/LCP/CLS) · Google Search Central (FAQPage/HowTo, helpful content) · DigitalApplied (scaled content abuse, programmatic après mars 2026) · Rankability/Invenio (densité, Mueller) · SearchEngineLand/Journal (meta, parasite SEO) · BuzzStream/Ahrefs (link spam).
