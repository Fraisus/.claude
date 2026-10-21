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
---

# Point d'extension : invariants de mise en œuvre

Complète la règle « séparer la feature de ses consommateurs » (CLAUDE.md). Le pattern est nommé : Dependency Inversion + Open/Closed → *plugin architecture* (Martin).

- **Nom namespacé.** Un point d'extension est une API publique dès qu'un tiers s'y branche : préfixe unique, sinon collision et bugs introuvables.
- **Lu le plus tard possible, à l'usage.** Un consommateur qui se déclare après la lecture est ignoré en silence. L'ordre d'initialisation n'est jamais symétrique : celui qui démarre en dernier peut se brancher sur l'autre, pas l'inverse.
- **Appliqué après tout cache.** Un registre mis en cache avant l'appel du point d'extension fige les déclarations faites en code jusqu'à expiration.
- **Renommer ou déprécier : la portée des appelants décide, pas l'ancienneté.** « Puis-je mettre à jour TOUS les appelants dans le même déploiement ? » Oui (pas encore livré, appelants du même dépôt) → renommer franchement, router les appelants dans le même commit, aucun alias. Non (dépôt déployé séparément, code déjà livré, code tiers) → déprécier avec période de grâce.
- **Deux entrées quand elles ont du sens** : déclarer une source à scanner, et inscrire une entrée à la main.
- **Composition déclarée par le point, jamais par l'ordre de chargement.** Chaque point dit s'il prend une liste (défaut), une offre unique (un conflit est signalé, jamais tranché par l'ordre) ou un ordre choisi par l'utilisateur ; « la première offre gagne » est interdit.
- **Crochets nommés, pas de bus libre.** Un crochet se crée pour un besoin réel, porte un nom et une forme de données, est en lecture seule, collecté par l'hôte, et un abonné lent ou en panne ne bloque jamais les autres ; un point sans deuxième cas réel ne s'ajoute pas, un point sans producteur se sert ou se retire.
- **Contrat en données, jamais une classe à importer.** Un point ouvert à des tiers s'écrit en données ou en fonctions aux formes sérialisables ; une extension qui sert le point d'une autre le déclare comme besoin facultatif, par le nom du point, jamais par le nom de l'extension ; qui possède un point (l'hôte ou une extension) le sert par le même chemin qu'un tiers, jamais par un raccourci privé.

## Refondre vers des points d'extension : ordre imposé

Chaque étape se livre et se mesure avant la suivante.

1. **Inventaire par preuve.** Chaque import interne fait par une extension, y compris dynamique et dans les scripts qu'elle lance hors de l'hôte, et chaque mention d'une extension dans l'hôte (nom, constante, texte, icône, route, champ), relevés dans le code avec fichier et ligne.
2. **Garde exécutable avant de réduire.** Une liste des modules publics de l'hôte, tout autre import refusé à une extension, aucun import entre extensions, par analyse statique de l'arbre syntaxique (sur le modèle des contrats « forbidden » et « independence » d'import-linter), la dette mesurée gelée et ne pouvant que baisser ; une recherche de termes ne vaut pas garde.
3. **Surface publique étroite.** Une extension ne reçoit que l'objet du port donné à son branchement, plus des modules entièrement sans état ni effet (la garde ne voit que des modules, jamais des fonctions choisies dans un module à état) ; tout ce qui a un état ou un effet (disque, réseau, processus, secrets, réglages, cache) passe par le port, sous une capacité déclarée. Un usage interne sans équivalent dans le port reçoit une offre neutre ou reste un écart déclaré et gelé, jamais une équivalence supposée.
4. **Remonter ce que l'hôte nomme.** Toute mention d'une extension dans l'hôte devient une déclaration de l'extension par un mécanisme neutre (libellé, icône, drapeau de comportement, offre nommée). Test d'altitude : le point garde-t-il un sens pour une extension sans aucun rapport avec celle qui l'a fait naître ?
5. **Preuve de séparation.** L'hôte démarre et passe ses vérifications sans aucune extension.
6. **Contrat publiable.** L'extension déclare la version minimale de l'hôte et les points dont elle a besoin, refus explicite sinon. Deux axes distincts : la provenance (signée par une clé d'éditeur connue, ou non) et la charte (données seules, vérifiée par la construction du paquet, jamais par une analyse de son code ; ou code dans le processus de l'hôte, avec tous ses droits) ; l'écran d'approbation dit ces droits, que l'hôte n'en répond pas, et que signature et empreinte garantissent l'intégrité et le consentement, jamais la sécurité.
7. **Ouvrir de nouveaux points en dernier**, sur un besoin réel et après le contrat de version : tout ce qu'une extension fournit à un modèle de langage (outil, texte, résultat) est traité par l'hôte comme contenu étranger, sa description comptée dans l'empreinte approuvée.
