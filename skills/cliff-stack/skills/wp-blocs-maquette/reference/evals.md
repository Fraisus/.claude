# Évaluations du skill (maintenance, ne pas charger pour construire une page)

Format de `skill-builder`. Les scénarios a, b, c et e éprouvent les scripts sur un site local réel (lecture seule) ; le d éprouve la décision, joué avec et sans le skill dans le même tour, en session fraîche. Les attendus ne citent aucune valeur d'un site : ils se vérifient contre la sortie des scripts au moment du passage.

## Doit déclencher

```json
[
  {
    "skills": ["wp-blocs-maquette"],
    "id": "a-lecture-site",
    "query": "Avant de monter la page depuis la maquette, dis-moi quels blocs je peux insérer sur ce site WordPress et quels presets il sert vraiment.",
    "expected_behavior": [
      "Lance scripts/presets.php et scripts/inventory.php par wp eval-file depuis la racine du site, avec le PHP du projet",
      "Rend le nombre de blocs listés et la raison de chaque bloc enregistré non listé (non autorisé, inserter:false), compte qui se retrouve dans not_listed",
      "Distingue presets offerts et presets servis non offerts (served_not_offered) et dit ce que l'auteur peut taper hors presets (free_values)",
      "Sépare pour un bloc les attributs du block.json, ceux des supports du cœur, ceux ajoutés à tous les blocs par le thème ou une extension, et ceux propres au bloc",
      "Signale la limite editor_only (scripts de l'éditeur invisibles au serveur) au lieu de présenter l'inventaire comme complet"
    ]
  },
  {
    "skills": ["wp-blocs-maquette"],
    "id": "b-audit-presets-page",
    "query": "Fais l'audit « que des presets » de la page d'accueil du site, sans rien modifier.",
    "expected_behavior": [
      "Trouve l'ID de la page d'accueil (option page_on_front) puis lance scripts/audit-presets.php sur cet ID",
      "Rend hard_values, dead_presets, hidden_presets et redundant avec bloc, propriété et valeur",
      "Lit repetitions avec la part des instances et propose défaut global, variation ou composition selon la règle des répétitions",
      "Ne traite pas unknown_attrs ni classes comme des défauts à supprimer sans preuve dans l'éditeur",
      "N'écrit rien sur le site"
    ]
  },
  {
    "skills": ["wp-blocs-maquette"],
    "id": "c-lint",
    "query": "Valide ces deux fichiers de balisage de blocs avant de les insérer dans une page : section.html et brouillon.html.",
    "files": ["fixture: un markup valide (groupe, titre, paragraphe en presets) et un markup avec un commentaire fermant manquant et un bloc acme/* non enregistré"],
    "expected_behavior": [
      "Lance scripts/wp-block-lint.php sur chaque fichier et lit le code de sortie",
      "Valide : exit 0, aucun avertissement",
      "Invalide : exit 1, deux erreurs (aller-retour échoué, bloc non enregistré nommé), et refuse l'insertion",
      "Rappelle que le lint ne prouve pas l'ouverture sans erreur dans l'éditeur d'un bloc statique"
    ]
  },
  {
    "skills": ["wp-blocs-maquette"],
    "id": "e-styles-globaux-essai",
    "query": "Applique le kit aux Styles globaux du site : voici le theme.json d'entrée (kit.theme.json). Montre-moi d'abord ce qui changerait, n'écris rien.",
    "files": ["fixture: theme.json v3 qui modifie une couleur de la palette et une taille, ajoute une couleur, une taille et une police non installée, écrit une échelle d'espacement et des rayons, une largeur large différente, color.custom, un bloc et une variation inexistants, une valeur d'espacement libre sur un bloc, un preset mort et un preset servi non offert, et des défauts valides (titre, survol de lien, marge interne de bouton en preset)"],
    "expected_behavior": [
      "Lance scripts/global-styles.php sans write=1 (essai à blanc) et vérifie que l'article wp_global_styles est inchangé",
      "Rend refused : modification et ajout de presets sans option, échelle d'espacement et rayons refusés sans option possible, color.custom, bloc et variation inexistants, valeur libre d'espacement, preset mort, preset non offert, police non installée",
      "Rend le diff des seuls défauts valides, comparé par sens (var:preset et var(--wp--preset) égaux), et core_would_drop vide",
      "Propose, pour chaque refus, le cran voisin d'après presets.php plutôt que l'option qui force",
      "Ne propose l'écriture qu'avec --user administrateur et backup= hors du site, après validation du diff par l'utilisateur"
    ]
  },
  {
    "skills": ["wp-blocs-maquette"],
    "id": "d-tuiles",
    "query": "Dans la maquette, la section « Nos réalisations » montre 4 cartes côte à côte en desktop. En mobile on veut garder les cartes à leur taille et les faire défiler au doigt. Quel bloc et quels réglages je prends sur ce site ?",
    "expected_behavior": [
      "Part du comportement voulu en mobile et écarte Colonnes (empile sous 782 px) et la Grille (s'empile ou rétrécit)",
      "Cherche dans inventory.php un bloc de défilement horizontal du thème ou d'une extension, par titre, catégorie ou description, au lieu de le supposer",
      "Renvoie au manuel du thème pour ses réglages (doc_folders) ou à la lecture de son inspecteur, sans inventer d'attributs",
      "Choisit l'espacement et les largeurs dans les presets offerts",
      "Ne propose ni CSS ni classe ajoutée ; sans bloc de défilement disponible, déclare un écart"
    ]
  }
]
```

