# 05 · Conception : équipes d'agents composées, plafonnées et escaladables

Entrées : 00 à 04 (même dossier), `~\CLAUDE.md`, conventions lues sur `CS\agents\independent-critic.md`, `CS\hooks\scripts\log-critic.ps1`, `CS\hooks\hooks.json`. Abréviations reprises de 02 : `~` = `C:\Users\cliff\.claude`, `CS` = `~\skills\cliff-stack`. Rien n'est écrit ailleurs que dans ce document.

Idée directrice, en une phrase : **par défaut pas d'équipe ; un plan d'équipe fichier fait la décision, un hook unique en fait la barrière, et l'escalade est une relance à neuf depuis le thread principal, jamais une promotion.**

Statuts des pièces : **[N]** nouvelle, **[M]** modifiée, **[R]** réutilisée telle quelle.

---

## 1. Protocole d'escalade

### 1.1 Ce que le harness interdit, dit tel quel

- **Seul le thread principal peut monter en P2, par de nouveaux lancements nommés ; un sous-agent ne devient jamais coéquipier ; l'escalade passe donc par « état écrit dans le plan d'équipe, remontée au principal, relance à neuf au palier supérieur ».** (01 §1 et §2 : `name` ne crée un coéquipier que depuis la conversation principale ; pas d'équipe imbriquée ; chef fixe ; une équipe par session.)
- Un sous-agent ne lance ni workflow ni équipe (`Workflow` retiré à tout sous-agent ; 01 §1).
- Un workflow (P1b) ne peut ni demander à l'utilisateur en cours de run ni lancer de coéquipier : il finit, rend son résultat, et le principal relance (01 §4).
- P2 n'existe pas en `-p`/SDK et ne survit pas à `/resume` ou `/rewind` : toute escalade P2 vit dans une session interactive et doit tolérer la perte des coéquipiers (01 §2, implication 5).
- Windows : P2 en in-process seulement, coéquipiers sans worktree propre, donc partition des fichiers obligatoire (01 §7).

Contournement honnête : un travailleur qui découvre qu'il faut plus **ne lance rien** ; il rend un signal structuré dans son rapport, le hook le journalise dans le plan, le principal décide, réécrit le plan et relance de zéro les zones concernées au palier choisi. L'état transmis est toujours le plan (fichier) plus les diffs déjà produits, jamais le contexte vivant d'un agent.

### 1.2 Les paliers et leurs passages

Les seuils cités vivent dans `CS\hooks\budget-agents.json` (§3.1), clés entre crochets ; ils sont réglables et journalisés, aucun n'est validé publiquement (03 §2), ils se recalent après les deux semaines de mesure (§6).

| Passage | Signal mesurable qui le déclenche | Qui détecte | Qui décide | Ce qui est transmis |
|---|---|---|---|---|
| **P0 → P1** (cadrage) | Graphe du plan : au moins 2 zones sans arête de couplage > `[couplage_parallele_max]` (0,3) et chacune ≥ `[zone_min_fichiers]` fichiers ; **ou** besoin d'un vérificateur à contexte vierge selon les critères existants du CLAUDE.md global ; **ou** un conseiller de projet dont la description couvre un chemin du plan | script `plan.ps1 verifier` (calcule largeur, couplage, nombre de fichiers) | principal ; porte utilisateur si ≥ 2 écrivains (§4.5) | plan `statut: validé` |
| **P0 → P1b** | ≥ `[items_homogenes_min]` (5) éléments de même forme, même transformation, vérification déterministe disponible | script (`plan.ps1 verifier`, champ `fanout`) | principal ; porte native : `Workflow` est déjà une règle `ask` | plan + `args` du workflow |
| **P1 → P1b** (en cours) | un ouvrier rapporte `signal.type = fanout_homogene` avec la liste des éléments, nombre ≥ seuil | travailleur (champ du rapport), journalisé par hook | principal, puis `ask` du Workflow | plan mis à jour, liste des éléments en `args` |
| **P1 → P2** (en cours) | relais inter-zones passés par le principal ≥ `[relais_p2_min]` (3) dans le même plan, **ou** la même paire de zones relayée 2 fois, **et** le contrat entre ces zones ne peut pas être figé d'avance (le principal l'a réécrit ≥ 2 fois, compté dans le journal) | hook (compte les signaux `hors_zone_requis` / `contrat_insuffisant` des rapports) | principal propose ; **porte utilisateur toujours** ; barrière mécanique : variable de session `CLIFF_EQUIPE_P2=1` (§1.3) | plan `palier: P2`, journal, diffs intégrés ou mis de côté ; coéquipiers lancés à neuf avec le brief de zone |
| **P1 → P0** (descente) | un ouvrier rapporte `hors_zone_requis` vers la même zone 2 fois (couplage réel ≥ `[couplage_fusion_min]`) : les zones fusionnent ; s'il ne reste qu'une zone, le principal écrit lui-même | hook (compteur par paire), script `plan.ps1 escalade` | principal, sans porte (descente = moins de risque) | plan réécrit (zones fusionnées), journal |
| **P1 → arrêt** | même signature d'échec de vérification `[steriles_arret]` (3) fois de suite sur une zone, ou `[iterations_max]` atteint, ou échéance dépassée | script `integrer.ps1` (hache la sortie de vérification) ; hook refuse tout nouveau lancement sur la zone | **utilisateur** (le hook bloque, le principal rend compte) | journal, dernier diff, sortie d'échec |
| **P1b → P1 / P0** | le workflow rend `arret_sterile` (non-amélioration déjà codée, `generator-critic-verifier.js:192-194`) ou des éléments hétérogènes | workflow (résultat) | principal | résultat du run dans le journal du plan |
| **P2 → P1** | aucun `SendMessage` entre coéquipiers sur les `[p2_taches_sans_echange]` (2) dernières tâches livrées, **ou** contrat figé (le principal le marque `fige`), **ou** perte des coéquipiers (compaction, `/resume`), **ou** doublon détecté | hook (compte les messages entre coéquipiers ; `SubagentStart` compte les coéquipiers distincts) | principal ; doublon : hook immédiat (§3.3, mode `idle`) | plan `palier: P1`, zones restantes relancées en sous-agents |
| **P2 → arrêt** | coéquipiers distincts > `[p2_coequipiers]` (doublon, issue #55586) | hook `SubagentStart` | hook coupe (`continue: false` à l'inactivité, refus de tout nouveau lancement), utilisateur prévenu | journal `doublon` |

Ce qui n'existe volontairement pas : une montée automatique sans le principal, une montée P1 → P2 sans l'utilisateur, une escalade décidée par « ça semble complexe ». La communauté et la mesure convergent (03 §3) : plus d'écrivains parallèles est le schéma qui échoue le plus ; P2 n'est justifié que par un **échange répété entre zones** que le principal relaie mal, jamais par la taille.

### 1.3 Activation de P2 : ce qu'il faut mesurer, et un dispositif qui marche même si le chaud échoue

Le flag `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS` posé à chaud n'est pas garanti (01 §2) ; les outils Task sont absents sur Opus 5.5 sans `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` au lancement (01, en tête).

Dispositif retenu, indépendant du chaud :
1. `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1` posé **une fois** dans `~\settings.json` (`env`), donc présent au démarrage de toute session (édition sous la règle `ask` existante). Effet : un appel `Agent` du principal avec `name` lance un coéquipier. Ce pouvoir est **neutralisé par le hook** (§3.3) : tout `name` est refusé tant que les deux conditions P2 ne sont pas réunies.
2. La porte P2 réelle est une variable à nous, `CLIFF_EQUIPE_P2=1`, que seul l'utilisateur peut poser : dans `.claude\settings.local.json` du projet (édition sous `ask`, donc acceptée par lui) ou en lançant la session avec elle. Le hook ne laisse passer un `name` que si `CLIFF_EQUIPE_P2=1` **et** `plan.palier == "P2"`.
3. La liste de tâches partagée du harness n'est pas requise : le plan d'équipe tient lieu de liste (le chef seul l'écrit) ; P2 fonctionne « par messages » (01 : « coordinates with its team through messages »). Les outils Task restent une option mesurée (M3), jamais une dépendance.
4. Si la mesure M8 montre qu'une variable posée à chaud dans `settings.local.json` n'atteint pas les hooks : la porte P2 passe par une relance de session (`claude --resume <id>` avec la variable dans l'environnement du lanceur, ou extension VS Code rouverte), le plan survivant sur disque. Les coéquipiers ne sont pas encore lancés à ce moment, rien n'est perdu.

Mesures à faire avant d'y compter (une session jetable, projet d'essai, hook de sonde temporaire `scripts\sonde-hooks.ps1` qui recopie le stdin brut dans `~\.equipe-log\sondes\` ; câblé seulement dans `.claude\settings.local.json` du projet d'essai, retiré après) :

| Id | Question | Geste | Signal attendu |
|---|---|---|---|
| M1 | Sans le flag, `name` lance-t-il un sous-agent simple ? | session sans flag, `Agent` avec `name` | pas de dossier `~\.claude\teams\` créé |
| M2 | Le flag posé à chaud dans `settings.local.json` crée-t-il une équipe ? | éditer, puis `Agent` avec `name` | dossier `teams\{team}\config.json` apparu ou non |
| M3 | Outils Task présents avec `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` exporté au lancement ; `TaskCreated` déclenché | lancer avec la variable | `TaskCreate` listé ; ligne de sonde `TaskCreated` |
| M4 | `PreToolUse Agent` porte-t-il `name`, `isolation`, `run_in_background`, `tool_use_id` ? | sonde sur `Agent` | champs présents dans le stdin recopié |
| M5 | Dans un sous-agent, `agent_id` et `prompt_id` sont-ils dans `PreToolUse` ? | sous-agent qui appelle `Read` | champs présents |
| M6 | Les `agent()` d'un workflow passent-ils par `PreToolUse Agent` ? | workflow de 2 agents | 0 ou 2 lignes de sonde |
| M7 | `PostToolUse Agent` en arrière-plan rend-il `agentId` ? | `run_in_background` | champ présent |
| M8 | Une `env` posée à chaud dans `settings.local.json` atteint-elle le processus d'un hook lancé après ? | poser `CLIFF_SONDE=1`, puis un `Read` | variable visible dans la sonde |
| M9 | `agentType: 'cliff-stack:independent-critic'` est-il accepté par `agent()` de workflow ? | workflow d'un agent | agent lancé avec ce type |
| M10 | `SubagentHandback` est-il visible à `PreToolUse` avec `tool_input.message` ? | sous-agent en mode auto | message présent |

Chaque réponse s'écrit dans `~\suivi\equipes-agents\06-mesures.md` (fichier du chantier, au moment de la mise en place, pas maintenant) avec la commande et l'extrait de sonde.

---

## 2. L'artefact « plan d'équipe »

### 2.1 Où il vit, et pourquoi

`<racine du projet>\.claude\equipes\<AAAA-MM-JJ>-<slug>\` contient :
- `plan.json` : le plan, source unique de la décision ;
- `journal.jsonl` : une ligne par événement (lancement, signal, escalade, livraison, échec, arrêt) ;
- `compteurs.json` : compteurs écrits par le hook seul (lancements, relais, échecs stériles, messages P2), séparés pour que le principal ne les réécrive pas en éditant le plan.

Racine découverte par `git rev-parse --show-toplevel` depuis `cwd`, sinon `CLAUDE_PROJECT_DIR`. **Non versionné** : c'est un état de travail (chemins absolus, durées, compteurs), son histoire utile va au message de commit, au suivi du projet (`docs/suivi.md` dans Réactorm) et au journal global de rétro (§6) ; le versionner mêlerait l'état d'une séance à l'histoire du dépôt. Dans le projet plutôt que sous `~\suivi\` : les worktrees, les sous-agents et les règles de projet comme « rien hors du dossier du kit » (Réactorm) le voient sans exception. À la création, `plan.ps1 nouveau` vérifie `git check-ignore` ; si le dossier n'est pas ignoré, il le dit et propose la ligne `.claude/equipes/` pour `.gitignore`, sans l'écrire seul.

Format JSON, parce qu'il est lu par trois lecteurs mécaniques (hook PowerShell, script d'intégration, `args` d'un workflow) ; l'utilisateur ne lit jamais le JSON : `plan.ps1 resume` en rend un tableau de dix lignes pour la porte de validation.

### 2.2 Qui l'écrit, qui le relit, comment il change

- Écrit : le **thread principal seul**, par `plan.ps1` (jamais un sous-agent ni un coéquipier ; ils rendent des signaux).
- Relu : `plan.ps1 verifier` (mécanique : schéma, recouvrement, budget) ; `framing-critic` **seulement** s'il y a une question de niveau (règle existante de son frontmatter) ; l'utilisateur à la porte (§4.5).
- Changement de palier : `plan.ps1 escalade --vers <P> --signal <id de ligne du journal> --raison "<texte>"` réécrit `palier`, `statut: relance`, fusionne ou scinde les zones, ajoute une ligne `escalade` ou `desescalade` au journal avec l'empreinte SHA-256 du plan avant et après, et imprime la liste des zones à relancer avec leur dernier état. Le plan ne garde jamais deux versions : l'historique est le journal.

### 2.3 Format (champs)

| Champ | Sens |
|---|---|
| `schema` | version du format (1) |
| `id`, `sujet`, `restitution` | identifiant ; demande de l'utilisateur ; ma compréhension en une phrase (règle globale « restituer ») |
| `projet` | racine absolue découverte |
| `palier`, `statut` | `P0`·`P1`·`P1b`·`P2` ; `cadré` → `validé` → `actif` → `intégration` → `clos`, ou `relance`, `arrêté`, `abandonné` |
| `validation` | `{requise, raison, par, le}` |
| `preuves[]` | `{type: grep·git-log·imports·rules-paths·frontieres·agents, commande, extrait}` : ce qui fonde les zones |
| `graphe` | `{largeur, profondeur, couplage_max, aretes[{de, vers, via, couplage}]}` ; couplage noté 0 / 0,3 / 0,7 / 1 (aucun, faible, fort, critique, échelle d'AdaptOrch, 03 §1) |
| `contrats[]` | `{id, fichier, ecrit_par: "principal", etat: brouillon·fige, reecritures}` |
| `fichiers_partages[]` | écrits par le principal seul |
| `zones[]` | `{id, but, ecrivain{role, modele, effort, isolation}, fichiers_permis[globs], lecture_seule[globs], depend_de[], verification{niveau, commandes[]}, critere, estimation_min, etat, worktree}` |
| `conseillers[]` | `{role, quand: avant·apres, question}` (lecture seule) |
| `verification_finale` | `{role, raison}` : une seule relecture par livraison quand le projet le dit |
| `budget` | `{agents_total, simultanes, ecrivains, iterations_max, echeance, source}` ; chaque valeur ≤ celle de `budget-agents.json` |
| `escalade` | `{montee_si[], descente_si[]}` : les signaux du §1.2 instanciés pour ce plan |
| `fanout` | facultatif, P1b : `{elements[], transformation, verification}` |

Le brief envoyé à chaque agent commence par deux lignes lues par le hook, puis le reste :
```
plan: <chemin absolu de plan.json>
zone: <id>
```

### 2.4 Exemple rempli : Réactorm, « ajouter un loueur »

Cas décrit sans lire le dépôt, à partir de son CLAUDE.md (déjà en contexte) : un quatrième loueur, appelé ici `loueurx`. Ce qui est propre à un loueur vit dans `moteur/fournisseurs/<loueur>` (connexion) et `extensions/location/loueurs/<loueur>` (location) ; la location ne rend qu'une commande SSH (port `Loueur`), la connexion la consomme (port `Fournisseur`) ; tout fichier du moteur figure dans `moteur/noyau/version.json` (fichier partagé, principal) ; `README.md`, `ARCHITECTURE.md`, contrats : principal. Les chemins d'essais sont des **hypothèses à vérifier** par `git ls-files | grep -i essai` au cadrage.

```json
{
  "schema": 1,
  "id": "2026-10-14-loueurx",
  "sujet": "Ajouter le loueur loueurx (location et connexion)",
  "restitution": "Brancher un quatrième loueur sur les ports Loueur et Fournisseur existants, sans toucher le noyau, avec ses essais et sa doc.",
  "projet": "C:/MAMP/htdocs/webidi/Reactorm",
  "palier": "P1",
  "statut": "cadré",
  "validation": { "requise": true, "raison": "2 écrivains", "par": null, "le": null },
  "preuves": [
    { "type": "rules-paths", "commande": "en-têtes paths de .claude/rules/location.md", "extrait": "moteur/fournisseurs/**, extensions/location/**" },
    { "type": "git-log", "commande": "co-changement.ps1 -Depuis 200 -Chemins moteur/fournisseurs,extensions/location/loueurs", "extrait": "co-modifiés ensemble dans 3 commits sur 41 : ratio 0,07" },
    { "type": "imports", "commande": "grep -rn 'import' extensions/location/loueurs | grep fournisseurs", "extrait": "0 ligne (aucun import entre familles, règle du projet tenue)" },
    { "type": "frontieres", "commande": "python outils/verifier_frontieres.py", "extrait": "dette inchangée attendue" },
    { "type": "agents", "commande": "descriptions de .claude/agents/*.md contenant « ajouter un loueur »", "extrait": "reactorm-infra-gpu" }
  ],
  "graphe": {
    "largeur": 2, "profondeur": 2, "couplage_max": 0.3,
    "aretes": [
      { "de": "Z1", "vers": "C1", "via": "lit la commande SSH rendue", "couplage": 0.3 },
      { "de": "Z2", "vers": "C1", "via": "rend la commande SSH", "couplage": 0.3 }
    ]
  },
  "contrats": [
    { "id": "C1", "fichier": "docs/contrat_loueurx.md (hypothèse : ou section du contrat de location existant)", "ecrit_par": "principal", "etat": "brouillon", "reecritures": 0 }
  ],
  "fichiers_partages": ["moteur/noyau/version.json", "README.md", "ARCHITECTURE.md", "docs/location_securite.md", "docs/suivi.md"],
  "zones": [
    {
      "id": "Z1", "but": "Connexion : adaptateur Fournisseur de loueurx",
      "ecrivain": { "role": "cliff-stack:ouvrier-zone", "modele": "opus", "effort": "high", "isolation": "aucune" },
      "fichiers_permis": ["moteur/fournisseurs/loueurx*.py", "moteur/fournisseurs/essais/*loueurx*"],
      "lecture_seule": ["moteur/fournisseurs/**", "moteur/noyau/**", "docs/contrat_*.md"],
      "depend_de": ["C1"],
      "verification": { "niveau": 0, "commandes": ["python -m py_compile <fichiers touchés>", "python outils/verifier_noms.py", "python outils/verifier_frontieres.py"] },
      "critere": "Le fournisseur reconnaît une commande C1 et rend un état {id, niveau, code, texte, action} ; aucun nom de loueur hors de son fichier.",
      "estimation_min": 45, "etat": "à faire", "worktree": null
    },
    {
      "id": "Z2", "but": "Location : adaptateur Loueur de loueurx",
      "ecrivain": { "role": "cliff-stack:ouvrier-zone", "modele": "opus", "effort": "high", "isolation": "aucune" },
      "fichiers_permis": ["extensions/location/loueurs/loueurx*", "extensions/location/essais/*loueurx*"],
      "lecture_seule": ["extensions/location/**", "docs/contrat_*.md"],
      "depend_de": ["C1"],
      "verification": { "niveau": 0, "commandes": ["python -m py_compile <fichiers touchés>", "python outils/verifier_noms.py", "python outils/verifier_frontieres.py"] },
      "critere": "Le loueur rend une commande C1 ; clé d'API en mémoire vive seulement ; action qui engage de l'argent derrière le jeton du moteur.",
      "estimation_min": 60, "etat": "à faire", "worktree": null
    }
  ],
  "conseillers": [
    { "role": "reactorm-infra-gpu", "quand": "avant", "question": "Ce que loueurx offre réellement : SSH standard, point de montage, API, et ce qu'il faut demander s'il n'a pas de SSH standard." }
  ],
  "verification_finale": { "role": "reactorm-securite", "raison": "appel réseau sortant, clés d'API, exécution distante : une seule relecture par livraison" },
  "budget": { "agents_total": 4, "simultanes": 2, "ecrivains": 2, "iterations_max": 3, "echeance": "2026-10-14T18:00:00+02:00", "source": "budget-agents.json schema 1" },
  "escalade": {
    "montee_si": ["relais Z1↔Z2 ≥ 3 ou C1 réécrit ≥ 2 fois → proposer P2 (porte utilisateur)"],
    "descente_si": ["Z1 demande un fichier de Z2 (ou l'inverse) 2 fois → fusion Z1+Z2, un seul écrivain"]
  }
}
```

`isolation: "aucune"` parce que le projet dit que les codeurs travaillent « dans le dossier de travail » et que ses essais lisent des dossiers non suivis absents d'un worktree ; l'intégration contrôle alors l'union des fichiers changés (§3.6). Budget : 1 conseiller + 2 ouvriers + 1 relecteur = 4.

Journal, trois lignes typiques :
```json
{"ts":"2026-10-14T15:02:11+02:00","evenement":"lancement","zone":"Z2","role":"cliff-stack:ouvrier-zone","agent_id":"a3f…","decision":"allow"}
{"ts":"2026-10-14T15:40:52+02:00","evenement":"signal","zone":"Z2","type":"contrat_insuffisant","detail":"C1 ne dit pas comment la commande porte le port SSH non standard"}
{"ts":"2026-10-14T15:44:03+02:00","evenement":"escalade","de":"P1","vers":"P1","action":"C1 réécrit (reecritures=1), Z2 relancée à neuf","plan_avant":"sha256:9c1…","plan_apres":"sha256:41d…","decideur":"principal"}
```

---

## 3. Pièces à créer ou modifier

Vue d'ensemble :

| Pièce | Chemin | Statut | Palier |
|---|---|---|---|
| Budget unique | `CS\hooks\budget-agents.json` | N | tous |
| Garde budget et équipe | `CS\hooks\scripts\guard-budget-agents.ps1` (issu de `guard-workflow-budget.ps1` par `git mv`) | M | P1, P1b, P2 |
| Journal des agents | `CS\hooks\scripts\log-agents.ps1` (issu de `log-critic.ps1` par `git mv`) | M | tous |
| Câblage | `CS\hooks\hooks.json` | M | tous |
| Skill de composition | `CS\skills\equipe\SKILL.md` + `reference\` + `scripts\` | N | cadrer, activer, intégrer |
| Rôle d'écrivain | `CS\agents\ouvrier-zone.md` | N | P1, P2 |
| Workflows | `~\workflows\generator-critic-verifier.js`, `~\workflows\research-arbitrate.js` | M | P1b |
| Section CLAUDE.md | `~\CLAUDE.md` | M | tous |
| Variables | `~\settings.json` (`env`) | M | P2 |
| Renvois de chiffres | `CS\skills\fable-mode\SKILL.md:44`, `CS\skills\fable-mode\reference\stack-maison.md:34-42`, `CS\skills\asana-triage\SKILL.md:9,52,69`, `~\README.md:108`, `~\SECURITY-SETUP.md:11,27`, `CS\.claude-plugin\plugin.json` (« 17 agents ») | M | tous |
| Vérificateurs | `CS\agents\independent-critic.md`, `CS\agents\framing-critic.md`, `CS\agents\untrusted-content-auditor.md`, agents de projet (`<projet>\.claude\agents\`) | R | intégrer, cadrer |
| `morph-orchestrator`, `project-idea-validator`, `asana-triage` (logique) | inchangés ; leurs lancements passent sous la garde | R | P1 |

### 3.1 [N] `CS\hooks\budget-agents.json` : la source unique

Placé sous `hooks\` pour que la règle `ask` sur l'édition des hooks le couvre (**hypothèse à vérifier** : lire le motif exact de cette règle dans `~\settings.json:52-58` ; s'il ne vise que `scripts\*.ps1`, l'élargir à `hooks\**`).

```json
{
  "schema": 1,
  "mode": "journal",
  "unites": "simultanes = agents vivants en même temps dans la session ; total = lancements par plan, ou par session hors plan",
  "simultanes": 5,
  "ecrivains_simultanes": 3,
  "ecrivains_par_zone": 1,
  "total_par_plan": 12,
  "total_hors_plan": 12,
  "p2_coequipiers": 3,
  "lancements_par_tour": 6,
  "playwright_simultanes": 1,
  "iterations_max": 8,
  "steriles_arret": 3,
  "ttl_agent_min": 60,
  "seuils": {
    "p0_fichiers_max": 5,
    "zone_min_fichiers": 2,
    "items_homogenes_min": 5,
    "couplage_parallele_max": 0.3,
    "couplage_fusion_min": 0.7,
    "relais_p2_min": 3,
    "p2_taches_sans_echange": 2
  },
  "plafond_harness": {
    "variable": "CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS",
    "defaut_documente": 20,
    "source": "code.claude.com/docs/en/sub-agents.md, Concurrent subagent limit"
  },
  "copies_controlees": {
    "CLAUDE_CODE_WORKFLOW_MAX_CONCURRENT_AGENTS": "simultanes"
  },
  "sources_des_valeurs": {
    "total_par_plan": "garde existante (12) ; la prose « ~10 » renvoie désormais ici",
    "simultanes": "asana-triage (5)",
    "ecrivains_simultanes": "communauté : 3, 5 au plus (03 §3, §4)",
    "seuils": "03 §2 et §3, aucun validé publiquement : réglables, journalisés"
  }
}
```

Réconciliation des chiffres de 02 §5 : deux unités distinctes, **simultanés** (5, écrivains 3) et **total par plan** (12). « ~10 » et les constantes des workflows disparaissent au profit d'un renvoi. Les 20 de Réactorm (`R\CLAUDE.md:187`) sont une règle de l'utilisateur dans son projet : je ne la tranche pas (§7). Surcharge par projet, sans écrire de nom de projet nulle part : variables `CLIFF_EQUIPE_<CLE>` (ex. `CLIFF_EQUIPE_SIMULTANES=20`) dans `<projet>\.claude\settings.json` ou `settings.local.json`, éditables seulement sous `ask` ; elles peuvent relever une valeur jusqu'au plafond du harness, jamais au-delà. `mode` : `journal` (le hook calcule et journalise sa décision sans refuser) pendant les deux premières semaines, puis `bloquer`. Exception : en mode `journal`, un `name` (P2) est **toujours** refusé hors conditions P2.

### 3.2 [M] `log-critic.ps1` → `log-agents.ps1` (SubagentStop)

Généralisation demandée par 02 §6, sans second hook. Comportement :
- inchangé pour les critiques : même ligne dans `~\.critic-log\critics.jsonl` (la mesure en cours du CLAUDE.md global n'est pas cassée) ;
- en plus, pour **tout** `agent_type` non vide : une ligne dans `~\.equipe-log\agents.jsonl` : `ts, session_id, cwd, agent_id, agent_type, plan_id, zone` (repris de l'état du hook budget s'il existe), `stop_hook_active`, `extrait` (400 caractères, comme aujourd'hui), `fond: background_tasks.Count`. Pas de contenu au-delà de l'extrait.
- toujours `exit 0`, UTF-8 sans BOM, dossier créé à la volée. `~\.equipe-log\` est hors versionnement comme `.critic-log` (le commentaire de `log-critic.ps1` dit `.gitignore` deny-by-default ; **à vérifier** par `git -C ~ check-ignore .equipe-log/x`).

### 3.3 [M] `guard-workflow-budget.ps1` → `guard-budget-agents.ps1`

Un script, plusieurs événements, choisis par argument (même convention que `play-sound.ps1 always|stop`). Ce qui existe est gardé : refus du lancement par `name:` sans script, ligne `// agents-max: N` obligatoire, marqueur `// agents-max-user-ok`, passage de `resumeFromRunId`.

**Entrées** (stdin JSON, UTF-8 forcé comme dans `log-critic.ps1`) : `session_id`, `prompt_id`, `agent_id` (absent = thread principal), `hook_event_name`, `tool_name`, `tool_input`, `tool_response` (Post), `agent_type`.
**État de session** : `~\.equipe-log\etat\<session_id>.json` (agents vivants, empreintes de lancement, cache des rôles écrivains). **État de plan** : `<dossier du plan>\compteurs.json`. Accès sous un `System.Threading.Mutex` nommé `cliff-equipe-<session_id>` (lancements parallèles dans un même tour), écriture atomique (fichier temporaire puis `Move-Item`). Purge : états de session de plus de 7 jours supprimés à l'écriture.
**Journal des décisions** : `~\.equipe-log\decisions.jsonl`.

```text
param($mode)            # pre | post | start | stop | idle | rapport
p      = lire stdin
B      = lire budget-agents.json ; puis surcharges CLIFF_EQUIPE_* bornées par plafond_harness
         si illisible : P1 laissé passer avec ligne 'budget_illisible' au journal ; tout 'name' refusé
S      = verrou + lire etat\<session_id>.json
purger S : agents vivants dont lance < maintenant - ttl_agent_min → fini = 'perime' (journal)

mode pre, tool Workflow :
    logique existante (script, agents-max, user-ok, resumeFromRunId)
    restant = (plan ? plan.budget.agents_total - compteurs.lances : B.total_hors_plan - S.lances_hors_plan)
    refuser si N > restant
    pour chaque copie de B.copies_controlees : refuser si valeur d'env > valeur du budget ("copie divergente")

mode pre, tool Agent :
    in = tool_input ; plan = ligne '^plan:' du prompt → lire plan.json + compteurs.json ; zone = ligne '^zone:'
    ecrit = role écrivain (frontmatter tools de la définition contient Edit|Write ou est absent ;
            définition cherchée dans <projet>\.claude\agents, CLAUDE_PLUGIN_ROOT\agents, ~\agents ;
            cache par chemin + date de modification ; general-purpose et types inconnus = écrivains)
    vivants = S.agents sans fini
    empreinte = sha256(subagent_type + description + prompt)
    refuser, première règle qui tombe :
      1  in.name présent et agent_id présent        → "un sous-agent ne crée pas de coéquipier : rends un signal au thread principal"
      2  in.name présent et (env CLIFF_EQUIPE_P2 ≠ 1 ou plan.palier ≠ P2)
                                                     → "P2 fermé : porte utilisateur (CLIFF_EQUIPE_P2) et plan au palier P2"
      3  in.name présent et (coéquipiers vivants ≥ p2_coequipiers ou même name vivant)
                                                     → "coéquipier en double ou au-delà du plafond"
      4  plan présent et statut ∉ {validé, actif, relance} → "plan non validé"
      5  vivants ≥ simultanes                       → "plafond simultanés"
      6  ecrit et écrivains vivants ≥ ecrivains_simultanes → "plafond écrivains"
      7  ecrit et plan présent et zone absente ou inconnue du plan → "zone manquante"
      8  ecrit et un écrivain vivant tient déjà cette zone → "un seul écrivain par zone"
      9  lancés (plan ou hors plan) ≥ total          → "budget total épuisé"
      10 zone.steriles ≥ steriles_arret ou zone.iterations ≥ iterations_max → "arrêt stérile : remonter à l'utilisateur"
      11 maintenant > plan.budget.echeance           → "échéance dépassée"
      12 lancements du même prompt_id ≥ lancements_par_tour, ou empreinte déjà lancée dans ce prompt_id
                                                     → "lancement dupliqué dans le même tour"
    règles 2 et 3 : refus quel que soit B.mode ; autres : si B.mode = journal → laisser passer, journal 'aurait_refuse'
    sinon accepter : S.agents += {empreinte, role, zone, plan, ecrit, p2: name présent, nom, lance, prompt_id, lanceur: agent_id}
                     compteurs.lances++ (ou S.lances_hors_plan++)
    sortie de refus : stderr = raison + budget restant ; exit 2
    sortie d'acceptation : exit 0

mode pre, tool SendMessage :
    cible fini dans S (reprise d'un agent terminé) → appliquer règles 5, 6, 8, 9 (une reprise prend un créneau, 01 §1)
    lanceur et cible coéquipiers du même plan → compteurs.messages_p2++
    exit 0 sinon

mode post (PostToolUse Agent) :
    retrouver l'entrée par empreinte de tool_input ; poser agent_id = tool_response.agentId
    status = completed → fini = maintenant
    sortie JSON additionalContext : "budget : <vivants>/<simultanes> vivants, <lancés>/<total>" ; exit 0

mode start (SubagentStart) :
    agent_id inconnu de S et plan au palier P2 → compter les coéquipiers distincts vus
    distincts > p2_coequipiers → marquer 'doublon', stderr à l'utilisateur, journal 'doublon',
                                additionalContext à l'agent : "tu es en surnombre : rends 'doublon' sans agir"
    exit 0 (événement non bloquant)

mode stop (SubagentStop) : marquer fini ; exit 0

mode idle (TeammateIdle) :
    agent marqué doublon, ou plan au palier ≠ P2 → sortie {"continue": false}
    sinon exit 0

mode rapport (PreToolUse SubagentHandback, mesure M10) :
    agent_id → plan, zone ; extraire le bloc JSON après la ligne 'SIGNAL:' de tool_input.message
    ajouter au journal du plan ; mettre à jour compteurs :
      hors_zone_requis / contrat_insuffisant → relais[paire]++
      echec_repete → steriles++ si l'empreinte de la sortie d'échec = la précédente, sinon steriles = 1
    jamais de refus ; exit 0

toujours : ligne decisions.jsonl {ts, session_id, mode, tool, role, zone, plan_id, decision, raison, vivants, ecrivains, lances}
           écrire S et compteurs, relâcher le verrou
```

Limites dites : les agents de workflow ne passent peut-être pas par `PreToolUse Agent` (M6) ; ils sont alors bornés par `agents-max` et la copie contrôlée de la variable de concurrence. Des coéquipiers créés par le harness sans appel `Agent` (scénario #55586) ne sont pas refusables à la création ; ils sont détectés à `SubagentStart` et coupés à l'inactivité.

### 3.4 [M] `CS\hooks\hooks.json`

Remplacements et ajouts (le reste du fichier inchangé) :

```json
{
  "PreToolUse": [
    {
      "matcher": "Workflow|Agent|SendMessage",
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" pre" }
      ]
    },
    {
      "matcher": "SubagentHandback",
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" rapport" }
      ]
    }
  ],
  "PostToolUse": [
    {
      "matcher": "Agent",
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" post" }
      ]
    }
  ],
  "SubagentStart": [
    {
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" start" }
      ]
    }
  ],
  "SubagentStop": [
    {
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/log-agents.ps1\"" },
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" stop" }
      ]
    }
  ],
  "TeammateIdle": [
    {
      "hooks": [
        { "type": "command", "command": "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"${CLAUDE_PLUGIN_ROOT}/hooks/scripts/guard-budget-agents.ps1\" idle" }
      ]
    }
  ]
}
```

L'entrée `"matcher": "Workflow"` actuelle disparaît (absorbée). Coût : un démarrage PowerShell (quelques centaines de ms, **à mesurer** sur 20 lancements) par appel `Agent`, `SendMessage` et fin d'agent ; aucun sur Read/Edit/Bash. Pas de garde de zone en direct sur Write/Edit : le lien `agent_id` → zone n'est fiable qu'après M4, M5, M7 ; la barrière de zone est à l'intégration (§3.6), déterministe. Une garde en direct pourra s'ajouter au matcher existant de `guard-root-hygiene` si les mesures la rendent fiable.

### 3.5 [N] Skill `CS\skills\equipe\`

`SKILL.md`, frontmatter complet :

```yaml
---
name: equipe
description: "Compose, lance et intègre une équipe d'agents à partir d'un plan d'équipe fichier, et la fait changer de palier (session seule, sous-agents, workflow, équipe coordonnée) sur des signaux mesurés. Use when une tâche semble demander plus d'un agent qui écrit, plus de deux agents en tout, un fan-out sur des éléments homogènes, une équipe coordonnée (agent teams), ou quand un travailleur remonte un signal d'escalade ; déclencheurs : « fais-le en équipe », « parallélise », « lance des agents », « plusieurs agents », « agent teams », « gros chantier », « découpe en zones », « monte d'un palier », « redescends ». Règle par défaut : pas d'équipe ; le skill sait d'abord dire « une session seule suffit ». NE PAS utiliser pour lancer un seul vérificateur (independent-critic) ou un seul cadrage (framing-critic), qui s'appellent directement ; ni pour un domaine qui a déjà son orchestrateur (moteur morph : morph-orchestrator ; tickets Asana : asana-triage), qui reste maître de sa composition sous les mêmes plafonds."
---
```

Corps (court, pour ne pas dégrader Fable ; la décision vit dans les scripts) :
1. **Cadrer** : restituer la demande ; `scripts\plan.ps1 nouveau` ; collecter les preuves (§4.1) ; `scripts\plan.ps1 verifier` rend le palier proposé et ses raisons, ou « P0 : pas d'équipe » ; porte utilisateur selon §4.5.
2. **Activer** : contrats d'abord, écrits par le principal ; puis un `Agent` par zone avec le brief de `reference\brief-ouvrier.md`, conseillers « avant » avant les écrivains ; ne rien décider d'autre que ce que dit le plan ; un refus du hook se lit et se rapporte, jamais contourné (reformuler pour passer le hook est interdit).
3. **Intégrer** : `scripts\integrer.ps1` ; puis la relecture finale du plan (un seul vérificateur, agent maison, contexte vierge) ; `scripts\plan.ps1 clore`.
4. **Escalader** : lire le journal ; `scripts\plan.ps1 escalade` dit si un seuil est franchi ; appliquer §1.2 ; P2 seulement par la porte §1.3.

Fichiers :
- [N] `reference\plan.schema.json` : schéma JSON du §2.3 (lu par `plan.ps1 verifier`).
- [N] `reference\brief-ouvrier.md` : gabarit du brief (lignes `plan:` et `zone:` en tête, but, fichiers permis, lecture seule, contrats, vérification, critère, budget de temps).
- [N] `reference\escalade.md` : le tableau du §1.2, renvoyé par le skill, jamais injecté d'office.
- [N] `scripts\plan.ps1` (sous-commandes ci-dessous).
- [N] `scripts\co-changement.ps1`, [N] `scripts\integrer.ps1`, [N] `scripts\sonde-hooks.ps1` (mesures du §1.3, retirée après usage).

`plan.ps1`, pseudo-code :
```text
nouveau -Sujet <texte> -Slug <slug>
    racine = git rev-parse --show-toplevel (repli CLAUDE_PROJECT_DIR) ; crée .claude\equipes\<date>-<slug>\ avec gabarit,
    journal vide, compteurs à zéro ; avertit si le dossier n'est pas ignoré par git ; exit 0, imprime le chemin
verifier -Plan <chemin>
    schéma (plan.schema.json) ; développe chaque glob par git ls-files (+ fichiers nouveaux déclarés) ;
    recouvrement : un fichier dans deux zones → exit 3 ; fichier partagé dans une zone → exit 3 ;
    budget du plan ≤ budget-agents.json (surcharges comprises) → sinon exit 4 ;
    calcule largeur (zones sans chemin entre elles), profondeur, couplage_max ;
    règle de palier (§4.3) → imprime {palier_propose, raisons[], porte_requise} ; exit 0
valider -Plan <chemin> -Par utilisateur|principal
    refuse si verifier échoue ; statut = validé ; journal 'validation' ; exit 0
resume -Plan <chemin>         tableau court pour la porte : zones, écrivains, fichiers, conseillers, budget, échéance
escalade -Plan <chemin> [-Vers P0|P1|P1b|P2] [-Signal <n° de ligne>] [-Raison <texte>]
    sans -Vers : lit compteurs + seuils, imprime le passage proposé et le signal qui le fonde, exit 0 (rien) ou 10 (seuil franchi)
    avec -Vers P2 : exit 5 si CLIFF_EQUIPE_P2 ≠ 1 (message : porte utilisateur) ;
    réécrit palier, statut = relance, fusionne ou scinde les zones demandées, journal avec empreintes avant/après ;
    imprime les zones à relancer et leur dernier état
clore -Plan <chemin>
    statut = clos ; ligne ~\.equipe-log\chantiers.jsonl :
    {id, projet (nom du dossier), palier_initial, palier_final, escalades, agents_lances, refus, doublons,
     estimation_min, reel_min, ecart_pct, steriles, verdict_verification}
    écart > 50 % : le dit (règle de Réactorm l. 196, valable ici pour tout projet)
```

`co-changement.ps1` :
```text
entrée : -Depuis <n commits> -Chemins <liste de dossiers ou globs>
git log -n <n> --name-only --format=%H ; regroupe par commit ; pour chaque paire de chemins (A,B) :
ratio = commits touchant A et B / commits touchant A ou B
sortie JSON [{a, b, ratio, commits}] ; exit 0 ; dépôt non git → exit 2 avec message
correspondance avec l'échelle : ratio < 0,1 → 0 ; < 0,3 → 0,3 ; < 0,6 → 0,7 ; sinon 1 (seuils dans budget-agents.json au besoin)
```

`integrer.ps1` :
```text
entrée : -Plan <chemin> ; lit plan, compteurs, et l'instantané pris à l'activation (git status --porcelain + empreintes)
pour chaque zone livrée, ordre topologique de depend_de :
    isolation worktree : fichiers = git -C <wt> diff --name-only HEAD + ls-files --others --exclude-standard
    isolation aucune  : fichiers = changés depuis l'instantané (zones disjointes, chaque fichier tombe dans une seule zone)
    fichier hors fichiers_permis de sa zone et hors fichiers_partages → zone refusée, exit 3 (journal 'hors_zone')
    worktree : git -C <wt> diff HEAD | git apply --3way dans l'arbre principal (aucun commit : commit sur demande seulement) ;
               conflit → exit 4 ; succès → git worktree remove <wt> (WorktreeRemove n'est pas lancé pour un sous-agent, 01 §3)
lancer les commandes de vérification du plan ; sortie hachée
    échec, même empreinte que l'échec précédent de la zone → compteurs.steriles++ ; ≥ steriles_arret → exit 6 'arret_sterile'
    échec autre → exit 5 ; succès → exit 0
journal 'integration' {zones, fichiers, verification:{commande, code, empreinte}}
```

### 3.6 [N] `CS\agents\ouvrier-zone.md`

```markdown
---
name: ouvrier-zone
description: "Écrit le code d'UNE zone d'un plan d'équipe validé : seulement les fichiers permis du brief, avec leur vérification de niveau 0, puis rend un rapport structuré. À lancer par le thread principal, depuis le skill equipe, un par zone, jamais deux sur la même zone. Use PROACTIVELY quand un plan d'équipe validé assigne une zone à un écrivain. NE PAS lancer hors plan, pour une retouche que le thread principal fait seul, pour relire (independent-critic), cadrer (framing-critic) ou lire un contenu étranger (untrusted-content-auditor), ni dans une zone qui a déjà son agent de projet qui écrit (le plan le nomme alors). Aucune opération git, aucun lancement d'agent."
tools: Read, Grep, Glob, Bash, Edit, Write, Skill
model: inherit
color: green
---

Tu es l'**ouvrier d'une zone**. Un seul job : livrer la zone que le brief t'assigne, sans sortir de ses fichiers permis. Réponds en français.

## Entrée attendue (le brief)
En tête, `plan:` (chemin du plan d'équipe) et `zone:` (identifiant). Puis : but, fichiers permis, lecture seule, contrats à respecter, vérification, critère de réussite, budget de temps. Brief incomplet : rends `statut: bloqué` avec ce qui manque, sans deviner.

## Découvrir avant d'écrire
Lis le `CLAUDE.md` et les rules du projet (`.claude/rules/**` dont les `paths` couvrent tes fichiers) et applique-les. Lis le plan pour ta zone seulement. Réutilise le code du projet avant d'en écrire.

## Règles
- N'écris que dans tes fichiers permis. Besoin d'un autre fichier : arrête-toi et rends le signal `hors_zone_requis` avec le fichier et la raison. Jamais d'écriture « juste cette ligne ».
- Contrat insuffisant : signal `contrat_insuffisant`, avec le point précis qui manque ; ne l'interprète pas.
- Chaque fichier modifié se vérifie aussitôt (syntaxe au minimum) ; seulement la vérification que le brief déclare.
- Même échec trois fois de suite : arrête, signal `echec_repete` avec la commande et la sortie.
- Éléments homogènes nombreux découverts (même transformation sur une liste) : signal `fanout_homogene` avec la liste.
- Ni git, ni agent, ni workflow, ni action sortante.

## Sortie (contrat)
Texte court, puis une ligne `SIGNAL:` suivie d'un objet JSON sur une ligne :
`{"statut":"livré|partiel|bloqué","zone":"<id>","fichiers_touches":[...],"verification":{"commande":"...","code":0,"extrait":"..."},"signal":{"type":"aucun|hors_zone_requis|contrat_insuffisant|echec_repete|fanout_homogene","detail":"...","cible":"<zone ou fichier>"},"estimation_min":0,"reel_min":0,"ecarts":["écart non grave pour la file du projet"]}`
Un `livré` sans vérification exécutée est interdit.
```

Note : les hooks de frontmatter sont ignorés pour un agent de plugin (01 §3) ; c'est voulu, la barrière vit dans `hooks.json`. Création par le skill `agent-builder` (critique adversarial compris), en lui précisant la cible `CS\agents\` (écart (e) de 02 §5).

### 3.7 [M] Workflows `~\workflows\`

`generator-critic-verifier.js` :
- Gate : sauté si `args.plan?.statut === 'validé'` (le cadrage a déjà été fait par le skill) ; gardé sinon.
- Generate : `agent(brief, { agentType: 'cliff-stack:ouvrier-zone', isolation: 'worktree', ... })` avec le brief de `reference\brief-ouvrier.md` reçu en `args.brief` (le script ne lit pas de fichier).
- Critique : `agentType: 'cliff-stack:independent-critic'` avec AFFIRMATION, ARTEFACT, CRITÈRE (son contrat d'entrée), au lieu du prompt inline ; le schéma CRITIQUE reste celui que l'agent rend (verdict, défauts, exercé) ; dépend de M9.
- Verify : inchangé (déterministe, `ranRealTests`).
- Budget : `AGENTS_MAX = args.agentsMax ?? 10` ; la ligne `// agents-max:` reste la déclaration que la garde compare au budget du plan.
- Fin : rend `{best, diff, worktree}` ; la fusion se fait par `integrer.ps1` depuis le principal (un workflow n'a ni fichier ni shell).

`research-arbitrate.js` : même Gate conditionnel ; phase Challenge par `agentType: 'cliff-stack:independent-critic'` (il réfute des affirmations et des absences, son périmètre exact).

### 3.8 [M] `~\CLAUDE.md`

Ligne 8, compte des hooks rendu exact sans chiffre figé : « … barrière par les hooks `PreToolUse` câblés dans le plugin cliff-stack (`skills/cliff-stack/hooks/hooks.json`, qui fait foi) + `permissions.deny` en filet. » Nouvelle section, à placer après « Style de travail » :

```markdown
## Équipes d'agents
- Par défaut, pas d'équipe. Plus d'un agent qui écrit, plus de deux agents pour une même tâche, un fan-out ou une équipe coordonnée : charger le skill `equipe`, qui cadre par un plan d'équipe fichier.
- Un seul agent écrit par zone ; les autres conseillent ou relisent. Plafonds : `skills/cliff-stack/hooks/budget-agents.json`, appliqués par hook ; un refus se rapporte, jamais contourné.
- Seul ce thread monte en équipe coordonnée ; un sous-agent qui a besoin de plus rend un signal, il ne lance rien.
```

### 3.9 [M] `~\settings.json`

`env` : ajouter `"CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS": "1"` (neutralisé par la garde, §1.3). Ne pas ajouter `CLAUDE_CODE_ENABLE_TODO_TOOLS` (outils en plus dans chaque contexte, sans besoin tant que le plan tient la liste ; option après M3). Ne pas toucher `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS` (le harness reste le filet au-dessus de notre budget). Si un workflow doit être borné : `CLAUDE_CODE_WORKFLOW_MAX_CONCURRENT_AGENTS` égal à `simultanes`, copie contrôlée par la garde.

### 3.10 [M] Renvois

Remplacer chaque chiffre par un renvoi au fichier de budget : `fable-mode\SKILL.md:44` (« ~10 agents » → « plafonds : `hooks/budget-agents.json` ») ; `stack-maison.md:34-42` (retirer « 19-21 », appeler les deux workflows « workflows », pas « skill ») ; `asana-triage\SKILL.md:9,52,69` (« max 5 en parallèle » → « dans la limite `simultanes` du budget ») ; `~\README.md:108` et `~\SECURITY-SETUP.md:11,27` (nom du script, plus de chiffre) ; `plugin.json` (17 agents).

---

## 4. Composition automatique

### 4.1 Preuves collectées (toutes par commande, rien supposé)

1. **Restitution** de la demande en une phrase (`restitution`).
2. **Fichiers candidats** : grep des symboles, termes et chemins de la demande ; `git ls-files` pour les globs.
3. **Zones déclarées par le projet** : en-têtes `paths:` de `<projet>\.claude\rules\*.md` ; fichiers de frontières que le `CLAUDE.md` du projet désigne (découverts en le lisant, jamais nommés dans le skill).
4. **Couplage** : `co-changement.ps1` sur l'historique ; imports croisés (grep des instructions d'import du langage détecté) ; contrats existants qui citent les deux zones.
5. **Rôles disponibles** : descriptions des agents de projet (`<projet>\.claude\agents\*.md`) et du plugin, appariées aux chemins et termes du plan (déclencheurs cités dans leur description) ; outils lus dans leur frontmatter pour savoir s'ils écrivent.
6. **Vérification** : commandes de vérification que le projet déclare (CLAUDE.md, rules, scripts de test découverts) ; jamais une commande inventée.

### 4.2 Construction des zones

- Fichiers qui s'importent, ou co-modifiés au-dessus de `couplage_fusion_min` : même zone.
- Fichier cité par au moins deux zones : `fichiers_partages`, au principal.
- Une zone par règle de projet (`paths:`) quand elle existe : la frontière du projet prime sur l'heuristique.
- Un agent de projet qui écrit et couvre une zone (Réactorm : `reactorm-interface` pour `fenetre/interface/`) en devient l'écrivain à la place de `ouvrier-zone`.
- Conseillers : agents de projet en lecture seule dont la description couvre la zone ; « avant » si le choix technique est ouvert, sinon aucun. Relecture finale : celle que le projet impose (une seule par livraison chez Réactorm), sinon `independent-critic` seulement si ses critères existants sont remplis.

### 4.3 Règle de palier (dans `plan.ps1 verifier`, pas dans la prose)

```text
si fichiers candidats ≤ p0_fichiers_max ou une seule zone           → P0 (pas d'équipe)
sinon si fanout.elements ≥ items_homogenes_min et vérification déterministe → P1b
sinon si zones indépendantes (couplage ≤ couplage_parallele_max) ≥ 2 et chacune ≥ zone_min_fichiers
                                                                    → P1, écrivains = min(zones, ecrivains_simultanes)
sinon si couplage entre zones > couplage_parallele_max              → P1 séquentiel : contrat d'abord, une zone à la fois,
                                                                       ou P0 si la séquence tient dans une session
P2 n'est jamais proposé au cadrage : il ne naît que d'un signal en cours de route (§1.2)
```

La largeur et le couplage sont le seul signal de composition retenu (03 §1 et §3 : gain mesuré sur le parallélisable, perte sur le séquentiel).

### 4.4 Contrôle de recouvrement

`plan.ps1 verifier` développe chaque glob en fichiers réels (plus les fichiers nouveaux déclarés) : intersection vide entre zones, aucun fichier partagé dans une zone, chaque fichier candidat attribué une fois (zone ou principal). `lecture_seule` peut recouvrir. Échec : exit 3 avec la liste des fichiers en double.

### 4.5 Porte utilisateur

| Cas | Porte |
|---|---|
| P0 | aucune |
| P1 avec un seul écrivain, conseillers en lecture seule, budget par défaut | sautée : le plan s'annonce en une ligne et part |
| P1 avec ≥ 2 écrivains | **obligatoire** (`AskUserQuestion` avec le résumé de `plan.ps1 resume`) |
| P1b | native : `ask` sur `Workflow` |
| P2 | **obligatoire et mécanique** : `CLIFF_EQUIPE_P2=1`, que seul l'utilisateur pose |
| budget au-dessus du défaut, ou action irréversible ou sortante prévue | obligatoire |
| descente (fusion, retour P0) | aucune, journalisée |

Avant une porte réellement indécidable par moi, la règle globale s'applique (soumettre d'abord à `independent-critic`) ; la porte d'un plan ordinaire n'en a pas besoin.

---

## 5. Garde-fous

- **Une source** : `budget-agents.json`. Tout autre endroit renvoie à lui (§3.10) ; les deux copies d'environnement possibles sont vérifiées par la garde.
- **Budgets** : simultanés 5, écrivains 3, un écrivain par zone, total 12 par plan, coéquipiers 3, 6 lancements par tour ; itérations 8 par zone ; échéance par plan. Le temps n'est pas un plafond du harness : il vit dans `plan.budget.echeance`, que la garde applique.
- **Arrêt stérile** : même empreinte d'échec 3 fois sur une zone → refus de tout nouveau lancement sur la zone ; le principal rend compte à l'utilisateur, ne repatche pas (cohérent avec « 3 correctifs échoués = stop » du CLAUDE.md global).
- **Désescalade** : fusion de zones sur couplage découvert ; retour à P1 sans échange entre coéquipiers ; retour à P0 quand il ne reste qu'une zone ; fin de tâche = fin d'agent (pas d'agent qui attend).
- **Doublons P2** : refus au lancement (même `name` vivant, même empreinte dans un tour), détection à `SubagentStart`, coupure à `TeammateIdle`.
- **Refus du hook** : le principal le rapporte tel quel ; reformuler un lancement pour passer la garde est interdit par la section CLAUDE.md, et visible dans `decisions.jsonl`.
- **Remonte à l'utilisateur** : toute montée en P2 ; tout arrêt stérile ; tout doublon ; tout dépassement de budget demandé ; une zone refusée à l'intégration (fichier hors zone) ; un écart estimé/réel > 50 % en clôture.
- **Échouer visiblement** : un agent qui finit sans `SIGNAL:` valide est journalisé `rapport_illisible` et compté comme non livré.

---

## 6. Mise en place par palier, et mesure sur deux semaines

| Étape | Contenu | Ce qui se mesure avant l'étape suivante |
|---|---|---|
| 0 | Mesures M1 à M10 dans un projet d'essai, sonde temporaire | chaque réponse écrite avec son extrait ; décide la porte P2 (chaud ou relance) et la faisabilité du mode `rapport` |
| 1 | `budget-agents.json` (`mode: journal`), `guard-budget-agents.ps1` (modes pre/post/stop), `log-agents.ps1`, `hooks.json`, renvois §3.10 | une semaine : `decisions.jsonl` relu ; refus qui auraient eu lieu classés vrais ou faux ; latence par appel `Agent` ; aucun refus de `name` hors P2 manquant |
| 2 | skill `equipe` (P0/P1), `ouvrier-zone`, `plan.ps1`, `co-changement.ps1`, `integrer.ps1` ; section CLAUDE.md | 3 chantiers réels au moins : plans produits, part de « P0 : pas d'équipe », zones refusées à l'intégration, écart estimé/réel |
| 3 | `mode: bloquer` si les faux refus de l'étape 1 sont rares (< 10 % des refus) ; workflows modifiés (§3.7) | runs de workflow : critiques maison effectivement appelés, `agents-max` cohérent avec le budget |
| 4 | P2 : `settings.json` (`AGENT_TEAMS`), modes start/idle/rapport, porte `CLIFF_EQUIPE_P2` | un essai P2 volontaire sur un cas à couplage négocié ; zéro doublon ; messages entre coéquipiers comptés ; désescalade observée |

Journaux, tous dans `~\.equipe-log\` (hors versionnement) :
- `agents.jsonl` (fin de chaque agent), `decisions.jsonl` (chaque décision de la garde), `chantiers.jsonl` (rétro de chaque plan clos) ; `critics.jsonl` reste où il est.

À relire au bout de deux semaines, chacun avec sa commande de lecture (`ConvertFrom-Json` ligne à ligne) :
1. Part des demandes cadrées en P0 (attendu : la majorité ; sinon le cadrage gonfle).
2. Refus de la garde : nombre, raisons, vrais ou faux ; aucun refus vrai ne doit avoir été contourné.
3. Escalades : nombre par palier, signal déclencheur, issue (livré, abandonné) ; une escalade qui ne livre pas mieux que le palier inférieur rend son seuil plus strict.
4. Écart estimé/réel par zone et par chantier.
5. Doublons P2 (doit être 0 ; sinon P2 suspendu en remettant `CLIFF_EQUIPE_P2` à vide).
6. Arrêts stériles et ce que l'utilisateur en a fait.
7. Jetons par chantier (`totalTokens` des lancements au premier plan, dans `agents.jsonl` quand disponible) ; les lancements en arrière-plan n'en donnent pas (01 §1), dit tel quel.

---

## 7. Risques, et ce que la proposition ne fait volontairement pas

Risques :
- **Champs de hook non documentés** (M4, M5, M7, M10) : sans eux, le lien agent → zone et le mode `rapport` tombent ; le repli est le principal qui passe le signal à `plan.ps1` et la barrière de zone à l'intégration, déjà prévue.
- **Agents de workflow invisibles à la garde** (M6) : bornés par la déclaration `agents-max`, qui reste déclarative ; écart dit, non corrigé.
- **Doublons créés par le harness** (#55586) : non refusables à la création, seulement détectés et coupés ; le coût d'un tour de doublons reste possible. D'où P2 en dernier, sous porte, avec mesure.
- **Flag d'équipe permanent** : peut changer le comportement du modèle (proposer des coéquipiers plus souvent) ; mesuré par les refus de `name` dans `decisions.jsonl` ; si gênant, retirer le flag et passer P2 en relance de session.
- **Plan modifiable par le modèle** : ce n'est pas une barrière de sécurité ; il est journalisé (empreintes), et les deux portes qui comptent (P2, budget au-dessus du défaut) passent par l'utilisateur.
- **Isolation par worktree** dans un projet aux dossiers non suivis : essais cassés dans le worktree ; d'où `isolation: "aucune"` permis, avec contrôle d'union à l'intégration.
- **Latence** : un démarrage PowerShell par lancement d'agent ; négligeable devant un agent, à confirmer à l'étape 1.
- **État périmé** après plantage : TTL de 60 min sur les agents vivants ; un agent légitime plus long que le TTL libère un créneau à tort (journalisé `perime`).

Ne fait volontairement pas :
- Ne promeut jamais un sous-agent en coéquipier, et ne fait monter en P2 qu'à partir du thread principal, par relance à neuf.
- Pas de routeur LLM de composition : la décision est une règle sur le graphe, dans un script.
- Pas d'escalade automatique vers plus d'écrivains parallèles sans l'utilisateur.
- Pas de garde de zone en direct sur Bash (écritures par shell) : la barrière de zone est à l'intégration.
- Ne dépend pas des outils Task ni de la liste de tâches du harness.
- Ne modifie pas le `CLAUDE.md` de Réactorm : ses « 20 à la fois » (l. 187) contredisent le budget global ; c'est à l'utilisateur de choisir entre abaisser cette règle ou poser `CLIFF_EQUIPE_SIMULTANES` dans les réglages du projet, et de corriger le renvoi mort de sa l. 208.
- Ne corrige pas la cible de `agent-builder` (`~\agents\` contre `CS\agents\`), hors périmètre ; signalé.
- N'écrit aucune configuration maintenant : ce document seul.
