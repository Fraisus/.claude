# agent-builder — référence condensée

> Chargé à la demande. Le SKILL.md porte la procédure ; ici : tableaux, gabarits, formats.

> **Anti-péremption** : ce fichier contient des faits VOLATILS (champs frontmatter, IDs de modèle, numéros de version) volatils par nature. Avant de t'appuyer dessus, re-confirmer au runtime via `/agents`, la doc officielle (code.claude.com/docs/en/sub-agents) ou Context7. Les **principes** (single-responsibility, moindre privilège, density, finding-contract) sont stables ; les **listes/versions** ne le sont pas.

## 1. Champs frontmatter officiels (source : code.claude.com/docs/en/sub-agents)

| Champ | Requis | Règle |
|---|---|---|
| `name` | Oui | kebab-case, unique sur TOUT l'arbre (`~/.claude/agents/` + `.claude/agents/` récursifs). Collision dans un même scope = un fichier ignoré sans warning. Sans "claude"/"anthropic". Le filename peut différer du `name`. |
| `description` | Oui | QUAND déléguer + déclencheurs. 3e personne. `use PROACTIVELY` pour pousser la délégation auto. C'est le SEUL signal de routage. |
| `tools` | Non | Allowlist. Omis = hérite tous les tools. Moindre privilège. `mcp__server` / `mcp__server__*` pour un serveur entier. |
| `disallowedTools` | Non | Denylist, appliquée AVANT `tools`. « tout sauf X ». `mcp__*` retire tout MCP. |
| `model` | Non | `haiku`/`sonnet`/`opus`/`fable`/ID complet/`inherit`. Défaut = `inherit`. |
| `permissionMode` | Non | `default`/`acceptEdits`/`auto`/`dontAsk`/`bypassPermissions`/`plan`. Le mode parent `bypassPermissions`/`acceptEdits` prime et ne peut être abaissé. |
| `skills` | Non | Précharge le CONTENU complet de skills au démarrage (pas juste la description). |
| `memory` | Non | `user`/`project`/`local` → apprentissage inter-sessions ; active auto Read/Write/Edit. `project` = défaut recommandé. |
| `mcpServers` | Non | Serveurs MCP scoped à l'agent (inline ou référence). Inline = hors contexte principal (n'alourdit pas le contexte parent). |
| `hooks` | Non | Hooks de cycle de vie scoped à l'agent (`PreToolUse`/`PostToolUse`/`Stop`→`SubagentStop`). |
| `isolation` | Non | `worktree` = copie git isolée (édits parallèles). Auto-nettoyée si aucun changement. |
| `effort` | Non | `low`/`medium`/`high`/`xhigh`/`max`. Override l'effort de session. |
| `background` | Non | `true` = toujours en tâche de fond. |
| `color` | Non | `red`/`blue`/`green`/`yellow`/`purple`/`orange`/`pink`/`cyan` (lisibilité UI). |

Tools INDISPONIBLES aux sous-agents même si listés : `AskUserQuestion`, `EnterPlanMode`, `ExitPlanMode` (sauf `permissionMode: plan`), `ScheduleWakeup`, `WaitForMcpServers`.

Plugin agents : `hooks`/`mcpServers`/`permissionMode` ignorés (sécurité).

## 2. Choix du modèle (charge cognitive)

> Les **alias** (`haiku`/`sonnet`/`opus`/`fable`/`inherit`) sont stables ; les **IDs complets** (ex. `claude-opus-5-5`, `claude-sonnet-5-5`) et « le plus récent » sont volatils → vérifier l'ID courant au besoin, ne pas le coder en dur dans un agent.

| Alias | Quand |
|---|---|
| `haiku` | Lookup, recherche fichiers, exploration read-only, coût/latence prioritaires. |
| `sonnet` | Analyse équilibrée, review de patterns, workflow standard. |
| `opus` | Raisonnement profond, contrats cross-zone, gating, arbitrage adverse. |
| `inherit` | L'agent doit suivre le modèle de la session (défaut). |

## 3. Gabarit de system prompt maison (corps du .md)

