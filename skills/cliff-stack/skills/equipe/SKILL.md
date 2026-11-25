---
name: equipe
description: "Compose, lance et intègre une équipe d'agents à partir d'un plan de zones, et la fait changer de palier (session seule, sous-agents, /batch ou workflow, équipe coordonnée) sur des signaux mesurés. Use when une tâche semble demander plus d'un agent qui écrit, plus de deux agents en tout, un fan-out sur des éléments homogènes, une équipe coordonnée, ou quand un travailleur remonte un signal d'escalade ; déclencheurs : « fais-le en équipe », « monte une équipe », « parallélise », « lance des agents », « plusieurs agents », « agent teams », « gros chantier », « découpe en zones », « monte d'un palier », « redescends ». Règle par défaut : pas d'équipe ; le skill sait d'abord dire « une session seule suffit ». NE PAS utiliser pour lancer un seul vérificateur (independent-critic) ou un seul cadrage (framing-critic), qui s'appellent directement ; ni pour un domaine qui a déjà son orchestrateur dans la liste de la session, qui reste maître de sa composition sous les mêmes plafonds."
---

# equipe — composer, lancer et intégrer une équipe d'agents

Par défaut, PAS d'équipe : dis d'abord si une session seule suffit. Une équipe se justifie par la FORME du travail (zones écrites en parallèle, fan-out homogène, allers-retours entre zones), jamais par « ça semble gros ».

Le thread principal orchestre et écrit les contrats partagés ; chaque sous-agent écrit une zone ou conseille/relit ; un sous-agent ne lance qu'un agent qui lit (jamais un agent qui écrit ni un workflow), et ce qu'un critique ainsi lancé rend reste à arbitrer par le principal. Plafonds et journal : garde `budget-agents` déjà posée (`skills/cliff-stack/hooks/budget-agents.json`), journalisée par le hook ; pour un `Agent`, il ne refuse que les règles P2 et l'imbrication ; un workflow sans déclaration `// agents-max: N` sous le plafond est refusé. Un refus se rapporte tel quel, jamais contourné.

