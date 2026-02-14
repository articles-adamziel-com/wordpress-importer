<?php
/**
 * WXR Media Extractor
 *
 * Scans a WXR export file for media (images, video, audio, documents) embedded
 * in post content and generates corresponding attachment items so the WordPress
 * Importer will download them into the uploads directory.
 *
 * Uses streaming XML processing (XMLReader) with cursor-based pause/resume
 * support for handling very large WXR files.
 *
 * Usage:
 *   // One-shot processing:
 *   WXR_Media_Extractor::process( 'input.xml', 'output.xml' );
 *
 *   // Batch processing with pause/resume:
 *   do {
 *       $cursor = WXR_Media_Extractor::process( 'input.xml', 'output.xml', 'cursor.json', 100 );
 *   } while ( $cursor['phase'] !== 'complete' );
 *
 * @package WordPress_Importer
 */

/**
 * Transforms a WXR file by adding attachment items for media referenced in
 * post content but not present as separate attachment entries.
 */
class WXR_Media_Extractor {

	/**
	 * Known media file extensions.
	 *
	 * @var array
	 */
	private static $media_extensions = array(
		// Images.
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'tif', 'avif', 'heic',
		// Video.
		'mp4', 'webm', 'ogv', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'm4v',
		// Audio.
		'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma',
		// Documents.
		'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
	);

	/**
	 * Default cursor state.
	 *
	 * @var array
	 */
	private static $default_cursor = array(
		'phase'                   => 'scan',
		'items_processed'         => 0,
		'max_post_id'             => 0,
		'existing_attachment_urls' => array(),
		'discovered_media'        => array(),
		'scan_complete'           => false,
		'transform_complete'      => false,
		'wp_namespace'            => 'http://wordpress.org/export/1.1/',
	);

	/**
	 * Run both scan and transform phases.
	 *
	 * When batch_size is 0, processes the entire file in one call.
	 * When batch_size > 0, processes that many items per call and saves
	 * progress to cursor_file so the caller can resume later.
	 *
	 * @param string      $input_file  Path to input WXR file.
	 * @param string      $output_file Path to output WXR file.
	 * @param string|null $cursor_file Path to cursor JSON file for pause/resume.
	 * @param int         $batch_size  Items to process per call (0 = unlimited).
	 * @return array Cursor state after processing.
	 */
	public static function process( $input_file, $output_file, $cursor_file = null, $batch_size = 0 ) {
		// When no cursor file is provided, use a temp file so state flows
		// between the scan and transform phases within this call.
		$temp_cursor = false;
		if ( null === $cursor_file ) {
			$cursor_file = tempnam( sys_get_temp_dir(), 'wxr_cursor_' );
			$temp_cursor = true;
		}

		$cursor = self::load_cursor( $cursor_file );

		// Phase 1: Scan.
		if ( ! $cursor['scan_complete'] ) {
			$cursor = self::scan( $input_file, $cursor_file, $batch_size );

			if ( ! $cursor['scan_complete'] ) {
				if ( $temp_cursor ) {
					@unlink( $cursor_file );
				}
				return $cursor;
			}
		}

		// Phase 2: Transform.
		if ( ! $cursor['transform_complete'] ) {
			$cursor = self::transform( $input_file, $output_file, $cursor_file );
		}

		if ( $temp_cursor ) {
			@unlink( $cursor_file );
		}

		return $cursor;
	}

