# Styles globaux depuis le kit

Avant toute page, les décisions du kit (`ui-kit`) passent dans la couche que l'administrateur édite : l'article `wp_global_styles` du thème actif (Apparence > Éditeur > Styles). Une page construite ensuite hérite de ces défauts et ne porte que ce qui lui est propre. Tout choix de valeur suit la règle des crans de `SKILL.md`.

## 1. Traduire le kit en un theme.json d'entrée

Un fichier `theme.json` version 3, `settings` et `styles` seulement, presets en listes simples comme dans un `theme.json` de thème. Partir de `presets.php` (slugs, valeurs, plages fluides, origine) :

| Décision du kit | Où dans le fichier | Règle |
|---|---|---|
| Couleur de marque | `settings.color.palette`, slug existant de la palette | la couleur de même rôle ; sa valeur change seulement pour appliquer le kit au site entier (`allow_modify=1`) ; une couleur nouvelle seulement si aucune ne tient ce rôle (`allow_add=1`) |
| Taille de texte | `styles.elements.h1…h6`, `styles.typography`, `styles.blocks.<bloc>.typography.fontSize` en `var:preset\|font-size\|<slug>` | le preset dont le maximum `fluid` est le plus proche de la taille desktop de la maquette ; une taille ajoutée (`allow_add=1`) seulement si aucun cran voisin n'est acceptable ; jamais une taille existante modifiée |
| Police | `styles.typography.fontFamily`, `styles.elements.*` | une famille déjà servie ; une nouvelle s'installe d'abord par la Bibliothèque de polices (fichiers), puis s'ajoute (`allow_add=1`) |
| Rôles de texte (couleur, hauteur de ligne, graisse) | `styles` racine et `styles.elements` (`h1`…`h6`, `heading`, `link`, `button`, `caption`) | presets pour couleurs et tailles, valeurs libres pour hauteur de ligne et espacement des lettres |
| Survol | `styles.elements.link[":hover"]`, `styles.elements.button[":hover"]`, et les blocs listés dans `presets.php` `hover` | ailleurs, rien à écrire : écart |
| Défaut d'un bloc (marge interne, espacement des blocs, rayon, ombre, couleur) | `styles.blocks.<bloc>` | **preset existant** seulement : `var:preset\|spacing\|<slug>`, `var:preset\|border-radius\|<slug>` ; un preset que le bloc déclare lui-même (`settings.blocks.<bloc>`) compte pour ce bloc |
| Style existant d'un bloc | `styles.blocks.<bloc>.variations.<style>` | le style doit être déclaré pour ce bloc ; en créer un est du code |
| Largeurs | `settings.layout.contentSize`, `wideSize` | modifier l'une des deux est une décision de kit (`allow_modify=1`) ; une troisième n'existe pas |

