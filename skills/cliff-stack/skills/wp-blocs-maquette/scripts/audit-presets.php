<?php
/**
 * "Presets only" audit of block content (read-only): every style value a block carries is checked
 * against the presets THIS site serves and against its Global Styles defaults.
 *
 *   wp eval-file audit-presets.php <source> [out=<file.json>] [min=3]
 *
 * <source>  a post ID, several IDs separated by commas, `type:<post_type>` (all published posts of
 *           that type), or a path to a file of block markup.
 * min=      how many identical (block, property, value) occurrences make a repetition (default 3).
 *
 * Findings:
 *   hard_values       a free value where the family has presets the author could have picked
 *                     (frozen in the block: changing the scale later will not reach it)
 *   no_preset_family  free values in a family without any preset (line height, letter spacing...):
 *                     legitimate, listed for the decision "declare a preset or a default"
 *   dead_presets      a preset slug the site no longer serves (renders nothing)
 *   hidden_presets    a preset served but not offered in the editor (the author cannot pick it again)
 *   redundant         a value equal to the Global Styles default of this block or element (remove it)
 *   repetitions       (block, property, value) repeated >= min times: candidate block default in
 *                     Global Styles (most instances) or a style variation / pattern (a subset)
 *   per_screen        values in the core per-screen states (style["@tablet"], style["@mobile"])
 *   classes           additional CSS classes (typed by hand, or written by an editor extension): a
 *                     dependency on a stylesheet, not on a setting; find their origin before judging
 *   unknown_attrs     attributes the server registry does not declare for the block: declared in
 *                     editor JS only, or left by a removed feature; prove which in the editor
 */

require_once __DIR__ . '/lib.php';

$opt = wbmaq_args( $args ?? array() );
$src = $opt[0][0] ?? '';
$min = max( 2, (int) ( $opt['min'] ?? 3 ) );
if ( $src === '' ) {
	fwrite( STDERR, "Usage: wp eval-file audit-presets.php <id[,id...] | type:<post_type> | file.html> [out=<file>] [min=3]\n" );
	exit( 1 );
}

