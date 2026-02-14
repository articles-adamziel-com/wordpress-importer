<?php
/**
 * Tests for WXR_Media_Extractor.
 *
 * These tests are standalone and do not require a WordPress installation.
 * Run with: php phpunit/tests/media-extractor.php
 *
 * @package WordPress_Importer
 */

require_once dirname( __DIR__, 2 ) . '/src/class-wxr-media-extractor.php';

/**
 * Simple test runner for WXR_Media_Extractor.
 */
class WXR_Media_Extractor_Tests {

	private $test_dir;
	private $data_dir;
	private $passed = 0;
	private $failed = 0;

	public function __construct() {
		$this->data_dir = dirname( __DIR__ ) . '/data';
		$this->test_dir = sys_get_temp_dir() . '/wxr-media-extractor-tests-' . getmypid();
		if ( ! is_dir( $this->test_dir ) ) {
			mkdir( $this->test_dir, 0777, true );
		}
	}

	public function __destruct() {
		// Clean up temp files.
		$files = glob( $this->test_dir . '/*' );
		if ( $files ) {
			foreach ( $files as $file ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->test_dir ) ) {
			rmdir( $this->test_dir );
		}
	}

	/**
	 * Assert helper.
	 */
	private function assert( $condition, $message ) {
		if ( $condition ) {
			$this->passed++;
			echo "  PASS: {$message}\n";
		} else {
			$this->failed++;
			echo "  FAIL: {$message}\n";
		}
	}

	/**
	 * Run all tests.
	 */
	public function run() {
		$methods = get_class_methods( $this );
		foreach ( $methods as $method ) {
			if ( strpos( $method, 'test_' ) === 0 ) {
				echo "\n{$method}:\n";
				$this->$method();
			}
		}

		echo "\n---\n";
		echo "Results: {$this->passed} passed, {$this->failed} failed\n";
		return $this->failed === 0;
	}

	// --- URL extraction tests ---

	public function test_extract_img_src() {
		$content = '<p>Hello</p><img src="http://example.com/photo.jpg" alt="test" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'Finds one URL' );
		$this->assert( $urls[0] === 'http://example.com/photo.jpg', 'Correct URL extracted' );
	}

	public function test_extract_multiple_images() {
		$content = '<img src="http://example.com/a.jpg" /><img src="http://example.com/b.png" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 2, 'Finds two URLs' );
		$this->assert( in_array( 'http://example.com/a.jpg', $urls, true ), 'First URL found' );
		$this->assert( in_array( 'http://example.com/b.png', $urls, true ), 'Second URL found' );
	}

	public function test_extract_deduplicates_urls() {
		$content = '<img src="http://example.com/a.jpg" /><img src="http://example.com/a.jpg" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'Deduplicates identical URLs' );
	}

	public function test_extract_normalizes_resized_images() {
		$content = '<img src="http://example.com/photo-300x200.jpg" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'One URL after normalization' );
		$this->assert( $urls[0] === 'http://example.com/photo.jpg', 'Resize suffix stripped' );
	}

	public function test_extract_deduplicates_resized_and_original() {
		$content = '<img src="http://example.com/photo.jpg" />'
			. '<img src="http://example.com/photo-300x200.jpg" />'
			. '<img src="http://example.com/photo-150x150.jpg" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'All resize variants collapse to one URL' );
		$this->assert( $urls[0] === 'http://example.com/photo.jpg', 'Original URL used' );
	}

