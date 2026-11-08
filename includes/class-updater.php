<?php
/**
 * Updates from Wisemattic's update server (wisemattic.com/wp-json/wisemattic-updates/v1).
 *
 * The server reads the newest zip uploaded to the Lemon Squeezy product and serves it to
 * sites with an active license. See docs/RELEASE.md.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin updater.
 */
final class Updater {

	/**
	 * Product slug on the update server. Also the plugin's folder and main file name.
	 */
	const SLUG = 'wise-addons-for-elementor-pro';

	/**
	 * Cached release details.
	 */
	const CACHE = 'wiseaddons_pro_release';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'add_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_information' ], 20, 3 );
		add_action( 'in_plugin_update_message-' . WISEADDONS_PRO_BASENAME, [ __CLASS__, 'license_message' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'clear_cache' ], 10, 2 );
	}

	/**
	 * Update server base URL for this product.
	 *
	 * @return string
	 */
	private static function api() {
		/**
		 * Filter the update server URL (for staging or local testing).
		 *
		 * @param string $url Base URL, without a trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'wiseaddons_pro/updates/api', 'https://wisemattic.com/wp-json/wisemattic-updates/v1/' . self::SLUG ) );
	}

	/**
	 * Release details from the server, cached for 12 hours (1 hour after a failure).
	 *
	 * @return array|null
	 */
	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$release  = null;
		$response = wp_remote_get( self::api() . '/info', [ 'timeout' => 10 ] );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['version'] ) ) {
				$release = $data;
			}
		}

		set_site_transient( self::CACHE, $release ? $release : [ 'version' => '' ], $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Download URL for the current license, or '' without an active license.
	 *
	 * @return string
	 */
	private static function package() {
		if ( ! License::updates_enabled() ) {
			return '';
		}
		$data = License::data();
		return add_query_arg(
			[
				'license_key' => rawurlencode( $data['key'] ),
				'instance_id' => rawurlencode( $data['instance_id'] ),
			],
			self::api() . '/download'
		);
	}

	/**
	 * Add Pro to WordPress' update list when the server has a newer version.
	 *
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public static function add_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::release();
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) [
			'id'           => self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => WISEADDONS_PRO_BASENAME,
			'new_version'  => (string) $release['version'],
			'url'          => (string) ( $release['homepage'] ?? 'https://wiseaddons.com/pro/' ),
			'package'      => self::package(),
			'requires'     => (string) ( $release['requires'] ?? '' ),
			'requires_php' => (string) ( $release['requires_php'] ?? '' ),
			'tested'       => (string) ( $release['tested'] ?? '' ),
			'icons'        => (array) ( $release['icons'] ?? [] ),
			'banners'      => (array) ( $release['banners'] ?? [] ),
		];

		if ( version_compare( $item->new_version, WISEADDONS_PRO_VERSION, '>' ) ) {
			$transient->response[ WISEADDONS_PRO_BASENAME ] = $item;
		} else {
			$transient->no_update[ WISEADDONS_PRO_BASENAME ] = $item;
		}
		return $transient;
	}

	/**
	 * "View details" popup.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => (string) ( $release['name'] ?? 'Wise Addons for Elementor Pro' ),
			'slug'          => self::SLUG,
			'version'       => (string) $release['version'],
			'author'        => '<a href="https://wisemattic.com/">Wisemattic</a>',
			'homepage'      => (string) ( $release['homepage'] ?? 'https://wiseaddons.com/pro/' ),
			'requires'      => (string) ( $release['requires'] ?? '' ),
			'requires_php'  => (string) ( $release['requires_php'] ?? '' ),
			'tested'        => (string) ( $release['tested'] ?? '' ),
			'last_updated'  => (string) ( $release['last_updated'] ?? '' ),
			'sections'      => array_map( 'wp_kses_post', (array) ( $release['sections'] ?? [] ) ),
			'banners'       => (array) ( $release['banners'] ?? [] ),
			'icons'         => (array) ( $release['icons'] ?? [] ),
			'download_link' => self::package(),
		];
	}

	/**
	 * Under the update row: explain why the update can't be installed without a license.
	 */
	public static function license_message() {
		if ( License::updates_enabled() ) {
			return;
		}
		$text = 'expired' === License::status()
			? __( 'Your license has expired. Renew it to download this update.', 'wise-addons-for-elementor-pro' )
			: __( 'Activate your license to download this update.', 'wise-addons-for-elementor-pro' );
		echo ' <strong>' . esc_html( $text ) . '</strong> <a href="' . esc_url( Admin\License_Page::url() ) . '">' . esc_html__( 'License settings', 'wise-addons-for-elementor-pro' ) . '</a>';
	}

	/**
	 * Forget the cached release after an update.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $options  Update details.
	 */
	public static function clear_cache( $upgrader, $options ) {
		unset( $upgrader );
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_site_transient( self::CACHE );
		}
	}
}