	/**
	 * Phase 1: Scan the WXR file to discover media URLs and existing attachments.
	 *
	 * Streams through the file item by item using XMLReader. Only one item
	 * is in memory at a time. Progress is saved to cursor_file after each
	 * batch so processing can be paused and resumed.
	 *
	 * @param string      $input_file  Path to input WXR file.
	 * @param string|null $cursor_file Path to cursor file for pause/resume.
	 * @param int         $batch_size  Items to process per batch (0 = unlimited).
	 * @return array Cursor state after processing.
	 */
	public static function scan( $input_file, $cursor_file = null, $batch_size = 0 ) {
		$cursor = self::load_cursor( $cursor_file );

		if ( $cursor['scan_complete'] ) {
			return $cursor;
		}

		$reader = new XMLReader();
		if ( ! $reader->open( $input_file ) ) {
			throw new RuntimeException( "Cannot open file: {$input_file}" );
		}

		// Detect the WP namespace from the root element.
		while ( $reader->read() ) {
			if ( $reader->nodeType === XMLReader::ELEMENT && 'rss' === $reader->localName ) {
				$wp_ns = $reader->lookupNamespace( 'wp' );
				if ( $wp_ns ) {
					$cursor['wp_namespace'] = $wp_ns;
				}
				break;
			}
		}

		$items_seen    = 0;
		$items_in_batch = 0;

		while ( $reader->read() ) {
			if ( $reader->nodeType !== XMLReader::ELEMENT || 'item' !== $reader->localName ) {
				continue;
			}

			// Only process top-level <item> elements (direct children of <channel>).
			if ( $reader->depth > 2 ) {
				continue;
			}

			$items_seen++;

			// Skip already-processed items when resuming.
			if ( $items_seen <= $cursor['items_processed'] ) {
				// Use next() to skip over this element's subtree efficiently.
				$reader->next();
				continue;
			}

			// Read the full <item> XML. Only one item in memory at a time.
			$item_xml = $reader->readOuterXml();
			$item     = self::parse_item_xml( $item_xml, $cursor['wp_namespace'] );

			// Track the highest post_id for generating new unique IDs.
			if ( ! empty( $item['post_id'] ) ) {
				$cursor['max_post_id'] = max( $cursor['max_post_id'], (int) $item['post_id'] );
			}

			// Record existing attachment URLs so we don't create duplicates.
			if ( ! empty( $item['post_type'] ) && 'attachment' === $item['post_type'] ) {
				$url = '';
				if ( ! empty( $item['attachment_url'] ) ) {
					$url = $item['attachment_url'];
				} elseif ( ! empty( $item['guid'] ) ) {
					$url = $item['guid'];
				}
				if ( $url ) {
					$cursor['existing_attachment_urls'][ $url ] = true;
				}
			}

			// Extract media URLs from the post content.
			if ( ! empty( $item['content'] ) ) {
				$urls = self::extract_media_urls( $item['content'] );
				foreach ( $urls as $url ) {
					if ( ! isset( $cursor['discovered_media'][ $url ] ) ) {
						$cursor['discovered_media'][ $url ] = array(
							'title'     => self::url_to_title( $url ),
							'post_date' => ! empty( $item['post_date'] ) ? $item['post_date'] : '',
						);
					}
				}
			}

			$cursor['items_processed'] = $items_seen;
			$items_in_batch++;

			// Check batch limit for pause/resume.
			if ( $batch_size > 0 && $items_in_batch >= $batch_size ) {
				$cursor['scan_complete'] = false;
				self::save_cursor( $cursor_file, $cursor );
				$reader->close();
				return $cursor;
			}

			// Skip past this item's subtree.
			$reader->next();
		}

		$reader->close();

		// Remove discovered URLs that already have attachment items.
		foreach ( $cursor['existing_attachment_urls'] as $url => $_ ) {
			unset( $cursor['discovered_media'][ $url ] );
		}

		$cursor['scan_complete'] = true;
		self::save_cursor( $cursor_file, $cursor );
		return $cursor;
	}

