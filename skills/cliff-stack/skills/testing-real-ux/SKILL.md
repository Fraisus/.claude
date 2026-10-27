---
name: testing-real-ux
description: "Runs final user-experience tests on the REAL application with real input events: Playwright drives Chromium through its DevTools protocol (trusted clicks, keys, typing, isTrusted true), never the OS mouse, keyboard or foreground. Works for websites (WordPress, Astro, any URL) and for desktop apps built on Chromium (Electron, any app that opens a DevTools port in a test mode), every page target of the app (its views and windows; an Electron <webview> tag is not one) reached as a page. Each project declares how to open its app in a small data file (.claude/ux-e2e.json); journeys are written by role and visible label, like a user reads the screen, and the run returns a JSON report, screenshots and a Playwright trace. Use when asked to test the real UX, run an end-to-end journey, check a feature or an add-on as a user would, click through the real app, validate a screen before delivery, or set up such tests on a new project. Triggers: « tester le vrai UX », « test de bout en bout », « vrais clics », « parcours utilisateur », « tester l'appli Electron », « Playwright sur l'appli », « test final », « e2e »."
---

# Parcours réels sur la vraie application

But : prouver qu'une fonction marche **pour un utilisateur**, dans l'application réelle, par de vrais gestes. Un geste Playwright passe par le protocole de débogage de Chromium : la page reçoit des événements authentiques (`isTrusted: true`), après les vérifications d'action (visible, stable, non recouvert, actif). C'est le vrai parcours à un étage près, le système : choix de fichier natif, menus natifs, barre des tâches restent hors de portée (automatisation du système, à part, avec l'accord de l'utilisateur).

## Lancer un parcours
Run `node <ce skill>/scripts/ux.mjs <parcours.mjs> [--projet <dir>]` depuis le projet. Le script lit l'adaptateur, ouvre l'application, se branche, joue le parcours, rend un rapport JSON (étapes, captures de chaque page, trace) et code 0 seulement si tout passe. Il ne télécharge rien : Playwright est pris dans le projet, dans `PLAYWRIGHT` ou dans le cache de npx ; un navigateur déjà installé sert de repli. Ouvrir la trace : `npx playwright show-trace <trace.zip>`.

## En vérification de fond (niveau 4, silencieux)
Quand un agent de fond vérifie un lot (CLAUDE.md global, porte (8)), il explore : il reçoit un but et un résultat attendu, jamais un scénario, et choisit chaque geste d'après ce qu'il lit à l'écran, par les commandes de pilotage du projet quand il en déclare (son environnement d'essai de séance), sinon par un parcours jetable écrit dans `sortie` et joué par `ux.mjs` en une commande, en tâche de fond ; il lit ensuite le rapport JSON. Jamais geste par geste avec les outils MCP Playwright : chaque appel s'afficherait dans la conversation de l'utilisateur. Aucun parcours n'est gardé pour être rejoué : le dossier `parcours` de l'adaptateur ne reçoit un script que sur demande de l'utilisateur.

## Mettre en place un projet
1. Lire les règles du projet sur les tests, les ports et les données (CLAUDE.md, rules) : elles priment.
2. Écrire `.claude/ux-e2e.json` : format et exemples dans [reference/adaptateur.md](reference/adaptateur.md).
3. Appli de bureau : son mode test ouvre le port de débogage **sur 127.0.0.1, port tiré par le système**, et l'annonce par la ligne standard de Chromium `DevTools listening on ws://127.0.0.1:<port>/`. Si l'appli refuse ce port (garde de sécurité voulue), on ajoute une option de test au projet, acceptée seulement en mode test, avec sa règle écrite d'abord ; jamais un contournement de la garde.
4. Ce qui ouvre l'appli en mode test (faux serveur, copie isolée, données d'essai) vit dans un lanceur du projet, déclaré comme `commande` : ce skill ne nomme jamais un projet.

## Écrire un parcours
Un module `.mjs` qui exporte par défaut `async ({ page, pages, etape, pause, verifier, expect, sortie, navigateur })`. Exemples complets dans la référence.
- `page({ url, contient }, delai)` attend la page voulue parmi toutes les vues : `contient` est un sélecteur ou une fonction `(p) => p.getByRole(...)`. Une confirmation, un panneau, une fenêtre secondaire sont des pages à part.
- `etape(nom, fn)` découpe le rapport ; un échec dit l'étape, la capture et la trace disent où.
- Trouver les éléments comme l'utilisateur les voit : `getByRole`, `getByLabel`, `getByText`, `getByPlaceholder`. Un `#id` seulement quand rien de visible ne le nomme (un conteneur). Un parcours qui ne trouve pas un bouton par son libellé a trouvé un défaut.
- Vérifier ce que l'utilisateur voit, par `expect` (assertions qui réessaient), jamais par l'état interne de l'appli (variables, appels de fonctions) ni par `page.evaluate` pour agir : un geste programmatique ne prouve rien.

## Pièges vérifiés
- **Gardes de temps** : une confirmation qui ignore les gestes de ses premières centaines de millisecondes est une protection voulue. `await pause()` (600 ms) avant de cliquer, comme un humain ; jamais désactiver la garde.
- **Fenêtre active** : une appli qui ne valide qu'avec sa fenêtre au premier plan refuse un clic venu d'une fenêtre hors écran. Son mode test doit tenir la fenêtre pour active au premier geste reçu par le port, jamais l'amener au premier plan.
- **Fenêtre d'accueil** : la première ouverture montre souvent un écran d'accueil ou de session ; le parcours le traverse comme l'utilisateur (« Plus tard »), il ne le contourne pas.
- **Sondes du mode test** : une page qui tente exprès des navigations interdites laisse Playwright « waiting for navigation to finish ». Agir avant ses sondes, ou viser une autre page.
- **Instance unique** : une appli déjà ouverte par l'utilisateur bloque une seconde instance. Le lanceur du projet ouvre une copie isolée (son propre dossier de données), jamais l'instance de l'utilisateur.
- **Captures** : une fenêtre protégée des captures du système reste capturée par Playwright (rendu de la page) ; ces captures ne quittent pas le dossier `sortie`.

## Garde-fous
- Jamais la souris, le clavier ni le premier plan de l'utilisateur : le script relève la fenêtre au premier plan (Windows) et échoue si un processus descendu de lui (appli, serveur, navigateur) l'a prise.
- Le port de débogage n'existe qu'en mode test, sur 127.0.0.1 ; tout processus local peut s'y brancher pendant l'essai : jamais avec de vraies données ni la session de l'utilisateur.
- Seul ce que le script a lancé est arrêté : entrée standard fermée d'abord (le lanceur range et efface), puis, un par un, ses descendants encore vivants (relevés pendant le passage, date de naissance revérifiée, Windows réutilisant les identifiants) ; le rapport les nomme (`processus_arretes_de_force`). Une étape rattrapée par le parcours fait quand même échouer le passage.
- Rapport, captures, traces et fichiers provisoires de Playwright restent dans `sortie` du projet (un passage interrompu y laisse son `tmp/`) ; une trace contient le texte des pages : jamais partagée sans relecture.
