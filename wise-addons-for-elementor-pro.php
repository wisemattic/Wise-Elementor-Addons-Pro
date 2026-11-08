<?php
/**
 * Plugin Name:       Wise Addons for Elementor Pro
 * Plugin URI:        https://wiseaddons.com/pro/
 * Description:       Pro widgets and features for Wise Addons for Elementor: Display Conditions, Sticky Element, Particle Effects, Image Masking, Horizontal Scroll, Live Copy, Mega Menu, Post Carousel, Product Grid and Table of Contents.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  elementor, wise-addons-for-elementor
 * Author:            Wisemattic
 * Author URI:        https://wisemattic.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wise-addons-for-elementor-pro
 * Domain Path:       /languages
 * Elementor tested up to: 4.3.6
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

defined( 'ABSPATH' ) || exit;

define( 'WISEADDONS_PRO_VERSION', '1.0.0' );
define( 'WISEADDONS_PRO_FILE', __FILE__ );
define( 'WISEADDONS_PRO_PATH', plugin_dir_path( WISEADDONS_PRO_FILE ) );
define( 'WISEADDONS_PRO_URL', plugin_dir_url( WISEADDONS_PRO_FILE ) );
define( 'WISEADDONS_PRO_BASENAME', plugin_basename( WISEADDONS_PRO_FILE ) );

/*
 * The free plugin's add-on API version this release needs (WISEADDONS_ADDON_API).
 * Wise Addons for Elementor 1.1.0 is the first version that has it.
 */
define( 'WISEADDONS_PRO_NEEDS_ADDON_API', 1 );
define( 'WISEADDONS_PRO_NEEDS_FREE', '1.1.0' );

require_once WISEADDONS_PRO_PATH . 'includes/class-plugin.php';

register_activation_hook( WISEADDONS_PRO_FILE, [ 'WiseAddonsPro\Plugin', 'activate' ] );
register_deactivation_hook( WISEADDONS_PRO_FILE, [ 'WiseAddonsPro\Plugin', 'deactivate' ] );

WiseAddonsPro\Plugin::instance();
