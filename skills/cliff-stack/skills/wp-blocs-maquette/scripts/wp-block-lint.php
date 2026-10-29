<?php
/**
 * wp-block-lint.php — Validateur de markup de blocs Gutenberg (générique).
 *
 * S'exécute dans le contexte WordPress complet :
 *   wp eval-file <ce skill>/scripts/wp-block-lint.php <fichier.html>
 *   wp eval-file <ce skill>/scripts/wp-block-lint.php stdin     (markup sur stdin)
 *   wp eval-file <ce skill>/scripts/wp-block-lint.php selftest   (suite de tests unitaires)
 *
 * Source de vérité = le REGISTRE de blocs du site (core + blocs custom du thème/plugins).
 * Aucune liste de blocs codée en dur : ce qui est valide = ce que CE WordPress connaît.
 *
 * Stratégie de validation (double filet, prouvé nécessaire) :
 *   1. parse_blocks() -> serialize_blocks() doit ROUND-TRIP à l'identique
 *      (détecte : commentaire fermant manquant, JSON d'attribut malformé).
 *   2. Chaque blockName nommé doit être ENREGISTRÉ dans WP_Block_Type_Registry
 *      (détecte : bloc inexistant — que le round-trip seul ne voit pas).
 *   3. Chaque attribut fourni doit exister dans le block.json du bloc, type cohérent
 *      (avertissement, non bloquant : un attribut inconnu est ignoré par WP sans casse).
 *
 * Sortie : JSON { valid: bool, errors: [], warnings: [], stats: {} } + exit code
 *   exit 0 = valide (éventuels warnings), exit 1 = invalide (erreurs bloquantes).
 *
 * Validateur neutre et universel (préfixe wblint_) : aucune dépendance à un thème,
 * plugin ou nom de bloc précis. La source de vérité est le registre vivant du site.
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "Doit être lancé via wp eval-file.\n"); exit(1); }

/**
 * Valide un markup de blocs. Retourne un rapport structuré.
 *
 * @param string $markup Markup de blocs Gutenberg sérialisé.
 * @return array{valid:bool,errors:array,warnings:array,stats:array}
 */
function wblint_validate(string $markup): array {
    $errors   = [];
    $warnings = [];

    $markup_trim = trim($markup);
    if ($markup_trim === '') {
        return ['valid' => false, 'errors' => ['Markup vide.'], 'warnings' => [], 'stats' => []];
    }

    // --- Filet 1 : round-trip parse/serialize -------------------------------
    $blocks      = parse_blocks($markup);
    $reserialize = serialize_blocks($blocks);
    $roundtrip   = (trim($reserialize) === $markup_trim);

    if (!$roundtrip) {
        $errors[] = "Round-trip échoué : le markup ne se re-sérialise pas à l'identique "
                  . "(commentaire de bloc non appairé, ou JSON d'attribut malformé).";
    }

    // --- Collecte récursive des blocs nommés --------------------------------
    $named = [];
    $walk = function ($list) use (&$walk, &$named) {
        foreach ($list as $b) {
            if (!empty($b['blockName'])) { $named[] = $b; }
            if (!empty($b['innerBlocks'])) { $walk($b['innerBlocks']); }
        }
    };
    $walk($blocks);

    if (count($named) === 0) {
        $warnings[] = "Aucun bloc nommé détecté (markup = HTML brut / classic ?).";
    }

    // --- Filet 2 + 3 : registre & attributs ---------------------------------
    $registry = WP_Block_Type_Registry::get_instance();
    $unknown  = [];

    foreach ($named as $b) {
        $name = $b['blockName'];
        $type = $registry->get_registered($name);

        if (!$type) {
            $unknown[$name] = true;
            continue; // inutile de valider les attributs d'un bloc inconnu
        }

        // Filet 3 : attributs déclarés dans le block.json ?
        $declared = is_array($type->attributes) ? $type->attributes : [];
        foreach ((array) $b['attrs'] as $attr_key => $attr_val) {
            if (!array_key_exists($attr_key, $declared)) {
                $warnings[] = "Bloc '$name' : attribut '$attr_key' non déclaré dans block.json (sera ignoré par WP).";
                continue;
            }
            // Vérif de type légère (string/number/boolean/array/object)
            $expected = $declared[$attr_key]['type'] ?? null;
            if ($expected && !wblint_type_matches($expected, $attr_val)) {
                $warnings[] = "Bloc '$name' : attribut '$attr_key' attendu de type '$expected', "
                            . "reçu '" . gettype($attr_val) . "'.";
            }
        }
    }

    if ($unknown) {
        $errors[] = "Bloc(s) non enregistré(s) sur ce site : " . implode(', ', array_keys($unknown))
                  . ". Vérifie le nom (faute de frappe ?) ou que le thème/plugin est actif.";
    }

    // --- Filet 4 : une boucle personnalisée sans nombre par page ne pagine pas --------------
    // Le cœur n'applique ni décalage ni posts_per_page quand query.perPage n'est pas numérique
    // (build_query_vars_from_query_block) : la page 2 rejoue la page 1. L'éditeur ne produit
    // jamais cet état, une écriture par script si.
    foreach ($named as $b) {
        $q = $b['attrs']['query'] ?? null;
        if ('core/query' !== $b['blockName'] || !is_array($q) || !empty($q['inherit'])) continue;
        if (!isset($q['perPage']) || !is_numeric($q['perPage'])) {
            $errors[] = "Bloc 'core/query' (queryId " . ($b['attrs']['queryId'] ?? '?') . ") : query.perPage "
                      . "doit être un nombre, sinon sa pagination rejoue la première page.";
        }
    }

    return [
        'valid'    => count($errors) === 0,
        'errors'   => $errors,
        'warnings' => $warnings,
        'stats'    => [
            'blocks_named' => count($named),
            'roundtrip'    => $roundtrip,
            'unknown'      => array_keys($unknown),
        ],
    ];
}

