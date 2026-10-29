<?php
/**
 * Writes kit decisions into the Global Styles of the ACTIVE theme (its `wp_global_styles` post, the
 * layer an administrator edits in Appearance > Editor > Styles), from a theme.json v3 file.
 * DRY RUN BY DEFAULT: nothing is written without `write=1`.
 *
 *   wp --user=<admin> eval-file global-styles.php <input.json> [write=1 backup=<file>] [mode=merge|replace]
 *                                                  [allow_modify=1] [allow_add=1] [out=<report.json>]
 *
 * input.json   { "version": <WP_Theme_JSON::LATEST_SCHEMA>, "settings": {...}, "styles": {...} } with preset lists
 *              written as plain lists, as in a theme.json.
 * write=1      write for real; needs backup=<file or folder> OUTSIDE the site root (timestamped copy of the post).
 *              Booleans: 1, true, yes, on / 0, false, no, off; anything else is refused.
 * backup=      checked in a dry run too, so the write does not fail on it.
 * mode=        merge (default: the input is merged into the current layer) or replace (the layer
 *              becomes the input; everything else the administrator had set is dropped).
 * allow_modify=1  accept changing the value of an existing preset or of a global width.
 * allow_add=1     accept adding a preset (custom origin). A font family must already be installed in
 *                 the Font Library.
 *
 * What it accepts, the rest is refused with a reason (never silently dropped):
 *   settings  the preset families an administrator edits and the two global widths (wbmaq_known), at the root
 *             and under settings.blocks.<registered block>. Any other setting is theme capability or code.
 *   styles    root, elements, blocks.<registered block>, blocks.<b>.variations.<REGISTERED style of b>.
 *             A value in a family that has presets must be an offered preset (or one added in the same
 *             input with allow_add=1); zero is accepted; free values only where no preset exists.
 *             NEVER custom CSS (styles.css, styles.blocks.<b>.css): no option accepts it.
 *
 * Same steps as the REST controller of Global Styles: block style variations of theme.json partials registered
 * before sanitizing, a stored configuration without the isGlobalStylesUserThemeJSON marker discarded, the same
 * JSON encoding. Proof chain: the result is passed through WP_Theme_JSON (core sanitize) and refused if core
 * would drop any key; after a write the post is read back and compared with what was meant to be stored.
 */

require_once __DIR__ . '/lib.php';

$fail = function ( string $msg ) {
	fwrite( STDERR, $msg . "\n" );
	exit( 1 );
};
$opt  = wbmaq_args( $args ?? array() );
$flag = function ( string $key ) use ( $opt, $fail ): bool {
	$v = strtolower( trim( (string) ( $opt[ $key ] ?? '0' ) ) );
	if ( in_array( $v, array( '1', 'true', 'yes', 'on' ), true ) ) {
		return true;
	}
	if ( in_array( $v, array( '0', 'false', 'no', 'off', '' ), true ) ) {
		return false;
	}
	$fail( "$key=$v is not a boolean (1, true, yes, on / 0, false, no, off)" );
};
$file  = $opt[0][0] ?? '';
$write = $flag( 'write' );
$mode  = ( $opt['mode'] ?? 'merge' ) === 'replace' ? 'replace' : 'merge';
$allow = array(
	'modify' => $flag( 'allow_modify' ),
	'add'    => $flag( 'allow_add' ),
);

if ( $file === '' || ! is_file( $file ) ) {
	$fail( 'Usage: wp --user=<admin> eval-file global-styles.php <input.json> [write=1 backup=<file>] [mode=merge|replace] [allow_modify=1] [allow_add=1] [out=<file>]' );
}
$input = json_decode( (string) file_get_contents( $file ), true );
if ( ! is_array( $input ) ) {
	$fail( "Not valid JSON: $file" );
}
if ( (int) ( $input['version'] ?? 0 ) !== WP_Theme_JSON::LATEST_SCHEMA ) {
	$fail( 'The input must be theme.json version ' . WP_Theme_JSON::LATEST_SCHEMA . '.' );
}

$theme = wp_get_theme();
$stamp = gmdate( 'Ymd-His' );

