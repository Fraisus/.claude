---
name: seo-launch
description: "Stratégie SEO & contenu pour acquérir du trafic organique DURABLE pour un produit logiciel (plugin WordPress, thème, SaaS prosumer/B2B) : recherche de mots-clés intent-first, architecture topic-clusters + money pages, SEO technique on-page (Core Web Vitals, données structurées), E-E-A-T pour éditeur inconnu, et AEO (être cité par les moteurs IA / AI Overviews). À utiliser quand on demande une stratégie SEO, du contenu/blog pour ranker, des mots-clés, une page 'alternative à X' / comparatif, du référencement, de l'acquisition organique, de l'optimisation pour Google ou les réponses IA, un plan de lancement SEO. Distinct de copywriting-landing (le message de conversion) et de paid-ads (l'acquisition payante). NOT le classement dans le moteur de recherche interne du dépôt WordPress.org (readme.txt/tags/active installs/discoverability sur le repo) → wporg-readme-optimizer ; seo-launch = Google/web/AEO uniquement."
---

# seo-launch — trafic organique durable pour un produit logiciel

Acquérir du trafic organique **durable** pour un plugin WP / thème / SaaS, via SEO + contenu. Complémentaire : [[copywriting-landing]] (convertir le trafic), [[paid-ads]] (accélérer), [[pricing-strategist]]/[[marketing-psychology]] (offre & persuasion).

> **RÈGLE NON NÉGOCIABLE — intent-first, valeur-first, jamais volume-first.** On part de l'**intention** (le job-to-be-done de la requête), pas du volume. Et chaque page doit apporter une **valeur unique vérifiable** — sinon ne pas la créer. Le volume de contenu est un **facteur de risque** (scaled content abuse), jamais un objectif.

> Source des seuils, types de schema et politiques Google : doc Google Search Central. Les chiffres de gain (« +40 % trafic ») sont des **ordres de grandeur**, jamais des promesses.

## 0. Avant d'agir
1. **Découvrir** : le produit, l'audience (technique ?), l'autorité actuelle du domaine (jeune/sans backlinks → longue traîne + KD basse obligatoires), la présence wordpress.org existante.
2. **Cadrer l'objectif** : trafic de découverte (blog/cluster) vs conversion (money pages) — les deux se nourrissent.

## 1. Recherche de mots-clés (intent-first)
Cartographier d'abord les **intentions**, filtrer ensuite par volume/difficulté.
- **4 intentions** : informational (~70 %, haut funnel/blog), **commercial (~22 %, la plus rentable pour du logiciel)**, navigational, transactional (~1 %, la money page).
- **Mots-clés produit prioritaires** (valeur ↓) : `alternative à [concurrent]` · `best [catégorie] plugin for WordPress` · `[vous] vs [concurrent]` · `[concurrent] review` · longue traîne (questions, cas d'usage).
- **Site jeune** : viser KD basse + longue traîne (~70 % du trafic total, seule traîne réaliste sans autorité).
- Détail méthode, outils, croisement avec citation IA : `references/keyword-intent.md`.

## 2. Architecture de contenu
**Topic clusters (hub-and-spoke)**, pas silos rigides :
- **Pillar page** = sujet large, autorité canonique, lie vers chaque cluster.
- **Cluster pages** = un sous-sujet chacune, **lient vers le pillar** (ancre = mot-clé du pillar). Le maillage interne transmet l'autorité topique.
- **Money pages** (home, produit, pricing, « alternative à X », comparatifs) = cœur commercial/transactionnel, irriguées par le blog. Pour un plugin, les **comparatifs/alternatives** sont le filon le plus rentable.
- **Avant d'écrire les pages « alternative à X »/« vs X »** : identifier les concurrents réels (via l'agent `competitive-analyst` ou la connaissance du marché) — une page « alternative à » qui cible le mauvais concurrent est inutile.
- **Séquencement sur site jeune** : commencer par les **money pages comparatives** (conversion immédiate + très citées par les IA), puis le **pillar**, puis les **clusters**. Ne pas attendre d'avoir tout le blog pour publier les pages qui vendent.
- Détail + structure URL : `references/content-architecture.md`.

