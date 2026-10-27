---
name: cancellation-flow-designer
description: "Designs the subscription CANCELLATION / retention flow for a freemium WordPress plugin or SaaS — the 'reason → matched offer → confirmation' sequence (survey the cancellation reason, present ONE offer matched to that reason: pause / downgrade / support / last-resort one-time coupon), with save-rate analytics and HARD legal/dark-pattern guardrails. Decides the flow STRATEGY and copy; delegates the licensing/coupon/pause wiring to the project's billing platform (freemius when it is Freemius) and the dark-pattern/a11y audit to design-auditor. Use when asked to design a cancellation flow, reduce voluntary churn at the cancel moment, build a retention/offboarding/win-on-cancel flow, add a cancellation survey with offers, decide what to offer when someone cancels, or 'how do I keep customers from cancelling'. NOT failed-payment/dunning recovery (that's involuntary churn → the billing platform), NOT win-back emails after they've left (use email-lifecycle), NOT the price/tier design (use pricing-strategist)."
---

# cancellation-flow-designer — flux d'annulation « raison → offre matchée »

Conçoit le flux d'annulation d'abonnement qui **retient au moment du churn volontaire** : sonder la raison, présenter UNE offre adaptée, confirmer. Les plateformes de facturation fournissent au mieux le sondage de raison (Freemius le fait nativement), rarement l'offre matchée — d'où ce skill (la stratégie) + une couche maison (l'implémentation).

## Garde-fous légaux (sourcés)

Un flux d'annulation à offres frôle les **dark patterns** et tombe sous ROSCA (US) et le **DSA (UE, en vigueur depuis fév. 2024)**. Ces quatre points s'imposent :
1. **L'annulation doit être aussi facile que la souscription** (principe DSA + ROSCA). Le bouton « Annuler quand même » reste **visible et accessible à chaque étape**.
2. **Une seule offre par raison**, jamais une chaîne d'écrans obligatoires pour atteindre l'annulation.
3. **Zéro dark pattern** : pas de faux compte à rebours/urgence, pas de guilt-tripping agressif, pas de case pré-cochée, pas de friction artificielle, pas de bouton « Annuler » masqué/dégradé visuellement.
4. **L'offre de rétention ne bloque ni ne retarde** l'accès au bouton d'annulation final.
> La **FTC « Click-to-Cancel » Rule a été annulée le 8 juil. 2025** (8e Circuit) : ne pas s'appuyer dessus comme si elle s'appliquait. Mais **ROSCA (US) + DSA (UE)** imposent déjà ces exigences. Traiter la barre comme **de fait obligatoire**. → Faire auditer le flux par `[[design-auditor]]` (dark patterns / a11y / GDPR).

## Frontière dure — ce skill DÉCIDE, il ne code pas (déléguer)

- **Câblage coupon/pause/downgrade, API d'annulation, webhooks** → la plateforme de facturation du projet ; si c'est Freemius, `[[freemius]]`.
- **Audit dark-pattern / a11y / conformité GDPR du flux** → `[[design-auditor]]`.
- **Emails de win-back APRÈS le départ** → `[[email-lifecycle]]`.
- **Récupération de paiements échoués (churn INVOLONTAIRE, dunning)** → la plateforme de facturation du projet (si Freemius : `[[freemius]]`, mécanique J+1/J+3/J+5 native). Ne pas confondre : ce skill = churn **volontaire** (l'utilisateur clique « annuler »).
- **Niveau de prix du downgrade / du coupon** → `[[pricing-strategist]]`.

## La structure recommandée (confirmée Paddle Retain + Churnkey)

1. **Sondage de raison** (5-7 raisons prédéfinies + « Autre » libre). Souvent natif côté plateforme (c'est le cas de Freemius).
2. **UNE offre matchée à la raison** :
   | Raison déclarée | Offre matchée |
   |---|---|
   | Temporaire / pause de projet | **Pause** (données préservées, réactivation 1 clic) |
   | Trop cher | **Downgrade** vers un palier inférieur (ou mensuel) |
   | Bug / problème technique | **Contact support** (pas une remise) |
   | N'utilise plus / manque une feature | Pointer la valeur / roadmap, sinon laisser partir |
   | (dernier recours, toute raison « prix ») | **Coupon one-time, plafonné** |
3. **Confirmation** (et collecte du feedback même si départ — utile produit).
> Le mapping ci-dessus est la **logique de place** (Paddle/Churnkey), pas un standard normatif chiffré. L'adapter au produit.

## Choix d'offres — ce que disent les données (sourcé, avec biais)

- **Pause** : supérieure en **qualité de rétention** (zéro marge sacrifiée, données gardées, réactivation 1 clic) mais **moins acceptée** (~22%) que le discount (~62%, données Churnkey). → privilégier la pause pour les raisons « temporaire », pas la survendre comme l'offre la plus convertissante.
- **Coupon = DERNIER palier, one-time, plafonné** (modèle Amazon : 1 offre de rétention / abonnement / 12 mois). Raison : le discount est l'offre la plus acceptée donc la plus coûteuse en marge ET la plus addictive — le mettre en premier **entraîne les clients à annuler pour obtenir une remise**.
- **Save-rate réaliste** : un flux structuré sauve de l'ordre de **25-35% des tentatives d'annulation** (Churnkey ~34% auto-déclaré, Paddle Retain 25-30%). **Chiffres éditeurs, biais d'auto-sélection.** Ne jamais écrire « 10% → 34% » : le « 10% baseline » n'a aucune source primaire. Présenter une fourchette, jamais un avant/après fabriqué.

## Implémentation : identifier d'abord la plateforme du projet

Lire dans le projet quelle plateforme facture (SDK, dépendances, config) avant de parler d'implémentation ; ne jamais la présumer. Dans tous les cas, la logique « raison → offre matchée » est une couche maison qui s'exécute **avant** l'appel d'annulation de la plateforme. Détail ci-dessous quand c'est Freemius.

### Si la plateforme est Freemius (natif vs à bâtir)

- **Natif** : Cancellation Survey dans le Customer Portal (raison prédéfinie + « Autre »), exposée via events/webhooks/API/email. Plus une « License Retention Guidance » (dialog « Retain vs cancel ») — mais c'est une guidance, **pas une offre**.
- **À bâtir (couche maison)** : la logique « raison → offre matchée ». Capter la raison (webhook/feedback), présenter l'offre (pause/downgrade/coupon via API Freemius) dans une UI custom **AVANT** de laisser l'API d'annulation s'exécuter. Sur un abonnement existant, la remise de rétention porte sur le renouvellement (« First payment only » ne vaut que pour un achat neuf) ; câblage côté `[[freemius]]`. Cette couche suit l'architecture de facturation du projet, à découvrir.

## Analytics (mesurer le save-rate honnêtement)

- Tracker par **raison** : taux de présentation d'offre, taux d'acceptation par type d'offre, save-rate global.
- Distinguer **save réel** (a renoncé à annuler) de **report** (pause qui ne se réactive pas). Seuil opérationnel cité : pause→réactivation < 25% = la pause ne vaut pas la charge ; > 45% = en faire l'offre primaire.
- Ne pas confondre ce save-rate (volontaire) avec la récupération de dunning (involontaire, côté plateforme de facturation).

## Garde-fous (refus)

- **Refuser** tout flux qui viole les garde-fous légaux (annulation enterrée, dark pattern, offre bloquante), faire auditer par `design-auditor`.
- **Refuser** le coupon en première offre à tous, ou un coupon récurrent/non plafonné.
- **Ne pas affirmer** « 10% → 34% » ni « 3M sessions » (la source dit « tens of thousands »).
- **Ne pas coder** le câblage de la plateforme de facturation ici → déléguer (`[[freemius]]` si c'est elle).

## Référence (chargée à la demande, 1 niveau)
- `reference/cancellation-flow-evidence.md` — structure Paddle/Churnkey, chiffres sourcés (save-rates, acceptation par offre, stats pause), garde-fous légaux datés (FTC vacatée / ROSCA / DSA), surface native vs à bâtir quand la plateforme est Freemius.
