<?php
/**
 * Live Copy importer: turns an untrusted pasted payload into Elementor elements for this site.
 *
 * - The JSON shape is validated: element keys are allow-listed, depth and count are limited.
 * - Element types and widgets this site does not have are left out and reported.
 * - Element IDs are regenerated. Foreign attachment IDs are never kept.
 * - Images are downloaded with wp_safe_remote_get() (http/https only, no private or local
 *   addresses, size limit), checked to be real raster images and added with
 *   media_handle_sideload(). Each source URL is imported once: later pastes reuse the
 *   attachment through its _wea_live_copy_source_hash meta.
 *
 * Nothing in the payload is ever executed.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Plugin as Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Live Copy importer.
 */
class Live_Copy_Importer {

	/**
	 * Attachment meta: original URL and its hash (for the duplicate check).
	 */
	const META_SOURCE = '_wea_live_copy_source';
	const META_HASH   = '_wea_live_copy_source_hash';

	/**
	 * Limits.
	 */
	const MAX_DEPTH          = 30;
	const MAX_ELEMENTS       = 1500;
	const MAX_SETTINGS_DEPTH = 20;

	/**
	 * Element keys kept from the payload. Everything else (htmlCache, editSettings…) is dropped.
	 */
	const ELEMENT_KEYS = [ 'id', 'elType', 'widgetType', 'isInner', 'settings', 'elements', 'styles', 'editor_settings', 'version', 'interactions' ];