// Backup target: the final file, resolved, must lie outside the site root.
$backup = null;
if ( isset( $opt['backup'] ) && $opt['backup'] !== '' ) {
	$target = wp_normalize_path( (string) $opt['backup'] );
	if ( is_dir( $target ) ) {
		$target = untrailingslashit( $target ) . "/global-styles-{$theme->get_stylesheet()}-$stamp.json";
	}
	$dir = realpath( dirname( $target ) );
	if ( ! $dir ) {
		$fail( 'Refused: the backup folder does not exist: ' . dirname( $target ) );
	}
	$backup    = wp_normalize_path( $dir ) . '/' . basename( $target );
	$site_root = trailingslashit( wp_normalize_path( realpath( ABSPATH ) ?: ABSPATH ) );
	if ( stripos( $backup, $site_root ) === 0 ) {
		$fail( "Refused: the backup must live outside the site root ($backup)." );
	}
}

$cpt        = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( $theme, false );
$post_id    = $cpt['ID'] ?? null;
$stored     = $post_id ? json_decode( (string) $cpt['post_content'], true ) : array();
$stored     = is_array( $stored ) ? $stored : array();
$has_marker = ! empty( $stored['isGlobalStylesUserThemeJSON'] );
$current    = $has_marker ? $stored : array(); // the REST controller discards a configuration without the marker
$merged     = wp_get_global_settings();
$registry   = WP_Block_Type_Registry::get_instance();
$styles_rg  = WP_Block_Styles_Registry::get_instance();
$families   = wbmaq_preset_families();
$known      = wbmaq_known();
$index_of   = wbmaq_index_by_block( $merged );

