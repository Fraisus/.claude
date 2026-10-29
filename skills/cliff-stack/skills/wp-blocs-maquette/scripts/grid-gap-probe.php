<?php
/**
 * Probe for Gutenberg issue #82747 on THIS site (read-only): a grid with "Columns" AND a
 * "Minimum column width" computes its capped columns with the gap; when the gap comes from the
 * Global Styles blockGap written as a preset, affected versions drop it and use 0.5em, so the front
 * can show fewer columns than the editor.
 *
 *   wp eval-file grid-gap-probe.php [columns=3] [min=14rem]
 *
 * Renders the same grid twice through the real block-supports pipeline (without and with a
 * block-level gap) and prints the gap each `grid-template-columns` formula uses.
 */

require_once __DIR__ . '/lib.php';

$opt  = wbmaq_args( $args ?? array() );
$cols = max( 2, (int) ( $opt['columns'] ?? 3 ) );
$min  = preg_replace( '/[^0-9a-z.%]/i', '', (string) ( $opt['min'] ?? '14rem' ) );

$root_gap  = wp_get_global_styles( array( 'spacing', 'blockGap' ) );
$offered   = wbmaq_preset_index( wp_get_global_settings() )['spacing']['offered'] ?? array();
$block_gap = $offered ? 'var:preset|spacing|' . $offered[ intdiv( count( $offered ), 2 ) ] : '1rem';
$child     = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
$layout    = '"layout":{"type":"grid","columnCount":' . $cols . ',"minimumColumnWidth":"' . $min . '"}';

do_blocks( '<!-- wp:group {' . $layout . '} --><div class="wp-block-group">' . $child . '</div><!-- /wp:group -->' );
do_blocks( '<!-- wp:group {"style":{"spacing":{"blockGap":"' . $block_gap . '"}},' . $layout . '} --><div class="wp-block-group">' . $child . '</div><!-- /wp:group -->' );

preg_match_all( '/grid-template-columns:\s*repeat\(auto-fi(?:ll|t),\s*minmax\(max\(min\([^)]*\),\s*\(100% - \((.+?) \* \(/', wp_style_engine_get_stylesheet_from_context( 'block-supports' ), $m );
$without = $m[1][0] ?? null;
$with    = $m[1][1] ?? null;

$root_is_preset = is_string( $root_gap ) && wbmaq_preset_ref( $root_gap ) !== null;
$verdict        = 'not measurable (formula not found: core may have changed how it writes capped grids)';
if ( $without !== null ) {
	if ( $root_is_preset && trim( $without ) === '0.5em' ) {
		$verdict = 'DEFECT PRESENT: the Global Styles preset gap is dropped (0.5em). Set the gap on each such grid block.';
	} elseif ( $root_is_preset ) {
		$verdict = 'not affected: the Global Styles preset gap reaches the formula';
	} else {
		$verdict = 'not exercised: the Global Styles blockGap is not a preset on this site, the defect cannot show';
	}
}

wbmaq_emit(
	array(
		'wp_version'         => get_bloginfo( 'version' ),
		'global_blockGap'    => $root_gap,
		'formula_gap'        => array(
			'without_block_gap' => $without,
			'with_block_gap'    => $with,
			'block_gap_used'    => $block_gap,
		),
		'verdict'            => $verdict,
	),
	null
);
