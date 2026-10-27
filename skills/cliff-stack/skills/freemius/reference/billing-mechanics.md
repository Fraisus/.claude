# Mécaniques commerciales Freemius — vérité terrain (dunning, licences, coupons, events, expiration)

> **Frontière :** ce skill couvre l'**implémentation/configuration** commerciale dans Freemius (plans, coupons, dunning, gating SDK). **Décider** le niveau de prix, le modèle, le multiple lifetime ou la stratégie de grandfathering relève de `pricing-strategist` (reference `wordpress_plugin_licensing.md`) — on décide là-bas, on implémente ici.
>
> Faits tirés du help center Freemius et du code du WordPress SDK, chacun sourcé. Plusieurs « règles » du blog Freemius circulant sur le web sont **fausses ou déformées** : corrigées ici.

## Sommaire
- [1. Dunning (échecs de paiement) — calendrier FIXE](#1-dunning-échecs-de-paiement--calendrier-fixe)
- [2. Rappels de renouvellement AVANT prélèvement (pas un angle mort)](#2-rappels-de-renouvellement-avant-prélèvement-pas-un-angle-mort)
- [3. Exemptions d'activation dev/staging](#3-exemptions-dactivation-devstaging)
- [4. Coupons : first-payment vs renewals](#4-coupons--first-payment-vs-renewals)
- [5. track_event() — PIÈGE : gaté, à ne pas recommander](#5-track_event--piège--gaté-à-ne-pas-recommander)
- [6. Comportement à l'expiration (gating premium)](#6-comportement-à-lexpiration-gating-premium)
- [7. Biais de source à signaler](#7-biais-de-source-à-signaler)

## 1. Dunning (échecs de paiement) — calendrier FIXE
Source : `freemius.com/help/documentation/selling-with-freemius/dunning-failed-payments/`. Calendrier **non configurable**, cartes ET PayPal :
- 1er échec → email + retry **J+1**
- 2e → email + retry **J+3**
- 3e → email + retry **J+5**
- 4e (final) → annulation abonnement + annulation licence + email d'annulation
≈ 4 tentatives sur ~14 jours. Emails de recovery natifs (lien vers page de mise à jour du moyen de paiement).
**Mode `Expired`** pendant un délai de traitement du renouvellement (source : `.../license-renewals-mechanism/` : *« the license will be set to `Expired` mode »*). Durée exacte / maintien d'accès **non chiffrés** dans la doc → rester vague.

## 2. Rappels de renouvellement AVANT prélèvement (pas un angle mort)
**Correctif** : le claim « le pré-dunning J-7 avant prélèvement est un angle mort que Freemius ne couvre pas » est **FAUX**. Rappels natifs (source `.../license-renewals-mechanism/`) : **J-30, J-7, J-2, J+1**. Page Subscriptions : *« Automated renewal reminders… to meet credit card compliance requirements and reduce failed payments. »*
- Mise à jour auto des cartes **remplacées** : **active**.
→ Donc ne pas proposer de « bâtir une pré-dunning J-7 » : c'est natif. Un email maison n'a de sens que pour un message hors-périmètre Freemius (onboarding, valeur), pas pour le rappel de paiement.

## 3. Exemptions d'activation dev/staging
Source : `.../selling-with-freemius/license-utilization/`. Les environnements localhost/staging/dev **ne sont pas décomptés** du quota d'activations si le domaine indique clairement un site dev/staging — **activé par défaut** à la création d'un plan. Patterns custom (URLs de staging non standard) → champ **« Custom localhost URLs »** du Developer Dashboard. Pas de liste exhaustive publique des patterns auto-détectés → formuler « auto pour les cas évidents (*.local, *.test, localhost, hébergeurs de staging connus) + override manuel pour le reste ».

## 4. Coupons : first-payment vs renewals
Source : `.../selling-with-freemius/coupon-discount/`. Deux options par coupon : **« First payment only »** vs **« First payment and renewals »**. « First payment only » ne vaut que pour un achat neuf ; sur un abonnement existant, la remise de rétention porte sur le renouvellement (`subscription.applyRenewalCoupon(...)` avec le coupon de `product.retrieveSubscriptionCancellationCoupon()`, cf. `integration-recipes.md` §4).

## 5. track_event() — PIÈGE : gaté, à ne pas recommander
Vérité terrain (`includes/class-freemius.php`) — la fonction **existe** :
```php
public function track_event( $name, $properties = array(), $process_at = false, $once = false )
public function track_event_once( $name, $properties = array(), $process_at = false )
```
`$properties` = tableau associatif de **scalaires uniquement** (sinon `Freemius_InvalidArgumentException`). POST vers `events.json`.
**MAIS la docstring officielle du SDK dit** : *« Custom event tracking is currently only supported for specific clients. If you are not one of them, please don't use this method. […] the API will simply ignore your request based on the plugin ID […] contact yo@freemius.com »*.
→ **NE PAS recommander `track_event()` comme API d'analytics custom / pour marquer un "aha moment"** : pour un éditeur lambda, l'appel est **silencieusement ignoré** côté serveur. Pour tracker un aha moment de façon fiable → **couche maison** (action hook local + son propre backend/analytics), pas cette méthode SDK gatée.

## 6. Comportement à l'expiration (gating premium)
- **Updates + support** : **toujours coupés** à l'expiration (source `.../software-updates-distribution/` : seuls trial ou licence valide non-expirée reçoivent les updates premium).
- **Features premium** : **configurable par plan** (block / allow / blocage sélectif pour les mensuels), override possible par licence. Source `.../faq/licensing/` + `.../wordpress-sdk/software-licensing/`. → « garder les features mais couper updates+support » est **une option**, pas le défaut universel.
- **Gating correct dans le code** : utiliser **`can_use_premium_code()`** (trial OU licence active features-enabled) pour gater le code premium — **pas** `is_paying()` (qui ne couvre pas le trial). Aussi : `is_paying()`, `is_paying_or_trial()`. (Cf. `reference/sdk-surface.md` pour la surface complète.)

## 7. Biais de source à signaler
Beaucoup de ces mécaniques sont décrites sur le **blog Freemius**, qui a un **intérêt commercial à pousser l'abonnement récurrent**. Les faits techniques (dunning, exemptions, coupons, SDK) sont vérifiés en doc/code → fiables. Les **recommandations stratégiques** (lifetime vs abonnement, mensuel…) relèvent du pricing → voir `pricing-strategist` (reference WordPress licensing), et garder l'esprit critique sur l'origine.
