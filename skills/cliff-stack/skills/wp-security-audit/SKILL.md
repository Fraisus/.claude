---
name: wp-security-audit
description: Audits the security of existing WordPress custom code (plugin, theme, mu-plugin, agency code copied across projects), prioritised by real-world exploitation, and proves each finding by a real trigger before reporting it. Use when asked for a security audit or review ("audit de sécurité", "revue de sécurité", "est-ce exploitable", "y a-t-il une faille", "un abonné peut-il devenir admin", "contrôle d'accès", "élévation de privilèges", "XSS/CSRF/SSRF/IDOR/injection SQL", "upload dangereux", "secret ou clé en dur", "journaux exposés", "fichier appelable en direct", "sécurise ce plugin/thème"), or when a security fix is requested on existing code. Covers broken access control (admin_init, is_admin, ajax without capability), privilege escalation, uploads, custom auth, XSS, direct-access scripts, exposed files, secrets, TLS, and fixes that keep third-party HTTP contracts. NOT a hacked site (wp-incident-response), NOT the Plugin Check gate (wp-plugin-check), NOT writing new code (wp-native).
---

# wp-security-audit — trouver, prouver, corriger sans casser

> **Aucun secret en clair** dans ce que tu écris, jamais : masque + empreinte (§Secrets).

Un audit utile ne liste pas des motifs inquiétants : il **prouve** ce qui est exploitable, **écarte** ce qui ne l'est pas, et propose des correctifs qui **ne cassent rien chez ceux qui appellent le code**.

## Frontières (ne pas doublonner)
- **wp-plugin-check** : gate officiel Plugin Check d'un plugin distribuable. Réutiliser son inventaire `../wp-plugin-check/scripts/scan-security-sinks.sh` (exige `rg` exécutable ; dans Git Bash `rg` n'est souvent qu'une fonction du shell interactif, rejouer alors ses motifs avec l'outil Grep).
- **`../../references/wp-security-controls.md`** : matrice point d'entrée → contrôles obligatoires, faits vérifiés dans le cœur. C'est LA grille de vérification ; ce skill ne la recopie pas.
- **wp-incident-response** : site déjà compromis (malware, spam SEO, admins inconnus).
- **Hook PHPCS** : signalements syntaxiques sur les lignes éditées ; une ligne ancienne signalée n'est pas un finding de l'audit tant qu'elle n'est pas tracée.

## Procédure

### 1. Périmètre et appelants
En tête du rapport : révision revue (hash de commit, ou « arbre de travail non commité »), site de test, et le fait que les tests actifs portent sur un site local autorisé. Nommer ce qui est revu (dossiers) et ce qui ne l'est pas. Établir **qui appelle le code** : routage WP, crontab serveur, webhook, CRM, autres projets qui en embarquent une copie. Un appelant tiers inconnu = une question à poser avant tout correctif touchant son point d'entrée (§8 de la référence).

### 2. Inventaire (heuristique, lecture seule)
Run `bash scripts/scan-exposure.sh <dossier>` : scripts appelables en direct, fichiers sans garde ABSPATH, écritures de fichiers, TLS coupé, secret dans une URL, entrée recollée sans encodage, secrets littéraux **masqués avec empreinte**. Compléter par l'inventaire de wp-plugin-check (points d'entrée, contrôles, SQL, sorties, HTTP sortant), **toujours filtré** : `bash ../wp-plugin-check/scripts/scan-security-sinks.sh <dossier> | perl scripts/mask-secrets.pl` (il imprime les lignes brutes, secrets compris). Un hit n'est pas une faille ; zéro hit n'est pas une preuve.

### 3. Tracer chaque point d'entrée jusqu'au sink, dans l'ordre du risque réel
Chasser d'abord ce qui est **exploité**, pas ce qui est le plus publié : contrôle d'accès (handlers sur `admin_init`/`init`, `is_admin()` pris pour une garde, `wp_ajax_*` sans capability, IDOR), puis élévation par les données (options, métas utilisateur, rôles), upload/inclusion, authentification maison, XSS stockée, CSRF/redirection/REST. Ordre, chiffres sourcés et preuve par classe : `reference/classes-exploitees.md`.
Suivre la donnée, pas la proximité : un nonce, un `current_user_can`, un `prepare()` dans le même fichier ne protège que s'il gouverne ce chemin. Contrôles attendus : la matrice partagée. Pièges propres au code d'agence (scripts directs, journaux servis, liens, secrets, contrats tiers) : `reference/pieges-terrain.md`.

