# skill-builder — checklist, format d'éval, squelette

> Source : doc officielle Anthropic « Skill authoring best practices » + skill-creator officiel (`github.com/anthropics/skills`). Chiffres vérifiés en source primaire.

## Chiffres-clés officiels (ne pas inventer)
| Élément | Contrainte officielle |
|---|---|
| `name` | ≤ **64 car.**, minuscules/chiffres/tirets, **interdits "anthropic"/"claude"**, pas de XML. Convention **gérondif** (`processing-pdfs`). |
| `description` | ≤ **1024 car.**, non vide, **3e personne**, what + when + déclencheurs, légèrement insistante. |
| Corps SKILL.md | ≤ **500 lignes** (perf). Au-delà → splitter en `reference/`. |
| Budget contexte | Métadonnées (name+desc) préchargées à froid ; SKILL.md lu au déclenchement ; reference/scripts lus à la demande (0 token avant). |
| Références | **un seul niveau** depuis SKILL.md (le nesting → lecture partielle `head`). TOC si fichier >100 lignes. |
| Évals | **≥3 scénarios**, créés **avant** le skill, avec **baseline sans skill**. |
| Modèles | tester **sur les modèles que le poste utilise** si ciblés. |

## Format d'éval (officiel, JSON)
Pas de runner intégré — créer le sien (ex. petit harnais d'agents with/without).
```json
{
  "skills": ["nom-du-skill"],
  "query": "Demande utilisateur représentative déclenchant le skill",
  "files": ["test-files/exemple.ext"],
  "expected_behavior": [
    "Comportement attendu 1 (précis, vérifiable)",
    "Comportement attendu 2",
    "Comportement attendu 3"
  ]
}
```
Prévoir aussi des cas **should-NOT-trigger** (pour vérifier que le skill ne se déclenche pas à tort).

## Boucle de développement (Claude A crée / Claude B teste)
1. Faire la tâche **sans** skill (Claude A) → repérer le contexte qu'on fournit à répétition.
2. Extraire le pattern réutilisable.
3. Demander à Claude A de générer le SKILL.md (il connaît le format nativement).
4. Élaguer les explications inutiles (« Claude sait déjà »).
5. Améliorer l'architecture info (schémas/refs dans des fichiers séparés).
6. Tester avec **Claude B** (session fraîche, skill chargé) sur des tâches réelles.
7. Observer les ratés → revenir à Claude A → une consigne concrète et vérifiable, sans emphase ; réorganiser. Répéter.

## Squelette de fichiers
```
<name>/
├── SKILL.md                 # table des matières dense, ≤500 lignes
├── reference/
│   ├── <domaine-a>.md       # détail chargé à la demande (1 niveau)
│   └── <domaine-b>.md
└── scripts/                 # optionnel : opérations déterministes/fragiles
    └── <outil>.py           # exécuté (Run …), pas chargé en contexte
```

## Checklist avant livraison (officielle, condensée)
**Qualité**
- [ ] `description` spécifique, what + when + déclencheurs, 3e personne
- [ ] `name` gérondif, ≤64 car., sans "claude"/"anthropic"
- [ ] corps ≤500 lignes ; détail en `reference/`
- [ ] références à 1 niveau ; TOC si >100 lignes
- [ ] terminologie constante ; pas d'info datée ; exemples concrets
- [ ] pièges/conventions encodés (pas une paraphrase de doc)

**Code/scripts (si présents)**
- [ ] scripts résolvent (gèrent les erreurs), pas de « voodoo constants »
- [ ] dépendances listées et vérifiées dispo
- [ ] forward-slash partout ; intent execute-vs-read explicite
- [ ] étapes de validation/feedback pour les opérations critiques

**Tests**
- [ ] ≥3 évals créées (avant le skill) + baseline sans skill
- [ ] testé sur les modèles que le poste utilise (si ciblés)
- [ ] déclenchement ET qualité de sortie validés, en session fraîche

**Sécurité**
- [ ] scripts bundlés + fetch externes audités
- [ ] `allowed-tools` justifié (élévation de privilège)

## Méthode maison complémentaire (rappel — au-delà de l'officiel)
- Fan-out parallèle dès la reconnaissance.
- `independent-critic` sur tout « ça n'existe pas » que le skill encode.
- **Vérité terrain** (`.d.ts`/sources installées) > web > mémoire pour chaque fait.
- Tracer le skill en **mémoire** après livraison.
