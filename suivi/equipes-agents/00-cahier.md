# Chantier : équipes d'agents automatiques et évolutives dans ~/.claude (global)

But de l'utilisateur : que Claude Code compose de lui-même une équipe adaptée au sujet demandé,
dimensionnée au besoin, et capable de changer de palier en cours de route (une équipe de palier 1,
sous-agents, qui découvre pendant le code qu'il lui faut le palier 2, équipe coordonnée, et inversement).

Paliers (vocabulaire commun) :
- P0 : une session seule + skills.
- P1 : sous-agents (contexte isolé, rapport au parent, worktree possible).
- P1b : workflow dynamique ou /batch (script qui tient le plan, N sous-agents vérifiés entre eux).
- P2 : agent teams (coéquipiers, liste de tâches partagée, messagerie), expérimental.

Trois moments déjà identifiés : cadrer (produire un plan d'équipe : zones, fichiers, vérification, rôles,
budget), activer (lancer bêtement le plan validé), intégrer (vérificateur à contexte vierge, fusion, retro).
Question ouverte centrale : l'escalade en cours de route (qui la détecte, sur quels signaux mesurables,
qui décide, comment l'état survit au changement de palier, comment on redescend).

Contraintes de la maison (CLAUDE.md global, déjà chargé) : français, rien en dur, preuve avant affirmation,
hooks comme barrière réelle, Fable se dégrade sous un préambule trop prescriptif, plafond ~10 agents par run,
Windows 11 (agent teams : mode in-process seulement).

Chaque rapport : faits sourcés (chemin de fichier ou URL), puis une courte section « implications pour le but »,
sans concevoir la solution complète (c'est le rôle du concepteur). Français. Dense.
