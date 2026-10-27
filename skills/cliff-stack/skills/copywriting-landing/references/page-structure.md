# Structure de page — section par section + templates

> Ordre canonique convergent (toutes sources indépendantes). Le pricing ne va JAMAIS sans preuve sociale collée ; la page ne se termine JAMAIS sans CTA final + garantie.

## Les 10 sections

1. **Hero** — exactement 1 de chaque : H1 (bénéfice) · sous-titre (précise/lève l'objection #1) · **1 CTA dominant** · visuel produit RÉEL (capture/GIF/micro-démo, jamais stock photo) · preuve sociale légère (note, logos, compteur). Démontrer la valeur en 3-5 s. **Jamais de carrousel auto.**
2. **Barre de preuve** — « Utilisé par [logos] » ou « 4,8/5 sur G2 · 200+ avis » ou compteur d'installs. Scannée en <1 s.
3. **Problème** — articule la douleur (PAS), dans les mots de l'audience.
4. **Solution / Comment ça marche** — 3 étapes max, chaque étape avec un visuel. Montrer, pas raconter.
5. **Bénéfices** — outcomes (gagner du temps, livrer plus vite, moins de bugs).
6. **Fonctionnalités** — en **FAB**. Pour public technique : tableau feature ↔ ce que ça débloque, specs assumées.
7. **Preuve approfondie** — témoignages (vidéo > texte), études de cas chiffrées. Hiérarchie de confiance : vidéo > note agrégée (G2/Capterra/wordpress.org) > logo bar > étude de cas.
8. **Objections / FAQ** — lister les vraies objections (prix, compat, support, migration, lock-in) et y répondre.
9. **Pricing** — voir [[pricing-strategist]] pour les niveaux/anchoring. Copy : preuve sociale (témoignage outcome-driven + badge) **collée aux tiers**, garantie visible, plan « Le plus populaire » mis en avant.
10. **CTA final** + **garantie / réducteur de friction** — « Essai 14 j, sans carte », « Remboursé 30 j ».

## Règles transverses
- **Preuve au moment de décision** : placement bat volume. Preuve légère dans le hero, preuve riche **dans/avant le pricing** et près du sign-up.
- **Whitespace** : aérer (charge cognitive ↓). Pas de mur de texte.
- **Un seul objectif par page**, un seul CTA dominant par section.
- **Longueur = fonction de la conscience + du ticket** : court si problem-aware/low-ticket ; long si éducation/ticket élevé. Pas de dogme long-form.

---

## Deux registres pour un plugin/thème

### (a) Page repo wordpress.org (readme)
Relève de `wporg-readme-optimizer` ; elle renvoie vers le site propre pour la version pro.

### (b) Site propre — la vraie page de vente
- Applique tout l'arsenal ci-dessus.
- Pricing 3-4 tiers, anchoring, garantie, hiérarchie freemium.

## Freemium — hiérarchie de CTA
Ordre de mise en avant : **payant > trial avec carte > trial sans carte > gratuit**. Dé-emphasiser le gratuit sur la page de vente (le mettre en dernier recours), sinon il cannibalise les conversions payantes (benchmark free→paid ~2 %). Mécanique d'offre/trial/dunning : la plateforme de facturation du projet ([[freemius]] si c'est elle).

---

## Template — site propre (squelette à remplir)
```
HERO
  H1: [bénéfice principal, ≤8 mots, 5-s test]
  Sous-titre: [précise + lève l'objection #1]
  CTA: [verbe + bénéfice, ex. « Démarrer mon essai »]   ·   sous-CTA: [sans carte / annulable]
  Visuel: [capture/GIF produit réel]
  Preuve légère: [note wordpress.org / G2 · nb avis]

BARRE DE PREUVE: [logos « Utilisé par » | compteur installs]

PROBLÈME (PAS): [douleur dans les mots de l'audience]
SOLUTION / COMMENT ÇA MARCHE: [3 étapes + visuels]
BÉNÉFICES: [3-5 outcomes]
FONCTIONNALITÉS (FAB): [feature → advantage → benefit ; specs pour public technique]
PREUVE: [témoignages réels — placeholders [à brancher] ; études de cas chiffrées]
OBJECTIONS / FAQ: [prix, compat, support, migration, lock-in]
PRICING: [→ pricing-strategist] + preuve collée + garantie 30 j + « Le plus populaire »
CTA FINAL + GARANTIE
```

## Sources
KlientBoost, SaaSFrame (trends 2026), GenesysGrowth, SmartClick, Userpilot (structure SaaS) ; Freemius (pricing/freemium plugin WP) ; Prismic/Omniconvert/Memorable (hero) ; SaaSHero/GoPrecision/Ravefy (social proof).
