# Socle commun des agents morph

Lu en première étape par chaque agent `morph-*` et par `regression-tester`. Il porte ce qui leur est commun ; chaque agent ne garde que sa zone.

## Le moteur
Le moteur responsive morph (variantes de blocs par écran, attributs suffixés `_rsp_tablet` et `_rsp_mobile`) vit dans le thème WordPress du projet qui le porte. Ses fonctions PHP gardent l'espace de noms `wbd_rsp_` et son JavaScript `wbdRsp` : un renommage casserait la parité des signatures PHP et JS et les clés `_rsp_*` déjà enregistrées dans les contenus. Aucun chemin, slug de thème, préfixe de base, identifiant de post ni URL n'est supposé : tout se découvre.

## Découverte
- **Racine du moteur** : Glob `**/wp-content/**/includes/core/constants.php`, garder celui qui définit `WBD_RSP_SCHEMA_VER` (à l'exécution, `WBD_RSP_DIR`). Aucun : le dire et s'arrêter.
- **Doctrine du dépôt, avant ce fichier et avant le tien** : le `CLAUDE.md` racine du projet (wrapper WP-CLI, binaire PHP, source de l'URL locale, environnement d'essai), `<racine du moteur>/includes/CLAUDE.md` (zones, forme des unités, clés des `*.asset.php`, chargeur, gestes interdits), `.claude/rules/themezero-structure.md` du projet (section du moteur : deux canaux, attributs d'identité, trois listes de sources, schémas dépréciés) et `tooling/tests/README.md` du projet (chaque harnais du moteur et sa commande). Un fait de ton fichier se reconfirme là ou dans le code avant d'entrer dans un verdict.
- **WP-CLI** : le wrapper que nomme le `CLAUDE.md` du projet, jamais un `php` nu si le projet dit autrement.
- **Identifiants** : `includes/core/constants.php` est la seule source (`WBD_RSP_SCHEMA_VER`, clés de méta, suffixes, ids DOM, marqueurs, clés réservées du cache), exposée au JS par `wbd_rsp_constants_for_js()` ; jamais cités de mémoire.
- **Points d'extension** : Grep `apply_filters(` dans les fichiers du moteur ; ils portent le préfixe court `wbd_rsp_`.
- **Largeurs d'écran** : `wbd_rsp_media_queries()` et `wbd_rsp_viewport_px()` (réglage `settings.viewport` du cœur), jamais une valeur en px écrite.

## L'interrupteur du moteur, en premier
`wp eval 'var_dump(wbd_rsp_enabled());'`. Éteint (un `define` en amont, ou le filtre marche-arrêt branché sur l'écran des réglages) : ni marqueurs, ni registre, ni écriture par écran ; un rapport « la variante ne bascule pas » est alors le comportement attendu, pas un défaut.

## Essais
Tout essai (enregistrement, parcours, harnais) se fait sur la copie ou l'environnement d'essai que décrit le projet, jamais sur l'instance, la base ou le serveur de l'utilisateur ; ce qui est créé ou lancé est effacé ou arrêté à la fin. Un parcours dans l'éditeur ou au front passe par les commandes du projet en tâche de fond (skill `testing-real-ux`, adaptateur `.claude/ux-e2e.json`, oracles de `tooling/tests/`), preuve par le contenu (DOM, ligne de cache décodée, réponse). Un enregistrement réel est un vrai clic sur Enregistrer ou Ctrl+S, jamais `savePost()` ni `wp.data.dispatch()`. Les vérités d'API WordPress et Gutenberg viennent du code sur disque, puis du skill `wp-native`, puis de Context7 (`/wordpress/gutenberg`, `/websites/wp-gb`) en dernier.

## Contrat d'un constat
Un constat n'est rapporté comme défaut que rempli ainsi :
- **direct_signal** : lecture, commande ou sortie exacte (fichier:ligne, grep, sortie de `wp eval`, valeur réelle du DOM ou du cache ; pour une signature, les deux chaînes `pos_<hex>`). Une absence dans un fichier n'est pas une absence dans le système.
- **refutation_attempt** : où un mécanisme compensateur ou un autre consommateur a été cherché, et ce qui a été trouvé.
- **wp_native_baseline** : WordPress seul fait-il la même chose sans le moteur ? Si oui, c'est hérité, pas un défaut du moteur.
- **trigger_frequency** : fréquent ou marginal en usage réel, jugé par le contrat, pas par « zéro occurrence dans le contenu actuel ».
- **signal_targets_claim** : le signal mesure l'objet même de l'affirmation (code exécuté, pas un commentaire, un objet voisin ou un substitut) et entraîne logiquement le verdict.
- **verdict** : `confirmed` ou `false_positive` seulement. Sans `direct_signal` ni `refutation_attempt`, c'est une hypothèse : le dire et s'arrêter.

## Limites communes
Lecture seule sur le code : aucun agent morph n'écrit le correctif, le thread principal le code. Une affirmation « résolu » attend `regression-tester` et les oracles du projet. Les rapports se rendent en français.
