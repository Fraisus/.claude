<?php
/**
 * Inventory of the blocks an author can insert on THIS site and of their settings, read from the
 * live registry (read-only). Nothing is listed by hand: every row comes from the registry, the
 * block.json files, the merged settings and the editor filters.
 *
 *   wp eval-file inventory.php [out=<file.json>] [block=<name>] [post_type=<type>]
 *
 * out=        full report (default: stdout).
 * block=      one block only, with its full detail.
 * post_type=  editor context for the allowed-blocks filter (default: page).
 *
 * Per block:
 *   origin           core, or the namespace of the theme or extension that registers it
 *   insertable       true, or why not (not allowed, inserter:false, only inside a parent)
 *   controls         each core support crossed with the merged settings for this block:
 *                    offered, presets only (count), free value allowed, or cut (by which setting)
 *   attributes       own (block.json), from_supports (core supports), added_by_extension_supports
 *                    (supports registered by the theme or an extension, on every eligible block),
 *                    added (block-specific, by a registration filter)
 *   supports_diff    registry supports that differ from block.json (added or removed by a filter)
 *   styles           style variations (block.json and PHP registry); one at a time per block
 *   variations       block variations (title, scope)
 *   overridable      attributes a synced pattern can override per instance (block bindings)
 *   hover            :hover states Global Styles accepts for this block (core constant)
 * The server cannot see what editor scripts add or remove (JS filters, unregisterBlockStyle,
 * attributes declared only in JS): `editor_only` says so, the real editor is the proof.
 */

require_once __DIR__ . '/lib.php';

$opt       = wbmaq_args( $args ?? array() );
$post_type = $opt['post_type'] ?? 'page';
$only      = $opt['block'] ?? null;

$registry = WP_Block_Type_Registry::get_instance();
$all      = $registry->get_all_registered();
$settings = wp_get_global_settings();

// ---------- allowed blocks, as the editor asks for them ----------
$sample  = get_posts( array( 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => 1 ) );
$context = new WP_Block_Editor_Context( array( 'name' => 'core/edit-post', 'post' => $sample[0] ?? null ) );
$allowed = get_allowed_block_types( $context );

// ---------- block.json of every registered block ----------
$block_json = array();
$core_file  = ABSPATH . WPINC . '/blocks/blocks-json.php';
if ( file_exists( $core_file ) ) {
	foreach ( (array) require $core_file as $dir => $meta ) {
		if ( isset( $meta['name'] ) ) {
			$block_json[ $meta['name'] ] = array( 'file' => WPINC . "/blocks/$dir/block.json", 'meta' => $meta );
		}
	}
}
$roots = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory(), WPMU_PLUGIN_DIR ) ) );
foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
	$roots[] = WP_PLUGIN_DIR . '/' . dirname( $plugin );
}
foreach ( $roots as $root ) {
	if ( ! is_dir( $root ) || $root === WP_PLUGIN_DIR . '/.' ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			fn( $f ) => ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', 'vendor', '.git' ), true ) )
		)
	);
	foreach ( $it as $f ) {
		if ( $f->getFilename() !== 'block.json' ) {
			continue;
		}
		$meta = json_decode( (string) file_get_contents( $f->getPathname() ), true );
		if ( is_array( $meta ) && isset( $meta['name'] ) && ! isset( $block_json[ $meta['name'] ] ) ) {
			$block_json[ $meta['name'] ] = array(
				'file' => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $f->getPathname() ) ),
				'meta' => $meta,
			);
		}
	}
}

