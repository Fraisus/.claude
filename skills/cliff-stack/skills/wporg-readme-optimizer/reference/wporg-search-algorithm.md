# Algorithme de recherche du dépôt WordPress.org — vérité terrain

> Tout ce fichier concerne le **moteur de recherche interne du répertoire `wordpress.org/plugins/`**, pas Google. Chaque fait load-bearing est attribué à sa source. Non vérifié contre le code vivant : la formule vient du post de 2017 et du code du directory, les tickets Trac sont connus par citations, le moteur a migré depuis (ES7). Les leviers du skill tiennent quel que soit l'état exact.

## Sommaire
- [1. Le moteur](#1-le-moteur)
- [2. La formule de ranking (source primaire)](#2-la-formule-de-ranking-source-primaire)
- [3. Matching texte : AND, et la nuance 2025](#3-matching-texte--and-et-la-nuance-2025)
- [4. Pondération des champs texte](#4-pondération-des-champs-texte)
- [5. Tags : la vraie règle officielle](#5-tags--la-vraie-règle-officielle)
- [6. Tableau mythes / réalité](#6-tableau-mythes--réalité)
- [7. Règles officielles readme.txt utiles](#7-règles-officielles-readmetxt-utiles)
- [8. Sources](#8-sources)
- [9. Précautions de datation](#9-précautions-de-datation)

## 1. Le moteur
Le search du répertoire est bâti sur **Elasticsearch** (wrapper WordPress.com/Automattic). La requête combine trois sections : (a) **function score boosting** sur des signaux méta (installs, support, rating, fraîcheur), (b) **matching texte** sur le contenu, (c) **text boosting** par champ. Migré vers ES7 ultérieurement (ticket meta #6677, ~2021-2022).

## 2. La formule de ranking (source primaire)
Deux sources primaires concordantes : le **post de Greg « Ichneumon » Brown** (Automattic, l'ingénieur du moteur), *Improving Relevance and Elasticsearch Query Patterns*, **15 mars 2017**, `https://data.blog/2017/03/15/improving-relevance-and-elasticsearch-query-patterns/` ; et surtout le **CODE officiel** du plugin directory, `class-plugin-search.php`, méthode `jetpack_search_es_query_args` (miroir : `https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/class-plugin-search.php`).

`function_score` **multiplicatif** (`boost_mode: multiply`), chaque facteur défini par un `field_value_factor` avec une **valeur `missing`** (substituée quand le champ est vide) :

| Champ | factor | modifier | missing (si vide) |
|---|---|---|---|
| `active_installs` | **0.375** | `log2p` | 1 |
| `support_threads_resolved` | **0.25** | `log2p` | 0.5 |
| `rating` | **0.25** | `sqrt` | **2.5** |

(+ un facteur `exp(...)` de différenciation des active_installs autour de 1M, cité par Brown 2017.)

**Lectures décisives :**
- `multiply` → les signaux se **combinent** ; aucun n'est « au-dessus » au sens hiérarchique. Mais en coefficients bruts :
- **active_installs** (0.375 + exp) = signal structurellement le plus fort.
- **rating** (0.25, `sqrt`) : à **zéro avis**, le paramètre **`missing => 2.5`** s'applique → le plugin entre dans la formule **comme s'il avait 2,5/5** (score `0.25*sqrt(2.5)≈0.395`), **pas 0**. C'est un garde-fou codé en dur (sinon `sqrt(0)=0` écraserait tout nouveau plugin). Ce 2,5 est **interne au tri** ; la note **affichée** reste « no ratings yet ».
- **support_threads_resolved** (0.25, `log2p`, missing 0.5) : un **compte** de fils résolus, log-atténué. Le « 0.5 » est une valeur de substitution, **pas un taux de 50%**.
- Conséquence : **« le rating pèse plus que les installs » est faux** (rating 0.25 < installs 0.375). L'effet inverse n'apparaît que sur des plugins à très faibles installs, où `log2p(active_installs)` est petit.

## 3. Matching texte : AND, et la nuance 2025
Brown (2017), verbatim :
> *« Use an AND operator. The user specified both 'post' and 'stats.' The docs we return should have both terms in them. This is the behavior users expect. »*

Matching sur un champ agrégé `all_content` (titre + author + slug + content + tags) en **AND** : en 2017, **tous les mots de la requête devaient apparaître** quelque part, sinon le plugin n'entrait pas dans le pool.

**Nuance** : une source secondaire (dev.to, **15 avril 2025**) rapporte que le moteur semble désormais **splitter les requêtes multi-mots** et pondérer les correspondances individuelles (comportement OR/partiel). **Non confirmé en source primaire.** → Dans un audit, traiter le AND comme **borne de prudence** : couvrir tous les mots reste la stratégie la plus sûre quel que soit l'algo courant, mais ne pas affirmer « un mot manquant = exclusion totale » comme une certitude.

## 4. Pondération des champs texte
Source primaire (Brown 2017) : `title.ngram^2` (boost ×2 sur le titre), et l'**author** ×2 (cas particulier pour détecter les recherches par auteur).
Ordre approximatif de poids textuel : **titre (n-gram ×2) > author (×2) > content / tags / slug (poids de base)**.
FAQ officiel (developer.wordpress.org) confirme l'importance du nom : *« Make your display name memorable and descriptive, while keeping it under 5 words, for maximum benefit. »*

## 5. Tags : la vraie règle officielle
Plugin Developer FAQ (developer.wordpress.org), verbatim :
> *« plugins are limited to 12 tags in their readme »* ; *« only the first FIVE tags will display on WordPress.org »* ; *« The first 12 tags are used for searches, and the rest are ignored, so tag-stuffing won't help you at all. »*

Donc : **12 tags utilisés pour la recherche, 5 affichés** publiquement. Le « seuls 5 comptent » est une simplification fausse pour l'indexation.
De plus, le **poids des tags a été réduit** (sources concordantes : *« the search relevance algorithm was adjusted by dropping the tag-weight by half »*) et les **tags uniques à un seul plugin ne sont plus affichés**. → Tags = signal **faible**, à remplir proprement (≤12 ; les 5 premiers = ceux qu'on veut voir affichés et les plus pertinents) sans surinvestir.

## 6. Tableau mythes / réalité
Ces mythes proviennent surtout de l'article Freemius *Outrank Competitors' SEO on The NEW Plugin Repository* (publié 29 mars 2017, source **secondaire** fondée sur le post de Brown). Article globalement utile mais avec des **valeurs chiffrées inventées/simplifiées** :

| Mythe / claim | Réalité sourcée |
|---|---|
| Seuls les 5 tags comptent | 12 indexés / 5 affichés ; poids ÷2 (faible). |
| Note 2,5/5 par défaut sans avis | **VRAI pour le ranking** (`'missing' => 2.5` dans le code, vérifié). Faux seulement si présenté comme note **publique** (la fiche affiche « no ratings yet »). |
| La note pèse plus que les installs | Faux : installs coef 0.375 + exp, rating 0.25. |
| Support 50% défaut / 100% au 1er ticket | Faux : le `missing 0.5` du facteur support est une **valeur de substitution** dans `log2p(support_threads_resolved)` (un compte de fils), **pas un taux** 50/100. |
| Un mot manquant = exclusion absolue (toujours) | Vrai en 2017 (AND) ; possiblement assoupli en 2025 (OR split, non confirmé). |

## 7. Règles officielles readme.txt utiles
- **Titre** : descriptif + mémorable, **< 5 mots** pour bénéfice max (FAQ).
- **Short description (Excerpt)** : ~150 caractères, affichée et matchée — mots-clés pertinents, **jamais de stuffing** (« tag-stuffing won't help you at all » — s'applique à l'esprit général).
- **`Tested up to`** : à jour avec la version WP majeure courante — les recherches génériques favorisent les plugins testés récemment (ticket meta #1692, via citations : *« should favor plugins… tested with recent WP releases »*).
- **Tags** : ≤12, choisis pour la pertinence.

## 8. Sources
- **PRIMAIRE (code)** — plugin directory officiel, `class-plugin-search.php` (`jetpack_search_es_query_args`), miroir `https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/class-plugin-search.php` : `field_value_factor` exacts (factor/modifier/**missing**, dont `rating … missing 2.5`).
- **PRIMAIRE** — Greg Brown (Automattic), `https://data.blog/2017/03/15/improving-relevance-and-elasticsearch-query-patterns/` (15 mars 2017) : formule + structure ES + AND + boosts.
- **PRIMAIRE (officiel)** — Plugin Handbook & Developer FAQ : `https://developer.wordpress.org/plugins/wordpress-org/` (tags 12/5, titre <5 mots, readme).
- **MÉTA / Trac** — tickets #1692 (qualité search), #4450 (pénalité installs), #6677 (ES7). Connus par citations.
- **SECONDAIRE (origine des mythes)** — Freemius, *Outrank Competitors' SEO on The NEW Plugin Repository* (2017-2018).
- **SECONDAIRE récent** — dev.to (15 avril 2025), hypothèse OR-split, non confirmée.

## 9. Précautions de datation
Toute la mécanique fine date de **2017**. Le moteur a migré (ES7) et un signal 2025 suggère un assouplissement du matching. **Règle pour ce skill** : encoder les leviers robustes au changement (titre fort, installs, couvrir les mots des requêtes, avis, support, fraîcheur), présenter le AND strict et les coefficients exacts comme l'état documenté. Le 2,5 est la valeur de substitution interne au tri (code), jamais une note publique ; le « 50 % » du support est faux.
