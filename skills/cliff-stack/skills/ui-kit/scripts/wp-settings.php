<?php
/**
 * wp-settings.php: read-only dump of what a WordPress block theme SERVES, input of wp-anchor.mjs.
 *
 * Run by WP-CLI from the site root (PHP code always through a file, never inline):
 *   wp eval-file <ui-kit>/scripts/wp-settings.php <out.json>
 * Without <out.json> the JSON goes to stdout (WP-CLI notices may then mix in: prefer the file).
 *
 * Writes nothing to the database or the theme. Output keys:
 *   meta            WordPress version, active theme, block theme flag, user global styles post
 *   settings        wp_get_global_settings(): merged default + blocks + theme + user (served)
 *   styles          wp_get_global_styles(): merged element and block styles (served)
 *   theme_settings  raw settings of the theme layer only (theme.json and its filters), to show
 *                   what the database overrides
 *   variables_css   wp_get_global_stylesheet(['variables']): the --wp--preset--* values served,
 *                   fluid font sizes already computed, block-scoped redefinitions included
 *   stylesheets     local paths of the stylesheets enqueued on the front, read by wp-anchor.mjs to
 *                   resolve a preset whose value is var(--x) defined by the theme or an extension
 *   inline_css      inline styles attached to those handles (same purpose)
 *   block_styles    registered block style variations: block name -> [variation names]
 */
if ( ! function_exists( 'wp_get_global_settings' ) ) {
	fwrite( STDERR, "wp-settings.php: run through WP-CLI (wp eval-file), WordPress not loaded\n" );
	exit( 1 );
}

$wpsa_out_file = isset( $args[0] ) ? (string) $args[0] : '';

$wpsa_user_id   = class_exists( 'WP_Theme_JSON_Resolver' ) ? WP_Theme_JSON_Resolver::get_user_global_styles_post_id() : 0;
$wpsa_user_post = $wpsa_user_id ? get_post( $wpsa_user_id ) : null;

$wpsa_theme_raw = class_exists( 'WP_Theme_JSON_Resolver' ) ? WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data() : array();

/**
 * Maps an enqueued stylesheet URL to a readable local file, or '' when it is not local.
 */
function wpsa_local_path( $src ) {
	if ( ! is_string( $src ) || '' === $src ) {
		return '';
	}
	$src   = strtok( $src, '?#' );
	$pairs = array(
		content_url()  => WP_CONTENT_DIR,
		includes_url() => ABSPATH . WPINC . '/',
		site_url( '/' ) => ABSPATH,
	);
	foreach ( $pairs as $url => $dir ) {
		$url = set_url_scheme( $url );
		foreach ( array( $url, set_url_scheme( $url, 'http' ), set_url_scheme( $url, 'https' ), wp_make_link_relative( $url ) ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $src, $prefix ) ) {
				$path = rtrim( $dir, '/\\' ) . '/' . ltrim( substr( $src, strlen( $prefix ) ), '/' );
				return is_readable( $path ) ? wp_normalize_path( $path ) : '';
			}
		}
	}
	if ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) ) {
		$path = ABSPATH . ltrim( $src, '/' );
		return is_readable( $path ) ? wp_normalize_path( $path ) : '';
	}
	return '';
}

$wpsa_sheets = array();
$wpsa_inline = '';
try {
	ob_start();
	do_action( 'wp_enqueue_scripts' );
	ob_end_clean();
	$wpsa_styles = wp_styles();
	$wpsa_seen   = array();
	$wpsa_walk   = function ( $handle ) use ( &$wpsa_walk, &$wpsa_seen, &$wpsa_sheets, &$wpsa_inline, $wpsa_styles ) {
		if ( isset( $wpsa_seen[ $handle ] ) || empty( $wpsa_styles->registered[ $handle ] ) ) {
			return;
		}
		$wpsa_seen[ $handle ] = true;
		$dep                  = $wpsa_styles->registered[ $handle ];
		foreach ( (array) $dep->deps as $d ) {
			$wpsa_walk( $d );
		}
		$path = wpsa_local_path( $dep->src );
		if ( $path && '.css' === substr( $path, -4 ) ) {
			$wpsa_sheets[ $handle ] = $path;
		}
		$after = $wpsa_styles->get_data( $handle, 'after' );
		if ( is_array( $after ) ) {
			$wpsa_inline .= implode( "\n", $after ) . "\n";
		}
	};
	foreach ( (array) $wpsa_styles->queue as $h ) {
		$wpsa_walk( $h );
	}
} catch ( Throwable $e ) {
	if ( ob_get_level() ) {
		ob_end_clean();
	}
	fwrite( STDERR, 'wp-settings.php: front stylesheets not collected (' . $e->getMessage() . ")\n" );
}

$wpsa_block_styles = array();
foreach ( WP_Block_Styles_Registry::get_instance()->get_all_registered() as $block => $list ) {
	$wpsa_block_styles[ $block ] = array_keys( $list );
}

$wpsa_data = array(
	'meta'           => array(
		'generated'          => gmdate( 'c' ),
		'wp_version'         => get_bloginfo( 'version' ),
		'theme'              => get_stylesheet(),
		'is_block_theme'     => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : null,
		'user_styles_post'   => $wpsa_user_id ? (int) $wpsa_user_id : null,
		'user_styles_modified' => $wpsa_user_post ? $wpsa_user_post->post_modified_gmt : null,
	),
	'settings'       => wp_get_global_settings(),
	'styles'         => wp_get_global_styles(),
	'theme_settings' => isset( $wpsa_theme_raw['settings'] ) ? $wpsa_theme_raw['settings'] : array(),
	'variables_css'  => wp_get_global_stylesheet( array( 'variables' ) ),
	'stylesheets'    => array_values( $wpsa_sheets ),
	'inline_css'     => $wpsa_inline,
	'block_styles'   => $wpsa_block_styles,
);

$wpsa_json = wp_json_encode( $wpsa_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( '' !== $wpsa_out_file ) {
	file_put_contents( $wpsa_out_file, $wpsa_json );
	fwrite( STDERR, "wp-settings.php: written {$wpsa_out_file}, " . strlen( $wpsa_json ) . " bytes\n" );
} else {
	fwrite( STDOUT, $wpsa_json . "\n" );
}