### 4. Prouver par déclenchement réel
- **Confirmé** exige une preuve exécutée sur le site local : `curl` (code HTTP + corps), payload rendu dans le HTML du front, effet mesuré en base, **avec son contrôle négatif** (même requête sans le payload, accueil en 200).
- Exercer le **chemin nominal, préconditions remplies** (utilisateur, nonce, dossier existant) avant de conclure qu'une garde tient ou cède.
- **Étalonner la sonde** sur un cas connu-positif avant de tirer une conclusion d'un silence.
- Toute écriture de test porte sur un **objet jetable créé pour le test** (post, fichier), jamais sur un contenu réel ; tout est supprimé à la fin et la suppression vérifiée.
- Sans preuve exécutable (API externe, production inaccessible) : **Piste non vérifiée**, avec la mesure qui trancherait.

### 5. Trier, chaîner, classer, rendre compte
Pour chaque candidat, le constat du rapport porte revendication, cause, déclencheur, impact, attaquant et preuve ; rejeter les rationalisations (« clairement critique », « le scanner l'a vu ») ; écarter ce qui exige déjà le droit visé (admin `unfiltered_html`). Puis chercher les **chaînes** (lecture de fichier + secret de `wp-config.php`) et les **variantes** d'un Confirmé dans le reste du code et dans les copies (`reference/classes-exploitees.md` §4).
Confirmé / Piste non vérifiée / Durcissement (méthode de la matrice partagée, §3). Gravité **uniquement** pour les Confirmés, par impact × atteignabilité (anonyme ? rôle requis ?). Pas de score inventé, pas de « sécurisé » global. Chaque Confirmé : `fichier:ligne`, flux, prérequis, impact, **preuve exécutée** (commande et résultat), correctif. Un chemin tracé de bout en bout mais non exécuté reste une Piste, avec la mesure qui la trancherait : c'est le seuil de la matrice partagée, le même pour wp-plugin-check. Lister explicitement les leurres écartés et le périmètre non revu.

### 6. Corriger (seulement sur demande)
1. Corriger la cause au niveau partagé (le helper qui colle l'URL, pas chaque appelant).
2. Rejouer la preuve : le déclenchement échoue désormais, le chemin nominal marche toujours.
3. **Critique indépendant sur le diff**, dans les cas du CLAUDE.md global : `cliff-stack:independent-critic`, avec le diff, la commande de preuve et le critère « la faille est fermée, et aucun appelant, contrat HTTP ni copie dans un autre projet ne casse ».
4. Supprimer un fichier vulnérable seulement après avoir prouvé l'absence d'appelant sur tout le périmètre (référence §9), puis vérifier le 404.

## Secrets
Jamais la valeur, nulle part (rapport, question, commit, nom de fichier). Forme **recopiée telle quelle depuis la sortie de `scripts/scan-exposure.sh`**, jamais reconstruite à la main : à partir de 16 caractères `fichier:ligne  abcd… (32 car., sha256:0123456789ab)` ; en dessous, la longueur seule, car préfixe et empreinte d'un secret court se retrouvent par force brute (mesuré : 8 caractères en 11 s). Toute autre sortie qui imprime des lignes source (inventaire de wp-plugin-check, `grep`, extrait de journal, réponse HTTP) passe par `perl scripts/mask-secrets.pl` avant d'être lue ou citée. L'empreinte sert à compter les projets qui partagent la même valeur **avant** de recommander une révocation, qui les casserait tous. Dire explicitement ce qu'un retrait dans le code change et ne change pas (ni le prestataire, ni les autres copies, ni l'historique git).

## Pièges clés
- **Contrat tiers** : un correctif de garde qui « refuse tout tant que rien n'est configuré » casse chaque crontab ou webhook déjà posé. Garder chemin, paramètre, valeur actuelle en repli, codes et corps de réponse. Ce repli EST un secret par défaut : l'écrire comme une dette (qui la solde, quand : nouvelle valeur par site, puis retrait du repli), jamais le présenter comme une fermeture.
- **`admin_init` et `is_admin()`** ne prouvent rien sur l'utilisateur : `admin-ajax.php` et `admin-post.php` déclenchent `admin_init` pour l'anonyme. Premier vecteur réellement exploité.
- **Coller une requête à tout lien** casse `tel:`, `mailto:`, les ancres et les paramètres lus dans le hash ; la requête va avant le `#`.
- **Journaux « exposés »** sans `curl` sur un fichier réel = Piste. Dossier protégé à la création seulement = dossier livré sans protection qui le reste.
- **Nonce ≠ autorisation** : un nonce imprimé pour les anonymes sur une action `nopriv` qui écrit ne protège rien.
- **Leurres** : `unserialize` avec `allowed_classes => false` sur une option admin, `sanitize_key` + allowlist, nonce imprimé via `esc_js` : les écarter nommément.

## Anti-patterns
Gravité sans preuve exécutée • secret recopié, même « pour mémoire » • leurre compté comme faille • correctif qui change un contrat HTTP appelé par un tiers • suppression d'un fichier sans recherche d'appelant hors du dossier courant • test destructif sur un contenu réel • « sécurisé » sur un périmètre partiel.
