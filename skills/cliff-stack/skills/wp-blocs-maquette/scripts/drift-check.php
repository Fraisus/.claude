<?php
/**
 * Drift check of the lists wbmaq_known() keeps by hand, against what core and the block registry expose
 * (read-only). Exit 1 with the novelties to review when WordPress changed something they depend on, else 0.
 *
 *   wp eval-file drift-check.php [reviewed=<x.y>] [out=<file.json>]
 *
 * reviewed=  core version the lists were last reviewed against (default: wbmaq_known() reviewed_core). An older
 *            version must make the check list what core added since: that is how the check is calibrated.
 *
 * Kinds of findings:
 *   core_changed  a core constant the scripts read has an @since entry newer than the review
 *   unhandled     something core or a registered block declares that no list classifies (new preset family,
 *                 style path without CSS property, block support key mapping to no setting nor style)
 *   vanished      a name a list holds that core no longer has
 * After review: update wbmaq_known() in lib.php, then its reviewed_core.
 */

require_once __DIR__ . '/lib.php';

$opt      = wbmaq_args( $args ?? array() );
$known    = wbmaq_known();
$reviewed = (string) ( $opt['reviewed'] ?? $known['reviewed_core'] );
$found    = array();
$add      = function ( string $kind, string $detail ) use ( &$found ) {
	$found[] = array( 'kind' => $kind, 'detail' => $detail );
};

// ---------- core constants changed since the review ----------
foreach ( array( 'PRESETS_METADATA', 'PROPERTIES_METADATA', 'INDIRECT_PROPERTIES_METADATA', 'VALID_TOP_LEVEL_KEYS', 'VALID_SETTINGS', 'VALID_STYLES', 'ELEMENTS' ) as $const ) {
	if ( ! defined( "WP_Theme_JSON::$const" ) ) {
		$add( 'vanished', "WP_Theme_JSON::$const" );
		continue;
	}
	$doc = (string) ( new ReflectionClassConstant( 'WP_Theme_JSON', $const ) )->getDocComment();
	foreach ( array_slice( preg_split( '/@since\s+/', $doc ), 1 ) as $entry ) {
		if ( ! preg_match( '/^(\d+\.\d+)(?:\.\d+)?\s*(.*)$/s', $entry, $m ) || ! version_compare( $m[1], $reviewed, '>' ) ) {
			continue;
		}
		$text = preg_replace( '/\s*\*\s*@.*$|\s*\*\/\s*$/s', '', $m[2] );
		$add( 'core_changed', "WP_Theme_JSON::$const, $m[1]: " . trim( preg_replace( '/\s*\n\s*\*?\s*/', ' ', $text ) ) );
	}
}

// ---------- preset families: each one classified ----------
$families   = wbmaq_preset_families();
$fam_keys   = array_keys( $families );
$fam_paths  = array_column( $families, 'path' );
$classified = array_merge( $known['admin_presets'], $known['code_presets'] );
foreach ( array_diff( $fam_paths, $classified ) as $p ) {
	$add( 'unhandled', "preset family $p: put it in admin_presets or code_presets" );
}
foreach ( array_diff( $classified, $fam_paths ) as $p ) {
	$add( 'vanished', "preset family $p (admin_presets or code_presets)" );
}
foreach ( array_diff( $fam_keys, array_keys( $known['free_switch'] ) ) as $f ) {
	$add( 'unhandled', "preset family $f: give it a free_switch entry" );
}
foreach ( array_diff( array_keys( $known['free_switch'] ), $fam_keys ) as $f ) {
	$add( 'vanished', "preset family $f (free_switch)" );
}
foreach ( array_diff( $known['value_families'], $fam_keys ) as $f ) {
	$add( 'vanished', "preset family $f (value_families)" );
}