	/**
	 * Phase 2: Transform the WXR file by injecting attachment items.
	 *
	 * Streams the input file to the output file, inserting generated
	 * attachment items for discovered media URLs before the closing
	 * </channel> tag.
	 *
	 * @param string      $input_file  Path to input WXR file.
	 * @param string      $output_file Path to output WXR file.
	 * @param string|null $cursor_file Path to cursor file.
	 * @return array Cursor state after transform.
	 */
	public static function transform( $input_file, $output_file, $cursor_file = null ) {
		$cursor = self::load_cursor( $cursor_file );

		if ( ! $cursor['scan_complete'] ) {
			throw new RuntimeException(
				'Scan phase must complete before transform. Run scan() first.'
			);
		}

		if ( $cursor['transform_complete'] ) {
			return $cursor;
		}

		// Filter out URLs that already have attachment items.
		$new_media = array();
		foreach ( $cursor['discovered_media'] as $url => $info ) {
			if ( ! isset( $cursor['existing_attachment_urls'][ $url ] ) ) {
				$new_media[ $url ] = $info;
			}
		}

		// Generate attachment XML for each new media URL.
		$next_id         = $cursor['max_post_id'] + 1;
		$attachments_xml = '';
		foreach ( $new_media as $url => $info ) {
			$attachments_xml .= self::generate_attachment_xml( $url, $next_id++, $info );
		}

		// Stream input to output, injecting attachments before </channel>.
		$in  = fopen( $input_file, 'r' );
		$out = fopen( $output_file, 'w' );

		if ( ! $in || ! $out ) {
			throw new RuntimeException( 'Cannot open input or output file.' );
		}

		$buffer         = '';
		$injection_done = false;
		$chunk_size     = 8192;

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, $chunk_size );
			if ( false === $chunk ) {
				break;
			}
			$buffer .= $chunk;

