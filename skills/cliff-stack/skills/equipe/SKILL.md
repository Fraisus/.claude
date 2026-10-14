---
name: equipe
description: "Compose, lance et intègre une équipe d'agents à partir d'un plan de zones, et la fait changer de palier (session seule, sous-agents, /batch ou workflow, équipe coordonnée) sur des signaux mesurés. Use when une tâche semble demander plus d'un agent qui écrit, plus de deux agents en tout, un fan-out sur des éléments homogènes, une équipe coordonnée, ou quand un travailleur remonte un signal d'escalade ; déclencheurs : « fais-le en équipe », « monte une équipe », « parallélise », « lance des agents », « plusieurs agents », « agent teams », « gros chantier », « découpe en zones », « monte d'un palier », « redescends ». Règle par défaut : pas d'équipe ; le skill sait d'abord dire « une session seule suffit ». NE PAS utiliser pour lancer un seul vérificateur (independent-critic) ou un seul cadrage (framing-critic), qui s'appellent directement ; ni pour un domaine qui a déjà son orchestrateur (moteur morph : morph-orchestrator ; tickets Asana : asana-triage), qui reste maître de sa composition sous les mêmes plafonds."
---

# equipe — composer, lancer et intégrer une équipe d'agents

Réponds en français. Par défaut, PAS d'équipe : dis d'abord si une session seule suffit. Une équipe se justifie par la FORME du travail (zones écrites en parallèle, fan-out homogène, allers-retours entre zones), jamais par « ça semble gros ».

Le thread principal orchestre et écrit les contrats partagés ; chaque sous-agent écrit une zone ou conseille/relit ; un sous-agent ne lance jamais d'agent. Plafonds et journal : garde `budget-agents` déjà posée (`skills/cliff-stack/hooks/budget-agents.json`, appliquée par hook) ; un refus du hook se lit et se rapporte, jamais contourné.

## 1. Cadrer (le plan de zones)
- Restitue la demande en une phrase.
- Nomme les fichiers candidats par commande (`git ls-files`, grep des termes), jamais de mémoire.
- Découpe en ZONES disjointes à partir des candidats : co-changement (`git log --name-only`), imports, et les `paths:` des rules du projet. Deux fichiers couplés (co-modifiés souvent, import direct) vont dans la MÊME zone ; sinon zones séparées. Un fichier ne vit que dans une zone. Les fichiers partagés (version commune, CLAUDE.md, contrats) restent au principal.
- Palier par la forme : session seule (rien) ; sous-agents (≥2 zones disjointes, chacune ≥2 fichiers) ; /batch ou workflow (≥5 éléments homogènes, même transformation, vérification déterministe) ; équipe coordonnée (allers-retours répétés entre zones que le principal relaie mal), expérimentale et derrière la porte utilisateur.
- Modèles : Opus pour tout agent qui écrit ou juge ; Sonnet pour la récupération pure ; l'orchestration et l'adversaire restent sur le modèle de conversation.
- Porte utilisateur dès que ≥2 agents écrivent : montre le plan court (zones, écrivains, fichiers, budget) et attends l'accord avant de lancer.

## 2. Activer
- Écris les contrats partagés toi-même d'abord.
- Un `Agent` par zone, rôle `cliff-stack:ouvrier-zone`, brief rempli depuis `reference/brief-ouvrier.md` ; les conseillers « avant » passent avant les écrivains.
- Deux écrivains jamais sur le même fichier. Jamais plus d'agents que le budget.
- Route selon la forme : fan-out homogène → `/batch` natif ; pipeline ordonné → un workflow ; zones disjointes → sous-agents simples. Ne refais pas ce que le natif fait déjà.

## 3. Intégrer
- Vérifie qu'aucun agent n'a écrit hors de ses fichiers permis (diff des fichiers touchés contre les globs de sa zone) ; un fichier hors zone = zone refusée, à relancer.
- Lance la vérification déclarée du plan.
- Une seule relecture finale quand le projet l'exige (sécurité, machines, adversaire), à contexte vierge, sur les lignes changées.

## 4. Escalader (en cours de route)
- Un ouvrier ne monte rien : il rend un signal (`hors_zone_requis`, `contrat_insuffisant`, `echec_repete`, `fanout_homogene`). Le principal lit, réécrit le plan, relance la zone à neuf au bon palier. L'état transmis est le plan plus les diffs déjà produits, jamais le contexte vivant d'un agent.
- Passages et signaux : `reference/escalade.md`.
- Monter en équipe coordonnée exige la porte utilisateur (variable `CLIFF_EQUIPE_P2`) ; sans elle, reste en sous-agents.

## Incrément suivant (non encore livré)
La composition déterministe par script (`plan.ps1` proposer/verifier, `co-changement.ps1`, `integrer.ps1`) calcule zones, couplage, budget et contrôle d'intégration à la place de la méthode manuelle ci-dessus. À écrire et tester après une mesure de ce que `/batch` et les workflows couvrent déjà, jamais posé non testé. D'ici là, applique la méthode ci-dessus à la main.
