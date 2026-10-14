# 02 · Existant : ce que la configuration actuelle fait, ou empêche, pour des équipes d'agents

Éclaireur « existant », lecture seule, le 2026-10-14. Chemins abrégés : `~` = `C:\Users\cliff\.claude`, `CS` = `~\skills\cliff-stack`, `R` = `C:\MAMP\htdocs\webidi\Reactorm`. « non mesuré » = déduit de la lecture, pas déclenché.

## 1. Inventaire des rôles

Agents du plugin (`CS\agents\*.md`, frontmatter l. 1-12) : 16, conforme à `CS\.claude-plugin\plugin.json` (« 16 agents »). Aucun dossier `~\agents\` n'existe.

| Agent | Modèle | Outils | Écrit ? | Rôle |
|---|---|---|---|---|
| framing-critic | opus | Read, Grep, Glob, Bash | non (Bash « pour mesurer », l. 68) | cadrage d'un plan avant code |
| independent-critic | opus, effort medium | Read, Grep, Glob, Bash, Playwright | non (l. 32-33) | vérificateur, réfute un résultat |
| untrusted-content-auditor | opus | Read, Grep, Glob | non | lecteur isolé de contenu étranger |
| morph-orchestrator | opus | Read, Grep, Glob, **Agent** | non (l. 103) | orchestrateur de zone |
| morph-blocks-auditor, morph-editor-agent, morph-build-cache-agent, morph-serve-front-agent, morph-signature-contracts-agent | opus | lecture + Bash (+ Playwright/Context7 selon zone) | non | producteurs ou critiques de zone |
| regression-tester | opus | lecture + Bash + Playwright | non | vérificateur de bout en bout |
| wp-block-pipeline-tracer | opus | lecture + Bash + **Edit** + Playwright | oui, instrumentation temporaire retirée avant rendu | traceur |
| asana-ticket-analyzer | sonnet | Bash, Read, Glob, Grep | non | analyste, 1 par ticket en fan-out |
| project-idea-validator | (hérité) | **Agent**, Web, **Write, Edit** | mémo seulement (l. 44) | orchestrateur de 3 analystes |
| competitive-analyst, market-researcher, trend-analyst | sonnet | WebFetch, WebSearch | non | analystes |

Agents de Réactorm (`R\.claude\agents\`) : reactorm-generation (opus, Web+Context7), reactorm-infra-gpu (opus, Web), reactorm-machines et reactorm-securite (opus, Bash+Web), untrusted-content-auditor (opus, lecture seule, doublon de nom avec celui du plugin, distinct par l'espace de noms `cliff-stack:`), et **reactorm-interface** (`model: inherit`, Read, Grep, Glob, Bash, **Edit, Write**, Skill) : le seul travailleur qui écrit, dans tout le parc. `R\CLAUDE.md:192` le confirme : « en lecture seule sauf `reactorm-interface` ».

Constat : **aucun rôle générique de travailleur (codeur) n'existe**. Les « codeurs » de `R\CLAUDE.md:188` sont des agents génériques sans définition ; dans les workflows, le générateur est un `agent()` anonyme (`~\workflows\generator-critic-verifier.js:123-130`).

Orchestrateurs et leur mode de dispatch :
- `morph-orchestrator` (`CS\agents\morph-orchestrator.md:84-92`) : triage en zones primaires et impactées, liste des coutures, producteur (zones indépendantes en appels Agent parallèles, dépendantes en séquence), puis **toujours** un critique et un « sweep critic » (l. 73, 89), jamais deux agents Playwright à la fois (l. 89), porte finale `regression-tester` (l. 91). Contrat de finding obligatoire (l. 60-69). Table de routage figée par zone (l. 26-31). Spécifique au moteur morph.
- `project-idea-validator` (l. 16-20) : 3 analystes en parallèle, synthèse par lui.
- skill `asana-triage` (`CS\skills\asana-triage\SKILL.md:52, 69, 77`) : 1 agent par ticket, max 5 en parallèle, puis 1 agent par correction après « go », `independent-critic` par correctif.
- Imbrication (sous-agent qui lance des sous-agents) : supposée par ces deux agents qui portent l'outil Agent ; non mesurée ici.

## 2. Ce qui tient lieu de « cadrer »

| Pièce | Ce qu'elle juge | Artefact produit |
|---|---|---|
| Phase `Gate` des deux workflows (`generator-critic-verifier.js:98-111`, `research-arbitrate.js:136-147`) | « le multi-agent est-il justifié ? », schéma `{justified, reason, cheaperAlternative}` (l. 47-55 / 40-48), effort low | un objet JSON dans le résultat du run ; aucun fichier. Refus = `return {aborted}` |
| Phase `Cadrage` de research-arbitrate (l. 150-157) | sous-questions + voies de sources | JSON en mémoire du script |
| `framing-critic` | cause à la source, bon niveau, réutilisation (5 questions, l. 27-33) ; verdicts `framing_sound | reframe_needed | wrong_level | duplicates_existing | treats_symptom` (l. 51) | rapport dans la conversation, aucun fichier ; il ne juge **pas** la composition d'équipe |
| `morph-orchestrator` étapes 1-2 (l. 86-87) | zones primaires/impactées, coutures | rapport, aucun fichier |
| Mode plan | `ExitPlanMode` ne déclenche qu'un son (`CS\hooks\hooks.json:53-58`) | aucune règle maison ne s'y accroche |
| Réactorm | le thread principal écrit les contrats communs (`R\CLAUDE.md:188`), dans `docs/contrat_*.md` ; durée et limite annoncées pour un gros chantier (l. 199) | **seul endroit où le cadrage laisse un fichier** (contrats), mais pas de plan d'équipe (zones, fichiers permis, budget) écrit quelque part de façon normée |

Aucune pièce ne produit aujourd'hui un « plan d'équipe » (zones, fichiers permis, rôles, vérification, budget) comme artefact.

## 3. Ce qui tient lieu d'« activer » et d'« intégrer »

Workflows (`~\workflows\`, lancés par `scriptPath`, `meta.whenToUse` l. 4) :
- **generator-critic-verifier.js** : Gate → boucle `MAX_ITER` de Generate (en `isolation: 'worktree'`, l. 129 ; reprise de l'état du tour précédent par `git diff | git apply`, l. 127) → Critique ×`N_CRITICS` en `parallel` (l. 137-148, reçoit l'artefact et le critère, pas le raisonnement) → Verify déterministe (l. 160-168, `ranRealTests` sinon `pass=false`) → best-so-far et arrêt sur non-amélioration (l. 172-195). Intégration : **aucune fusion**, « review puis merge manuel » (l. 205). Budget : `AGENTS_MAX = 10` (l. 36), itérations calculées pour le tenir (l. 38).
- **research-arbitrate.js** : Gate → Cadrage → Sweep 4-5 voies en parallèle (l. 197-204) → Croisement (barrière) → Challenge dans le budget restant (l. 219-228, ce qui dépasse est transmis « non challengé ») → Arbitrage (l. 274-289). Routage par rôle via `agentType: 'Explore'` pour la voie projet (l. 189) et par modèle `sonnet` pour la collecte web (l. 196). Lecture seule.
- Ni l'un ni l'autre n'appelle un agent maison (`independent-critic`, `framing-critic`) : critiques et vérificateurs sont des prompts inline. L'option `agentType` existe pourtant (l. 201).
- Schémas réutilisables déjà écrits : GATE, CRITIQUE (`defects/verdict/exercised`), VERIFY (`ranRealTests/commandUsed/pass/output`), CHALLENGE, VERDICT.

Règles d'équipe Réactorm (`R\CLAUDE.md`) :
- l. 187 : trois tailles (retouche sans agent ; fonction moyenne, un agent de bout en bout ; gros chantier, « 20 à la fois au maximum, un par sujet sur des zones disjointes »).
- l. 188 : contrats par le thread principal seul ; codeurs en parallèle, une zone disjointe chacun, « cahier des charges, fichiers permis et essais » ; deux codeurs jamais sur le même fichier ; fichiers partagés au thread principal ; aucune opération git pour les codeurs.
- l. 189 : fichier du moteur jamais laissé non compilable.
- l. 190 : **une seule relecture par livraison**, à la fin (reactorm-securite, reactorm-machines, ou l'adversaire d'une équipe), ne bloque que le grave.
- l. 191 : file des écarts dans `docs/suivi.md`, jamais un agent de plus.
- l. 196 : estimation et temps réel notés par agent, écart > 50 % dit (aucun fichier ni hook ne le porte).
- l. 202 : un changement de méthode en cours de route **remplace** la voie en cours ; arrêter ou réorienter l'agent qui tourne d'abord.
- l. 206-213 : niveaux de vérification 0 à 4 ; les codeurs ne lancent que le niveau 0.
Aucune de ces règles n'est tenue par un hook : fichiers permis, non-chevauchement, « pas de git pour les codeurs » sont déclaratifs.

## 4. Barrières qui touchent les agents

- **guard-workflow-budget** (`CS\hooks\scripts\guard-workflow-budget.ps1`, câblé `hooks.json:43-50`, matcher `Workflow` seul). Bloque (exit 2) : lancement par `name:` sans script (l. 52-57) ; script sans ligne `// agents-max: N` (l. 70-72) ; N > `$MAX_AGENTS = 12` (l. 27) sans `// agents-max-user-ok` (l. 61-67). Laisse passer : `resumeFromRunId` (l. 38), `scriptPath` introuvable (l. 46), erreur de parsing (l. 73-75). Limites : N est **déclaratif**, jamais comparé au nombre réel d'`agent()` ; le marqueur d'accord est écrit par le modèle, couvert par le `ask` global sur `Workflow` (`~\SECURITY-SETUP.md:27`). **Ne voit pas l'outil Agent** : un fan-out par sous-agents (P1) ou par équipe (P2) n'a aucun plafond mécanique.
- **log-critic** (`CS\hooks\scripts\log-critic.ps1`, `SubagentStop`, `hooks.json:102-110`) : n'écrit que pour `agent_type` finissant par `independent-critic` (l. 17), 400 caractères du dernier message (l. 20), dans `~\.critic-log\critics.jsonl` (41 lignes au 2026-10-14). Aucune trace des autres sous-agents (durée, rôle, nombre, issue) : **aucune télémétrie d'équipe**.
- **guard-write-scope** (`hooks.json:13-19`, matcher `Bash|PowerShell` seulement) : zones permises = `CLAUDE_PROJECT_DIR`, `c:\tmp`, `~`, `%LOCALAPPDATA%\Temp\claude`, `write-scope-extra-roots.txt` (l. 35-62) ; un chemin relatif est toujours permis (l. 139-140) ; un `cd` absolu hors zone avant une commande destructrice est bloqué (l. 311-322). Il ne contient aucune mention de worktree. Un worktree créé sous la racine du projet est donc couvert par le préfixe du projet ; un worktree hors de `CLAUDE_PROJECT_DIR` (dépôt dont la racine git est au-dessus du dossier de projet) serait refusé sur chemin absolu mais passerait sur chemin relatif (non mesuré). Il ne voit pas Write/Edit : ces outils ne sont filtrés que par `guard-root-hygiene` et `guard-private-read`.
- **guard-root-hygiene** et **guard-private-read** se règlent sur `CLAUDE_PROJECT_DIR` (`guard-root-hygiene.ps1:33`, `guard-private-read.ps1:21-25`) : dans un worktree, la « racine » jugée reste celle du projet principal, et `private-paths.txt` se résout par rapport à elle (non mesuré ; les dossiers privés non suivis par git n'existent de toute façon pas dans un worktree). Réactorm double ce garde par `R\.claude\hooks\guard_private_read.py` (`R\.claude\settings.json`).
- **Règles `ask`** (`~\settings.json:52-58`) : `Workflow`, Edit des hooks, de `settings*.json`, de `write-scope-extra-roots.txt`. `Agent` est en `allow` (l. 16), sans effet en mode auto (`~\CLAUDE.md:9`) : le lancement d'un sous-agent n'interrompt jamais.
- Agent teams (P2) : aucune variable d'activation dans `settings.json` (`env`, l. 3-6), aucun réglage de coéquipiers : P2 n'est pas branché.

## 5. Plafonds et chiffres posés, et leurs contradictions

| Valeur | Unité | Où |
|---|---|---|
| ~10 agents | total par recherche/run | `~\suivi\equipes-agents\00-cahier.md:19`, `CS\skills\fable-mode\SKILL.md:44`, `fable-mode\reference\stack-maison.md:42` |
| 12 | total par Workflow (garde) | `guard-workflow-budget.ps1:27` (commentaire : « ~10 le 27/06, monté à 12 le 19/07 »), `~\README.md:108` |
| 10 | total du run | `generator-critic-verifier.js:13, 36` |
| 12 | total du run | `research-arbitrate.js:14, 31` |
| 5 | **en parallèle** | `asana-triage\SKILL.md:9, 52, 69` |
| 20 | **à la fois** | `R\CLAUDE.md:187` |
| 1 | agent Playwright à la fois | `rules\morph-blocks.md:32`, `morph-orchestrator.md:89`, `independent-critic.md:23`, `fable-mode\SKILL.md:44` |
| 1 | relecture par livraison | `R\CLAUDE.md:190` |

Contradictions : (a) ~10 en prose contre 12 dans la garde ; (b) deux unités mélangées, total par run (10/12) contre simultanés (5, 20), sans règle qui dise laquelle prime ni comment on compte un run qui change de palier ; (c) les 20 de Réactorm dépassent le plafond global, mais passent par l'outil Agent, que la garde ne voit pas ; (d) `stack-maison.md:42` dit que les workflows « peuvent dériver à 19-21 », périmé depuis que les deux scripts bornent à 10 et 12 ; `stack-maison.md:34-36` appelle « skill » les deux workflows ; (e) `agent-builder\SKILL.md:10` vise `~\agents\` alors que `~\README.md:71-73` impose `CS\agents\` pour toute nouvelle brique ; (f) `R\CLAUDE.md:208` renvoie à « CLAUDE.md global, « Vérification de fond » », section absente du CLAUDE.md global (grep vide) ; (g) `~\CLAUDE.md:8` compte « 4 hooks shell + guard-workflow-budget », `SECURITY-SETUP.md:11` en compte 7, `hooks.json` en câble davantage (guard-private-read sur tous les outils, l. 4-12 ; guard-console-window et css-order en PostToolUse).

## 6. Écarts par moment, et où la pièce devrait vivre

Conventions : toute brique (skill, agent, hook) dans `CS\` (`README.md:71-73`) ; scripts multi-agents dans `~\workflows\` (`README.md:46-48`) ; règles par sujet dans `~\rules\` mais déclenchées par `paths:` de fichiers (inadaptées à une règle d'orchestration sans type de fichier) ; règles propres à un projet dans son `.claude\rules\` ; journaux de chantier dans `~\suivi\`.

- **Cadrer** : manque un producteur de plan d'équipe (zones disjointes, fichiers permis, rôles, vérification, budget, palier) et son format d'artefact fichier. Place probable : un skill `CS\skills\<orchestration>\` (procédure dans le contexte principal, comme fable-mode), avec un gabarit de plan en `reference/`. À ne pas dupliquer : le schéma GATE des workflows, `framing-critic` (qui juge le niveau et la réutilisation, à appeler sur le plan, pas à réécrire), le format « cahier des charges, fichiers permis et essais » de `R\CLAUDE.md:188`.
- **Activer** : manque un rôle de travailleur générique qui écrit (aucun agent maison n'a Edit/Write hors exceptions bornées) ; place : `CS\agents\` via `agent-builder`. Manque une barrière sur le nombre d'agents lancés par l'outil Agent (P1) et par une équipe (P2) : extension de `guard-workflow-budget.ps1` ou hook voisin dans `CS\hooks\scripts\`, câblé dans `hooks.json`. Manque une barrière sur les « fichiers permis » d'un codeur (aujourd'hui déclaratifs). À ne pas dupliquer : la boucle Generate/Critique/Verify en worktree, le calcul de budget par phase, le routage `agentType`/`model` par voie.
- **Intégrer** : manque la fusion (les workflows s'arrêtent au « merge manuel », l. 205) et la rétro (rien n'enregistre durée, coût, écarts estimé/réel ; `R\CLAUDE.md:196` le demande sans support). Le journal `SubagentStop` de `log-critic.ps1` est le point naturel à généraliser plutôt qu'un second hook. À ne pas dupliquer : `independent-critic` comme vérificateur à contexte vierge, les schémas CRITIQUE/VERIFY, la règle « une seule relecture par livraison » et la file des écarts de Réactorm.
- **Escalade entre paliers** : rien n'existe. Ni signal mesuré (nombre de zones touchées, fichiers en conflit, échecs de vérification, durée, contexte consommé), ni décideur, ni état qui survive au changement de palier (les workflows rendent un objet en mémoire ; `resumeFromRunId` reprend le même script, pas un autre palier), ni règle de redescente. Seuls appuis : `R\CLAUDE.md:202` (une nouvelle voie remplace l'ancienne, arrêter l'agent d'abord), l'arrêt sur non-amélioration de `generator-critic-verifier.js:192-194` (un signal mesurable déjà codé), et `SendMessage` pour continuer un agent existant (`fable-mode\SKILL.md:44` recommande déjà les agents persistants). L'état d'équipe aurait sa place dans un fichier sous `~\suivi\<chantier>\` ou dans le projet, à décider par le concepteur.

## Implications pour le but

1. Les trois moments existent par morceaux, jamais reliés : Gate (cadrer en JSON), workflows (activer et vérifier), Réactorm (règles d'intégration en prose). Le travail est d'assembler, pas d'inventer.
2. Aucun artefact de plan d'équipe : sans fichier, ni l'activation « bête » ni l'escalade n'ont d'état à relire.
3. La seule barrière mécanique compte des agents de Workflow, sur déclaration ; P1 et P2 n'ont aucun plafond. Toute équipe composée automatiquement passe donc sous le radar actuel.
4. Il faut trancher l'unité du plafond (total ou simultané) et réconcilier 10, 12, 5 et 20 avant d'en coder un.
5. Le parc n'a qu'un travailleur qui écrit, propre à un projet : un rôle générique de codeur à zone bornée est le premier manque d'« activer ».
6. Les vérificateurs existent et sont bons (independent-critic, schémas VERIFY/CRITIQUE) : les workflows devraient les appeler par `agentType` plutôt que réécrire leurs prompts.
7. L'escalade n'a ni signal ni décideur ; le seul signal codé (non-amélioration entre tours) et la journalisation SubagentStop sont les points d'ancrage.
8. Les worktrees passent les gardes tant qu'ils sont sous la racine du projet ; à mesurer avant de s'y fier, surtout pour un projet sous la racine git.
9. Fable se dégrade sous un préambule prescriptif (`fable-mode\SKILL.md:12`) : la composition doit vivre dans des scripts et des hooks, pas dans un long texte injecté.
10. Corriger au passage, hors périmètre de ce chantier ou non : renvoi mort de `R\CLAUDE.md:208`, cible de `agent-builder`, chiffres périmés de `stack-maison.md`.
