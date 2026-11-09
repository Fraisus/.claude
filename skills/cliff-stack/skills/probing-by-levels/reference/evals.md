# Évaluations

Écrites avant le skill (méthode skill-builder). Chaque scénario se pose à un agent neuf, avec et sans le skill, sur une
question seule : jamais l'échelle ni la piste. On juge l'ordre des sondes, le critère d'arrêt et ce qui est dit d'un
échelon non atteint.

```json
[
  {
    "skills": ["probing-by-levels"],
    "query": "Sur ce site WordPress, il y a un écart entre les deux boutons de l'en-tête, mais le markup du bloc Boutons n'a aucun réglage d'espacement. D'où vient cet écart ?",
    "expected_behavior": [
      "Première sonde à l'échelon enregistré : configuration et valeur fusionnée (theme.json, Styles globaux) par la lecture outillée du projet",
      "S'arrête là si rien d'autre ne peut produire l'écart ; ne monte à l'échelon servi qu'en nommant une règle concurrente possible (marge posée par une feuille du thème)",
      "N'ouvre le navigateur que si plusieurs règles servies restent candidates, puisque seul le calculé dit laquelle gagne"
    ]
  },
  {
    "skills": ["probing-by-levels"],
    "query": "J'ai changé la valeur du preset d'espacement g-1 dans les Styles globaux, mais l'écart entre les boutons ne bouge pas au front. Pourquoi ?",
    "expected_behavior": [
      "Monte échelon par échelon en nommant à chaque fois ce que l'échelon courant ne peut pas voir : enregistré (la valeur est-elle en base), servi (la feuille livrée contient-elle la nouvelle valeur, cache compris), calculé (une autre règle l'écrase-t-elle)",
      "S'arrête au premier échelon où l'écart entre attendu et observé apparaît",
      "N'utilise le navigateur que si la règle est servie et que la question devient celle de la cascade"
    ]
  },
  {
    "skills": ["probing-by-levels"],
    "query": "Sur une application avec une API JSON et une interface web, l'API renvoie-t-elle bien le champ discount pour un client professionnel ?",
    "expected_behavior": [
      "Sonde à l'échelon servi : requête sur la route ou test existant de la route, avec des données d'essai conformes au contrat",
      "N'ouvre pas l'interface : l'interface ne voit pas mieux la réponse que la route",
      "Dit ce qui reste non prouvé (affichage du champ) sans aller le chercher, puisque non demandé"
    ]
  },
  {
    "skills": ["probing-by-levels"],
    "query": "J'ai corrigé l'ordre de tri dans le composant de liste des programmes. Prouve que c'est bon.",
    "expected_behavior": [
      "Reconnaît une preuve de changement : suit les niveaux 0 à 4 de la porte (8) sans les redéfinir",
      "Commence par le statique et les tests existants des fichiers touchés, ne réserve le parcours réel qu'à un gros lot fini",
      "Rend la preuve avec la commande et son résultat, et dit le niveau sans outil s'il en saute un"
    ]
  }
]
```

Cas à ne pas déclencher : écrire une fonction, concevoir une interface, une question de prix ou de stratégie, un audit
d'accessibilité.

## Dernière passe (09/11/2026, Opus, session neuve dans un projet WordPress)

- Déclenchement : le skill se charge seul sur les quatre questions, sans être nommé.
- Sans le skill : navigateur et outils de débogage prévus d'office pour Q1 ; onze étapes pour Q4, parcours réel et
  critique compris.
- Avec le skill : Q2 et Q3 conformes ; Q4 s'arrête au niveau 3 quand le tri est fait côté serveur et dit le niveau 4
  sans outil ; Q1 monte à l'échelon servi en nommant une marge de thème concurrente, attente reformulée en conséquence.