// ---------- attributes created by block supports, split core / extension by the callback's file ----------
$support_callbacks = array( 'core' => array(), 'extension' => array() );
try {
	$prop = new ReflectionProperty( 'WP_Block_Supports', 'block_supports' );
	$prop->setAccessible( true );
	$inc = wp_normalize_path( ABSPATH . WPINC );
	foreach ( (array) $prop->getValue( WP_Block_Supports::get_instance() ) as $cfg ) {
		if ( ! isset( $cfg['register_attribute'] ) ) {
			continue;
		}
		$cb = $cfg['register_attribute'];
		$rf = is_array( $cb ) ? new ReflectionMethod( $cb[0], $cb[1] ) : new ReflectionFunction( Closure::fromCallable( $cb ) );
		$support_callbacks[ str_starts_with( wp_normalize_path( (string) $rf->getFileName() ), $inc ) ? 'core' : 'extension' ][] = $cb;
	}
} catch ( Throwable $e ) {
	$support_callbacks = null;
}
$support_attrs = function ( WP_Block_Type $type, string $side ) use ( $support_callbacks ): ?array {
	if ( $support_callbacks === null ) {
		return null;
	}
	// The constructor runs register_block_type_args: what a filter adds to ANY block is already there.
	$probe = new WP_Block_Type( 'wbmaq-probe/x', array( 'supports' => $type->supports ?? array(), 'attributes' => array() ) );
	$base  = array_keys( (array) $probe->attributes );
	if ( $side === 'any_block_filter' ) {
		return array_values( array_diff( $base, array_keys( WP_Block_Type::GLOBAL_ATTRIBUTES ) ) );
	}
	foreach ( $support_callbacks[ $side ] as $cb ) {
		call_user_func( $cb, $probe );
	}
	return array_values( array_diff( array_keys( (array) $probe->attributes ), $base ) );
};

// ---------- block supports -> controls, read from the supports each block declares ----------
// A style support is a key whose name (`__experimental` prefix dropped) is a theme.json style or setting, or a preset
// path; its gate is the setting of the same path; its family comes from the preset metadata (wbmaq_family_of).
$known          = wbmaq_known();
$setting_leaves = array_flip( wbmaq_leaves( WP_Theme_JSON::VALID_SETTINGS ) );
$style_leaves   = array_flip( wbmaq_leaves( WP_Theme_JSON::VALID_STYLES ) );
$preset_paths   = array_flip( array_column( wbmaq_preset_families(), 'path' ) );
$family_by_path = array_combine( array_column( wbmaq_preset_families(), 'path' ), array_keys( wbmaq_preset_families() ) );
$style_groups   = array_flip( array_merge( array_keys( WP_Theme_JSON::VALID_STYLES ), array_keys( WP_Theme_JSON::VALID_SETTINGS ) ) );
$index_of       = wbmaq_index_by_block( $settings );
$free_switches  = array_filter( $known['free_switch'], 'is_string' );

$controls = function ( string $name, WP_Block_Type $type ) use ( $settings, $known, $setting_leaves, $style_leaves, $preset_paths, $family_by_path, $style_groups, $index_of, $free_switches ): array {
	$sup = (array) ( $type->supports ?? array() );
	if ( isset( $sup['__experimentalBorder'] ) && ! isset( $sup['border'] ) ) {
		$sup['border'] = $sup['__experimentalBorder']; // former name core still reads
	}
	unset( $sup['__experimentalBorder'] );
	$eff  = wbmaq_block_settings( $settings, $name );
	$idx  = $index_of( $name );
	$rows = array();
	foreach ( $sup as $group => $value ) {
		if ( $group === 'layout' ) {
			if ( $value ) {
				$l      = is_array( $value ) ? $value : array();
				$rows[] = array(
					'support' => 'layout',
					'state'   => ( $l['allowEditing'] ?? true ) === false ? 'layout panel hidden' : 'offered',
					'default' => $l['default'] ?? null,
					'flags'   => array_diff_key( $l, array( 'default' => 1, 'allowEditing' => 1 ) ),
				);
			}
			continue;
		}
		if ( ! isset( $style_groups[ $group ] ) ) {
			if ( ! str_starts_with( (string) $group, '__' ) ) {
				$rows[] = array( 'support' => $group, 'state' => $value === false ? 'off' : 'offered', 'value' => $value );
			}
			continue;
		}
		$is_leaf = isset( $style_leaves[ $group ] ) || isset( $setting_leaves[ $group ] ); // `shadow`: the group is the control
		$entries = is_array( $value ) && ! array_is_list( $value ) && ! $is_leaf ? $value : array( '' => $value );
		if ( $group === 'color' && $value ) {
			// Core: color text and background are on unless the block turns them off.
			$c       = is_array( $value ) ? $value : array();
			$entries = $c + array( 'text' => true, 'background' => true );
		}
		$skip = is_array( $value ) ? ( $value['__experimentalSkipSerialization'] ?? false ) : false;
		foreach ( $entries as $key => $v ) {
			$path = $key === '' ? $group : $group . '.' . wbmaq_support_key( (string) $key );
			if ( ! $v ) {
				continue;
			}
			if ( in_array( $path, $known['supports_without_setting'], true ) ) {
				$rows[] = array( 'support' => $path, 'state' => 'offered', 'note' => 'editor only: no theme.json setting nor style' );
				continue;
			}
			if ( ! isset( $setting_leaves[ $path ] ) && ! isset( $style_leaves[ $path ] ) && ! isset( $preset_paths[ $path ] ) ) {
				continue; // internal key (default controls, skip serialization...); drift-check.php lists the unknown ones
			}
			$row = array( 'support' => $path );
			if ( $skip === true || ( is_array( $skip ) && array_intersect( array( $key, wbmaq_support_key( (string) $key ) ), $skip ) ) ) {
				$row['note'] = 'not serialized by core: the block renders it itself';
			}
			$gate       = isset( $setting_leaves[ $path ] ) && ! isset( $preset_paths[ $path ] ) ? $path : null;
			$family     = $family_by_path[ $path ] ?? wbmaq_family_of( $path );
			$gate_value = $gate ? wbmaq_get( $eff, $gate ) : null;
			$presets    = $family && isset( $idx[ $family ] ) ? count( $idx[ $family ]['offered'] ) : 0;
			$switch     = $family ? ( $known['free_switch'][ $family ] ?? null ) : true;
			$free       = is_string( $switch ) ? ( wbmaq_get( $eff, $switch ) !== false ) : $switch;
			if ( $gate && $gate_value === false && ! in_array( $gate, $free_switches, true ) ) {
				$row['state'] = "cut by setting $gate=false";
			} elseif ( $family && $presets === 0 && $free === false ) {
				$row['state'] = 'cut: no preset offered and no free value';
			} else {
				$row['state'] = 'offered';
				if ( $family ) {
					$row['presets']    = $presets;
					$row['free_value'] = $free;
				}
			}
			$rows[] = $row;
		}
	}
	return $rows;
};

