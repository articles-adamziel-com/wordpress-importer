<?php
/**
 * WXR Media Extractor
 *
 * Transforms a WXR export file in a single streaming pass, injecting
 * attachment items for media referenced in post content. This enables the
 * WordPress Importer to download images/video/audio into the uploads
 * directory even when the original export lacks explicit attachment entries.
 *
 * Uses line-by-line I/O so memory usage stays flat regardless of file size.
 * Supports cursor-based pause/resume for processing very large WXR files
 * in batches.
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
 *
 * Single-pass streaming: reads line-by-line, writes as it goes, and emits
 * new attachment items immediately after each post/page item. The cursor
 * stores only the set of already-emitted URL strings and byte offsets,
 * keeping memory proportional to the number of unique media URLs rather
 * than the total file size.
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
		'phase'           => 'processing',
		'items_processed' => 0,
		'next_post_id'    => 0,
		'emitted_urls'    => array(),
		'input_offset'    => 0,
		'output_offset'   => 0,
		'media_added'     => 0,
	);

	/**
	 * Process a WXR file in a single streaming pass.
	 *
	 * Reads the input line by line, copies everything to the output, and
	 * after each <item> that contains media URLs in its content, appends
	 * new attachment items for any URLs not yet emitted.
	 *
	 * When batch_size > 0, pauses after processing that many items and
	 * saves progress to cursor_file. Re-running with the same arguments
	 * resumes from where it left off.
	 *
	 * @param string      $input_file  Path to input WXR file.
	 * @param string      $output_file Path to output WXR file.
	 * @param string|null $cursor_file Path to cursor JSON file for pause/resume.
	 * @param int         $batch_size  Items to process per call (0 = unlimited).
	 * @return array Cursor state after processing.
	 */
	public static function process( $input_file, $output_file, $cursor_file = null, $batch_size = 0 ) {
		$cursor = self::load_cursor( $cursor_file );

		if ( 'complete' === $cursor['phase'] ) {
			return $cursor;
		}

		$in = fopen( $input_file, 'r' );
		if ( ! $in ) {
			throw new RuntimeException( "Cannot open input file: {$input_file}" );
		}

		// When resuming, open output for append; otherwise truncate.
		$resuming = $cursor['input_offset'] > 0;
		$out      = fopen( $output_file, $resuming ? 'r+' : 'w' );
		if ( ! $out ) {
			fclose( $in );
			throw new RuntimeException( "Cannot open output file: {$output_file}" );
		}

		if ( $resuming ) {
			fseek( $in, $cursor['input_offset'] );
			fseek( $out, $cursor['output_offset'] );
			ftruncate( $out, $cursor['output_offset'] );
		}

		// First pass through: determine next_post_id if not set.
		// We need a post_id higher than any existing one. On the first call
		// we do a quick pre-scan for the highest post_id. This is the only
		// part that reads ahead, but it only extracts a number per item and
		// discards everything else.
		if ( 0 === $cursor['next_post_id'] && ! $resuming ) {
			$cursor['next_post_id'] = self::find_max_post_id( $input_file ) + 1;
		}

		$in_item        = false;
		$item_buffer    = '';
		$items_seen     = $resuming ? $cursor['items_processed'] : 0;
		$items_in_batch = 0;

		while ( false !== ( $line = fgets( $in, 65536 ) ) ) {
			// Detect <item> open tag.
			if ( ! $in_item && false !== strpos( $line, '<item>' ) ) {
				$in_item     = true;
				$item_buffer = '';
			}

			if ( $in_item ) {
				$item_buffer .= $line;

				// Detect </item> close tag.
				if ( false !== strpos( $line, '</item>' ) ) {
					$in_item = false;
					$items_seen++;

					// Skip items we already processed in a prior batch.
					if ( $items_seen <= $cursor['items_processed'] ) {
						// Still write the raw item through.
						fwrite( $out, $item_buffer );
						$item_buffer = '';
						continue;
					}

					// Write the original item.
					fwrite( $out, $item_buffer );

					// Parse and emit attachments for any new media URLs.
					$item = self::parse_item_xml( $item_buffer );

					// Skip if this item is itself an attachment.
					if ( 'attachment' !== $item['post_type'] && ! empty( $item['content'] ) ) {
						$media_urls = self::extract_media_urls( $item['content'] );
						foreach ( $media_urls as $url ) {
							if ( isset( $cursor['emitted_urls'][ $url ] ) ) {
								continue;
							}
							$cursor['emitted_urls'][ $url ] = true;
							$cursor['media_added']++;

							$attachment_xml = self::generate_attachment_xml(
								$url,
								$cursor['next_post_id']++,
								array(
									'title'     => self::url_to_title( $url ),
									'post_date' => $item['post_date'],
								)
							);
							fwrite( $out, $attachment_xml );
						}
					}

					// If this IS an attachment, record its URL so we don't
					// emit a duplicate for it later.
					if ( 'attachment' === $item['post_type'] ) {
						$att_url = ! empty( $item['attachment_url'] ) ? $item['attachment_url'] : $item['guid'];
						if ( $att_url ) {
							$cursor['emitted_urls'][ $att_url ] = true;
						}
					}

					$cursor['items_processed'] = $items_seen;
					$item_buffer               = '';
					$items_in_batch++;

					// Check batch limit.
					if ( $batch_size > 0 && $items_in_batch >= $batch_size ) {
						$cursor['input_offset']  = ftell( $in );
						$cursor['output_offset'] = ftell( $out );
						self::save_cursor( $cursor_file, $cursor );
						fclose( $in );
						fclose( $out );
						return $cursor;
					}
				}
			} else {
				// Outside an <item>: write through verbatim.
				fwrite( $out, $line );
			}
		}

		fclose( $in );
		fclose( $out );

		$cursor['phase'] = 'complete';
		self::save_cursor( $cursor_file, $cursor );
		return $cursor;
	}

	/**
	 * Quick pre-scan to find the highest wp:post_id in the file.
	 *
	 * Reads line-by-line, only looking for <wp:post_id> values.
	 * Does not load the file into memory.
	 *
	 * @param string $input_file Path to WXR file.
	 * @return int Highest post_id found, or 0.
	 */
	private static function find_max_post_id( $input_file ) {
		$max = 0;
		$fh  = fopen( $input_file, 'r' );
		if ( ! $fh ) {
			return $max;
		}

		while ( false !== ( $line = fgets( $fh, 65536 ) ) ) {
			if ( preg_match( '/<wp:post_id>(\d+)<\/wp:post_id>/', $line, $m ) ) {
				$id = (int) $m[1];
				if ( $id > $max ) {
					$max = $id;
				}
			}
		}

		fclose( $fh );
		return $max;
	}

	/**
	 * Parse a single <item> XML string into an associative array.
	 *
	 * Uses regex extraction to avoid namespace complications when parsing
	 * fragments outside of their parent document context. Only extracts
	 * the fields needed for media extraction.
	 *
	 * @param string $item_xml Raw XML string of an <item> element.
	 * @return array Parsed item fields.
	 */
	private static function parse_item_xml( $item_xml ) {
		$item = array(
			'post_id'        => '',
			'post_type'      => '',
			'post_date'      => '',
			'content'        => '',
			'attachment_url' => '',
			'guid'           => '',
		);

		if ( preg_match( '/<wp:post_id>(\d+)<\/wp:post_id>/', $item_xml, $m ) ) {
			$item['post_id'] = $m[1];
		}

		if ( preg_match( '/<wp:post_type>([^<]+)<\/wp:post_type>/', $item_xml, $m ) ) {
			$item['post_type'] = trim( $m[1] );
		}

		if ( preg_match( '/<wp:post_date>(?:<!\[CDATA\[)?([^\]<]+)(?:\]\]>)?<\/wp:post_date>/', $item_xml, $m ) ) {
			$item['post_date'] = trim( $m[1] );
		}

		if ( preg_match( '/<content:encoded><!\[CDATA\[(.*?)\]\]><\/content:encoded>/s', $item_xml, $m ) ) {
			$item['content'] = $m[1];
		} elseif ( preg_match( '/<content:encoded>(.*?)<\/content:encoded>/s', $item_xml, $m ) ) {
			$item['content'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		if ( preg_match( '/<wp:attachment_url>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/wp:attachment_url>/', $item_xml, $m ) ) {
			$item['attachment_url'] = trim( $m[1] );
		}

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
