<?php
/**
 * Shared helpers of the wp-blocs-maquette scripts (read-only). Loaded by every script through
 * `require_once __DIR__ . '/lib.php'`.
 *
 * Arguments: `wp eval-file` refuses `--flags`, so every option is a positional `key=value` token.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through: wp eval-file <script.php> [key=value ...]\n" );
	exit( 1 );
}

/** Positional `key=value` tokens to an array; a bare token goes to the list under key 0. */
function wbmaq_args( array $args ): array {
	$out = array( 0 => array() );
	foreach ( $args as $a ) {
		$a = ltrim( (string) $a, '-' );
		if ( preg_match( '/^([a-z_-]+)=(.*)$/i', $a, $m ) ) {
			$out[ strtolower( $m[1] ) ] = $m[2];
		} else {
			$out[0][] = $a;
		}
	}
	return $out;
}

/** JSON to a file (when `out=` is given) or to stdout. */
function wbmaq_emit( $data, ?string $file ): void {
	$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( $file ) {
		file_put_contents( $file, $json . "\n" );
		fwrite( STDOUT, "written: $file (" . strlen( $json ) . " bytes)\n" );
	} else {
		echo $json, "\n";
	}
}

/** Nested array to `a.b.c => scalar` (lists kept whole, as JSON). */
function wbmaq_flatten( $value, string $prefix = '', array &$out = array() ): array {
	if ( is_array( $value ) && $value !== array() && ! array_is_list( $value ) ) {
		foreach ( $value as $k => $v ) {
			wbmaq_flatten( $v, $prefix === '' ? (string) $k : "$prefix.$k", $out );
		}
	} elseif ( $prefix !== '' ) {
		$out[ $prefix ] = $value;
	}
	return $out;
}

function wbmaq_get( $array, string $path ) {
	foreach ( explode( '.', $path ) as $k ) {
		if ( ! is_array( $array ) || ! array_key_exists( $k, $array ) ) {
			return null;
		}
		$array = $array[ $k ];
	}
	return $array;
}