$hover_blocks = defined( 'WP_Theme_JSON::VALID_BLOCK_PSEUDO_SELECTORS' ) ? WP_Theme_JSON::VALID_BLOCK_PSEUDO_SELECTORS : array();
$styles_reg   = WP_Block_Styles_Registry::get_instance();

$blocks = array();
$skipped = array();
foreach ( $all as $name => $type ) {
	if ( $only && $name !== $only ) {
		continue;
	}
	$sup        = (array) ( $type->supports ?? array() );
	$is_allowed = ( $allowed === true ) || ( is_array( $allowed ) && in_array( $name, $allowed, true ) );
	$why        = array();
	if ( ! $is_allowed ) {
		$why[] = 'not allowed (allowed_block_types_all)';
	}
	if ( isset( $sup['inserter'] ) && $sup['inserter'] === false ) {
		$why[] = 'inserter:false';
	}
	if ( ! $only && $why ) {
		$skipped[ $name ] = implode( ', ', $why );
		continue;
	}
	$bj        = $block_json[ $name ] ?? null;
	$own       = $bj ? array_keys( (array) ( $bj['meta']['attributes'] ?? array() ) ) : null;
	$from_sup  = $support_attrs( $type, 'core' );
	$ext_sup   = $support_attrs( $type, 'extension' );
	$any_flt   = $support_attrs( $type, 'any_block_filter' );
	$ext_sup   = $ext_sup === null ? null : array_values( array_unique( array_merge( $ext_sup, $any_flt ) ) );
	$reg_attrs = array_keys( (array) $type->attributes );
	$known     = array_merge( $own ?? array(), $from_sup ?? array(), $ext_sup ?? array(), array_keys( WP_Block_Type::GLOBAL_ATTRIBUTES ) );
	$added     = $own === null ? null : array_values( array_diff( $reg_attrs, $known ) );

	$diff = array();
	if ( $bj ) {
		$a = wbmaq_flatten( (array) ( $bj['meta']['supports'] ?? array() ) );
		$b = wbmaq_flatten( $sup );
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
			if ( wp_json_encode( $a[ $k ] ?? null ) !== wp_json_encode( $b[ $k ] ?? null ) ) {
				$diff[] = array( 'path' => $k, 'block_json' => $a[ $k ] ?? null, 'registry' => $b[ $k ] ?? null );
			}
		}
	}

	$styles = array();
	foreach ( (array) ( $type->styles ?? array() ) as $s ) {
		$styles[ $s['name'] ] = array( 'label' => $s['label'] ?? '', 'source' => 'block.json', 'default' => ! empty( $s['isDefault'] ) );
	}
	foreach ( $styles_reg->get_registered_styles_for_block( $name ) as $sn => $s ) {
		$styles[ $sn ] = array( 'label' => $s['label'] ?? '', 'source' => 'register_block_style', 'default' => ! empty( $s['is_default'] ) );
	}

	$variations = array();
	foreach ( (array) $type->get_variations() as $v ) {
		$variations[] = array( 'name' => $v['name'] ?? '', 'title' => $v['title'] ?? '', 'scope' => $v['scope'] ?? null, 'default' => ! empty( $v['isDefault'] ) );
	}

	$row = array(
		'title'       => $type->title,
		'origin'      => strtok( $name, '/' ) === 'core' ? 'core' : strtok( $name, '/' ),
		'category'    => $type->category,
		'keywords'    => $type->keywords,
		'description' => $type->description,
		'insertable'  => $why ? implode( ', ', $why ) : ( $type->parent ? 'only inside: ' . implode( ', ', $type->parent ) : ( $type->ancestor ? 'only under: ' . implode( ', ', $type->ancestor ) : true ) ),
		'dynamic'     => is_callable( $type->render_callback ),
		'block_json'  => $bj['file'] ?? null,
		'controls'    => $controls( $name, $type ),
		'attributes'  => array(
			'own'           => $own,
			'from_supports' => $from_sup,
			'added_by_extension_supports' => $ext_sup,
			'added'         => $added,
			'defaults'      => array_filter( array_map( fn( $a ) => $a['default'] ?? null, (array) ( $bj['meta']['attributes'] ?? array() ) ), fn( $v ) => $v !== null ),
		),
		'supports_diff' => $diff,
		'styles'        => $styles,
		'variations'    => $variations,
		'overridable'   => function_exists( 'get_block_bindings_supported_attributes' ) ? array_values( get_block_bindings_supported_attributes( $name ) ) : null,
		'hover'         => $hover_blocks[ $name ] ?? array(),
	);
	if ( ! $only ) {
		unset( $row['description'], $row['attributes']['defaults'] );
	}
	$blocks[ $name ] = $row;
}
ksort( $blocks );
ksort( $skipped );

