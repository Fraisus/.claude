# `@freemius/sdk` — surface réelle (v0.3.x)

> Extrait des **sources TS réelles** du tag publié 0.3.0 (`github.com/Freemius/freemius-js`, `packages/sdk/src/`) + schéma OpenAPI généré (`api/schema.d.ts`, 12 965 lignes). Tout est en **snake_case**. Si la version du projet diffère, la version installée (`node_modules/@freemius/sdk`) fait foi.

## Constructeur
```ts
import { Freemius } from '@freemius/sdk';
// FSId = string | number
const fs = new Freemius({ productId, apiKey, secretKey, publicKey }); // 4 requis
```
Services exposés sur l'instance (pas seulement `.api`) :
`fs.api`, `fs.checkout`, `fs.purchase`, `fs.customerPortal`, `fs.webhook`, `fs.pricing`, `fs.entitlement`.

## `fs.api.*` — namespaces réels
**Seuls existants :** `product`, `user`, `subscription`, `license`, `payment`, `event`.
**N'existent PAS :** `fs.api.install`, `fs.api.plan` (le *type* `Plan` existe, mais aucun namespace ni endpoint `plan`).
Base abstraite `ApiBase` : impose `retrieve(id)` et `retrieveMany(filter?, pagination?)`.

### `fs.api.user`
```ts
retrieve(userId): Promise<UserEntity | null>
retrieveMany(filter?, pagination?): Promise<UserEntity[]>
retrieveByEmail(email): Promise<UserEntity | null>
retrieveBilling(userId): Promise<UserBillingEntity | null>
updateBilling(userId, payload): Promise<UserBillingEntity | null>          // PUT
retrieveSubscriptions(userId, filters?, pagination?): Promise<UserSubscriptionWithDiscounts[]>
retrieveLicenses(userId, filters?, pagination?): Promise<LicenseEntity[]>
retrievePayments(userId, filters?, pagination?): Promise<PaymentEntity[]>
retrieveInvoice(userId, paymentId): Promise<Blob | null>                   // PDF, 2 args
retrieveHostedCustomerPortal(userId): Promise<UserCustomerPortalResult | null>      // POST /portal/login.json
retrieveHostedCustomerPortalByEmail(email): Promise<UserCustomerPortalResult | null>
```
`UserCustomerPortalResult` = `{ token?: string; link?: string }` → **le champ URL est `link`**.

### `fs.api.subscription`
```ts
retrieve(subscriptionId): Promise<SubscriptionEntity | null>
retrieveMany(filter?, pagination?): Promise<SubscriptionEntity[]>
applyRenewalCoupon(subscriptionId, couponId, logAutoRenew): Promise<SubscriptionRenewalCouponResult | null>  // PUT
cancel(subscriptionId, feedback?, reasonIds?): Promise<SubscriptionCancellationResult | null>               // DELETE
```
> **`cancel` existe** (DELETE `/subscriptions/{id}.json`) → annulation **inline possible**, pas obligatoirement via le portail. `reasonIds` = `SubscriptionCancellationReasonType[]`.

### `fs.api.license`
```ts
retrieve(licenseId): Promise<LicenseEntity | null>
retrieveMany(filter?, pagination?): Promise<LicenseEntity[]>
retrieveSubscription(licenseId): Promise<SubscriptionEntity | null>        // GET .../subscription.json
retrieveCheckoutUpgradeAuthorization(licenseId): Promise<string | null>    // POST .../checkout/link.json → settings.authorization
```
> **PAS de wrapper `retrieveInstalls`** en 0.3.x — MAIS la route REST EXISTE (cf. §Surface REST réelle ci-dessous). « Non wrappé » ≠ « impossible » : appeler le client REST sous-jacent.

### `fs.api.payment`
```ts
retrieve(paymentId): Promise<PaymentEntity | null>
retrieveMany(filter?, pagination?): Promise<PaymentEntity[]>
retrieveInvoice(paymentId): Promise<Blob | null>                          // PDF, 1 arg ici
```