/** Recursive merge where the right side wins and lists are replaced, not appended. */
function wbmaq_merge( array $base, array $over ): array {
	foreach ( $over as $k => $v ) {
		$base[ $k ] = ( is_array( $v ) && ! array_is_list( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! array_is_list( $base[ $k ] ) )
			? wbmaq_merge( $base[ $k ], $v )
			: $v;
	}
	return $base;
}

/** Dotted leaf paths of a core schema constant (VALID_SETTINGS, VALID_STYLES). */
function wbmaq_leaves( array $schema, string $prefix = '' ): array {
	$out = array();
	foreach ( $schema as $k => $v ) {
		$p   = $prefix === '' ? (string) $k : "$prefix.$k";
		$out = array_merge( $out, is_array( $v ) && $v !== array() && ! array_is_list( $v ) ? wbmaq_leaves( $v, $p ) : array( $p ) );
	}
	return $out;
}

/**
 * What core does not expose at runtime: admin screens and editor controls. drift-check.php compares every entry
 * with the core constants and the block registry, and lists what core added or removed since `reviewed_core`.
 */
function wbmaq_known(): array {
	return array(
		'reviewed_core'            => '7.1',
		// Preset families an administrator edits in Appearance > Editor > Styles; the others change in code only.
		'admin_presets'            => array( 'color.palette', 'color.gradients', 'typography.fontSizes', 'typography.fontFamilies', 'shadow.presets' ),
		'code_presets'             => array( 'color.duotone', 'spacing.spacingSizes', 'border.radiusSizes', 'dimensions.aspectRatios', 'dimensions.dimensionSizes' ),
		'admin_widths'             => array( 'layout.contentSize', 'layout.wideSize' ),
		// Preset families whose existing values allow_modify=1 may change (the kit applied to the whole site); every
		// other family is a rung: the value picks a neighbour or adds one, never moves.
		'modifiable_presets'       => array( 'color.palette' ),
		// Per family: the setting that lets an author type a value outside the presets, else the fixed answer of
		// the editor control (true always, false never, null not established).
		'free_switch'              => array(
			'color'         => 'color.custom',
			'gradient'      => 'color.customGradient',
			'duotone'       => 'color.customDuotone',
			'font-size'     => 'typography.customFontSize',
			'font-family'   => false,
			'spacing'       => 'spacing.customSpacingSize',
			'shadow'        => false,
			'border-radius' => true,
			'dimension'     => true,
			'aspect-ratio'  => null,
		),
		// Families whose editor control stores the picked preset's VALUE, not var:preset|family|slug.
		'value_families'           => array( 'aspect-ratio' ),
		// Values a family's control offers besides its presets (the aspect-ratio control's « Original » stores auto).
		'control_values'           => array( 'aspect-ratio' => array( 'auto' ) ),
		// Keys inside a support group that are editor flags, not settings (an unknown __experimental* key is a novelty).
		'support_flags'            => array( '__experimentalSkipSerialization', '__experimentalDefaultControls', '__experimentalSelector' ),
		// Style or setting paths whose control picks a family's presets, the preset metadata not listing the property.
		'family_extra'             => array(
			'spacing.blockGap'    => 'spacing',
			'background.gradient' => 'gradient',
			'color.link'          => 'color',
			'color.heading'       => 'color',
			'color.button'        => 'color',
			'color.caption'       => 'color',
		),
		// Block attributes created by core supports that hold a bare preset slug: attribute => [family, style path].
		'slug_attrs'               => array(
			'backgroundColor' => array( 'color', 'color.background' ),
			'textColor'       => array( 'color', 'color.text' ),
			'borderColor'     => array( 'color', 'border.color' ),
			'overlayColor'    => array( 'color', null ),
			'gradient'        => array( 'gradient', 'color.gradient' ),
			'fontSize'        => array( 'font-size', 'typography.fontSize' ),
			'fontFamily'      => array( 'font-family', 'typography.fontFamily' ),
		),
		// Blocks whose Global Styles default also comes from an element (%d: the value of the block attribute `attr`).
		'block_elements'           => array(
			'core/heading' => array( 'element' => 'h%d', 'attr' => 'level' ),
			'core/button'  => array( 'element' => 'button' ),
		),
		// Block support keys with no theme.json setting nor style (editor-only control or flag).
		'supports_without_setting' => array( 'typography.fitText', 'color.enableContrastChecker' ),
	);
}

/** Support key as core reads it: `__experimentalFontFamily` is `fontFamily`. */
function wbmaq_support_key( string $key ): string {
	return preg_match( '/^__experimental([A-Z]\w*)$/', $key, $m ) ? lcfirst( $m[1] ) : $key;
}

/**
 * Preset families as core declares them (WP_Theme_JSON::PRESETS_METADATA), so no family is hard-coded:
 * [ css family key (as in var:preset|<key>|slug) => [ path, default_toggle, value_key, properties (CSS) ] ].
 */
function wbmaq_preset_families(): array {
	static $out = null;
	if ( $out !== null ) {
		return $out;
	}
	$out = array();
	foreach ( WP_Theme_JSON::PRESETS_METADATA as $m ) {
		$path = implode( '.', $m['path'] );
		$key  = null;
		if ( ! empty( $m['css_vars'] ) && preg_match( '/^--wp--preset--([a-z0-9-]+)--\$slug$/', $m['css_vars'], $mm ) ) {
			$key = $mm[1];
		} elseif ( preg_match( '/\.([a-zA-Z]+)$/', $path, $mm ) ) {
			$key = $mm[1]; // family without a CSS variable (duotone): keyed by its settings leaf
		}
		$toggle      = isset( $m['prevent_override'] ) && is_array( $m['prevent_override'] ) ? implode( '.', $m['prevent_override'] ) : null;
		$out[ $key ] = array( 'path' => $path, 'default_toggle' => $toggle, 'value_key' => $m['value_key'] ?? null, 'properties' => $m['properties'] ?? array() );
	}
	return $out;
}

/**
 * Settings as the editor applies them to one block: global settings, then settings.blocks[name]. A preset list the
 * block declares replaces the global one in its pickers (the editor reads the block path before the global one).
 */
function wbmaq_block_settings( array $global, string $block ): array {
	$own = $global['blocks'][ $block ] ?? array();
	$g   = $global;
	unset( $g['blocks'] );
	if ( ! is_array( $own ) ) {
		return $g;
	}
	$out = wbmaq_merge( $g, $own );
	foreach ( wbmaq_preset_families() as $f ) {
		$list = wbmaq_get( $own, $f['path'] );
		if ( is_array( $list ) ) {
			_wp_array_set( $out, explode( '.', $f['path'] ), $list );
		}
	}
	return $out;
}

/**
 * Every preset of a family with its origin, and whether the editor offers it. Core serves the
 * `default` origin as CSS variables even when the theme hides it from the pickers (default* = false),
 * so "served" and "offered" are two different sets.
 */
function wbmaq_presets_of( array $settings, string $path, ?string $default_toggle ): array {
	$by_origin = wbmaq_get( $settings, $path );
	if ( ! is_array( $by_origin ) ) {
		return array();
	}
	if ( array_is_list( $by_origin ) ) {
		$by_origin = array( 'theme' => $by_origin );
	}
	$default_offered = $default_toggle ? ( wbmaq_get( $settings, $default_toggle ) !== false ) : true;
	$out             = array();
	foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
		foreach ( (array) ( $by_origin[ $origin ] ?? array() ) as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['slug'] ) ) {
				continue;
			}
			$p['origin']  = $origin;
			$p['offered'] = ( $origin !== 'default' ) || $default_offered;
			$out[ $p['slug'] ] = $p; // a later origin with the same slug replaces the earlier one
		}
	}
	return $out;
}

/**
 * Served slugs per family key, plus the offered subset. With a block: what the editor offers inside that block
 * (its own lists replace the global ones), served including the root presets, still CSS variables inside it.
 */