/**
 * Vérifie qu'une valeur correspond au type déclaré dans un block.json.
 * Tolérant : WP accepte plusieurs formes ; on ne signale que les vraies incohérences.
 */
function wblint_type_matches(string $expected, $value): bool {
    switch ($expected) {
        case 'string':  return is_string($value);
        case 'boolean': return is_bool($value);
        case 'number':
        case 'integer': return is_int($value) || is_float($value);
        case 'array':   return is_array($value);
        case 'object':  return is_array($value) || is_object($value); // JSON object -> array assoc en PHP
        case 'null':    return is_null($value);
        default:        return true; // type inconnu/union : on ne bloque pas
    }
}

/**
 * Découvre dynamiquement le 1er bloc NON-core enregistré sur ce site (préfixe ≠ « core/ »).
 * Universel : ne présume aucun nom/préfixe de projet. Retourne null si le site n'en a aucun.
 */
function wblint_first_non_core_block(): ?string {
    $all = WP_Block_Type_Registry::get_instance()->get_all_registered();
    foreach (array_keys($all) as $name) {
        if (strpos($name, 'core/') !== 0) { return $name; }
    }
    return null;
}

/**
 * Suite de tests unitaires intégrée (auto-vérification du validateur lui-même).
 * @return bool true si tous les cas passent.
 */
function wblint_selftest(): bool {
    // Nom neutre, syntaxiquement valide mais garanti non enregistré (ne présume aucun préfixe de projet).
    $fake = 'acme/inexistant';

    $cases = [
        // [label, markup, attendu_valide]
        ['core valide',        "<!-- wp:paragraph -->\n<p>Bonjour</p>\n<!-- /wp:paragraph -->", true],
        ['fermeture manquante',"<!-- wp:paragraph -->\n<p>Bonjour</p>", false],
        ['json invalide',      "<!-- wp:paragraph {\"dropCap\":tru} -->\n<p>x</p>\n<!-- /wp:paragraph -->", false],
        ['bloc inexistant',    "<!-- wp:$fake -->\n<div></div>\n<!-- /wp:$fake -->", false],
        ['heading valide',     "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">T</h2>\n<!-- /wp:heading -->", true],
        ['vide',               "   ", false],
        ['boucle sans perPage', "<!-- wp:query {\"queryId\":1,\"query\":{\"postType\":\"page\",\"perPage\":null}} -->\n<div class=\"wp-block-query\"></div>\n<!-- /wp:query -->", false],
        ['boucle avec perPage', "<!-- wp:query {\"queryId\":1,\"query\":{\"postType\":\"page\",\"perPage\":3}} -->\n<div class=\"wp-block-query\"></div>\n<!-- /wp:query -->", true],
        ['boucle héritée',      "<!-- wp:query {\"queryId\":1,\"query\":{\"inherit\":true}} -->\n<div class=\"wp-block-query\"></div>\n<!-- /wp:query -->", true],
    ];

    // Cas universel « bloc custom réel » : on découvre au runtime un bloc non-core
    // enregistré sur CE site (quel qu'il soit). Si le site n'en a aucun, on saute.
    $custom = wblint_first_non_core_block();
    if ($custom) {
        $cases[] = ["bloc custom réel ($custom)", "<!-- wp:$custom /-->", true];
    }

    $all_ok = true;
    foreach ($cases as [$label, $markup, $want]) {
        $r = wblint_validate($markup);
        $ok = ($r['valid'] === $want);
        $all_ok = $all_ok && $ok;
        printf("[%s] %-22s valid=%s (attendu %s)\n",
            $ok ? 'PASS' : 'FAIL', $label,
            $r['valid'] ? 'true' : 'false', $want ? 'true' : 'false');
        if (!$ok) { echo "       errors: " . implode(' | ', $r['errors']) . "\n"; }
    }
    return $all_ok;
}

// --- Entrée CLI -------------------------------------------------------------
// wp eval-file expose les arguments positionnels dans $args (sans les tirets : « selftest », « stdin »).
$args = $args ?? [];
$mode = ltrim($args[0] ?? '', '-');   // tolère « selftest » comme « --selftest »

if ($mode === 'selftest') {
    $ok = wblint_selftest();
    echo $ok ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHEC : au moins un test a échoué\n";
    exit($ok ? 0 : 1);
}

if ($mode === 'stdin') {
    $markup = stream_get_contents(STDIN);
} elseif ($mode !== '' && file_exists($args[0])) {
    $markup = file_get_contents($args[0]);
} else {
    fwrite(STDERR, "Usage: wp eval-file wp-block-lint.php <fichier.html | stdin | selftest>\n");
    exit(1);
}

$report = wblint_validate($markup);
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($report['valid'] ? 0 : 1);
