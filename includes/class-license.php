<?php
/**
 * Lemon Squeezy license for Wise Addons Pro.
 *
 * Policy:
 * - active   Pro features run and updates download.
 * - expired  Pro features keep running; updates stop until the license is renewed.
 * - disabled / refunded / inactive / unknown key: Pro features are off.
 *
 * The license is checked against Lemon Squeezy once a day. When Lemon Squeezy cannot be
 * reached, the last known state is kept, so a network problem never turns Pro off.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro;

defined( 'ABSPATH' ) || exit;

/**
 * License handling.
 */
final class License {

	/**
	 * Option that stores the license state.
	 */
	const OPTION = 'wiseaddons_pro_license';

	/**
	 * Lemon Squeezy license API.
	 */
	const API = 'https://api.lemonsqueezy.com/v1/licenses/';

	/**
	 * Wisemattic's Lemon Squeezy store.
	 */
	const STORE_ID = 79937;

	/**
	 * Daily check hook.
	 */
	const CRON = 'wiseaddons_pro_license_check';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( self::CRON, [ __CLASS__, 'refresh' ] );
	}

	/**
	 * Lemon Squeezy product IDs whose licenses unlock Pro.
	 *
	 * Empty until the products exist on Lemon Squeezy. While empty, any product of the
	 * Wisemattic store whose name contains "Wise Addons" is accepted.
	 *
	 * @return int[]
	 */
	public static function product_ids() {
		/**
		 * Filter the accepted Lemon Squeezy product IDs.
		 *
		 * @param int[] $ids Product IDs.
		 */
		return array_map( 'intval', (array) apply_filters( 'wiseaddons_pro/license/product_ids', [] ) );
	}

	/**
	 * Saved license data.
	 *
	 * @return array
	 */
	public static function data() {
		$data = get_option( self::OPTION, [] );
		return wp_parse_args(
			is_array( $data ) ? $data : [],
			[
				'key'              => '',
				'instance_id'      => '',
				'status'           => 'inactive',
				'expires_at'       => '',
				'customer_email'   => '',
				'customer_name'    => '',
				'product_name'     => '',
				'variant_name'     => '',
				'activation_limit' => 0,
				'activation_usage' => 0,
				'checked'          => 0,
				'error'            => '',
			]
		);
	}

	/**
	 * License status: active, expired, disabled or inactive.
	 *
	 * @return string
	 */
	public static function status() {
		$data = self::data();
		return '' === $data['key'] ? 'inactive' : (string) $data['status'];
	}

	/**
	 * Whether the Pro widgets and features may run.
	 *
	 * @return bool
	 */
	public static function features_enabled() {
		/**
		 * Filter whether Pro features run. Used by local development and tests.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'wiseaddons_pro/license/features_enabled', in_array( self::status(), [ 'active', 'expired' ], true ) );
	}

	/**
	 * Whether updates may be downloaded.
	 *
	 * @return bool
	 */
	public static function updates_enabled() {
		return 'active' === self::status();
	}

	/**
	 * The key with only its last 4 characters visible.
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::data()['key'];
		return '' === $key ? '' : str_repeat( '•', 12 ) . substr( $key, -4 );
	}

	/**
	 * Activate a license key on this site.
	 *
	 * @param string $key License key.
	 * @return true|\WP_Error
	 */
	public static function activate( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return new \WP_Error( 'empty', __( 'Enter your license key.', 'wise-addons-for-elementor-pro' ) );
		}

		// A key that is already active here is validated instead of using a second activation.
		$data = self::data();
		if ( $data['key'] === $key && '' !== $data['instance_id'] && in_array( $data['status'], [ 'active', 'expired' ], true ) ) {
			return self::refresh( true );
		}

		$response = self::request(
			'activate',
			[
				'license_key'   => $key,
				'instance_name' => self::instance_name(),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['activated'] ) ) {
			return new \WP_Error( 'not_activated', self::api_error( $response ) );
		}
		if ( ! self::belongs_to_pro( $response ) ) {
			// Free the activation slot we just used on someone else's product.
			self::request(
				'deactivate',
				[
					'license_key' => $key,
					'instance_id' => (string) ( $response['instance']['id'] ?? '' ),
				]
			);
			return new \WP_Error( 'wrong_product', __( 'This license key is for a different product. Use your Wise Addons Pro license key.', 'wise-addons-for-elementor-pro' ) );
		}

		self::save( $key, (string) ( $response['instance']['id'] ?? '' ), $response );
		return true;
	}

	/**
	 * Deactivate the license on this site, so the activation can be used elsewhere.
	 *
	 * @return true|\WP_Error
	 */
	public static function deactivate() {
		$data = self::data();
		if ( '' !== $data['key'] && '' !== $data['instance_id'] ) {
			$response = self::request(
				'deactivate',
				[
					'license_key' => $data['key'],
					'instance_id' => $data['instance_id'],
				]
			);
			// An unknown instance is already gone at Lemon Squeezy; anything else is a real error.
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( empty( $response['deactivated'] ) && false === stripos( self::api_error( $response ), 'not found' ) ) {
				return new \WP_Error( 'not_deactivated', self::api_error( $response ) );
			}
		}
		delete_option( self::OPTION );
		return true;
	}

	/**
	 * Validate the saved license with Lemon Squeezy.
	 *
	 * Runs daily from cron; $force runs it now. Network errors keep the last state.
	 *
	 * @param bool $force Check even if checked in the last 12 hours.
	 * @return true|\WP_Error
	 */
	public static function refresh( $force = false ) {
		$data = self::data();
		if ( '' === $data['key'] ) {
			return true;
		}
		if ( ! $force && ( time() - (int) $data['checked'] ) < 12 * HOUR_IN_SECONDS ) {
			return true;
		}

		$body = [ 'license_key' => $data['key'] ];
		if ( '' !== $data['instance_id'] ) {
			$body['instance_id'] = $data['instance_id'];
		}
		$response = self::request( 'validate', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// This site's activation was removed (for example from the customer's Lemon Squeezy account).
		if ( '' !== $data['instance_id'] && empty( $response['instance'] ) && ! empty( $response['error'] ) ) {
			$data['status']  = 'inactive';
			$data['error']   = self::api_error( $response );
			$data['checked'] = time();
			update_option( self::OPTION, $data, false );
			return new \WP_Error( 'instance', $data['error'] );
		}

		// Lemon Squeezy answers 404 with valid=false for unknown keys.
		if ( empty( $response['license_key'] ) || ! self::belongs_to_pro( $response ) ) {
			$data['status']  = 'disabled';
			$data['error']   = self::api_error( $response );
			$data['checked'] = time();
			update_option( self::OPTION, $data, false );
			return new \WP_Error( 'invalid', $data['error'] );
		}

		self::save( $data['key'], $data['instance_id'], $response );
		return true;
	}

	/**
	 * Schedule the daily check.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Remove the daily check.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Store the license state from a Lemon Squeezy answer.
	 *
	 * @param string $key         License key.
	 * @param string $instance_id Activation instance ID.
	 * @param array  $response    Decoded answer.
	 */
	private static function save( $key, $instance_id, array $response ) {
		$license = (array) ( $response['license_key'] ?? [] );
		$meta    = (array) ( $response['meta'] ?? [] );
		$status  = (string) ( $license['status'] ?? 'inactive' );

		// "inactive" from Lemon Squeezy means a key with no activations; ours has one.
		if ( 'inactive' === $status && '' !== $instance_id ) {
			$status = 'active';
		}

		update_option(
			self::OPTION,
			[
				'key'              => $key,
				'instance_id'      => $instance_id,
				'status'           => $status,
				'expires_at'       => (string) ( $license['expires_at'] ?? '' ),
				'customer_email'   => (string) ( $meta['customer_email'] ?? '' ),
				'customer_name'    => (string) ( $meta['customer_name'] ?? '' ),
				'product_name'     => (string) ( $meta['product_name'] ?? '' ),
				'variant_name'     => (string) ( $meta['variant_name'] ?? '' ),
				'activation_limit' => (int) ( $license['activation_limit'] ?? 0 ),
				'activation_usage' => (int) ( $license['activation_usage'] ?? 0 ),
				'checked'          => time(),
				'error'            => '',
			],
			false
		);
		self::schedule();
	}

	/**
	 * Whether a Lemon Squeezy answer is for a Wise Addons Pro license.
	 *
	 * @param array $response Decoded answer.
	 * @return bool
	 */
	private static function belongs_to_pro( array $response ) {
		$meta = (array) ( $response['meta'] ?? [] );
		if ( (int) ( $meta['store_id'] ?? 0 ) !== self::STORE_ID ) {
			return false;
		}
		$ids = self::product_ids();
		if ( $ids ) {
			return in_array( (int) ( $meta['product_id'] ?? 0 ), $ids, true );
		}
		return false !== stripos( (string) ( $meta['product_name'] ?? '' ), 'Wise Addons' );
	}

	/**
	 * POST to the Lemon Squeezy license API.
	 *
	 * @param string $endpoint activate, validate or deactivate.
	 * @param array  $body     Form fields.
	 * @return array|\WP_Error Decoded answer (also for 4xx answers, which carry an "error").
	 */
	private static function request( $endpoint, array $body ) {
		$response = wp_remote_post(
			self::API . $endpoint,
			[
				'timeout' => 15,
				'headers' => [ 'Accept' => 'application/json' ],
				'body'    => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'network', __( 'Could not reach the license server. Please try again in a few minutes.', 'wise-addons-for-elementor-pro' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 500 || ! is_array( $data ) ) {
			return new \WP_Error( 'network', __( 'The license server did not answer correctly. Please try again in a few minutes.', 'wise-addons-for-elementor-pro' ) );
		}
		return $data;
	}

	/**
	 * Human-readable error from an answer.
	 *
	 * @param array $response Decoded answer.
	 * @return string
	 */
	private static function api_error( array $response ) {
		$error = isset( $response['error'] ) ? trim( (string) $response['error'] ) : '';
		return '' !== $error ? $error : __( 'The license key is not valid.', 'wise-addons-for-elementor-pro' );
	}

	/**
	 * Name of this site's activation, shown in the customer's Lemon Squeezy account.
	 *
	 * @return string
	 */
	private static function instance_name() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) . wp_parse_url( home_url(), PHP_URL_PATH );
	}
}
