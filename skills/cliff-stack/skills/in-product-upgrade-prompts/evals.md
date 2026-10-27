# Évals — in-product-upgrade-prompts

> Scénarios eval-driven (skill-builder). Rejouer with/without-skill ; succès = correction d'une erreur à coût réel (API inventée, rejet repo, build cassé).

## eval-1 — API « pattern verrouillé inserter » (piège : API inventée)
```json
{
  "skills": ["in-product-upgrade-prompts"],
  "query": "Je veux que mes blocs/patterns premium apparaissent dans l'inserter Gutenberg avec un cadenas : aperçu visible mais insertion bloquée sans le Pro. Comment je verrouille l'aperçu du pattern premium dans l'inserter ?",
  "expected_behavior": [
    "Dit qu'AUCUNE API native ne fait ça (bug Gutenberg #55469 ; Block Locking API = blocs déjà insérés, pas un aperçu inserter)",
    "Redirige vers registerBlockVariation scope:['inserter'] + Placeholder d'upsell dans edit(), ou bloc absent tant que le Pro n'est pas installé",
    "Gating réel délégué à la plateforme de licence du projet (si Freemius : can_use_premium_code(), pas is_paying())"
  ]
}
```

## eval-2 — admin notice globale + Block Directory (piège : rejet repo)
```json
{
  "skills": ["in-product-upgrade-prompts"],
  "query": "Pour pousser vers le Pro : une admin notice en haut du dashboard 'Passez à Pro' affichée à chaque chargement jusqu'à l'achat, et je soumets au Block Directory pour la visibilité. Bon plan ?",
  "expected_behavior": [
    "Refuse la notice dashboard globale répétée (viole Guideline 11) → contextuelle, dismissible, dismiss mémorisé",
    "Corrige : freemium va au Plugin Directory ; Block Directory interdit tout paywall ('No form of payment')",
    "Propose option Pro inerte au point de friction / Placeholder"
  ]
}
```

## eval-3 — package + composants (piège : edit-post déprécié, status Notice inventé)
```json
{
  "skills": ["in-product-upgrade-prompts"],
  "query": "Pour mon upsell in-editor : un panneau latéral 'Pro' avec un encart d'alerte stylé 'premium' et un bouton CTA. Donne-moi les imports @wordpress/* et les props.",
  "expected_behavior": [
    "PluginSidebar depuis @wordpress/editor (pas @wordpress/edit-post déprécié)",
    "Notice status ∈ warning|success|error|info uniquement (refuse 'premium') ; isDismissible reste true (Guideline 11)",
    "Button variant='primary' pour le CTA"
  ]
}
```

## eval-4 — arbitrage upgrade-prompt vs avis (trigger)
```json
{
  "skills": ["in-product-upgrade-prompts"],
  "query": "Après le 5e bloc inséré + page publiée, je prompte l'upgrade ou je demande un avis ?",
  "expected_behavior": [
    "Choisir UN seul objectif, ne pas empiler les deux sollicitations",
    "Pour le repo, le 1er avis 5★ prime (délègue la mécanique repo à wporg-readme-optimizer)",
    "Pas de prompt à l'activation ; espacer, dismissible"
  ]
}
```
