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

## Audit à une semaine (à partir du 25/11/2026)
Question : le plafond `contexte_agent_max` (150 000) et le filet `autoCompactWindow` (200 000) sont-ils au bon niveau, et la méthode accélère-t-elle les lots ?
1. Par projet, `.claude/equipe-log/agents.jsonl` : par type d'agent, contexte du premier tour, contexte maximal, tours, cache lu ; part des agents qui dépassent le plafond.
2. `decisions.jsonl` : lignes `mode: contexte` (passations demandées) par agent ; lancement relié à son plan et sa zone par l'empreinte (`pre` puis `post`) ; refus d'imbrication.
3. Croiser avec la mesure par lot du projet (Réactorm : `docs/mesure.md`) : passations par lot, refus et défauts trouvés après, selon que le lot a eu une passation ou non, et selon le contexte maximal de ses écrivains.
4. Comparer au relevé du 25/11 : 56 000 au premier tour pour `general-purpose`, 33 000 pour `ouvrier-zone` ; 98,5 % des tokens en cache lu.
Décision : monter le plafond (et le filet de 50 000 au-dessus) si la plupart des lots ont plus d'une passation sans gain sur les refus ; le baisser si les refus suivent les contextes longs.

## État au 25/11/2026 au soir
- Les 17 hooks tournent en node (`hooks.json` : 28 appels `node`, aucun `powershell`), briques `lib/hook.mjs`, `lib/regex.mjs`, `lib/agents.mjs`, `lib/turn-timing.mjs` ; essais verts dans un miroir sans aucun `.ps1` et dans le plugin ; session réelle : refus attendus (racine, secret), aucune erreur de hook.
- `.ps1` retirés le 25/11 au soir ; les essais ne comparent plus qu'aux attendus (comparaison au `.ps1` sautée quand il manque). Sessions ouvertes avant : sans gardes jusqu'à leur relance.
- Constat : lancées en nombre, les gardes PowerShell rendaient 0 (laisser passer) au lieu de 2 sur des cas qu'elles bloquent seules.
- Corrigé le 25/11 : guard-agent-resume ne juge plus qu'une reprise d'agent fini (`finished` du meta) ; sémantique .NET de `s` (U+0085) et du point dans `lib/regex.mjs` et `readPayload`, relevée par la relecture adverse.
