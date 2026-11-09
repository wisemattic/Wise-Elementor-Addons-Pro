<?php
/**
 * "Load more" endpoint for the Product Grid widget.
 *
 * admin-ajax action "wea_product_grid_more". The browser sends only a document
 * ID, an element ID, a page number and a nonce. The settings are read from the
 * element saved in that document, never from the request.
 *
 * WordPress loads widget files late (when Elementor renders a page), so this
 * file must also be loaded early on every request for admin-ajax to see the
 * handler. Until a loader does that, available() is false and the grid's script
 * falls back to fetching the next page URL and reading the new cards from it.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Widgets;

use Elementor\Plugin;

defined( 'ABSPATH' ) || exit;

if ( class_exists( __NAMESPACE__ . '\\Product_Grid_Load_More' ) ) {
	return;
}

/**
 * Load more handler.
 */
final class Product_Grid_Load_More {

	/**
	 * AJAX action and nonce action.
	 */
	const ACTION = 'wea_product_grid_more';

	/**
	 * Hooks added.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Hooks were added before WordPress finished loading, so admin-ajax requests can reach them.
	 *
	 * @var bool
	 */
	private static $early = false;

	/**
	 * Add the AJAX hooks. Safe to call more than once.
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::$early  = ! did_action( 'wp_loaded' );

		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/**
	 * Whether the endpoint answers on admin-ajax requests.
	 *
	 * @return bool
	 */
	public static function available() {
		/**
		 * Filters whether Product Grid uses the admin-ajax endpoint for "Load more".
		 *
		 * @param bool $available True when this file was loaded early enough.
		 */
		return (bool) apply_filters( 'wiseaddons_pro/product_grid/ajax_load_more', self::$early );
	}

	/**
	 * Find an element by ID in Elementor data.
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Element ID.
	 * @return array|null
	 */
	private static function find_element( $elements, $id ) {
		foreach ( (array) $elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = self::find_element( $element['elements'], $id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Build the widget instance from the saved element.
	 *
	 * @param int    $post_id    Document ID.
	 * @param string $element_id Element ID.
	 * @return Product_Grid|\WP_Error
	 */
	public static function widget_from_document( $post_id, $element_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || post_password_required( $post ) ) {
			return new \WP_Error( 'not_found', 'Document not found.', [ 'status' => 404 ] );
		}
		$public = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) || 'elementor_library' === $post->post_type && 'publish' === $post->post_status;
		if ( ! $public && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'Not allowed.', [ 'status' => 403 ] );
		}

		$document = Plugin::$instance->documents->get( $post->ID );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return new \WP_Error( 'not_found', 'Document not found.', [ 'status' => 404 ] );
		}

		$element = self::find_element( $document->get_elements_data(), $element_id );
		if ( ! $element || 'widget' !== ( $element['elType'] ?? '' ) || 'wea-product-grid' !== ( $element['widgetType'] ?? '' ) ) {
			return new \WP_Error( 'not_found', 'Element not found.', [ 'status' => 404 ] );
		}

		$widget = Plugin::$instance->elements_manager->create_element_instance( $element );
		if ( ! $widget instanceof Product_Grid ) {
			return new \WP_Error( 'not_found', 'Widget not available.', [ 'status' => 404 ] );
		}
		return $widget;
	}

	/**
	 * AJAX handler.
	 */
	public static function handle() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
		}

		$post_id    = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$element_id = isset( $_POST['element_id'] ) ? preg_replace( '/[^a-z0-9]/i', '', sanitize_text_field( wp_unslash( $_POST['element_id'] ) ) ) : '';
		$page       = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 0;

		if ( ! $post_id || '' === $element_id || $page < 2 || $page > 500 || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( [ 'message' => 'Bad request.' ], 400 );
		}

		// Dynamic tags in the settings resolve against the document's own post.
		Plugin::$instance->db->switch_to_post( $post_id );

		$widget = self::widget_from_document( $post_id, $element_id );
		if ( is_wp_error( $widget ) ) {
			Plugin::$instance->db->restore_current_post();
			$data = $widget->get_error_data();
			wp_send_json_error( [ 'message' => $widget->get_error_message() ], isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}

		$result = $widget->render_page( $page );
		Plugin::$instance->db->restore_current_post();

		wp_send_json_success(
			[
				'html'    => $result['html'],
				'page'    => $result['page'],
				'pages'   => $result['pages'],
				'count'   => $result['count'],
				'hasMore' => $result['page'] < $result['pages'],
			]
		);
	}
}