function wbmaq_preset_index( array $settings, ?string $block = null ): array {
	$eff = $block ? wbmaq_block_settings( $settings, $block ) : $settings;
	$idx = array();
	foreach ( wbmaq_preset_families() as $key => $f ) {
		$all    = wbmaq_presets_of( $eff, $f['path'], $f['default_toggle'] );
		$served = array_keys( $all );
		if ( $block ) {
			$served = array_values( array_unique( array_merge( $served, array_keys( wbmaq_presets_of( $settings, $f['path'], $f['default_toggle'] ) ) ) ) );
		}
		$idx[ $key ] = array(
			'path'      => $f['path'],
			'value_key' => $f['value_key'],
			'served'    => $served,
			'offered'   => array_keys( array_filter( $all, fn( $p ) => $p['offered'] ) ),
			'items'     => $all,
		);
	}
	return $idx;
}

/** wbmaq_preset_index() per block, cached; blocks without settings of their own share the global index. */
function wbmaq_index_by_block( array $settings ): Closure {
	$cache = array();
	return function ( string $block = '' ) use ( &$cache, $settings ): array {
		$key = isset( $settings['blocks'][ $block ] ) ? $block : '';
		return $cache[ $key ] ??= wbmaq_preset_index( $settings, $key === '' ? null : $key );
	};
}

/** True for a value an author typed outside the family's presets; zero, empty and a value the family's control itself offers are not. */
function wbmaq_is_free_value( ?string $family, $value ): bool {
	if ( ! is_scalar( $value ) || $value === '' || preg_match( '/^0(px|rem|em|%)?$/', trim( (string) $value ) ) ) {
		return false;
	}
	return ! in_array( trim( (string) $value ), wbmaq_known()['control_values'][ $family ] ?? array(), true );
}

/** For a family whose editor control stores the preset value (value_families): the slug holding this value, else null. */
function wbmaq_preset_by_value( ?string $family, $value, array $index ): ?string {
	if ( ! $family || ! is_scalar( $value ) || empty( $index[ $family ] ) || ! in_array( $family, wbmaq_known()['value_families'], true ) ) {
		return null;
	}
	$key  = $index[ $family ]['value_key'];
	$norm = fn( $v ) => preg_replace( '/\s+/', '', (string) $v );
	foreach ( $index[ $family ]['items'] as $slug => $p ) {
		if ( isset( $p[ $key ] ) && $norm( $p[ $key ] ) === $norm( $value ) ) {
			return (string) $slug;
		}
	}
	return null;
}

/** `var:preset|family|slug` or `var(--wp--preset--family--slug)` to [family, slug], else null. */
function wbmaq_preset_ref( $value ): ?array {
	if ( ! is_string( $value ) ) {
		return null;
	}
	if ( preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9_-]+)$/i', trim( $value ), $m ) ) {
		return array( $m[1], $m[2] );
	}
	if ( preg_match( '/^var\(\s*--wp--preset--([a-z0-9-]+?)--([a-z0-9_-]+)\s*\)$/i', trim( $value ), $m ) ) {
		return array( $m[1], $m[2] );
	}
	return null;
}

/** Same value written two ways (`var:preset|x|y` and `var(--wp--preset--x--y)`) compares equal. */
function wbmaq_norm( $value ): string {
	$ref = wbmaq_preset_ref( $value );
	return $ref ? 'preset:' . $ref[0] . ':' . $ref[1] : ( is_scalar( $value ) ? trim( (string) $value ) : wp_json_encode( $value ) );
}

/**
 * Style path (styles.* or block style attribute, flattened) to its preset family key, or null. Derived from core:
 * each family's CSS properties (PRESETS_METADATA) mapped to style paths (PROPERTIES_METADATA), a side or corner
 * property (`padding-top`, `border-top-left-radius`) following its shorthand; then wbmaq_known() family_extra.
 */
function wbmaq_family_of( string $path ): ?string {
	static $map = null;
	if ( $map === null ) {
		$by_css = array();
		foreach ( wbmaq_preset_families() as $key => $f ) {
			foreach ( $f['properties'] as $prop ) {
				$by_css[ $prop ] = $key;
			}
		}
		$map = array();
		foreach ( WP_Theme_JSON::PROPERTIES_METADATA as $prop => $p ) {
			$short = preg_replace( '/-(?:top|right|bottom|left)(?:-(?:left|right))?(?=-|$)/', '', $prop, 1 );
			if ( isset( $by_css[ $short ] ) ) {
				$map[ implode( '.', $p ) ] = $by_css[ $short ];
			}
		}
		$map += wbmaq_known()['family_extra'];
	}
	$p = preg_replace( '/^(?:blocks\.[a-z0-9-]+\/[a-z0-9-]+\.)?(?:variations\.[a-z0-9_-]+\.)?(?:[:@][a-z-]+\.)?(?:elements\.[a-z0-9]+\.)?(?:[:@][a-z-]+\.)?/i', '', $path );
	while ( $p !== '' ) {
		if ( isset( $map[ $p ] ) ) {
			return $map[ $p ];
		}
		$cut = strrpos( $p, '.' );
		$p   = $cut === false ? '' : substr( $p, 0, $cut );
	}
	return null;
}
