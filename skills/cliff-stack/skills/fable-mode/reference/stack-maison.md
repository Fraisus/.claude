# Stack maison — routage par capacité

But : router vers le bon skill/agent SANS coder de nom en dur (les noms peuvent être renommés). Vérifier le nom réel au runtime avant d'invoquer.

## Comment vérifier au runtime
- Skills : la liste des skills disponibles est fournie en contexte de session (bloc « available skills »). S'y référer pour le nom exact.
- Agents : de même, la liste des types d'agents disponibles est fournie en contexte. Sinon, `ls ~/.claude/agents/` donne les fichiers `.md` installés.
- Ne jamais invoquer un nom de mémoire : confirmer d'abord qu'il existe dans la liste courante.

## Table capacité → où router (juin 2026, à re-confirmer au runtime)

| Besoin | Capacité à chercher |
|---|---|
| Feature/bloc/réglage WordPress ou Gutenberg | skill d'implémentation WP-native |
| Feature/composant/page Astro | skill d'implémentation Astro-native |
| Bug/audit/feature du moteur responsive morph (une seule zone) | agent de la zone concernée (editor, build+cache, serve+front, signature) |
| Tâche du moteur responsive morph touchant plusieurs zones, ou "pourquoi X ne marche pas de bout en bout" | orchestrateur morph (dispatche + impose la chaîne admin→cache→front) |
| Investigation générale du moteur responsive morph (repro Playwright + cache DB + code) | agent auditeur morph |
| Non-régression après un fix du moteur responsive morph (matrice save-paths × viewports) | agent testeur de régression |
| Tracer un bloc WP dans son pipeline de rendu (instrumentation temporaire) | agent traceur de pipeline de bloc WP |
| Cadrage d'un plan de fix/refactor HORS moteur responsive morph (cause à la source, bon niveau) | agent critique de cadrage |
| Landing/page de vente/copy | skill copywriting landing |
| SEO/contenu/acquisition organique | skill SEO launch |
| Modèle de prix/tiers/WTP | skill pricing strategist |
| Séquences email lifecycle | skill email lifecycle |
| Plan d'acquisition payante | skill paid ads |
| Upsell free→pro DANS l'éditeur Gutenberg | skill in-product upgrade prompts |
| Optimisation readme.txt / classement repo WordPress.org | skill wporg readme optimizer |
| Freemius (code + produit) | skill freemius |
| Audit conformité plugin avant livraison | skill plugin check |
| Vrai save humain éditeur via Playwright | skill wp save UI test |
| Minifier JS/CSS | skill minify assets |
| Audit UI/design/a11y/dark patterns | skill design auditor |
| Recherche multi-sources vérifiée et arbitrée | workflow research-arbitrate (`~/.claude/workflows/`) ou deep-research selon l'ampleur |
| Réfuter une affirmation sur un artefact (diff, commande, URL), sur les critères du CLAUDE.md global seulement | agent critique indépendant |
| Boucle générateur→critique→vérifieur en worktree | workflow generator-critic-verifier (`~/.claude/workflows/`) |
| Créer/MAJ un skill | skill builder de skills |
| Créer/MAJ un agent | skill builder d'agents |

## Règles de fan-out (Fable)
- Fable excelle en sous-agents async : déléguer les sous-tâches indépendantes et continuer.
- Plafonds : `hooks/budget-agents.json` du plugin (une seule source : simultanés, total par plan, Playwright), appliqués par la garde `guard-budget-agents` sur Agent et Workflow ; les deux workflows déclarent leur fan-out en tête (`// agents-max: N`) et sont bornés par elle.
- Jamais plus d'un agent Playwright à la fois (navigateur + session admin partagés → faux positifs).
