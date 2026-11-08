<?php
/**
 * Uninstall: remove the license data and cached release details.
 *
 * The Pro widgets' on/off switches live in the free plugin's options and are removed
 * with the free plugin. The license activation stays counted at Lemon Squeezy; deactivate
 * it on the license page first to free the site slot.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wiseaddons_pro_license' );
delete_site_transient( 'wiseaddons_pro_release' );
wp_clear_scheduled_hook( 'wiseaddons_pro_license_check' );
