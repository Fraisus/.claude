<?php
/**
 * Presets and global styles as THIS site serves them (core < theme < user Global Styles), read-only.
 *
 *   wp eval-file presets.php [out=<file.json>] [flat=<theme-flat.json>]
 *
 * out=   full report (default: stdout).
 * flat=  a minimal theme.json whose `settings` hold each offered preset family as a plain list,
 *        the shape Block Runner reads with `--token-resolver file --theme-json <file>` (its reader
 *        drops origin-keyed lists without a word).
 *
 * Report keys:
 *   presets            family => [{slug, name, value, origin, offered, css_var, fluid?}]
 *   served_not_offered family => slugs served as CSS variables but absent from the editor pickers
 *   free_values        every other core setting the site sets (setting => value): switches of free values and
 *                      of controls, default* toggles
 *   layout             the two global widths (contentSize, wideSize)
 *   viewport           media queries of the core per-screen channel (@mobile, @tablet), when core has it
 *   hover              blocks and elements that accept :hover in Global Styles (core constants)
 *   styles             merged Global Styles, flattened: root, elements, blocks (path => value)
 *   user_layer         what was changed in the admin (wp_global_styles post), flattened
 */

require_once __DIR__ . '/lib.php';

$opt      = wbmaq_args( $args ?? array() );
$settings = wp_get_global_settings();
$families = wbmaq_preset_families();

$presets            = array();
$served_not_offered = array();
$flat               = array( 'version' => 3, 'settings' => array() );

foreach ( $families as $key => $f ) {
	$items = wbmaq_presets_of( $settings, $f['path'], $f['default_toggle'] );
	if ( ! $items ) {
		continue;
	}
	$value_key = $f['value_key'] ?? 'size';
	$rows      = array();
	foreach ( $items as $slug => $p ) {
		$rows[] = array(
			'slug'    => $slug,
			'name'    => $p['name'] ?? '',
			'value'   => $p[ $value_key ] ?? ( $p['size'] ?? ( $p['colors'] ?? null ) ),
			'origin'  => $p['origin'],
			'offered' => $p['offered'],
			'css_var' => "--wp--preset--$key--$slug",
		);
		if ( isset( $p['fluid'] ) ) {
			$rows[ count( $rows ) - 1 ]['fluid'] = $p['fluid']; // false, or {min, max}: the range a mockup size falls in
		}
		if ( ! $p['offered'] ) {
			$served_not_offered[ $key ][] = $slug;
		}
	}
	$presets[ $key ] = $rows;

	$offered = array_values( array_map(
		function ( $p ) {
			unset( $p['origin'], $p['offered'] );
			return $p;
		},
		array_filter( $items, fn( $p ) => $p['offered'] )
	) );
	$parts = explode( '.', $f['path'] );
	$flat['settings'][ $parts[0] ][ $parts[1] ] = $offered;
}
$flat['settings']['layout'] = array_filter( array(
	'contentSize' => $settings['layout']['contentSize'] ?? null,
	'wideSize'    => $settings['layout']['wideSize'] ?? null,
) );

// Every core setting (WP_Theme_JSON::VALID_SETTINGS) but the preset lists and the two widths, reported above.
$free = array();
foreach ( array_diff( wbmaq_leaves( WP_Theme_JSON::VALID_SETTINGS ), array_column( $families, 'path' ), wbmaq_known()['admin_widths'] ) as $path ) {
	$v = wbmaq_get( $settings, $path );
	if ( $v !== null ) {
		$free[ $path ] = $v;
	}
}

$viewport = null;
if ( method_exists( 'WP_Theme_JSON', 'get_viewport_media_queries' ) ) {
	$viewport = WP_Theme_JSON::get_viewport_media_queries( $settings['viewport'] ?? null );
}

$hover = array();
foreach ( array( 'VALID_ELEMENT_PSEUDO_SELECTORS' => 'elements', 'VALID_BLOCK_PSEUDO_SELECTORS' => 'blocks' ) as $const => $label ) {
	if ( defined( "WP_Theme_JSON::$const" ) ) {
		$hover[ $label ] = constant( "WP_Theme_JSON::$const" );
	}
}

$styles     = wp_get_global_styles();
$flat_style = array(
	'root'     => array(),
	'elements' => array(),
	'blocks'   => array(),
);
foreach ( $styles as $k => $v ) {
	if ( $k === 'elements' || $k === 'blocks' ) {
		foreach ( (array) $v as $name => $sub ) {
			$flat_style[ $k ][ $name ] = wbmaq_flatten( $sub );
		}
	} else {
		$flat_style['root'] += wbmaq_flatten( $v, $k );
	}
}

$user      = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
// get_user_global_styles_post_id() would CREATE the post when missing: read without creating.
$user_post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false )['ID'] ?? null;
unset( $user['version'], $user['isGlobalStylesUserThemeJSON'] );

$report = array(
	'site'               => array(
		'wp_version'  => get_bloginfo( 'version' ),
		'theme'       => get_stylesheet(),
		'block_theme' => wp_is_block_theme(),
	),
	'presets'            => $presets,
	'served_not_offered' => $served_not_offered,
	'free_values'        => $free,
	'layout'             => $flat['settings']['layout'],
	'viewport'           => $viewport,
	'hover'              => $hover,
	'styles'             => $flat_style,
	'user_layer'         => array(
		'post_id' => $user_post,
		'values'  => wbmaq_flatten( $user ),
	),
);

if ( ! empty( $opt['flat'] ) ) {
	file_put_contents( $opt['flat'], wp_json_encode( $flat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	fwrite( STDOUT, "flat theme.json written: {$opt['flat']}\n" );
}
wbmaq_emit( $report, $opt['out'] ?? null );