			if ( ! $injection_done ) {
				$pos = strpos( $buffer, '</channel>' );
				if ( false !== $pos ) {
					// Write everything before </channel>.
					fwrite( $out, substr( $buffer, 0, $pos ) );
					// Inject the attachment items.
					fwrite( $out, $attachments_xml );
					// Write </channel> and the rest.
					fwrite( $out, substr( $buffer, $pos ) );
					$buffer         = '';
					$injection_done = true;
					continue;
				}

				// Keep the tail of the buffer in case </channel> spans chunks.
				if ( strlen( $buffer ) > 20 ) {
					fwrite( $out, substr( $buffer, 0, -20 ) );
					$buffer = substr( $buffer, -20 );
				}
			} else {
				fwrite( $out, $buffer );
				$buffer = '';
			}
		}

		// Flush remaining buffer.
		if ( strlen( $buffer ) > 0 ) {
			if ( ! $injection_done ) {
				// Last chance to find </channel>.
				$pos = strpos( $buffer, '</channel>' );
				if ( false !== $pos ) {
					fwrite( $out, substr( $buffer, 0, $pos ) );
					fwrite( $out, $attachments_xml );
					fwrite( $out, substr( $buffer, $pos ) );
				} else {
					// No </channel> found; append attachments at end of file.
					fwrite( $out, $buffer );
				}
			} else {
				fwrite( $out, $buffer );
			}
		}

		fclose( $in );
		fclose( $out );

		$cursor['transform_complete'] = true;
		$cursor['phase']              = 'complete';
		self::save_cursor( $cursor_file, $cursor );
		return $cursor;
	}

	/**
	 * Parse a single <item> XML string into an associative array.
	 *
	 * Uses regex extraction to avoid namespace complications when parsing
	 * fragments outside of their parent document context.
	 *
	 * @param string $item_xml Raw XML string of an <item> element.
	 * @param string $wp_ns   The WP namespace URI (unused, kept for future use).
	 * @return array Parsed item fields.
	 */
	private static function parse_item_xml( $item_xml, $wp_ns = '' ) {
		$item = array(
			'post_id'        => '',
			'post_type'      => '',
			'post_date'      => '',
			'content'        => '',
			'attachment_url' => '',
			'guid'           => '',
		);

		// Extract wp:post_id.
		if ( preg_match( '/<wp:post_id>(\d+)<\/wp:post_id>/', $item_xml, $m ) ) {
			$item['post_id'] = $m[1];
		}

		// Extract wp:post_type.
		if ( preg_match( '/<wp:post_type>([^<]+)<\/wp:post_type>/', $item_xml, $m ) ) {
			$item['post_type'] = trim( $m[1] );
		}

		// Extract wp:post_date.
		if ( preg_match( '/<wp:post_date>(?:<!\[CDATA\[)?([^\]<]+)(?:\]\]>)?<\/wp:post_date>/', $item_xml, $m ) ) {
			$item['post_date'] = trim( $m[1] );
		}

		// Extract content:encoded (CDATA or plain).
		if ( preg_match( '/<content:encoded><!\[CDATA\[(.*?)\]\]><\/content:encoded>/s', $item_xml, $m ) ) {
			$item['content'] = $m[1];
		} elseif ( preg_match( '/<content:encoded>(.*?)<\/content:encoded>/s', $item_xml, $m ) ) {
			$item['content'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		// Extract wp:attachment_url.
		if ( preg_match( '/<wp:attachment_url>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/wp:attachment_url>/', $item_xml, $m ) ) {
			$item['attachment_url'] = trim( $m[1] );
		}

		// Extract guid.
		if ( preg_match( '/<guid[^>]*>([^<]+)<\/guid>/', $item_xml, $m ) ) {
			$item['guid'] = trim( $m[1] );
		}

		return $item;
	}

	/**
	 * Extract media URLs from HTML content.
	 *
	 * Finds URLs in:
	 * - <img src="..."> and <img srcset="..."> attributes
	 * - <video src="...">, <audio src="...">, <source src="..."> attributes
	 * - <a href="..."> links to media files
	 *
	 * Normalizes resized image URLs (e.g., image-300x200.jpg) back to their
	 * original filenames to avoid duplicate attachment entries.
	 *
	 * @param string $content HTML content to scan.
	 * @return array List of unique media URLs.
	 */
	public static function extract_media_urls( $content ) {
		$urls = array();

		if ( empty( $content ) ) {
			return $urls;
		}

		// Match <img> src attributes.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Match <img> srcset attributes (comma-separated list of "url size").
		if ( preg_match_all( '/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $srcset ) {
				$entries = explode( ',', $srcset );
				foreach ( $entries as $entry ) {
					$parts = preg_split( '/\s+/', trim( $entry ) );
					if ( ! empty( $parts[0] ) ) {
						$urls[] = $parts[0];
					}
				}
			}
		}

		// Match <video>, <audio>, <source> src attributes.
		if ( preg_match_all( '/<(?:video|audio|source)[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Match <a href="..."> links to media files.
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Filter to actual media URLs, deduplicate, and normalize resized variants.
		$filtered = array();
		foreach ( array_unique( $urls ) as $url ) {
			if ( self::is_media_url( $url ) ) {
				$original_url              = self::get_original_url( $url );
				$filtered[ $original_url ] = true;
			}
		}

		return array_keys( $filtered );
	}

	/**
	 * Check if a URL points to a media file based on its extension.
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL has a recognized media file extension.
	 */
	private static function is_media_url( $url ) {
		// Must be an absolute HTTP(S) URL.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$path = parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::$media_extensions, true );
	}

	/**
	 * Strip WordPress resized image suffixes to get the original URL.
	 *
	 * WordPress generates thumbnails with a -WIDTHxHEIGHT suffix before the
	 * extension (e.g., photo-300x200.jpg). We strip this to reference the
	 * original upload so the importer downloads the full-size image.
	 *
	 * @param string $url URL that may contain a resize suffix.
	 * @return string URL with resize suffix removed.
	 */
	private static function get_original_url( $url ) {
		return preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
	}

	/**
	 * Generate a human-readable title from a media URL.
	 *
	 * @param string $url Media URL.
	 * @return string Title derived from the filename.
	 */
	private static function url_to_title( $url ) {
		$path = parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return basename( $url );
		}
		$filename = pathinfo( $path, PATHINFO_FILENAME );
		return str_replace( array( '-', '_' ), ' ', $filename );
	}

	/**
	 * Generate a URL-safe slug from a title.
	 *
	 * @param string $title Title to convert.
	 * @return string Slug.
	 */
	private static function simple_slug( $title ) {
		$slug = strtolower( $title );
		$slug = preg_replace( '/[^a-z0-9\s-]/', '', $slug );
		$slug = preg_replace( '/[\s]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		return $slug;
	}

	/**
	 * Generate WXR XML for an attachment item.
	 *
	 * The generated XML matches the format that the WordPress Importer expects:
	 * an <item> with wp:post_type=attachment and wp:attachment_url pointing to
	 * the remote media file. When imported, the importer will download the file
	 * and create a local attachment post.
	 *
	 * @param string $url     Remote media URL.
	 * @param int    $post_id Post ID to assign.
	 * @param array  $info    Optional info array with 'title' and 'post_date'.
	 * @return string XML string for the attachment item.
	 */
	private static function generate_attachment_xml( $url, $post_id, $info = array() ) {
		$title     = ! empty( $info['title'] ) ? $info['title'] : self::url_to_title( $url );
		$post_date = ! empty( $info['post_date'] ) ? $info['post_date'] : gmdate( 'Y-m-d H:i:s' );
		$post_name = self::simple_slug( $title );

		$escaped_url   = htmlspecialchars( $url, ENT_XML1, 'UTF-8' );
		$escaped_title = htmlspecialchars( $title, ENT_XML1, 'UTF-8' );

		$pub_timestamp = strtotime( $post_date );
		$pub_date      = ( false !== $pub_timestamp )
			? gmdate( 'D, d M Y H:i:s +0000', $pub_timestamp )
			: gmdate( 'D, d M Y H:i:s +0000' );

		$xml  = "\t<item>\n";
		$xml .= "\t\t<title>{$escaped_title}</title>\n";
		$xml .= "\t\t<link>{$escaped_url}</link>\n";
		$xml .= "\t\t<pubDate>{$pub_date}</pubDate>\n";
		$xml .= "\t\t<dc:creator><![CDATA[admin]]></dc:creator>\n";
		$xml .= "\t\t<guid isPermaLink=\"false\">{$escaped_url}</guid>\n";
		$xml .= "\t\t<description></description>\n";
		$xml .= "\t\t<content:encoded><![CDATA[]]></content:encoded>\n";
		$xml .= "\t\t<excerpt:encoded><![CDATA[]]></excerpt:encoded>\n";
		$xml .= "\t\t<wp:post_id>{$post_id}</wp:post_id>\n";
		$xml .= "\t\t<wp:post_date><![CDATA[{$post_date}]]></wp:post_date>\n";
		$xml .= "\t\t<wp:post_date_gmt><![CDATA[{$post_date}]]></wp:post_date_gmt>\n";
		$xml .= "\t\t<wp:comment_status>closed</wp:comment_status>\n";
		$xml .= "\t\t<wp:ping_status>closed</wp:ping_status>\n";
		$xml .= "\t\t<wp:post_name><![CDATA[{$post_name}]]></wp:post_name>\n";
		$xml .= "\t\t<wp:status>inherit</wp:status>\n";
		$xml .= "\t\t<wp:post_parent>0</wp:post_parent>\n";
		$xml .= "\t\t<wp:menu_order>0</wp:menu_order>\n";
		$xml .= "\t\t<wp:post_type>attachment</wp:post_type>\n";
		$xml .= "\t\t<wp:post_password></wp:post_password>\n";
		$xml .= "\t\t<wp:is_sticky>0</wp:is_sticky>\n";
		$xml .= "\t\t<wp:attachment_url>{$escaped_url}</wp:attachment_url>\n";
		$xml .= "\t</item>\n";

		return $xml;
	}

	/**
	 * Load cursor state from a JSON file.
	 *
	 * @param string|null $cursor_file Path to cursor file.
	 * @return array Cursor state.
	 */
	public static function load_cursor( $cursor_file ) {
		if ( $cursor_file && file_exists( $cursor_file ) ) {
			$data = json_decode( file_get_contents( $cursor_file ), true );
			if ( is_array( $data ) ) {
				return array_merge( self::$default_cursor, $data );
			}
		}
		return self::$default_cursor;
	}

	/**
	 * Save cursor state to a JSON file.
	 *
	 * @param string|null $cursor_file Path to cursor file.
	 * @param array       $cursor      Cursor state to save.
	 */
	public static function save_cursor( $cursor_file, $cursor ) {
		if ( $cursor_file ) {
			file_put_contents(
				$cursor_file,
				json_encode( $cursor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
		}
	}
}
