<?php
/**
 * Adds the Pro widgets and features to the free plugin's maps.
 *
 * The free plugin then handles everything else exactly as for its own items: the dashboard
 * switches, Elementor registration and per-widget asset loading. Each Pro item lives in
 * widgets/<slug>/ or features/<slug>/ of this plugin and follows the free plugin's
 * docs/DEVELOPING.md, with classes in the WiseAddonsPro\Widgets and WiseAddonsPro\Features
 * namespaces.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro;

defined( 'ABSPATH' ) || exit;

/**
 * Pro registry.
 */
final class Pro_Map {

	/**
	 * Hook into the free plugin's maps.
	 */
	public static function init() {
		add_filter( 'wiseaddons/widgets_map', [ __CLASS__, 'add_widgets' ] );
		add_filter( 'wiseaddons/features_map', [ __CLASS__, 'add_features' ] );
	}

	/**
	 * Pro widgets. Keys follow the free plugin's Widgets_Map; "requires" is checked here.
	 *
	 * @return array
	 */
	public static function widgets() {
		return [
			'mega-menu'         => [
				'title'    => __( 'Mega Menu', 'wise-addons-for-elementor-pro' ),
				'desc'     => __( 'Navigation menu with mega dropdowns: card panels with ready design presets or any Elementor template, plus a mobile off-canvas menu.', 'wise-addons-for-elementor-pro' ),
				'icon'     => 'eicon-nav-menu',
				'cat'      => 'theme',
				'class'    => '\\WiseAddonsPro\\Widgets\\Mega_Menu',
				'dynamic'  => true,
				'keywords' => [ 'menu', 'mega', 'navigation', 'nav', 'header', 'dropdown' ],
			],
			'post-carousel'     => [
				'title'    => __( 'Post Carousel', 'wise-addons-for-elementor-pro' ),
				'desc'     => __( 'Posts or any post type in a touch-friendly carousel with several card layouts.', 'wise-addons-for-elementor-pro' ),
				'icon'     => 'eicon-posts-carousel',
				'cat'      => 'posts',
				'class'    => '\\WiseAddonsPro\\Widgets\\Post_Carousel',
				'libs'     => [
					'css' => [ 'swiper' ],
					'js'  => [ 'swiper' ],
				],
				'dynamic'  => true,
				'keywords' => [ 'post', 'blog', 'carousel', 'slider', 'news' ],
			],
			'product-grid'      => [
				'title'    => __( 'Product Grid', 'wise-addons-for-elementor-pro' ),
				'desc'     => __( 'WooCommerce products in a styled grid with filters, badges, ratings and add to cart.', 'wise-addons-for-elementor-pro' ),
				'icon'     => 'eicon-products',
				'cat'      => 'woo',
				'class'    => '\\WiseAddonsPro\\Widgets\\Product_Grid',
				'dynamic'  => true,
				'requires' => 'woocommerce',
				'keywords' => [ 'woocommerce', 'product', 'shop', 'store', 'grid' ],
			],
			'table-of-contents' => [
				'title'    => __( 'Table of Contents', 'wise-addons-for-elementor-pro' ),
				'desc'     => __( 'Automatic, collapsible table of contents built from the headings on the page, with scroll spy.', 'wise-addons-for-elementor-pro' ),
				'icon'     => 'eicon-table-of-contents',
				'cat'      => 'content',
				'class'    => '\\WiseAddonsPro\\Widgets\\Table_Of_Contents',
				'keywords' => [ 'toc', 'contents', 'headings', 'index', 'anchor' ],
			],
		];
	}

	/**
	 * Pro features.
	 *
	 * @return array
	 */
	public static function features() {
		return [
			'display-conditions' => [
				'title' => __( 'Display Conditions', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Show or hide any element by login state, user role, device, date and time, page, URL parameter and more.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-flow',
				'cat'   => 'utility',
				'class' => '\\WiseAddonsPro\\Features\\Display_Conditions',
			],
			'sticky-element'     => [
				'title' => __( 'Sticky Element', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Pin any element, column or container while scrolling, inside its parent or the whole page.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-pin',
				'cat'   => 'layout',
				'class' => '\\WiseAddonsPro\\Features\\Sticky_Element',
			],
			'particle-effects'   => [
				'title' => __( 'Particle Effects', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Animated particle backgrounds for containers and sections: links, bubbles, snow, stars.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-flash',
				'cat'   => 'effects',
				'class' => '\\WiseAddonsPro\\Features\\Particle_Effects',
			],
			'image-masking'      => [
				'title' => __( 'Image Masking', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Cut images and other elements into shapes, or use your own SVG or PNG mask.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-image-rollover',
				'cat'   => 'effects',
				'class' => '\\WiseAddonsPro\\Features\\Image_Masking',
			],
			'horizontal-scroll'  => [
				'title' => __( 'Horizontal Scroll', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Turn vertical scrolling into a horizontal slide through the items of a container.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-h-align-stretch',
				'cat'   => 'layout',
				'class' => '\\WiseAddonsPro\\Features\\Horizontal_Scroll',
			],
			'live-copy'          => [
				'title' => __( 'Live Copy', 'wise-addons-for-elementor-pro' ),
				'desc'  => __( 'Copy a section on one site and paste it into the Elementor editor of another, with its images.', 'wise-addons-for-elementor-pro' ),
				'icon'  => 'eicon-copy',
				'cat'   => 'utility',
				'class' => '\\WiseAddonsPro\\Features\\Live_Copy',
			],
		];
	}

	/**
	 * Add the built Pro widgets to the free plugin's widget map.
	 *
	 * @param array $map Widget map.
	 * @return array
	 */
	public static function add_widgets( $map ) {
		foreach ( self::widgets() as $slug => $entry ) {
			if ( isset( $map[ $slug ] ) || ! self::available( 'widgets', $slug, 'widget.php', $entry ) ) {
				continue;
			}
			unset( $entry['requires'] );
			$map[ $slug ] = $entry + self::location( 'widgets', $slug );
		}
		return $map;
	}

	/**
	 * Add the built Pro features to the free plugin's feature map.
	 *
	 * @param array $map Feature map.
	 * @return array
	 */
	public static function add_features( $map ) {
		foreach ( self::features() as $slug => $entry ) {
			if ( isset( $map[ $slug ] ) || ! self::available( 'features', $slug, 'feature.php', $entry ) ) {
				continue;
			}
			unset( $entry['requires'] );
			$map[ $slug ] = $entry + self::location( 'features', $slug );
		}
		return $map;
	}

	/**
	 * Whether an item is built and its required plugin is active.
	 *
	 * @param string $type  widgets or features.
	 * @param string $slug  Item slug.
	 * @param string $file  Main file name.
	 * @param array  $entry Map entry.
	 * @return bool
	 */
	private static function available( $type, $slug, $file, array $entry ) {
		if ( ! file_exists( WISEADDONS_PRO_PATH . $type . '/' . $slug . '/' . $file ) ) {
			return false;
		}
		if ( 'woocommerce' === ( $entry['requires'] ?? '' ) && ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Path, URL and Pro flag of an item.
	 *
	 * @param string $type widgets or features.
	 * @param string $slug Item slug.
	 * @return array
	 */
	private static function location( $type, $slug ) {
		return [
			'path'   => WISEADDONS_PRO_PATH . $type . '/' . $slug . '/',
			'url'    => WISEADDONS_PRO_URL . $type . '/' . $slug . '/',
			'pro'     => true,
			'is_new'  => true,
			'version' => WISEADDONS_PRO_VERSION,
		];
	}
}