// ---------- the content to audit ----------
$docs = array();
if ( is_file( $src ) ) {
	$docs[ 'file:' . basename( $src ) ] = (string) file_get_contents( $src );
} elseif ( str_starts_with( $src, 'type:' ) ) {
	foreach ( get_posts( array( 'post_type' => substr( $src, 5 ), 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
		$docs[ $p->ID ] = $p->post_content;
	}
} else {
	foreach ( array_filter( array_map( 'intval', explode( ',', $src ) ) ) as $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			fwrite( STDERR, "No post $id\n" );
			exit( 1 );
		}
		$docs[ $id ] = $p->post_content;
	}
}
if ( ! $docs ) {
	fwrite( STDERR, "Nothing to audit for: $src\n" );
	exit( 1 );
}

// ---------- what the site serves ----------
$settings = wp_get_global_settings();
$styles   = wp_get_global_styles();
$registry = WP_Block_Type_Registry::get_instance();
$known    = wbmaq_known();
$groups   = array_diff( array_keys( WP_Theme_JSON::VALID_STYLES ), array( 'css' ) );

$index_of = wbmaq_index_by_block( $settings );

// Global Styles default for a block (block, then its element), flattened.
$defaults_for = function ( string $name, array $attrs ) use ( $styles, $known, $registry ): array {
	$d  = wbmaq_flatten( $styles['blocks'][ $name ] ?? array() );
	$el = $known['block_elements'][ $name ] ?? null;
	if ( $el ) {
		$attr = $el['attr'] ?? null;
		$v    = $attr ? ( $attrs[ $attr ] ?? $registry->get_registered( $name )->attributes[ $attr ]['default'] ?? null ) : null;
		$d   += wbmaq_flatten( $styles['elements'][ sprintf( $el['element'], (int) $v ) ] ?? array() );
	}
	return $d;
};

$found = array(
	'hard_values'      => array(),
	'no_preset_family' => array(),
	'dead_presets'     => array(),
	'hidden_presets'   => array(),
	'redundant'        => array(),
	'per_screen'       => array(),
	'classes'          => array(),
	'unknown_attrs'    => array(),
);
$rep    = array();
$counts = array();
$total  = 0;

$check_value = function ( $doc, string $name, string $path, $value, string $screen ) use ( &$found, $index_of, $groups ) {
	$where = array( 'post' => $doc, 'block' => $name, 'prop' => $path, 'value' => $value );
	if ( $screen !== '' ) {
		$where['screen'] = $screen;
	}
	$index = $index_of( $name );
	$fam   = wbmaq_family_of( $path );
	$ref   = wbmaq_preset_ref( $value );
	$slug  = $ref ? null : wbmaq_preset_by_value( $fam, $value, $index );
	if ( $slug !== null ) {
		$ref = array( $fam, $slug ); // the editor stores this family's preset as its value
	}
	if ( $ref ) {
		[ $fam, $slug ] = $ref;
		if ( isset( $index[ $fam ] ) && ! in_array( $slug, $index[ $fam ]['served'], false ) ) {
			$found['dead_presets'][] = $where;
		} elseif ( isset( $index[ $fam ] ) && ! in_array( $slug, $index[ $fam ]['offered'], false ) ) {
			$found['hidden_presets'][] = $where;
		}
		return;
	}
	if ( $fam && ! empty( $index[ $fam ]['offered'] ) && wbmaq_is_free_value( $fam, $value ) ) {
		$where['family']          = $fam;
		$where['presets_offered'] = count( $index[ $fam ]['offered'] );
		$found['hard_values'][]   = $where;
	} elseif ( in_array( strtok( preg_replace( '/^elements\.[a-z0-9]+\./i', '', $path ), '.' ), $groups, true ) ) {
		$found['no_preset_family'][] = $where;
	}
};

$walk = function ( array $blocks, $doc ) use ( &$walk, &$found, &$rep, &$counts, &$total, $registry, $known, $index_of, $defaults_for, $check_value ) {
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? null;
		if ( $name ) {
			$total++;
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			$attrs = (array) ( $b['attrs'] ?? array() );
			$type  = $registry->get_registered( $name );
			if ( $type ) {
				$unknown = array_diff( array_keys( $attrs ), array_keys( (array) $type->attributes ) );
				foreach ( $unknown as $u ) {
					$found['unknown_attrs'][ "$name.$u" ] = ( $found['unknown_attrs'][ "$name.$u" ] ?? 0 ) + 1;
				}
			}
			$style = (array) ( $attrs['style'] ?? array() );
			$base  = $style;
			foreach ( array_keys( $base ) as $k ) {
				if ( str_starts_with( (string) $k, '@' ) || str_starts_with( (string) $k, ':' ) ) {
					unset( $base[ $k ] );
				}
			}
			$flat = wbmaq_flatten( $base );
			foreach ( $flat as $path => $value ) {
				$check_value( $doc, $name, $path, $value, '' );
			}
			foreach ( $style as $k => $sub ) {
				if ( is_string( $k ) && ( $k[0] === '@' || $k[0] === ':' ) && is_array( $sub ) ) {
					foreach ( wbmaq_flatten( $sub ) as $path => $value ) {
						$found['per_screen'][] = array( 'post' => $doc, 'block' => $name, 'state' => $k, 'prop' => $path, 'value' => $value );
						$check_value( $doc, $name, $path, $value, $k );
					}
				}
			}
			$index = $index_of( $name );
			foreach ( $known['slug_attrs'] as $attr => [ $fam ] ) {
				if ( isset( $attrs[ $attr ] ) && is_string( $attrs[ $attr ] ) && isset( $index[ $fam ] ) ) {
					$flat[ $attr ] = "var:preset|$fam|{$attrs[$attr]}";
					$check_value( $doc, $name, $attr, $flat[ $attr ], '' );
				}
			}
			if ( ! empty( $attrs['className'] ) ) {
				foreach ( preg_split( '/\s+/', trim( $attrs['className'] ) ) as $c ) {
					if ( $c !== '' && ! str_starts_with( $c, 'is-style-' ) ) {
						$found['classes'][ "$name .$c" ] = ( $found['classes'][ "$name .$c" ] ?? 0 ) + 1;
					}
				}
			}
			// redundancy with the Global Styles default of this block / element
			$defs = $defaults_for( $name, $attrs );
			$map  = array_filter( array_map( fn( $a ) => $a[1], $known['slug_attrs'] ) );
			foreach ( $flat as $path => $value ) {
				$dpath = $map[ $path ] ?? $path;
				if ( isset( $defs[ $dpath ] ) && wbmaq_norm( $defs[ $dpath ] ) === wbmaq_norm( $value ) ) {
					$found['redundant'][] = array( 'post' => $doc, 'block' => $name, 'prop' => $dpath, 'value' => $value );
				}
				$key           = $name . '|' . ( $map[ $path ] ?? $path ) . '|' . wbmaq_norm( $value );
				$rep[ $key ][] = $doc;
			}
			foreach ( array( 'className', 'align' ) as $k ) {
				if ( isset( $attrs[ $k ] ) && is_string( $attrs[ $k ] ) ) {
					$rep[ "$name|$k|{$attrs[$k]}" ][] = $doc;
				}
			}
			if ( isset( $attrs['layout'] ) && is_array( $attrs['layout'] ) ) {
				foreach ( wbmaq_flatten( $attrs['layout'], 'layout' ) as $p => $v ) {
					$rep[ "$name|$p|" . wbmaq_norm( $v ) ][] = $doc;
				}
			}
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$walk( $b['innerBlocks'], $doc );
		}
	}
};

foreach ( $docs as $doc => $content ) {
	$walk( parse_blocks( $content ), $doc );
}

$repetitions = array();
foreach ( $rep as $key => $where ) {
	if ( count( $where ) < $min ) {
		continue;
	}
	[ $name, $prop, $value ] = explode( '|', $key, 3 );
	$repetitions[] = array(
		'block'     => $name,
		'prop'      => $prop,
		'value'     => $value,
		'count'     => count( $where ),
		'instances' => $counts[ $name ],
		'share'     => round( count( $where ) / max( 1, $counts[ $name ] ), 2 ),
		'posts'     => array_values( array_unique( $where ) ),
	);
}
usort( $repetitions, fn( $a, $b ) => $b['count'] <=> $a['count'] );
arsort( $counts );
arsort( $found['classes'] );
arsort( $found['unknown_attrs'] );

$summary = array();
foreach ( $found as $k => $v ) {
	$summary[ $k ] = is_array( $v ) && array_is_list( $v ) ? count( $v ) : array_sum( (array) $v );
}
$summary['repetitions'] = count( $repetitions );

wbmaq_emit(
	array(
		'source'   => $src,
		'audited'  => array( 'documents' => count( $docs ), 'blocks' => $total ),
		'summary'  => $summary,
		'blocks'   => $counts,
		'findings' => $found + array( 'repetitions' => $repetitions ),
	),
	$opt['out'] ?? null
);
