---
name: paid-ads
description: "Produit le PLAN d'acquisition payante pour vendre un produit logiciel (plugin WordPress, thème, SaaS prosumer/B2B) à petit budget : choix de canal (Google Search/Bing d'abord), structure de compte/campagnes, angles créa (RSA, démo), plan budget/enchères (Smart Bidding & seuils), framework de mesure ROAS/CAC/LTV et attribution cookieless (Consent Mode v2). À utiliser quand on demande une stratégie/un plan ads, Google Ads, Meta/Facebook ads, campagne payante, SEA, PPC, retargeting, acquisition payante, ou 'dois-je faire de la pub pour mon plugin/SaaS'. Produit un PLAN (structure, créas, budget, mesure) — il ne pilote PAS une campagne live. Dit honnêtement quand le paid est un piège. Distinct de seo-launch (organique) et copywriting-landing (la page d'atterrissage)."
---

# paid-ads — plan d'acquisition payante (produit logiciel, petit budget)

Produit un **plan** d'acquisition payante : canal, structure de compte, angles créa, plan budget/enchères, framework de mesure. Complémentaire : [[seo-launch]] (organique long terme), [[copywriting-landing]] (la landing qui reçoit le clic), [[pricing-strategist]] (LTV/prix), la plateforme de facturation du projet pour le revenu réel de la mesure ([[freemius]] si c'est elle).

> **Périmètre : un plan, pas un pilotage.** Ce skill ne lance ni n'optimise une campagne live (budget réel, Ads Manager, data de compte = hors de portée d'un LLM). Il produit : structure de compte, angles créa, plan budget/enchères, framework de mesure. Être explicite sur cette limite avec l'utilisateur.

> **RÈGLE NON NÉGOCIABLE — dire quand le paid est un PIÈGE.** Le paid **amplifie** une proposition de valeur qui marche déjà ; il ne la crée pas. Avant tout plan, vérifier l'unit economics (§0). Si le profil ne tient pas, **déconseiller le paid** et renvoyer vers [[seo-launch]] / communauté / wordpress.org / affiliation de la plateforme de facturation du projet, ne pas produire un plan qui brûlera un budget modeste à vide.

## 0. Gate AVANT tout plan — le paid a-t-il du sens ?
Déconseiller le paid si l'un de ces signaux est présent :
- **LTV faible** (plugin 29-99 €/an) **et** CAC paid probable > LTV.
- **< 30 conversions/mois** atteignables → Smart Bidding ne peut pas apprendre (voir §4).
- **Proposition de valeur non validée organiquement** (pas de traction SEO/communauté) → on amplifie le vide.
- **Pas d'ICP clair** → CAC ×5.
- **Budget < ~1 500 $/mois** pour PMax, ou incapable de concentrer sur 1 canal.

> Pour ce profil, **LTV:CAC ≥ 3:1** et **CAC payback < 12 mois** sont le minimum. Sous ces seuils, recommander d'abord l'organique. Détail : `references/measurement.md`.

## 1. Canal — intention d'abord
Hiérarchie pour solo/petit budget :
1. **Google Search** — intention, contrôle, conversion. Le défaut.
2. **Microsoft/Bing Ads** — sous-coté B2B/devs : CPC 30-50 % moins cher, audience desktop/pro. À activer tôt, pas « en dernier ».
3. **Reddit Ads** — uniquement audiences techniques (dev tools) + démo vidéo. Très catégorie-dépendant.
4. **Meta** — interruption, pas intention. Bon pour **retargeting** et démo. Faible pour la demande froide B2B de niche.
5. **LinkedIn** — ciblage B2B précis mais CPC 5-12 $+ → souvent hors budget modeste (sauf ACV élevé). Astuce : ciblage pro via Microsoft Ads (CPA ~31 % plus bas).
- **Ne pas s'étaler** : sous ~15 000 $/mois, > 2 canaux = pire CAC. Dominer 1 canal, puis ajouter.

