# Modes d'accès

Un seul détecteur, `scripts/detect.php`, exécutable dans les quatre modes. Il est toujours en lecture seule et ne charge jamais WordPress.

## A. SSH (idéal)

```
php detect.php --root=/chemin/docroot \
  --url=https://site.example \
  --access-log=/chemin/logs/access.log \
  --scope=/chemin/abonnement \
  --json=/hors/docroot/rapport.json
```

Ajouter `--legit-admins=a,b`, `--since=AAAA-MM-JJ` quand ils sont connus, `--watch=60` après nettoyage, `--inventory-out=/hors/docroot/inv.json` pour la surveillance longue.

## B'. Tâche planifiée du panneau, en PHP CLI (préféré sans SSH)

Le cas d'école a été traité sans SSH. Quand le panneau permet une tâche planifiée en **PHP CLI**, c'est le meilleur mode sans SSH : pas de limite de temps HTTP, pas d'auto-exposition.

1. Déposer `detect.php` hors du docroot si possible, sinon dedans (temporairement).
2. Planifier une commande unique :
   `php /chemin/detect.php --root=/chemin/docroot --json=/hors/docroot/rapport-$(date +%s).json`
3. Récupérer le JSON par FTP, supprimer le script.

## B. Outil web déposé (ni SSH ni tâche CLI)

En dernier recours. `detect.php` seul répond 404 par le web ; on génère un outil jetable :

1. `php scripts/build-drop.php --out=/hors/depot/nom.php [--hours=4] [--token-out=FICHIER]`. Le fichier assemble le détecteur, `drop/actions.php` et `drop/app.php` ; il ne contient que l'empreinte sha256 du jeton et une expiration codée. Quand un agent lance le build, `--token-out` : le jeton ne passe jamais par le chat.
2. Déposer par FTP à la racine du projet. Le nom doit passer un éventuel verrou `.htaccess` par liste blanche : sinon le retirer d'abord (chirurgical).
3. Ouvrir l'adresse, saisir le jeton (POST). Session propre (cookie HttpOnly, SameSite=Strict, Secure en HTTPS, 30 min d'inactivité), CSRF sur chaque POST. Expiré ou 10 jetons faux : le fichier se supprime.
4. Analyse par étapes (reprise automatique sous `max_execution_time`), en lecture seule. Le fichier et son dossier de travail sont exclus (`--exclude`, annoncé dans le rapport).
5. Actions proposées **seulement si un constat les justifie** : quarantaine, remplacement d'un fichier du cœur par l'archive officielle vérifiée contre le manifeste, retrait de lignes précises d'un `.htaccess`/`.user.ini`, suppression de lignes de base (options vitales jamais proposées), neutralisation de comptes, révocation de mots de passe d'application, déconnexion générale, invalidation des mots de passe (au moins un compte conservé), régénération des clés. Rien n'est coché par défaut ; le navigateur ne transmet que des clés du plan serveur. Refus si la cible a changé depuis l'analyse (signe de réécriture).
6. Chaque action : sauvegarde, journal, annulation (refusée si la cible a encore changé depuis).
7. Dossier de travail (état, sauvegardes, quarantaine, journal) hors de la racine servie quand le parent est inscriptible, sinon dans la racine sous un nom imprévisible fermé par `.htaccess` (sous nginx, seul le nom imprévisible protège). Le récupérer par FTP, puis « Terminer et supprimer l'outil ».

Limites HTTP fréquentes : `open_basedir`, `shell_exec` coupé, `/proc` masqué → les contrôles concernés passent `ILLISIBLE`/`NEEDS_HUMAN`, jamais « ok ».

## C. Hors ligne (dump + fichiers copiés)

```
php detect.php --root=/copie/docroot --sql=/copie/dump.sql --offline
```

- Copier fichiers **et** dump du serveur. Les contrôles système (processus, cron, `/tmp`, dates ctime) sont alors `NEEDS_HUMAN` : ils exigent le serveur.
- Base seule : `php detect.php --sql=dump.sql` (préfixe déduit du dump, ou `--prefix=`).
- Dump volumineux : le mode C peut être délégué à un agent d'analyse en lecture seule, à contexte isolé (optionnel).
- Antivirus local : le dossier de preuves peut déclencher une mise en quarantaine. **Ne jamais créer d'exclusion soi-même** : le signaler à l'utilisateur, qui décide.

## Périmètre = abonnement / utilisateur système

`--scope=RACINE` trouve chaque installation WordPress sous la racine et les analyse toutes (recettes, copies, `*.old`). Un ancien docroot partage l'utilisateur système, donc l'exposition. Prouvé par le cas d'école : la recette était infectée, la prod saine.

## Références de confiance

- `--ref=/copie/saine` : diff de tout ce qui n'a pas de manifeste officiel contre une copie de confiance.
- `--ref=wp-content/plugins/xxx=/copie/version-editeur` : une extension commerciale contre l'archive de la **même version** du compte éditeur.
- `--baseline=inventaire.json` : un état antérieur sain (produit plus tôt par `--inventory-out`).
- `--no-network` : aucun appel aux API officielles ; sans référence, les composants passent `NEEDS_HUMAN` (jamais « ok »).
