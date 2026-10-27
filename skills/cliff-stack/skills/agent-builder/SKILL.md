---
name: agent-builder
description: "Use when the user asks to create, build, design or improve a Claude Code subagent, global or project. Triggers: « crée/fais/conçois un agent sur X », « un agent spécialisé Y », « un sous-agent qui Z », « améliore/mets à jour cet agent », « nouvel agent », « subagent ». NE PAS utiliser pour une procédure ou une connaissance chargée dans le contexte principal (→ skill-builder), ni quand le travail demande des allers-retours fréquents ou partage le contexte de la conversation (le thread principal suffit, sans agent)."
---

# agent-builder — fabriquer un sous-agent Claude éprouvé (doctrine officielle + conventions maison + challenge adverse)

Produire un sous-agent **focalisé, déclenchable, au moindre privilège, et VALIDÉ par réfutation + éval** — pas un agent « qui a l'air bien » jugé sur une checklist. Cible par défaut : **global** (`~/.claude/agents/<name>.md`) ; **projet** (`.claude/agents/<name>.md`) si l'agent est propre à un codebase et doit être versionné.

## Principe directeur
Un sous-agent vit ou meurt sur **trois points de défaillance**, jamais sur la beauté de son prompt :
1. **Déclenchement** — la `description` est le SEUL signal de délégation ; si elle rate, l'agent est mort-né.
2. **Périmètre** — il chevauche un agent existant (anti-DRY) ou déborde/manque de tools.
3. **Fardeau de preuve** — un agent producteur conclut d'une absence locale (« je ne vois pas X lire le flag → bug ») = faux positif garanti. La preuve doit être DANS sa sortie, pas dans ta demande de vérification.
La valeur de l'outil = **être plus rigoureux que créer l'agent à la main**. Sans le finding-contract (§7) + la baseline (`reference/checklist.md` §6) + la passe adverse (§8), il n'a pas de raison d'exister.

## Procédure (ordre)

### 1. Auditer l'existant AVANT de créer (DRY, anti-doublon — règle cardinale)
Lister `~/.claude/agents/*.md` + `.claude/agents/*.md` du projet. Pour chaque agent proche, lire sa `description` + son périmètre. **Décider explicitement : créer OU mettre à jour un existant.** Jamais d'agent recouvrant >~40 % d'un autre — fusionner, ou délimiter des frontières nettes (cf. `Cross-zone links` des agents du plugin, par exemple `framing-critic`, `independent-critic`, `untrusted-content-auditor`).

