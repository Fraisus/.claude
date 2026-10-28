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
