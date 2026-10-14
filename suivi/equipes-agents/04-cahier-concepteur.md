# Cahier des charges du concepteur

Entrées : 00-cahier.md, 01-harness.md, 02-existant.md, 03-communaute.md (même dossier). Ne rien re-rechercher
qui y figure ; si un fait manque et qu'il est décisif, le dire comme hypothèse à vérifier.

Livrable : 05-conception.md, une proposition complète et actionnable pour ~/.claude (global), structurée ainsi :

1. Le protocole d'escalade, en une page : paliers P0, P1, P1b, P2 ; pour chaque passage (montée et descente),
   le signal mesurable qui le déclenche (jamais « si ça semble complexe »), qui le détecte (travailleur,
   orchestrateur, hook), qui le décide (orchestrateur seul, ou porte utilisateur, et quand), et ce qui est
   transmis (l'artefact plan d'équipe). Dire explicitement ce que le harness interdit (un sous-agent ne
   devient pas coéquipier, etc.) et comment on contourne honnêtement (remontée au thread principal).
2. L'artefact « plan d'équipe » : format exact (champs, exemple rempli sur un cas Réactorm réaliste),
   où il vit (chemin par projet, non versionné ou versionné, à justifier), qui l'écrit, qui le relit,
   comment il est mis à jour à chaque changement de palier (journal des escalades inclus).
3. Les pièces à créer ou modifier, une par une, avec leur emplacement selon les conventions du dépôt
   ~/.claude (plugin cliff-stack : agents, skills, hooks ; CLAUDE.md ; settings.json ; workflows) :
   contenu complet quand c'est court (frontmatter d'agent, section CLAUDE.md, entrée hooks.json),
   pseudo-code précis quand c'est un script (entrées lues, sortie, code de retour). Marquer pour
   chaque pièce : nouvelle / modifiée / existante réutilisée telle quelle, et le palier qu'elle sert.
   Zéro doublon avec l'existant inventorié dans 02-existant.md.
4. La composition automatique : la procédure par laquelle le skill de cadrage déduit les zones et
   les rôles du sujet (preuves : grep, git log, imports, frontières du projet, rules paths), le contrôle
   de recouvrement, la règle « pas d'équipe » par défaut, et la porte de validation utilisateur
   (quand elle est obligatoire, quand elle peut être sautée).
5. Garde-fous : budgets (agents, itérations, temps), plafonds cohérents entre fable-mode, workflows et
   CLAUDE.md (une seule source), arrêt après N itérations stériles, désescalade, ce qui remonte à
   l'utilisateur.
6. Plan de mise en place par palier (ordre, ce qui se mesure avant de passer au suivant), et ce qu'il
   faut mesurer sur deux semaines (journal JSONL comme critics.jsonl ?).
7. Risques et ce que la proposition ne fait volontairement pas.

Contraintes : français ; Fable se dégrade sous un préambule prescriptif, donc CLAUDE.md reste court et le
détail va dans un skill chargé à la demande ; rien en dur (chemins, noms de projet, commandes de test
découverts) ; les hooks sont la barrière réelle ; Windows 11 ; respecter les trois moments (cadrer,
activer, intégrer) ; aucune écriture dans la config, seulement le document.