## Ne doit pas déclencher

- « Crée le kit UI et les jetons de la marque » : `ui-kit`.
- « Ajoute un preset d'espacement au thème » : `wp-native` (code du thème).
- « Ajoute un réglage d'icône au bloc bouton » : `wp-native`.
- « Fais la maquette de la page contact » : `impeccable`.
- « Audite l'accessibilité de cette page » : `design-auditor`.
- « Écris un test de bout en bout de l'enregistrement d'une page » : `testing-real-ux`.

## Calibrage des scripts (à refaire après toute modification)

- `audit-presets.php` sur un fichier connu-positif (une valeur en dur, la même en `@mobile`, un preset mort en `style` et un en attribut, un preset servi non offert, une valeur égale au défaut global d'un bloc, une hauteur de ligne libre, une classe ajoutée) doit trouver exactement ces sept constats ; sur un fichier tout en presets offerts, zéro partout. Un preset que le bloc déclare lui-même (dimension d'un bouton dans le `theme.json` du cœur) n'est pas mort ; un ratio choisi parmi les presets et enregistré en valeur (`3/2`) n'est pas une valeur en dur, un ratio hors presets (`5/2`) l'est.
- `wp-block-lint.php selftest` : tous les cas passent.
- `global-styles.php` : l'essai à blanc sur la fixture du scénario e rend 15 refus sans option et 10 avec `allow_modify=1 allow_add=1` (les ajouts de couleur, de taille et de police, et les modifications de couleur et de largeur passent ; une taille de police, une ombre ou une famille de police existantes restent refusées même avec allow_modify=1 (seules la palette et les deux largeurs sont modifiables sur option : un cran se choisit et ne se modifie pas) ; un ratio « auto » (option Original du contrôle) ou égal à un preset n'est pas une valeur libre, 5/2 l'est ; drift-check.php liste un support __experimental* inconnu d'un bloc factice (code 1) et ignore les drapeaux d'éditeur connus ; les refus de principe restent) ; `write=1` sans administrateur, sans `backup=` ou avec une sauvegarde dans le site est refusé avant toute écriture, empreinte de l'article inchangée. Sans jamais `write=1` : `backup=` égal à la racine du site, à `.`, à un sous-dossier du site ou au nom court 8.3 de la racine est refusé dès l'essai à blanc (code 1), un dossier hors du site est accepté et le fichier final rendu ; `write=false`, `no`, `0`, `off` restent un essai à blanc, `write=maybe` est refusé, `write=yes` sans `--user` s'arrête sur le droit d'administrateur ; `styles.css` et `styles.blocks.<bloc>.css` sont refusés ; une clé de premier niveau de `theme.json` autre que `settings` et `styles` et une clé inconnue sont refusées avec deux motifs distincts ; la couche stockée privée de son marqueur par un filtre en mémoire (`posts_results`) donne l'avertissement et un `diff` en `removed`.
- `inventory.php` : `controls` de chaque bloc reprend tous les supports de l'ancienne table, plus ceux que le cœur a ajoutés depuis (`background.gradient`, `dimensions.minWidth` sur le Groupe, `typography.textIndent`) ; `shadow` d'un bouton à sérialisation coupée reste listé.
- `drift-check.php` : code 0 sur la version relue ; `reviewed=<version précédente>` liste les entrées `@since` de la version relue (code 1) ; un bloc enregistré en mémoire avec un support inconnu (`register_block_type` dans un fichier qui inclut ensuite le script) sort à 1 en le nommant.
- `grid-gap-probe.php` : rendre l'espacement global en preset en mémoire (filtre `wp_theme_json_data_user`, rien d'enregistré) doit faire passer le verdict de `not exercised` à `not affected` ou `DEFECT PRESENT`.
- `presets.php flat=` puis Block Runner `convert --token-resolver file --theme-json` sur une section dont la couleur égale un preset : la couleur sort en slug ; avec le fichier fusionné du cœur, elle reste en hexadécimal.
