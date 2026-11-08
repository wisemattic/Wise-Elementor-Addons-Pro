<?php
/**
 * Boots Wise Addons Pro on top of the free plugin.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Why Pro cannot run, or '' when it can.
	 *
	 * @var string
	 */
	private $missing = '';

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load files and hook in.
	 */
	private function __construct() {
		require_once WISEADDONS_PRO_PATH . 'includes/class-license.php';
		require_once WISEADDONS_PRO_PATH . 'includes/class-updater.php';
		require_once WISEADDONS_PRO_PATH . 'includes/class-pro-map.php';

		// Before the free plugin boots (plugins_loaded, 10), so the Pro entries are in its maps from the start.
		add_action( 'plugins_loaded', [ $this, 'boot' ], 5 );

		// Updates and the license page work even when the free plugin is missing, so a broken
		// setup can still be fixed and updated.
		License::init();
		Updater::init();

		if ( is_admin() ) {
			require_once WISEADDONS_PRO_PATH . 'includes/admin/class-license-page.php';
			Admin\License_Page::init();
			add_action( 'admin_notices', [ $this, 'notices' ] );
			add_filter( 'plugin_action_links_' . WISEADDONS_PRO_BASENAME, [ $this, 'action_links' ] );
		}
	}

	/**
	 * Register the Pro widgets and features with the free plugin.
	 */
	public function boot() {
		if ( ! defined( 'WISEADDONS_VERSION' ) ) {
			$this->missing = 'free';
			return;
		}
		if ( ! defined( 'WISEADDONS_ADDON_API' ) || WISEADDONS_ADDON_API < WISEADDONS_PRO_NEEDS_ADDON_API ) {
			$this->missing = 'free_version';
			return;
		}
		if ( ! License::features_enabled() ) {
			$this->missing = 'license';
			return;
		}

		Pro_Map::init();

		/**
		 * Fires after Wise Addons Pro has added its widgets and features.
		 */
		do_action( 'wiseaddons_pro/loaded' );
	}

	/**
	 * Whether the Pro widgets and features are running.
	 *
	 * @return bool
	 */
	public function is_running() {
		return '' === $this->missing && did_action( 'wiseaddons_pro/loaded' ) > 0;
	}

	/**
	 * Admin notices, shown only on the Plugins screen and on our own pages.
	 */
	public function notices() {
		if ( '' === $this->missing || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && ( 'plugins' === $screen->id || false !== strpos( (string) $screen->id, 'wise-addons' ) );
		if ( ! $ours ) {
			return;
		}

		if ( 'free' === $this->missing ) {
			$message = esc_html__( 'Wise Addons for Elementor Pro needs the free Wise Addons for Elementor plugin. Install and activate it to use the Pro widgets.', 'wise-addons-for-elementor-pro' );
		} elseif ( 'free_version' === $this->missing ) {
			$message = sprintf(
				/* translators: %s: version number */
				esc_html__( 'Wise Addons for Elementor Pro needs Wise Addons for Elementor %s or newer. Update the free plugin to use the Pro widgets.', 'wise-addons-for-elementor-pro' ),
				esc_html( WISEADDONS_PRO_NEEDS_FREE )
			);
		} else {
			// The license page shows its own status, so no notice there.
			if ( $screen && false !== strpos( (string) $screen->id, Admin\License_Page::SLUG ) ) {
				return;
			}
			$message = sprintf(
				/* translators: %s: link to the license page */
				esc_html__( 'Activate your Wise Addons Pro license to turn on the Pro widgets and features. %s', 'wise-addons-for-elementor-pro' ),
				'<a href="' . esc_url( Admin\License_Page::url() ) . '">' . esc_html__( 'Activate license', 'wise-addons-for-elementor-pro' ) . '</a>'
			);
		}

		echo '<div class="notice notice-warning"><p>' . wp_kses( $message, [ 'a' => [ 'href' => [] ] ] ) . '</p></div>';
	}

	/**
	 * "License" link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Admin\License_Page::url() ) . '">' . esc_html__( 'License', 'wise-addons-for-elementor-pro' ) . '</a>' );
		return $links;
	}

	/**
	 * Activation: schedule the daily license check.
	 */
	public static function activate() {
		require_once WISEADDONS_PRO_PATH . 'includes/class-license.php';
		License::schedule();
	}

	/**
	 * Deactivation: stop the daily license check. The license stays activated on this site.
	 */
	public static function deactivate() {
		require_once WISEADDONS_PRO_PATH . 'includes/class-license.php';
		License::unschedule();
	}
}