### `fs.api.product`
```ts
retrieve(): Promise<ProductEntity | null>                                 // pas d'argument (productId du constructeur)
retrieveMany(): Promise<ProductEntity[]>                                  // typé ainsi, comportement au runtime non confirmé : ne pas s'y fier
retrievePricingData(): Promise<PricingTableData | null>                   // GET /pricing.json
retrieveSubscriptionCancellationCoupon(): Promise<CouponEntityEnriched[] | null>
```

### `fs.api.event`
`WebhookEvent` (classe interne `WebhookEvent$1`, propriété de service `event`) — event logs / webhooks.

### Pagination (sur tous les namespaces via `ApiBase`)
`retrieveMany(filter?, pagination?)` page par page, OU `iterateAll(filter?, pageSize?)` — **async generator** idiomatique pour parcourir TOUS les items (paiements, licences…) sans gérer l'offset. Préférer `iterateAll` aux boucles d'offset manuelles.

## Entités — noms de champs réels (snake_case)

### UserEntity
`id, created, updated, email, first, last` (pas `first_name`), `picture, ip, is_verified, is_marketing_allowed, is_beta, note, secret_key, public_key, gross` (total dépensé), `last_login_at, email_status`.

### SubscriptionEntity
`id, created, updated, user_id, install_id, plan_id, pricing_id, license_id, coupon_id, plugin_id, gateway, external_id, currency, tax_rate,`
**`total_gross`** (le montant — PAS `gross`), `amount_per_cycle, initial_amount, renewal_amount, renewals_discount, renewals_discount_type, billing_cycle, outstanding_balance, failed_payments, trial_ends,`
**`next_payment`** (string|null ; null ⇒ annulé), **`canceled_at`** (string|null).
> État annulé = `canceled_at !== null` (ou `next_payment === null`). **Il n'y a pas de `is_canceled`.**

### LicenseEntity
`id, created, updated, plugin_id, user_id, plan_id, pricing_id,`
**`quota`** (number|null ; null ⇒ illimité), **`activated`** (number), `activated_local,`
**`expiration`** (string|null ; null ⇒ lifetime), **`secret_key`** (= la clé de licence),
`is_free_localhost, is_block_features,` **`is_cancelled`** (double L), `is_whitelabeled, environment, source`.
Variante `LicenseEnriched` (si enrichi) ajoute : `subscription_id, next_payment, subscription_total_gross, subscription_initial_amount, subscription_gateway, subscription_failed_payments, parent_plan_id, parent_license_id, trial_ends`.

### InstallEntity (type existe, mais aucune méthode API ne le renvoie en 0.3.x)
`id, created, updated, site_id, plugin_id, user_id, url (string|null), title, version, plan_id, license_id, trial_plan_id, trial_ends, subscription_id, gross, country_code, language, platform_version, sdk_version, is_active, is_disconnected, is_premium, is_uninstalled, is_locked, secret_key, public_key, source, last_seen_at`.

### PaymentEntity
`id, created, updated, user_id, install_id, plan_id, pricing_id, license_id, subscription_id, plugin_id, currency,` **`gross`** (montant HT), `vat, gateway_fee, is_renewal, type,` **`gateway`** (string|**null** — null sur achat 100% remboursé/gratuit : ne pas tester `if(!gateway)` comme une erreur), `external_id,` **`refund_reason`** (string|null ⇒ remboursé) + `bound_payment_id` (id du paiement remboursé), `coupon_id, country_code, vat_id, zip_postal_code`. **Pas de `is_refund` ni `amount`.**

### PlanEntity
`id, created, updated, plugin_id, name, title, description, is_free_localhost, is_block_features, license_type, trial_period, is_require_subscription, support_*, is_featured, is_hidden`.

### ProductEntity (= schema `Plugin`)
`id, created, updated, parent_plugin_id, secret_key, public_key, …`.

