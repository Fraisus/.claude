---
paths:
  - "**/*.py"
  - "**/*.js"
  - "**/*.mjs"
  - "**/*.cjs"
  - "**/*.ts"
  - "**/*.ps1"
---

# Processus enfants : jamais de fenêtre console surprise (Windows)

Sous Windows, un programme console lancé par un parent **qui n'a
pas de console** en reçoit une neuve, et elle est visible : un onglet Windows Terminal titré du nom de
l'exécutable. Un parent n'a pas de console quand il a lui-même été lancé détaché (`DETACHED_PROCESS`,
`detached: true`), ou depuis une application graphique. Le cas typique : un gardien détaché qui relance
un `node` ; la fenêtre apparaît à chaque relance, et personne ne sait qui l'ouvre.

- **Tout lancement d'enfant console porte le drapeau sans fenêtre**, dès la première écriture, via **une
  constante unique par projet**, jamais inline : Python `creationflags=CREATE_NO_WINDOW` (`0x08000000`),
  Node `windowsHide: true`, PowerShell `Start-Process -WindowStyle Hidden` ou `-NoNewWindow`. Avant d'en
  créer une, chercher celle du projet (`grep -rn "CREATE_NO_WINDOW\|windowsHide"`) et la réutiliser.
- **Garder la portabilité** : la constante vaut `{}` hors Windows (`if os.name == "nt"` / `process.platform
  === "win32"`), le drapeau n'a de sens que là.
- **Un enfant sans fenêtre n'a nulle part où écrire** : ses `stdin`, `stdout`, `stderr` se fixent
  explicitement (`DEVNULL`, `PIPE`, un fichier journal). Un enfant qui hérite d'une console absente et
  qui écrit meurt en silence.
- Le drapeau se pose **sur chaque appel** qui lance un processus, pas seulement sur le
  détachement : c'est l'enfant de l'enfant qui ouvre la fenêtre.

Le hook `guard-console-window` (plugin cliff-stack, `PostToolUse` sur Write/Edit) signale les appels sans
drapeau dans tout fichier qui détache un processus. Il informe, il ne bloque pas : la preuve reste la
mesure (compter les `OpenConsole.exe` avant et pendant le lancement, +1 = une fenêtre s'est ouverte).