	/**
	 * Raster image types that may be imported. SVG is never imported (it can carry scripts).
	 */
	const IMAGE_MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	];

	/**
	 * Import images into the media library.
	 *
	 * @var bool
	 */
	private $import_images;

	/**
	 * Post the new attachments are uploaded to (0 for none).
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Elements seen so far.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * New element IDs given out in this request.
	 *
	 * @var array
	 */
	private $ids = [];

	/**
	 * Source URL => [ id, url ] or WP_Error, for this request.
	 *
	 * @var array
	 */
	private $images = [];

	/**
	 * Request start, for the download time budget.
	 *
	 * @var float
	 */
	private $started;

	/**
	 * Report counters.
	 *
	 * @var array
	 */
	private $report = [
		'images_imported' => false,
		'imported'        => 0,
		'reused'          => 0,
		'linked'          => 0,
		'failed'          => 0,
		'failures'        => [],
		'skipped'         => 0,
		'skipped_types'   => [],
	];

	/**
	 * Constructor.
	 *
	 * @param array $args { import_images: bool, post_id: int }.
	 */
	public function __construct( array $args ) {
		$this->import_images = ! empty( $args['import_images'] );
		$this->post_id       = isset( $args['post_id'] ) ? max( 0, (int) $args['post_id'] ) : 0;
		$this->started       = microtime( true );

		$this->report['images_imported'] = $this->import_images;
	}

	/* --------------------------------------------------------------------- *
	 * Payload
	 * --------------------------------------------------------------------- */

	/**
	 * Decode and validate a pasted payload.
	 *
	 * Accepts the Live Copy format and Elementor's own copy format ({ type: "elementor", elements }).
	 *
	 * @param string $json Raw clipboard text.
	 * @return array|\WP_Error { elements: array, source: string }
	 */
	public static function decode( $json ) {
		$invalid = new \WP_Error( 'wea_live_copy_invalid', __( 'The clipboard does not contain an element copied with Wise Copy or Elementor.', 'wise-addons-for-elementor-pro' ), [ 'status' => 400 ] );

		if ( ! is_string( $json ) || '' === trim( $json ) || strlen( $json ) > Live_Copy::MAX_PAYLOAD_BYTES ) {
			return $invalid;
		}

		$data = json_decode( $json, true, 512 );
		if ( ! is_array( $data ) ) {
			return $invalid;
		}

		$is_wise      = isset( $data[ Live_Copy::MARKER ] ) && 1 === (int) $data[ Live_Copy::MARKER ];
		$is_elementor = isset( $data['type'] ) && 'elementor' === $data['type'];
		if ( ! $is_wise && ! $is_elementor ) {
			return $invalid;
		}

		if ( $is_wise && isset( $data['version'] ) && (int) $data['version'] > Live_Copy::FORMAT ) {
			return new \WP_Error( 'wea_live_copy_version', __( 'This content was copied with a newer version of Wise Addons Pro. Update the plugin on this site and paste again.', 'wise-addons-for-elementor-pro' ), [ 'status' => 400 ] );
		}

		if ( empty( $data['elements'] ) || ! is_array( $data['elements'] ) || ! wp_is_numeric_array( $data['elements'] ) ) {
			return $invalid;
		}

		foreach ( $data['elements'] as $element ) {
			if ( ! is_array( $element ) || empty( $element['elType'] ) || ! is_string( $element['elType'] ) ) {
				return $invalid;
			}
		}

		$source = isset( $data['source'] ) && is_string( $data['source'] ) ? esc_url_raw( $data['source'], [ 'http', 'https' ] ) : '';

		return [
			'elements' => array_values( $data['elements'] ),
			'source'   => $source,
		];
	}

	/**
	 * Report for the editor.
	 *
	 * @return array
	 */
	public function report() {
		$report                  = $this->report;
		$report['skipped_types'] = array_values( array_unique( $report['skipped_types'] ) );
		$report['failures']      = array_slice( $report['failures'], 0, 10 );
		return $report;
	}

	/* --------------------------------------------------------------------- *
	 * Elements
	 * --------------------------------------------------------------------- */

	/**
	 * Prepare elements for this site.
	 *
	 * @param array $elements Decoded elements.
	 * @return array Clean elements (may be empty).
	 */
	public function import( array $elements ) {
		$clean = $this->clean_elements( $elements, 0 );

		if ( $clean ) {
			$flag = isset( Elementor::$instance->uploads_manager ) && method_exists( Elementor::$instance->uploads_manager, 'set_elementor_upload_state' );
			if ( $flag ) {
				Elementor::$instance->uploads_manager->set_elementor_upload_state( true );
			}

			foreach ( $clean as $index => $element ) {
				$clean[ $index ] = $this->process_media( $element );
			}

			if ( $flag ) {
				Elementor::$instance->uploads_manager->set_elementor_upload_state( false );
			}
		}

		return self::objects( $clean );
	}

	/**
	 * Empty settings must stay JSON objects ({}), not lists ([]).
	 *
	 * @param array $elements Elements.
	 * @return array
	 */
	private static function objects( array $elements ) {
		foreach ( $elements as $index => $element ) {
			if ( isset( $element['settings'] ) && ( [] === $element['settings'] ) ) {
				$elements[ $index ]['settings'] = new \stdClass();
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $index ]['elements'] = self::objects( $element['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Validate a list of elements recursively.
	 *
	 * @param array $elements Elements.
	 * @param int   $depth    Current depth.
	 * @return array
	 */
	private function clean_elements( $elements, $depth ) {
		if ( ! is_array( $elements ) || $depth > self::MAX_DEPTH ) {
			return [];
		}

		$list = [];
		foreach ( $elements as $element ) {
			if ( $this->count >= self::MAX_ELEMENTS ) {
				break;
			}
			$clean = is_array( $element ) ? $this->clean_element( $element, $depth ) : null;
			if ( $clean ) {
				$list[] = $clean;
			}
		}
		return $list;
	}

	/**
	 * Validate one element. Returns null when it cannot exist on this site.
	 *
	 * @param array $element Element.
	 * @param int   $depth   Depth.
	 * @return array|null
	 */
	private function clean_element( array $element, $depth ) {
		$el_type = isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '';
		if ( ! preg_match( '/^[a-z0-9_-]{1,50}$/i', $el_type ) ) {
			return null;
		}

		if ( 'widget' === $el_type ) {
			$widget = isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '';
			if ( ! preg_match( '/^[a-z0-9_.-]{1,100}$/i', $widget ) || ! self::widget_exists( $widget ) ) {
				$this->skip( '' !== $widget ? $widget : 'widget' );
				return null;
			}
		} elseif ( ! self::element_type_exists( $el_type ) ) {
			$this->skip( $el_type );
			return null;
		}

		++$this->count;

		$clean = array_intersect_key( $element, array_flip( self::ELEMENT_KEYS ) );

		$clean['id']     = $this->new_id();
		$clean['elType'] = $el_type;

		if ( isset( $clean['isInner'] ) ) {
			$clean['isInner'] = (bool) $clean['isInner'];
		}

		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		$settings = self::clean_value( $settings, 0 );
		// Sharing on this site is the site owner's decision, never the source's.
		unset( $settings[ Live_Copy::CONTROL ] );
		$clean['settings'] = $settings;

		foreach ( [ 'styles', 'editor_settings', 'interactions' ] as $key ) {
			if ( isset( $clean[ $key ] ) ) {
				$clean[ $key ] = is_array( $clean[ $key ] ) ? self::clean_value( $clean[ $key ], 0 ) : [];
			}
		}

		if ( isset( $clean['version'] ) && ! is_scalar( $clean['version'] ) ) {
			unset( $clean['version'] );
		}

		$children          = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];
		$clean['elements'] = $this->clean_elements( $children, $depth + 1 );

		return $clean;
	}

	/**
	 * Keep only JSON-like data (arrays and scalars) with a depth limit.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function clean_value( $value, $depth ) {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_SETTINGS_DEPTH ) {
				return [];
			}
			$out = [];
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ! is_string( $key ) ) {
					continue;
				}
				$out[ $key ] = self::clean_value( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * Is a widget registered on this site?
	 *
	 * @param string $name Widget name.
	 * @return bool
	 */
	private static function widget_exists( $name ) {
		$manager = Elementor::$instance->widgets_manager;
		return $manager && null !== $manager->get_widget_types( $name );
	}

	/**
	 * Is an element type (container, section, column…) registered on this site?
	 *
	 * @param string $type Element type.
	 * @return bool
	 */
	private static function element_type_exists( $type ) {
		$manager = Elementor::$instance->elements_manager;
		return $manager && null !== $manager->get_element_types( $type );
	}

	/**
	 * Count a left-out element.
	 *
	 * @param string $type Widget or element type.
	 */
	private function skip( $type ) {
		++$this->report['skipped'];
		if ( count( $this->report['skipped_types'] ) < 30 ) {
			$this->report['skipped_types'][] = substr( preg_replace( '/[^a-z0-9_.-]/i', '', (string) $type ), 0, 100 );
		}
	}

	/**
	 * A fresh Elementor element ID (7 hex characters), unique in this request.
	 *
	 * @return string
	 */
	private function new_id() {
		do {
			$id = substr( str_pad( dechex( wp_rand( 0, 0xfffffff ) ), 7, '0', STR_PAD_LEFT ), 0, 7 );
		} while ( isset( $this->ids[ $id ] ) );
		$this->ids[ $id ] = true;
		return $id;
	}

	/* --------------------------------------------------------------------- *
	 * Media
	 * --------------------------------------------------------------------- */

	/**
	 * Walk an element tree and fix every media value.
	 *
	 * @param array $element Element.
	 * @return array
	 */
	private function process_media( array $element ) {
		$element['settings'] = $this->walk_media( $element['settings'] );
		if ( isset( $element['styles'] ) && is_array( $element['styles'] ) ) {
			$element['styles'] = $this->walk_media( $element['styles'] );
		}
		foreach ( $element['elements'] as $index => $child ) {
			$element['elements'][ $index ] = $this->process_media( $child );
		}
		return $element;
	}

	/**
	 * Fix media values in settings.
	 *
	 * Classic controls (media, gallery items, background images, SVG icons) share the
	 * { url, id } shape. Atomic widgets use { $$type: "image-src", value: { id, url } }.
	 *
	 * @param mixed $value Settings or part of them.
	 * @return mixed
	 */
	private function walk_media( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->walk_media( $item );
			}
		}

		if ( isset( $value['$$type'] ) && 'image-src' === $value['$$type'] && isset( $value['value'] ) && is_array( $value['value'] ) ) {
			return $this->atomic_image( $value );
		}

		if ( array_key_exists( 'url', $value ) && array_key_exists( 'id', $value ) && is_string( $value['url'] ) ) {
			return $this->classic_image( $value );
		}

		return $value;
	}

	/**
	 * Classic { url, id } media value.
	 *
	 * @param array $value Media value.
	 * @return array
	 */
	private function classic_image( array $value ) {
		$url = trim( $value['url'] );
		if ( '' === $url ) {
			$value['id'] = '';
			return $value;
		}

		$result = $this->image( $url, isset( $value['alt'] ) && is_string( $value['alt'] ) ? $value['alt'] : '' );

		$value['url'] = $result['url'];
		$value['id']  = $result['id'] ? $result['id'] : '';
		return $value;
	}

	/**
	 * Atomic { $$type: image-src } value.
	 *
	 * @param array $value Image source prop.
	 * @return array
	 */
	private function atomic_image( array $value ) {
		$inner = $value['value'];
		$url   = '';
		if ( isset( $inner['url']['value'] ) && is_string( $inner['url']['value'] ) ) {
			$url = trim( $inner['url']['value'] );
		}

		if ( '' === $url ) {
			// Only a foreign attachment ID: it means nothing here.
			if ( isset( $inner['id'] ) ) {
				$inner['id'] = null;
			}
			$value['value'] = $inner;
			return $value;
		}

		$result = $this->image( $url, '' );
		if ( $result['id'] ) {
			$inner['id']  = [
				'$$type' => 'image-attachment-id',
				'value'  => (int) $result['id'],
			];
			$inner['url'] = null;
		} else {
			$inner['id']  = null;
			$inner['url'] = '' !== $result['url']
				? [
					'$$type' => 'url',
					'value'  => $result['url'],
				]
				: null;
		}
		$value['value'] = $inner;
		return $value;
	}

	/**
	 * Resolve one image URL for this site.
	 *
	 * @param string $url Source URL.
	 * @param string $alt Alt text from the source.
	 * @return array { id: int, url: string }. id 0 means "not in this media library".
	 */
	private function image( $url, $alt ) {
		if ( isset( $this->images[ $url ] ) ) {
			return $this->images[ $url ];
		}

		$result               = $this->resolve_image( $url, $alt );
		$this->images[ $url ] = $result;
		return $result;
	}

	/**
	 * Resolve an image URL that was not seen yet in this request.
	 *
	 * @param string $url Source URL.
	 * @param string $alt Alt text.
	 * @return array { id: int, url: string }
	 */
	private function resolve_image( $url, $alt ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			// data:, javascript:, file: and friends are dropped.
			$this->fail( $url, 'scheme' );
			return [
				'id'  => 0,
				'url' => '',
			];
		}

		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $url ) {
			$this->fail( $url, 'invalid' );
			return [
				'id'  => 0,
				'url' => '',
			];
		}

		// An image from this very site: point at the existing attachment.
		if ( self::is_own_url( $url ) ) {
			$id = self::own_attachment_id( $url );
			if ( $id ) {
				++$this->report['reused'];
			} else {
				++$this->report['linked'];
			}
			return [
				'id'  => $id,
				'url' => $url,
			];
		}

		// Never let the server reach local or private addresses.
		if ( ! wp_http_validate_url( $url ) ) {
			$this->fail( $url, 'unsafe' );
			return [
				'id'  => 0,
				'url' => '',
			];
		}

		// Media controls also hold videos, PDFs, Lottie files and SVGs: those stay links.
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( '' !== $ext && ! preg_match( '/^(' . implode( '|', array_keys( self::IMAGE_MIMES ) ) . ')$/', $ext ) ) {
			++$this->report['linked'];
			return [
				'id'  => 0,
				'url' => $url,
			];
		}

		$existing = self::find_imported( $url );
		if ( $existing ) {
			++$this->report['reused'];
			return [
				'id'  => $existing,
				'url' => (string) wp_get_attachment_url( $existing ),
			];
		}

		// Users who may not upload keep the source URL (it still shows, from the source site).
		if ( ! $this->import_images ) {
			++$this->report['linked'];
			return [
				'id'  => 0,
				'url' => $url,
			];
		}

		/**
		 * Seconds Live Copy may spend downloading images in one paste.
		 * Images after that keep loading from the source site.
		 *
		 * @param int $seconds Default 40.
		 */
		$budget = (int) apply_filters( 'wiseaddons_pro/live_copy/time_budget', 40 );
		if ( microtime( true ) - $this->started > max( 5, $budget ) ) {
			$this->fail( $url, 'time' );
			return [
				'id'  => 0,
				'url' => $url,
			];
		}

		$id = $this->download( $url, $alt );
		if ( is_wp_error( $id ) ) {
			$this->fail( $url, $id->get_error_code() );
			// The URL passed the safety checks, so it stays as a link to the source.
			return [
				'id'  => 0,
				'url' => $url,
			];
		}

		++$this->report['imported'];
		return [
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
		];
	}

	/**
	 * Record a failed image.
	 *
	 * @param string $url    URL.
	 * @param string $reason Short reason code.
	 */
	private function fail( $url, $reason ) {
		++$this->report['failed'];
		$this->report['failures'][] = [
			'url'    => esc_url_raw( substr( (string) $url, 0, 300 ) ),
			'reason' => sanitize_key( $reason ),
		];
	}

	/**
	 * Is a URL on this site's own hosts?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_own_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$uploads = wp_get_upload_dir();
		foreach ( [ home_url(), site_url(), isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '' ] as $own ) {
			if ( $host === strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Attachment ID of one of this site's image URLs, including resized and scaled copies.
	 *
	 * @param string $url URL on this site.
	 * @return int
	 */
	private static function own_attachment_id( $url ) {
		$clean = strtok( $url, '?#' );
		$id    = attachment_url_to_postid( $clean );
		if ( ! $id ) {
			$original = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $clean );
			$id       = attachment_url_to_postid( $original );
			if ( ! $id ) {
				$id = attachment_url_to_postid( preg_replace( '/(?=\.[a-z0-9]+$)/i', '-scaled', $original, 1 ) );
			}
		}
		return $id && wp_attachment_is_image( $id ) ? (int) $id : 0;
	}

	/**
	 * Attachment already imported from this URL (by Live Copy or by Elementor's own import).
	 *
	 * @param string $url Source URL.
	 * @return int
	 */
	private static function find_imported( $url ) {
		$queries = [
			[ self::META_HASH, md5( $url ) ],
			[ '_elementor_source_image_hash', sha1( $url ) ],
		];

		foreach ( $queries as $query ) {
			$ids = get_posts(
				[
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
					'meta_key'               => $query[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'             => $query[1], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			if ( $ids && wp_attachment_is_image( (int) $ids[0] ) && wp_get_attachment_url( (int) $ids[0] ) ) {
				return (int) $ids[0];
			}
		}
		return 0;
	}

	/**
	 * Largest image we download, in bytes.
	 *
	 * @return int
	 */
	private static function max_bytes() {
		/**
		 * Largest image Live Copy downloads, in bytes.
		 *
		 * @param int $bytes Default: the site's upload limit, at most 10 MB.
		 */
		return (int) apply_filters( 'wiseaddons_pro/live_copy/max_image_bytes', min( wp_max_upload_size(), 10 * MB_IN_BYTES ) );
	}

	/**
	 * Download an image and add it to the media library.
	 *
	 * @param string $url Safe http(s) URL.
	 * @param string $alt Alt text.
	 * @return int|\WP_Error Attachment ID.
	 */
	private function download( $url, $alt ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$max = max( 1024, self::max_bytes() );
		$tmp = wp_tempnam( 'wea-live-copy' );
		if ( ! $tmp ) {
			return new \WP_Error( 'temp_file', 'No temporary file.' );
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 20,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
				'user-agent'          => 'WiseAddonsPro/' . WISEADDONS_PRO_VERSION . '; ' . home_url( '/' ),
				'headers'             => [ 'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'download', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'http_' . $code, 'HTTP ' . $code );
		}

		$length = (int) wp_remote_retrieve_header( $response, 'content-length' );
		$size   = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $size <= 0 || $size > $max || $length > $max ) {
			wp_delete_file( $tmp );
			return new \WP_Error( $size > 0 ? 'too_large' : 'empty', 'Size' );
		}

		// Trust the file's bytes, not its name or the server's content type.
		$mime = wp_get_image_mime( $tmp );
		$ext  = $mime ? array_search( $mime, self::IMAGE_MIMES, true ) : false;
		$info = $ext ? wp_getimagesize( $tmp ) : false;
		if ( ! $ext || ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'not_image', 'Not an image' );
		}

		/**
		 * Largest image Live Copy imports, in pixels (width × height). Guards against decompression bombs.
		 *
		 * @param int $pixels Default 50 megapixels.
		 */
		$max_pixels = (int) apply_filters( 'wiseaddons_pro/live_copy/max_image_pixels', 50000000 );
		if ( (int) $info[0] * (int) $info[1] > $max_pixels ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'too_large', 'Pixels' );
		}

		$ext  = explode( '|', $ext )[0];
		$base = sanitize_file_name( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$base = '' !== $base ? substr( $base, 0, 80 ) : 'live-copy-image';
		$name = $base . '.' . $ext;

		$check = wp_check_filetype_and_ext( $tmp, $name, self::IMAGE_MIMES );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'not_image', 'Type' );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}

		$parent = ( $this->post_id && current_user_can( 'edit_post', $this->post_id ) ) ? $this->post_id : 0;
		$file   = [
			'name'     => $name,
			'tmp_name' => $tmp,
			'size'     => $size,
			'type'     => $check['type'],
			'error'    => 0,
		];

		$id = media_handle_sideload( $file, $parent );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'sideload', $id->get_error_message() );
		}

		update_post_meta( $id, self::META_SOURCE, esc_url_raw( $url ) );
		update_post_meta( $id, self::META_HASH, md5( $url ) );

		$alt = sanitize_text_field( $alt );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $id;
	}

	/* --------------------------------------------------------------------- *
	 * Public copies
	 * --------------------------------------------------------------------- */

	/**
	 * An element as served on the public route: no rendered HTML, no private settings.
	 *
	 * @param array $element Saved element.
	 * @return array
	 */
	public static function public_copy( array $element ) {
		$copy = array_intersect_key( $element, array_flip( self::ELEMENT_KEYS ) );

		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		unset( $settings[ Live_Copy::CONTROL ] );
		$settings         = self::strip_private( $settings );
		$copy['settings'] = $settings ? $settings : new \stdClass();

		$children         = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];
		$copy['elements'] = [];
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$copy['elements'][] = self::public_copy( $child );
			}
		}

		return $copy;
	}

	/**
	 * Remove settings that may hold private data: form recipients, API keys, webhooks, passwords.
	 *
	 * @param array $settings Settings or part of them.
	 * @return array
	 */
	private static function strip_private( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && self::is_private_key( $key ) ) {
				unset( $settings[ $key ] );
				continue;
			}
			if ( is_array( $value ) ) {
				$settings[ $key ] = self::strip_private( $value );
			}
		}
		return $settings;
	}

	/**
	 * Does a setting key look private?
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function is_private_key( $key ) {
		$key     = strtolower( $key );
		$private = (bool) preg_match( '/^(email_to|email_from|email_reply_to)|api_?key|api_token|access_token|secret|password|passwd|webhook/', $key );

		/**
		 * Whether a setting is removed from elements served to visitors by Live Copy.
		 *
		 * @param bool   $private Default from the built-in list.
		 * @param string $key     Setting key.
		 */
		return (bool) apply_filters( 'wiseaddons_pro/live_copy/private_setting', $private, $key );
	}
}
