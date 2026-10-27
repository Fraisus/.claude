# Pièges de terrain d'un audit de sécurité WordPress

Ce que la matrice `../../../references/wp-security-controls.md` ne dit pas : failles réellement trouvées sur du
code maison (plugins d'agence, thèmes sur mesure), la preuve qui les confirme, et le correctif qui ne
casse rien. Chaque entrée : **signe**, **preuve**, **correctif**, **piège du correctif**.

## Sommaire
1. Script PHP appelable en direct
2. Fichiers écrits sous la racine servie (journaux, cache)
3. Entrée de requête recollée dans un lien (XSS réfléchie)
4. Nonce publié, `nopriv`, aucune capability
5. Secrets : en dur, dans les URL, dans les journaux, dans git, entre projets
6. Vérification TLS coupée
7. Jetons chiffrés maison
8. Contrat HTTP d'un point d'entrée appelé par un tiers
9. Retirer un fichier vulnérable
10. Leurres fréquents (ne pas signaler)

## 1. Script PHP appelable en direct
- **Signe** : un fichier du plugin fait `require … wp-load.php` (ou `wp-config.php`) lui-même, ou exécute du code au niveau du fichier sans `if ( ! defined( 'ABSPATH' ) ) exit;`. C'est un point d'entrée HTTP qui échappe au routage WP : ni `admin-ajax`, ni REST, ni nonce, ni capability par défaut.
- **Preuve** : `curl -s -o /dev/null -w '%{http_code}' <url-du-fichier>` répond 200, puis le paramètre dangereux exercé (voir SSRF, lecture de fichier : `?url=file:///…/wp-config.php` ou `php://filter/convert.base64-encode/resource=…` sur un `file_get_contents` brut).
- **Correctif** : si rien ne l'appelle, supprimer le fichier (voir §9). Sinon, le passer derrière `admin-post`/AJAX/REST avec nonce + capability, et `wp_safe_remote_get` pour toute URL venue de la requête.
- **Piège** : la garde ABSPATH ne sert qu'aux fichiers inclus ; un script conçu pour être appelé en direct (cron serveur, webhook) ne peut pas l'avoir, il lui faut sa propre authentification (§8).

## 2. Fichiers écrits sous la racine servie
- **Signe** : `file_put_contents`/`fopen`/`error_log(…, 3, …)` vers `WP_CONTENT_DIR/logs`, `…/cache/<plugin>`, `uploads`, ou un dossier du plugin, avec des données de contact, des réponses d'API, des URL porteuses de clés.
- **Preuve** : lister le dossier, puis `curl` sur un fichier réel ET sur le dossier. 200 = exposé. Mesurer aussi l'accueil (200) pour ne pas conclure d'un site en panne. Un dossier encore inexistant n'est pas « exposé » : c'est une **Piste** tant qu'on n'a pas écrit un fichier par le chemin nominal et mesuré.
- **Correctif** : à chaque écriture (pas seulement à la création du dossier, sinon un dossier livré sans protection le reste), poser un `.htaccess` de refus compatible Apache 2.2 et 2.4 et un `index.php` vide, en silence si le dossier n'est pas inscriptible :
  ```apache
  <IfModule mod_authz_core.c>
  	Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
  	Order allow,deny
  	Deny from all
  </IfModule>
  ```
  Un `Deny from all` nu échoue en 500 sous Apache 2.4 sans `mod_access_compat` : il refuse quand même, mais bruyamment. Nginx ignore `.htaccess` : le dire, la règle va dans la conf serveur. Mieux encore : écrire hors racine servie quand l'hébergement le permet.
- **Piège** : une écriture de journal qui échoue ne doit jamais faire échouer l'envoi qu'elle trace (`@fopen` + retour anticipé). Ne pas écraser un `.htaccess` existant. Vider un cache par `glob('*')` supprime `index.php` mais pas `.htaccess` (fichier caché) : c'est voulu.
- **Et git** : des journaux versionnés publient les données de contact dans le dépôt. `git ls-files <dossier-logs>` le mesure ; le retrait (`git rm --cached` + `.gitignore`) est une décision de l'utilisateur, l'historique garde les versions passées.

## 3. Entrée de requête recollée dans un lien
- **Signe** : une fonction de « propagation » (UTM, tracking, retour) concatène `$_GET[...]` dans un `href` construit à la main, souvent via `preg_replace_callback` sur `the_content`, `wp_nav_menu`, `post_link`.
- **Preuve** : front réel avec `?utm_source="><svg onload=alert(1)>` : le marqueur sort tel quel dans un attribut. Toujours comparer avec la même URL sans paramètre (contrôle négatif).
- **Correctif** : encoder la valeur au point de collage (`rawurlencode`), pas au point de sortie seulement : le HTML est déjà construit.
- **Piège du correctif** : coller une requête à tout `href` casse les liens qui n'en portent pas. `tel:…?utm_…`, `mailto:`, `javascript:`, ancres pures `#x` (y compris `#modale&param=x?utm_…` qui corrompt les paramètres lus dans le hash), et `page/#ancre` (la requête doit passer AVANT le `#`). Ne pas coller sur un schéma autre que http(s) ni sur une ancre, placer la requête avant le fragment, tolérer un espace en tête. Vérifier que l'attribution (la source du lead) passe par le serveur et non par le lien avant de retirer quoi que ce soit.

## 4. Nonce publié, `nopriv`, aucune capability
- **Signe** : `wp_ajax_nopriv_<action>` qui écrit ou supprime ; le nonce vérifié est imprimé dans le footer pour tous les visiteurs.
- **Preuve** : récupérer le nonce sur la page publique déconnecté, appeler `admin-ajax.php` avec lui, sur un post **jetable créé pour le test** (jamais un contenu réel), mesurer qu'il a disparu, supprimer le jetable si besoin.
- **Correctif** : capability par objet (`current_user_can( 'delete_post', $id )`) ; retirer `nopriv` si l'action n'a pas de sens pour un anonyme.

## 5. Secrets
- **Jamais en clair dans un rapport, un message, un commit ou une question** : masque (4 premiers caractères + longueur) et empreinte `sha256` tronquée à 12 (`scripts/scan-exposure.sh` le fait). L'empreinte permet de dire « la même clé est dans 33 projets » sans la divulguer.
- **Distinguer** : une constante remplie depuis un réglage (`define( 'X_API_KEY', get_option(…) )`) n'est pas un secret en dur. Une clé en dur utilisée n'est pas une faille exploitable en soi : c'est une exposition (dépôt, copies, lecture de fichier via une autre faille). La classer selon ce qui la rend lisible.
- **Secret dans une URL** : `?apikey=…` finit dans les journaux d'accès du serveur, dans le `Referer`, et dans les journaux applicatifs qui tracent l'URL. Mesurer dans les journaux réels (`grep -c 'apikey='`), en masquant la sortie.
- **Inter-projets** : un code maison est souvent copié dans d'autres projets (landings, anciens sites). Avant de dire « révoquer la clé », mesurer combien de projets l'utilisent (empreinte), car la révocation les casse tous. Retirer la clé du code d'un projet ne change rien chez le prestataire ni dans les autres copies : le dire clairement, c'est la première inquiétude de l'utilisateur.

## 6. Vérification TLS coupée
- **Signe** : `CURLOPT_SSL_VERIFYPEER` / `VERIFYHOST` à 0/false, `'sslverify' => false`, `add_filter( 'https_ssl_verify', '__return_false' )`. L'API HTTP de WP vérifie par défaut (`'sslverify' => true`, filtre `https_ssl_verify`, `wp-includes/class-wp-http.php`, vérifié en 7.1.2).
- **Classement** : **Durcissement** (exploitation = position réseau entre le serveur et l'API). Le risque du correctif est réel : un serveur client aux certificats racine périmés cessera d'appeler l'API. Sur un plugin déployé chez des tiers non maîtrisés, proposer, ne pas imposer ; si on corrige, passer par `wp_remote_*` (bundle de CA de WordPress) plutôt que cURL brut.

## 7. Jetons chiffrés maison
- **Signe** : identifiant de contact « chiffré » passé en URL, avec clé et IV en dur, IV fixe, mode sans authentification (CFB/CBC sans MAC).
- **Risque** : jeton forgeable ou rejouable (IDOR sur les données du contact) ; clé partagée entre projets = un jeton d'un site vaut sur tous. Le contrat est souvent imposé par un CRM tiers : signaler, ne pas « corriger » unilatéralement, c'est une décision de l'équipe qui émet les jetons.

## 8. Contrat HTTP d'un point d'entrée appelé par un tiers
Crontab serveur, webhook, CRM, application mobile : le code ne voit pas l'appelant, et on n'a souvent aucun accès à sa configuration.
- **Invariants du correctif** : même chemin de fichier, même nom de paramètre, **valeur actuelle toujours acceptée** tant qu'aucune autre n'est configurée (repli), mêmes codes et corps de réponse (une crontab en `curl -f` ou une alerte lit le 200 et le texte). Le détail (verrou pris, étape en échec) va au journal, pas dans la réponse.
- **Secret de garde** : sortir la valeur vers une constante `wp-config.php` ou une option générée par site, avec repli sur l'actuelle ; comparer par `hash_equals` après avoir vérifié que l'entrée est une chaîne (`?key[]=` fait sinon un TypeError, donc une 500).
- **Faute typique** : « sans configuration, tout est refusé » : c'est plus sûr sur le papier, et cela casse toutes les crontabs clients au déploiement.
- **Long traitement appelé en HTTP** : `ignore_user_abort( true )` pour qu'un client qui coupe ne laisse pas une table vidée à moitié remplie.

## 9. Retirer un fichier vulnérable
- Prouver l'absence d'appelant sur **tout le périmètre où il pourrait vivre** : le plugin, le thème, les mu-plugins, les autres projets du poste qui embarquent une copie, les crontabs et intégrations connues (demander si inconnu). Une recherche dans un dossier deviné n'établit rien.
- **Recherche inter-projets bornée** : un `grep -r` sur toute la racine web (des dizaines de milliers de fichiers, `node_modules`, `uploads`) ne finit pas. D'abord localiser les copies (`ls <racine>/*/wp-content/plugins/ <racine>/*/*/wp-content/plugins/`, puis les thèmes), puis chercher dans ces seuls dossiers, `--include=*.php`, en excluant `node_modules`, `vendor`, `uploads`, `cache`. Mettre une limite de temps et rendre ce qui a été couvert : une recherche coupée n'établit pas l'absence.
- **`rg` respecte `.gitignore` par défaut** : un dépôt qui ignore `wp-content/plugins/*` fait sauter le plugin audité et ses copies, et la recherche « ne trouve rien ». Passer `--no-ignore` (ou `grep -r`) et étalonner sur un symbole connu-présent avant de conclure.
- **Un 403 d'un pare-feu applicatif ou d'une règle serveur** sur un payload (`php://filter`, `<svg onload>`) n'est pas un correctif du code : chercher une variante qui passe (`file://`, chevrons seuls), et consigner que la couche existe sans la compter comme protection.
- Après retrait : l'URL répond 404, et les pages qui l'appelaient (s'il y en avait) sont vérifiées.
- Code mort ≠ code inutilisé : une méthode d'enveloppe d'API sans appelant ici peut être appelée par une copie ailleurs. Retirer seulement ce qui est mort **et** sans valeur, après la passe `independent-critic` que le CLAUDE.md global demande pour une suppression.

## 10. Leurres fréquents (ne pas signaler comme faille)
- `unserialize( $x, [ 'allowed_classes' => false ] )` sur une option que seul un administrateur écrit : pas d'injection d'objet ; au plus une note de style (préférer JSON).
- Valeur de requête passée par `sanitize_key` puis `in_array( …, $allowlist, true )` : sûre.
- Nonce imprimé via `esc_js( wp_create_nonce( … ) )` : l'impression est sûre ; la question est ce que le nonce autorise (§4).
- `$_GET` lu, comparé ou passé à `absint` sans jamais être réémis : pas de XSS.
Un leurre compté comme faille coûte la confiance du lecteur dans les vraies.