// Block style variations declared by theme.json partials, registered as the REST controller does before saving.
if ( function_exists( 'wp_register_block_style_variations_from_theme_json_partials' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_style_variations' ) ) {
	wp_register_block_style_variations_from_theme_json_partials( WP_Theme_JSON_Resolver::get_style_variations( 'block' ) );
}

$refused   = array();
$warnings  = array();
$unchanged = array(); // input entries equal to what is already served: nothing to write
$accepted = array( 'settings' => array(), 'styles' => array() );
$changes  = array(); // human-readable preset-level changes: add / modify

if ( $post_id && ! $has_marker ) {
	$warnings[] = 'the stored Global Styles have no isGlobalStylesUserThemeJSON marker: discarded, as the REST controller does (see diff, kind removed)';
}
foreach ( array_keys( $input ) as $k ) {
	if ( in_array( $k, array( 'version', 'settings', 'styles', '$schema' ), true ) ) {
		continue;
	}
	$refused[] = array( 'path' => $k, 'reason' => in_array( $k, WP_Theme_JSON::VALID_TOP_LEVEL_KEYS, true ) ? 'theme.json key that Global Styles do not store: only settings and styles' : 'not a theme.json key' );
}

// ---------- settings ----------
$editable       = $known['admin_presets'];
$setting_leaves = wbmaq_leaves( WP_Theme_JSON::VALID_SETTINGS );
$family_by_path = array();
foreach ( $families as $key => $f ) {
	$family_by_path[ $f['path'] ] = array( 'key' => $key, 'value_key' => $f['value_key'] ?? 'size' );
}
$installed_fonts = array();
foreach ( get_posts( array( 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $fp ) {
	$installed_fonts[ $fp->post_name ] = (array) json_decode( (string) $fp->post_content, true );
}
$added_slugs = array(); // scope ('' or 'blocks.<b>.') => family key => slugs added by this input (for the styles check)

/**
 * One preset list of the input against the presets currently served for that path.
 * Returns the origin-keyed lists to store in the user layer (theme, custom), or null when unchanged.
 */
$process_presets = function ( string $scope, string $path, array $list, array $served_settings ) use ( &$refused, &$changes, &$added_slugs, &$unchanged, $allow, $family_by_path, $installed_fonts, $known ) {
	$fam       = $family_by_path[ $path ];
	$value_key = $path === 'typography.fontSizes' ? 'size' : $fam['value_key'];
	$by_origin = wbmaq_get( $served_settings, $path );
	$by_origin = is_array( $by_origin ) ? $by_origin : array();
	$lists     = array(
		'theme'  => array_values( (array) ( $by_origin['theme'] ?? array() ) ),
		'custom' => array_values( (array) ( $by_origin['custom'] ?? array() ) ),
	);
	$touched   = array( 'theme' => false, 'custom' => false );
	$where     = function ( string $slug ) use ( &$lists, $by_origin ) {
		foreach ( array( 'custom', 'theme' ) as $o ) {
			foreach ( $lists[ $o ] as $i => $p ) {
				if ( ( $p['slug'] ?? null ) === $slug ) {
					return array( $o, $i );
				}
			}
		}
		foreach ( (array) ( $by_origin['default'] ?? array() ) as $p ) {
			if ( ( $p['slug'] ?? null ) === $slug ) {
				return array( 'default', null );
			}
		}
		return null;
	};
	if ( ! array_is_list( $list ) ) {
		$refused[] = array( 'path' => "settings.$scope$path", 'reason' => 'write presets as a plain list, as in a theme.json (not keyed by origin)' );
		return null;
	}
	foreach ( $list as $p ) {
		$slug = is_array( $p ) ? (string) ( $p['slug'] ?? '' ) : '';
		if ( $slug === '' ) {
			$refused[] = array( 'path' => "settings.$scope$path", 'reason' => 'preset without slug' );
			continue;
		}
		$at = $where( $slug );
		if ( $at && $at[0] === 'default' ) {
			$refused[] = array( 'path' => "settings.$scope$path.$slug", 'reason' => 'core default preset: not editable in the admin' );
			continue;
		}
		if ( $at ) {
			[ $o, $i ] = $at;
			$before    = $lists[ $o ][ $i ];
			$after     = array_merge( $before, $p );
			if ( wp_json_encode( $before ) === wp_json_encode( $after ) ) {
				$unchanged[] = "settings.$scope$path.$slug";
				continue;
			}
			// Outside modifiable_presets a preset is a rung: the value picks the nearest one or adds one, the rung never moves.
			if ( ! in_array( $path, $known['modifiable_presets'], true ) ) {
				$refused[] = array( 'path' => "settings.$scope$path.$slug", 'reason' => 'changes a rung of ' . $path . ': pick the nearest preset, or add one (allow_add=1) when no neighbour fits', 'before' => $before[ $value_key ] ?? null, 'after' => $after[ $value_key ] ?? null );
				continue;
			}
			if ( ! $allow['modify'] ) {
				$refused[] = array( 'path' => "settings.$scope$path.$slug", 'reason' => 'changes an existing preset: pick another preset, or pass allow_modify=1 for a kit decision', 'before' => $before[ $value_key ] ?? null, 'after' => $after[ $value_key ] ?? null );
				continue;
			}
			$lists[ $o ][ $i ] = $after;
			$touched[ $o ]     = true;
			$changes[]         = array( 'change' => 'MODIFY PRESET', 'path' => "settings.$scope$path.$slug", 'origin' => $o, 'before' => $before[ $value_key ] ?? $before, 'after' => $after[ $value_key ] ?? $after );
			continue;
		}
		$reasons = array();
		if ( $path === 'typography.fontFamilies' && ! isset( $installed_fonts[ $slug ] ) ) {
			$reasons[] = 'font family not installed: install it with the Font Library first (it uploads the font files)';
		}
		if ( ! $allow['add'] ) {
			$reasons[] = 'adds a preset: use an existing one, shift the mockup to the nearest, or pass allow_add=1 when no neighbour is acceptable';
		}
		if ( $reasons ) {
			$refused[] = array( 'path' => "settings.$scope$path.$slug", 'reason' => implode( ' ; ', $reasons ) );
			continue;
		}
		$lists['custom'][]           = $p;
		$touched['custom']           = true;
		$added_slugs[ $scope ][ $fam['key'] ][] = $slug;
		$changes[]                   = array( 'change' => 'ADD PRESET', 'path' => "settings.$scope$path.$slug", 'origin' => 'custom', 'after' => $p[ $value_key ] ?? $p );
	}
	if ( ! $touched['theme'] && ! $touched['custom'] ) {
		return null;
	}
	return array_filter( array( 'theme' => $touched['theme'] ? $lists['theme'] : null, 'custom' => $touched['custom'] ? $lists['custom'] : null ) );
};

$walk_settings = function ( array $settings, string $scope, array $served ) use ( &$walk_settings, &$accepted, &$refused, &$changes, &$unchanged, $editable, $setting_leaves, $known, $process_presets, $registry, $allow, $merged ) {
	foreach ( wbmaq_flatten_until( $settings, $setting_leaves ) as $path => $value ) {
		if ( $scope === '' && $path === 'blocks' ) {
			continue; // handled below, per block
		}
		$target = 'settings.' . $scope . $path;
		if ( in_array( $path, $editable, true ) ) {
			$res = $process_presets( $scope, $path, (array) $value, $served );
			if ( $res !== null ) {
				$prefix = $scope === '' ? array() : explode( '.', rtrim( $scope, '.' ) );
				foreach ( $res as $o => $l ) {
					_wp_array_set( $accepted['settings'], array_merge( $prefix, explode( '.', $path ), array( $o ) ), $l );
				}
			}
			continue;
		}
		if ( $scope === '' && in_array( $path, $known['admin_widths'], true ) ) {
			$before = wbmaq_get( $merged, $path );
			if ( (string) $before === (string) $value ) {
				$unchanged[] = $target;
				continue;
			}
			if ( ! $allow['modify'] ) {
				$refused[] = array( 'path' => $target, 'reason' => 'changes a global width: pass allow_modify=1 for a kit decision', 'before' => $before, 'after' => $value );
				continue;
			}
			_wp_array_set( $accepted['settings'], explode( '.', $path ), $value );
			$changes[] = array( 'change' => 'MODIFY WIDTH', 'path' => $target, 'before' => $before, 'after' => $value );
			continue;
		}
		if ( in_array( $path, $known['code_presets'], true ) ) {
			$why = 'preset family not editable in the admin: choose an existing step, never write one';
		} elseif ( in_array( $path, $setting_leaves, true ) ) {
			$why = 'not an administrator setting of Global Styles (theme capability or code)';
		} else {
			$why = 'not a theme.json setting';
		}
		$refused[] = array( 'path' => $target, 'reason' => $why );
	}
	if ( $scope === '' && isset( $settings['blocks'] ) && is_array( $settings['blocks'] ) ) {
		foreach ( $settings['blocks'] as $block => $sub ) {
			if ( ! $registry->is_registered( $block ) ) {
				$refused[] = array( 'path' => "settings.blocks.$block", 'reason' => 'block not registered on this site' );
				continue;
			}
			$walk_settings( (array) $sub, "blocks.$block.", wbmaq_block_settings( $merged, $block ) );
		}
	}
};

/** Flatten, but stop at the given paths (preset lists stay whole). */
function wbmaq_flatten_until( array $value, array $stops, string $prefix = '', array &$out = array() ): array {
	foreach ( $value as $k => $v ) {
		$p = $prefix === '' ? (string) $k : "$prefix.$k";
		if ( in_array( $p, $stops, true ) || ! is_array( $v ) || $v === array() || array_is_list( $v ) || $k === 'blocks' ) {
			$out[ $p ] = $v;
		} else {
			wbmaq_flatten_until( $v, $stops, $p, $out );
		}
	}
	return $out;
}

if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
	$walk_settings( $input['settings'], '', $merged );
}

// ---------- styles ----------
$check_style = function ( string $path, $value ) use ( $index_of, &$added_slugs ): ?string {
	$block = preg_match( '/^blocks\.([^.]+\/[^.]+)\./', $path, $m ) ? $m[1] : '';
	$index = $index_of( $block );
	$fam   = wbmaq_family_of( $path );
	$ref   = wbmaq_preset_ref( $value );
	$slug  = $ref ? null : wbmaq_preset_by_value( $fam, $value, $index );
	if ( $slug !== null ) {
		$ref = array( $fam, $slug ); // the editor stores this family's preset as its value
	}
	if ( $ref ) {
		[ $fam, $slug ] = $ref;
		$added = array_merge( $added_slugs[''][ $fam ] ?? array(), $block !== '' ? ( $added_slugs[ "blocks.$block." ][ $fam ] ?? array() ) : array() );
		if ( in_array( (string) $slug, array_map( 'strval', $added ), true ) ) {
			return null;
		}
		if ( ! isset( $index[ $fam ] ) || ! in_array( $slug, $index[ $fam ]['served'], false ) ) {
			return "preset $fam/$slug is not served by this site";
		}
		if ( ! in_array( $slug, $index[ $fam ]['offered'], false ) ) {
			return "preset $fam/$slug is served but not offered in the editor";
		}
		return null;
	}
	if ( $fam && ! empty( $index[ $fam ]['offered'] ) && wbmaq_is_free_value( $fam, $value ) ) {
		return "free value where $fam presets exist: choose the nearest step";
	}
	return null;
};
$styles_now = wbmaq_flatten( array( 'styles' => wp_get_global_styles() ) );
if ( isset( $input['styles'] ) && is_array( $input['styles'] ) ) {
	foreach ( wbmaq_flatten( $input['styles'] ) as $path => $value ) {
		if ( preg_match( '/^blocks\.([^.]+\/[^.]+)(?:\.variations\.([^.]+))?/', $path, $m ) ) {
			if ( ! $registry->is_registered( $m[1] ) ) {
				$refused[] = array( 'path' => "styles.$path", 'reason' => "block {$m[1]} not registered on this site" );
				continue;
			}
			if ( ! empty( $m[2] ) ) {
				$type  = $registry->get_registered( $m[1] );
				$names = array_merge( array_column( (array) ( $type->styles ?? array() ), 'name' ), array_keys( $styles_rg->get_registered_styles_for_block( $m[1] ) ) );
				if ( ! in_array( $m[2], $names, true ) ) {
					$refused[] = array( 'path' => "styles.$path", 'reason' => "style variation {$m[2]} does not exist for {$m[1]}: creating one is code" );
					continue;
				}
				$warnings[ "{$m[1]}/{$m[2]}" ] = "style variation {$m[2]} of {$m[1]} is registered on the server; an editor script may still unregister it (search unregisterBlockStyle in the theme and extensions, then check the editor)";
			}
		}
		$why = preg_match( '/(^|\.)css$/', $path )
			? 'additional CSS: outside this skill (presets only); set it in the admin, Styles > Additional CSS, if really wanted'
			: $check_style( $path, $value );
		if ( $why ) {
			$refused[] = array( 'path' => "styles.$path", 'reason' => $why, 'value' => $value );
			continue;
		}
		if ( isset( $styles_now[ "styles.$path" ] ) && wbmaq_norm( $styles_now[ "styles.$path" ] ) === wbmaq_norm( $value ) ) {
			$unchanged[] = "styles.$path";
		}
		_wp_array_set( $accepted['styles'], explode( '.', $path ), $value );
	}
}

// ---------- the new user layer ----------
$base = $mode === 'replace' ? array() : $current;
unset( $base['version'], $base['isGlobalStylesUserThemeJSON'] );
$new = wbmaq_merge( $base, array_filter( $accepted ) );
$new = array( 'version' => WP_Theme_JSON::LATEST_SCHEMA, 'isGlobalStylesUserThemeJSON' => true ) + $new;

// Core sanitize: refuse if core would drop anything we mean to store.
$clean   = ( new WP_Theme_JSON( $new, 'custom' ) )->get_raw_data();
$intent  = wbmaq_flatten( array_diff_key( $new, array( 'isGlobalStylesUserThemeJSON' => 1 ) ) );
$kept    = wbmaq_flatten( $clean );
$dropped = array();
foreach ( $intent as $p => $v ) {
	if ( ! array_key_exists( $p, $kept ) || wbmaq_norm( $kept[ $p ] ) !== wbmaq_norm( $v ) ) {
		$dropped[] = array( 'path' => $p, 'meant' => $v, 'core_keeps' => $kept[ $p ] ?? null );
	}
}

$before = wbmaq_flatten( array_diff_key( $stored, array( 'version' => 1, 'isGlobalStylesUserThemeJSON' => 1 ) ) );
// Core rewrites `var:preset|x|y` as `var(--wp--preset--x--y)`: store core's canonical form, compare by meaning.
$new   = array( 'isGlobalStylesUserThemeJSON' => true ) + $clean;
$after = wbmaq_flatten( array_diff_key( $new, array( 'version' => 1, 'isGlobalStylesUserThemeJSON' => 1 ) ) );
// What core drops from content that was ALREADY stored is not this input's doing: warn, do not block.
$drops_existing = array_values( array_filter( $dropped, fn( $d ) => array_key_exists( $d['path'], $before ) && wbmaq_norm( $before[ $d['path'] ] ) === wbmaq_norm( $d['meant'] ) ) );
$dropped        = array_values( array_filter( $dropped, fn( $d ) => ! in_array( $d, $drops_existing, true ) ) );
$diff      = array();
$effective = wbmaq_flatten( array( 'styles' => wp_get_global_styles() ) );
foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $p ) {
	$b = $before[ $p ] ?? null;
	$a = $after[ $p ] ?? null;
	if ( ( $b === null ) !== ( $a === null ) || wbmaq_norm( $b ) !== wbmaq_norm( $a ) ) {
		$row = array( 'path' => $p, 'before' => $b, 'after' => $a, 'kind' => $b === null ? 'added' : ( $a === null ? 'removed' : 'changed' ) );
		if ( str_starts_with( $p, 'styles.' ) ) {
			// What the site renders today for this path (theme + admin layers), not only the admin layer.
			$row['effective_before'] = $effective[ $p ] ?? null;
		}
		$diff[] = $row;
	}
}

$report = array(
	'theme'          => $theme->get_stylesheet(),
	'post_id'        => $post_id,
	'mode'           => $mode,
	'dry_run'        => ! $write,
	'allow'          => $allow,
	'backup'         => $backup,
	'preset_changes' => $changes,
	'refused'        => $refused,
	'warnings'       => array_values( $warnings ),
	'unchanged'      => $unchanged,
	'core_would_drop' => $dropped,
	'core_drops_already_stored' => $drops_existing,
	'diff'           => $diff,
);

if ( ! $write ) {
	$report['next'] = $dropped ? 'Fix core_would_drop first.' : 'Dry run only. Review refused and diff, then rerun with write=1 backup=<file outside the site>.';
	wbmaq_emit( $report, $opt['out'] ?? null );
	exit( 0 );
}

// ---------- write ----------
if ( $dropped ) {
	$fail( 'Refused: core sanitize would drop keys (see a dry run, core_would_drop).' );
}
if ( ! $diff ) {
	$report['written'] = false;
	$report['next']    = 'Nothing to write.';
	wbmaq_emit( $report, $opt['out'] ?? null );
	exit( 0 );
}
if ( ! current_user_can( 'edit_theme_options' ) ) {
	$fail( 'Refused: run as an administrator (wp --user=<login> eval-file ...).' );
}
if ( $backup === null ) {
	$fail( 'Refused: write=1 needs backup=<file or folder> outside the site root.' );
}
$saved = file_put_contents( $backup, wp_json_encode( array( 'theme' => $theme->get_stylesheet(), 'post_id' => $post_id, 'saved_at_utc' => $stamp, 'post_content' => $cpt['post_content'] ?? null ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
if ( ! $saved ) {
	$fail( "Refused: could not write the backup $backup" );
}
if ( ! $post_id ) {
	$post_id = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( $theme, true )['ID'] ?? null;
	if ( ! $post_id ) {
		$fail( 'Could not create the Global Styles post.' );
	}
}
// Same encoding as the REST controller: "<>&" escaped against HTML filters.
$res = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => wp_json_encode( $new, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) ) ), true );
if ( is_wp_error( $res ) ) {
	$fail( 'Write failed: ' . $res->get_error_message() );
}
WP_Theme_JSON_Resolver::clean_cached_data();
if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
	wp_clean_theme_json_cache();
}

// Read back and compare with what was meant.
$readback = array();
$sflat    = wbmaq_flatten( (array) json_decode( (string) get_post_field( 'post_content', $post_id, 'raw' ), true ) );
foreach ( wbmaq_flatten( $new ) as $p => $v ) {
	if ( wp_json_encode( $sflat[ $p ] ?? null ) !== wp_json_encode( $v ) ) {
		$readback[] = array( 'path' => $p, 'meant' => $v, 'stored' => $sflat[ $p ] ?? null );
	}
}
$report['written']            = true;
$report['post_id']            = $post_id;
$report['backup']             = $backup;
$report['readback_mismatch']  = $readback;
$report['next']               = $readback ? 'Stored content differs from what was meant: restore from the backup and investigate.' : 'Prove on the front (computed styles), then in the editor Styles panel.';
wbmaq_emit( $report, $opt['out'] ?? null );
exit( $readback ? 1 : 0 );
