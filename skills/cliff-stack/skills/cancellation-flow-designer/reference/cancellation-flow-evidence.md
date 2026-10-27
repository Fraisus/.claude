# Cancellation flows — preuves sourcées, chiffres, garde-fous légaux

> Distingue **[source primaire]**, **[chiffre éditeur — biais]**, **[tendance]**, **[à proscrire]**. Tous les chiffres « éditeur » viennent d'acteurs (Churnkey/Paddle) qui vendent l'outil → biais d'auto-sélection à signaler.

## Sommaire
- [1. Structure du flow](#1-structure-du-flow)
- [2. Save-rates — ce qui est réel](#2-save-rates--ce-qui-est-réel)
- [3. Choix d'offres](#3-choix-doffres)
- [4. Garde-fous légaux (datés)](#4-garde-fous-légaux-datés)
- [5. Freemius : natif vs à bâtir](#5-freemius--natif-vs-à-bâtir)
- [6. Sources](#6-sources)

## 1. Structure du flow
**[source primaire]** Paddle Retain : le flow « first asks customers their cancellation reason, then gauges satisfaction… then presenting a matched salvage attempt », jusqu'à « five salvage attempts… each matched to the customer's stated cancellation reason » (pause, plan switch/downgrade, discount, contact support). Churnkey : « Survey then matched offer » (raisons mappées vers offres).
→ La séquence raison → offre matchée → confirmation est la pratique de place. Le mapping précis raison→offre est cohérent mais **pas un standard chiffré publié** — l'adapter.

## 2. Save-rates — ce qui est réel
- **[chiffre éditeur — biais]** Churnkey : « average save rate of **34%** … over **tens of thousands** of customer sessions ». (Ne PAS citer « 3 millions de sessions » — la page primaire dit « tens of thousands ».)
- **[chiffre éditeur — biais]** Paddle Retain : sauve « over one quarter of customers at risk » → **25-30%**, réduction du churn ARR ~5%.
- **[à proscrire]** Le **« 10% baseline »** (save-rate sans flow structuré) : **aucune source primaire**. → **Ne jamais écrire « 10% → 34% »**. Au mieux : « un flux non structuré convertit nettement moins, sans chiffre de référence publié ».
- **[chiffre éditeur]** Churnkey, acceptation par type d'offre : discount **~62%**, pause **~22%**, change plan **~7,7%**, extend trial **~7,5%**. (Ce sont des taux d'acceptation d'offre, pas des save-rates.)
→ À graver : « un flux structuré sauve de l'ordre de **25-35% des tentatives** (chiffres éditeurs, biais d'auto-sélection) ».

## 3. Choix d'offres
- **Pause [tendance + données]** : Chargebee « The Power of Pause ». Stats marché directionnelles : ~58% ont déjà mis en pause au lieu d'annuler, ~79% veulent l'option, usage +66% en 2024. Seuils opérationnels cités : réactivation < 25% → pause non rentable ; > 45% → offre primaire. La pause est **moins acceptée (~22%)** mais **meilleure en préservation de revenu** (pas de remise, données gardées).
- **Coupon [source + précédent]** : dernier recours, one-time, **plafonné**. Précédent concret : **Amazon = 1 offre de rétention / abonnement / 12 mois**. Un discount cadré (pas promo de masse) a donné « +17% de rétention without training customers to wait for discounts ». Mettre le discount en premier = le plus coûteux en marge + addictif.

## 4. Garde-fous légaux (datés)
**US :**
- **FTC « Click-to-Cancel » (Negative Option Rule)** votée 15 nov. 2024 → **VACATÉE intégralement le 8 juil. 2025** (8e Circuit, vice de procédure). **N'est pas en vigueur.** Ne pas s'appuyer dessus.
- **ROSCA** (en vigueur) impose déjà : disclosure claire, consentement explicite, **mécanisme d'annulation simple**. Enforcement : Amazon, settlement **2,5 Md$ en 2025** (retrait de flows d'annulation manipulatifs).

**UE :**
- **DSA** (pleinement applicable depuis fév. 2024) : interdit les dark patterns, **y compris rendre l'annulation difficile**.
- **Digital Fairness Act** en préparation : vise les processus d'annulation difficiles.

**Ligne OK vs INTERDIT :**
- ✅ OK : proposer UNE alternative par raison ; sondage de raison ; offre claire.
- ❌ INTERDIT : annulation plus longue/complexe que la souscription ; multiplier les écrans obligatoires ; masquer/dégrader « Annuler quand même » ; faux compte à rebours/urgence ; cases pré-cochées ; guilt-tripping agressif.
- **Principe cardinal** : l'annulation **aussi facile que la souscription** ; l'offre ne bloque jamais le bouton d'annulation final.

## 5. Freemius : natif vs à bâtir
Cas particulier, seulement quand le projet facture par Freemius (le vérifier dans le projet d'abord).
- **[source primaire]** Natif : **Cancellation Survey** (Customer Portal), raisons prédéfinies + « Other » libre, exposée via events/webhooks/API/email transactionnel. Plus « License Retention Guidance » (dialog Retain vs cancel) — **guidance, pas offre**.
- **À bâtir (couche maison)** : la logique « raison → offre matchée » (pause/downgrade/coupon présentés selon la raison) n'est **pas** fournie. Implémentation : webhook/feedback capte la raison → UI custom présente l'offre (coupon/pause via API Freemius) **avant** l'appel d'annulation. Les coupons existent comme feature séparée (« First payment only » vs « renewals ») — pour une remise de rétention sur un abonnement existant, **valider le mécanisme exact côté `freemius`** (cf. `freemius/reference/billing-mechanics.md` §4).

## 6. Sources
- Paddle Retain — `https://developer.paddle.com/concepts/retain/cancellation-flows-surveys/`
- Churnkey cancel flows — `https://churnkey.co/feature/cancel-flows`
- Churnkey 34% / 12 companies — `https://churnkey.co/blog/how-12-huge-companies-save-millions-of-dollars-from-cancellation-flows/`
- Churnkey benchmarks (acceptation par offre) — `https://churnkey.co/blog/voluntary-churn-benchmarks/`
- Chargebee « Power of Pause » — `https://www.chargebee.com/blog/power-of-pause-subscription-retention-strategy/`
- Amazon retention offers (plafond 1/12 mois) — `https://developer.amazon.com/docs/reports-promo/retention-offers.html`
- FTC rule vacatée — `https://www.cooley.com/news/insight/2025/2025-07-11-click-to-cancel-just-got-cancelled-eighth-circuit-vacates-entirety-of-ftcs-negative-option-rule`
- DSA dark patterns — `https://cbtw.tech/insights/illegal-dark-patterns-europe`
- Freemius Cancellation Survey — `https://freemius.com/help/documentation/users-account-management/cancellation-survey/`
