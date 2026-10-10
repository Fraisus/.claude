# claude-config

Configuration Claude Code versionnée, portable d'un poste à l'autre : un clone du
dépôt dans `~/.claude` suffit à provisionner un environnement complet (skills, agents,
hooks, réglages, règles et workflows).

## Politique de versionnement : deny-by-default

Le dossier `~/.claude` d'une machine en usage mélange configuration réutilisable et
données sensibles (credentials, transcripts de sessions, mémoires par projet). Le
`.gitignore` applique donc une politique inversée :

1. **Tout est ignoré par défaut** (`/*` puis `/**`).
2. **Seules les zones approuvées sont whitelistées** : `CLAUDE.md`, `settings.json`,
   `rules/`, `skills/`, `agents/`, `hooks/`, `scripts/`, `workflows/`, `plugins/` (index).
3. **Les patterns de secrets sont re-bloqués globalement** (`**/*secret*`, `**/*password*`,
   `**/*token*`, clés SSH). Exception documentée : `guard-secrets-read.ps1` est un script
   de configuration dont le nom contient « secret » ; il est ré-inclus explicitement,
   sans quoi un clone neuf perdrait ce hook.
4. **Les zones sensibles sont re-exclues nommément** en défense en profondeur
   (`projects/`, `sessions/`, `playwright-output/`, `shell-snapshots/`,
   `.credentials.json`, etc.).

Un `git status` propre garantit ainsi qu'aucune donnée sensible ne peut partir par
accident ; une nouvelle zone n'entre dans le versionnement que par ajout explicite
à la whitelist.

## Architecture : un dépôt, un plugin in-place

L'essentiel de la configuration vit dans **`skills/cliff-stack/`**, un plugin Claude Code
complet développé directement dans le dépôt (pas de dépôt séparé, pas de copie) :

```
~/.claude/
├── CLAUDE.md                  # Instructions globales (langue, sécurité, style, rigueur)
├── settings.json              # Réglages du harness (permissions, hooks du plugin)
├── README.md                  # Ce fichier
├── rules/                     # Règles par sujet, chargées automatiquement selon le contexte
│   │                          #   (frontmatter `paths:` = fichiers qui les déclenchent)
│   ├── architecture-extension.md  # Points d'extension : nommage, moment de lecture, dépréciation
│   ├── code-style.md              # 2 occurrences, commentaires, destroy, build des assets
│   ├── css-scss.md                # Invariants CSS (baseline du projet, zéro déclaration morte)
│   ├── wordpress-php.md
│   ├── morph-blocks.md
│   └── astro.md
├── workflows/                 # Scripts d'orchestration multi-agents réutilisables
│   ├── generator-critic-verifier.js
│   └── research-arbitrate.js
├── scripts/
│   ├── setup-mcp.ps1          # Installation des serveurs MCP sur un poste neuf
│   └── wp-playwright-storage-state.php  # Cookies WP → storage-state partagé (Playwright --isolated)
├── plugins/                   # Index des plugins installés (catalogue, pas de code)
└── skills/
    ├── developing-godot-games/  # Skill autonome hors plugin
    └── cliff-stack/             # Plugin principal : toute nouvelle brique se crée ici
        ├── .claude-plugin/plugin.json
        ├── skills/              # Skills métier (WordPress natif, licensing, SEO,
        │                        #   pricing, design, authoring de skills/agents, etc.)
        ├── agents/              # Agents spécialisés (flotte d'audit multi-zones,
        │                        #   critique de cadrage, testeur de régression,
        │                        #   analystes marché, etc.)
        ├── hooks/
        │   ├── hooks.json       # Câblage des hooks (source de vérité)
        │   └── scripts/         # Scripts des hooks (guards, PHPCS, sons, timer)
        ├── scripts/             # Utilitaires d'administration (dont vendor-sync.ps1)
        ├── vendor/              # Skills tiers : manifeste épinglé + surcouches maison
        ├── sounds/              # Sons des notifications
        └── phpcs-wp-native.xml  # Ruleset PHPCS WordPress
```

