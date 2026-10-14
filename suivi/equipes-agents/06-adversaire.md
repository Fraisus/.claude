# 06 · Adversaire (independent-critic, régime adversaire) sur 05-conception.md

Rendu en conversation le 2026-10-14, recopié tel quel par le thread principal (le critique n'écrit pas de fichier). Aucune exécution : D1 à D15 reposent sur la doc officielle (hooks, sub-agents, agent-teams, permission-modes, env-vars, lues par curl le jour même) et la config réelle ; M1 à M10 restent à faire.

## Verdict : réfuté

- (a) composition automatique : tombe, la composition est écrite par le LLM, le script ne fait que la contrôler.
- (b) escalade P1 ↔ P2 : tombe en partie, compteurs dépendants d'un hook qui ne voit pas tous les cas ; descente P2 → P1 fondée sur un événement inexistant.
- (c) faisabilité harness : tombe sur le sens de `prompt_id` et la nature de `SubagentStart`.
- (d) faux positifs : tombe sur cinq gestes fréquents.
- (e) doublons : écarts mineurs.
- (f) CLAUDE.md court : survit.

## Défauts (graves d'abord)

1. **D1** (bloque en mode `bloquer`). Règle 12 : `prompt_id` = « UUID identifying the user prompt currently being processed » (hooks.md l.735), donc toute une exécution autonome partage le même `prompt_id` ; une réponse à `AskUserQuestion` n'en ouvre pas un nouveau. Un plan validé à 12 agents est refusé au 7e lancement ; la clause « empreinte déjà lancée » refuse la relance à l'identique après erreur d'API. Les lancements des sous-agents (morph-orchestrator) comptent aussi.
2. **D5** (bloque). Contrôle Workflow `restant = total_hors_plan - lances_hors_plan` : `research-arbitrate.js:14` déclare 12 = total ; un seul agent lancé plus tôt → refus d'un workflow légitime ; `agents-max-user-ok` (20 agents validés) passe la logique existante puis tombe sur `restant` : voie de validation explicite morte. Rien ne dit comment la garde trouve le plan d'un Workflow (pas de `prompt`, `args.plan` non lu).
3. **D2** (dégrade P2). `SubagentStart` se déclenche pour sous-agents, reprises et chaque message d'un coéquipier in-process (hooks.md l.2507), entrée `agent_id`, `agent_type` seulement : il ne distingue pas coéquipier et sous-agent. En premier plan l'`agentId` n'arrive qu'au PostToolUse (l.1878-1881). P2 à 3 coéquipiers + un critique lancé = 4 « distincts » > 3 : l'agent légitime reçoit « tu es en surnombre ».
4. **D4** (dégrade la seule barrière qui compte). `ask` ne vise que `Edit(~/.claude/settings.json)` et `settings.local.json` utilisateur ; aucune règle sur `<projet>\.claude\settings*.json`. permission-modes.md l.622-630 : `.claude` protégé mais en auto « Routed to the classifier », pas à l'utilisateur ; `guard-write-scope.ps1:267-280` ne renvoie `ask` que sur commande shell mutante, Edit ne passe pas par lui. Le modèle peut donc poser `CLIFF_EQUIPE_P2=1` ou relever un budget. §7 (« les deux portes passent par l'utilisateur ») est faux. À mesurer : Edit de `<projet>\.claude\settings.local.json` en session auto.
5. **D6** (dégrade). `AGENTS_MAX = args.agentsMax ?? 10` : `generator-critic-verifier.js:36-38`, avec `agentsMax: 30, maxIterations: 9` → 28 agents alors que la garde lit `// agents-max: 10`. L'accident de `guard-workflow-budget.ps1:2-4` (106 agents).
6. **D3** (dégrade). Refuser tout `name` hors P2 : sub-agents.md l.931-933, Claude nomme des sous-agents de lui-même pour les reprendre par nom ; `fable-mode:44` recommande les agents persistants. Étape 1 sans flag : un `name` ne crée aucun coéquipier mais est refusé avec un message faux. Étape 4 avec flag : chaque nommage spontané devient un refus.
7. **D7** (dégrade). « types inconnus = écrivains » : Explore et Plan sont intégrés sans fichier (sub-agents.md l.33-47) ; `~\agents\` n'existe pas ; `subagent_type` = `cliff-stack:independent-critic` alors que le fichier est `independent-critic.md` (`name` sans `:`), préfixe non retiré → classés écrivains. 3 Explore en parallèle, le 4e refusé « plafond écrivains ».
8. **D8** (contredit (a)). `plan.ps1` n'écrit ni `zones`, ni `preuves`, ni `graphe.aretes`, ni les couplages ; `verifier` calcule sur des notes posées par le modèle et n'appelle pas `co-changement.ps1` ; §4.2 est de la prose ; « fichiers candidats » (§4.3) absent du schéma §2.3 ; le skill ne se charge que si le modèle juge qu'il faut plus d'un écrivain.
9. **D9** (contredit (b)). `SubagentHandback` n'existe qu'en mode auto (hooks.md l.1895) ; l'extension VS Code peut démarrer en Manual → aucun mode `rapport`. En premier plan `agent_id` → zone connu après le handback. Le JSON SIGNAL n'a pas le chemin du plan. `plan.ps1` n'a aucune sous-commande pour enregistrer un signal ; `contrats[].reecritures` n'est incrémenté par rien.
10. **D10** (contredit (b), partiel). Descente P2 → P1 sur « tâches livrées » : sans outils Task (non activés §3.9), `TaskCompleted` ne se déclenche pas ; seul `TeammateIdle` existe et n'est pas utilisé comme mesure ; « perte des coéquipiers » sans détecteur. Ce qui marche : `continue:false` sur `TeammateIdle` quand palier ≠ P2.
11. **D11** (dégrade). `steriles` écrit par le hook (`rapport`, empreinte de la sortie de l'ouvrier) et par `integrer.ps1` (empreinte de sa vérification) : double compte ou remise mutuelle à 1 ; contredit « compteurs écrits par le hook seul ».
12. **D12** (dégrade). Règle 4 refuse hors {validé, actif, relance} ; le cycle a `intégration` ; la relecture finale (briefée `plan:`) est refusée « plan non validé » ; personne ne pose `actif` ni `intégration`.
13. **D14** (dégrade). `.claude` est un chemin protégé (permission-modes.md l.643-655, exceptions `worktrees/`, `agent-memory`) : chaque Edit de `plan.json` part au classifieur en auto, invite en Manual. `git check-ignore .claude/equipes/x` dans Réactorm : non ignoré.
14. **D13** (mineur). `playwright_simultanes` jamais appliqué ; §3.7 lance jusqu'à 2 `independent-critic` en parallèle alors qu'il exige d'être seul sous Playwright.
15. **D15** (mineur). Renvois « §3.6 » erronés (intégration est en §3.5) ; P0 → P1 « besoin d'un vérificateur » contredit « NE PAS utiliser pour un seul vérificateur » ; « 32 skills » oublié dans plugin.json ; retirer une variable n'a pas d'effet à chaud (env-vars.md l.83).

## Soupçons non reproduits

- **S1** : `name`, `isolation`, `run_in_background` dans `tool_input` de `PreToolUse Agent` (M4) ; la doc ne liste que prompt, description, subagent_type, model (hooks.md l.1872-1879). Si absent : règles 1 à 3 inertes.
- **S2** : une `env` posée à chaud atteint-elle un hook lancé ensuite (M8) ; probable d'après env-vars.md.
- **S3** : `independent-critic` avec un `schema` de workflow rend-il du JSON conforme, et articulation avec `SubagentHandback`.
- **S4** : un coéquipier émet-il `SubagentStop` ? Sinon « coéquipiers vivants » saturé jusqu'au TTL.
- **S5** : remonter en P2 une seconde fois dans la même session (« One team per session ») ?

## Exercé

Lecture intégrale de 05, 01, 02 ; `~\settings.json` (ask), `hooks.json`, `guard-workflow-budget.ps1`, `log-critic.ps1`, `guard-write-scope.ps1` (l.1-80, 255-300) ; frontmatter `tools:` des 16 agents ; `ls ~\agents` (absent) ; `plugin.json` ; `fable-mode\SKILL.md` ; en-têtes des deux workflows ; `.claude\settings.json` de Réactorm et `git ls-files .claude` ; `git check-ignore` sur `.claude/equipes/x` (non ignoré) et `~\.equipe-log/x` (ignoré, `.gitignore:9`) ; pages officielles par curl puis grep ciblés.
