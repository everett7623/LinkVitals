<?php
/**
 * Link Extractor class
 *
 * Parses HTML content via DOMDocument to extract URLs from various HTML tags and attributes.
 * Handles both Gutenberg block content and Classic Editor HTML.
 *
 * Supported tags/attributes:
 * - a[href], img[src], iframe[src], embed[src], object[data], video[src], audio[src]
 * - source[srcset], img[srcset] (parses comma-separated descriptors)
 *
 * @package LinkVitals
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LHA_Link_Extractor
 *
 * DOM-based link extraction with URL resolution and classification.
 */
class LHA_Link_Extractor {

	/**
	 * File extensions considered as downloads.
	 *
	 * @var array<string>
	 */
	private const DOWNLOAD_EXTENSIONS = array(
		'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
		'zip', 'rar', '7z', 'tar', 'gz',
		'csv', 'txt', 'rtf', 'odt', 'ods',
	);

	/**
	 * File extensions considered as media.
	 *
	 * @var array<string>
	 */
	private const MEDIA_EXTENSIONS = array(
		'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
		'ogg', 'wav', 'aac', 'flac', 'm4a',
	);

	/**
	 * Image file extensions.
	 *
	 * @var array<string>
	 */
	private const IMAGE_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico', 'avif',
	);

	/**
	 * Tag/attribute pairs for standard URL extraction.
	 *
	 * @var array<string, string>
	 */
	private const TAG_ATTRIBUTE_MAP = array(
		'a'      => 'href',
		'img'    => 'src',
		'iframe' => 'src',
		'embed'  => 'src',
		'object' => 'data',
		'video'  => 'src',
		'audio'  => 'src',
	);

	/**
	 * Extract all links from HTML content.
	 *
	 * Parses the content as a DOM document and extracts URLs from supported
	 * tag/attribute pairs. Works with both Gutenberg block content and
	 * Classic Editor HTML since both produce standard HTML output.
	 *
	 * @param string $content    HTML content to parse.
	 * @param string $source_url URL of the source page (for resolving relative URLs).
	 * @return array<int, array{url: string, html_tag: string, attribute_name: string, anchor_text: string, raw_html: string, link_type: string}> Array of extracted link data.
	 */
	public function extract( string $content, string $source_url = '' ): array {
		if ( empty( trim( $content ) ) ) {
			return array();
		}

		// Suppress HTML parsing errors from malformed content.
		$previous_errors = libxml_use_internal_errors( true );

		$dom = new DOMDocument();

		// Load HTML with UTF-8 encoding support.
		// The XML encoding declaration ensures proper UTF-8 handling.
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div>' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		$links = array();

		// Extract from standard tag/attribute pairs.
		foreach ( self::TAG_ATTRIBUTE_MAP as $tag => $attribute ) {
			$links = array_merge( $links, $this->extract_from_tag( $dom, $tag, $attribute ) );
		}

		// Extract from srcset attributes (source and img tags).
		$links = array_merge( $links, $this->extract_srcset( $dom ) );

		// Resolve relative URLs and classify each link.
		foreach ( $links as &$link ) {
			$link['url']       = $this->resolve_url( $link['url'], $source_url );
			$link['link_type'] = $this->classify_link( $link['url'], $link['html_tag'], $link['attribute_name'] );
		}
		unset( $link );

		return $links;
	}

	/**
	 * Extract URLs from a specific tag and attribute combination.
	 *
	 * @param DOMDocument $dom       The parsed DOM document.
	 * @param string      $tag       HTML tag name to search for.
	 * @param string      $attribute Attribute name containing the URL.
	 * @return array<int, array> Array of link data arrays.
	 */
	private function extract_from_tag( DOMDocument $dom, string $tag, string $attribute ): array {
		$links    = array();
		$elements = $dom->getElementsByTagName( $tag );

		foreach ( $elements as $element ) {
			if ( ! $element instanceof DOMElement ) {
				continue;
			}

			$url = $element->getAttribute( $attribute );

			// For anchor tags, record even empty href (classified as 'empty' type).
			if ( '' === $url && 'a' === $tag ) {
				if ( ! $element->hasAttribute( $attribute ) ) {
					continue;
				}
				$links[] = array(
					'url'            => '',
					'html_tag'       => $tag,
					'attribute_name' => $attribute,
					'anchor_text'    => $this->get_anchor_text( $element ),
					'raw_html'       => $this->get_outer_html( $dom, $element ),
					'link_type'      => '',
				);
				continue;
			}

			if ( '' === $url ) {
				continue;
			}

			$anchor_text = '';
			if ( 'a' === $tag ) {
				$anchor_text = $this->get_anchor_text( $element );
			} elseif ( 'img' === $tag ) {
				$anchor_text = $element->getAttribute( 'alt' );
			}

			$links[] = array(
				'url'            => trim( $url ),
				'html_tag'       => $tag,
				'attribute_name' => $attribute,
				'anchor_text'    => $anchor_text,
				'raw_html'       => $this->get_outer_html( $dom, $element ),
				'link_type'      => '',
			);
		}

		return $links;
	}

	/**
	 * Extract URLs from srcset attributes on source and img elements.
	 *
	 * Parses the comma-separated srcset format: "url1 1x, url2 2x"
	 * or "url1 300w, url2 600w". Extracts the URL portion from each descriptor.
	 *
	 * @param DOMDocument $dom The parsed DOM document.
	 * @return array<int, array> Array of link data arrays.
	 */
	private function extract_srcset( DOMDocument $dom ): array {
		$links = array();

		// Process both <source> and <img> elements for srcset.
		$tags_with_srcset = array( 'source', 'img' );

		foreach ( $tags_with_srcset as $tag ) {
			$elements = $dom->getElementsByTagName( $tag );

			foreach ( $elements as $element ) {
				if ( ! $element instanceof DOMElement ) {
					continue;
				}

				$srcset = $element->getAttribute( 'srcset' );
				if ( empty( $srcset ) ) {
					continue;
				}

				$anchor_text = '';
				if ( 'img' === $tag ) {
					$anchor_text = $element->getAttribute( 'alt' );
				}

				$raw_html = $this->get_outer_html( $dom, $element );

				// Parse srcset: comma-separated entries of "url [descriptor]".
				$entries = explode( ',', $srcset );
				foreach ( $entries as $entry ) {
					$entry = trim( $entry );
					if ( empty( $entry ) ) {
						continue;
					}

					// Split on whitespace — first part is the URL.
					$parts = preg_split( '/\s+/', $entry, 2 );
					if ( ! empty( $parts[0] ) ) {
						$links[] = array(
							'url'            => trim( $parts[0] ),
							'html_tag'       => $tag,
							'attribute_name' => 'srcset',
							'anchor_text'    => $anchor_text,
							'raw_html'       => $raw_html,
							'link_type'      => '',
						);
					}
				}
			}
		}

		return $links;
	}

	/**
	 * Get the text content of an anchor element.
	 *
	 * Collapses whitespace and truncates to 500 characters.
	 *
	 * @param DOMElement $element The anchor element.
	 * @return string Normalized text content.
	 */
	private function get_anchor_text( DOMElement $element ): string {
		$text = $element->textContent;
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return mb_substr( $text, 0, 500, 'UTF-8' );
	}

	/**
	 * Get the outer HTML of an element.
	 *
	 * Truncates to 1000 characters to avoid storing very large HTML blocks.
	 *
	 * @param DOMDocument $dom     The parent DOM document.
	 * @param DOMElement  $element The element to serialize.
	 * @return string HTML string of the element.
	 */
	private function get_outer_html( DOMDocument $dom, DOMElement $element ): string {
		$html = $dom->saveHTML( $element );
		return mb_substr( $html, 0, 1000, 'UTF-8' );
	}

	/**
	 * Resolve a relative URL to an absolute URL using the source page URL as base.
	 *
	 * Handles:
	 * - Already-absolute URLs (http/https)
	 * - Special schemes (mailto, tel, javascript, data, ftp)
	 * - Fragment-only links (#anchor)
	 * - Protocol-relative URLs (//example.com)
	 * - Absolute paths (/path/to/page)
	 * - Relative paths (path/to/page)
	 *
	 * @param string $url      The URL to resolve.
	 * @param string $base_url The base URL of the source page.
	 * @return string The resolved absolute URL.
	 */
	public function resolve_url( string $url, string $base_url ): string {
		$url = trim( $url );

		// Already absolute HTTP(S) URL.
		if ( preg_match( '/^https?:\/\//i', $url ) ) {
			return $url;
		}

		// Special schemes — don't resolve.
		if ( preg_match( '/^(mailto|tel|javascript|data|ftp):/i', $url ) ) {
			return $url;
		}

		// Empty URL.
		if ( '' === $url ) {
			return '';
		}

		// No base URL available — can't resolve.
		if ( empty( $base_url ) ) {
			return $url;
		}

		// Fragment-only link (same-page anchor).
		if ( str_starts_with( $url, '#' ) ) {
			// Strip any existing fragment from base before appending.
			$base_without_fragment = preg_replace( '/#.*$/', '', $base_url );
			return $base_without_fragment . $url;
		}

		// Protocol-relative URL.
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
			$scheme = $scheme ?: 'https';
			return $scheme . ':' . $url;
		}

		// Absolute path (starts with /).
		if ( str_starts_with( $url, '/' ) ) {
			return $this->resolve_relative_url( $url, $base_url );
		}

		// Relative path — resolve against base URL directory.
		return $this->resolve_relative_url( $url, $base_url );
	}

	/** Resolve a root-relative or path-relative URL against an absolute base URL. */
	private function resolve_relative_url( string $url, string $base_url ): string {
		$parsed = wp_parse_url( $base_url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return $url;
		}

		$relative = wp_parse_url( $url );
		if ( false === $relative ) {
			return $url;
		}

		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'];
		$port   = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
		$path   = (string) ( $parsed['path'] ?? '/' );
		$relative_path = (string) ( $relative['path'] ?? '' );

		if ( str_starts_with( $relative_path, '/' ) ) {
			$resolved_path = $relative_path;
		} elseif ( '' === $relative_path ) {
			$resolved_path = $path;
		} else {
			$base_dir = preg_replace( '/\/[^\/]*$/', '/', $path );
			$resolved_path = ( $base_dir ?: '/' ) . $relative_path;
		}

		$resolved_path = $this->normalize_path( $resolved_path );
		$query = array_key_exists( 'query', $relative )
			? '?' . $relative['query']
			: ( '' === $relative_path && isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
		$fragment = isset( $relative['fragment'] ) ? '#' . $relative['fragment'] : '';

		return $scheme . '://' . $host . $port . $resolved_path . $query . $fragment;
	}

	/** Collapse dot segments in an absolute URL path while preserving a trailing slash. */
	private function normalize_path( string $path ): string {
		$has_trailing_slash = str_ends_with( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		$normalized = '/' . implode( '/', $segments );
		if ( $has_trailing_slash && '/' !== $normalized ) {
			$normalized .= '/';
		}

		return $normalized;
	}

	/**
	 * Classify a link into one of the supported link types.
	 *
	 * Classification priority:
	 * 1. empty — URL is empty
	 * 2. mailto — starts with mailto:
	 * 3. tel — starts with tel:
	 * 4. javascript — starts with javascript:
	 * 5. malformed — not a valid HTTP(S) URL
	 * 6. anchor — same-site URL with fragment identifier
	 * 7. image — image extension or img tag
	 * 8. download — download file extension
	 * 9. media — media extension or video/audio tag
	 * 10. internal — same domain as site
	 * 11. external — different domain
	 *
	 * @param string $url       The resolved URL to classify.
	 * @param string $tag       The HTML tag the URL was found in.
	 * @param string $attribute The attribute name containing the URL.
	 * @return string The link type classification.
	 */
	public function classify_link( string $url, string $tag, string $attribute ): string {
		// Empty URL.
		if ( '' === $url ) {
			return 'empty';
		}

		// Special schemes.
		if ( preg_match( '/^mailto:/i', $url ) ) {
			return 'mailto';
		}
		if ( preg_match( '/^tel:/i', $url ) ) {
			return 'tel';
		}
		if ( preg_match( '/^javascript:/i', $url ) ) {
			return 'javascript';
		}

		// Malformed: not a valid HTTP(S) URL after resolution.
		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			return 'malformed';
		}

		// Anchor: same-site link with a fragment identifier.
		$site_url    = home_url();
		$site_host   = wp_parse_url( $site_url, PHP_URL_HOST );
		$parsed_url  = wp_parse_url( $url );
		$link_host   = $parsed_url['host'] ?? '';

		// Normalize hosts for comparison (strip www).
		$site_host_clean = preg_replace( '/^www\./i', '', $site_host ?? '' );
		$link_host_clean = preg_replace( '/^www\./i', '', $link_host );

		$is_same_site = ( $site_host_clean === $link_host_clean );

		if ( $is_same_site && ! empty( $parsed_url['fragment'] ) ) {
			return 'anchor';
		}

		// File extension-based classification.
		$extension = $this->get_extension( $url );

		if ( in_array( $extension, self::IMAGE_EXTENSIONS, true ) ) {
			return 'image';
		}
		if ( in_array( $extension, self::DOWNLOAD_EXTENSIONS, true ) ) {
			return 'download';
		}
		if ( in_array( $extension, self::MEDIA_EXTENSIONS, true ) ) {
			return 'media';
		}

		// Tag-based classification.
		if ( 'img' === $tag ) {
			return 'image';
		}
		if ( in_array( $tag, array( 'video', 'audio' ), true ) ) {
			return 'media';
		}

		// Internal vs external based on host comparison.
		if ( $is_same_site ) {
			return 'internal';
		}

		return 'external';
	}

	/**
	 * Get file extension from a URL path.
	 *
	 * @param string $url The URL to extract the extension from.
	 * @return string Lowercase file extension, or empty string if none.
	 */
	private function get_extension( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}
}
