---
name: copywriting-landing
description: "Rédige et structure le MESSAGE d'une page de vente qui convertit pour un produit logiciel (plugin WordPress, thème, SaaS prosumer/B2B) : headline above-the-fold, ordre des sections (hero→problème→solution→preuve→pricing→CTA), copy des fonctionnalités (FAB), microcopy & CTA, traitement des objections. Choisit le bon framework selon la conscience de l'audience (PAS / PASTOR / AIDA). À utiliser quand on demande d'écrire/structurer/améliorer une landing, page de vente, page produit, hero, headline, accroche, CTA, argumentaire, copy pour vendre un plugin/SaaS/thème. Distinct de design-auditor (qui AUDITE le rendu), de marketing-psychology (les biais sous-jacents) et de pricing-strategist (le niveau de prix). NOT la microcopy/CTA d'upgrade affichée DANS l'éditeur Gutenberg → in-product-upgrade-prompts ; NOT les emails de conversion → email-lifecycle ; NOT le readme wordpress.org → wporg-readme-optimizer."
---

# copywriting-landing — message de vente qui convertit (logiciel)

Produire le **message** d'une page de vente pour un produit de code (plugin WP, thème, SaaS prosumer/B2B). Ce skill rédige et structure le **copy** ; il ne dessine pas le rendu (→ [[design-auditor]] audite l'UI/a11y/dark patterns) et ne fixe pas les prix (→ [[pricing-strategist]]). Les biais qui sous-tendent le copy = [[marketing-psychology]] (catalogue). La mécanique d'offre (trial, coupon, checkout) relève de la plateforme de facturation du projet : [[freemius]] quand c'est Freemius.

> **Recherche audience avant framework.** 60-80 % d'une bonne page = connaître l'audience (le job-to-be-done, le niveau de conscience du problème, l'objection #1, le vocabulaire réel). Le framework n'est qu'un squelette. Sans données audience, **demander** (ou lire `.claude/product-marketing-context.md` s'il existe) — ne jamais générer une page « générique ».

> **RÈGLE NON NÉGOCIABLE — aucune preuve fabriquée.** Jamais de témoignage, logo, chiffre d'usage, note ou avis inventé. Jamais de fausse urgence/rareté. C'est **illégal en UE** (DSA art. 25) et **aux USA** (FTC fake-reviews rule, pénalité civile par violation, montant révisé chaque année par la FTC), inclut le contenu IA. Insérer des placeholders explicites `[témoignage réel — à brancher]` et indiquer la source de vraie donnée (note wordpress.org, G2, installs actives). Détail légal : `references/legal-ethics.md`.

## 0. Avant d'écrire
1. **Découvrir le produit & l'audience** : que fait le produit, pour qui, le job-to-be-done, l'objection principale, le différenciateur réel, le canal (site propre vs page repo wordpress.org). Lire `.claude/product-marketing-context.md` si présent.
2. **Niveau de conscience** (détermine le framework) : l'audience *cherche déjà* une solution (problem-aware — cas le plus fréquent pour un plugin/SaaS) ou doit être éduquée ?
3. **Public technique ?** Des devs achetant un plugin **veulent les specs** (compat PHP, 0 JS au front, hooks, perf). Pour eux, *feature = bénéfice* : lier les deux, ne jamais masquer les features (nuance clé, cf. §3).

## 1. Choisir le framework (appariement, pas dogme)
| Framework | Quand | Portée |
|---|---|---|
| **PAS** (Problem-Agitate-Solution) | Audience problem-aware, page courte/mid. **Défaut SaaS/plugin.** | squelette de page |
| **PASTOR** | Page longue, ticket élevé, beaucoup d'objections à lever. | squelette de page |
| **AIDA** | Audience à éduquer (ignore le problème). Fallback structurant. | squelette de page |
| **FAB** (Feature→Advantage→Benefit) | Section fonctionnalités — **toujours**. | section |
| **BAB** (Before-After-Bridge) | Micro-pattern « avant/après » dans le hero ou une section transformation. | micro |
| **4U** (Useful/Urgent/Unique/Ultra-specific) | Grille de **validation** de la headline. | checklist |

En 2026 on **combine** : PAS/PASTOR au niveau page + FAB sur les features + BAB en micro-pattern. Détail & exemples : `references/frameworks.md`.