**Frontière, sans option pour la franchir** : les familles de presets qu'aucun écran d'administration ne crée ni ne modifie (échelle d'espacement et `spacingScale`, rayons, ratios, dimensions, bichromies : `code_presets` de `scripts/lib.php`) ne s'écrivent pas ; la maquette choisit un cran existant du thème et se décale d'un cran si l'écart est acceptable, jamais un cran modifié ni créé. Seul un défaut de bloc qui cite un de ces presets existants s'écrit. Un écart inacceptable est une décision de code, à rendre à l'utilisateur.

Un preset d'espacement ou de rayon dont la valeur est une variable du thème (`var(--x)`, souvent fluide en `clamp()` ou en unités de conteneur) ne se compare pas par `presets.php` : lire la définition de la variable dans la feuille du thème, ou mesurer la valeur calculée au front aux bornes d'écran du site, avant de choisir le cran voisin.

Changer la valeur d'une couleur de la palette ou d'une largeur est l'application du kit que la règle des crans permet (`allow_modify=1`, une fois, validée) ; changer la valeur d'une taille de police pour coller à une maquette est la modification de cran qu'elle interdit. Un thème dérive souvent d'autres presets ou variables de sa palette (bichromies, couleurs de texte contrastées, alias) : certains se recalculent à la lecture de la couche de l'administrateur, d'autres sont écrits en dur. Ne rien présumer : relire `presets.php` après l'essai et lister, dans le rapport, les presets dérivés qui ont suivi la couleur modifiée et ceux qui ne l'ont pas suivie.

## 2. Essai à blanc, toujours d'abord

```
wp eval-file <ce skill>/scripts/global-styles.php <kit.theme.json> out=<tmp>/essai.json [allow_modify=1] [allow_add=1] [mode=replace]
```

Le rapport :

| Clé | Lecture |
|---|---|
| `refused` | chaque entrée écartée et pourquoi : preset ajouté ou modifié sans l'option, famille non éditable, valeur libre là où des presets existent, preset non servi ou non offert, bloc non enregistré, style inexistant, réglage qui n'est pas de l'administration, clé hors `theme.json`, CSS additionnel (`styles.css`, `styles.blocks.<bloc>.css` : refusé sans option, hors doctrine ; l'administrateur le pose dans Styles > CSS additionnel s'il le veut vraiment) |
| `preset_changes` | `ADD PRESET`, `MODIFY PRESET`, `MODIFY WIDTH` acceptés par les options, avec avant et après |
| `warnings` | style de bloc connu du serveur qu'un script d'éditeur peut retirer : vérifier qu'il est proposé dans l'éditeur ; couche stockée sans marqueur `isGlobalStylesUserThemeJSON`, abandonnée comme le fait le contrôleur REST (le `diff` la montre retirée) |
| `core_would_drop` | clés que la validation du cœur (`WP_Theme_JSON`) jetterait : l'écriture est refusée tant qu'il en reste |
| `core_drops_already_stored` | clés déjà stockées que le cœur jette : signalées, pas bloquantes |
| `unchanged` | entrées égales à ce que le site sert déjà : rien à écrire, elles n'ont pas été ignorées |
| `diff` | chemins ajoutés, changés, retirés dans la couche de l'administrateur, comparés par sens (`var:preset\|x\|y` et `var(--wp--preset--x--y)` sont égaux) ; pour un style, `effective_before` donne la valeur que les couches `theme.json` et administrateur servent aujourd'hui |

`effective_before` ne voit pas les feuilles CSS du thème : une marge, un rayon ou une couleur que le thème pose en CSS sur un bloc n'y figure pas, et un défaut de bloc écrit ici peut l'écraser ou être écrasé par lui. Avant d'écrire un défaut de bloc, lire au front la propriété calculée actuelle de ce bloc ; un réglage que le thème calcule exprès (marge proportionnelle à la taille du texte, par exemple) ne se remplace pas par un preset sans le dire.

Un style de bloc signalé dans `warnings` : chercher d'abord `unregisterBlockStyle` dans le thème et les extensions actives (le retrait y est souvent écrit), puis vérifier dans l'éditeur.

`mode=merge` (défaut) fusionne l'entrée dans la couche existante. `mode=replace` remplace la couche entière : tout ce que l'administrateur avait réglé et qui n'est pas dans l'entrée disparaît ; à ne lancer que sur demande explicite, diff relu.

Rendre `refused`, `preset_changes` et `diff` à l'utilisateur avant d'écrire. Une entrée refusée se corrige dans le fichier (cran voisin), pas en forçant une option.

## 3. Écrire

```
wp --user=<identifiant administrateur> eval-file <ce skill>/scripts/global-styles.php <kit.theme.json> write=1 backup=<dossier ou fichier hors du site> [mêmes options que l'essai]
```

- `--user` : l'écriture exige `edit_theme_options` ; un administrateur évite aussi le filtrage du contenu à l'enregistrement.
- `backup=` obligatoire, hors de la racine du site : le chemin final résolu est contrôlé, dès l'essai à blanc quand il est donné ; un dossier reçoit une copie horodatée de l'article tel qu'il était. Une option booléenne vaut `1`, `true`, `yes`, `on` ou `0`, `false`, `no`, `off` ; toute autre valeur est refusée.
- Mêmes étapes que le contrôleur REST : variations de style des partiels `theme.json` du thème enregistrées avant l'assainissement, même encodage JSON. L'article manquant est créé, comme le fait l'éditeur de site.
- Après écriture, l'article est relu et comparé à ce qui devait être stocké (`readback_mismatch`, code de sortie 1 s'il diffère) : restaurer alors depuis la sauvegarde et chercher la cause.
- Restaurer : le `post_content` de la sauvegarde remis par `wp post update <ID> <fichier>` (contenu extrait dans un fichier).

## 4. Prouver

Un défaut global enregistré peut rester sans effet au front (règle du thème plus spécifique, instance qui porte déjà la valeur). Après écriture : relancer `presets.php` (la couche `user_layer` porte les valeurs), puis lire au front la propriété calculée d'un élément de chaque famille touchée (titre de chaque niveau, lien, bouton, bloc réglé), aux bornes d'écran du site ; enfin ouvrir Apparence > Éditeur > Styles par `testing-real-ux` et vérifier que l'administrateur voit et peut changer les valeurs écrites.
