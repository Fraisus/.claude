---
name: wp-plugin-check
description: Gate "plugin vendable" — lance l'outil officiel WordPress Plugin Check (wp plugin check <slug>) sur un plugin WP et synthétise les violations (sécurité, i18n, perf, a11y, conformité repo WordPress.org). À utiliser AVANT de livrer/commiter un plugin destiné à être distribué ou vendu, ou quand on demande "vérifie/audite la conformité du plugin", "est-ce que le plugin passe Plugin Check", "prépare le plugin pour le repo". NE PAS utiliser en per-édition (l'outil scanne tout le plugin, ~10 s) — c'est un gate ponctuel, le lint per-fichier reste PHPCS. Ce skill SCANNE et synthétise les violations ; l'implémentation des correctifs (esc_*, sanitize, nonce, textdomain) revient à wp-native.
---

# wp-plugin-check — gate pré-ship pour plugin WP

Lance l'outil **officiel** WordPress.org `plugin-check` et transforme sa sortie en plan d'action. **Plugins uniquement** (jamais un thème : l'outil attend un plugin header + checks repo non pertinents pour un thème — pour le thème, c'est PHPCS/WPCS).

## Quand
Sur demande explicite, ou avant un commit/release d'un plugin distribuable. Pas automatique par édition (trop lent : ~10 s, scanne tout le plugin).

## Procédure
1. **Slug du plugin** : pris en argument si fourni ; sinon déduit du fichier en cours (`wp-content/plugins/<slug>/…`) ou demandé. Vérifier qu'il existe : `wp plugin list`.
2. **Découvrir l'invocation WP-CLI** du projet (project-agnostic) :
   - `wp` dans le PATH → l'utiliser ;
   - sinon binaire php + `wp-cli.phar` avec `--path=<racine WP>` (détecter la racine = dossier contenant `wp-load.php`). Découvrir l'emplacement réel de `php`/`wp-cli.phar` du poste (PATH, wrapper du CLAUDE.md projet, ou installation locale type MAMP/XAMPP/Laragon).
   - Le plugin `plugin-check` s'installe et s'active sur la copie d'essai du projet, jamais sur l'instance de l'utilisateur (`wp plugin install plugin-check --activate` si absent), et s'en retire à la fin.
3. **Lancer** : `wp plugin check <slug> --format=csv` (csv = parsable). Pour cibler : `--checks=<...>` ou `--categories=security,plugin_repo` ; sévérité via `--severity=<n>`.
4. **Synthèse** (dense) :
   - Regrouper par code de sniff, trier par gravité. Mettre en tête **Security** (EscapeOutput, ValidatedSanitizedInput, NonceVerification), **i18n** (MissingTranslatorsComment, textdomain), **PrefixAllGlobals**.
   - Pour chaque famille : fichier:ligne + fix idiomatique (cf. skill `wp-native` : `esc_*`, `wp_unslash`+`sanitize_*`, nonce/capability, `/* translators: */`, préfixe unique).
   - Distinguer **bloquant repo** (empêche l'accept WordPress.org) vs **best-practice**.
5. **Revue logique (ce que Plugin Check ne voit pas)** : ses sniffs sont syntaxiques (sortie échappée, nonce présent, requête préparée) ; ils ne prouvent ni l'autorisation par objet, ni un `permission_callback` adapté à chaque méthode, ni la SSRF, la désérialisation, les chemins inclus, le cron sans utilisateur, les paramètres REST non déclarés persistés. Cette revue passe par le skill **`wp-security-audit`**.
6. **Correctifs** : par le skill `wp-native` ; corriger la cause, jamais masquer le sniff.

## Garde-fous
- Thème détecté (`wp-content/themes/`) → refuser et rediriger vers PHPCS/WPCS.
- Sortie volumineuse → résumer, ne pas vomir le CSV brut.
- L'absence de violations « repo » ≠ plugin parfait : signaler les warnings perf/a11y restants, et ne jamais conclure « sécurisé » sans l'étape 5.
