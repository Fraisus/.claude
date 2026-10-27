# Recettes d'intégration Freemius (sécurisées, idiomatiques)

> Confirmer chaque signature via `sdk-surface.md` / Context7 avant d'écrire. Les exemples reflètent des conventions **anti-vendor-lock-in** (ports & adapters) déjà vues sur les projets de l'utilisateur — réutiliser l'existant, ne pas dupliquer.

## 1. Webhook — vérifier la signature AVANT tout (fail-closed)
Freemius signe chaque webhook : header **`X-Signature` = HMAC-SHA256 du body BRUT** avec la **Secret Key du PRODUIT** (il n'existe **pas** de « webhook secret » dédié). Recalculer sur le corps brut, jamais sur du JSON ré-encodé. Répondre **200 vite**, traitement **idempotent** (les webhooks sont rejouables).

PHP (motif fail-closed) :
```php
$raw = file_get_contents('php://input');
$secret = getenv('FREEMIUS_SECRET_KEY') ?: '';
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    http_response_code(401); exit; // ne RIEN traiter avant cette barrière
}
```
JS/TS : préférer **`fs.webhook`** (vérif native du SDK) à un HMAC maison.
Le webhook est le seul endroit qui écrit le cache local des droits. Jamais de remplissage manuel.

## 2. Customer portal (actions sensibles via lien hébergé signé)
```ts
const portal = await fs.api.user.retrieveHostedCustomerPortal(fsUserId);
// portal = { token?, link? }  →  rediriger vers portal.link  (PAS .url)
```
`fsUserId` vient **toujours de la session serveur**, jamais d'un paramètre client (anti-IDOR). Annulation/reprise/changement de carte : soit via portail, soit inline (`subscription.cancel`, voir §4).

## 3. Cache local des droits (Freemius = source de vérité)
- Le stockage local des droits (table, option, colonnes : découvrir celui du projet) se remplit **uniquement par webhook**. Lecture rapide des droits sans appel API à chaque requête.
- Factures, paiements, cartes : **jamais stockés** ; récupérés à la volée (`user.retrievePayments`, `retrieveInvoice` → Blob PDF).
- `fs.entitlement` (service SDK) aide à valider un droit/licence côté serveur.

## 4. Annulation : inline possible (ne pas tout router vers le portail par défaut)
```ts
await fs.api.subscription.cancel(subscriptionId, feedback?, reasonIds?); // DELETE — inline
```
Choisir inline vs portail selon l'UX voulue, et le dire là où le projet décrit ses capacités de facturation. Coupon de rétention sur un abonnement existant : `subscription.applyRenewalCoupon(...)` avec le coupon que rend `product.retrieveSubscriptionCancellationCoupon()` ; il porte sur le renouvellement.

## 5. Checkout / achat
- JS in-app : `fs.checkout` / `fs.purchase` (SDK) ou le **Checkout JS SDK** (`@freemius/freemius-checkout-js`, Context7 `/freemius/freemius-checkout-js`) pour un bouton d'achat.
- Token de checkout serveur : REST `/api/users/create-checkout-token`.
- Upgrade depuis une licence : `license.retrieveCheckoutUpgradeAuthorization(licenseId)` → `settings.authorization`.

## 6. Couche d'intégration
- Découvrir celle du projet (port, adaptateur, service, ou appel direct) et s'y brancher ; une couche nouvelle suit l'architecture du projet, pas un patron importé.
- Le code qui parle à Freemius **traduit** les champs snake_case réels (cf. `sdk-surface.md`) vers les noms du projet.
- Il vit **côté serveur uniquement** (détient les secrets). Un mapping non confirmé par un achat sandbox se dit non confirmé, jamais une shape devinée.

## 7. WordPress SDK (PHP, côté plugin/thème vendu)
Pour le produit WordPress lui-même (licensing, mise à jour, opt-in, `fs_*`) : `Freemius/wordpress-sdk` (Context7 `/freemius/wordpress-sdk`). Distinct du SDK serveur `@freemius/sdk` qui interroge l'API depuis ton backend.

## 8. Sandbox d'abord (gratuit, sans vente)
Compte dev Freemius gratuit → produit test → API Token + Product ID dans le dashboard → `FREEMIUS_SANDBOX=true` → achat sandbox (CB de test) → vérifier que le webhook remplit le cache local des droits → confirmer empiriquement les shapes.
