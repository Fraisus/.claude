# Stack maison — routage par capacité

But : router vers le bon skill/agent SANS coder de nom en dur (les noms peuvent être renommés). Vérifier le nom réel au runtime avant d'invoquer.

## Comment vérifier au runtime
- Skills : la liste des skills disponibles est fournie en contexte de session (bloc « available skills »). S'y référer pour le nom exact.
- Agents : de même, la liste des types d'agents disponibles est fournie en contexte. Sinon, `ls ~/.claude/agents/` donne les fichiers `.md` installés.
- Ne jamais invoquer un nom de mémoire : confirmer d'abord qu'il existe dans la liste courante.

## Table capacité → où router (à re-confirmer au runtime)

| Besoin | Capacité à chercher |
|---|---|
| Feature/bloc/réglage WordPress ou Gutenberg | skill d'implémentation WP-native |
| Feature/composant/page Astro | skill d'implémentation Astro-native |
| Tâche d'un domaine qui a son orchestrateur ou ses agents de zone (une zone, plusieurs zones, non-régression) | l'agent de la zone ou l'orchestrateur du domaine, quand la session en liste un |
| Tracer un bloc WP dans son pipeline de rendu (instrumentation temporaire) | agent traceur de pipeline de bloc WP |
| Cadrage d'un plan de fix/refactor hors d'un tel domaine (cause à la source, bon niveau) | agent critique de cadrage |
| Landing/page de vente/copy | skill copywriting landing |
| SEO/contenu/acquisition organique | skill SEO launch |
| Modèle de prix/tiers/WTP | skill pricing strategist |
| Séquences email lifecycle | skill email lifecycle |
| Plan d'acquisition payante | skill paid ads |
| Upsell free→pro DANS l'éditeur Gutenberg | skill in-product upgrade prompts |
| Optimisation readme.txt / classement repo WordPress.org | skill wporg readme optimizer |
| Freemius (code + produit) | skill freemius |
| Audit conformité plugin avant livraison | skill plugin check |
| Parcours réel dans l'application (site, appli Electron), vrai save éditeur WordPress compris | skill testing real UX |
| Audit de sécurité du code WordPress | skill wp security audit |
| Site WordPress piraté, mises à jour bloquées | skill wp incident response |
| Minifier JS/CSS | skill minify assets |
| Audit UI/design/a11y/dark patterns | skill design auditor |
| Concevoir, refaire ou polir une interface | skill impeccable |
| Animations (construire, auditer, trouver où animer, nommer un effet) | skills animate, improve animations, find animation opportunities, animation vocabulary |
| Casser une UI par des données extrêmes | skill break UI |
| Appli web qui doit sembler native sur téléphone | skill mobile native |
| Parcours de résiliation et rétention | skill cancellation flow designer |
| Leviers psychologiques du marketing | skill marketing psychology |
| Tickets Asana assignés | skill asana triage |
| Documentation pour un utilisateur non développeur | skill handbook |
| Recherche multi-sources vérifiée et arbitrée | workflow research-arbitrate (`~/.claude/workflows/`) ou deep-research selon l'ampleur |
| Réfuter une affirmation sur un artefact (diff, commande, URL), sur les critères du CLAUDE.md global seulement | agent critique indépendant |
| Boucle générateur→critique→vérifieur en worktree | workflow generator-critic-verifier (`~/.claude/workflows/`) |
| Créer/MAJ un skill | skill builder de skills |
| Créer/MAJ un agent | skill builder d'agents |
| Composer une équipe d'agents, changer de palier | skill equipe |

## Règles de fan-out (Fable)
- Fable excelle en sous-agents async : déléguer les sous-tâches indépendantes et continuer.
- Plafonds : `hooks/budget-agents.json` du plugin (une seule source : simultanés, total par plan, Playwright), tenus par la garde `guard-budget-agents`, qui journalise les agents et ne refuse que le palier P2 ; les deux workflows déclarent leur fan-out en tête (`// agents-max: N`) et sont bornés par elle.
- Jamais plus d'un agent Playwright à la fois (navigateur + session admin partagés → faux positifs).
