---
name: wp-incident-response
description: Use when a WordPress site is hacked, compromised, infected or defaced ("site WordPress piraté/hacké/infecté", "malware WordPress", "site nettoyé qui se réinfecte"), shows SEO spam or redirects to spam for Google visitors ("redirige vers du spam", "spam SEO", "cloaking"), has unknown or fake admin accounts ("des admins inconnus", "80 comptes administrateurs"), backdoors/webshells, a host or scanner flag, or needs a post-breach desinfection without SSH (panel + FTP, dropped PHP script) or offline (SQL dump + files), AND when WordPress or plugin updates are stuck/blocked/impossible ("les mises à jour ne se font plus", "auto-update désactivé"). Read-only investigation first, then guided containment, cleanup, secret rotation, hardening and verification. NOT for building a WordPress feature (wp-native), nor the sellable-plugin compliance gate (wp-plugin-check), nor a security audit of code on a site that is not compromised (wp-security-audit).
license: Skill maison. Le détecteur réutilise la méthode en phases de wp-malware-cleanup (panstemon, MIT) réécrite en invariants génériques.
---

# wp-incident-response — reprendre un WordPress compromis (sans SSH si besoin)

Un WordPress piraté, c'est deux problèmes sous un même manteau. Les **fichiers** sont la moitié visible. La **base** est la moitié qui survit à une réinstallation, et celle que la plupart des nettoyages ratent. Cette skill mène l'intervention de bout en bout : constater, contenir, détecter, trouver le vecteur, fermer et tuer, nettoyer la base, faire tourner les secrets, durcir, vérifier. Le cœur technique est **un seul détecteur en lecture seule**, `scripts/detect.php`.

## Principe directeur : expliquer ou signaler

On **inverse la charge de la preuve**. Ce n'est pas au code d'être reconnu malveillant, c'est à lui d'être reconnu **légitime** par une référence de confiance. Tout fichier, valeur de base, compte, tâche planifiée, processus ou règle serveur qu'aucune référence n'explique devient une **PISTE**. Une signature connue (encodage, nom de variable en O/0/_) sert à **prioriser**, jamais de condition nécessaire : sinon on ne voit que les variantes déjà cataloguées et l'on rate le piratage de demain. La détection repose sur cinq leviers, dans cet ordre de force :