```
Tu es le **<RÔLE> spécialiste de <ZONE/MISSION UNIQUE>** pour <produit/contexte>.
Tu possèdes UN job : <mission en une phrase>. <read-only ? / écrit ?>

## Discover the environment first (nothing hardcoded)
<comment trouver paths/prefix/valeurs au runtime — 0 hypothèse d'env>

## Domain knowledge you own (stable)
<invariants/connaissances stables à porter, évite de re-dériver>

## Your workflow
1. <étape concrète> … N. <validation finale>

## Cardinal invariants / breaks_if_touched
<liste d'alarme : ce qui casse si on y touche>

## Cross-zone links — announce BEFORE proposing
<ce que l'agent NE fait pas → délègue à tel agent/skill (anti-doublon)>

## Constraints
- Anti-proxy : jamais « résolu » par proxy (longueur, flag) — signal sémantique direct.
- <read-only / concision / format de sortie>
```

## 4. Finding contract (agents producteurs de findings UNIQUEMENT)

À coller dans le prompt de l'agent. Conditionnel : sauter pour un agent purement exécutant.

```
## Finding contract (obligatoire)
Chaque finding remonté DOIT porter :
- direct_signal      : commande/lecture exacte qui le prouve (jamais « il semble »)
- refutation_attempt : où j'ai cherché un mécanisme compensatoire (liste des endroits)
- baseline           : ce comportement existe-t-il SANS le plugin / hors contexte ?
- trigger_frequency  : fréquent | marginal en usage distribué réel
- verdict            : confirmed | false_positive
                       (« unproven » INTERDIT en sortie finale)
Un champ vide = signal visible, pas un trou.
Ne JAMAIS conclure d'une absence locale (« je ne vois pas X lire le flag → bug »).
```

## 5. Passe adverse — critère passé à `independent-critic`

Appel à `cliff-stack:independent-critic` (protocole, périmètre et forme du verdict chez lui). Entrée : chemin du fichier agent + le critère ci-dessous, jamais le raisonnement de conception.

```
AXE 1 — Déclenchement
  3-4 requêtes-utilisateur réalistes. Pour chacune : la `description` délègue-t-elle à cet agent ?
  → exhiber ≥1 faux négatif OU faux positif, ou prouver honnêtement qu'il n'y en a pas.
AXE 2 — Chevauchement
  vs chaque agent existant proche : % d'overlap estimé + agent le plus proche nommé.
AXE 3 — Tools/model
  sur-privilège (tool inutile) ? sous-privilège (workflow infaisable) ? model adéquat ?
AXE 4 (si finding-producteur) — Contract probant
  tenter un finding-bidon : le contract le bloquerait-il ? champs probants ou cosmétiques ?
```
Verdict survit → livrer ; réfuté → corriger puis re-challenger ; non concluant → nommer ce qui manque pour trancher.

## 6. Format d'éval (baseline avant rédaction)

```json
[
  {
    "query": "requête-utilisateur réaliste",
    "expected_agent": "<name attendu, ou 'aucun' pour un leurre>",
    "expected_behavior": "ce que l'agent doit produire/faire"
  }
]
```
≥3 scénarios + ≥1 leurre (requête voisine qui ne DOIT pas déclencher). Mesurer sans l'agent (baseline) puis avec.

## 7. Squelette de fichier agent

```markdown
---
name: <kebab-case>
description: <QUAND déléguer + triggers ; 3e personne ; use PROACTIVELY si voulu>
tools: Read, Grep, Glob, Bash
model: <haiku|sonnet|opus|inherit>
color: <couleur>
---

<system prompt selon le gabarit §3, + finding contract §4 si producteur>
```

## Chiffres-clés
- `description` : seul signal de délégation → triggers explicites obligatoires.
- Overlap toléré entre agents : < ~40 % (au-delà → fusionner ou redélimiter).
- Passe adverse : 1 appel à `independent-critic` (pas 100), proportionné.
- Profondeur max de sous-agents imbriqués : 5 (niveau 5 ne reçoit pas l'outil Agent).
