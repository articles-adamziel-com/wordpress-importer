#!/usr/bin/env php
<?php
/**
 * CLI tool: Extract media from WXR post content into attachment items.
 *
 * Transforms a WXR export file so that images (and other media) referenced
 * in post content get their own <item> entries with post_type=attachment.
 * This enables the WordPress Importer to download them into the uploads
 * directory during import.
 *
 * Usage:
 *   php wxr-extract-media.php <input.xml> <output.xml> [--cursor=cursor.json] [--batch-size=100]
 *
 * Options:
 *   --cursor=FILE      Save/resume progress to FILE (JSON). Enables pause/resume
 *                       for very large exports. Re-run the same command to continue.
 *   --batch-size=N     Process N items per run (0 = unlimited). Use with --cursor
 *                       to process large files in chunks.
 *
 * Examples:
 *   # Process an entire file at once:
 *   php wxr-extract-media.php export.xml export-with-media.xml
 *
 *   # Process in batches of 500 items (pause/resume with cursor):
 *   php wxr-extract-media.php export.xml export-with-media.xml --cursor=progress.json --batch-size=500
 *   # Re-run the same command to continue until complete.
 *
 * @package WordPress_Importer
 */

if ( 'cli' !== php_sapi_name() ) {
	die( 'This script must be run from the command line.' );
}

require_once dirname( __DIR__ ) . '/src/class-wxr-media-extractor.php';

/**
 * Parse command-line arguments.
 */
function wxr_extract_media_parse_args( $argv ) {
	$args = array(
		'input'      => '',
		'output'     => '',
		'cursor'     => null,
		'batch_size' => 0,
	);

	$positional = array();

	for ( $i = 1; $i < count( $argv ); $i++ ) {
		$arg = $argv[ $i ];

		if ( strpos( $arg, '--cursor=' ) === 0 ) {
			$args['cursor'] = substr( $arg, 9 );
		} elseif ( strpos( $arg, '--batch-size=' ) === 0 ) {
			$args['batch_size'] = (int) substr( $arg, 13 );
		} elseif ( $arg === '--help' || $arg === '-h' ) {
			wxr_extract_media_usage();
			exit( 0 );
		} elseif ( strpos( $arg, '-' ) !== 0 ) {
			$positional[] = $arg;
		} else {
			fwrite( STDERR, "Unknown option: {$arg}\n" );
			exit( 1 );
		}
	}

	if ( count( $positional ) < 2 ) {
		wxr_extract_media_usage();
		exit( 1 );
	}

	$args['input']  = $positional[0];
	$args['output'] = $positional[1];

	return $args;
}

/**
 * Print usage information.
 */
function wxr_extract_media_usage() {
	fwrite( STDERR, "Usage: php wxr-extract-media.php <input.xml> <output.xml> [options]\n\n" );
	fwrite( STDERR, "Options:\n" );
	fwrite( STDERR, "  --cursor=FILE      Save/resume progress (enables pause/resume)\n" );
	fwrite( STDERR, "  --batch-size=N     Items to process per run (0 = unlimited)\n" );
	fwrite( STDERR, "  --help, -h         Show this help message\n" );
}

// --- Main ---

$args = wxr_extract_media_parse_args( $argv );

if ( ! file_exists( $args['input'] ) ) {
	fwrite( STDERR, "Error: Input file not found: {$args['input']}\n" );
	exit( 1 );
}

// Check for resuming.
$cursor = WXR_Media_Extractor::load_cursor( $args['cursor'] );
if ( $cursor['phase'] === 'complete' ) {
	fwrite( STDOUT, "Processing already complete (cursor says phase=complete).\n" );
	fwrite( STDOUT, "Delete the cursor file to start over.\n" );
	exit( 0 );
}

$resuming = $cursor['items_processed'] > 0;
if ( $resuming ) {
	fwrite( STDOUT, "Resuming from item {$cursor['items_processed']}...\n" );
} else {
	fwrite( STDOUT, "Processing {$args['input']}...\n" );
}

$cursor = WXR_Media_Extractor::process(
	$args['input'],
	$args['output'],
	$args['cursor'],
	$args['batch_size']
);

if ( 'complete' === $cursor['phase'] ) {
	$emitted = count( $cursor['emitted_urls'] );

	fwrite( STDOUT, "Done! Processed {$cursor['items_processed']} items.\n" );
	fwrite( STDOUT, "Found {$emitted} unique media URLs.\n" );
	fwrite( STDOUT, "Added {$cursor['media_added']} new attachment items to {$args['output']}.\n" );
} else {
	$pct = '';
	if ( $args['batch_size'] > 0 ) {
		$pct = " (batch of {$args['batch_size']})";
	}
	fwrite( STDOUT, "Paused after {$cursor['items_processed']} items{$pct}.\n" );
	fwrite( STDOUT, "Found " . count( $cursor['emitted_urls'] ) . " media URLs so far.\n" );
	fwrite( STDOUT, "Run the same command again to continue.\n" );
}

exit( 0 );
