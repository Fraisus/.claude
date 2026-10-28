# Phases 2 et 3 : plan de jetons, échelles, contrastes

## Sommaire

- Trois niveaux
- Rôles exposés
- Alias vers le projet
- Échelles
- Fichier de couples
- Directions
- Contenu du plan soumis à framing-critic

## Trois niveaux

| Niveau | Contenu | Où il vit | Règle |
|---|---|---|---|
| Primitifs | échelles brutes (50 à 950 par teinte), tailles, durées | un document d'architecture du système, pas la palette exposée | jamais lus par un composant |
| Rôles | fonds, textes, bordures, accent, focus, statuts, rayons, ombres, espacements, durées | `tokens.json` (la page les expose) | un rôle lit un primitif ou une valeur mesurée ; chaque rôle a une note d'usage |
| Composants | variables propres à un composant | `bundle.css`, sous le sélecteur de thème si elles varient | n'existe qu'à la seconde divergence réelle |

Une entrée de palette dans un projet coûte souvent plusieurs classes générées : exposer des rôles, pas des échelles.

## Rôles exposés

Vocabulaire de départ, à ajuster aux besoins réels de l'audit et aux noms que le projet emploie déjà :

- fonds : `bg-canvas`, `bg-subtle`, `bg-surface`, `bg-inverse` (et ce qu'il faut pour un chrome distinct) ;
- textes : `text-default`, `text-muted`, `text-inverse`, `text-inverse-muted`, `text-disabled` (non-texte de fait, exclu des couples à 4,5) ;
- bordures : `border-subtle` (décoratif), `border-control` (3:1, porte le sens d'un champ) ;
- accent : `accent` (aplat, icône, focus, grand texte), `accent-strong` (texte, bouton plein), `accent-hover`, `accent-subtle` (fond pâle), `on-accent` (texte sur aplat d'accent, jamais un blanc littéral dans un composant) ;
- `focus-ring` : plein, 3:1 sur chaque surface où il tombe ;
- statuts : `status-<nom>-bg` et `status-<nom>-text` par statut, mesurés en couple ; un statut porte toujours un mot ou une icône, succès et danger ne se distinguent jamais par la seule teinte.

Note d'usage de chaque rôle de texte : les fonds sur lesquels il se lit, nommés. Ces fonds deviennent ses couples.

## Alias vers le projet

- Les jetons existants du projet se lisent dans le rapport de la zone du thème ou code local et s'aliasent ici ; rien ne se consolide ni ne migre dans le code du projet avant l'implémentation demandée (`impeccable extract` y intervient alors, sur demande).
- Chaque rôle dit le slug, la variable ou le préréglage du projet qu'il alimente. Les slugs restent, seules les valeurs changent : renommer une palette casse les contenus publiés qui la citent (les compter par une recherche dans le contenu, pas seulement dans le code).
- Si le projet calcule seul la couleur du texte selon le fond (couche contextuelle, contraste automatique), mesurer ce qu'il choisira sur chaque fond nouveau avant de l'ajouter : un aplat de marque sous 4,5:1 avec du blanc ne doit jamais devenir un fond porteur de texte.
- Les échelles déjà en place dans le projet (tailles fluides, espacements, rayons, ombres) se réutilisent : ramener les valeurs du kit à leurs crans plutôt qu'ouvrir une seconde échelle.

## Échelles

- `node scripts/scale.mjs <ancre> --name <famille> [--on <fond1>,<fond2>]` : l'ancre reste exacte au pas le plus proche en luminosité (ou `--at`), les autres pas à teinte fixe, chroma ramenée au gamut sRGB.
- Neutres : `--chroma 0.003` à `0.012` et `--hue` de la teinte de marque pour des gris teintés ; au-delà, le neutre devient une couleur.
- Lire les deux lignes de synthèse : premier pas qui tient 4,5:1 en texte, pas sur lesquels le fond tient 4,5:1 en texte sur aplat. Elles décident de `accent` contre `accent-strong`.
- Une valeur réelle de la marque qui manque un seuil reste exacte ; on restreint son rôle et on le dit dans sa note.
- Consigner les mesures qui ont décidé, dans un tableau du document d'architecture : couple, ratio, décision.

## Fichier de couples

Lu par `check-tokens.mjs`, il déclare ce qui doit être lisible. Forme :

```json
[
  ["text-default", "bg-canvas", 4.5],
  ["text-muted", "bg-subtle", 4.5],
  ["accent-strong", "accent-subtle", 4.5],
  ["on-accent", "accent-strong", 4.5],
  ["accent", "bg-canvas", 3],
  ["border-control", "bg-surface", 3],
  { "fg": "focus-ring", "bg": "bg-inverse", "min": 3 },
  { "fg": "text-inverse", "bg": "overlay-scrim", "min": 4.5, "under": "bg-canvas", "themes": ["night"] }
]
```

- Un couple par rôle de texte et par fond que sa note nomme ; 3:1 pour le grand texte (24 px, ou gras 19 px), une bordure de contrôle, un anneau de focus, une icône porteuse de sens.
- Mesuré dans chaque thème : les thèmes suivants héritent du premier sauf surcharge, et c'est souvent là que tombe l'échec.
- Un fond translucide se compose sur `under` (ou `--base`).
- Le script mesure les couples déclarés et rien d'autre : un couple oublié n'est pas un couple réussi. Relire la liste contre les notes d'usage avant de conclure.
- Chaque jeton de couleur absent de tous les couples (ni `fg` ni `bg`) est listé « NON mesuré » et le code de sortie vaut 2. Un rôle qui n'a légitimement aucun couple (filet décoratif, état inactif) se justifie dans le document d'architecture, puis le contrôle se relance avec `--allow-unpaired`, qui garde la liste et rend 0.

## Directions

- Une direction est un choix sur des axes nommés à l'avance, pas un kit de plus. Axes de structure : modèle de navigation, système de colonnes et de marges, densité, rapport texte et image, place du geste signature.
- Couleur, police de titre ou crans de rayon peuvent accompagner une direction ; seuls, ils ne font qu'un habillage.
- Mécanisme : un thème de couleur de `tokens.json` par direction quand les couleurs changent, des groupes de type pour les titres, des variables de composant sous `[data-theme]` pour le reste. Côté projet, dire laquelle s'écrit dans la configuration servie et comment les autres seraient portées.
- Pas de mode sombre global par défaut : les surfaces inversées (pied, bande d'appel, encart) sont des rôles, pas un thème.

## Contenu du plan soumis à framing-critic

1. Résultat attendu (la phrase de SKILL.md, remplie).
2. Niveaux et liste des rôles, avec l'alias de chacun dans le projet.
3. Couleurs : ancres exactes, rôles restreints et pourquoi (ratios).
4. Typographie : familles réelles et leur licence, échelle fluide (bornes min et max, rapport entre corps et plus grand titre), graisses.
5. Espacement, rayons, ombres, mouvement (durées, courbes, mouvement réduit) : crans repris du projet.
6. Directions et leurs axes, ou l'absence de directions.
7. Inventaire des composants, chacun avec sa source (audit, projet) ; ajouts intentionnels avec leur raison.
8. Ce que le plan ne fait pas (aucun fichier dans le dépôt, polices sans licence prouvée écartées).
