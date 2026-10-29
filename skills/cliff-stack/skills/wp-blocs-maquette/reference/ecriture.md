# Écrire et valider une page

Principe : valider avant d'insérer, insérer par `wp post` natif depuis un fichier, ne jamais réparer après coup un bloc cassé (on corrige le markup en amont). La source de vérité de ce qui est valide est le registre vivant du site : cœur, thème et extensions actifs.

## 1. Lint : `scripts/wp-block-lint.php`

```
wp eval-file <ce skill>/scripts/wp-block-lint.php <fichier.html>   # exit 0 valide, 1 refus
wp eval-file <ce skill>/scripts/wp-block-lint.php selftest         # suite intégrée, à lancer une fois par site
```

| Défaut | Mécanisme | Verdict |
|---|---|---|
| Commentaire `<!-- /wp:x -->` manquant, JSON d'attribut malformé | aller-retour `parse_blocks` / `serialize_blocks` | bloquant |
| Bloc non enregistré sur ce site | registre | bloquant |
| Boucle de requête personnalisée sans `query.perPage` numérique (la page 2 rejoue la page 1) | lecture de l'attribut | bloquant |
| Attribut absent du registre, ou de type incohérent | registre | avertissement : WordPress l'ignore |

Le lint ne prouve pas qu'un bloc statique s'ouvrira sans « contenu inattendu » dans l'éditeur : un bloc statique dont le HTML ne correspond pas à sa fonction `save()` passe le lint et casse à l'ouverture. Seul l'éditeur réel le dit (étape 4). Écrire le HTML d'un bloc statique exactement comme l'éditeur le sérialise (classes `has-*`, `wp-block-*`, styles en ligne dans l'ordre du cœur), ou partir d'un bloc enregistré une fois dans l'éditeur.

Un avertissement « attribut non déclaré » sur un attribut que `inventory.php` ne connaît pas non plus est souvent un attribut que l'éditeur déclare en JS seulement : il est conservé dans le markup ; le prouver dans l'éditeur avant de le retirer.

## 2. Procédure `wp post`, toujours par fichier

```
# créer, en brouillon (jamais publié sans demande explicite)
wp post create <fichier.html> --post_type=page --post_status=draft --post_title="Titre" --porcelain   # rend l'ID

# modifier : sauvegarder d'abord, relinter le nouveau markup, puis
wp post get <ID> --field=post_content > <tmp>/sauvegarde-<ID>.html
wp post update <ID> <fichier.html>

# supprimer : corbeille, réversible ; --force seulement sur demande explicite
wp post delete <ID>
```

- Le contenu passe toujours par un fichier : un argument multi-ligne peut être tronqué à sa première ligne par certains lanceurs, et ses guillemets mangés.
- Une page existante se modifie sur une copie (brouillon dupliqué) tant que l'utilisateur n'a pas validé, ou avec sa sauvegarde à portée.
- Après écriture, relancer `audit-presets.php <ID>` : `hard_values`, `dead_presets` et `redundant` à zéro hors écarts acceptés et notés.

## 3. Block Runner (optionnel)

Outil tiers (`block-runner`, Human Made) qui convertit du HTML en blocs natifs du cœur et valide le markup avec les paquets de l'éditeur. Utile pour une section de pur contenu (titres, paragraphes, listes, images, boutons, groupes) écrite en HTML ; inutile si l'on sait déjà écrire les blocs.

Conditions sûres :
- Installer en local au chantier avec `npm install block-runner --ignore-scripts`, jamais en global, version épinglée et relue (installer un paquet, c'est exécuter son code) ; il exige un Node récent (sa plage est affichée à l'exécution : un Node plus ancien est refusé).
- Jetons du site : `--token-resolver file --theme-json <tmp>/theme-flat.json`, fichier écrit par `presets.php flat=`. Jamais les résolveurs `wpcli` ni `rest`, ni pour les médias (`--resolver`) ni pour les jetons (`--token-resolver`) : ils donnent à l'outil tiers l'accès au site réel. Les médias se passent par une table (`--resolver map`, fichier nommé par `media.mapFile` dans la configuration).
- Configuration `.mjs` : c'est du code exécuté. Seulement une configuration écrite par nous, jamais celle d'un dépôt tiers.

Limites mesurées (version 1.0, 2026-10) :
- Un fichier `--theme-json` absent, illisible, ou au format fusionné du cœur (listes rangées par origine `default/theme/custom`) donne **zéro jeton sans erreur** : les couleurs restent en hexadécimal. Contrôle : sur une section d'essai, une couleur égale à un preset doit sortir en slug (`backgroundColor`).
- Le remappage vaut pour une valeur égale à un preset. Un espacement fluide (`clamp()`) ne correspond jamais à une valeur en pixels : les marges restent en dur, à reprendre à la main.
- Les grilles et mises en page fines se perdent ; les blocs du thème et des extensions ne sont ni connus ni validés (`assemble` les refuse). Ils se posent à la main puis passent le lint du site.
- Le markup valide ne prouve ni la fidélité visuelle ni l'ouverture dans l'éditeur du site.

Tout ce qui sort de Block Runner passe ensuite le lint du site (étape 1), puis `audit-presets.php`.

## 4. Preuve dans le vrai éditeur et au front

Par `testing-real-ux` (ce skill ne duplique pas sa procédure) :
1. Ouvrir la page dans l'éditeur : aucun avertissement de bloc invalide ou de contenu inattendu.
2. Un vrai enregistrement par clic, vérifié par l'état (horodatage de modification, plus d'état modifié) : un enregistrement qui n'a rien marqué n'a rien prouvé.
3. Le front aux largeurs des bornes du site (`presets.php` `viewport`) : chaque section rend le comportement écrit à l'étape de décision (nombre d'éléments par ligne, défilement présent, empilement), lu dans le DOM ou les styles calculés, pas sur une capture.
4. Un réglage du bloc changé dans l'inspecteur produit son effet : la page reste modifiable par l'administrateur.