## 3. SEO technique on-page
- **Core Web Vitals** (seuils confirmés web.dev, 75e percentile) : **LCP ≤ 2,5 s · INP ≤ 200 ms · CLS ≤ 0,1**. INP a remplacé FID (mars 2024) et est la CWV la plus souvent échouée → point de vigilance n°1. (Ne pas encoder le mythe « LCP 2,0 s » non confirmé.)
- **Données structurées** : `SoftwareApplication` + `Organization` + `Product`/`Review` (sous règles qualité) + `BreadcrumbList`. **FAQPage et HowTo sont MORTS** comme rich results (FAQ retiré 2026) → garder éventuellement le markup pour la compréhension machine, **jamais** compter dessus pour un snippet.
- Title/Hn propres, meta description **pour le CTR** (pas un facteur de ranking, réécrite ~70 % du temps par Google), sitemap, canonical, HTTPS.
- Détail + checklist : `references/technical-seo.md`.

## 4. AEO — être cité par les moteurs IA
Le zero-click est la norme (~80 % sur requêtes avec AI Overview). Objectif nouveau : **être la source citée** (AI Overviews, ChatGPT, Perplexity, Claude).
- **SEO classique = prérequis de l'AEO** (les IA puisent dans l'index Google) — l'AEO s'ajoute, ne remplace pas.
- Leviers : réponses **directes/extractibles** (Q→R, listes, tableaux, définition en tête de section), **données originales chiffrées**, structure Hn, **mentions d'entité hors-site** (annuaires logiciels, comparatifs).
- **`llms.txt` n'aide ni le ranking ni la visibilité IA** (position Google + crawlers qui l'ignorent, 2026) — optionnel, ne jamais le vendre comme un levier.
- Détail : `references/aeo-ai-overviews.md`.

## 5. E-E-A-T pour un éditeur inconnu
Le 1er **E (Experience)** est le moat anti-IA. Concrètement :
- **Pages auteur structurées** (bio, credentials vérifiables, balisage `Person`, byline cohérente).
- **Expérience de première main** injectée : captures réelles du produit, chiffres d'usage, « ce qui a cassé quand… » — ce qu'un non-praticien ne pourrait écrire.
- **Trust site-level** : HTTPS, entité business transparente, contact réel, mentions légales, changelog. Entité claire (`Organization` + sameAs vers GitHub/profil wordpress.org).

## 6. Spécifique plugin / wordpress.org
- La page **wordpress.org** est un actif SEO (autorité de domaine élevée) et un signal d'entité ; son optimisation relève de `wporg-readme-optimizer`.
- **Ne pas copier le readme sur le site** (dilution + canonicalisation vers wordpress.org qui a plus d'autorité) → réécrire la copy du site.
- **Backlinks** (ordre d'efficacité) : annuaires/sites de review logiciels > **linkable assets** (données propriétaires, outil gratuit, guide définitif) > partenariats d'intégration (« works with X ») > digital PR > guest posting **ciblé**. Jamais d'achat/échange de liens.

## 7. Court terme vs long terme
SEO = durable mais lent. L'articuler avec [[paid-ads]] (valider un angle/headline vite, retargeting) et [[copywriting-landing]] (convertir). La donnée des mots-clés qui convertissent en payant oriente le contenu SEO.

## Anti-patterns à REFUSER (sourcés, cf. `references/technical-seo.md`)
- **Contenu en masse pour la couverture/le volume** (IA ou template) = scaled content abuse, désormais algorithmique (pertes 60-90 %). **Programmatic SEO** uniquement si valeur réellement unique par page (≥ ~40 % d'unicité) ; sinon = doorway pages.
- **Densité de mots-clés / keyword stuffing / exact-match en masse** = mort (confirmé Google), bascule en spam.
- **Acheter/échanger des liens, guest posts/PBN en masse** = cible des link spam updates → action manuelle.
- Promettre que **schema / meta description / EMD** améliorent le ranking = faux.
- Traiter le SEO comme **canal de trafic suffisant** en ignorant zero-click/AEO = stratégie 2018.
- Contenu **impersonnel sans expérience de première main** = déclassé par les helpful content / core updates.

## Références (chargées à la demande)
- `references/keyword-intent.md` — méthode intent-first, intentions, mots-clés produit, outils, longue traîne.
- `references/content-architecture.md` — topic clusters, pillar/cluster, maillage, money pages, comparatifs, URL.
- `references/technical-seo.md` — Core Web Vitals, schema (vivant vs mort), on-page, + anti-patterns/pénalités détaillés.
- `references/aeo-ai-overviews.md` — zero-click, citation IA, leviers AEO, llms.txt, sources.
