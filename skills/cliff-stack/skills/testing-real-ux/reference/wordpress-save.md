# Vrai enregistrement dans l'éditeur WordPress

## Pourquoi un vrai clic
Certains effets ne se produisent qu'à l'enregistrement fait depuis l'éditeur (clic sur le bouton d'enregistrement ou raccourci clavier) : hooks `rest_after_insert_{type}`, filtres JS `editor.preSavePost`, validation REST, méta posées par l'éditeur, cache tenu par un plugin. Un enregistrement programmatique (`wp post update`, `wp.data.dispatch("core/editor").savePost()`) passe à côté et donne un faux négatif. Le parcours enregistre donc par un vrai geste, joué par `ux.mjs`.

## Où
Toujours sur la copie d'essai du site (cible `web` de l'adaptateur, serveur lancé par la `commande` du projet sur 127.0.0.1, port tiré par le système, base d'essai) : le compte d'administration et le post d'essai vivent dans cette base et partent avec elle à la fin du passage. Jamais un compte ni un post créé sur le site ou la base de l'utilisateur.

## Le parcours
1. Connexion : le parcours remplit l'écran de connexion par ses libellés (`getByLabel`), avec le compte de la base d'essai ; déjà connecté, l'écran n'apparaît pas.
2. Éditeur ouvert sur le post d'essai ; une fenêtre d'accueil se ferme comme l'utilisateur (Échap ou son bouton), jamais contournée.
3. Rendre l'éditeur « modifié » par un vrai changement : l'éditeur moderne vit dans l'iframe `editor-canvas` (`page.frameLocator('iframe[name="editor-canvas"]')`) ; changer le titre pour une valeur différente de l'actuelle (un espace puis un retour arrière ne marque pas toujours un post publié comme modifié, et l'enregistrement serait ignoré). Un attribut de bloc se change par son vrai contrôle (barre d'outils, inspecteur), jamais par `setAttributes` ; seuls les contrôles présents dans le bloc ciblé existent, aucun réglage inventé.
4. Enregistrer par le bouton trouvé à l'exécution par son rôle et son libellé, sans figer la langue : lire la langue du site (`wp option get WPLANG` sur la copie, ou l'attribut `lang` de la page) et prendre le libellé que l'éditeur affiche, par exemple `getByRole("button", { name: /^(Enregistrer|Mettre à jour|Save|Update)$/ })` construit depuis ce qui est lu, jamais par la classe CSS du bouton, qui change d'une version à l'autre.
5. Vérifier que l'enregistrement a eu lieu avant de juger son effet : `post_modified` du post (`wp eval 'echo get_post(<ID>)->post_modified;'` sur la copie) doit avoir changé. Inchangé, rien n'a été enregistré : refaire l'étape 3 avec un vrai changement, ne jamais conclure que le correctif échoue.

## Prouver l'effet
Lire l'état avant (contenu, méta visée, artefact serveur du plugin) puis après, par le contenu réellement visé ; le front réel rend l'état attendu. Contre-épreuve : le même changement par `wp post update` ne produit pas l'effet, ce qui confirme que le déclencheur est bien l'enregistrement depuis l'éditeur.
