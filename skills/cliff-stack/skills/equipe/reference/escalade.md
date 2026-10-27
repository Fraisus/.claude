# Passages de palier (escalade et descente)

Un ouvrier ne décide d'aucun passage : il rend un signal, le thread principal lit, décide, réécrit le plan et relance à neuf la zone concernée. Les seuils réglables vivent dans `skills/cliff-stack/hooks/budget-agents.json`.

| Passage | Signal qui le déclenche | Qui décide |
|---|---|---|
| Session seule → sous-agents | ≥2 zones sans couplage fort, chacune ≥2 fichiers | principal ; plan court annoncé si ≥2 écrivains, puis lancement |
| Session seule → /batch ou workflow | ≥5 éléments homogènes, même transformation, vérification déterministe | principal ; `Workflow` est déjà une règle `ask` |
| Sous-agents → /batch (en cours) | un ouvrier rend `fanout_homogene` avec la liste | principal, puis porte du workflow |
| Sous-agents → équipe coordonnée | allers-retours entre deux zones que le principal relaie mal (relais ≥3, ou même paire 2 fois) ET contrat réécrit ≥2 fois | refusé aujourd'hui par la garde (règles P2, actives quand `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`) : rester en sous-agents |
| Sous-agents → fusion (descente) | un ouvrier rend `hors_zone_requis` vers la même zone 2 fois (couplage réel fort) | principal, sans porte (moins de risque) |
| Sous-agents → arrêt | même échec de vérification 3 fois, ou itérations épuisées, ou échéance dépassée | utilisateur (le garde bloque, le principal rend compte) |
| Équipe coordonnée → sous-agents | plus d'échange entre coéquipiers sur les 2 dernières livraisons, contrat figé, ou perte des coéquipiers | principal ; coéquipiers coupés à l'inactivité |

Règle de fond : jamais de montée automatique sans le principal, jamais de montée en équipe coordonnée tant que la garde la refuse, jamais d'escalade décidée par « ça semble complexe ». Plus d'écrivains en parallèle est le schéma qui échoue le plus ; l'équipe coordonnée ne se justifie que par un échange répété entre zones, pas par la taille.
