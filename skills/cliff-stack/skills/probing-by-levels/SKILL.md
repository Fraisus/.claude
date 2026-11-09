---
name: probing-by-levels
description: "Chooses the cheapest probe that can see the signal a question is about, and stops at the first rung that answers it: stored (data, configuration, merged settings), served (what a route, page or API actually returns), computed (runtime state, computed style, layout in a browser), real gesture (editor or journey driven by real input). Climbs a rung only after naming what the current rung cannot see. Also covers probe craft (calibrating on a known-positive and a known-negative case, checking what emits a signal, waiting for the real end of an action, the perimeter of an absence) and how a project declares its tools per rung. Use when diagnosing or checking something: « d'où vient », « pourquoi ça ne change pas », « est-ce appliqué / servi / enregistré », « vérifie que », « comment le prouver », « quelle sonde », « faut-il ouvrir le navigateur », or before writing a probe script. NOT for proving a finished change end to end (niveaux 0 à 4 de la porte (8), testing-real-ux), refuting a result (independent-critic) or tracing one block's render step by step (wp-block-pipeline-tracer)."
---

# Sonder au bon échelon

But : répondre à la question posée avec la sonde la moins chère qui voit vraiment le signal, puis s'arrêter. Une sonde plus lourde ne prouve pas davantage ; elle prouve autre chose, et elle coûte des allers-retours.

## Les quatre échelons

On les appelle échelons pour ne pas les confondre avec les niveaux 0 à 4 de la porte (8), qui servent à prouver un changement.

| Échelon | Ce qu'il voit | Ce qu'il ne voit pas |
|---|---|---|
| **Enregistré** | Données, configuration, réglages fusionnés, contenu en base, fichiers | Ce qui sort réellement (cache, filtre, rendu) |
| **Servi** | La réponse réelle d'une route, d'une page, d'une API : corps, en-têtes, feuilles livrées | Ce que le client en fait (cascade CSS, script, mise en page) |
| **Calculé** | L'état d'exécution : style calculé, DOM après scripts, dimensions, valeur en mémoire | L'effet d'un vrai geste (événements, gardes de temps, enregistrement déclenché) |
| **Geste réel** | Ce qui arrive sous de vrais clics, saisies, enregistrements | Rien de plus bas : c'est le plus lent, il se réserve à ce que seul un geste déclenche |

## La règle

1. **Écrire la question en une phrase**, et le signal qui y répond. « D'où vient l'écart » appelle une origine ; « l'écart est-il appliqué » appelle une valeur calculée. Ce ne sont pas les mêmes échelons.
2. **Commencer à l'échelon le plus bas qui voit ce signal.** Une origine, une valeur enregistrée, un réglage fusionné : enregistré. Une réponse de route : servi. Une valeur que la cascade ou un script peut changer : calculé. Un effet qui ne naît que d'un geste (enregistrement depuis un éditeur, garde de temps, événement de confiance) : geste réel.
3. **S'arrêter dès que la question posée a sa réponse.** Une question voisine que l'on se pose en chemin se signale à l'utilisateur, elle ne justifie pas de monter.
4. **Monter seulement en nommant ce que l'échelon courant ne voit pas.** « La valeur fusionnée est bonne, mais une règle du thème peut l'écraser au navigateur » justifie l'échelon calculé. « Pour confirmer » ne justifie rien.
5. **Un écart entre deux échelons localise la cause.** Enregistré bon et servi faux : livraison (cache, filtre, environnement d'exécution différent). Servi bon et calculé faux : client (cascade, script).
6. **Rendre la réponse avec sa sonde** : la commande et son résultat, l'échelon atteint, et ce qui reste non vérifié au-dessus quand cela compte.

Prouver un changement terminé suit les niveaux 0 à 4 de la porte (8), sans les redéfinir. Ce skill sert alors à choisir, dans chaque niveau, la sonde la plus directe.

## Métier de la sonde

- **Étalonner avant de conclure** : la sonde doit dire oui sur un cas qu'on sait positif et non sur un cas qu'on sait négatif. Une recherche textuelle compte des mentions (commentaires, chaînes, documentation), pas des exécutions.
- **Vérifier ce qui émet le signal** : le nom d'un événement, d'un champ ou d'une fonction décrit rarement son déclencheur exact. Lire l'émetteur avant de s'y fier.
- **Attendre la fin réelle d'une action**, jamais un délai fixe : s'accrocher au signal de fin (requête revenue, état retiré) et échantillonner.
- **Une absence se rend avec son périmètre** : tout l'endroit où la chose pourrait vivre, et la commande qui l'établit. Un silence a deux causes possibles, la chose absente et la sonde aveugle ; vérifier la seconde d'abord.
- **Une donnée d'essai conforme au contrat**, étalonnée sur un cas connu avant d'en tirer un défaut.
- **Une sonde qui ne trouve pas sa cible est d'abord une sonde fausse** : vérifier ce qu'elle cherche (libellé transformé à l'affichage, attribut ajouté au rendu) avant de conclure à l'absence.

## Déclaration d'un projet

Chaque projet dit quel outil sert à quel échelon, dans son `CLAUDE.md` ou ses règles. S'il ne le dit pas, découvrir ses outils (lanceurs, CLI, tests, adaptateur `.claude/ux-e2e.json`), proposer la déclaration à l'utilisateur et l'écrire sur accord. Format et correspondances par type de projet : [reference/declaration.md](reference/declaration.md).

## Pièges vérifiés

- **Lecture outillée contre navigateur** : la valeur fusionnée par un framework (réglages globaux, configuration résolue) dit ce qui est généré, pas ce qui s'applique. Si la question porte sur l'origine, elle suffit ; si elle porte sur l'application, monter au calculé.
- **Une sonde qui passe par un lanceur peut altérer son entrée** : un shell Windows PowerShell 5.1 ajoute une marque d'octets (BOM) devant ce qu'il passe à un programme, un lanceur `.bat` peut manger les guillemets ou tronquer un argument multi-ligne. Passer le contenu par un fichier, et vérifier que l'entrée arrive intacte.
- **Un vérificateur automatique peut ne reconnaître que certaines commandes** comme preuve : ce n'est pas une raison de monter d'échelon pour lui plaire. Dire la preuve obtenue, et corriger le vérificateur s'il refuse une sonde légitime.

## Évaluations

Scénarios et comportements attendus : [reference/evals.md](reference/evals.md).
