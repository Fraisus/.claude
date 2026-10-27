# Sécurité du poste Claude Code (documentation, pour l'humain)

Le poste tourne en autonomie maximale. Ce fichier décrit le montage ; les deux consignes que le modèle doit suivre sont dans `~/.claude/CLAUDE.md`, section « Sécurité Claude Code ».

## Montage
- `permissions.defaultMode: "auto"` dans `~/.claude/settings.json`.
- Barrière par les hooks `PreToolUse` câblés dans `hooks.json` de ce dossier : guard-write-scope, guard-secrets-read, guard-git-destructive, guard-root-hygiene sur le shell ; guard-budget-agents sur Agent et Workflow.
- `permissions.deny` en filet.

## Ce qui protège vraiment
Les hooks et les `deny` s'appliquent dans tous les modes, bypass compris. Les `allow` n'ont aucun effet en bypass, et les plus larges (`Bash(*)`, `PowerShell(*)`, `Agent`) sont supprimées d'office en mode auto : ne jamais compter sur elles, ni en ajouter par projet (inutile, génère des prompts).

## Ce qui interrompt encore, et c'est voulu
- Les règles `ask` : `Workflow`, édition des scripts de hooks, de `settings*.json` et de `write-scope-extra-roots.txt`.
- Les git destructifs, `AskUserQuestion`, `rm` visant un chemin critique.
Détail : `settings.json` (`permissions`) et `hooks.json`.

## Extension VS Code
Le mode ne vient pas de `~/.claude/settings.json` seul : il faut aussi `claudeCode.allowDangerouslySkipPermissions: true` et `claudeCode.initialPermissionMode` dans les réglages utilisateur VS Code, sinon la conversation démarre en Manual. Le mode se fixe à l'ouverture d'une conversation, jamais à chaud.

## Un hook bloque à tort
Corriger le script dans `scripts/`, jamais le câblage. Pour écrire hors du projet courant, lancer ce dossier comme projet au lieu de contourner.
