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

	private function assert( $condition, $message ) {
		if ( $condition ) {
			$this->passed++;
			echo "  PASS: {$message}\n";
		} else {
			$this->failed++;
			echo "  FAIL: {$message}\n";
		}
	}

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

	// ── URL extraction tests ──────────────────────────────────────────

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

	// ── Single-pass process tests ─────────────────────────────────────

	public function test_process_no_attachments_file() {
		$input  = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-no-att.xml';

		$cursor = WXR_Media_Extractor::process( $input, $output );

		$this->assert( $cursor['phase'] === 'complete', 'Processing completes' );
		$this->assert( $cursor['items_processed'] === 4, 'Processed 4 items' );
		$this->assert( $cursor['media_added'] === 5, "Added 5 attachments (got {$cursor['media_added']})" );

		// Verify output is valid XML with correct structure.
		$dom = new DOMDocument();
		$loaded = $dom->loadXML( file_get_contents( $output ) );
		$this->assert( $loaded === true, 'Output is valid XML' );

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		$all_items = $xpath->query( '//item' );
		$this->assert(
			$all_items->length === 9,
			"9 total items (4 original + 5 attachments), got {$all_items->length}"
		);

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert(
			$attachments->length === 5,
			"5 attachment items, got {$attachments->length}"
		);

		// Verify specific URLs.
		$attachment_urls = $xpath->query( '//item[wp:post_type="attachment"]/wp:attachment_url' );
		$found_urls = array();
		foreach ( $attachment_urls as $node ) {
			$found_urls[] = $node->textContent;
		}
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/photo.jpg', $found_urls, true ),
			'photo.jpg attachment in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/02/banner.png', $found_urls, true ),
			'banner.png attachment in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/03/logo.svg', $found_urls, true ),
			'logo.svg attachment in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/03/document.pdf', $found_urls, true ),
			'document.pdf attachment in output'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/02/tutorial.mp4', $found_urls, true ),
			'tutorial.mp4 attachment in output'
		);

		// Verify post IDs are all above the max original (4).
		$attachment_ids = $xpath->query( '//item[wp:post_type="attachment"]/wp:post_id' );
		$ids = array();
		foreach ( $attachment_ids as $node ) {
			$ids[] = (int) $node->textContent;
		}
		sort( $ids );
		$this->assert( $ids[0] >= 5, 'Attachment post_ids start above max original' );

		// Verify attachment metadata.
		foreach ( $attachments as $item ) {
			$type = $xpath->query( 'wp:post_type', $item )->item( 0 )->textContent;
			$this->assert( $type === 'attachment', 'post_type is attachment' );

			$status = $xpath->query( 'wp:status', $item )->item( 0 )->textContent;
			$this->assert( $status === 'inherit', 'status is inherit' );
		}
	}

	public function test_process_with_existing_attachments() {
		$input  = $this->data_dir . '/export-with-some-attachments.xml';
		$output = $this->test_dir . '/output-existing.xml';

		$cursor = WXR_Media_Extractor::process( $input, $output );

		$this->assert( $cursor['phase'] === 'complete', 'Processing complete' );

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		// In single-pass mode: the post comes before the attachment item.
		// So we emit missing-photo.jpg after the post. Then we see the
		// existing attachment for existing-photo.jpg and record it (no dup
		// since we hadn't emitted it). But we also emit existing-photo.jpg
		// after the post because we haven't seen the attachment yet.
		// That's 2 new + 1 existing = post sees 2 URLs, emits 2, then
		// the existing attachment item passes through.
		// Total: 1 post + 2 emitted + 1 existing attachment = 4 items.
		$all_items = $xpath->query( '//item' );
		$this->assert(
			$all_items->length === 4,
			"4 total items (1 post + 2 emitted + 1 existing), got {$all_items->length}"
		);

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert(
			$attachments->length === 3,
			"3 attachment items, got {$attachments->length}"
		);

		// Verify both URLs have attachment entries.
		$attachment_urls = $xpath->query( '//item[wp:post_type="attachment"]/wp:attachment_url' );
		$found_urls = array();
		foreach ( $attachment_urls as $node ) {
			$found_urls[] = $node->textContent;
		}
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/missing-photo.jpg', $found_urls, true ),
			'missing-photo.jpg attachment present'
		);
		$this->assert(
			in_array( 'http://example.com/wp-content/uploads/2024/01/existing-photo.jpg', $found_urls, true ),
			'existing-photo.jpg attachment present'
		);
	}

	// ── Batch/cursor tests ────────────────────────────────────────────

	public function test_batch_pause_resume() {
		$input       = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output      = $this->test_dir . '/output-batch.xml';
		$cursor_file = $this->test_dir . '/cursor-batch.json';

		// Process 2 items at a time.
		$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file, 2 );
		$this->assert( $cursor['phase'] !== 'complete', 'First batch: not complete' );
		$this->assert( $cursor['items_processed'] === 2, 'First batch: 2 items processed' );
		$this->assert( $cursor['media_added'] > 0, 'First batch: some media emitted' );

		// Resume.
		$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file, 2 );
		$this->assert( $cursor['items_processed'] === 4, 'Second batch: 4 items processed' );

		// Final pass (no more items).
		$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file, 2 );
		$this->assert( $cursor['phase'] === 'complete', 'Third batch: complete' );

		// Verify final output.
		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert(
			$attachments->length === 5,
			"5 attachments after batched resume, got {$attachments->length}"
		);
	}

	public function test_batch_one_item_at_a_time() {
		$input       = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output      = $this->test_dir . '/output-batch1.xml';
		$cursor_file = $this->test_dir . '/cursor-batch1.json';

		$iterations = 0;
		do {
			$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file, 1 );
			$iterations++;
			if ( $iterations > 20 ) {
				break;
			}
		} while ( $cursor['phase'] !== 'complete' );

		$this->assert( $cursor['phase'] === 'complete', 'Completes with batch_size=1' );
		$this->assert( $iterations <= 10, "Reasonable iterations (got {$iterations})" );

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $output ) );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.1/' );

		$attachments = $xpath->query( '//item[wp:post_type="attachment"]' );
		$this->assert( $attachments->length === 5, "5 attachments with batch_size=1" );
	}

	public function test_cursor_persistence() {
		$cursor_file = $this->test_dir . '/cursor-persist.json';
		$cursor = array(
			'phase'           => 'processing',
			'items_processed' => 42,
			'next_post_id'    => 100,
			'emitted_urls'    => array( 'http://example.com/a.jpg' => true ),
			'media_added'     => 3,
		);

		WXR_Media_Extractor::save_cursor( $cursor_file, $cursor );
		$loaded = WXR_Media_Extractor::load_cursor( $cursor_file );

		$this->assert( $loaded['items_processed'] === 42, 'items_processed persisted' );
		$this->assert( $loaded['next_post_id'] === 100, 'next_post_id persisted' );
		$this->assert( $loaded['media_added'] === 3, 'media_added persisted' );
		$this->assert(
			isset( $loaded['emitted_urls']['http://example.com/a.jpg'] ),
			'emitted_urls persisted'
		);
	}

	public function test_already_complete_is_noop() {
		$input       = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output      = $this->test_dir . '/output-noop.xml';
		$cursor_file = $this->test_dir . '/cursor-noop.json';

		// Run to completion.
		$cursor = WXR_Media_Extractor::process( $input, $output, $cursor_file );
		$this->assert( $cursor['phase'] === 'complete', 'First run completes' );

		$mtime = filemtime( $output );
		sleep( 1 );

		// Run again - should be a no-op.
		$cursor2 = WXR_Media_Extractor::process( $input, $output, $cursor_file );
		$this->assert( $cursor2['phase'] === 'complete', 'Second run still complete' );
		$this->assert( filemtime( $output ) === $mtime, 'Output not modified on re-run' );
	}

	// ── Content preservation tests ────────────────────────────────────

	public function test_output_preserves_original_content() {
		$input  = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-preserve.xml';

		WXR_Media_Extractor::process( $input, $output );

		$output_content = file_get_contents( $output );

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
		$this->assert(
			strpos( $output_content, 'Here is an image:' ) !== false,
			'Original post content preserved'
		);
		$this->assert(
			strpos( $output_content, '</channel>' ) !== false,
			'Closing </channel> tag present'
		);
		$this->assert(
			strpos( $output_content, '</rss>' ) !== false,
			'Closing </rss> tag present'
		);
	}

	public function test_attachments_appear_inline_after_items() {
		$input  = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-inline.xml';

		WXR_Media_Extractor::process( $input, $output );

		$content = file_get_contents( $output );

		// The first item ("Post with Images") should be followed by its
		// attachment items before the second item appears.
		$first_item_end = strpos( $content, '</item>' );
		$this->assert( $first_item_end !== false, 'First </item> found' );

		// The attachment for photo.jpg should appear right after the first item.
		$photo_att = strpos( $content, '<wp:attachment_url>http://example.com/wp-content/uploads/2024/01/photo.jpg</wp:attachment_url>' );
		$this->assert( $photo_att !== false, 'photo.jpg attachment found in output' );
		$this->assert(
			$photo_att > $first_item_end,
			'photo.jpg attachment appears after first post item'
		);

		// And before the second original item.
		$second_item_title = strpos( $content, '<title>Post with Resized Image</title>' );
		$this->assert(
			$photo_att < $second_item_title,
			'photo.jpg attachment appears before second post'
		);
	}

	public function test_deduplicates_across_items() {
		$input  = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output = $this->test_dir . '/output-dedup.xml';

		WXR_Media_Extractor::process( $input, $output );

		$content = file_get_contents( $output );

		// banner.png appears in both post 1 and post 4 (page with video).
		// It should only get one attachment item.
		$count = substr_count(
			$content,
			'<wp:attachment_url>http://example.com/wp-content/uploads/2024/02/banner.png</wp:attachment_url>'
		);
		$this->assert( $count === 1, "banner.png emitted exactly once (got {$count})" );

		// photo.jpg appears as original in post 1 and as -300x200 in post 2.
		// Should be emitted once (normalized).
		$count = substr_count(
			$content,
			'<wp:attachment_url>http://example.com/wp-content/uploads/2024/01/photo.jpg</wp:attachment_url>'
		);
		$this->assert( $count === 1, "photo.jpg emitted exactly once (got {$count})" );
	}

	// ── Cursor memory test ────────────────────────────────────────────

	public function test_cursor_stores_only_urls() {
		$input       = $this->data_dir . '/export-with-images-no-attachments.xml';
		$output      = $this->test_dir . '/output-cursor-mem.xml';
		$cursor_file = $this->test_dir . '/cursor-mem.json';

		WXR_Media_Extractor::process( $input, $output, $cursor_file );

		$cursor_data = json_decode( file_get_contents( $cursor_file ), true );

		// emitted_urls should only contain URL keys with boolean values.
		$this->assert(
			is_array( $cursor_data['emitted_urls'] ),
			'emitted_urls is an array'
		);
		foreach ( $cursor_data['emitted_urls'] as $url => $val ) {
			$this->assert(
				is_string( $url ) && $val === true,
				"emitted_urls entry is url=>true: {$url}"
			);
		}

		// No 'discovered_media' or 'existing_attachment_urls' keys.
		$this->assert(
			! isset( $cursor_data['discovered_media'] ),
			'No discovered_media in cursor (old field absent)'
		);
		$this->assert(
			! isset( $cursor_data['existing_attachment_urls'] ),
			'No existing_attachment_urls in cursor (old field absent)'
		);
	}
}

// --- Run tests ---
$tests = new WXR_Media_Extractor_Tests();
$success = $tests->run();
exit( $success ? 0 : 1 );