## 1. Cadrer (le plan de zones)
- Restitue la demande en une phrase.
- Nomme les fichiers candidats par commande (`git ls-files`, grep des termes), jamais de mémoire.
- Découpe en ZONES disjointes à partir des candidats : co-changement (`git log --name-only`), imports, et les `paths:` des rules du projet. Deux fichiers couplés (co-modifiés souvent, import direct) vont dans la MÊME zone ; sinon zones séparées. Un fichier ne vit que dans une zone. Les fichiers partagés (version commune, CLAUDE.md, contrats) restent au principal.
- Un lot se borne par un livrable vérifiable (une ligne de l'état dû du projet, un écran, un comportement), jamais par une durée, et se découpe avant le lancement : un lot redécoupé en route était mal cadré. Un plan s'écrit seulement quand le lot crée ou change une brique partagée, une frontière ou un contrat ; sinon le brief est le plan. Plan et brief nomment les briques que le lot réutilise et celles qu'il crée (fichier:symbole).
- Le budget d'écrivains est un plafond, pas une cible : un front de plus ne s'ouvre que si sa zone est disjointe et que la relecture et l'intégration des autres suivent.
- Palier par la forme : session seule (rien) ; sous-agents (≥2 zones disjointes, chacune ≥2 fichiers) ; /batch ou workflow (≥5 éléments homogènes, même transformation, vérification déterministe) ; équipe coordonnée (allers-retours répétés entre zones que le principal relaie mal), refusée aujourd'hui par la garde (règles P2) : reste en sous-agents.
- Modèles, choisis à chaque lancement par le paramètre `model` (il prime sur le frontmatter de l'agent ; le modèle par défaut des sous-agents ne vaut qu'en l'absence des deux) : Opus pour écrire du code neuf, corriger, juger ; Sonnet pour une transformation mécanique que le brief décrit entièrement et pour la récupération ; `Explore` pour chercher ; l'orchestration reste sur le modèle de conversation, l'adversaire sur Opus.
- Dès que ≥2 agents écrivent : montre le plan court (zones, écrivains, fichiers, budget) en l'annonçant, puis lance, sans attendre ; seule une action irréversible hors du périmètre demandé attend l'accord.

## 2. Activer
- Écris les contrats partagés toi-même d'abord.
- Un `Agent` par zone, rôle `cliff-stack:ouvrier-zone`, brief rempli depuis `reference/brief-ouvrier.md` ; les conseillers « avant » passent avant les écrivains.
- Deux écrivains jamais sur le même fichier. Jamais plus d'agents que le budget.
- Route selon la forme : fan-out homogène → `/batch` natif ; pipeline ordonné → un workflow ; zones disjointes → sous-agents simples. Ne refais pas ce que le natif fait déjà.

## Coût (un agent repris garde tout son historique)
- Un agent = un lot ; son rapport est sa passation, écrite dans un fichier. Reprendre un agent par message se fait sous `contexte_agent_max` moins `contexte_palier` (`budget-agents.json`, refus de `guard-agent-resume` au-delà, « #reprise » pour du code à moitié écrit après une coupure) ; un agent qui passe `contexte_agent_max` en travaillant rend sa passation, ou un rapport `partiel` s'il lit (`guard-agent-context`) ; la suite part avec un agent neuf qui lit passation et diff.
- Brief commun et annuaire dans un fichier lu par chaque agent.
- Sous-agents : pas de message d'agent à agent hors blocage ; rien envoyé à un agent fini. Un agent qui doit écrire à un voisin a l'outil SendMessage (`general-purpose`) ; `ouvrier-zone` et les experts de projet ne l'ont pas : leurs fichiers partagés se tranchent par le principal avant le lancement.
- Adversaire neuf sur le diff d'un lot seulement dans les cas que porte la description d'`independent-critic` ; les autres lots se prouvent par la porte (8) ; arbitre neuf par vague. Un expert d'agencement du projet passe seul avant un lot qui crée ou change une brique partagée ; après le lot, son angle se fond dans le brief de l'adversaire, un seul passage.
- Petite retouche : le principal.

## 3. Intégrer
- Une équipe se juge à son orchestration, jamais à son nombre : chaque agent reçoit un rôle net (codeur d'une zone, vérificateur, adversaire) et connaît, par l'annuaire, les agents des zones voisines et les fichiers de chacun ; il ne message un voisin que pour un blocage.
- Arbitre des essais (porte (8)) : un arbitre neuf par vague reprend l'environnement d'essai de séance par la passation, jamais un arbitre gardé toute la séance. Il lance une seule fois l'union des essais des fichiers touchés sur l'arbre intégré (jamais un essai par lot, par copie ni par agent), borne le parallélisme à la capacité mesurée de la machine et tient la file ; il n'isole un lot (copie, bissection) que pour attribuer un rouge, ne corrige rien et rend un rapport court.
- À chaque lot reçu, le principal relance le niveau 0 (porte (8)) ; une hausse renvoie le lot à un agent neuf. Un écrivain ne lance que le niveau 0 et les essais de ses fichiers touchés ; le contrôle complet du projet se lance une seule fois, par le principal, à l'intégration.
- Un défaut trouvé sur un lot se traite selon la gravité que rend son vérificateur (l'adversaire quand le lot en a un). Bloquant : il repart chez un codeur neuf avec le rapport, puis repasse chez le vérificateur ; les refus se comptent dans le plan, au troisième, stop et retour à l'utilisateur. Mineur : le lot s'intègre, le défaut s'écrit dans la dette du plan et l'état dû reste « partiel » tant qu'elle est ouverte.
- Tronc : une branche par lot, partie du tronc, commitée à chaque étape ; elle reprend le tronc une seule fois, juste avant d'entrer, et entre dès qu'elle est verte et relue sans défaut bloquant. Une fonctionnalité inachevée entre cachée par le mécanisme du projet, prouvée sans chemin jusqu'à son action.
- Le principal note par lot, dans le plan ou le suivi du projet : lancement, refus, entrée au tronc, défauts bloquants trouvés après ; sans ces dates, un changement de méthode ne se juge pas.
- Le principal dit à l'utilisateur ce qui a été trouvé et corrigé ; un écart ancien trouvé en chemin (défaut, essai rouge, dette en hausse) se corrige aussi, dans la foulée ; seuls une idée, ou ce qui attend une décision de l'utilisateur, vont à la file des écarts du projet.
- À la réception de chaque lot, le principal vérifie qu'aucun agent n'a écrit hors de ses fichiers permis (diff des fichiers touchés contre les globs de sa zone) et que le diff appelle chaque brique à réutiliser du plan sans en définir une copie, et ne crée que les briques qu'il nomme ; un écart = zone refusée, à relancer.
- Lance la vérification déclarée du plan.
- Une seule relecture finale quand le projet l'exige (sécurité, machines, adversaire), à contexte vierge, sur les lignes changées.

## 4. Escalader (en cours de route)
- Un ouvrier ne monte rien : il rend un signal (`hors_zone_requis`, `contrat_insuffisant`, `echec_repete`, `fanout_homogene`). Le principal lit, réécrit le plan, relance la zone à neuf au bon palier. L'état transmis est le plan plus les diffs déjà produits, jamais le contexte vivant d'un agent.
- Passages et signaux : `reference/escalade.md`.
- Le palier équipe coordonnée est refusé par la garde aujourd'hui (règles P2, actives quand `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`) : reste en sous-agents.
