# Gabarit de brief d'ouvrier de zone

À remplir et passer à un agent `cliff-stack:ouvrier-zone`, un par zone. Les deux premières lignes sont lues par le garde de budget ; garde-les en tête.

```
plan: <chemin absolu du plan, ou du fichier de ce brief quand le brief est le plan>
zone: <identifiant de la zone>

But : <ce que la zone doit livrer, une phrase>
Fichiers permis : <globs que l'agent peut écrire ; rien d'autre>
Lecture seule : <globs qu'il lit pour comprendre, sans écrire>
Contrats à respecter : <contrats figés par le principal, ou « aucun »>
Briques à réutiliser : <fichier:symbole que la zone appelle au lieu de le refaire, ou « aucune »>
Briques à créer : <fichier:symbole que la zone crée, seules permises, ou « aucune »>
Branche : <branche ou arbre où commiter, et « reprendre <tronc> » s'il doit le reprendre ; « aucune » : l'ouvrier ne commite pas>
Vérification : <commandes à lancer après chaque écriture ; au minimum la syntaxe>
Critère de réussite : <le signal sémantique direct qui prouve que c'est fait>
Budget de temps : <minutes estimées>
```

Rappels à inclure : n'écris que dans tes fichiers permis ; besoin d'un autre fichier → arrête-toi et rends le signal `hors_zone_requis`, jamais « juste cette ligne ». Git : commit de tes seuls fichiers sur la branche du brief, reprise du tronc s'il le dit, rien d'autre. Jamais d'agent ni de workflow. Finis par une ligne `SIGNAL:` suivie du JSON du contrat de sortie de l'ouvrier.
