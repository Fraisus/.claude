# Évaluations du skill (maintenance, ne pas charger pour un kit)

Format de `skill-builder` : chaque scénario se joue avec et sans le skill dans le même tour, en session fraîche, sur les modèles du poste.

## Doit déclencher

```json
[
  {
    "skills": ["ui-kit"],
    "query": "Crée un kit UI pour la refonte du site de la marque, à partir du site actuel et de notre thème.",
    "expected_behavior": [
      "Écrit le résultat attendu en une phrase vérifiable avant tout fichier",
      "Découpe l'audit en zones d'après les familles de pages relevées, zone du thème local comprise, compose par le skill equipe, avec un brief commun et un rapport par zone retenue",
      "Soumet le plan de jetons à framing-critic avant d'écrire tokens.json",
      "Ne produit ni composant ni maquette avant la sortie des phases 1 à 3"
    ]
  },
  {
    "skills": ["ui-kit"],
    "query": "Notre rouge de marque est #E03C2B, fais-moi les jetons de couleur du design system et vérifie les contrastes.",
    "expected_behavior": [
      "Génère l'échelle par scripts/scale.mjs avec l'ancre exacte",
      "Sépare accent et accent-strong si l'ancre est sous 4,5:1 en texte sur le fond",
      "Écrit un fichier de couples et rend check-tokens.mjs à 0 échec dans chaque thème",
      "Expose des rôles alias des slugs du projet, sans renommer sa palette"
    ]
  },
  {
    "skills": ["ui-kit"],
    "query": "Publie la charte et les composants en artefact Design System et dis comment ça s'intègre dans le thème.",
    "expected_behavior": [
      "tokens.json en listes {name, value, usage}, jamais en carte DTCG",
      "tokens.css compilé par compile-tokens.mjs, première ligne finissant par generated from tokens.json",
      "Chaque aperçu commence par le marqueur @dsCard",
      "Livre une table d'ancrage jeton et composant vers mécanisme natif, chaque mécanisme retrouvé par une recherche"
    ]
  }
]
```

## Ne doit pas déclencher

- « Fais la maquette de la page contact » : `impeccable` (shape puis craft).
- « Audite l'accessibilité de ce formulaire » : `design-auditor`.
- « Rends ce bouton plus joli » : `impeccable polish`.
- « Génère le DESIGN.md du projet » : `impeccable document`.
- « Crée un skill pour nos design tokens » : `skill-builder`.
- « Ajoute une couleur à la palette du theme.json » : `wp-native` (pas de kit à construire ni à ancrer).
- « Crée un kit UI » sur un projet WordPress sans demande d'ancrage : ui-kit se déclenche, mais ni `wp-settings.php` ni `wp-anchor.mjs` ne tournent.

## Évaluations des scripts WordPress (2026-10-29)

Rejouer après toute modification de `wp-anchor.mjs` ou `wp-settings.php`.

1. `node scripts/wp-anchor.mjs --selftest` : 16 cas tenus. Sonde étalonnée par mutation : un test d'égalité de couleur cassé fait échouer « blanc exact » ; un `clamp()` réduit à sa valeur centrale fait échouer le cas borné ; une déclaration hors `:root` préférée à celle de `:root` fait échouer le cas `var()`.
2. `wp eval-file scripts/wp-settings.php <site.json>` sur un site local à thème bloc (WordPress 7.1.4) : sortie écrite, arbre de travail du dépôt inchangé (`git status` vide).
3. Comparaison du kit `tokens.css` d'un projet réel (111 jetons) : 57 ancrés (9 exact, 18 borne, 21 plage, 9 proche), 42 sans ancrage, 12 hors familles, 0 non évaluable. Les presets d'espacement et de rayon du thème, fluides par `var()` vers sa feuille, sont résolus et comparés en bornes ; 12 valeurs servies différentes de la couche du thème (10 couleurs, 2 largeurs) et 3 sélecteurs de presets redéfinis par bloc sont listés.
4. Cas connu-positif : un jeton `#ffffff` ressort `exact` sur le preset blanc de la palette servie. Cas connus-négatifs : une couleur `#7a00ff`, un espacement de 333px et une police absente ressortent `sans ancrage`, le plus proche donné pour lecture seulement.
5. Entrée `tokens.json` (deux thèmes, alias, familles `spacing`, `radius`, `motion`, `type`) : alias résolu vers le même preset que sa cible, tailles de `type.groups` et piles de `type.families` comparées, famille de mouvement classée hors familles ; `--json` valide.
6. Entrées fausses : kit absent ou `site.json` qui n'est pas une sortie de `wp-settings.php` : code 1 avec la raison ; arguments manquants : code 2.
