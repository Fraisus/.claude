# asana-triage — setup sur un nouveau poste

Pipeline de triage des tickets Asana, **versionné dans le repo** : après un `git pull`, tout est là
(skill, module `bin/asana-api.mjs`, helper `bin/asana-cred.ps1`, agent `asana-ticket-analyzer`).

## La seule étape manuelle : injecter le PAT une fois par poste

Le **token Asana est un secret** : il ne peut pas être dans git (faille de sécurité + interdit par les hooks).
C'est donc **la seule chose à faire** sur une nouvelle machine — une fois, ~30 s :

1. Générer un Personal Access Token : https://app.asana.com/0/my-apps → *Create new token*.
2. Le stocker dans le Credential Manager **de ce poste** (chemin résolu au runtime) :
   ```bash
   BIN=$(dirname "$(find "$USERPROFILE/.claude/skills" -path '*/asana-triage/bin/asana-api.mjs' -print -quit)")
   ASANA_CRED_IN='<colle-le-PAT-ici>' powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$BIN/asana-cred.ps1" -Action store
   ```
3. Vérifier : `node "$BIN/asana-api.mjs" me` doit afficher ton identité + workspaces.

Après ça : `/asana-triage` fonctionne, et le module **découvre tout dynamiquement** (identité, workspaces,
Premium) — aucun gid ni chemin à retrouver/ré-injecter.

## Prérequis du poste
- **Windows** (le helper utilise le Windows Credential Manager + `powershell.exe`).
- **Node** (fetch natif, v18+) et **curl** dans le PATH (Git Bash).

## Notes
- Le PAT est full-access (Asana n'a pas de token read-only) ; le pipeline s'impose la lecture seule
  par convention (seule écriture autorisée : un commentaire dont l'utilisateur a validé le texte). Révocable sur la page *my-apps*.
- Rotation du token : relancer l'étape 2 (écrase l'ancien). Suppression : `... -Action delete`.
- Le module fait remonter toute erreur HTTP en exit code ≠ 0 (jamais de succès silencieux) et retry sur 429/5xx.
