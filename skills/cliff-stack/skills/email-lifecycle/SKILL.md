---
name: email-lifecycle
description: "Conçoit les SÉQUENCES email lifecycle pour un SaaS / plugin WordPress freemium (éditeur solo/petite équipe) : welcome/onboarding vers le aha moment, activation, upgrade free→paid, trial-to-paid, nurturing, win-back/réactivation. Triggers comportementaux, segmentation par lifecycle stage, métriques fiables post-Apple-MPP, délivrabilité Gmail/Yahoo, conformité GDPR (marketing vs transactionnel), coordination avec le dunning du billing. À utiliser quand on demande une séquence/automation email, onboarding, nurturing, conversion d'essai, relance, réactivation, drip campaign, newsletter produit, ou une stratégie email pour convertir/retenir des utilisateurs d'un plugin/SaaS. Le dunning (relance de paiement échoué) reste géré par la plateforme de facturation du projet (si Freemius : [[freemius]]). NOT l'upsell free→pro affiché DANS l'éditeur Gutenberg → in-product-upgrade-prompts ; ce skill ne couvre la conversion free→paid que PAR EMAIL."
---

# email-lifecycle — séquences email pour SaaS / plugin freemium

Concevoir les séquences email d'un freemium plugin/SaaS. Complémentaire : la plateforme de facturation du projet (billing, trials, **dunning** ; [[freemius]] si c'est elle, à vérifier dans le projet, jamais à présumer), [[copywriting-landing]] (le copy des emails de conversion), [[pricing-strategist]]/[[marketing-psychology]] (offre & persuasion).

> **RÈGLE NON NÉGOCIABLE — consentement marketing séparé.** Un téléchargeur du plugin gratuit n'est PAS un consentement marketing : le download fonde le **transactionnel** (licence, sécurité, mises à jour), pas le démarchage. Pour l'envoyer en séquence marketing en UE, il faut un **opt-in marketing dédié, non pré-coché, granulaire**. Sinon = violation GDPR (jusqu'à 20 M€ / 4 % CA). Détail : `references/legal-deliverability.md`.

> **Ne jamais piloter sur l'open rate absolu.** Apple Mail Privacy Protection (~50-58 % des opens = bruit machine) l'a rendu inutilisable en absolu. Piloter sur **événements produit (activation, upgrade), conversions, revenue-per-email, reply rate, clics uniques anti-bot**. L'open rate ne sert qu'au timing relatif / A-B de sujet.

## 0. Avant d'agir
1. **Découvrir** : modèle (freemium WP.org vs trial), l'outil d'envoi (ESP simple vs product-triggered type Userlist/Customer.io/Encharge), le **aha moment** du produit (première valeur concrète — ex. premier rendu réussi au front), l'état de l'authentification email (SPF/DKIM/DMARC).
2. **D'où vient l'email ?** **wordpress.org ne transmet AUCUN email** au téléchargement du plugin gratuit. Pour un freemium WP.org, la **seule source d'email à l'activation est un opt-in intégré au plugin** : celui du SDK de licence/télémétrie s'il en a un (avec Freemius, l'écran « Allow & Continue »), sinon un formulaire d'inscription dans le plugin. C'est un opt-in **produit/transactionnel**, pas un opt-in marketing. Concevoir là la capture, et un **opt-in marketing dédié distinct** (case séparée, non pré-cochée) pour le démarchage. Sans cette étape, il n'y a pas de liste.
3. **Source légale** : audience UE → GDPR + double opt-in recommandé (obligatoire DE/AT/CH/GR/LU/NO).