// ---------- settings, styles and elements named by hand still exist ----------
$setting_leaves = wbmaq_leaves( WP_Theme_JSON::VALID_SETTINGS );
$style_leaves   = wbmaq_leaves( WP_Theme_JSON::VALID_STYLES );
$any_leaf       = array_merge( $setting_leaves, $style_leaves );
foreach ( array_merge( array_filter( $known['free_switch'], 'is_string' ), $known['admin_widths'] ) as $s ) {
	if ( ! in_array( $s, $setting_leaves, true ) ) {
		$add( 'vanished', "setting $s (free_switch or admin_widths)" );
	}
}
foreach ( $known['family_extra'] as $p => $f ) {
	if ( ! in_array( $p, $any_leaf, true ) || ! in_array( $f, $fam_keys, true ) ) {
		$add( 'vanished', "family_extra $p => $f" );
	}
}
foreach ( $known['slug_attrs'] as $attr => [ $f, $p ] ) {
	if ( ! in_array( $f, $fam_keys, true ) || ( $p !== null && ! in_array( $p, $style_leaves, true ) ) ) {
		$add( 'vanished', "slug_attrs $attr => $f, $p" );
	}
}
foreach ( $known['block_elements'] as $block => $e ) {
	if ( ! isset( WP_Theme_JSON::ELEMENTS[ sprintf( $e['element'], 1 ) ] ) ) {
		$add( 'vanished', "element {$e['element']} (block_elements $block)" );
	}
}
foreach ( array_intersect( $known['supports_without_setting'], $any_leaf ) as $p ) {
	$add( 'unhandled', "support $p is now a theme.json path: drop it from supports_without_setting" );
}

// ---------- style paths core does not tie to a CSS property ----------
$described = array();
foreach ( array_merge( array_values( WP_Theme_JSON::PROPERTIES_METADATA ), ...array_values( WP_Theme_JSON::INDIRECT_PROPERTIES_METADATA ) ) as $path ) {
	$described[] = implode( '.', $path );
}
foreach ( $style_leaves as $leaf ) {
	if ( $leaf === 'css' ) {
		continue; // custom CSS: refused by global-styles.php
	}
	$hit = array_filter( $described, fn( $d ) => $d === $leaf || str_starts_with( $d, "$leaf." ) );
	if ( ! $hit ) {
		$add( 'unhandled', "style $leaf has no CSS property in core metadata: does a preset family apply to it (family_extra)?" );
	}
}

// ---------- block supports the registered blocks declare ----------
$groups  = array_diff( array_unique( array_merge( array_keys( WP_Theme_JSON::VALID_STYLES ), array_keys( WP_Theme_JSON::VALID_SETTINGS ) ) ), array( 'layout' ) ); // inventory.php reports every layout key as is
$unknown = array();
foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
	$sup = (array) ( $type->supports ?? array() );
	if ( isset( $sup['__experimentalBorder'] ) && ! isset( $sup['border'] ) ) {
		$sup['border'] = $sup['__experimentalBorder'];
	}
	foreach ( $groups as $group ) {
		if ( ! isset( $sup[ $group ] ) || ! is_array( $sup[ $group ] ) || array_is_list( $sup[ $group ] ) || in_array( $group, $any_leaf, true ) ) {
			continue;
		}
		foreach ( array_keys( $sup[ $group ] ) as $key ) {
			$path = $group . '.' . wbmaq_support_key( (string) $key );
			if ( in_array( $path, $any_leaf, true ) || in_array( $path, $fam_paths, true ) || in_array( $path, $known['supports_without_setting'], true ) || in_array( $key, $known['support_flags'], true ) ) {
				continue;
			}
			$unknown[ $path ][] = $name;
		}
	}
}
foreach ( $unknown as $path => $blocks ) {
	$add( 'unhandled', "block support $path (" . implode( ', ', array_slice( $blocks, 0, 5 ) ) . ( count( $blocks ) > 5 ? ', ...' : '' ) . '): no theme.json setting nor style; add it to supports_without_setting once its control is known' );
}

wbmaq_emit(
	array(
		'wp_version' => get_bloginfo( 'version' ),
		'reviewed'   => $reviewed,
		'verdict'    => $found ? 'DRIFT: review wbmaq_known() in lib.php' : 'no drift',
		'findings'   => $found,
	),
	$opt['out'] ?? null
);
exit( $found ? 1 : 0 );