**Convention** : tout nouveau skill, agent ou hook se crée dans `skills/cliff-stack/`,
jamais à la racine de `~/.claude`, afin de rester dans le périmètre du plugin et du
versionnement.

### Skills tiers (vendor)

Un skill repris d'un dépôt tiers (aujourd'hui `impeccable`, `design-motion-principles`
et les skills de mouvement d'Emil Kowalski) n'est jamais retouché à la main : son dossier
sous `skills/` est une sortie de build. Chaque dépôt amont a son manifeste
`vendor/<source>/vendor.json` : dépôt git, commit complet épinglé, puis la liste des
skills qu'on en tire (sous-dossier, transformations maison : remplacement par motif,
texte ajouté, complément de description, correctif `.patch`). `vendor/_shared/` porte
les textes et les transformations communs à tous les skills tiers (priorité des règles
du projet, convention de chemins).

```powershell
skills/cliff-stack/scripts/vendor-sync.ps1            # reconstruit et installe tout
skills/cliff-stack/scripts/vendor-sync.ps1 -Check     # écart entre build et installé (exit 1)
skills/cliff-stack/scripts/vendor-sync.ps1 -Skill impeccable -Commit <sha complet> -Out <dossier>
                                                      # essai d'une autre version, sans installer
```

Chaque transformation doit agir, sinon le build s'arrête en nommant celle qui a échoué :
une version amont qui change de structure ne passe jamais en silence. Monter de version,
c'est auditer le build d'essai, puis changer `commit` (et au besoin les transformations)
dans le manifeste et relancer. Ajouter un skill tiers, c'est ajouter un dossier dans
`vendor/`. Seul prérequis : git (déjà requis par Claude Code sous Windows).

## Modèle de sécurité

L'environnement fonctionne avec des permissions shell larges, compensées par une
barrière de hooks `PreToolUse` câblés dans le plugin :

| Hook | Rôle |
|---|---|
| `guard-write-scope` | Bloque toute écriture ou destruction hors des zones autorisées (projet courant, dossier temporaire, `~/.claude`, plus les racines listées dans un fichier local) |
| `guard-secrets-read` | Bloque la lecture via shell des fichiers de secrets (`.env`, credentials, clés) |
| `guard-git-destructive` | Intercepte les commandes git destructrices (reset --hard, push --force, clean) pour confirmation |
| `guard-workflow-budget` | Bloque tout lancement de `Workflow` sans lecture préalable du script et déclaration `// agents-max: N` (fan-out mécanique calculé) ; plafond 12 agents, au-delà validation explicite de l'user requise (`// agents-max-user-ok`) |

En complément : `permissions.deny` dans `settings.json` en filet, analyse PHPCS
WordPress automatique après chaque écriture PHP (`PostToolUse`), rappel des règles
actives à chaque prompt (`UserPromptSubmit`), notifications sonores.

Principes de maintenance : pas de règles `allow` par projet (redondantes, sources de
prompts inutiles) ; un hook qui bloque à tort se corrige dans son **script**
(`skills/cliff-stack/hooks/scripts/`), jamais en retirant le câblage.

## Installation sur un poste neuf

```bash
git clone https://github.com/Cliff-FK/claude-config.git "%USERPROFILE%\.claude"
```

Puis :
1. Exécuter `scripts/setup-mcp.ps1` pour les serveurs MCP (playwright, context7).
2. Recréer les fichiers locaux non versionnés : `write-scope-extra-roots.txt`
   (racines d'écriture supplémentaires propres au poste) et les credentials.
3. Les dossiers runtime (`projects/`, `sessions/`, `playwright-output/`, etc.) se
   recréent à l'usage ; ils sont volontairement locaux au poste et hors versionnement.

## Maintenance

Le dépôt se sauvegarde par commit et push classiques. Avant d'ajouter une zone à la
whitelist du `.gitignore`, lire les commentaires de ce fichier : la politique de
secrets y est documentée en place.