// ---------- documentation folders of the active theme (candidates for its manual) ----------
$docs = array();
foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $root ) {
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			fn( $f ) => ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', 'vendor', '.git' ), true ) )
		)
	);
	foreach ( $it as $f ) {
		if ( strtolower( $f->getExtension() ) === 'md' ) {
			$top = explode( '/', ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $f->getPath() ) ), '/' ) )[0];
			$key = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $root ) ) . '/' . $top;
			$docs[ $key ] = ( $docs[ $key ] ?? 0 ) + 1;
		}
	}
}
arsort( $docs );

$report = array(
	'site'        => array(
		'wp_version'  => get_bloginfo( 'version' ),
		'theme'       => get_stylesheet(),
		'block_theme' => wp_is_block_theme(),
		'post_type'   => $post_type,
	),
	'counts'      => array(
		'registered' => count( $all ),
		'listed'     => count( $blocks ),
		'not_listed' => count( $skipped ),
	),
	'allowed'     => $allowed === true ? 'all' : ( is_array( $allowed ) ? count( $allowed ) . ' blocks' : $allowed ),
	'doc_folders' => array_slice( $docs, 0, 8, true ),
	'editor_only' => 'Not visible here: editor scripts that unregister a block, a style or a variation, hide a control, or add attributes in JS only. Prove in the real editor (wp.blocks.getBlockType(name) in its console, or the inserter itself).',
	'blocks'      => $blocks,
	'not_listed'  => $skipped,
);
if ( $support_callbacks === null ) {
	$report['warning'] = 'Could not read the block supports registry: attributes.from_supports, added_by_extension_supports and added are null.';
}
wbmaq_emit( $report, $opt['out'] ?? null );
