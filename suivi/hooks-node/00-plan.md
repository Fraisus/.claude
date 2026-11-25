# Hooks de cliff-stack : de PowerShell à node

But : même comportement, chaque hook en node (`hooks/scripts/*.mjs`, lancé comme `inject-method.mjs` : `"command": "node"`, `"args": [...]`), pour passer d'environ 600 à 1 100 ms par appel à environ 150 ms (mesuré en session réelle le 25/11/2026 par `--debug-file`).

## Mesure qui fixe l'ordre (session réelle du 25/11/2026)
| Hook | ms par appel | Déclenché sur |
|---|---|---|
| guard-proof-before-done | 4 941 | chaque fin de tour |
| guard-budget-agents | 1 131 | Agent, Workflow, SendMessage, SubagentStop |
| turn-timer, guard-cause-before-code | 850 | chaque saisie ; chaque Write, Edit, Bash |
| log-agents | 851 | chaque fin de sous-agent |
| guard-private-read | 601 | chaque Read, Edit, Grep, Glob, Bash |
| guard-write-scope, guard-secrets-read, guard-git-destructive, guard-root-hygiene | non mesurés un par un, même lanceur | chaque Bash (les quatre en série) |
| guard-agent-context, inject-method (node) | 147, 160 | référence |

Un appel Bash paie aujourd'hui six hooks PowerShell en série : c'est le chemin chaud.

## Lots, dans cet ordre
1. Chemin chaud du shell : guard-write-scope (412 lignes), guard-secrets-read, guard-git-destructive, guard-root-hygiene (326), guard-private-read, guard-cause-before-code. Barrières de sécurité : relecture adverse obligatoire (code partagé entre projets).
2. Fin de tour et saisie : guard-proof-before-done, turn-timer, play-sound.
3. Agents : guard-budget-agents (354), log-agents, guard-agent-resume (déjà sur `agent-usage.mjs`).
4. Écriture : phpcs-wp-native, css-order (a déjà `css-order.mjs`), guard-console-window, guard-rules-style, prune-session-temp.

## Méthode
- Un lot par groupe, porté ligne à ligne : mêmes refus, mêmes messages, mêmes journaux (`decisions.jsonl`, `agents.jsonl`), mêmes variables d'essai (`CLIFF_EQUIPE_*`).
- Les essais existants (`hooks/tests/*.ps1`) restent l'oracle : chacun pointe le script node à la place du PowerShell et doit rester vert sans changer ses attendus ; puis une session réelle `claude -p --debug-file` mesure les durées.
- Le PowerShell porté se retire dans le même commit que son câblage dans `hooks.json` (pas de double).
- Fonctions communes (lecture du payload avec BOM, résolution du dossier de projet, sortie d'un refus) dans un module partagé dès leur deuxième usage.
