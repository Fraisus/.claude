# Tests

Lancer sur **PHP 7.4 et une version 8.x** (le détecteur cible les versions PHP supportées par WordPress). Aucune donnée client ni charge en clair n'est stockée sous `~/.claude` : `lib.php` refuse tout dossier de travail sous `~/.claude`, les fixtures de mutation sont générées à la volée en zone temporaire, et le cas réel est référencé par chemin puis copié fraîchement ailleurs.

## `tests/unit.php` — logique pure

Sans site ni base. Décodage multi-schémas jusqu'au point fixe ; flux entrée→puits (concat de nom de fonction, sortie échappée non signalée) ; parseur `ps` sur une sortie réelle (utilisateur > 8 caractères, entête et `lstart` tolérés) ; kill sur signaux forts, jamais sur l'âge ; **analyse statique de `detect.php` lui-même** : aucun `include`/`require`, aucun chargement de WordPress, aucun hôte externe hors API officielles et profils du test de cloaking (invariant B1) ; emplacements et statuts du cœur ; domaines réservés RFC ; entropie.

```
php tests/unit.php
```

## `tests/mutations.php` — variantes inconnues générées au test

Chaque cas construit une mini-racine WordPress + un dump minimal, à partir de **gabarits encodés reconstruits en mémoire** (aucune charge en clair sur disque), lance le détecteur en sous-processus et vérifie le constat. Couvre : encodage jamais vu (`gzinflate`+base64) vers un puits ; nom de fonction reconstruit par `chr()` ; dropper (écriture de `.php` depuis la requête, chemin en hex) ; persistance par mu-plugin, `.user.ini` `auto_prepend_file`, verrou `.htaccess` à nom inédit ; PHP dans uploads ; `index.php` racine altéré ; **cas différentiel pur** (`M9` : fichier PHP bénin, sans aucun motif connu, signalé par la seule provenance) ; code exécuté stocké en base ; JS injecteur en option de widget ; hook de cron non déclaré ; et un **cas négatif** (plugin propre, zéro faux positif). Prouve que la détection tient sur des variantes non cataloguées.

```
php tests/mutations.php --work=DOSSIER_TEMP --no-network
```

## Test d'acceptation sur un cas réel (E1 à E24)

Il vit **à côté des données du client, jamais dans la skill** (aucun nom ni donnée client dans `~/.claude`). Il charge `tests/lib.php` de la skill (option `--skill=`, défaut : l'emplacement installé). Le test :

1. fait une **copie fraîche** du site hors `~/.claude`, vérifiée par sha256 (`E0`), en excluant ce qu'aucune assertion ne vise pour rester tractable sous un antivirus qui scanne chaque fichier (uploads, cache, cœur ; tout `wp-content/plugins|themes|mu-plugins` est conservé, donc les tables restent expliquées ou non par le vrai code) ;
2. lance le détecteur (fichiers + dump), en lecture seule ;
3. vérifie E1 à E24, puis **re-hache** la copie et le dump pour prouver l'absence de modification (`E19`).

Couverture : chargeur `index.php` + C2 décodé, verrou `.htaccess`, webshells sans `eval`, 82 comptes isolés (ID 5 à 86, 1 à 4 épargnés), artefacts de contenu (antidatés, type non déclaré, pic de la fenêtre), défacement sans script, orphelines, table SEO d'extension et ses valeurs hexadécimales, journal d'agent distant décodé, mises à jour coupées par le thème, **aucun faux positif** sur les trois pièges (chaîne contenant `eval(`, `eval` de jeton de configuration, `base64` de licence), `siteurl`/`home` sains, aucun secret affiché, corrélation, fenêtre sur la preuve la plus ancienne, composant commercial sans référence en `NEEDS_HUMAN`, parseur `ps` réel, statuts limités aux cinq, **aucun nom propre dans la skill et le détecteur** (`E24`).

```
php CHEMIN_DU_CAS/acceptance-case.php \
  --site=CHEMIN/httpdocs \
  --sql=CHEMIN/dump.sql \
  --work=DOSSIER_TEMP \
  --ps-sample=CHEMIN/sortie-ps-reelle.txt \
  --no-network       # déterministe ; retirer pour exercer les manifestes officiels
```

`--reuse` réutilise la copie déjà vérifiée (re-hachée à chaque passage) pour enchaîner les deux versions de PHP sans recopier.

## Antivirus

Si l'antivirus met en quarantaine une fixture ou un fichier de travail, la copie échoue **visiblement** (`tl_fresh_copy` lève une exception nommant le fichier). Le signaler précisément à l'utilisateur ; ne créer **aucune exclusion**.
