<?php
/**
 * Product Grid widget (Pro, WooCommerce).
 *
 * WooCommerce products in a responsive grid with badges, rating, price and
 * WooCommerce's own add to cart button. Pagination by page numbers or a
 * "Load more" button that renders the next page from the element's saved
 * settings on the server (see load-more.php).
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Css_Filter;
use Elementor\Group_Control_Image_Size;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use WiseAddons\Kses;
use WiseAddons\Widget_Base;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/load-more.php';

Product_Grid_Load_More::init();

/**
 * Product grid.
 */
class Product_Grid extends Widget_Base {

	const SLUG = 'product-grid';

	/**
	 * Query string key prefix for the page number. The element ID is appended.
	 */
	const PAGE_VAR = 'wea-pg-';

	/**
	 * Most products one page may show.
	 */
	const MAX_PER_PAGE = 48;

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->register_query_section();
		$this->register_layout_section();
		$this->register_elements_section();
		$this->register_badges_section();
		$this->register_pagination_section();

		$this->register_card_style();
		$this->register_image_style();
		$this->register_badges_style();
		$this->register_text_style();
		$this->register_rating_style();
		$this->register_price_style();
		$this->register_button_style();
		$this->register_pagination_style();
	}

	/* ------------------------------------------------------------------ *
	 * Content tab
	 * ------------------------------------------------------------------ */

	/**
	 * Terms of a product taxonomy for SELECT2 controls.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private static function term_options( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 300,
			]
		);
		$options = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->term_id ] = $term->name;
			}
		}
		return $options;
	}

	/**
	 * Content: query.
	 */
	private function register_query_section() {
		$this->start_controls_section(
			'section_query',
			[
				'label' => __( 'Query', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Source', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'recent',
				'options' => [
					'recent'       => __( 'Recent Products', 'wise-addons-for-elementor-pro' ),
					'featured'     => __( 'Featured Products', 'wise-addons-for-elementor-pro' ),
					'sale'         => __( 'On Sale', 'wise-addons-for-elementor-pro' ),
					'best_selling' => __( 'Best Selling', 'wise-addons-for-elementor-pro' ),
					'top_rated'    => __( 'Top Rated', 'wise-addons-for-elementor-pro' ),
					'terms'        => __( 'By Categories / Tags', 'wise-addons-for-elementor-pro' ),
					'manual'       => __( 'Manual Selection', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$this->add_control(
			'product_ids',
			[
				'label'       => __( 'Product IDs', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '12, 34, 56',
				'description' => __( 'Comma-separated product IDs.', 'wise-addons-for-elementor-pro' ),
				'label_block' => true,
				'condition'   => [ 'source' => 'manual' ],
			]
		);

		$this->add_control(
			'product_cats',
			[
				'label'       => __( 'Categories', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::term_options( 'product_cat' ),
				'default'     => [],
				'condition'   => [ 'source!' => 'manual' ],
			]
		);

		$this->add_control(
			'product_tags',
			[
				'label'       => __( 'Tags', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::term_options( 'product_tag' ),
				'default'     => [],
				'condition'   => [ 'source!' => 'manual' ],
			]
		);

		$this->add_control(
			'exclude_ids',
			[
				'label'       => __( 'Exclude Product IDs', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '12, 34',
				'label_block' => true,
				'condition'   => [ 'source!' => 'manual' ],
			]
		);

		$this->add_control(
			'hide_out_of_stock',
			[
				'label'   => __( 'Exclude Out of Stock', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			]
		);

		$this->add_control(
			'orderby',
			[
				'label'     => __( 'Order By', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'date',
				'options'   => [
					'date'       => __( 'Date', 'wise-addons-for-elementor-pro' ),
					'price'      => __( 'Price', 'wise-addons-for-elementor-pro' ),
					'popularity' => __( 'Popularity (sales)', 'wise-addons-for-elementor-pro' ),
					'rating'     => __( 'Average Rating', 'wise-addons-for-elementor-pro' ),
					'title'      => __( 'Title', 'wise-addons-for-elementor-pro' ),
					'menu_order' => __( 'Menu Order', 'wise-addons-for-elementor-pro' ),
					'rand'       => __( 'Random', 'wise-addons-for-elementor-pro' ),
					'post__in'   => __( 'Product IDs Order', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [ 'source!' => [ 'best_selling', 'top_rated' ] ],
			]
		);

		$this->add_control(
			'order',
			[
				'label'     => __( 'Order', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'DESC',
				'options'   => [
					'DESC' => __( 'Descending', 'wise-addons-for-elementor-pro' ),
					'ASC'  => __( 'Ascending', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [
					'source!'  => [ 'best_selling', 'top_rated' ],
					'orderby!' => [ 'rand', 'post__in' ],
				],
			]
		);

		$this->add_control(
			'limit',
			[
				'label'       => __( 'Products per Page', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 8,
				'min'         => 1,
				'max'         => self::MAX_PER_PAGE,
				'separator'   => 'before',
			]
		);

		$this->add_control(
			'offset',
			[
				'label'   => __( 'Offset', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
				'max'     => 100,
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: grid layout.
	 */
	private function register_layout_section() {
		$this->start_controls_section(
			'section_layout',
			[
				'label' => __( 'Layout', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'card_style',
			[
				'label'   => __( 'Card Style', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'classic',
				'options' => [
					'classic'  => __( 'Classic', 'wise-addons-for-elementor-pro' ),
					'overlay'  => __( 'Actions on Hover', 'wise-addons-for-elementor-pro' ),
					'minimal'  => __( 'Minimal', 'wise-addons-for-elementor-pro' ),
					'bordered' => __( 'Bordered', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$this->add_responsive_control(
			'columns',
			[
				'label'              => __( 'Columns', 'wise-addons-for-elementor-pro' ),
				'type'               => Controls_Manager::SELECT,
				'default'            => '4',
				'tablet_default'     => '3',
				'mobile_default'     => '2',
				'options'            => [
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				],
				// Makes Elementor create one control per device, so the tablet and mobile defaults apply.
				'frontend_available' => true,
				'selectors'          => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-cols: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'column_gap',
			[
				'label'      => __( 'Column Gap', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 80 ] ],
				'selectors'  => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-col-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'row_gap',
			[
				'label'      => __( 'Row Gap', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 100 ] ],
				'selectors'  => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-row-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'text_align',
			[
				'label'     => __( 'Alignment', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'start'  => [
						'title' => __( 'Start', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Center', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-center',
					],
					'end'    => [
						'title' => __( 'End', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid__body' => 'text-align: {{VALUE}}; --wea-pg-justify: {{VALUE}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: card elements.
	 */
	private function register_elements_section() {
		$this->start_controls_section(
			'section_elements',
			[
				'label' => __( 'Card Content', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'show_image',
			[
				'label'   => __( 'Image', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			[
				'name'      => 'image',
				'default'   => 'woocommerce_thumbnail',
				'exclude'   => [],
				'condition' => [ 'show_image' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'image_ratio',
			[
				'label'     => __( 'Image Aspect Ratio', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''     => __( 'Default (1:1)', 'wise-addons-for-elementor-pro' ),
					'4/3'  => '4:3',
					'3/4'  => '3:4',
					'4/5'  => '4:5',
					'2/3'  => '2:3',
					'16/9' => '16:9',
					'auto' => __( 'Original', 'wise-addons-for-elementor-pro' ),
				],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-ratio: {{VALUE}};' ],
				'condition' => [ 'show_image' => 'yes' ],
			]
		);

		$this->add_control(
			'image_swap',
			[
				'label'       => __( 'Show Second Image on Hover', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'Uses the first image of the product gallery.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'show_image' => 'yes' ],
			]
		);

		$switches = [
			'show_category' => [ __( 'Category', 'wise-addons-for-elementor-pro' ), 'yes' ],
			'show_title'    => [ __( 'Title', 'wise-addons-for-elementor-pro' ), 'yes' ],
		];
		foreach ( $switches as $id => $item ) {
			$this->add_control(
				$id,
				[
					'label'     => $item[0],
					'type'      => Controls_Manager::SWITCHER,
					'default'   => $item[1],
					'separator' => 'show_category' === $id ? 'before' : 'default',
				]
			);
		}

		$this->add_control(
			'title_tag',
			[
				'label'     => __( 'Title HTML Tag', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'h3',
				'options'   => self::heading_tag_options(),
				'condition' => [ 'show_title' => 'yes' ],
			]
		);

		$this->add_control(
			'show_rating',
			[
				'label'   => __( 'Rating', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'show_empty_rating',
			[
				'label'     => __( 'Show Stars Without Reviews', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'condition' => [ 'show_rating' => 'yes' ],
			]
		);

		$this->add_control(
			'show_price',
			[
				'label'   => __( 'Price', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'show_excerpt',
			[
				'label'   => __( 'Short Description', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			]
		);

		$this->add_control(
			'excerpt_length',
			[
				'label'     => __( 'Length (words)', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 12,
				'min'       => 1,
				'max'       => 100,
				'condition' => [ 'show_excerpt' => 'yes' ],
			]
		);

		$this->add_control(
			'show_button',
			[
				'label'       => __( 'Add to Cart Button', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => __( 'WooCommerce\'s own button: simple products are added with AJAX, other types link to the product.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'no_products_message',
			[
				'label'       => __( 'Nothing Found Message', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No products found.', 'wise-addons-for-elementor-pro' ),
				'label_block' => true,
				'separator'   => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: badges.
	 */
	private function register_badges_section() {
		$this->start_controls_section(
			'section_badges',
			[
				'label' => __( 'Badges', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'badge_sale',
			[
				'label'   => __( 'Sale', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'badge_sale_type',
			[
				'label'     => __( 'Sale Badge Shows', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'percent',
				'options'   => [
					'percent' => __( 'Discount (-25%)', 'wise-addons-for-elementor-pro' ),
					'text'    => __( 'Text', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [ 'badge_sale' => 'yes' ],
			]
		);

		$this->add_control(
			'badge_sale_text',
			[
				'label'       => __( 'Sale Text', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Sale', 'wise-addons-for-elementor-pro' ),
				'description' => __( 'Also used when the discount cannot be worked out, for example on grouped products.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'badge_sale' => 'yes' ],
			]
		);

		$this->add_control(
			'badge_new',
			[
				'label'     => __( 'New', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'badge_new_days',
			[
				'label'       => __( 'New for (days)', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 30,
				'min'         => 1,
				'max'         => 365,
				'condition'   => [ 'badge_new' => 'yes' ],
			]
		);

		$this->add_control(
			'badge_new_text',
			[
				'label'     => __( 'New Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'New', 'wise-addons-for-elementor-pro' ),
				'condition' => [ 'badge_new' => 'yes' ],
			]
		);

		$this->add_control(
			'badge_stock',
			[
				'label'     => __( 'Out of Stock', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'badge_stock_text',
			[
				'label'     => __( 'Out of Stock Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Sold out', 'wise-addons-for-elementor-pro' ),
				'condition' => [ 'badge_stock' => 'yes' ],
			]
		);

		$this->add_control(
			'badge_featured',
			[
				'label'     => __( 'Featured', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'badge_featured_text',
			[
				'label'     => __( 'Featured Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Featured', 'wise-addons-for-elementor-pro' ),
				'condition' => [ 'badge_featured' => 'yes' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: pagination.
	 */
	private function register_pagination_section() {
		$this->start_controls_section(
			'section_pagination',
			[
				'label' => __( 'Pagination', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'pagination',
			[
				'label'   => __( 'Pagination', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'none',
				'options' => [
					'none'      => __( 'None', 'wise-addons-for-elementor-pro' ),
					'numbers'   => __( 'Page Numbers', 'wise-addons-for-elementor-pro' ),
					'load_more' => __( 'Load More Button', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$this->add_control(
			'load_more_text',
			[
				'label'     => __( 'Button Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Load more', 'wise-addons-for-elementor-pro' ),
				'condition' => [ 'pagination' => 'load_more' ],
			]
		);

		$this->add_control(
			'load_more_loading_text',
			[
				'label'     => __( 'Loading Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Loading…', 'wise-addons-for-elementor-pro' ),
				'condition' => [ 'pagination' => 'load_more' ],
			]
		);

		$this->add_control(
			'max_pages',
			[
				'label'       => __( 'Page Limit', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'min'         => 1,
				'max'         => 100,
				'description' => __( 'Leave empty for no limit.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'pagination!' => 'none' ],
			]
		);

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------ *
	 * Style tab
	 * ------------------------------------------------------------------ */

	/**
	 * Normal / Hover tabs helper. Each entry is a COLOR control unless it sets 'type' or 'group'.
	 *
	 * @param string $prefix Tabs ID prefix.
	 * @param array  $normal Normal state controls.
	 * @param array  $hover  Hover state controls.
	 */
	private function add_state_tabs( $prefix, array $normal, array $hover ) {
		$this->start_controls_tabs( $prefix . '_tabs' );
		foreach ( [ 'normal' => $normal, 'hover' => $hover ] as $state => $controls ) {
			$this->start_controls_tab(
				$prefix . '_tab_' . $state,
				[ 'label' => 'normal' === $state ? __( 'Normal', 'wise-addons-for-elementor-pro' ) : __( 'Hover', 'wise-addons-for-elementor-pro' ) ]
			);
			foreach ( $controls as $id => $args ) {
				if ( isset( $args['group'] ) ) {
					$group = $args['group'];
					unset( $args['group'] );
					$this->add_group_control( $group, array_merge( [ 'name' => $id ], $args ) );
					continue;
				}
				$this->add_control( $id, array_merge( [ 'type' => Controls_Manager::COLOR ], $args ) );
			}
			$this->end_controls_tab();
		}
		$this->end_controls_tabs();
	}

	/**
	 * Dimensions control shortcut.
	 *
	 * @param string $id       Control ID.
	 * @param string $label    Label.
	 * @param string $selector Selector.
	 * @param string $property padding|margin|border-radius.
	 * @param array  $extra    Extra args.
	 */
	private function add_box_control( $id, $label, $selector, $property, $extra = [] ) {
		$this->add_responsive_control(
			$id,
			array_merge(
				[
					'label'      => $label,
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => [ 'px', '%', 'em' ],
					'selectors'  => [ $selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
				],
				$extra
			)
		);
	}

	/**
	 * Style: card.
	 */
	private function register_card_style() {
		$card = '{{WRAPPER}} .wea-product-grid__card';

		$this->start_controls_section(
			'section_style_card',
			[
				'label' => __( 'Card', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_box_control( 'card_padding', __( 'Content Padding', 'wise-addons-for-elementor-pro' ), '{{WRAPPER}} .wea-product-grid__body', 'padding' );

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'card_border',
				'selector'  => $card,
				'separator' => 'before',
			]
		);

		$this->add_box_control( 'card_radius', __( 'Border Radius', 'wise-addons-for-elementor-pro' ), $card, 'border-radius' );

		$this->add_state_tabs(
			'card',
			[
				'card_background' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $card => 'background-color: {{VALUE}};' ],
				],
				'card_shadow'     => [
					'group'    => Group_Control_Box_Shadow::get_type(),
					'selector' => $card,
				],
			],
			[
				'card_background_hover'   => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $card . ':hover, ' . $card . ':focus-within' => 'background-color: {{VALUE}};' ],
				],
				'card_border_color_hover' => [
					'label'     => __( 'Border Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $card . ':hover, ' . $card . ':focus-within' => 'border-color: {{VALUE}};' ],
				],
				'card_shadow_hover'       => [
					'group'    => Group_Control_Box_Shadow::get_type(),
					'selector' => $card . ':hover, ' . $card . ':focus-within',
				],
				'card_lift'               => [
					'label'     => __( 'Lift (px)', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => [ 'px' => [ 'min' => 0, 'max' => 20 ] ],
					'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-lift: -{{SIZE}}px;' ],
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: image.
	 */
	private function register_image_style() {
		$media = '{{WRAPPER}} .wea-product-grid__media';

		$this->start_controls_section(
			'section_style_image',
			[
				'label'     => __( 'Image', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_image' => 'yes' ],
			]
		);

		$this->add_control(
			'image_background',
			[
				'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $media => 'background-color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'image_fit',
			[
				'label'     => __( 'Image Fit', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''        => __( 'Cover', 'wise-addons-for-elementor-pro' ),
					'contain' => __( 'Contain', 'wise-addons-for-elementor-pro' ),
				],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid__image' => 'object-fit: {{VALUE}};' ],
			]
		);

		$this->add_box_control( 'image_radius', __( 'Border Radius', 'wise-addons-for-elementor-pro' ), $media, 'border-radius' );

		$this->add_box_control(
			'image_margin',
			__( 'Margin', 'wise-addons-for-elementor-pro' ),
			$media,
			'margin',
			[ 'size_units' => [ 'px', 'em' ] ]
		);

		$this->add_control(
			'image_zoom',
			[
				'label'        => __( 'Zoom on Hover', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'prefix_class' => 'wea-product-grid--zoom-',
			]
		);

		$this->add_state_tabs(
			'image_filter',
			[
				'image_filters' => [
					'group'    => Group_Control_Css_Filter::get_type(),
					'selector' => '{{WRAPPER}} .wea-product-grid__image',
				],
			],
			[
				'image_filters_hover' => [
					'group'    => Group_Control_Css_Filter::get_type(),
					'selector' => '{{WRAPPER}} .wea-product-grid__card:hover .wea-product-grid__image',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: badges.
	 */
	private function register_badges_style() {
		$badge = '{{WRAPPER}} .wea-product-grid__badge';

		$this->start_controls_section(
			'section_style_badges',
			[
				'label' => __( 'Badges', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'badge_typography',
				'selector' => $badge,
			]
		);

		$this->add_box_control( 'badge_padding', __( 'Padding', 'wise-addons-for-elementor-pro' ), $badge, 'padding', [ 'size_units' => [ 'px', 'em' ] ] );
		$this->add_box_control( 'badge_radius', __( 'Border Radius', 'wise-addons-for-elementor-pro' ), $badge, 'border-radius' );

		$this->add_responsive_control(
			'badge_offset',
			[
				'label'     => __( 'Offset', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'max' => 40 ] ],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-badge-offset: {{SIZE}}px;' ],
			]
		);

		$kinds = [
			'sale'     => __( 'Sale', 'wise-addons-for-elementor-pro' ),
			'new'      => __( 'New', 'wise-addons-for-elementor-pro' ),
			'stock'    => __( 'Out of Stock', 'wise-addons-for-elementor-pro' ),
			'featured' => __( 'Featured', 'wise-addons-for-elementor-pro' ),
		];
		foreach ( $kinds as $kind => $label ) {
			$this->add_control(
				'badge_' . $kind . '_heading',
				[
					'label'     => $label,
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				]
			);
			$this->add_control(
				'badge_' . $kind . '_color',
				[
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => [ $badge . '--' . $kind => 'color: {{VALUE}};' ],
				]
			);
			$this->add_control(
				'badge_' . $kind . '_background',
				[
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => [ $badge . '--' . $kind => 'background-color: {{VALUE}};' ],
				]
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Style: category, title and short description.
	 */
	private function register_text_style() {
		$parts = [
			'category' => [ __( 'Category', 'wise-addons-for-elementor-pro' ), '.wea-product-grid__category', Global_Typography::TYPOGRAPHY_TEXT, 'show_category' ],
			'title'    => [ __( 'Title', 'wise-addons-for-elementor-pro' ), '.wea-product-grid__title', Global_Typography::TYPOGRAPHY_PRIMARY, 'show_title' ],
			'excerpt'  => [ __( 'Short Description', 'wise-addons-for-elementor-pro' ), '.wea-product-grid__excerpt', Global_Typography::TYPOGRAPHY_TEXT, 'show_excerpt' ],
		];

		foreach ( $parts as $key => $part ) {
			list( $label, $class, $typo, $switch ) = $part;
			$sel = '{{WRAPPER}} ' . $class;

			$this->start_controls_section(
				'section_style_' . $key,
				[
					'label'     => $label,
					'tab'       => Controls_Manager::TAB_STYLE,
					'condition' => [ $switch => 'yes' ],
				]
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				[
					'name'     => $key . '_typography',
					'selector' => $sel,
					'global'   => [ 'default' => $typo ],
				]
			);

			if ( 'excerpt' === $key ) {
				$this->add_control(
					$key . '_color',
					[
						'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
						'type'      => Controls_Manager::COLOR,
						'selectors' => [ $sel => 'color: {{VALUE}};' ],
					]
				);
			} else {
				$this->add_state_tabs(
					$key,
					[
						$key . '_color' => [
							'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
							'selectors' => [ $sel . ', ' . $sel . ' a' => 'color: {{VALUE}};' ],
						],
					],
					[
						$key . '_color_hover' => [
							'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
							'selectors' => [ $sel . ' a:hover, ' . $sel . ' a:focus-visible' => 'color: {{VALUE}};' ],
						],
					]
				);
			}

			$this->add_responsive_control(
				$key . '_spacing',
				[
					'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => [ 'px', 'em' ],
					'range'      => [ 'px' => [ 'max' => 60 ] ],
					'separator'  => 'before',
					'selectors'  => [ $sel => 'margin-block-end: {{SIZE}}{{UNIT}};' ],
				]
			);

			$this->end_controls_section();
		}
	}

	/**
	 * Style: rating.
	 */
	private function register_rating_style() {
		$this->start_controls_section(
			'section_style_rating',
			[
				'label'     => __( 'Rating', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_rating' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'rating_size',
			[
				'label'     => __( 'Star Size', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 8, 'max' => 40 ] ],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid__stars' => 'font-size: {{SIZE}}px;' ],
			]
		);

		$this->add_control(
			'rating_color',
			[
				'label'     => __( 'Star Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-star: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'rating_empty_color',
			[
				'label'     => __( 'Empty Star Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-star-empty: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'rating_count_color',
			[
				'label'     => __( 'Count Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .wea-product-grid__rating-count' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'rating_spacing',
			[
				'label'     => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'max' => 40 ] ],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid__rating' => 'margin-block-end: {{SIZE}}px;' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: price.
	 */
	private function register_price_style() {
		$price = '{{WRAPPER}} .wea-product-grid__price';

		$this->start_controls_section(
			'section_style_price',
			[
				'label'     => __( 'Price', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_price' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'price_typography',
				'selector' => $price,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_ACCENT ],
			]
		);

		$this->add_control(
			'price_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $price => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'price_sale_color',
			[
				'label'     => __( 'Sale Price Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $price . ' ins' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'price_regular_color',
			[
				'label'     => __( 'Old Price Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $price . ' del' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'price_spacing',
			[
				'label'     => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'max' => 40 ] ],
				'selectors' => [ $price => 'margin-block-end: {{SIZE}}px;' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: add to cart button.
	 */
	private function register_button_style() {
		$btn = '{{WRAPPER}} .wea-product-grid__button';

		$this->start_controls_section(
			'section_style_button',
			[
				'label'     => __( 'Button', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_button' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'button_typography',
				'selector' => $btn,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_ACCENT ],
			]
		);

		$this->add_control(
			'button_full_width',
			[
				'label'        => __( 'Full Width', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'prefix_class' => 'wea-product-grid--btn-full-',
			]
		);

		$this->add_state_tabs(
			'button',
			[
				'button_color'      => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $btn => 'color: {{VALUE}};' ],
				],
				'button_background' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $btn => 'background-color: {{VALUE}};' ],
				],
				'button_shadow'     => [
					'group'    => Group_Control_Box_Shadow::get_type(),
					'selector' => $btn,
				],
			],
			[
				'button_color_hover'        => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $btn . ':hover, ' . $btn . ':focus-visible' => 'color: {{VALUE}};' ],
				],
				'button_background_hover'   => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $btn . ':hover, ' . $btn . ':focus-visible' => 'background-color: {{VALUE}};' ],
				],
				'button_border_color_hover' => [
					'label'     => __( 'Border Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $btn . ':hover, ' . $btn . ':focus-visible' => 'border-color: {{VALUE}};' ],
				],
				'button_shadow_hover'       => [
					'group'    => Group_Control_Box_Shadow::get_type(),
					'selector' => $btn . ':hover, ' . $btn . ':focus-visible',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'button_border',
				'selector'  => $btn,
				'separator' => 'before',
			]
		);

		$this->add_box_control( 'button_radius', __( 'Border Radius', 'wise-addons-for-elementor-pro' ), $btn, 'border-radius' );
		$this->add_box_control( 'button_padding', __( 'Padding', 'wise-addons-for-elementor-pro' ), $btn, 'padding', [ 'size_units' => [ 'px', 'em' ] ] );

		$this->end_controls_section();
	}

	/**
	 * Style: pagination and load more.
	 */
	private function register_pagination_style() {
		$nav  = '{{WRAPPER}} .wea-product-grid__pagination';
		$item = $nav . ' .page-numbers:not(.dots), {{WRAPPER}} .wea-product-grid__more';

		$this->start_controls_section(
			'section_style_pagination',
			[
				'label'     => __( 'Pagination', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'pagination!' => 'none' ],
			]
		);

		$this->add_responsive_control(
			'pagination_align',
			[
				'label'     => __( 'Alignment', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'flex-start' => [
						'title' => __( 'Start', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center'     => [
						'title' => __( 'Center', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-center',
					],
					'flex-end'   => [
						'title' => __( 'End', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-nav-justify: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'pagination_spacing',
			[
				'label'     => __( 'Spacing Above', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'max' => 100 ] ],
				'selectors' => [ '{{WRAPPER}} .wea-product-grid' => '--wea-pg-nav-space: {{SIZE}}px;' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'pagination_typography',
				'selector' => $nav . ', {{WRAPPER}} .wea-product-grid__more',
			]
		);

		$this->add_state_tabs(
			'pagination',
			[
				'pagination_color'      => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $item => 'color: {{VALUE}};' ],
				],
				'pagination_background' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $item => 'background-color: {{VALUE}};' ],
				],
			],
			[
				'pagination_color_hover'      => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $nav . ' a.page-numbers:hover, ' . $nav . ' .page-numbers.current, {{WRAPPER}} .wea-product-grid__more:hover, {{WRAPPER}} .wea-product-grid__more:focus-visible' => 'color: {{VALUE}};' ],
				],
				'pagination_background_hover' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $nav . ' a.page-numbers:hover, ' . $nav . ' .page-numbers.current, {{WRAPPER}} .wea-product-grid__more:hover, {{WRAPPER}} .wea-product-grid__more:focus-visible' => 'background-color: {{VALUE}};' ],
				],
			]
		);

		$this->add_box_control(
			'pagination_radius',
			__( 'Border Radius', 'wise-addons-for-elementor-pro' ),
			$nav . ' .page-numbers, {{WRAPPER}} .wea-product-grid__more',
			'border-radius',
			[ 'separator' => 'before' ]
		);

		$this->add_box_control(
			'pagination_padding',
			__( 'Load More Padding', 'wise-addons-for-elementor-pro' ),
			'{{WRAPPER}} .wea-product-grid__more',
			'padding',
			[
				'size_units' => [ 'px', 'em' ],
				'condition'  => [ 'pagination' => 'load_more' ],
			]
		);

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------ *
	 * Query
	 * ------------------------------------------------------------------ */

	/**
	 * Settings value with a fallback for hidden (null) controls.
	 *
	 * @param array  $s       Settings.
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function val( $s, $key, $default = '' ) {
		return ( isset( $s[ $key ] ) && null !== $s[ $key ] ) ? $s[ $key ] : $default;
	}

	/**
	 * Products per page, clamped.
	 *
	 * @param array $s Settings.
	 * @return int
	 */
	private static function per_page( $s ) {
		$limit = (int) self::val( $s, 'limit', 8 );
		return max( 1, min( self::MAX_PER_PAGE, $limit ? $limit : 8 ) );
	}

	/**
	 * WP_Query arguments for one page of products.
	 *
	 * @param array $s    Settings.
	 * @param int   $page Page number (1-based).
	 * @return array
	 */
	public function query_args( $s, $page = 1 ) {
		$sources = [ 'recent', 'featured', 'sale', 'best_selling', 'top_rated', 'terms', 'manual' ];
		$source  = in_array( self::val( $s, 'source', 'recent' ), $sources, true ) ? $s['source'] : 'recent';
		$limit   = self::per_page( $s );
		$offset  = max( 0, min( 100, (int) self::val( $s, 'offset', 0 ) ) );
		$page    = max( 1, (int) $page );
		$paged   = 'none' !== self::val( $s, 'pagination', 'none' );

		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'offset'              => $offset + ( $page - 1 ) * $limit,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => ! $paged,
			'orderby'             => 'date',
			'order'               => 'DESC',
		];

		$tax_query  = [ 'relation' => 'AND' ];
		$visibility = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : [];
		$hidden     = [];

		if ( ! empty( $visibility['exclude-from-catalog'] ) ) {
			$hidden[] = (int) $visibility['exclude-from-catalog'];
		}
		if ( ( 'yes' === self::val( $s, 'hide_out_of_stock' ) || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) && ! empty( $visibility['outofstock'] ) ) {
			$hidden[] = (int) $visibility['outofstock'];
		}
		if ( $hidden ) {
			$tax_query[] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => $hidden,
				'operator' => 'NOT IN',
			];
		}

		if ( 'featured' === $source ) {
			$tax_query[] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => [ ! empty( $visibility['featured'] ) ? (int) $visibility['featured'] : 0 ],
			];
		}

		if ( 'manual' === $source ) {
			$ids              = wp_parse_id_list( self::val( $s, 'product_ids' ) );
			$args['post__in'] = $ids ? $ids : [ 0 ];
		} else {
			$cats = array_filter( array_map( 'absint', (array) self::val( $s, 'product_cats', [] ) ) );
			$tags = array_filter( array_map( 'absint', (array) self::val( $s, 'product_tags', [] ) ) );
			if ( $cats ) {
				$tax_query[] = [
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_values( $cats ),
				];
			}
			if ( $tags ) {
				$tax_query[] = [
					'taxonomy' => 'product_tag',
					'field'    => 'term_id',
					'terms'    => array_values( $tags ),
				];
			}

			$exclude = wp_parse_id_list( self::val( $s, 'exclude_ids' ) );
			if ( $exclude ) {
				$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}

			if ( 'sale' === $source ) {
				$on_sale          = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : [];
				$args['post__in'] = array_merge( [ 0 ], array_map( 'absint', $on_sale ) );
			}
		}

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Ordering.
		$order   = 'ASC' === strtoupper( (string) self::val( $s, 'order', 'DESC' ) ) ? 'ASC' : 'DESC';
		$orderby = (string) self::val( $s, 'orderby', 'date' );

		if ( 'best_selling' === $source ) {
			$args['wea_pg_order'] = 'total_sales DESC';
		} elseif ( 'top_rated' === $source ) {
			$args['wea_pg_order'] = 'rating DESC';
		} else {
			switch ( $orderby ) {
				case 'price':
					$args['wea_pg_order'] = 'price ' . $order;
					break;
				case 'popularity':
					$args['wea_pg_order'] = 'total_sales ' . $order;
					break;
				case 'rating':
					$args['wea_pg_order'] = 'rating ' . $order;
					break;
				case 'title':
					$args['orderby'] = 'title';
					$args['order']   = $order;
					break;
				case 'menu_order':
					$args['orderby'] = [
						'menu_order' => $order,
						'title'      => 'ASC',
					];
					break;
				case 'rand':
					// A seed keeps "Load more" pages consistent with the first page for an hour.
					$args['orderby'] = 'RAND(' . ( absint( crc32( $this->get_id() . gmdate( 'YmdH' ) ) ) % 100000 ) . ')';
					break;
				case 'post__in':
					if ( ! empty( $args['post__in'] ) && 'manual' === $source ) {
						$args['orderby'] = 'post__in';
					}
					break;
				default:
					$args['order'] = $order;
			}
		}

		return $args;
	}

	/**
	 * Order by WooCommerce's product lookup table (price, sales, rating) for queries that ask for it.
	 *
	 * @param array     $clauses SQL clauses.
	 * @param \WP_Query $query   Query.
	 * @return array
	 */
	public static function lookup_order_clauses( $clauses, $query ) {
		global $wpdb;

		$key = (string) $query->get( 'wea_pg_order' );
		if ( '' === $key || empty( $wpdb->wc_product_meta_lookup ) ) {
			return $clauses;
		}

		$map = [
			'price ASC'         => 'wea_pg_lookup.min_price ASC',
			'price DESC'        => 'wea_pg_lookup.max_price DESC',
			'total_sales ASC'   => 'wea_pg_lookup.total_sales ASC',
			'total_sales DESC'  => 'wea_pg_lookup.total_sales DESC',
			'rating ASC'        => 'wea_pg_lookup.average_rating ASC, wea_pg_lookup.rating_count ASC',
			'rating DESC'       => 'wea_pg_lookup.average_rating DESC, wea_pg_lookup.rating_count DESC',
		];
		if ( ! isset( $map[ $key ] ) ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], 'wea_pg_lookup' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} wea_pg_lookup ON {$wpdb->posts}.ID = wea_pg_lookup.product_id ";
		}
		$clauses['orderby'] = $map[ $key ] . ", {$wpdb->posts}.ID DESC";

		return $clauses;
	}

	/**
	 * Run the product query for one page.
	 *
	 * @param array $s    Settings.
	 * @param int   $page Page.
	 * @return \WP_Query
	 */
	private function run_query( $s, $page ) {
		add_filter( 'posts_clauses', [ __CLASS__, 'lookup_order_clauses' ], 10, 2 );
		$query = new \WP_Query( $this->query_args( $s, $page ) );
		remove_filter( 'posts_clauses', [ __CLASS__, 'lookup_order_clauses' ], 10 );
		return $query;
	}

	/**
	 * Number of pages for a query, after the offset and the page limit.
	 *
	 * @param array     $s     Settings.
	 * @param \WP_Query $query Query.
	 * @return int
	 */
	private static function total_pages( $s, $query ) {
		$offset = max( 0, min( 100, (int) self::val( $s, 'offset', 0 ) ) );
		$found  = max( 0, (int) $query->found_posts - $offset );
		$pages  = (int) ceil( $found / self::per_page( $s ) );
		$limit  = (int) self::val( $s, 'max_pages', 0 );
		if ( $limit > 0 ) {
			$pages = min( $pages, $limit );
		}
		return max( 1, $pages );
	}

	/* ------------------------------------------------------------------ *
	 * Card parts
	 * ------------------------------------------------------------------ */

	/**
	 * Image tag for an attachment using the Image Size group control.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $s    Settings.
	 * @param array $attr Attributes.
	 * @return string
	 */
	private function image_tag( $id, $s, $attr ) {
		$size = self::val( $s, 'image_size', 'woocommerce_thumbnail' );
		if ( 'custom' !== $size ) {
			return wp_get_attachment_image( $id, $size ? $size : 'woocommerce_thumbnail', false, $attr );
		}
		$src = Group_Control_Image_Size::get_attachment_image_src( $id, 'image', $s );
		if ( ! $src ) {
			return wp_get_attachment_image( $id, 'full', false, $attr );
		}
		$html = '<img src="' . esc_url( $src ) . '"';
		foreach ( $attr as $name => $value ) {
			$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		return $html . ' />';
	}

	/**
	 * Biggest discount in percent, 0 when unknown.
	 *
	 * @param \WC_Product $product Product.
	 * @return int
	 */
	private static function discount_percent( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			$best   = 0;
			foreach ( (array) $prices['regular_price'] as $vid => $regular ) {
				$regular = (float) $regular;
				$sale    = isset( $prices['sale_price'][ $vid ] ) ? (float) $prices['sale_price'][ $vid ] : $regular;
				if ( $regular > 0 && $sale < $regular ) {
					$best = max( $best, ( $regular - $sale ) / $regular * 100 );
				}
			}
			return (int) round( $best );
		}
		if ( $product->is_type( 'grouped' ) ) {
			return 0;
		}
		$regular = (float) $product->get_regular_price();
		$sale    = $product->get_sale_price();
		if ( $regular <= 0 || '' === $sale ) {
			return 0;
		}
		return (int) round( ( $regular - (float) $sale ) / $regular * 100 );
	}

	/**
	 * Badges for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $s       Settings.
	 * @return string
	 */
	private function badges_html( $product, $s ) {
		$badges = [];

		if ( 'yes' === self::val( $s, 'badge_stock' ) && ! $product->is_in_stock() ) {
			$badges['stock'] = self::val( $s, 'badge_stock_text', __( 'Sold out', 'wise-addons-for-elementor-pro' ) );
		} elseif ( 'yes' === self::val( $s, 'badge_sale' ) && $product->is_on_sale() ) {
			$percent = 'percent' === self::val( $s, 'badge_sale_type', 'percent' ) ? self::discount_percent( $product ) : 0;
			/* translators: %d: discount in percent. */
			$badges['sale'] = $percent > 0 ? sprintf( __( '-%d%%', 'wise-addons-for-elementor-pro' ), $percent ) : self::val( $s, 'badge_sale_text', __( 'Sale', 'wise-addons-for-elementor-pro' ) );
		}

		if ( 'yes' === self::val( $s, 'badge_new' ) ) {
			$created = $product->get_date_created();
			$days    = max( 1, (int) self::val( $s, 'badge_new_days', 30 ) );
			if ( $created && ( time() - $created->getTimestamp() ) < $days * DAY_IN_SECONDS ) {
				$badges['new'] = self::val( $s, 'badge_new_text', __( 'New', 'wise-addons-for-elementor-pro' ) );
			}
		}

		if ( 'yes' === self::val( $s, 'badge_featured' ) && $product->is_featured() ) {
			$badges['featured'] = self::val( $s, 'badge_featured_text', __( 'Featured', 'wise-addons-for-elementor-pro' ) );
		}

		$html = '';
		foreach ( $badges as $kind => $text ) {
			if ( '' !== trim( (string) $text ) ) {
				$html .= '<span class="wea-product-grid__badge wea-product-grid__badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
			}
		}
		return $html ? '<div class="wea-product-grid__badges">' . $html . '</div>' : '';
	}

	/**
	 * Star rating.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $s       Settings.
	 * @return string
	 */
	private function rating_html( $product, $s ) {
		if ( ! wc_review_ratings_enabled() ) {
			return '';
		}
		$count   = (int) $product->get_rating_count();
		$average = (float) $product->get_average_rating();
		if ( ! $count && 'yes' !== self::val( $s, 'show_empty_rating' ) ) {
			return '';
		}
		/* translators: %s: average rating. */
		$label = $count ? sprintf( __( 'Rated %s out of 5', 'wise-addons-for-elementor-pro' ), number_format_i18n( $average, 2 ) ) : __( 'Not rated yet', 'wise-addons-for-elementor-pro' );
		$html  = '<div class="wea-product-grid__rating">';
		$html .= '<span class="wea-product-grid__stars" role="img" aria-label="' . esc_attr( $label ) . '" style="--wea-pg-rating:' . esc_attr( round( $average / 5 * 100, 2 ) ) . '%"></span>';
		if ( $count ) {
			$html .= '<span class="wea-product-grid__rating-count">(' . esc_html( number_format_i18n( $count ) ) . '<span class="wea-sr-only"> ' . esc_html( _n( 'review', 'reviews', $count, 'wise-addons-for-elementor-pro' ) ) . '</span>)</span>';
		}
		return $html . '</div>';
	}

	/**
	 * WooCommerce's loop add to cart button with our class added.
	 *
	 * @return string
	 */
	private function button_html() {
		if ( ! function_exists( 'woocommerce_template_loop_add_to_cart' ) ) {
			return '';
		}
		$add_class = function ( $args ) {
			$args['class'] = trim( ( isset( $args['class'] ) ? $args['class'] : 'button' ) . ' wea-product-grid__button' );
			return $args;
		};
		add_filter( 'woocommerce_loop_add_to_cart_args', $add_class, 20 );
		ob_start();
		woocommerce_template_loop_add_to_cart();
		$html = ob_get_clean();
		remove_filter( 'woocommerce_loop_add_to_cart_args', $add_class, 20 );
		return (string) $html;
	}

	/**
	 * Print one product card.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $s       Settings.
	 * @param string      $style   Card style.
	 * @param bool        $eager   Image is likely above the fold.
	 */
	private function render_card( $product, $s, $style, $eager ) {
		$name      = $product->get_name();
		$permalink = $product->get_permalink();
		$allowed   = Kses::allowed_html();
		$button    = 'yes' === self::val( $s, 'show_button' ) ? $this->button_html() : '';
		$classes   = [ 'wea-product-grid__card' ];

		if ( $product->is_on_sale() ) {
			$classes[] = 'is-on-sale';
		}
		if ( ! $product->is_in_stock() ) {
			$classes[] = 'is-out-of-stock';
		}

		echo '<article class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		$badges = $this->badges_html( $product, $s );

		if ( 'yes' === self::val( $s, 'show_image' ) ) {
			$image_id = (int) $product->get_image_id();
			$alt      = $image_id ? trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) : '';
			$attr     = [
				'class'    => 'wea-product-grid__image wea-product-grid__image--main',
				'alt'      => '' !== $alt ? $alt : $name,
				'loading'  => $eager ? 'eager' : 'lazy',
				'decoding' => 'async',
			];
			$main     = $image_id ? $this->image_tag( $image_id, $s, $attr ) : '';
			if ( ! $main && function_exists( 'wc_placeholder_img' ) ) {
				$main = wc_placeholder_img( 'woocommerce_thumbnail', $attr );
			}

			$swap    = '';
			$gallery = 'yes' === self::val( $s, 'image_swap' ) ? $product->get_gallery_image_ids() : [];
			if ( $gallery ) {
				$swap = $this->image_tag(
					(int) reset( $gallery ),
					$s,
					[
						'class'    => 'wea-product-grid__image wea-product-grid__image--hover',
						'alt'      => '',
						'loading'  => 'lazy',
						'decoding' => 'async',
					]
				);
			}

			echo '<div class="' . esc_attr( 'wea-product-grid__media' . ( $swap ? ' has-hover-image' : '' ) ) . '">';
			// The title link names the product, so the image link stays out of the tab order and the accessibility tree.
			echo '<a class="wea-product-grid__thumb" href="' . esc_url( $permalink ) . '" tabindex="-1" aria-hidden="true">';
			echo wp_kses( $main . $swap, $allowed );
			echo '</a>';
			echo wp_kses( $badges, $allowed );
			if ( 'overlay' === $style && $button ) {
				echo '<div class="wea-product-grid__actions">' . wp_kses( $button, $allowed ) . '</div>';
			}
			echo '</div>';
		}

		echo '<div class="wea-product-grid__body">';

		if ( 'yes' !== self::val( $s, 'show_image' ) ) {
			echo wp_kses( $badges, $allowed );
		}

		if ( 'yes' === self::val( $s, 'show_category' ) ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term = reset( $terms );
				$link = get_term_link( $term );
				echo '<div class="wea-product-grid__category">';
				echo is_wp_error( $link ) ? esc_html( $term->name ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
				echo '</div>';
			}
		}

		if ( 'yes' === self::val( $s, 'show_title' ) ) {
			$tag = $this->safe_tag( self::val( $s, 'title_tag', 'h3' ), 'h3' );
			printf(
				'<%1$s class="wea-product-grid__title"><a href="%2$s">%3$s</a></%1$s>',
				tag_escape( $tag ),
				esc_url( $permalink ),
				esc_html( $name )
			);
		}

		if ( 'yes' === self::val( $s, 'show_rating' ) ) {
			echo wp_kses( $this->rating_html( $product, $s ), $allowed );
		}

		if ( 'yes' === self::val( $s, 'show_price' ) ) {
			$price = $product->get_price_html();
			if ( '' !== trim( (string) $price ) ) {
				echo '<div class="wea-product-grid__price">' . wp_kses_post( $price ) . '</div>';
			}
		}

		if ( 'yes' === self::val( $s, 'show_excerpt' ) ) {
			$text = wp_strip_all_tags( strip_shortcodes( (string) $product->get_short_description() ) );
			$text = wp_trim_words( trim( preg_replace( '/\s+/', ' ', $text ) ), max( 1, (int) self::val( $s, 'excerpt_length', 12 ) ), '&hellip;' );
			if ( '' !== $text ) {
				echo '<p class="wea-product-grid__excerpt">' . esc_html( html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) . '</p>';
			}
		}

		if ( $button && ( 'overlay' !== $style || 'yes' !== self::val( $s, 'show_image' ) ) ) {
			echo '<div class="wea-product-grid__footer">' . wp_kses( $button, $allowed ) . '</div>';
		}

		echo '</div></article>';
	}

	/**
	 * Print the list items of one page.
	 *
	 * @param \WP_Query $query Query.
	 * @param array     $s     Settings.
	 * @param int       $page  Page.
	 */
	private function render_items( $query, $s, $page ) {
		global $product;

		$style    = in_array( self::val( $s, 'card_style', 'classic' ), [ 'classic', 'overlay', 'minimal', 'bordered' ], true ) ? $s['card_style'] : 'classic';
		$previous = $product;
		$index    = 0;

		while ( $query->have_posts() ) {
			$query->the_post();
			$item = wc_get_product( get_the_ID() );
			if ( ! $item || ! $item->is_visible() ) {
				continue;
			}
			$product = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WooCommerce's loop button reads the global.
			echo '<li class="wea-product-grid__item">';
			$this->render_card( $item, $s, $style, 1 === $page && $index < 4 );
			echo '</li>';
			++$index;
		}

		$product = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Query string key holding this grid's page number.
	 *
	 * @return string
	 */
	private function page_var() {
		return self::PAGE_VAR . $this->get_id();
	}

	/**
	 * Current page from the URL.
	 *
	 * @return int
	 */
	private function current_page() {
		$key = $this->page_var();
		// Read-only: picks which page of public products to show.
		return isset( $_GET[ $key ] ) ? max( 1, absint( wp_unslash( $_GET[ $key ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * URL of a page of this grid on the current request.
	 *
	 * @param int $page Page.
	 * @return string
	 */
	private function page_url( $page ) {
		$key = $this->page_var();
		$url = remove_query_arg( $key );
		return $page > 1 ? add_query_arg( $key, $page, $url ) : $url;
	}

	/**
	 * ID of the Elementor document that holds this element.
	 *
	 * @return int
	 */
	private function document_id() {
		$document = Plugin::$instance->documents->get_current();
		$id       = $document ? (int) $document->get_main_id() : 0;
		return $id ? $id : (int) get_the_ID();
	}

	/**
	 * Render the products of one page for a "Load more" request. Settings come from the saved element.
	 *
	 * @param int $page Page (2 or more).
	 * @return array{html:string,page:int,pages:int,count:int}
	 */
	public function render_page( $page ) {
		$s     = $this->get_settings_for_display();
		$pages = 1;
		$html  = '';
		$count = 0;

		if ( 'none' !== self::val( $s, 'pagination', 'none' ) ) {
			$query = $this->run_query( $s, $page );
			$pages = self::total_pages( $s, $query );
			if ( $page <= $pages ) {
				ob_start();
				$this->render_items( $query, $s, $page );
				$html  = ob_get_clean();
				$count = substr_count( $html, 'class="wea-product-grid__item"' );
			} else {
				wp_reset_postdata();
			}
		}

		return [
			'html'  => $html,
			'page'  => $page,
			'pages' => $pages,
			'count' => $count,
		];
	}

	/**
	 * Page number links.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Total pages.
	 */
	private function render_numbers( $page, $pages ) {
		$key   = $this->page_var();
		$base  = add_query_arg( $key, '%#%', remove_query_arg( $key ) );
		$links = paginate_links(
			[
				'base'      => str_replace( '%25%23%25', '%#%', esc_url_raw( $base ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'mid_size'  => 1,
				'type'      => 'list',
				'prev_text' => '<span aria-hidden="true">&lsaquo;</span><span class="wea-sr-only">' . esc_html__( 'Previous page', 'wise-addons-for-elementor-pro' ) . '</span>',
				'next_text' => '<span class="wea-sr-only">' . esc_html__( 'Next page', 'wise-addons-for-elementor-pro' ) . '</span><span aria-hidden="true">&rsaquo;</span>',
			]
		);
		if ( ! $links ) {
			return;
		}
		echo '<nav class="wea-product-grid__pagination" aria-label="' . esc_attr__( 'Products pages', 'wise-addons-for-elementor-pro' ) . '">';
		echo wp_kses( $links, Kses::allowed_html() );
		echo '</nav>';
	}

	/**
	 * Load more button. A plain link to the next page, so it works without JavaScript too.
	 *
	 * @param array $s     Settings.
	 * @param int   $page  Current page.
	 * @param int   $pages Total pages.
	 */
	private function render_load_more( $s, $page, $pages ) {
		if ( $page >= $pages ) {
			return;
		}
		$text   = trim( (string) self::val( $s, 'load_more_text' ) );
		$text   = '' !== $text ? $text : __( 'Load more', 'wise-addons-for-elementor-pro' );
		$config = [
			'ajax'      => Product_Grid_Load_More::available() ? admin_url( 'admin-ajax.php' ) : '',
			'action'    => Product_Grid_Load_More::ACTION,
			'nonce'     => wp_create_nonce( Product_Grid_Load_More::ACTION ),
			'postId'    => $this->document_id(),
			'elementId' => $this->get_id(),
			'page'      => $page,
			'pages'     => $pages,
			'text'      => $text,
			'loading'   => (string) self::val( $s, 'load_more_loading_text', __( 'Loading…', 'wise-addons-for-elementor-pro' ) ),
			/* translators: %d: number of products added to the grid. */
			'loaded'    => __( '%d more products loaded.', 'wise-addons-for-elementor-pro' ),
			'done'      => __( 'All products are shown.', 'wise-addons-for-elementor-pro' ),
			'failed'    => __( 'Could not load more products. Please try again.', 'wise-addons-for-elementor-pro' ),
		];

		printf(
			'<div class="wea-product-grid__load-more"><a class="wea-product-grid__more wea-button" href="%1$s" data-wea-load-more="%2$s">%3$s</a></div>',
			esc_url( $this->page_url( $page + 1 ) ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html( $text )
		);
	}

	/**
	 * Render.
	 */
	protected function render() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$s          = $this->get_settings_for_display();
		$pagination = in_array( self::val( $s, 'pagination', 'none' ), [ 'numbers', 'load_more' ], true ) ? $s['pagination'] : 'none';
		$page       = 'none' === $pagination ? 1 : $this->current_page();
		$query      = $this->run_query( $s, $page );
		$style      = in_array( self::val( $s, 'card_style', 'classic' ), [ 'classic', 'overlay', 'minimal', 'bordered' ], true ) ? $s['card_style'] : 'classic';
		$edit_mode  = Plugin::$instance->editor->is_edit_mode() || Plugin::$instance->preview->is_preview_mode();

		echo '<div class="' . esc_attr( 'wea-product-grid wea-product-grid--' . $style ) . '">';

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			$counts = wp_count_posts( 'product' );
			if ( $edit_mode && ( ! $counts || empty( $counts->publish ) ) ) {
				echo '<div class="wea-product-grid__notice" role="note">';
				esc_html_e( 'Your store has no published products yet. Add products in WooCommerce > Products and they will show up here.', 'wise-addons-for-elementor-pro' );
				echo '</div>';
			} else {
				$message = trim( (string) self::val( $s, 'no_products_message' ) );
				if ( '' !== $message ) {
					echo '<p class="wea-product-grid__empty">' . esc_html( $message ) . '</p>';
				}
			}
			echo '</div>';
			return;
		}

		$pages = 'none' === $pagination ? 1 : self::total_pages( $s, $query );

		echo '<ul class="wea-product-grid__items" role="list">';
		$this->render_items( $query, $s, $page );
		echo '</ul>';

		if ( 'numbers' === $pagination && $pages > 1 ) {
			$this->render_numbers( $page, $pages );
		} elseif ( 'load_more' === $pagination ) {
			$this->render_load_more( $s, $page, $pages );
			echo '<p class="wea-product-grid__status wea-sr-only" role="status" aria-live="polite"></p>';
		}

		echo '</div>';
	}
}
