<?php
/**
 * License page: Wise Addons → License.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Admin;

use WiseAddonsPro\License;
use WiseAddonsPro\Pro_Map;

defined( 'ABSPATH' ) || exit;

/**
 * License admin page.
 */
final class License_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'wise-addons-license';

	/**
	 * The free plugin's dashboard menu slug.
	 */
	const PARENT = 'wise-addons';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 30 );
		add_action( 'admin_post_wiseaddons_pro_license', [ __CLASS__, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	public static function url() {
		$parent = self::parent_exists() ? 'admin.php' : 'options-general.php';
		return admin_url( $parent . '?page=' . self::SLUG );
	}

	/**
	 * Whether the free plugin's menu exists.
	 *
	 * @return bool
	 */
	private static function parent_exists() {
		return defined( 'WISEADDONS_VERSION' );
	}

	/**
	 * Add the page under the Wise Addons menu, or under Settings without the free plugin.
	 */
	public static function menu() {
		$title      = __( 'Pro License', 'wise-addons-for-elementor-pro' );
		self::$hook = (string) add_submenu_page(
			self::parent_exists() ? self::PARENT : 'options-general.php',
			$title,
			$title,
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, [ __CLASS__, 'on_load' ] );
		}
	}

	/**
	 * Re-check the license when the page opens and the last check is old.
	 */
	public static function on_load() {
		License::refresh();
	}

	/**
	 * Page styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		$file = WISEADDONS_PRO_PATH . 'assets/admin/license.css';
		wp_enqueue_style( 'wiseaddons-pro-license', WISEADDONS_PRO_URL . 'assets/admin/license.css', [], (string) filemtime( $file ) );
	}

	/**
	 * Activate, deactivate or check the license.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wise-addons-for-elementor-pro' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'wiseaddons_pro_license' );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( 'activate' === $task ) {
			$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			$result = License::activate( $key );
			$ok     = __( 'Your license is active. The Pro widgets and features are on.', 'wise-addons-for-elementor-pro' );
		} elseif ( 'deactivate' === $task ) {
			$result = License::deactivate();
			$ok     = __( 'The license was deactivated on this site. You can now activate it on another site.', 'wise-addons-for-elementor-pro' );
		} else {
			$result = License::refresh( true );
			$ok     = __( 'License checked.', 'wise-addons-for-elementor-pro' );
		}

		self::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : $ok );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Remember a message for the next page view of this user.
	 *
	 * @param string $type    success or error.
	 * @param string $message Text.
	 */
	private static function flash( $type, $message ) {
		set_transient( 'wiseaddons_pro_flash_' . get_current_user_id(), [ $type, $message ], MINUTE_IN_SECONDS );
	}

	/**
	 * Status label and modifier class.
	 *
	 * @param string $status License status.
	 * @return array [label, class]
	 */
	private static function status_label( $status ) {
		$labels = [
			'active'   => [ __( 'Active', 'wise-addons-for-elementor-pro' ), 'active' ],
			'expired'  => [ __( 'Expired', 'wise-addons-for-elementor-pro' ), 'warn' ],
			'disabled' => [ __( 'Disabled', 'wise-addons-for-elementor-pro' ), 'bad' ],
		];
		return $labels[ $status ] ?? [ __( 'Not active', 'wise-addons-for-elementor-pro' ), 'off' ];
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		$data   = License::data();
		$status = License::status();
		$has    = '' !== $data['key'];
		$flash  = get_transient( 'wiseaddons_pro_flash_' . get_current_user_id() );
		delete_transient( 'wiseaddons_pro_flash_' . get_current_user_id() );
		list( $label, $mod ) = self::status_label( $status );
		$action = admin_url( 'admin-post.php' );
		?>
		<div class="wrap wap">
			<header class="wap-head">
				<div>
					<h1 class="wap-title"><?php esc_html_e( 'Wise Addons Pro', 'wise-addons-for-elementor-pro' ); ?> <span class="wap-ver"><?php echo esc_html( WISEADDONS_PRO_VERSION ); ?></span></h1>
					<p class="wap-sub"><?php esc_html_e( 'Activate your license to turn on the Pro widgets and features and get updates.', 'wise-addons-for-elementor-pro' ); ?></p>
				</div>
				<span class="wap-pill wap-pill--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $label ); ?></span>
			</header>

			<?php if ( is_array( $flash ) && 2 === count( $flash ) ) : ?>
				<div class="wap-msg wap-msg--<?php echo esc_attr( 'error' === $flash[0] ? 'error' : 'success' ); ?>" role="status"><?php echo esc_html( $flash[1] ); ?></div>
			<?php endif; ?>

			<div class="wap-grid">
				<section class="wap-card">
					<h2><?php esc_html_e( 'License', 'wise-addons-for-elementor-pro' ); ?></h2>

					<?php if ( ! $has ) : ?>
						<form method="post" action="<?php echo esc_url( $action ); ?>" class="wap-form">
							<?php wp_nonce_field( 'wiseaddons_pro_license' ); ?>
							<input type="hidden" name="action" value="wiseaddons_pro_license">
							<input type="hidden" name="task" value="activate">
							<label for="wap-key"><?php esc_html_e( 'License key', 'wise-addons-for-elementor-pro' ); ?></label>
							<div class="wap-row">
								<input id="wap-key" type="text" name="license_key" class="wap-input" autocomplete="off" spellcheck="false" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" required>
								<button type="submit" class="wap-btn wap-btn--primary"><?php esc_html_e( 'Activate', 'wise-addons-for-elementor-pro' ); ?></button>
							</div>
							<p class="wap-help">
								<?php esc_html_e( 'Your key is in the purchase email from Lemon Squeezy and in your orders at', 'wise-addons-for-elementor-pro' ); ?>
								<a href="https://app.lemonsqueezy.com/my-orders" target="_blank" rel="noopener noreferrer">app.lemonsqueezy.com/my-orders</a>.
							</p>
						</form>
					<?php else : ?>
						<dl class="wap-facts">
							<dt><?php esc_html_e( 'Key', 'wise-addons-for-elementor-pro' ); ?></dt>
							<dd><code><?php echo esc_html( License::masked_key() ); ?></code></dd>
							<?php if ( $data['product_name'] ) : ?>
								<dt><?php esc_html_e( 'Plan', 'wise-addons-for-elementor-pro' ); ?></dt>
								<dd><?php echo esc_html( trim( $data['product_name'] . ( $data['variant_name'] && 'Default' !== $data['variant_name'] ? ' · ' . $data['variant_name'] : '' ) ) ); ?></dd>
							<?php endif; ?>
							<?php if ( $data['customer_email'] ) : ?>
								<dt><?php esc_html_e( 'Licensed to', 'wise-addons-for-elementor-pro' ); ?></dt>
								<dd><?php echo esc_html( $data['customer_email'] ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Expires', 'wise-addons-for-elementor-pro' ); ?></dt>
							<dd><?php echo esc_html( $data['expires_at'] ? wp_date( get_option( 'date_format' ), strtotime( $data['expires_at'] ) ) : __( 'Never (lifetime)', 'wise-addons-for-elementor-pro' ) ); ?></dd>
							<?php if ( $data['activation_limit'] ) : ?>
								<dt><?php esc_html_e( 'Sites', 'wise-addons-for-elementor-pro' ); ?></dt>
								<dd>
									<?php
									printf(
										/* translators: 1: sites used, 2: site limit */
										esc_html__( '%1$d of %2$d used', 'wise-addons-for-elementor-pro' ),
										(int) $data['activation_usage'],
										(int) $data['activation_limit']
									);
									?>
								</dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Last checked', 'wise-addons-for-elementor-pro' ); ?></dt>
							<dd><?php echo esc_html( $data['checked'] ? sprintf( /* translators: %s: time ago */ __( '%s ago', 'wise-addons-for-elementor-pro' ), human_time_diff( (int) $data['checked'] ) ) : '—' ); ?></dd>
						</dl>

						<?php if ( 'expired' === $status ) : ?>
							<p class="wap-note"><?php esc_html_e( 'Your Pro widgets keep working. Renew the license to get updates and new Pro features.', 'wise-addons-for-elementor-pro' ); ?></p>
						<?php elseif ( 'active' !== $status && $data['error'] ) : ?>
							<p class="wap-note wap-note--bad"><?php echo esc_html( $data['error'] ); ?></p>
						<?php endif; ?>

						<div class="wap-actions">
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<?php wp_nonce_field( 'wiseaddons_pro_license' ); ?>
								<input type="hidden" name="action" value="wiseaddons_pro_license">
								<input type="hidden" name="task" value="check">
								<button type="submit" class="wap-btn"><?php esc_html_e( 'Check now', 'wise-addons-for-elementor-pro' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<?php wp_nonce_field( 'wiseaddons_pro_license' ); ?>
								<input type="hidden" name="action" value="wiseaddons_pro_license">
								<input type="hidden" name="task" value="deactivate">
								<button type="submit" class="wap-btn wap-btn--ghost"><?php esc_html_e( 'Deactivate on this site', 'wise-addons-for-elementor-pro' ); ?></button>
							</form>
						</div>
					<?php endif; ?>
				</section>

				<aside class="wap-card wap-card--side">
					<h2><?php esc_html_e( 'Included in Pro', 'wise-addons-for-elementor-pro' ); ?></h2>
					<ul class="wap-list">
						<?php foreach ( array_merge( Pro_Map::widgets(), Pro_Map::features() ) as $item ) : ?>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( $item['title'] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="wap-links">
						<a href="https://app.lemonsqueezy.com/my-orders" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage license', 'wise-addons-for-elementor-pro' ); ?></a>
						<a href="https://wiseaddons.com/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'wise-addons-for-elementor-pro' ); ?></a>
						<a href="https://wisemattic.com/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'wise-addons-for-elementor-pro' ); ?></a>
					</p>
				</aside>
			</div>
		</div>
		<?php
	}
}