1. **Intégrité par provenance.** Fichier qui diffère de son manifeste officiel ; fichier exécutable **orphelin** (absent de toute référence) ; code exécutable là où l'architecture WordPress n'en attend pas (uploads, dossiers de données, racine hors fichiers du cœur, /tmp).
2. **Capacité, pas signature.** Ce que le code **peut faire** une fois tout décodage défait : une entrée non fiable (requête, réseau, valeur de base) qui atteint un **puits** (exécution de code ou de commande, écriture de fichier, requête sortante, création de compte, écriture d'option sensible), quel que soit l'encodage.
3. **Comportement observable.** Réponse qui change selon le User-Agent ou le référent (cloaking) ; fichier réécrit après nettoyage ; processus sans fichier sur disque ; compte ou option créé dans une fenêtre anormale.
4. **Corrélation temporelle et statistique.** Grappes d'événements de natures différentes (compte + code + accès) dans une fenêtre serrée ; anomalies de plage d'ID ; dates antidatées ; table qui gonfle d'un coup.
5. **Heuristiques connues, en dernier.** Entropie, chaînes longues, noms obfusqués : pour trier, pas pour conclure.

**Statuts** (jamais « ok » par défaut) : `CONFIRMÉ`, `PISTE`, `DURCISSEMENT`, `NEEDS_HUMAN`, `ILLISIBLE`. Une vérification impossible (open_basedir, shell_exec coupé, /proc masqué, API injoignable) devient un constat `ILLISIBLE`/`NEEDS_HUMAN`, jamais un silence. **API officielle injoignable n'est PAS « pas de correctif ».**

## Garde-fous (les hooks locaux ne protègent pas une prod distante)

- **Lecture seule par défaut.** Le détecteur ne modifie rien, ne charge jamais WordPress ni aucun fichier du site (wp-config lu par `token_get_all`, base en SQL direct ou dump). Toute action destructive (suppression, kill, retrait de règle) est décidée par l'humain, **une par une, confirmée**, jamais par le script en mode diagnostic.
- **Ordre non négociable :** copie de preuve hachée hors serveur → contenir → **fermer le vecteur + tuer les processus de persistance AVANT de nettoyer la base** (sinon le nettoyage est réécrit).
- **`.htaccess` : jamais de réécriture complète.** Retrait chirurgical des blocs identifiés, sauvegarde avant. Un verrou de type liste blanche bloque wp-admin, les mises à jour et tout nettoyage par script : c'est souvent la première chose à défaire.
- **Aucun secret affiché** dans le chat ou le rapport (mots de passe, sels, hash, clés) : référence par chemin, valeur masquée.
- **Dates de fichiers = preuves non fiables** (un antidatage est courant) : jamais un filtre d'inclusion, seulement une donnée.
- **Antivirus hébergeur :** lire son rapport, relancer après nettoyage. Toute **exclusion antivirus est une action manuelle de l'utilisateur**, signalée comme un affaiblissement, jamais faite par la skill.

## Modes d'accès (un seul détecteur, `scripts/detect.php`)

Le cas d'école a été traité **sans SSH**, par script PHP déposé. Choisir selon l'accès (détail : [references/access-modes.md](references/access-modes.md)) :

- **A. SSH** (idéal) : `php detect.php --root=DOCROOT --url=https://site --access-log=…`.
- **B'. Tâche planifiée du panneau en PHP CLI** (préféré sans SSH) : planifier `php detect.php --root=… --json=/hors/docroot/rapport.json`, récupérer le JSON par FTP. Pas de limite de temps HTTP, pas d'auto-exposition.
- **B. Outil web déposé** (si ni SSH ni tâche CLI) : `php scripts/build-drop.php --out=/hors/depot/nom.php --token-out=FICHIER` génère UN fichier (détecteur + actions + interface) à poser par FTP à la racine. Accès par jeton (seule son empreinte est dans le fichier ; `--token-out` quand un agent lance le build, pour qu'aucun secret ne passe par le chat), expiration codée, autodestruction à l'expiration ou après 10 jetons faux. Analyse par étapes, puis **seulement les actions que les constats justifient**, chacune avec aperçu, validation élément par élément, sauvegarde, journal et annulation ; bouton final « Terminer et supprimer l'outil ». `detect.php` seul répond 404 par le web.
- **C. Hors ligne** : copier fichiers + dump, `php detect.php --root=COPIE --sql=dump.sql --offline`. Les contrôles système (processus, cron, /tmp) sont alors `NEEDS_HUMAN` (ils se font sur le serveur).

Périmètre = **l'abonnement entier / l'utilisateur système** : `--scope=RACINE_ABONNEMENT` analyse chaque installation trouvée (recettes, `*.old`, bases). Prouvé par le cas d'école : la recette était infectée, la prod saine. Un ancien docroot partage l'utilisateur système, donc l'exposition.

## Phases de l'intervention

Détail complet et check-lists : [references/phases.md](references/phases.md). En bref :

1. **Intake.** Domaines, accès, panneau, autorisation (audit seul vs remédiation), qui fournit les ZIP des extensions commerciales, fenêtre de maintenance. Confirmer qu'on est bien sur la machine qui sert le domaine.
2. **Preuves.** Sauvegarde complète fichiers + base **hachée et sortie du serveur** avant toute action, étiquetée « infectée, ne pas restaurer ». Copier les journaux d'accès avant rotation.
3. **Contenir.** Maintenance au niveau serveur (pas WordPress, qui exécute encore les portes dérobées) ; bloquer l'exécution PHP dans les dossiers inscriptibles ; geler les autres sites de l'abonnement.
4. **Détecter.** Lancer `detect.php`. Lire les constats, pas les compteurs.
5. **Vecteur.** Croiser la fenêtre d'incident avec les **journaux d'accès** (`--access-log`) : POST vers des scripts hors cœur, premier accès aux fichiers signalés. La fenêtre part de la **preuve la plus ancienne** (canary /tmp, premier compte), pas de la date d'une faille publique.
6. **Fermer et tuer.** Retirer le vecteur, tuer les processus de persistance (voir signaux forts ci-dessous) AVANT le nettoyage base.
7. **Nettoyer la base.** Simulation puis validation humaine ligne à ligne. Reconstruire plutôt que nettoyer les fichiers (réinstaller ce qui a une référence, relire le reste).
8. **Rotation de TOUS les secrets** exposés : comptes WordPress, sels (`wp config shuffle-salts`), mot de passe base, FTP/SSH, panneau, clés d'API et mots de passe d'application, secrets des agents de gestion distante. Un mot de passe d'application survit au changement de mot de passe.
9. **Durcir.** `references/hardening.md` (à croiser avec `../../references/wp-security-controls.md`, partagée avec wp-native et wp-plugin-check).
10. **Vérifier.** Surveillance de réécriture courte (`--watch=60`) ET longue (inventaire `--inventory-out`, re-hash à 24-72 h), cache-buster, antivirus hébergeur relancé, **recette fonctionnelle** (connexion, mot de passe oublié, envoi mail réel, formulaires, cron) : voir [references/verification.md](references/verification.md). Rapport **trouvé / changé / non fait** + check-list de notification RGPD (art. 33, 72 h).

## Tuer un processus : signaux forts seulement

Ne proposer un kill que sur **signaux forts**, jamais sur l'âge : `etimes` est l'âge du **processus**, pas de la requête. Signaux forts : `cwd`/`exe` en `(deleted)` (fichier supprimé du disque, processus sans fichier), exécutable dans `/tmp`, `/dev/shm`, `/var/tmp`, script rattaché à **PPID 1** (détaché de toute requête). Toujours confirmer **par PID** avant `kill -9`. Le parseur lit `ps -u USER -o pid=,ppid=,etimes=,args=` : la colonne user de `ps -eo` est **tronquée à 8 caractères** et ne permet aucun filtrage par regex (piège classique). Détail : [references/persistence.md](references/persistence.md).

## Mises à jour bloquées (facilitateur d'intrusion)

Des mises à jour automatiques coupées sont un **facilitateur** du vecteur, pas un détail. Le détecteur signale : filtres `auto_update_*`, `automatic_updater_disabled`, `file_mod_allowed`, `pre_site_transient_update_*`, `pre_http_request` (souvent cachés dans un thème ou un mu-plugin) ; constantes `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`, `DISALLOW_FILE_MODS`, `FS_METHOD`, `WP_HTTP_BLOCK_EXTERNAL` ; fichier `.maintenance`, verrous `core_updater.lock`/`auto_updater.lock` en base ; permissions/propriétaire des dossiers, disque plein ; `.htaccess` qui renvoie 403 sur admin-ajax/wp-cron/les mises à jour (verrou) ; joignabilité de l'API officielle. Diagnostic pas à pas : [references/updates-blocked.md](references/updates-blocked.md).

## Faux positifs avant de dire « malveillant »

Pièges de terrain qui produisent des faux positifs, et pourquoi le détecteur ne tombe pas dedans : [references/pitfalls.md](references/pitfalls.md). Appliquées au malware, les portes (1) à (3) du global demandent si une entrée non fiable atteint vraiment le puits et si le constat tombe quand on fournit la référence manquante. Un `eval()` sur un jeton de configuration, un `base64_decode()` de licence, un `eval(` dans une chaîne de pare-feu ne sont **pas** des puits : aucune entrée non fiable ne les atteint. Extensions/thèmes commerciaux sans référence publique → **NEEDS_HUMAN** + diff contre une copie de la **même version** téléchargée depuis le compte éditeur du client ; sans copie, on ne conclut pas.

## Lancer le détecteur

- `scripts/detect.php` — **exécuter** (jamais inclure). `php detect.php --help` pour les options. Sortie JSON (`--json=`, refusée dans une racine analysée) + résumé texte. Code retour : 2 si CONFIRMÉ, 1 sinon, 3 erreur fatale.
- Références de confiance : `--ref=CHEMIN` (copie saine ou archive éditeur), `--ref=chemin/rel=DIR` (une extension), `--baseline=inventaire.json` (état antérieur sain). Sans référence, un composant entier passe `NEEDS_HUMAN` : la skill explique comment **dériver** une référence (retélécharger le cœur et les extensions à la même version, diff `--strip-trailing-cr`).
- Invariants détaillés du détecteur : [references/detection-invariants.md](references/detection-invariants.md). Artefacts de base : [references/db-artifacts.md](references/db-artifacts.md).

## Tests

`tests/` (lancer sur PHP 7.4 ET 8.x) : `unit.php` (logique : décodage, parseur ps, analyse statique « aucun include du site », et pour l'outil déposé : aucune inclusion, aucune exécution de commande), `mutations.php` (mutations synthétiques générées au test, dont un cas différentiel **pur** sans aucun motif connu). Un test d'acceptation sur un cas réel vit **avec les données du client, hors de la skill**, et charge `tests/lib.php`. Voir [references/testing.md](references/testing.md).

## Anti-patterns

Nettoyer les fichiers sans toucher la base (la porte dérobée reste en `wp_users`/options) • restaurer depuis une sauvegarde prise pendant l'infection • réécrire un `.htaccess` en entier • tuer un processus sur son âge • filtrer `ps` par la colonne user tronquée • traiter un scan de fichiers propre comme un site propre • conclure « pas de correctif » sur une API injoignable • afficher un secret • exclure un fichier de l'antivirus à la place du client • nettoyer un site et laisser ses voisins de l'abonnement infectés • dater la fenêtre d'incident sur une CVE plutôt que sur la preuve la plus ancienne.
