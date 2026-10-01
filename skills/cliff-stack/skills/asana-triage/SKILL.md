---
name: asana-triage
description: >-
  Triage industrialisé des tickets Asana de l'utilisateur. Détecte ses tickets ASSIGNÉS non terminés
  (tous les workspaces, identité et workspaces découverts dynamiquement), les comprend (texte +
  commentaires + pièces jointes images/PDF analysées visuellement), identifie par preuve le dossier
  projet local sous la racine web locale, produit un rapport + un début de correctif par ticket —
  PUIS, sur validation explicite « go » de l'utilisateur, lance les corrections en fan-out (1 agent
  par correction, max 5 en parallèle) avec validation adversariale et test Playwright réel si pas sûr
  à 100 %. À utiliser quand l'utilisateur demande de « voir / lister / traiter / triager mes tickets
  Asana », « qu'est-ce qui m'est assigné », « analyse mes tickets », ou veut démarrer les correctifs
  de ses tickets, ou donne l'URL/le gid d'UN ticket à analyser. Le pipeline lit en Asana et ne poste
  un commentaire qu'après validation du texte par l'utilisateur ; il ne corrige le code QUE sur go explicite.
---

# Asana triage

Pipeline : **détecter → comprendre → router vers le projet → proposer → (sur go) corriger en fan-out**.
**Rien en dur** : identité, workspaces et Premium sont découverts au runtime via le module `asana-api.mjs`
(qui lit le token depuis le Credential Manager, pagine, et déduplique). Le token ne transite jamais en argv.

## 0. Connexion & découverte
```bash
# Chemin découvert à l'exécution (le skill vit dans un plugin, son emplacement n'est pas fixe)
API=$(find "$USERPROFILE/.claude/skills" -path '*/asana-triage/bin/asana-api.mjs' -print -quit 2>/dev/null)
[ -n "$API" ] || { echo "ERREUR: module asana-api.mjs introuvable sous ~/.claude/skills"; exit 1; }
BIN=$(dirname "$API")
node "$API" me            # -> identité + workspaces (aucun gid supposé)
```
**Premier lancement sur un nouveau poste** (seule étape manuelle, le secret ne peut pas être dans git) :
si `node "$API" me` échoue avec « Token introuvable », demander le PAT à l'utilisateur et le stocker
dans le Credential Manager de CE poste, puis revérifier par `node "$API" me` :
```bash
ASANA_CRED_IN='<pat-collé-par-l-utilisateur>' powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$BIN/asana-cred.ps1" -Action store
```
Le PAT Asana est full-access (pas de read-only) ; stocké chiffré (vault utilisateur Windows), jamais commité.
Tout le reste (skill, module, agent) est versionné : aucun élément à retrouver/ré-injecter à part ce collage unique.

## Entrée « un seul ticket » (URL ou gid fourni)
Si l'utilisateur donne une URL Asana ou un gid, **ne pas lister** les tickets : extraire le gid (dernier segment numérique de l'URL, ex. `…/task/<gid>`) et passer directement à l'étape 2 pour ce seul ticket.

## 1. Détecter (assignés non terminés, tous workspaces, paginé)
```bash
node "$API" assigned              # assignés uniquement (périmètre par défaut)
node "$API" assigned --involved   # + tickets où l'utilisateur est follower (Premium détecté dynamiquement)
```
- Sortie JSON : `{ user, count, tickets[], notes[] }`. Chaque ticket a `_role` (assigned/follower) et `_workspace`.
- Pagination et dédup par gid gérées par le module (anti-troncature). `notes[]` signale les workspaces où `search` est indisponible (non-Premium) — **échec visible, pas silencieux**.
- « Mentionné » n'est pas filtrable par l'API (limite assumée).
- Périmètre par défaut = **assignés**. N'activer `--involved` que si l'utilisateur demande « de près ou de loin / impliqué ».

## 2. Comprendre — fan-out d'analyse (1 agent par ticket, max 5 en parallèle)
Pour chaque ticket, déléguer à l'agent **`asana-ticket-analyzer`** (gid + nom + permalink en entrée). Il lit le ticket+commentaires+PJ via le module, analyse les images, **trouve le dossier projet par preuve**, propose un début de fix. Traiter par **lots de 5** ; enchaîner les lots jusqu'à épuisement (scalable).

Agréger en **rapport** : un bloc par ticket — titre/statut, problématique reformulée, **dossier projet local prouvé**, fichiers suspects, début de correctif, **statut COMPRIS / PARTIEL / INCOMPRIS**.

## 3. Commentaires Asana (relance, réponse) → toujours validés avant envoi
Tout commentaire part au nom de l'utilisateur et est lu par le client : **proposer le texte, attendre son accord explicite, puis poster**. Jamais d'envoi direct, y compris pour une relance de ticket **INCOMPRIS** ou **PARTIEL faute d'info**.
- Ton : court, non technique, au but (cause en une phrase, état actuel, action restante). Pas de jargon (cache, chiffrement, base64), pas de pavé.
- Ne dire « en ligne » que si le correctif est réellement déployé.
```bash
node "$API" comment <gid> "<texte validé par l'utilisateur>"
```
Story supprimable via `DELETE /stories/{gid}`.

## 4. Présenter + GATE « go »
Présenter le rapport. **Ne jamais corriger sans validation explicite.** Demander à l'utilisateur lesquels lancer (il peut n'en vouloir qu'une partie). Les tickets PARTIEL/INCOMPRIS sans piste sûre restent en attente d'info.

## 5. Corriger — fan-out de correction (sur go : 1 agent par correction, max 5)
Pour chaque correction validée, déléguer à un agent qui corrige **dans le bon dossier projet** identifié.
- **Routage préférentiel** : router vers l'agent spécialisé le plus pertinent pour le projet ciblé (ex. pour un projet dont le thème embarque le moteur responsive morph, un agent `morph-*` ou `regression-tester`) plutôt que `general-purpose`. Découvrir les agents disponibles, ne pas en supposer.
- Doctrine de l'utilisateur (cf. `~/.claude/CLAUDE.md`) : **cause racine** (pas de rustine), **DRY** sur le code/filters/hooks **du projet ciblé**, rien en dur, code moderne perf/sécu/LCP.
- Plusieurs agents sur **le même projet en parallèle** → isoler en **git worktree** ; sinon édition directe.
- Chaque agent produit le diff + un test qui prouve le **comportement** corrigé.

## 6. Valider — adversarial + Playwright
- Pour chaque correctif (fan-out autonome non surveillé : critère de délégation rempli) : `cliff-stack:independent-critic`, avec le diff, l'URL ou la commande qui montre le symptôme du ticket, et le critère « le symptôme décrit a disparu, sans régression ni cause laissée en place ». Rejeter si le verdict est réfuté ; non concluant = pas résolu. Les critiques de correctifs front ou éditeur (Playwright) se lancent un par un, jamais en parallèle : la session navigateur est partagée.
- Confiance < 100 % → **test Playwright réel** sur le site local (vraies actions : clic, Ctrl+S, resize — jamais une simulation programmatique). Réutiliser `wp-save-ui-test` / `verify` si pertinent.
- Ne déclarer « résolu » qu'après validation par signal sémantique DIRECT (l'élément réel ciblé), jamais par proxy.

## Garde-fous
- Lecture Asana libre ; **écriture Asana** limitée aux commentaires validés par l'utilisateur (étape 3) ; **écriture code** seulement après go.
- Token jamais en clair (lu en interne par le module, jamais en argv/historique).
- Échouer visiblement : ne jamais rapporter un succès si une étape a été sautée ou un workspace ignoré en silence.