### 2. Décider : agent vs skill vs main thread (doctrine officielle)
- **Agent** : travail auto-contenu, sortie verbeuse à isoler, tools à restreindre. Contexte **isolé** (démarre frais, ne voit pas l'historique).
- **Skill** : méthodologie/connaissance réutilisable **dans** le contexte principal. Besoin = « une procédure » → skill, pas agent.
- **Main thread** : aller-retours fréquents, phases partageant le contexte. Pas d'agent.

### 3. Frontmatter (champs officiels + règles)
- `name` : kebab-case, **unique sur tout l'arbre** (collision = fichier ignoré en silence), sans "claude"/"anthropic".
- `description` : **format maison = QUAND déléguer + déclencheurs + frontière « NE PAS … → tel agent ou skill » SEULEMENT**. 3e personne, what+when, triggers réels (FR + termes techniques), `use PROACTIVELY` si délégation proactive voulue. Le « quoi » va dans le corps.
- `tools` : **moindre privilège**. Allowlist read-only (`Read, Grep, Glob, Bash`) ; `Edit/Write` SEULEMENT si l'agent modifie. Chaque tool = élévation de privilège à justifier. `disallowedTools` pour « tout sauf X ».
- `model` : **selon la charge cognitive** — `haiku` (lookup/recherche), `sonnet` (analyse), `opus` (raisonnement profond/contrats), `inherit` (défaut). Justifier.
- Optionnels : `color`, `memory: project|user`, `isolation: worktree`, `mcpServers` (MCP scoped hors contexte principal).

### 4. System prompt structuré (le corps) — patterns maison
Au-delà du « rôle → workflow → contraintes » officiel, encoder (quand pertinent) ce qui fait la qualité des agents du plugin (`framing-critic`, `independent-critic`, `untrusted-content-auditor`) :
- **Rôle + mission unique** en tête.
- **Discover the environment first / nothing hardcoded** : paths/prefix/valeurs au runtime, 0 hypothèse d'env.
- **Workflow numéroté** 1→N, concret.
- **Domain knowledge / invariants** stables à porter.
- **breaks_if_touched** : liste d'alarme de ce qui casse si on y touche.
- **Cross-zone links** : ce que l'agent NE fait pas → déléguer (anti-doublon vivant).
- **Anti-proxy** : « jamais résolu par proxy (longueur, flag) — signal sémantique direct » ; une chaîne se prouve de bout en bout, de l'entrée réelle au résultat observé, jamais maillon par maillon.
- **Constraints** : read-only ?, concision, format de sortie.
Officiel : l'agent ne reçoit QUE ce prompt + l'env → être autoportant.

### 5. Densité — chaque token gagne sa place (PAS de compression en charabia)
Viser la **densité sémantique**, jamais la brièveté pour elle-même. Les modèles récents sont déjà *clear and direct* (doctrine Anthropic) ; le levier prouvé du gain de tokens, ce sont les **directives explicites**, pas le style télégraphique.
- **Couper** : remplissage (« we will now consider… »), redites, méta-phrases (« this section explains… »), politesses, exemples redondants.
- **Garder intact** : distinctions fines, invariants, **négations critiques** (« never delete », « ≠ fail-into-paid ») — c'est le contenu, pas du style.
- **Interdit** : monosyllabique/charabia dense qui sacrifie une nuance. Sous la *token complexity* intrinsèque de la tâche, la qualité chute → ne pas comprimer en-dessous.
- **Mesure** : JAMAIS par nb de caractères (métrique-proxy). Un prompt n'est « trop long » que si la passe adverse (§8) montre une instruction **diluée ou ignorée**.
- **Garde reasoning_extraction (conformité Fable, obligatoire)** : ne JAMAIS écrire dans un agent une directive « montre / explicite / transcris ton raisonnement EN RÉPONSE ». Sous Fable 5, cette formulation déclenche un refus (fallback silencieux vers Opus). Formuler toute exigence de rigueur en **actions de preuve** (reproduire, mesurer, grep, signal direct) ; le raisonnement vit dans les thinking blocks. Reformuler la DEMANDE (paraphrase) reste OK, transcrire le RAISONNEMENT interne non.

### 6. Anti-péremption — distinguer stable vs volatil
Ne jamais figer dans le prompt d'un agent un fait périssable comme certain (sinon il affirmera dans 1 an un fait périmé).
- **Volatil** (versions, IDs de modèle, « le plus récent », champs d'API, prix, « n'existe pas encore ») → soit **re-vérifier au runtime** (vérité terrain / Context7 / `/agents`) avant d'affirmer, soit **dater** (« vérifié AAAA-MM ») quand la re-vérif est impossible.
- **Stable** (principes, architecture, invariants, single-responsibility) → pas de marquage.
- Hiérarchie : un fait **re-vérifié > daté > récité**. Dates toujours **absolues** (jamais « récemment »).
- Préférer instruire l'agent à *découvrir au runtime* plutôt qu'à *réciter* (cohérent avec « nothing hardcoded » §4).

### 7. Finding contract — pour les agents qui PRODUISENT des findings (anti-faux-positif)
**Conditionnel** : si l'agent remonte des findings/diagnostics/bugs (auditeur, critique, regression-tester, zone analytique), son prompt DOIT imposer ce schéma de sortie par finding — sinon (agent purement exécutant), sauter cette section.
Cause des faux positifs : conclure d'une **absence locale** sans exécuter le test qui tranche. Le contract déplace le fardeau de preuve DANS l'agent :
```
Chaque finding remonté DOIT porter :
- direct_signal      : la commande/lecture exacte qui le prouve (jamais « il semble »)
- refutation_attempt : où j'ai cherché un mécanisme compensatoire (liste des endroits cherchés)
- baseline           : ce comportement existe-t-il SANS le plugin / hors contexte
                       (WP-natif, lib de base, comportement standard) ?
- trigger_frequency  : fréquent | marginal en usage distribué réel
- verdict            : confirmed | false_positive
                       (« unproven » INTERDIT en sortie finale : soit l'agent prouve,
                        soit il ne remonte pas le finding comme bug)
Un champ vide = signal visible, pas un trou.
```
Pourquoi pas un hook : un hook PreToolUse est un filtre syntaxique (chaînes/commandes), il ne peut pas juger « ce finding est-il prouvé ? » (sémantique). Le bon niveau = les instructions de l'agent, qui raisonnent.

### 8. PASSE ADVERSE OBLIGATOIRE — faire INVALIDER l'agent par `independent-critic` (cœur de l'outil)
Agent global ou de plugin (sert dans tous les projets, code partagé) : critère de délégation rempli. Agent projet (`.claude/agents/`) : seulement si un critère du CLAUDE.md global s'applique. Un seul appel à l'agent `independent-critic` (subagent_type `cliff-stack:independent-critic`, protocole chez lui), avec pour artefact le fichier agent écrit et pour critère les axes ci-dessous, sans le raisonnement de conception (gabarit : `reference/checklist.md` §5) :
- **Déclenchement** : 3-4 requêtes-utilisateur réalistes ; pour chacune, la `description` délègue-t-elle à CET agent ? Exhiber ≥1 **faux négatif** ou **faux positif**, ou prouver honnêtement qu'il n'y en a pas.
- **Chevauchement** : vs les agents de §1 ; chiffrer l'overlap, nommer le plus proche.
- **Tools/model** : prouver un sur-privilège (tool inutile) / sous-privilège (workflow infaisable), et l'adéquation du `model`.
- **Si finding-producteur** : vérifier que le finding-contract (§7) est imposé ET que ses champs seraient *probants* (pas juste présents) — le critique tente un finding-bidon pour voir si le contract le bloquerait.
Verdict réfuté → corriger → **re-challenger si une correction a touché description ou périmètre** (le delta n'est pas couvert par la première passe). Jamais de conclusion sur la seule checklist.

### 9. Test de déclenchement réel (axe DÉCLENCHEMENT)
Énoncer les requêtes-échantillons et **vérifier** que la `description` route au bon agent (pas aux leurres voisins). Un agent dont on n'a pas exercé le déclenchement a un angle mort garanti : chaque requête-échantillon se joue réellement, leurres voisins compris.

### 10. Sécurité = garde-fou dur
`tools`/`permissionMode`/`mcpServers` = surface de privilège : justifier chaque ajout, défaut au plus restrictif ; `bypassPermissions` jamais sans raison. Fetch URL externe / scripts → audit injection d'instructions. « Installer un agent = installer un logiciel. »

## Anti-patterns à refuser
Doubler un agent existant (sauter §1) • livrer sur checklist sans baseline (`reference/checklist.md` §6) ni passe adverse réelle (§8) • agent finding-producteur SANS finding-contract (§7) • finding conclu d'une absence locale (« je ne vois pas X → bug ») • figer un fait volatil sans le dater ni le renvoyer au runtime (§6) • comprimer en télégraphique/monosyllabique au prix d'une nuance (§5) • juger un prompt « trop long » au compteur de caractères • `description` pauvre en triggers / 1re-2e personne / énumérant le « quoi » • tools « au cas où » • `model` non justifié • prompt non autoportant • prompt non-trivial sans `breaks_if_touched`/`cross-zone`/anti-proxy • « résolu » par proxy • agent là où un skill convient • oublier l'axe déclenchement • `name` en collision / avec "claude"/"anthropic".

## Référence
- `reference/checklist.md` — champs frontmatter officiels (tableau), gabarit de system prompt maison, le finding-contract complet, canevas de la passe adverse (3 axes + test contract) + format d'éval JSON, squelette de fichier.
