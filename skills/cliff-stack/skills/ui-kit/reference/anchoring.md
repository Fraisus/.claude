# Phase 6 : table d'ancrage vers l'architecture du projet

Le kit n'est utile que s'il dit, pour chaque jeton et chaque composant, ce qui le portera dans le projet. La table se remplit depuis le rapport de la zone du thème ou code local et la lecture du code, jamais depuis la mémoire d'un autre projet. Rien ne se code dans le dépôt sans demande : la table documente.

## Sommaire

- Source de vérité du rendu
- Table des jetons
- Table des composants
- Points d'extension
- Contrôle de la table

## Source de vérité du rendu

Écrire d'abord, preuve à l'appui, où vit la valeur réellement servie : fichier de configuration, base de données (réglages globaux enregistrés), feuille compilée. Un fichier de configuration qui ne sert que de valeur de départ ne doit pas recevoir les jetons si la base fait foi. Une seule direction s'écrit dans la valeur servie ; les autres restent documentées, avec le mécanisme natif qui pourrait les porter, à vérifier dans la documentation à jour.

## Table des jetons

| Jeton du kit | Mécanisme natif | Remarque |
|---|---|---|
| `<rôle ou groupe de rôles>` | `<slug, variable, préréglage, réglage>` | `<ce qui suit seul, ce qui casse, piège mesuré>` |

Une ligne par groupe homogène : fonds et textes de base, accent, surfaces inversées, statuts, styles de type, polices, espacements, rayons, ombres, durées et courbes, conteneurs, états d'écran. Chaque remarque dit au moins une de ces choses : ce qui suit automatiquement (une couche contextuelle qui inverse le texte), ce qu'il ne faut pas faire (renommer, doubler une échelle), le piège mesuré (préchargement de toutes les faces d'une origine, contraste automatique sur un aplat de marque).

## Table des composants

| Composant du kit | Brique existante ou source de données | Remarque |
|---|---|---|
| `<Comp>` | `<bloc, gabarit, part, fonction de données, brique archivée>` | `<à construire, à réveiller, décision à prendre>` |

- Un composant sans brique existante le dit (« à construire sur `<base native>` ») ; une brique dormante ou archivée se nomme comme piste.
- Données : la fonction qui les fournit et ses cas limites (valeur absente, état), pas seulement le nom du champ.
- Un motif interactif nomme le motif APG et l'élément natif retenu.

## Points d'extension

La liste des filtres, hooks, registres et réglages par lesquels le kit entrera, chacun avec le fichier où il est déclaré. Une variante nouvelle d'un composant passe par un style ou une composition déclarés à ces points, jamais par une copie du composant.

## Contrôle de la table

- Chaque rôle de `tokens.json` et chaque composant de l'inventaire a sa ligne : compter les deux listes contre la table.
- Chaque mécanisme cité existe : une recherche dans le dépôt le retrouve (commande et résultat gardés). Un mécanisme supposé se marque « à vérifier » avec la source à consulter.
- Les décisions laissées à l'utilisateur sont listées à part, avec leur coût, pas enfouies dans une remarque.
