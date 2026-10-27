# Évals — wporg-readme-optimizer

> Scénarios eval-driven (skill-builder). Rejouer with-skill vs without-skill ; le succès = le with-skill corrige l'erreur que le without-skill commet.

## eval-1 — tags (piège « seuls 5 comptent »)
```json
{
  "skills": ["wporg-readme-optimizer"],
  "query": "Combien de tags je mets dans le readme de mon plugin et lesquels ? On m'a dit que seuls les 5 premiers comptent.",
  "expected_behavior": [
    "Corrige : 12 tags indexés pour la recherche, 5 seulement affichés",
    "Signale que le poids des tags a été divisé par 2 (signal faible) — ne pas surinvestir",
    "Pas de tag-stuffing ; éviter un tag unique à un seul plugin (non affiché)"
  ]
}
```

## eval-2 — installs vs rating (piège « la note pèse plus que les installs »)
```json
{
  "skills": ["wporg-readme-optimizer"],
  "query": "Mon plugin a 8000 installs et 4,8/5, un concurrent a 5000 installs mais 4,9/5 et passe devant sur 'contact form'. C'est la note qui compte plus que les installs ?",
  "expected_behavior": [
    "Réfute : active_installs factor 0.375 DOMINE rating factor 0.25 (formule code réelle)",
    "Écart 4,8→4,9 négligeable (sqrt, rendement décroissant)",
    "Réoriente vers la vraie cause probable : couverture texte de la requête + titre (title.ngram^2)"
  ]
}
```

## eval-3 — plugin sans avis (piège « 0/5 dans le ranking »)
```json
{
  "skills": ["wporg-readme-optimizer"],
  "query": "Je viens de publier sans aucun avis, je suis donc à 0/5 pour le classement et écrasé tant que je n'ai pas d'avis ?",
  "expected_behavior": [
    "Corrige : missing=>2.5 dans le code → entre comme 2,5/5 dans le ranking, pas 0",
    "Distinction critique : ce 2,5 est interne au tri ; la fiche affiche 'no ratings yet' (pas une note publique 2,5)",
    "1er avis 5★ = gain réel mais modéré (sqrt 1.58→2.24) ; vraie priorité départ = titre/short-desc/installs"
  ]
}
```

## should-NOT-trigger (frontière dure)
```json
{
  "query": "Optimise mon readme ET améliore mon SEO Google sur 'plugin animation WordPress'",
  "expected_behavior": [
    "Traite la partie readme/repo wordpress.org",
    "DÉLÈGUE explicitement la partie SEO Google à seo-launch (ne l'improvise pas)"
  ]
}
```
