<?php
/**
 * Live Copy: copy an element on one site and paste it into the Elementor editor of another.
 *
 * Editor side: "Wise Copy" and "Wise Paste" in the element and add-section context menus.
 * Copy writes a JSON payload to the clipboard. Paste reads it back, sends it to the
 * import route below (images are downloaded into the media library, element IDs are
 * regenerated, unknown widgets are left out) and inserts the result with Elementor's
 * own paste command, so one undo removes it again.
 *
 * Live site (optional, off by default): a container or section with "Allow Live Copy on
 * the live site" gets a small Copy button. Its JSON comes from a public route that only
 * returns elements that were explicitly switched on, on published pages.
 *
 * REST routes (wiseaddons-pro/v1):
 * - POST live-copy/import                         Editor users. Prepares a pasted payload.
 * - GET  live-copy/element/<post_id>/<element_id> Public. One copyable element.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Element_Base;
use Elementor\Plugin as Elementor;
use WiseAddons\Assets;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Live Copy feature.
 */
class Live_Copy extends Feature_Base {

	const SLUG = 'live-copy';

	/**
	 * Switch control ID. Permanent.
	 */
	const CONTROL = 'wea_live_copy';

	/**
	 * REST namespace and base.
	 */
	const REST_NS   = 'wiseaddons-pro/v1';
	const REST_BASE = 'live-copy';

	/**
	 * Marker key of a Live Copy payload.
	 */
	const MARKER = 'wiseaddons_live_copy';

	/**
	 * Payload format version.
	 */
	const FORMAT = 1;

	/**
	 * Largest pasted payload we accept, in bytes.
	 */
	const MAX_PAYLOAD_BYTES = 3145728;

	/**
	 * Frontend config printed once.
	 *
	 * @var bool
	 */
	private $frontend_config_added = false;