## 2. Structure de compte (Google Ads)
- **Petit budget → Search classique exclusivement.** PMax bascule sur du Display (CVR ~0,64 % vs 3,82 % Search) et **gaspille jusqu'à 73 %** en B2B sans tracking offline ; il **cannibalise la marque** (91 % de chevauchement Search/PMax mesuré). PMax seulement après **60+ conv/mois + données CRM**.
- **AI Max for Search** = un compromis automation/contrôle (couche IA sur une campagne Search classique, sans minimum de dépense, garde mots-clés + reporting + brand controls).
- **SKAG mort** → ad groups **thématiques** (clusters d'intention : outcome, comparaison/alternative, pain point, marque-concurrent) + une campagne **marque** isolée.
- Détail structure + Meta (campagnes standard, **pas** Advantage+ Shopping qui est e-commerce only) : `references/campaign-structure.md`.

## 3. Créas
- **RSA** : nourrir le système d'inputs **diversifiés** (jusqu'à 15 titres, angles distincts, pas des reformulations). Buckets : outcome · différenciation · pain point · social proof (chiffres réels) · offre (essai/garantie). Copy = [[copywriting-landing]].
- **On vend à des gens, pas à des entreprises** : outcomes > features.
- Meta/Reddit : **démo vidéo** (montrer le produit), social proof réel intégré.
- Détail + templates : `references/creatives.md`.

## 4. Budget & enchères
- **tCPA/tROAS exigent ≥ 30 conversions/30 j par campagne** (40-50 = stable). Sous le seuil → apprentissage permanent, perf instable.
- **Petit budget (< 30 conv/mois)** : **Maximize conversions sans tCPA** (ou Manual CPC au début), bascule vers tCPA une fois le volume atteint. **Concentrer** le budget sur 1 campagne / quelques ad groups à forte intention. Optimiser sur une **micro-conversion** (trial/lead) pour nourrir le ML, garder la vente comme métrique business.
- Budget journalier ≥ 2× le tCPA cible. Scaler **+15-25 % max / 24-48 h** (sinon on casse le ML).

## 5. Mesure — ROAS / CAC / attribution cookieless
- **Consent Mode v2 obligatoire UE/UK** (mars 2024) : sans lui, pas de retargeting ni de tracking conversion EEE, et illégal. **Prérequis avant de lancer.**
- Stack : **Consent Mode v2 + Enhanced Conversions + server-side tagging (GTM serveur)**. Récupère ~30-50 % des conversions perdues (le « modélisé » reste une **estimation**).
- **Ne pas croire le ROAS in-platform** : 60-70 % des users non trackés post-iOS14, last-click trompeur. **Croiser avec le revenu réel** (la plateforme de facturation du projet, via import offline conversions) et raisonner **unit economics** (LTV:CAC, payback), pas coût-par-lead.
- Détail : `references/measurement.md`.

## 6. Quand le paid a du sens (une fois le gate §0 passé)
- **Lancement** ; **validation d'angle/headline** (apprentissage rapide) ; **retargeting** des visiteurs organiques / free→paid ; **bidding sur marque concurrente** + capture « alternative à X ». La donnée PPC nourrit ensuite [[seo-launch]].

## Anti-patterns à REFUSER (sourcés, cf. `references/measurement.md` & `references/campaign-structure.md`)
- **PMax / broad match / Smart Bidding par défaut** sans seuil de volume ni budget plancher → budget cramé, marque cannibalisée.
- **Auto-apply des recommandations Google** → conflit d'intérêt (la régie optimise sa dépense, pas ton ROAS ; auto-apply à désactiver, sauf conflits de négatifs).
- **Broad match sans liste de négatifs** → budget cramé sur « free », « jobs », « DIY », « tutorial ».
- **Croire le ROAS/CAC plateforme** comme une vérité (attribution cassée).
- **Multi-canal simultané à petit budget** → dilution, pire CAC.
- **Retargeting pixel « comme avant » en UE** sans Consent Mode v2 → illégal/cassé.
- **Scaler vite** → casse le ML + rendements décroissants.

## Références (chargées à la demande)
- `references/campaign-structure.md` — canaux détaillés, structure Google/Bing/Meta, match types, AI Max vs PMax, SKAG.
- `references/creatives.md` — RSA buckets, créas Meta/Reddit, templates, ce qui vend du logiciel.
- `references/measurement.md` — Consent Mode v2, Enhanced Conversions, server-side, LTV:CAC/payback, conflit d'intérêt des régies, quand déconseiller le paid.
