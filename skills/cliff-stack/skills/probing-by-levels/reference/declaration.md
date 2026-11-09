# Déclarer les échelons d'un projet

Un projet déclare ses outils une fois, dans son `CLAUDE.md` ou une règle, sous ce format. Seul un outil réellement
présent dans le projet s'y écrit ; un échelon sans outil se déclare « aucun » et se dit sauté dans les rapports.

```markdown
**Échelons de sonde** (du plus rapide au plus précis) :
- enregistré : <commande de lecture des données et de la configuration résolue>
- servi : <commande qui récupère la réponse réelle d'une route ou d'une page>
- calculé : <outil d'exécution sans fenêtre déjà présent dans le projet>
- geste réel : <adaptateur de parcours réel, ou « aucun »>
```

## Découvrir les outils

Dans cet ordre, sans rien installer :
1. Les règles du projet (CLAUDE.md, rules) : un outil déjà imposé prime.
2. Les lanceurs et CLI du projet : scripts du gestionnaire de paquets, wrappers documentés, CLI du framework.
3. Un navigateur sans fenêtre déjà présent dans les dépendances du projet ou d'un de ses outils.
4. L'adaptateur de parcours réel (`.claude/ux-e2e.json`) pour le geste réel.

## Correspondances par type de projet

Exemples de départ pour la découverte, à remplacer par les outils réels du projet.

| Type | Enregistré | Servi | Calculé | Geste réel |
|---|---|---|---|---|
| Site WordPress | WP-CLI en lecture (`wp eval-file`, réglages globaux fusionnés, contenu des posts) | `curl` sur le front (HTML, feuilles de styles générées) | Navigateur sans fenêtre (style calculé, mise en page) | Éditeur ou parcours par `testing-real-ux` |
| Service avec API | Requête sur la base ou la configuration résolue | `curl` sur la route, avec un jeton d'essai | Rarement utile : la réponse est le signal | Parcours dans l'interface qui consomme l'API |
| Site statique ou généré | Contenus sources et configuration du générateur | Fichiers construits ou serveur de prévisualisation | Navigateur sans fenêtre | Parcours réel |
| Application de bureau | Fichiers de données et de configuration de l'application | Réponses du processus principal ou du service local | Rendu isolé (banc, faux hôte) | Parcours réel par le port de débogage en mode test |