## 1. Cartographie des séquences (ROI décroissant)
1. **Welcome / activation** (CRITIQUE) — à l'activation du plugin. Welcome + séquence d'expiration peuvent **doubler** la conversion.
2. **Onboarding → aha moment** (CRITIQUE) — users activés convertissent 5-10× plus.
3. **Upgrade free→paid** (CŒUR DU FREEMIUM) — déclenché par usage (limite atteinte, feature premium gated touchée, usage intensif).
4. **Trial-to-paid** (si la plateforme propose un funnel trial et qu'il est activé) — séquence d'expiration.
5. **Nurturing** — éducatif, entre les pics d'intention.
6. **Win-back / réactivation** — réactiver coûte moins qu'acquérir.
7. **Renewal / retention** — le renouvellement de **paiement** est géré par la plateforme de facturation (§5).

Détail de chaque séquence + nombre d'emails + rythme : `references/sequences.md`.

## 2. Onboarding & activation
- **5-8 emails sur 2 semaines** (type 6-7 / 14 j ; jusqu'à 21 j si évaluation longue). Front-loader les 3 premiers jours.
- **Welcome en quelques secondes** post-activation (un délai coûte 50 %+ d'engagement ; open welcome ~50 %).
- **Behavior-triggered > time-based** : 3-4× plus de clics. **Trigger le plus rentable = check d'activation à J+2 / 48 h** (un user non activé sous 48 h a 70-80 % de churn).
- **Mesurer l'activation, pas la conversion** à ce stade (l'activation explique 60-75 % de la conversion finale).
- Structure : 1 email = 1 action vers le aha moment, branché « a fait X / pas fait X ».

## 3. Trial-to-paid
- **Essai 14 j** = pic de conversion (7 j si time-to-value rapide). Opt-out (CB requise) convertit 3-4× l'opt-in.
- **L'activation domine** : essais activés 35-65 % vs non-activés 2-8 %.
- **Séquence d'expiration** : démarrer quand ~30 % du trial reste (14 j → J10) ; 4-5 emails de countdown + urgence sur les 4-5 derniers jours + **1 email J+7 post-expiration** (15-20 % des conversions s'y font). Détail : `references/sequences.md`.

## 4. Triggers & segmentation
- **Time-based** : welcome, countdown trial, renewal (dates connues).
- **Event/behavior-based** : onboarding (milestones), upgrade (limite/feature gated), réactivation (seuil d'inactivité).
- **Lifecycle stages** : trial → onboarding → active → at-risk → churned (au niveau company si le produit sert des équipes/agences).
- **Win-back = la segmentation la plus critique** : concentrer sur high-value parti pour raison réparable. Le dernier email = **sunset notice** (puis suppression pour l'hygiène de liste).

## 5. Coordination avec le dunning (NE PAS ré-encoder)
Le **dunning** (retries + emails de carte expirée/échec de paiement) est géré **côté plateforme de facturation** ([[freemius]] quand le projet facture par Freemius). Ce skill couvre seulement la **coordination** :
- **Suspendre** les sends marketing quand un user entre en état `payment_failed` (pas d'upsell pendant une relance de carte).
- Sur `payment_recovered` → reprendre ; sur `subscription_cancelled` (échec définitif) → bascule vers **win-back**, pas vers une relance de paiement.
- Écouter les **webhooks de la plateforme** comme triggers de changement de lifecycle stage — sans dupliquer la logique de retry.

## 6. Métriques fiables (post-MPP)
Piloter sur : **revenue-per-email + conversion**, **conversions/actions produit** (upgrade, feature activée, checkout), **reply rate** (même ~1 % compte), **CTOR hors Apple Mail / clics uniques filtrés bots**. L'open rate → timing relatif uniquement. Détail : `references/legal-deliverability.md`.

## 7. Délivrabilité (prérequis dur — au service des séquences)
> La délivrabilité est couverte ici comme **prérequis des séquences** (pour qu'elles arrivent en boîte de réception), pas comme support DNS isolé. Pour une question purement infra (configurer DMARC/SPF d'un domaine sans contexte de séquence), c'est de l'administration serveur.
- **SPF + DKIM + DMARC** alignés **dès le 1er envoi** (le seuil bulk Gmail/Yahoo de 5 000/j se franchit vite ; Microsoft rejette en 550 sinon).
- **One-click unsubscribe** (RFC 8058) obligatoire ; traiter sous 2 jours.
- **Taux de plainte spam < 0,3 % (viser < 0,1 %)**. Warm-up progressif, suppression des hard bounces, sunset des inactifs.
- Expéditeur **répondable** (pas `no-reply@`). Jamais d'email tout-image (spam + cassé en dark mode).

## Anti-patterns à REFUSER (sourcés, cf. `references/legal-deliverability.md`)
- **Démarcher (marketing) les téléchargeurs du gratuit sans opt-in marketing séparé** → violation GDPR.
- **Acheter/scraper des listes** (même « GDPR-ready ») → illégal UE, CAN-SPAM, réputation détruite.
- **Sujets trompeurs** (faux « Re: », fausses alertes) → illégal CAN-SPAM (pénalité civile par email en infraction, montant révisé chaque année par la FTC), domaine en spam.
- **Piloter sur l'open rate** (cassé par MPP).
- **Sur-fréquence** (81 % se désabonnent pour volume excessif) → fatigue → réputation → spam. Préférer segmentation + centre de préférences.
- `no-reply@`, emails tout-image, « mardi 10h universel » asséné comme règle (point de départ à tester).

## Références (chargées à la demande)
- `references/sequences.md` — chaque séquence détaillée : emails, rythme, triggers, templates (welcome, onboarding, upgrade free→paid, trial expiration, win-back).
- `references/legal-deliverability.md` — GDPR (marketing vs transactionnel, double opt-in), Gmail/Yahoo/Microsoft, métriques post-MPP, mythes email réfutés.
