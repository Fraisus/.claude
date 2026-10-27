# Séquences détaillées — emails, rythme, triggers, templates

> Tout email = 1 objectif, 1 CTA dominant (cf. [[copywriting-landing]]). Brancher sur le comportement, pas le calendrier, dès que possible.

## Welcome / activation (envoi en quelques secondes)
- **E1 — Welcome** : confirmer la valeur attendue + LA première action vers le aha moment (1 CTA). Pas de pavé.
- Déclencheur : activation du plugin / création de compte.

## Onboarding → aha moment (6-7 emails / 14 j, front-loadé J1-J3)
Structure branchée « a fait l'action cœur / pas fait » :
- **E1-E3 (J0-J3)** : guider pas à pas vers la première valeur (1 milestone par email).
- **J+2 — check d'activation (trigger le plus rentable)** : si **non activé** → email ciblé sur le blocage probable (pas une relance générique) ; si **activé** → bascule vers nurture/upgrade.
- **E4-E7 (J4-J14)** : approfondir l'usage, montrer les cas d'usage avancés, introduire la valeur premium.
- Aha moment plugin = première mise en production réussie (premier bloc/réglage rendu au front, premier formulaire publié… selon le produit).

> **Frontière transactionnel ↔ marketing DANS l'onboarding.** Les emails « comment utiliser ton plugin » sont transactionnels (base = contrat/service, envoyables sans opt-in marketing). Dès qu'un email **pitche le Pro/l'achat** (typiquement le dernier « intro valeur premium »), il devient **marketing** → réservé aux contacts ayant coché l'opt-in marketing. Pratique : garder E1→E5 en service avec une **invitation discrète à l'opt-in marketing** au milieu, puis réserver l'email d'intro Pro + toute la séquence upgrade aux opt-in. C'est là que se loge le risque GDPR.

## Upgrade free→paid (event-triggered — cœur du freemium)
Déclencheurs : limite atteinte · feature premium gated touchée · usage intensif détecté.
- Email contextuel **au moment du besoin** : « Vous venez d'atteindre [limite] — voici ce que [Pro] débloque ». Lier la feature au bénéfice (FAB). Preuve sociale réelle. CTA vers le pricing.
- Segmenter dès le 1er email (type de business auto-déclaré) pour personnaliser la valeur.

## Trial-to-paid — séquence d'expiration (essai 14 j)
- **Démarrage à J10** (~30 % du trial restant).
- **4-5 emails de countdown** : valeur obtenue pendant l'essai → ce qui sera perdu → urgence croissante J11-J14.
- **2-4 emails post-expiration**, dont **1 à J+7** (15-20 % des conversions ; proposer second trial ou remise).
- Essai 7 j : démarrer J4, 3-4 countdown + 1-2 post.

## Win-back / réactivation (segmenté)
- Cibler **high-value parti pour raison réparable** (power-user > jamais-onboardé).
- Séquence : rappel de valeur → nouveauté/feature attendue → offre de retour → **sunset notice** (dernier email : « on arrête de vous écrire sauf si… »), puis suppression de la liste (hygiène/délivrabilité).

## Nurturing
Entre les pics d'intention : contenu éducatif (lié au [[seo-launch]] blog), cas d'usage, bonnes pratiques. Maintient l'engagement et la réputation sans sur-fréquence.

## Triggers — récap
| Séquence | Type | Déclencheur |
|---|---|---|
| Welcome | time | activation/signup |
| Onboarding | behavior | milestones d'activation, check J+2 |
| Upgrade free→paid | event | limite/feature gated/usage |
| Trial expiration | time | J = fin trial − 30 % |
| Win-back | behavior | seuil d'inactivité |
| Renewal | time | avant échéance (paiement = plateforme de facturation) |
| Suspension marketing | event (webhook de la plateforme) | échec de paiement (`payment_failed` chez Freemius) |

## Sources
mailsoftly · digitalapplied (CRM playbook 2026) · smashsend · Userlist (trigger-based, re-engagement) · growthspree (benchmarks trial 2026) · ordwaylabs · customer.io · sequenzy · Freemius (upgrades, trials).