## Tableau récap des pièges (à appliquer dans tout adapter)
| Hypothèse fréquente | Réalité 0.3.x |
|---|---|
| `is_canceled` / `isCanceled` sur Subscription | ❌ → `canceled_at` / `next_payment` null |
| `is_canceled` sur License | ❌ → `is_cancelled` (double L) |
| `license.retrieveInstalls(id)` | ❌ n'existe pas |
| portal `{ url }` | ❌ → `{ token, link }` (champ `link`) |
| Subscription `gross` | ❌ → `total_gross` |
| annulation = forcément portail | ❌ `subscription.cancel()` existe (inline) |
| `product.retrieve(id)` | ❌ pas d'argument |
| `retrieveInvoice(paymentId)` côté user | ❌ → `(userId, paymentId)` |
| même shape en liste et en détail | ❌ **`retrieveMany` est ENRICHI** : `license.retrieveMany`/`subscription.retrieveMany` exposent des champs (`subscription_id, next_payment, plan_name, email, url, title`…) **absents** de `retrieve(id)`. Un champ présent en liste = `undefined` en détail unitaire. |
| `payment.gateway` toujours défini | ❌ `gateway: string\|null` (null si achat 100% off) |
| `is_cancelled` existe sur Subscription | ❌ aucune orthographe : Subscription = `canceled_at`/`next_payment` uniquement |

## Surface REST réelle (au-delà du wrapper `fs.api.*`)
> Le wrapper 0.3.x n'expose qu'une fraction des routes. Le **schéma OpenAPI complet** est dans `node_modules/@freemius/sdk/dist/index.d.ts` (type `paths`). Grep avant de conclure « impossible » (cf. SKILL §2bis). Routes présentes dans le schéma :

| Besoin | Route REST (verbe) | Wrappé ? |
|---|---|---|
| **Lister les sites/installs d'une licence** | `GET /products/{id}/licenses/{license_id}/installs.json` | ❌ non |
| Lister tous les installs d'un user | `GET /products/{id}/users/{user_id}/installs.json` | ❌ non |
| Sync installs d'une licence | `POST /products/{id}/licenses/{license_id}/installs/sync.json` | ❌ non |
| **Désactiver/retirer un site d'une licence** | `DELETE /products/{id}/licenses/{license_id}/installs.json` ; ou `PUT /products/{id}/installs/{install_id}.json` | ❌ non |
| **Modifier le profil user** (nom, etc.) | `PUT /products/{id}/users/{user_id}.json` | ❌ non |
| Émettre une licence SANS checkout (entitlement-only) | `POST /products/{id}/plans/{plan_id}/pricing/{pricing_id}/licenses.json` | ❌ non |
| Activer / désactiver une licence (REST doc) | `/api/licenses/activate` · `/api/licenses/deactivate` | ❌ non |
| Prix multi-devises (usd/eur/gbp) | `retrieve-pricing-table-data` → `all_plans_single_site_pricing` | via `product.retrievePricingData()` |

**`POST …/licenses.json` (émettre une licence hors checkout)** crée une licence **entitlement-only** : **NI paiement, NI abonnement, NI TVA, NI facture** (`/payments.json` = lecture seule, pas de création de paiement externe). → Un checkout 100% custom est *techniquement* faisable mais **fait perdre le rôle Merchant-of-Record** de Freemius (TVA UE/UK, facturation, dunning, SCA, refunds) + charge PCI + double source de vérité. **À proscrire** sauf migration assumée hors Freemius.

**Shapes des réponses REST non wrappées non typées par le domaine** : confirmer les noms de champs (install : `url`, `is_active`, `is_uninstalled`…) sur un vrai achat avant de câbler l'UI.

## Webhook (service `fs.webhook`)
- Auth par défaut **`SignatureHeader`** : HMAC-SHA256 du body **brut** avec la **Secret Key produit**, header `x-signature` (confirmé `index.js`). Mode alt `Api` (`WebhookAuthenticationMethod.Api`) re-vérifie l'événement via un appel API.
- Helpers prêts à l'emploi : `WebhookService.processFetch` (Next.js App Router, Cloudflare Workers…) et `processNodeHttp` (Node http) — évitent d'écrire le HMAC à la main. (Un proxy PHP garde sa propre vérif, cf. `integration-recipes.md`.)