	public function test_extract_video_src() {
		$content = '<video src="http://example.com/video.mp4" controls></video>';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'Finds video URL' );
		$this->assert( $urls[0] === 'http://example.com/video.mp4', 'Correct video URL' );
	}

	public function test_extract_audio_source() {
		$content = '<audio><source src="http://example.com/song.mp3" type="audio/mpeg"></audio>';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'Finds audio source URL' );
		$this->assert( $urls[0] === 'http://example.com/song.mp3', 'Correct audio URL' );
	}

	public function test_extract_a_href_to_media() {
		$content = '<a href="http://example.com/document.pdf">Download</a>';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'Finds linked media URL' );
		$this->assert( $urls[0] === 'http://example.com/document.pdf', 'Correct PDF URL' );
	}

	public function test_extract_ignores_non_media_links() {
		$content = '<a href="http://example.com/about">About</a>'
			. '<a href="http://example.com/page.html">Page</a>';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 0, 'Non-media links ignored' );
	}

	public function test_extract_ignores_relative_urls() {
		$content = '<img src="/uploads/photo.jpg" /><img src="photo.jpg" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 0, 'Relative URLs ignored' );
	}

	public function test_extract_empty_content() {
		$urls = WXR_Media_Extractor::extract_media_urls( '' );
		$this->assert( count( $urls ) === 0, 'Empty content returns no URLs' );
	}

	public function test_extract_srcset() {
		$content = '<img src="http://example.com/photo.jpg" '
			. 'srcset="http://example.com/photo-300x200.jpg 300w, '
			. 'http://example.com/photo-768x512.jpg 768w" />';
		$urls = WXR_Media_Extractor::extract_media_urls( $content );
		$this->assert( count( $urls ) === 1, 'srcset variants collapse to one original URL' );
		$this->assert( $urls[0] === 'http://example.com/photo.jpg', 'Original URL from srcset' );
	}

	// --- Full scan tests ---

	public function test_scan_no_attachments_file() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$cursor_file = $this->test_dir . '/cursor-scan.json';

		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file );

		$this->assert( $cursor['scan_complete'] === true, 'Scan completes' );
		$this->assert( $cursor['items_processed'] === 4, 'Processed 4 items' );
		$this->assert( $cursor['max_post_id'] === 4, 'Max post_id is 4' );
		$this->assert( count( $cursor['existing_attachment_urls'] ) === 0, 'No existing attachments' );

		// Expected media: photo.jpg, banner.png, logo.svg, document.pdf, tutorial.mp4
		$media_count = count( $cursor['discovered_media'] );
		$this->assert( $media_count === 5, "Found 5 unique media URLs (got {$media_count})" );

		// photo-300x200.jpg should have been normalized to photo.jpg
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/01/photo.jpg'] ),
			'photo.jpg found (with resize normalization)'
		);
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/02/banner.png'] ),
			'banner.png found'
		);
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/03/logo.svg'] ),
			'logo.svg found'
		);
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/03/document.pdf'] ),
			'document.pdf found'
		);
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/02/tutorial.mp4'] ),
			'tutorial.mp4 found'
		);
	}

	public function test_scan_with_existing_attachments() {
		$input = $this->data_dir . '/export-with-some-attachments.xml';
		$cursor_file = $this->test_dir . '/cursor-scan-existing.json';

		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file );

		$this->assert( $cursor['scan_complete'] === true, 'Scan completes' );
		$this->assert( $cursor['items_processed'] === 2, 'Processed 2 items' );
		$this->assert( $cursor['max_post_id'] === 11, 'Max post_id is 11' );

		// existing-photo.jpg already has an attachment item.
		$this->assert(
			isset( $cursor['existing_attachment_urls']['http://example.com/wp-content/uploads/2024/01/existing-photo.jpg'] ),
			'Existing attachment URL recorded'
		);

		// Only missing-photo.jpg should be in discovered_media.
		$this->assert(
			count( $cursor['discovered_media'] ) === 1,
			'Only 1 new media URL discovered (existing one filtered out)'
		);
		$this->assert(
			isset( $cursor['discovered_media']['http://example.com/wp-content/uploads/2024/01/missing-photo.jpg'] ),
			'missing-photo.jpg discovered'
		);
	}

	// --- Batch/cursor tests ---

	public function test_scan_batch_pause_resume() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$cursor_file = $this->test_dir . '/cursor-batch.json';

		// Process 2 items at a time.
		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file, 2 );
		$this->assert( $cursor['scan_complete'] === false, 'First batch: not complete' );
		$this->assert( $cursor['items_processed'] === 2, 'First batch: 2 items processed' );

		// Resume.
		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file, 2 );
		$this->assert( $cursor['scan_complete'] === false, 'Second batch: not complete' );
		$this->assert( $cursor['items_processed'] === 4, 'Second batch: 4 items processed' );

		// One more pass to finalize.
		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file, 2 );
		$this->assert( $cursor['scan_complete'] === true, 'Third batch: scan complete' );

		// Should have found all media.
		$this->assert( count( $cursor['discovered_media'] ) === 5, 'All 5 media URLs found after resume' );
	}

	public function test_cursor_persistence() {
		$cursor_file = $this->test_dir . '/cursor-persist.json';
		$cursor = array(
			'phase'           => 'scan',
			'items_processed' => 42,
			'max_post_id'     => 100,
			'scan_complete'   => false,
		);

		WXR_Media_Extractor::save_cursor( $cursor_file, $cursor );
		$loaded = WXR_Media_Extractor::load_cursor( $cursor_file );

		$this->assert( $loaded['items_processed'] === 42, 'items_processed persisted' );
		$this->assert( $loaded['max_post_id'] === 100, 'max_post_id persisted' );
		$this->assert( $loaded['phase'] === 'scan', 'phase persisted' );
	}

	// --- Transform tests ---

	public function test_transform_injects_attachments() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-transform.xml';
		$cursor_file = $this->test_dir . '/cursor-transform.json';

		// First scan.
		$cursor = WXR_Media_Extractor::scan( $input, $cursor_file );
		$this->assert( $cursor['scan_complete'] === true, 'Scan complete before transform' );

		// Then transform.
		$cursor = WXR_Media_Extractor::transform( $input, $output, $cursor_file );
		$this->assert( $cursor['transform_complete'] === true, 'Transform complete' );
		$this->assert( $cursor['phase'] === 'complete', 'Phase is complete' );
		$this->assert( file_exists( $output ), 'Output file created' );

		// Verify output is valid XML.
		$dom = new DOMDocument();
		$loaded = $dom->loadXML( file_get_contents( $output ) );
		$this->assert( $loaded === true, 'Output is valid XML' );

		// Verify it has the original items plus new attachment items.
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );

		$all_items = $xpath->query( '//item' );
		$this->assert(
			$all_items->length === 9,
			"9 total items (4 original + 5 attachments), got {$all_items->length}"
		);

		// Count attachment items.
		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert(
			$attachments->length === 5,
			"5 attachment items, got {$attachments->length}"
		);

		// Verify attachment URLs exist.
		$attachment_urls = $xpath->query( '//item[wp:post_type="attachment"]/wp:attachment_url' );
		$found_urls = array();
		foreach ( $attachment_urls as $node ) {
			$found_urls[] = $node->textContent;
		}
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/photo.jpg', $found_urls, true ),
			'photo.jpg attachment URL in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/02/banner.png', $found_urls, true ),
			'banner.png attachment URL in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/02/tutorial.mp4', $found_urls, true ),
			'tutorial.mp4 attachment URL in output'
		);

		// Verify generated post IDs are sequential starting after max_post_id.
		$attachment_ids = $xpath->query( '//item[wp:post_type="attachment"]/wp:post_id' );
		$ids = array();
		foreach ( $attachment_ids as $node ) {
			$ids[] = (int) $node->textContent;
		}
		sort( $ids );
		$this->assert( $ids[0] === 5, 'First attachment post_id is 5 (max was 4)' );
		$this->assert(
			$ids === range( 5, 9 ),
			'Attachment post_ids are sequential 5-9'
		);

		// Verify attachment post_type is 'attachment'.
		foreach ( $attachments as $item ) {
			$type = $xpath->query( 'wp:post_type', $item )->item( 0 )->textContent;
			$this->assert( $type === 'attachment', 'post_type is attachment' );

			$status = $xpath->query( 'wp:status', $item )->item( 0 )->textContent;
			$this->assert( $status === 'inherit', 'status is inherit' );
		}
	}

	public function test_transform_preserves_existing_attachments() {
		$input = $this->data_dir . '/export-with-some-attachments.xml';
		$output = $this->test_dir . '/output-existing.xml';
		$cursor_file = $this->test_dir . '/cursor-existing.json';

		$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file );

		$this->assert( $cursor['phase'] === 'complete', 'Processing complete' );

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		// Should have: 1 post + 1 existing attachment + 1 new attachment = 3 items.
		$all_items = $xpath->query( '//item' );
		$this->assert(
			$all_items->length === 3,
			"3 total items (1 post + 1 existing + 1 new attachment), got {$all_items->length}"
		);

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert(
			$attachments->length === 2,
			"2 attachment items (1 existing + 1 new), got {$attachments->length}"
		);

		// The new attachment should be for missing-photo.jpg.
		$attachment_urls = $xpath->query( '//item[wp:post_type="attachment"]/wp:attachment_url' );
		$found_urls = array();
		foreach ( $attachment_urls as $node ) {
			$found_urls[] = $node->textContent;
		}
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/missing-photo.jpg', $found_urls, true ),
			'missing-photo.jpg attachment added'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/existing-photo.jpg', $found_urls, true ),
			'existing-photo.jpg attachment preserved'
		);
	}

	// --- End-to-end process() test ---

	public function test_process_one_shot() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-oneshot.xml';

		$cursor = WXR_Media_Extractor::process( $input, $output );

		$this->assert( $cursor['phase'] === 'complete', 'One-shot processing complete' );
		$this->assert( file_exists( $output ), 'Output file exists' );

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert( $attachments->length === 5, "5 attachments generated in one-shot mode" );
	}

	public function test_process_with_cursor_batches() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-batched.xml';
		$cursor_file = $this->test_dir . '/cursor-batched.json';

		// Process 1 item at a time.
		$iterations = 0;
		do {
			$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file, 1 );
			$iterations++;
			if ( $iterations > 20 ) {
				break; // Safety valve.
			}
		} while ( $cursor['phase'] !== 'complete' );

		$this->assert( $cursor['phase'] === 'complete', 'Batched processing eventually completes' );
		$this->assert( $iterations <= 10, "Completed in reasonable iterations (got {$iterations})" );

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert( $attachments->length === 5, "5 attachments generated in batched mode" );
	}

	public function test_output_preserves_original_content() {
		$input = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-preserve.xml';

		WXR_Media_Extractor::process( $input, $output );

		$output_content = file_get_contents( $output );

		// Verify original post titles are preserved.
		$this->assert(
			strpos( $output_content, '<title>Post with Images</title>' ) !== false,
			'Original post title preserved'
		);
		$this->assert(
			strpos( $output_content, '<title>Post with No Images</title>' ) !== false,
			'No-image post preserved'
		);
		$this->assert(
			strpos( $output_content, '<title>Page with Video</title>' ) !== false,
			'Page preserved'
		);

		// Verify original content is preserved.
		$this->assert(
			strpos( $output_content, 'Here is an image:' ) !== false,
			'Original post content preserved'
		);

		// Verify XML structure.
		$this->assert(
			strpos( $output_content, '</channel>' ) !== false,
			'Closing </channel> tag present'
		);
		$this->assert(
			strpos( $output_content, '</rss>' ) !== false,
			'Closing </rss> tag present'
		);
	}
}

// --- Run tests ---
$tests = new WXR_Media_Extractor_Tests();
$success = $tests->run();
exit( $success ? 0 : 1 );
