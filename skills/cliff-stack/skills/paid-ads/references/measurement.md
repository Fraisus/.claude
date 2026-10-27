# Mesure, attribution & quand déconseiller le paid

## Le gate : quand déconseiller le paid tout court
Le paid est un **accélérateur, pas un générateur** : il amplifie une proposition de valeur déjà validée, il ne crée pas la demande. Pour un éditeur de plugin solo à petit budget et faible LTV (29-99 €/an), le **CAC paid dépasse fréquemment la LTV** :
- seuils ML inatteignables (< 30 conv/mois),
- CPC qui mange la marge,
- attribution cassée empêchant d'optimiser.

**Déconseiller le paid quand** : pas d'ICP clair (CAC ×5) · LTV faible · < 30 conv/mois · proposition non validée organiquement · dépendance à un seul canal payant comme amorçage. Tant que la traction n'existe pas en organique, le paid brûle un budget modeste sans signal exploitable. Pour ce profil, **[[seo-launch]], contenu, communauté, wordpress.org, affiliation de la plateforme de facturation du projet** ont un LTV:CAC structurellement meilleur.

## Mini-formule du gate (rendre le « oui/non » mécanique)
Estimer le CAC paid AVANT de décider, en back-of-envelope :
```
conversions/mois ≈ budget mensuel ÷ CPC × CVR(clic→vente)
CAC paid          ≈ budget mensuel ÷ conversions/mois
```
- CPC : 1-3 € (ou $) sur des requêtes logicielles/dev ; CVR clic→vente d'un freemium ≈ 1-3 %.
- Exemple : 400 €/mois ÷ 2 € × 2 % = **~4 ventes/mois** → CAC ≈ **100 €**. Si LTV ≈ 49-130 € → LTV:CAC ≈ 1:1 → **gate ROUGE** (et 4 < 30 conv → Smart Bidding impossible).
- **Lag freemium** : pour un freemium, la conversion free→paid se mesure sur **semaines/mois** après le clic → l'attribution est encore plus cassée et le CAC réel se révèle tard. Optimiser sur la micro-conversion amont (**install free / lead**), pas la vente directe, et juger sur cohorte.

> **Monnaie** : les seuils de ce skill valent en **$ comme en €** (ordres de grandeur, écart faible). Pour une vente UE, raisonner en € et **déduire la TVA OSS** de la LTV nette avant le ratio LTV:CAC.

## Unit economics (raisonner ici, pas en last-click)
- **LTV:CAC ≥ 3:1** (SMB SaaS parfois 2,5:1) ; minimum d'efficacité.
- **CAC payback < 12 mois** (médian SaaS ~6,8 mois, B2B ~8,6).
- Remonter les **conversions hors-ligne** (vente sur la plateforme de facturation du projet → import offline / CRM) pour réaligner l'algo sur le **revenu réel**, pas les form-fills.

## Attribution post-cookieless / iOS14
- **On rate 60-70 % des users.** Le last-click « était déjà mauvais en B2B, il est désormais activement trompeur ».
- Biais systématique : les conversions trackées sont les plus faciles (desktop, opt-in, last-click) → le ROAS sous-compte ou se contredit entre plateformes (« attribution theater »).
- **Traiter les chiffres plateforme comme des estimations directionnelles**, jamais une vérité. Source-of-truth = revenu réel côté serveur.

## Consent Mode v2 (UE/UK — obligatoire 6 mars 2024)
- Sans lui : plus de personnalisation ni de retargeting pour les visiteurs EEE, pas de tracking conversion, **et non-conforme**.
- ~31 % seulement acceptent les cookies → ~70 % du trafic invisible au tracking classique.
- Stack : **Consent Mode v2 (CMP) + Enhanced Conversions (first-party hashé) + server-side tagging (GTM serveur)**. Récupère ~30-50 % des conversions perdues (uplift reporting 15-25 %) — mais ce sont des **estimations statistiques**, pas des conversions réelles.

## Conflit d'intérêt des régies
Le moteur de recommandations Google est structurellement biaisé vers **plus de portée + plus de vélocité de dépense** (né comme outil de vente interne). Audit cité : 50/50 recommandations budgétaires auraient fait chuter le ROAS / doublé le CPA.
- **Auto-apply = à désactiver** (seule exception tolérée : conflits de négatifs).
- Recommandations à traiter comme intéressées : broad match, +budget, passage à PMax, « appliquer toutes les recommandations », ajout d'audiences/placements.

## Smart Bidding — rappel seuils
- tCPA/tROAS : ≥ 30 conv/30 j par campagne (40-50 = stable) ; apprentissage 2-6 semaines.
- Sous le seuil : Max Conversions / Max Clics / manuel, ou stratégie de **portefeuille** mutualisant les conversions.
- Scaler **+15-25 % max / 24-48 h** (gros saut = reset de la learning phase).

## Sources
digitalapplied (unit economics, Consent Mode v2) · foundrycro (LTV:CAC, payback) · growthspree (CMv2 + Enhanced Conversions B2B) · dataslayer / Fresh Egg / SE Journal (Consent Mode) · Optmyzr (cannibalisation) · Search Engine Land (PPC myths 2026, recommendations/auto-apply) · Jordan Glickman / Pathmonk / Cometly (attribution post-iOS14) · Slaymaker / SolidGrowth (multi-canal) · Store Growers / Groas (Smart Bidding, learning period).
