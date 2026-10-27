---
paths:
  - "**/*.php"
  - "**/*.js"
  - "**/*.jsx"
  - "**/*.ts"
  - "**/*.tsx"
  - "**/*.py"
  - "**/*.astro"
  - "**/*.vue"
  - "**/*.scss"
  - "**/*.css"
---

# Invariants d'écriture du code

- **Règle des 2 occurrences (opérationnelle, vérifiable)** : à la **2e fois** qu'un même motif (markup, suite d'attributs, calcul, requête, séquence d'appels) s'apprête à être écrit, NE PAS copier-coller — extraire un helper/partial/constante paramétrable et router les 2 usages dessus, AVANT de continuer. Une valeur identique répétée 2× = une constante ; un bloc identique 2× = une fonction. Si un invariant doit être respecté à chaque écriture (sentinelle, nonce, échappement), il vit dans CE helper unique pour être **impossible à oublier** au prochain usage. Après extraction, prouver l'iso-comportement (sortie identique) avant de déclarer fait. Vaut aussi en corrigeant : une occurrence identique trouvée par le grep compte comme une occurrence écrite ; hors du périmètre que l'utilisateur a nommé, l'extraction se propose avec sa liste (porte 6) au lieu de s'écrire.
- **Commentaires : rares, courts, jamais un journal**. Un commentaire ne se justifie que s'il dit ce que le code ne peut pas dire seul (un piège, une contrainte externe, un choix non évident) — sinon il ne s'écrit pas. Interdits : le suivi de mes modifications (« remplace l'ancien X », « avant c'était Y », « mesuré le JJ/MM », « doublonnait »), la paraphrase de la ligne suivante, les pavés d'explication. L'historique va dans git, la démonstration va dans ma réponse, pas dans le fichier. Viser 1 à 3 lignes maximum, au présent, sur le code tel qu'il est.
- **Toute ressource créée a son destroy écrit au même moment** : interval/timeout/listener/observer → clear/remove/disconnect ; cache → invalidation.
- **Build assets** : après avoir manipulé du CSS/JS **source** dans un projet doté d'un dossier de build, recompiler par la commande de ce dossier — sinon les modifs source restent inactives.