	/**
	 * Hooks.
	 */
	protected function init() {
		add_action( 'elementor/element/container/section_layout/after_section_end', [ $this, 'register_controls' ] );
		add_action( 'elementor/element/section/section_advanced/after_section_end', [ $this, 'register_controls' ] );

		add_action( 'elementor/frontend/container/before_render', [ $this, 'before_render' ] );
		add_action( 'elementor/frontend/section/before_render', [ $this, 'before_render' ] );

		// Elementor's element cache re-enqueues our script on cached pages without running
		// before_render, and it does not keep inline data. Add the config at print time instead.
		add_action( 'wp_footer', [ $this, 'add_frontend_config' ], 5 );

		// "before" so the context-menu filters exist before the first document renders.
		add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'editor_scripts' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'editor_styles' ] );

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* --------------------------------------------------------------------- *
	 * Controls and frontend
	 * --------------------------------------------------------------------- */

	/**
	 * Advanced tab section on containers and sections.
	 *
	 * @param Element_Base $element Element being registered.
	 */
	public function register_controls( $element ) {
		$element->start_controls_section(
			'wea_section_live_copy',
			[
				'label' => esc_html__( 'Wise Live Copy', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->add_control(
			self::CONTROL,
			[
				'label'          => esc_html__( 'Allow Live Copy on the live site', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SWITCHER,
				'default'        => '',
				'return_value'   => 'yes',
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			'wea_live_copy_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Visitors of the published page see a Copy button on this element. It copies the element’s design (not form recipients or API keys), so it can be pasted into another Elementor site with Wise Paste. Leave it off for anything you do not want to share.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
			]
		);

		$element->end_controls_section();
	}

	/**
	 * Mark copyable elements and load the frontend script.
	 *
	 * @param Element_Base $element Element about to render.
	 */
	public function before_render( $element ) {
		$raw = $element->get_data( 'settings' );
		if ( ! is_array( $raw ) || empty( $raw[ self::CONTROL ] ) || 'yes' !== $raw[ self::CONTROL ] ) {
			return;
		}

		// Never on the editor preview: the editor has its own Wise Copy.
		$elementor = Elementor::$instance;
		if ( ( $elementor->preview && $elementor->preview->is_preview_mode() ) || ( $elementor->editor && $elementor->editor->is_edit_mode() ) ) {
			return;
		}

		$document = $elementor->documents->get_current();
		$post_id  = $document ? (int) $document->get_main_id() : 0;
		if ( ! $post_id ) {
			return;
		}

		$element->add_render_attribute( '_wrapper', 'data-wea-live-copy', (string) $post_id );

		$this->enqueue_style();
		$this->enqueue_script();
	}

	/**
	 * Boot data for the frontend script, printed only when the script is on the page.
	 */
	public function add_frontend_config() {
		$handle = Assets::feature_handle( self::SLUG );
		if ( $this->frontend_config_added || ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}
		$this->frontend_config_added = true;

		$config = [
			'endpoint' => esc_url_raw( rest_url( self::REST_NS . '/' . self::REST_BASE . '/element/' ) ),
			'i18n'     => [
				'copy'     => __( 'Copy', 'wise-addons-for-elementor-pro' ),
				'label'    => __( 'Copy this section', 'wise-addons-for-elementor-pro' ),
				'copying'  => __( 'Copying…', 'wise-addons-for-elementor-pro' ),
				'copied'   => __( 'Copied!', 'wise-addons-for-elementor-pro' ),
				'hint'     => __( 'Copied. In your Elementor editor, right-click and choose “Wise Paste”.', 'wise-addons-for-elementor-pro' ),
				'failed'   => __( 'Copy failed. Please try again.', 'wise-addons-for-elementor-pro' ),
				'manual'   => __( 'Your browser blocked the clipboard. Copy the text below with Ctrl+C (⌘C on Mac).', 'wise-addons-for-elementor-pro' ),
				'close'    => __( 'Close', 'wise-addons-for-elementor-pro' ),
				'dialog'   => __( 'Copy section', 'wise-addons-for-elementor-pro' ),
			],
		];

		wp_add_inline_script(
			$handle,
			'window.WiseAddonsLiveCopy = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/* --------------------------------------------------------------------- *
	 * Editor
	 * --------------------------------------------------------------------- */

	/**
	 * Can the current user use Wise Copy / Wise Paste?
	 *
	 * @return bool
	 */
	private static function user_can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Editor window script.
	 */
	public function editor_scripts() {
		if ( ! self::user_can_edit() ) {
			return;
		}

		$handle = 'wea-feature-live-copy-editor';
		$file   = __DIR__ . '/editor.js';

		wp_enqueue_script(
			$handle,
			WISEADDONS_PRO_URL . 'features/' . self::SLUG . '/editor.js',
			[ 'jquery', 'backbone', 'wp-i18n', 'wp-api-fetch', 'elementor-editor' ],
			Assets::ver( $file ),
			true
		);

		wp_set_script_translations( $handle, 'wise-addons-for-elementor-pro', WISEADDONS_PRO_PATH . 'languages' );

		$config = [
			'restPath'  => self::REST_NS . '/' . self::REST_BASE,
			'marker'    => self::MARKER,
			'format'    => self::FORMAT,
			'siteUrl'   => home_url( '/' ),
			'canUpload' => current_user_can( 'upload_files' ),
			'maxBytes'  => self::MAX_PAYLOAD_BYTES,
		];

		wp_add_inline_script(
			$handle,
			'window.WiseAddonsPro = window.WiseAddonsPro || {}; window.WiseAddonsPro.liveCopyConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Editor window styles (paste dialog, menu icons).
	 */
	public function editor_styles() {
		if ( ! self::user_can_edit() ) {
			return;
		}
		$file = __DIR__ . '/editor.css';
		wp_enqueue_style( 'wea-feature-live-copy-editor', WISEADDONS_PRO_URL . 'features/' . self::SLUG . '/editor.css', [], Assets::ver( $file ) );
	}

	/* --------------------------------------------------------------------- *
	 * REST
	 * --------------------------------------------------------------------- */

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/' . self::REST_BASE . '/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_import' ],
				'permission_callback' => [ $this, 'can_import' ],
				'args'                => [
					'payload' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== $value && strlen( $value ) <= self::MAX_PAYLOAD_BYTES;
						},
					],
					'post_id' => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			]
		);

		register_rest_route(
			self::REST_NS,
			'/' . self::REST_BASE . '/element/(?P<post_id>\d+)/(?P<element_id>[a-z0-9]{1,20})',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_element' ],
				// Public by design: only elements an editor explicitly marked as copyable are returned.
				'permission_callback' => '__return_true',
				'args'                => [
					'post_id'    => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'element_id' => [
						'type'    => 'string',
						'pattern' => '^[a-z0-9]{1,20}$',
					],
				],
			]
		);
	}

	/**
	 * Editor users may import into a post they can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_import( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( current_user_can( 'edit_posts' ) && ( ! $post_id || current_user_can( 'edit_post', $post_id ) ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to paste content here.', 'wise-addons-for-elementor-pro' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * POST live-copy/import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import( \WP_REST_Request $request ) {
		require_once __DIR__ . '/class-importer.php';

		$payload = Live_Copy_Importer::decode( (string) $request->get_param( 'payload' ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$post_id = (int) $request->get_param( 'post_id' );

		try {
			$importer = new Live_Copy_Importer(
				[
					'import_images' => current_user_can( 'upload_files' ),
					'post_id'       => $post_id,
				]
			);
			$elements = $importer->import( $payload['elements'] );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wea_live_copy_failed', __( 'The copied content could not be prepared for this site.', 'wise-addons-for-elementor-pro' ), [ 'status' => 500 ] );
		}

		if ( ! $elements ) {
			return new \WP_Error(
				'wea_live_copy_empty',
				__( 'Nothing can be pasted: every copied element needs a widget that is not installed on this site.', 'wise-addons-for-elementor-pro' ),
				[
					'status' => 422,
					'report' => $importer->report(),
				]
			);
		}

		return rest_ensure_response(
			[
				'elements' => $elements,
				'source'   => $payload['source'],
				'report'   => $importer->report(),
			]
		);
	}

	/**
	 * GET live-copy/element/<post_id>/<element_id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_element( \WP_REST_Request $request ) {
		$limited = self::rate_limited();
		if ( $limited ) {
			return $limited;
		}

		$not_found = new \WP_Error( 'wea_live_copy_not_found', __( 'This element cannot be copied.', 'wise-addons-for-elementor-pro' ), [ 'status' => 404 ] );

		$post_id    = (int) $request->get_param( 'post_id' );
		$element_id = (string) $request->get_param( 'element_id' );
		$post       = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return $not_found;
		}
		if ( ! is_post_publicly_viewable( $post ) && 'elementor_library' !== $post->post_type ) {
			return $not_found;
		}

		$document = Elementor::$instance->documents->get( $post_id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return $not_found;
		}

		$data    = $document->get_elements_data();
		$element = is_array( $data ) ? self::find_element( $data, $element_id ) : null;

		if ( ! $element || ! in_array( $element['elType'] ?? '', [ 'container', 'section' ], true ) || 'yes' !== ( $element['settings'][ self::CONTROL ] ?? '' ) ) {
			return $not_found;
		}

		require_once __DIR__ . '/class-importer.php';

		$response = rest_ensure_response(
			[
				self::MARKER => 1,
				'version'    => self::FORMAT,
				'type'       => 'elementor',
				'siteurl'    => get_rest_url(),
				'source'     => home_url( '/' ),
				'elements'   => [ Live_Copy_Importer::public_copy( $element ) ],
			]
		);
		$response->header( 'X-Robots-Tag', 'noindex' );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Find an element by ID in Elementor data.
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Element ID.
	 * @return array|null
	 */
	private static function find_element( array $elements, $id ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find_element( $element['elements'], $id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Light per-visitor rate limit for the public route.
	 *
	 * @return \WP_Error|null Error when the visitor is over the limit.
	 */
	private static function rate_limited() {
		/**
		 * Live Copy requests one visitor may make per minute on the public route.
		 *
		 * @param int $limit Default 30. 0 turns the limit off.
		 */
		$limit = (int) apply_filters( 'wiseaddons_pro/live_copy/rate_limit', 30 );
		if ( $limit <= 0 ) {
			return null;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'wea_lc_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );

		$bucket = get_transient( $key );
		$now    = time();
		if ( ! is_array( $bucket ) || ! isset( $bucket['start'], $bucket['count'] ) || $now - (int) $bucket['start'] >= MINUTE_IN_SECONDS ) {
			$bucket = [
				'start' => $now,
				'count' => 0,
			];
		}

		++$bucket['count'];
		set_transient( $key, $bucket, MINUTE_IN_SECONDS );

		if ( $bucket['count'] > $limit ) {
			return new \WP_Error( 'wea_live_copy_rate_limited', __( 'Too many copy requests. Please wait a minute and try again.', 'wise-addons-for-elementor-pro' ), [ 'status' => 429 ] );
		}
		return null;
	}
}