## 2. Structure canonique (ordre par défaut)
1. **Hero** — H1 bénéfice + sous-titre + **1 seul** CTA + visuel produit réel + preuve sociale légère
2. **Barre de preuve** — logos « Utilisé par » / note agrégée / compteur d'usage
3. **Problème** (PAS) — articuler la douleur du visiteur
4. **Solution / Comment ça marche** — 3 étapes max, démo visuelle
5. **Bénéfices** — outcomes
6. **Fonctionnalités** — en **FAB**, jamais en liste brute
7. **Preuve approfondie** — témoignages, études de cas chiffrées
8. **Objections / FAQ**
9. **Pricing** — preuve sociale **collée** aux tiers + garantie (→ niveaux & anchoring : [[pricing-strategist]])
10. **CTA final** + **garantie / réducteur de friction**

Règles : preuve **au moment de décision** (pricing/sign-up), pas seulement en haut/footer. Détail section par section + templates : `references/page-structure.md`.

## 3. Headline & copy — règles dures
- **Clarté > cleverness.** H1 ≤ ~8 mots, bénéfice/outcome concret, passe le **5-second test**. Bannir jeux de mots et « vision ».
- **Jargon — critère de tranchage** (clé pour un public technique) : garder le jargon **précis attendu par l'audience** (ex. `LCP`, `Style Engine`, `block supports`, `hooks` pour des devs — c'est leur vocabulaire et un signal de crédibilité) ; bannir le jargon **vide/corporate** (« rapide », « flexible », « puissant », « solution innovante »). « Zéro jargon » vise le second, jamais le premier.
- **Feature → Advantage → Benefit** sur chaque fonctionnalité. **Public technique : garder les specs** et les relier à ce qu'elles débloquent.
- **CTA** : verbe + bénéfice, 1re personne par défaut (« Démarrer mon essai »), réducteur de friction juste dessous (« Sans carte bancaire », « Annulable à tout moment »). **Un seul CTA dominant** par section. Bannir « Envoyer », « Cliquez ici », « Acheter ».
- **Spécificité** : un résultat chiffré bat une promesse vague.
- Exemples avant/après, microcopy, formulaires : `references/headlines-cta.md`.

## 4. Spécifique plugin / freemium
- **Deux registres distincts** : (a) page repo **wordpress.org** = sobre, crédibilité + découverte (note, installs, screenshots, FAQ), **pas** de pricing agressif (politiques du repo) → renvoie vers (b) **site propre** = vraie page de vente/pricing avec tout l'arsenal conversion.
- **Hiérarchie de CTA freemium** : payant > trial avec carte > trial sans carte > gratuit. **Dé-emphasiser le gratuit** sur la page de vente (sinon il cannibalise le payant).
- Preuve foule pour plugin WP = **note + nb d'avis wordpress.org + installs actives**. Détail : `references/page-structure.md`.

## 5. Anti-patterns à REFUSER (sourcés, cf. `references/legal-ethics.md`)
- **Fausse urgence/rareté, faux social proof, avis inventés ou IA** → illégal UE/USA. Le piège n°1.
- H1 feature-centric / jargon / clever ; **carrousel/slider auto** dans le hero (anti-conversion).
- CTA multiples concurrents ; features en liste brute (sans FAB).
- Règles dogmatiques assénées comme lois : « CTA above-the-fold uniquement », « bouton rouge/orange convertit mieux », « toujours du long-form », « jamais de features ». Ce sont des **mythes/cargo-cult** → présenter les choix comme des **hypothèses à A/B-tester** sur l'audience réelle.
- Pop-ups intrusifs / exit-intent par défaut (pénalité interstitiel Google mobile, zone grise dark pattern UE).
- Génération de pages IA en masse / perso par profilage non consenti (scaled-content-abuse Google + GDPR/Digital Fairness Act).

## Métriques & chiffres
Les gains cités dans la littérature (+202 % CTA perso, +266 % offres multiples, etc.) sont des **ordres de grandeur agence**, pas des garanties. Les **encoder comme directions à tester**, jamais comme promesses. Les principes structurels (1 CTA dominant, bénéfice>feature, clarté>cleverness, PAS pour problem-aware, FAB, preuve au moment de décision, garantie 30 j) sont, eux, **consensus** → règles dures.

## Références (chargées à la demande)
- `references/frameworks.md` — PAS/PASTOR/AIDA/FAB/BAB/4U : quand, comment, exemples appliqués au logiciel.
- `references/page-structure.md` — chaque section détaillée + templates de page du site propre + freemium.
- `references/headlines-cta.md` — recettes de headlines, 5-second test, microcopy, CTA, formulaires, exemples avant/après.
- `references/legal-ethics.md` — DSA / FTC / Digital Fairness Act / GDPR : ce qui est interdit, mythes CRO réfutés, frontière copy persuasif vs dark pattern.
