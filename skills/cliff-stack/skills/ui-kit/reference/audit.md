# Phase 1 : audit du site existant en fan-out

Des agents de lecture, un brief commun, un rapport par zone retenue. Le thread principal seul écrit hors des rapports. Ce fichier donne les zones candidates ; le skill `equipe` décide de la composition (nombre d'agents, zones regroupées, plafonds, budget d'agents). Chaque agent reçoit le brief commun puis sa mission.

## Sommaire

- Brief commun (gabarit)
- Zones candidates
- Structure d'un rapport
- Synthèse

## Brief commun (gabarit à remplir)

```markdown
# Brief commun : audit UI/UX de <site> pour un kit UI

## Pourquoi
<le besoin réel : refonte, nouveau builder, ticket et échéance>. Phase 1 : savoir ce qui, sur le site
actuel, est OBSOLÈTE (à ne pas récupérer, sauf exception justifiée) et ce qui est MODERNE (refait
récemment), puis en tirer un kit complet (typographie, couleur, espacement, rayons, ombres,
mouvement, composants, jetons, ancrage dans <projet>).

## Faits déjà établis (ne pas refaire)
- Pile du site : <CMS, constructeur de pages, thème, bibliothèques JS>, mesurée par <commande>.
- Couches de CSS et leurs préfixes ou variables : <fichier : rôle, taille, variables relevées>.
- Polices chargées et leur origine.
- Copies locales déjà téléchargées dans <dossier> : <pages, feuilles, plans de site>.
- Ce que montrent les pièces de la cible (captures, ticket).

## Contrats partagés
- Lecture seule : aucun fichier du dépôt modifié, aucune écriture hors <dossier de séance>.
- Téléchargements : `curl -sL -A "Mozilla/5.0"` vers <dossier>/site/<nom>.html ; <N> pages au plus par
  agent, échantillon raisonné à partir des plans de site.
- Preuve avant affirmation : chaque constat cite l'URL, le sélecteur ou la ligne CSS. Une taille se
  lit dans le CSS ou le style calculé. Une absence se rend avec sa commande et son périmètre.
- Une sonde (grep, regex) s'étalonne sur un cas connu-positif avant de conclure.
- Contrastes calculés par `scripts/color.mjs contrast`, alpha composé sur le fond réel.
- Navigateur : un seul agent le pilote (la zone du chrome) ; les autres travaillent sur le HTML et le
  CSS téléchargés.
- Rapport en Markdown, en français, à l'emplacement de la mission, structure ci-dessous.
- Aucun message à un autre agent, aucune question à l'utilisateur ; un blocage se note dans le rapport.

## Annuaire
<les zones retenues, une ligne chacune, avec le chemin de leur rapport>
```

## Zones candidates

Une liste de départ, pas un découpage imposé. Avant de lancer, relever les familles de pages réelles (plans de site, gabarits, routes) et découper d'après elles : une zone par famille ou groupe de familles qui partage gabarit et feuilles, les petites fusionnées, les grosses scindées. Une zone candidate sans objet n'est pas lancée ; elle se note dans la synthèse avec sa raison.

| Zone | Quand | Périmètre | Ce qu'elle doit rendre |
|---|---|---|---|
| Chrome | le site a un chrome commun | en-tête, navigation et méga-menu, pied de page, la couche de CSS la plus récente | jetons réellement déclarés et leurs doublons, polices et graisses par rôle, systèmes de boutons, points de rupture, accessibilité au clavier (navigation, accordéons, dialogues), mouvement, contrastes mesurés ; seul agent avec navigateur |
| Famille de pages | une par famille ou groupe relevé | les pages de la famille, échantillon raisonné | génération (lue dans le gabarit et la feuille, pas dans la date), verdict moderne ou obsolète avec preuve ; feuilles qui la stylent, variables déclarées contre usage réel, couleurs littérales par fréquence, échelles de tailles, espacements, rayons et ombres relevées ; composants dupliqués ou morts, incohérences entre gabarits de la famille, données structurées ; défauts UI/UX, contenus à garder (contenu à migrer, forme à refaire) |
| Thème ou code local | obligatoire quand un projet existe | le projet qui accueillera le kit | palette et slugs existants, échelles de tailles et d'espacement, rayons et ombres prédéfinis, couche de variables contextuelles, styles de blocs, composants existants (actifs ou dormants), source de vérité des réglages (fichier ou base), points d'extension, chaîne de build, pièges de chargement des polices |
| Veille | conseillée | état de l'art des kits UI et des jetons, sources citées et datées | architecture de jetons, échelles de couleur et contraste, typographie fluide, espacement, rayons et ombres, motifs accessibles (APG), mouvement et mouvement réduit, pratiques du secteur de la marque ; toute affirmation volatile datée |

La zone du thème ou code local décide de l'ancrage : sans elle, le plan de jetons invente un second dialecte. La veille ne tranche rien seule : ses recommandations passent au crible des mesures des autres zones.

## Structure d'un rapport

1. **Verdict** en cinq lignes.
2. **Constats**, numérotés, chacun avec sa preuve (URL, sélecteur, ligne, commande).
3. **Inventaire** en tableaux (jetons relevés et équivalent, composants, pages).
4. **Recommandations pour le kit**.
5. **Ce que je n'ai pas pu mesurer**, avec la raison.

## Synthèse (thread principal)

- Verdict par couche (moderne, obsolète, mixte) et par famille de pages, chacun avec la preuve tirée d'un rapport.
- Zones candidates non lancées, chacune avec sa raison.
- Contenu à garder, forme à refaire : tableau par famille.
- Valeurs à reprendre exactement (le code fait foi, une capture n'est qu'une indication) et valeurs à abandonner, avec leur mesure.
- Contradictions entre rapports, tranchées par une mesure, jamais par une préférence.
- Cette synthèse devient la section d'audit du système publié.
