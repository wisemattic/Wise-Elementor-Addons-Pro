<?php
/**
 * Post Carousel widget (Pro).
 *
 * Posts or any post type in a Swiper carousel (Elementor's bundled copy) with
 * four card layouts. The query controls and loop come from the free plugin's
 * Post_Query trait.
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

require_once WISEADDONS_PATH . 'includes/traits/trait-post-query.php';

/**
 * Post carousel with Classic, Overlay, Side and Minimal cards.
 */
class Post_Carousel extends Widget_Base {

	use \WiseAddons\Widgets\Post_Query;

	const SLUG = 'post-carousel';

	/**
	 * BEM block class.
	 */
	const BLOCK = 'wea-post-carousel';

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->register_query_section();
		$this->register_layout_section();
		$this->register_elements_section();
		$this->register_carousel_section();

		$this->register_card_style();
		$this->register_image_style();
		$this->register_badge_style();
		$this->register_title_style();
		$this->register_meta_style();
		$this->register_excerpt_style();
		$this->register_button_style();
		$this->register_arrows_style();
		$this->register_pagination_style();
	}

	/* ------------------------------------------------------------------ *
	 * Content tab
	 * ------------------------------------------------------------------ */

	/**
	 * Authors for the Author filter.
	 *
	 * @return array user_id => display name
	 */
	private static function author_options() {
		$users = get_users(
			[
				'capability' => [ 'edit_posts' ],
				'number'     => 100,
				'fields'     => [ 'ID', 'display_name' ],
				'orderby'    => 'display_name',
			]
		);
		$options = [];
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}
		return $options;
	}

	/**
	 * Terms of every public taxonomy except categories and tags, for other post types.
	 *
	 * @return array term_id => "Taxonomy: Term"
	 */
	private static function other_term_options() {
		$options = [];
		$taxes   = get_taxonomies(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);
		foreach ( $taxes as $tax ) {
			if ( in_array( $tax->name, [ 'category', 'post_tag', 'post_format', 'product_visibility', 'product_type', 'product_shipping_class' ], true ) ) {
				continue;
			}
			foreach ( self::term_options( $tax->name ) as $id => $name ) {
				$options[ $id ] = $tax->labels->singular_name . ': ' . $name;
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

		$controls = $this->get_query_controls( [ 'posts_per_page' => 8 ] );

		foreach ( $controls as $id => $args ) {
			if ( 'orderby' === $id ) {
				// Extra filters from Pro sit right before the order controls.
				$this->add_control(
					'post_terms',
					[
						'label'       => __( 'Terms', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => self::other_term_options(),
						'default'     => [],
						'description' => __( 'Show only items in these terms. Terms that do not belong to the chosen source are ignored.', 'wise-addons-for-elementor-pro' ),
						'condition'   => [ 'post_type!' => 'post' ],
					]
				);

				$this->add_control(
					'post_authors',
					[
						'label'       => __( 'Authors', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => self::author_options(),
						'default'     => [],
					]
				);
			}
			$this->add_control( $id, $args );
		}

		$this->end_controls_section();
	}

	/**
	 * Content: card layout.
	 */
	private function register_layout_section() {
		$this->start_controls_section(
			'section_layout',
			[
				'label' => __( 'Layout', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'layout',
			[
				'label'   => __( 'Card Layout', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'classic',
				'options' => [
					'classic' => __( 'Classic (image on top)', 'wise-addons-for-elementor-pro' ),
					'overlay' => __( 'Overlay (text over image)', 'wise-addons-for-elementor-pro' ),
					'side'    => __( 'Side (image at start)', 'wise-addons-for-elementor-pro' ),
					'minimal' => __( 'Minimal (no image)', 'wise-addons-for-elementor-pro' ),
				],
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
				'selectors' => [ '{{WRAPPER}} .wea-post-carousel__body' => 'text-align: {{VALUE}}; --wea-pc-justify: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'overlay_align',
			[
				'label'                => __( 'Content Position', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => [
					'top'    => [
						'title' => __( 'Top', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-top',
					],
					'middle' => [
						'title' => __( 'Middle', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-middle',
					],
					'bottom' => [
						'title' => __( 'Bottom', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-bottom',
					],
				],
				'selectors_dictionary' => [
					'top'    => 'flex-start',
					'middle' => 'center',
					'bottom' => 'flex-end',
				],
				'selectors'            => [ '{{WRAPPER}} .wea-post-carousel--overlay .wea-post-carousel__card' => 'justify-content: {{VALUE}};' ],
				'condition'            => [ 'layout' => 'overlay' ],
			]
		);

		$this->add_control(
			'equal_height',
			[
				'label'        => __( 'Equal Height Cards', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'prefix_class' => 'wea-post-carousel--equal-',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: what each card shows.
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
				'label'     => __( 'Image', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'condition' => [ 'layout!' => 'minimal' ],
			]
		);

		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			[
				'name'      => 'thumbnail',
				'default'   => 'medium_large',
				'exclude'   => [],
				'condition' => [
					'show_image' => 'yes',
					'layout!'    => 'minimal',
				],
			]
		);

		$this->add_responsive_control(
			'image_ratio',
			[
				'label'     => __( 'Image Aspect Ratio', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''     => __( 'Default', 'wise-addons-for-elementor-pro' ),
					'1/1'  => '1:1',
					'4/3'  => '4:3',
					'3/2'  => '3:2',
					'16/9' => '16:9',
					'21/9' => '21:9',
					'3/4'  => '3:4',
					'2/3'  => '2:3',
				],
				'selectors' => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-ratio: {{VALUE}};' ],
				'condition' => [
					'show_image' => 'yes',
					'layout'     => [ 'classic', 'side' ],
				],
			]
		);

		$this->add_control(
			'show_badge',
			[
				'label'     => __( 'Category Badge', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'show_title',
			[
				'label'     => __( 'Title', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

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
			'meta_heading',
			[
				'label'     => __( 'Meta', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$meta = [
			'show_author'       => [ __( 'Author', 'wise-addons-for-elementor-pro' ), 'yes' ],
			'show_date'         => [ __( 'Date', 'wise-addons-for-elementor-pro' ), 'yes' ],
			'show_comments'     => [ __( 'Comments', 'wise-addons-for-elementor-pro' ), '' ],
			'show_reading_time' => [ __( 'Reading Time', 'wise-addons-for-elementor-pro' ), 'yes' ],
		];
		foreach ( $meta as $id => $item ) {
			$this->add_control(
				$id,
				[
					'label'   => $item[0],
					'type'    => Controls_Manager::SWITCHER,
					'default' => $item[1],
				]
			);
		}

		$this->add_control(
			'reading_speed',
			[
				'label'       => __( 'Words per Minute', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 200,
				'min'         => 50,
				'max'         => 1000,
				'description' => __( 'Reading time is the post word count divided by this number.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'show_reading_time' => 'yes' ],
			]
		);

		$this->add_control(
			'meta_icons',
			[
				'label'   => __( 'Meta Icons', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'show_excerpt',
			[
				'label'     => __( 'Excerpt', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'excerpt_length',
			[
				'label'     => __( 'Excerpt Length (words)', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 16,
				'min'       => 1,
				'max'       => 120,
				'condition' => [ 'show_excerpt' => 'yes' ],
			]
		);

		$this->add_control(
			'show_read_more',
			[
				'label'     => __( 'Read More', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'read_more_text',
			[
				'label'     => __( 'Text', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Read more', 'wise-addons-for-elementor-pro' ),
				'dynamic'   => [ 'active' => true ],
				'condition' => [ 'show_read_more' => 'yes' ],
			]
		);

		$this->add_control(
			'read_more_icon',
			[
				'label'       => __( 'Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'default'     => [
					'value'   => 'fas fa-arrow-right',
					'library' => 'fa-solid',
				],
				'condition'   => [ 'show_read_more' => 'yes' ],
			]
		);

		$this->add_control(
			'read_more_style',
			[
				'label'     => __( 'Style', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'link',
				'options'   => [
					'link'   => __( 'Link', 'wise-addons-for-elementor-pro' ),
					'button' => __( 'Button', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [ 'show_read_more' => 'yes' ],
			]
		);

		$this->add_control(
			'no_posts_message',
			[
				'label'       => __( 'Nothing Found Message', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No posts found.', 'wise-addons-for-elementor-pro' ),
				'label_block' => true,
				'separator'   => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: carousel behaviour.
	 */
	private function register_carousel_section() {
		$this->start_controls_section(
			'section_carousel',
			[
				'label' => __( 'Carousel', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_responsive_control(
			'slides_per_view',
			[
				'label'          => __( 'Slides per View', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 6,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
			]
		);

		$this->add_responsive_control(
			'slides_to_scroll',
			[
				'label'   => __( 'Slides to Scroll', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 6,
				'default' => 1,
			]
		);

		$this->add_responsive_control(
			'slide_gap',
			[
				'label'      => __( 'Gap', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 24,
				],
			]
		);

		$this->add_control(
			'centered',
			[
				'label'   => __( 'Center Active Slide', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			]
		);

		$this->add_control(
			'loop',
			[
				'label'   => __( 'Infinite Loop', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'speed',
			[
				'label'   => __( 'Transition Duration (ms)', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 500,
				'min'     => 100,
				'max'     => 5000,
				'step'    => 50,
			]
		);

		$this->add_control(
			'autoplay',
			[
				'label'       => __( 'Autoplay', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
				'separator'   => 'before',
				'description' => __( 'Autoplay never runs for visitors who ask their system for reduced motion.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'autoplay_delay',
			[
				'label'     => __( 'Delay (ms)', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 4000,
				'min'       => 1000,
				'max'       => 20000,
				'step'      => 500,
				'condition' => [ 'autoplay' => 'yes' ],
			]
		);

		$this->add_control(
			'pause_on_hover',
			[
				'label'     => __( 'Pause on Hover', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'condition' => [ 'autoplay' => 'yes' ],
			]
		);

		$this->add_control(
			'pause_on_interaction',
			[
				'label'     => __( 'Stop on Interaction', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'condition' => [ 'autoplay' => 'yes' ],
			]
		);

		$this->add_control(
			'show_arrows',
			[
				'label'     => __( 'Arrows', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'arrow_prev_icon',
			[
				'label'       => __( 'Previous Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'condition'   => [ 'show_arrows' => 'yes' ],
			]
		);

		$this->add_control(
			'arrow_next_icon',
			[
				'label'       => __( 'Next Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'condition'   => [ 'show_arrows' => 'yes' ],
			]
		);

		$this->add_control(
			'arrows_position',
			[
				'label'        => __( 'Arrows Position', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SELECT,
				'default'      => 'outside',
				'options'      => [
					'inside'  => __( 'Inside', 'wise-addons-for-elementor-pro' ),
					'outside' => __( 'Outside', 'wise-addons-for-elementor-pro' ),
				],
				'prefix_class' => 'wea-post-carousel--arrows-',
				'condition'    => [ 'show_arrows' => 'yes' ],
			]
		);

		$this->add_control(
			'pagination',
			[
				'label'     => __( 'Pagination', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'bullets',
				'options'   => [
					'none'        => __( 'None', 'wise-addons-for-elementor-pro' ),
					'bullets'     => __( 'Dots', 'wise-addons-for-elementor-pro' ),
					'fraction'    => __( 'Fraction (1 / 8)', 'wise-addons-for-elementor-pro' ),
					'progressbar' => __( 'Progress Bar', 'wise-addons-for-elementor-pro' ),
				],
				'separator' => 'before',
			]
		);

		$this->add_control(
			'carousel_name',
			[
				'label'       => __( 'Accessible Name', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Latest posts', 'wise-addons-for-elementor-pro' ),
				'description' => __( 'Announced by screen readers for this carousel.', 'wise-addons-for-elementor-pro' ),
				'separator'   => 'before',
			]
		);

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------ *
	 * Style tab
	 * ------------------------------------------------------------------ */

	/**
	 * Normal / Hover color pair helper.
	 *
	 * @param string $prefix   Control ID prefix.
	 * @param array  $normal   Normal controls: id suffix => [ label, selector => property ].
	 * @param array  $hover    Hover controls, same shape.
	 * @param array  $condition Condition for the tabs.
	 */
	private function add_state_tabs( $prefix, array $normal, array $hover, array $condition = [] ) {
		$this->start_controls_tabs( $prefix . '_tabs', $condition ? [ 'condition' => $condition ] : [] );

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
	 * Style: card.
	 */
	private function register_card_style() {
		$card = '{{WRAPPER}} .wea-post-carousel__card';
		$body = '{{WRAPPER}} .wea-post-carousel__body';

		$this->start_controls_section(
			'section_style_card',
			[
				'label' => __( 'Card', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'card_padding',
			[
				'label'      => __( 'Content Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors'  => [ $body => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'overlay_min_height',
			[
				'label'      => __( 'Card Height', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'vh' ],
				'range'      => [ 'px' => [ 'min' => 160, 'max' => 900 ] ],
				'selectors'  => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-overlay-h: {{SIZE}}{{UNIT}};' ],
				'condition'  => [ 'layout' => 'overlay' ],
			]
		);

		$this->add_control(
			'overlay_gradient',
			[
				'label'     => __( 'Overlay Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-shade: {{VALUE}};' ],
				'condition' => [ 'layout' => 'overlay' ],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'card_border',
				'selector'  => $card,
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			'card_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [ $card => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_state_tabs(
			'card',
			[
				'card_background'   => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $card => 'background-color: {{VALUE}};' ],
				],
				'card_shadow'       => [
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
					'selectors' => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-lift: -{{SIZE}}px;' ],
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: image.
	 */
	private function register_image_style() {
		$img = '{{WRAPPER}} .wea-post-carousel__image';

		$this->start_controls_section(
			'section_style_image',
			[
				'label'     => __( 'Image', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_image' => 'yes',
					'layout!'    => 'minimal',
				],
			]
		);

		$this->add_responsive_control(
			'side_image_width',
			[
				'label'      => __( 'Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ '%', 'px' ],
				'range'      => [
					'%'  => [ 'min' => 15, 'max' => 70 ],
					'px' => [ 'min' => 60, 'max' => 500 ],
				],
				'selectors'  => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-side-w: {{SIZE}}{{UNIT}};' ],
				'condition'  => [ 'layout' => 'side' ],
			]
		);

		$this->add_responsive_control(
			'image_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [ '{{WRAPPER}} .wea-post-carousel__media' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
				'condition'  => [ 'layout!' => 'overlay' ],
			]
		);

		$this->add_responsive_control(
			'image_spacing',
			[
				'label'      => __( 'Inner Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'max' => 40 ] ],
				'description' => __( 'Space between the image and the card edge.', 'wise-addons-for-elementor-pro' ),
				'selectors'  => [ '{{WRAPPER}} .wea-post-carousel__media' => 'margin: {{SIZE}}{{UNIT}};' ],
				'condition'  => [ 'layout!' => 'overlay' ],
			]
		);

		$this->add_control(
			'image_zoom',
			[
				'label'        => __( 'Zoom on Hover', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'prefix_class' => 'wea-post-carousel--zoom-',
			]
		);

		$this->add_state_tabs(
			'image_filter',
			[
				'image_filters' => [
					'group'    => Group_Control_Css_Filter::get_type(),
					'selector' => $img,
				],
			],
			[
				'image_filters_hover' => [
					'group'    => Group_Control_Css_Filter::get_type(),
					'selector' => '{{WRAPPER}} .wea-post-carousel__card:hover .wea-post-carousel__image',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: category badge.
	 */
	private function register_badge_style() {
		$badge = '{{WRAPPER}} .wea-post-carousel__badge';

		$this->start_controls_section(
			'section_style_badge',
			[
				'label'     => __( 'Badge', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_badge' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'badge_typography',
				'selector' => $badge,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_ACCENT ],
			]
		);

		$this->add_state_tabs(
			'badge',
			[
				'badge_color'      => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $badge . ', ' . $badge . ' a' => 'color: {{VALUE}};' ],
				],
				'badge_background' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'global'    => [ 'default' => Global_Colors::COLOR_ACCENT ],
					'selectors' => [ $badge => 'background-color: {{VALUE}};' ],
				],
			],
			[
				'badge_color_hover'      => [
					'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $badge . ':hover, ' . $badge . ':focus-within, ' . $badge . ':hover a' => 'color: {{VALUE}};' ],
				],
				'badge_background_hover' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $badge . ':hover, ' . $badge . ':focus-within' => 'background-color: {{VALUE}};' ],
				],
			]
		);

		$this->add_responsive_control(
			'badge_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'separator'  => 'before',
				'selectors'  => [ $badge => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'badge_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [ $badge => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'badge_offset',
			[
				'label'       => __( 'Offset', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px' ],
				'range'       => [ 'px' => [ 'max' => 60 ] ],
				'description' => __( 'Distance from the image corner.', 'wise-addons-for-elementor-pro' ),
				'selectors'   => [ '{{WRAPPER}} .wea-post-carousel' => '--wea-pc-badge-offset: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: title.
	 */
	private function register_title_style() {
		$title = '{{WRAPPER}} .wea-post-carousel__title';

		$this->start_controls_section(
			'section_style_title',
			[
				'label'     => __( 'Title', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_title' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'selector' => $title,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_PRIMARY ],
			]
		);

		$this->add_state_tabs(
			'title',
			[
				'title_color' => [
					'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $title . ', ' . $title . ' a' => 'color: {{VALUE}};' ],
				],
			],
			[
				'title_color_hover' => [
					'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $title . ' a:hover, ' . $title . ' a:focus-visible' => 'color: {{VALUE}};' ],
				],
			]
		);

		$this->add_responsive_control(
			'title_spacing',
			[
				'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 60 ] ],
				'separator'  => 'before',
				'selectors'  => [ $title => 'margin-block-end: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: meta.
	 */
	private function register_meta_style() {
		$meta = '{{WRAPPER}} .wea-post-carousel__meta';

		$this->start_controls_section(
			'section_style_meta',
			[
				'label' => __( 'Meta', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'meta_typography',
				'selector' => $meta,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_TEXT ],
			]
		);

		$this->add_control(
			'meta_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $meta . ', ' . $meta . ' a' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'meta_link_hover_color',
			[
				'label'     => __( 'Link Hover Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $meta . ' a:hover, ' . $meta . ' a:focus-visible' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'meta_icon_color',
			[
				'label'     => __( 'Icon Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $meta . ' .wea-post-carousel__meta-icon' => 'color: {{VALUE}};' ],
				'condition' => [ 'meta_icons' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'meta_gap',
			[
				'label'      => __( 'Gap Between Items', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 40 ] ],
				'selectors'  => [ $meta => 'column-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'meta_spacing',
			[
				'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 60 ] ],
				'selectors'  => [ $meta => 'margin-block-end: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: excerpt.
	 */
	private function register_excerpt_style() {
		$excerpt = '{{WRAPPER}} .wea-post-carousel__excerpt';

		$this->start_controls_section(
			'section_style_excerpt',
			[
				'label'     => __( 'Excerpt', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_excerpt' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'excerpt_typography',
				'selector' => $excerpt,
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_TEXT ],
			]
		);

		$this->add_control(
			'excerpt_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $excerpt => 'color: {{VALUE}};' ],
			]
		);

		$this->add_responsive_control(
			'excerpt_spacing',
			[
				'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 60 ] ],
				'selectors'  => [ $excerpt => 'margin-block-end: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: read more button.
	 */
	private function register_button_style() {
		$btn = '{{WRAPPER}} .wea-post-carousel__read-more';

		$this->start_controls_section(
			'section_style_button',
			[
				'label'     => __( 'Read More', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_read_more' => 'yes' ],
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

		$this->add_responsive_control(
			'button_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [ $btn => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'button_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [ $btn => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'button_spacing',
			[
				'label'      => __( 'Spacing Above', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [ 'px' => [ 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .wea-post-carousel__footer' => 'padding-block-start: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: arrows.
	 */
	private function register_arrows_style() {
		$root  = '{{WRAPPER}} .wea-post-carousel';
		$arrow = $root . '__arrow';

		$this->start_controls_section(
			'section_style_arrows',
			[
				'label'     => __( 'Arrows', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_arrows' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'arrow_size',
			[
				'label'     => __( 'Button Size', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 20, 'max' => 90 ] ],
				'selectors' => [ $root => '--wea-pc-arrow: {{SIZE}}px;' ],
			]
		);

		$this->add_responsive_control(
			'arrow_icon_size',
			[
				'label'     => __( 'Icon Size', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 8, 'max' => 60 ] ],
				'selectors' => [ $arrow => 'font-size: {{SIZE}}px;' ],
			]
		);

		$this->add_responsive_control(
			'arrow_offset',
			[
				'label'     => __( 'Horizontal Offset', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => -60, 'max' => 80 ] ],
				'selectors' => [ $root => '--wea-pc-arrow-offset: {{SIZE}}px;' ],
			]
		);

		$this->add_responsive_control(
			'arrow_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'max' => 50 ] ],
				'selectors'  => [ $arrow => 'border-radius: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_state_tabs(
			'arrow',
			[
				'arrow_color'      => [
					'label'     => __( 'Icon Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $arrow => 'color: {{VALUE}};' ],
				],
				'arrow_background' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $arrow => 'background-color: {{VALUE}};' ],
				],
			],
			[
				'arrow_color_hover'      => [
					'label'     => __( 'Icon Color', 'wise-addons-for-elementor-pro' ),
					'selectors' => [ $arrow . ':hover, ' . $arrow . ':focus-visible' => 'color: {{VALUE}};' ],
				],
				'arrow_background_hover' => [
					'label'     => __( 'Background', 'wise-addons-for-elementor-pro' ),
					'global'    => [ 'default' => Global_Colors::COLOR_ACCENT ],
					'selectors' => [ $arrow . ':hover, ' . $arrow . ':focus-visible' => 'background-color: {{VALUE}};' ],
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'arrow_border',
				'selector'  => $arrow,
				'separator' => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: pagination.
	 */
	private function register_pagination_style() {
		$root = '{{WRAPPER}} .wea-post-carousel';
		$pag  = $root . '__pagination';

		$this->start_controls_section(
			'section_style_pagination',
			[
				'label'     => __( 'Pagination', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'pagination!' => 'none' ],
			]
		);

		$this->add_responsive_control(
			'pagination_spacing',
			[
				'label'     => __( 'Spacing Above', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'selectors' => [ $root => '--wea-pc-pag-space: {{SIZE}}px;' ],
			]
		);

		$this->add_responsive_control(
			'dots_size',
			[
				'label'     => __( 'Dot Size', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 4, 'max' => 24 ] ],
				'selectors' => [ $root => '--wea-pc-dot: {{SIZE}}px;' ],
				'condition' => [ 'pagination' => 'bullets' ],
			]
		);

		$this->add_responsive_control(
			'dots_gap',
			[
				'label'     => __( 'Dot Gap', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 0, 'max' => 30 ] ],
				'selectors' => [ $pag => 'gap: {{SIZE}}px;' ],
				'condition' => [ 'pagination' => 'bullets' ],
			]
		);

		$this->add_responsive_control(
			'progress_height',
			[
				'label'     => __( 'Bar Height', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 1, 'max' => 16 ] ],
				'selectors' => [ $root => '--wea-pc-progress-h: {{SIZE}}px;' ],
				'condition' => [ 'pagination' => 'progressbar' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'fraction_typography',
				'selector'  => $pag,
				'condition' => [ 'pagination' => 'fraction' ],
			]
		);

		$this->add_control(
			'pagination_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ $root => '--wea-pc-pag-color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'pagination_active_color',
			[
				'label'     => __( 'Active Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [ 'default' => Global_Colors::COLOR_ACCENT ],
				'selectors' => [ $root => '--wea-pc-pag-active: {{VALUE}};' ],
			]
		);

		$this->end_controls_section();
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Effective value of a responsive number setting for every active device.
	 * Smaller devices inherit from the next bigger one when left empty.
	 *
	 * @param array  $s       Settings.
	 * @param string $id      Control ID.
	 * @param int    $default Desktop fallback.
	 * @param int    $min     Lower bound.
	 * @param int    $max     Upper bound.
	 * @param array  $caps    Device => highest value used when that device is left empty (the control's device defaults).
	 * @return array device => int
	 */
	private function per_device( $s, $id, $default, $min, $max, $caps = [] ) {
		$read = function ( $key ) use ( $s ) {
			if ( ! isset( $s[ $key ] ) || '' === $s[ $key ] || null === $s[ $key ] ) {
				return null;
			}
			$value = is_array( $s[ $key ] ) ? ( isset( $s[ $key ]['size'] ) ? $s[ $key ]['size'] : '' ) : $s[ $key ];
			return ( '' === $value || null === $value ) ? null : (int) $value;
		};
		$clamp = function ( $v ) use ( $min, $max ) {
			return max( $min, min( $max, (int) $v ) );
		};

		$desktop = $read( $id );
		$desktop = $clamp( null === $desktop ? $default : $desktop );
		$values  = [ 'desktop' => $desktop ];
		$prev    = $desktop;
		$smaller = false;

		foreach ( Plugin::$instance->breakpoints->get_active_devices_list( [ 'reverse' => true ] ) as $device ) {
			if ( 'desktop' === $device ) {
				$smaller = true;
				continue;
			}
			$value = $read( $id . '_' . $device );
			if ( ! $smaller ) {
				$values[ $device ] = null === $value ? $desktop : $clamp( $value );
				continue;
			}
			if ( null === $value ) {
				$prev = isset( $caps[ $device ] ) ? min( $prev, (int) $caps[ $device ] ) : $prev;
			} else {
				$prev = $clamp( $value );
			}
			$values[ $device ] = $prev;
		}

		return $values;
	}

	/**
	 * WP_Query args: the shared trait plus the Pro author and term filters.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private function query_args( $s ) {
		$args = $this->build_query_args( $s, 8 );

		$authors = ! empty( $s['post_authors'] ) ? array_filter( array_map( 'absint', (array) $s['post_authors'] ) ) : [];
		if ( $authors ) {
			$args['author__in'] = array_values( $authors );
		}

		if ( 'post' !== $args['post_type'] && ! empty( $s['post_terms'] ) ) {
			$by_tax = [];
			foreach ( array_filter( array_map( 'absint', (array) $s['post_terms'] ) ) as $term_id ) {
				$term = get_term( $term_id );
				if ( $term && ! is_wp_error( $term ) && is_object_in_taxonomy( $args['post_type'], $term->taxonomy ) ) {
					$by_tax[ $term->taxonomy ][] = $term_id;
				}
			}
			if ( $by_tax ) {
				$tax_query = [ 'relation' => 'AND' ];
				foreach ( $by_tax as $taxonomy => $ids ) {
					$tax_query[] = [
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $ids,
					];
				}
				$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
		}

		return $args;
	}

	/**
	 * First term of the post's main taxonomy (category for posts, first hierarchical public taxonomy otherwise).
	 *
	 * @return \WP_Term|null
	 */
	private function primary_term() {
		$type     = get_post_type();
		$taxonomy = 'post' === $type ? 'category' : '';
		if ( ! $taxonomy ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
				if ( $tax->hierarchical && $tax->public ) {
					$taxonomy = $tax->name;
					break;
				}
			}
		}
		$terms = $taxonomy ? get_the_terms( get_the_ID(), $taxonomy ) : false;
		return ( $terms && ! is_wp_error( $terms ) ) ? reset( $terms ) : null;
	}

	/**
	 * Reading time in minutes from the post's word count.
	 *
	 * @param int $speed Words per minute.
	 * @return int
	 */
	private function reading_minutes( $speed ) {
		$post = get_post();
		if ( ! $post ) {
			return 0;
		}
		$text  = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$count = is_array( $words ) ? count( $words ) : 0;
		return max( 1, (int) ceil( $count / max( 50, (int) $speed ) ) );
	}

	/**
	 * Small inline SVG for the reading-time meta item (the trait has the others).
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function icon_svg( $name ) {
		if ( 'time' !== $name ) {
			return str_replace( 'wea-post-meta-icon', 'wea-post-meta-icon wea-post-carousel__meta-icon', self::meta_icon( $name ) );
		}
		return '<svg class="wea-post-meta-icon wea-post-carousel__meta-icon" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
	}

	/**
	 * Meta list for the current post.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private function meta_html( $s ) {
		$icons = 'yes' === ( $s['meta_icons'] ?? '' );
		$items = [];

		if ( 'yes' === ( $s['show_author'] ?? '' ) && '' !== (string) get_the_author() ) {
			$items['author'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( get_author_posts_url( (int) get_the_author_meta( 'ID' ) ) ),
				esc_html( get_the_author() )
			);
		}

		if ( 'yes' === ( $s['show_date'] ?? '' ) ) {
			$items['date'] = sprintf(
				'<time datetime="%1$s">%2$s</time>',
				esc_attr( get_the_date( 'c' ) ),
				esc_html( get_the_date() )
			);
		}

		if ( 'yes' === ( $s['show_comments'] ?? '' ) && post_type_supports( get_post_type(), 'comments' ) ) {
			$count             = (int) get_comments_number();
			$items['comments'] = esc_html(
				sprintf(
					/* translators: %s: number of comments. */
					_n( '%s comment', '%s comments', $count, 'wise-addons-for-elementor-pro' ),
					number_format_i18n( $count )
				)
			);
		}

		if ( 'yes' === ( $s['show_reading_time'] ?? '' ) ) {
			$minutes       = $this->reading_minutes( $s['reading_speed'] ?? 200 );
			$items['time'] = esc_html(
				sprintf(
					/* translators: %s: minutes needed to read the post. */
					_n( '%s min read', '%s min read', $minutes, 'wise-addons-for-elementor-pro' ),
					number_format_i18n( $minutes )
				)
			);
		}

		if ( ! $items ) {
			return '';
		}

		$html = '<ul class="wea-post-carousel__meta">';
		foreach ( $items as $key => $item ) {
			$html .= '<li class="wea-post-carousel__meta-item wea-post-carousel__meta-item--' . esc_attr( $key ) . '">';
			$html .= $icons ? self::icon_svg( 'time' === $key ? 'time' : $key ) : '';
			$html .= $item . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Category badge for the current post.
	 *
	 * @return string
	 */
	private function badge_html() {
		$term = $this->primary_term();
		if ( ! $term ) {
			return '';
		}
		$link = get_term_link( $term );
		$name = esc_html( $term->name );
		$text = is_wp_error( $link ) ? $name : '<a href="' . esc_url( $link ) . '">' . $name . '</a>';
		return '<span class="wea-post-carousel__badge">' . $text . '</span>';
	}

	/**
	 * Featured image of the current post. Cards visible on load are not lazy-loaded.
	 *
	 * @param array $s     Settings.
	 * @param bool  $eager Load at once.
	 * @return string
	 */
	private function image_html( $s, $eager ) {
		$id = get_post_thumbnail_id();
		if ( ! $id ) {
			return '';
		}
		$size = ! empty( $s['thumbnail_size'] ) ? $s['thumbnail_size'] : 'medium_large';
		$attr = [
			'class'    => 'wea-post-carousel__image',
			'alt'      => '',
			'loading'  => $eager ? 'eager' : 'lazy',
			'decoding' => 'async',
		];

		if ( 'custom' !== $size ) {
			return wp_get_attachment_image( $id, $size, false, $attr );
		}

		$src = Group_Control_Image_Size::get_attachment_image_src( $id, 'thumbnail', $s );
		if ( ! $src ) {
			return wp_get_attachment_image( $id, 'full', false, $attr );
		}
		return sprintf(
			'<img src="%1$s" class="wea-post-carousel__image" alt="" loading="%2$s" decoding="async" />',
			esc_url( $src ),
			esc_attr( $attr['loading'] )
		);
	}

	/**
	 * Print one card for the current post.
	 *
	 * @param array  $s      Settings.
	 * @param string $layout Card layout.
	 * @param bool   $eager  Card is visible on load.
	 */
	private function render_card( $s, $layout, $eager = false ) {
		$title     = get_the_title();
		$permalink = get_permalink();
		$tag       = $this->safe_tag( $s['title_tag'] ?? 'h3', 'h3' );
		$use_image = 'minimal' !== $layout && 'yes' === ( $s['show_image'] ?? '' );
		$image     = $use_image ? $this->image_html( $s, $eager ) : '';
		$badge     = 'yes' === ( $s['show_badge'] ?? '' ) ? $this->badge_html() : '';
		$classes   = [ 'wea-post-carousel__card' ];

		if ( $image ) {
			$classes[] = 'has-image';
		} elseif ( 'overlay' === $layout ) {
			$classes[] = 'no-image';
		}

		echo '<article class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( $image ) {
			echo '<div class="wea-post-carousel__media">';
			// The title link already names the post, so the image link is hidden from assistive tech and the tab order.
			printf(
				'<a class="wea-post-carousel__thumb" href="%1$s" tabindex="-1" aria-hidden="true">%2$s</a>',
				esc_url( $permalink ),
				wp_kses( $image, Kses::allowed_html() )
			);
			if ( $badge ) {
				echo wp_kses( $badge, Kses::allowed_html() );
			}
			echo '</div>';
		}

		echo '<div class="wea-post-carousel__body">';

		if ( $badge && ! $image ) {
			echo wp_kses( $badge, Kses::allowed_html() );
		}

		if ( 'yes' === ( $s['show_title'] ?? 'yes' ) ) {
			printf(
				'<%1$s class="wea-post-carousel__title"><a href="%2$s">%3$s</a></%1$s>',
				tag_escape( $tag ),
				esc_url( $permalink ),
				esc_html( '' !== trim( $title ) ? $title : __( '(no title)', 'wise-addons-for-elementor-pro' ) )
			);
		}

		echo wp_kses( $this->meta_html( $s ), Kses::allowed_html() );

		if ( 'yes' === ( $s['show_excerpt'] ?? '' ) ) {
			$excerpt = $this->get_post_excerpt_text( $s['excerpt_length'] ?? 16 );
			if ( '' !== $excerpt ) {
				echo '<p class="wea-post-carousel__excerpt">' . esc_html( html_entity_decode( $excerpt, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) . '</p>';
			}
		}

		if ( 'yes' === ( $s['show_read_more'] ?? '' ) ) {
			$is_button = 'button' === ( $s['read_more_style'] ?? 'link' );
			$text      = ! empty( $s['read_more_text'] ) ? $s['read_more_text'] : __( 'Read more', 'wise-addons-for-elementor-pro' );

			echo '<div class="wea-post-carousel__footer">';
			echo '<a class="' . esc_attr( 'wea-post-carousel__read-more' . ( $is_button ? ' wea-button wea-post-carousel__read-more--button' : '' ) ) . '" href="' . esc_url( $permalink ) . '">';
			echo '<span>' . esc_html( $text ) . '</span>';
			/* translators: %s: post title. Completes the "Read more" link text for screen readers. */
			echo '<span class="wea-sr-only"> ' . esc_html( sprintf( __( 'about %s', 'wise-addons-for-elementor-pro' ), wp_strip_all_tags( $title ) ) ) . '</span>';
			if ( ! empty( $s['read_more_icon']['value'] ) ) {
				echo '<span class="wea-icon wea-post-carousel__read-more-icon">';
				$this->print_icon( $s['read_more_icon'] );
				echo '</span>';
			}
			echo '</a></div>';
		}

		echo '</div></article>';
	}

	/**
	 * Arrow icon: the user's icon or a built-in chevron.
	 *
	 * @param array  $s    Settings.
	 * @param string $type prev|next.
	 */
	private function print_arrow( $s, $type ) {
		$icon = $s[ 'arrow_' . $type . '_icon' ] ?? [];
		if ( ! empty( $icon['value'] ) ) {
			$this->print_icon( $icon );
			return;
		}
		$path = 'next' === $type ? 'M9 5l7 7-7 7' : 'M15 5l-7 7 7 7';
		printf(
			'<svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false"><path d="%s" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			esc_attr( $path )
		);
	}

	/**
	 * Render.
	 */
	protected function render() {
		$s      = $this->get_settings_for_display();
		$layout = in_array( $s['layout'] ?? '', [ 'classic', 'overlay', 'side', 'minimal' ], true ) ? $s['layout'] : 'classic';
		$query  = new \WP_Query( $this->query_args( $s ) );

		if ( ! $query->have_posts() ) {
			$message = isset( $s['no_posts_message'] ) ? trim( (string) $s['no_posts_message'] ) : '';
			if ( '' !== $message ) {
				echo '<div class="wea-post-carousel wea-post-carousel--empty"><p class="wea-post-carousel__empty">' . esc_html( $message ) . '</p></div>';
			}
			return;
		}

		$count      = (int) $query->post_count;
		$per_view   = $this->per_device(
			$s,
			'slides_per_view',
			3,
			1,
			6,
			[
				'tablet' => 2,
				'mobile' => 1,
			]
		);
		$per_group  = $this->per_device( $s, 'slides_to_scroll', 1, 1, 6 );
		$gap        = $this->per_device( $s, 'slide_gap', 24, 0, 200 );
		$autoplay   = 'yes' === ( $s['autoplay'] ?? '' );
		$pagination = in_array( $s['pagination'] ?? '', [ 'bullets', 'fraction', 'progressbar' ], true ) ? $s['pagination'] : 'none';
		$arrows     = 'yes' === ( $s['show_arrows'] ?? '' ) && $count > 1;
		$name       = isset( $s['carousel_name'] ) && '' !== trim( (string) $s['carousel_name'] ) ? $s['carousel_name'] : __( 'Posts', 'wise-addons-for-elementor-pro' );

		$this->add_render_attribute(
			'root',
			[
				'class'                  => [ 'wea-post-carousel', 'wea-post-carousel--' . $layout ],
				'role'                   => 'region',
				'aria-roledescription'   => __( 'carousel', 'wise-addons-for-elementor-pro' ),
				'aria-label'             => $name,
				'style'                  => sprintf(
					'--wea-pc-pre-view-desktop:%1$d;--wea-pc-pre-view-tablet:%2$d;--wea-pc-pre-view-mobile:%3$d;--wea-pc-pre-gap:%4$dpx;',
					$per_view['desktop'],
					$per_view['tablet'] ?? $per_view['desktop'],
					$per_view['mobile'] ?? $per_view['desktop'],
					$gap['desktop']
				),
				'data-wea-post-carousel' => wp_json_encode(
					[
						'perView'            => $per_view,
						'perGroup'           => $per_group,
						'gap'                => $gap,
						'loop'               => 'yes' === ( $s['loop'] ?? '' ),
						'speed'              => max( 100, min( 5000, (int) ( $s['speed'] ?? 500 ) ) ),
						'autoplay'           => $autoplay ? max( 1000, (int) ( $s['autoplay_delay'] ?? 4000 ) ) : 0,
						'pauseOnHover'       => $autoplay && 'yes' === ( $s['pause_on_hover'] ?? '' ),
						'pauseOnInteraction' => 'yes' === ( $s['pause_on_interaction'] ?? '' ),
						'centered'           => 'yes' === ( $s['centered'] ?? '' ),
						'pagination'         => $count > 1 ? $pagination : 'none',
					]
				),
			]
		);
		?>
		<div <?php $this->print_render_attribute_string( 'root' ); ?>>
			<div class="wea-post-carousel__stage">
				<div class="swiper wea-post-carousel__swiper">
					<div class="swiper-wrapper">
						<?php
						$index = 0;
						while ( $query->have_posts() ) {
							$query->the_post();
							++$index;
							printf(
								'<div class="swiper-slide wea-post-carousel__slide" role="group" aria-roledescription="%1$s" aria-label="%2$s">',
								esc_attr__( 'slide', 'wise-addons-for-elementor-pro' ),
								/* translators: 1: slide number, 2: total slides. */
								esc_attr( sprintf( __( '%1$d of %2$d', 'wise-addons-for-elementor-pro' ), $index, $count ) )
							);
							$this->render_card( $s, $layout, $index <= $per_view['desktop'] );
							echo '</div>';
						}
						wp_reset_postdata();
						?>
					</div>
				</div>
				<?php if ( $arrows ) : ?>
					<button type="button" class="wea-post-carousel__arrow wea-post-carousel__arrow--prev" aria-label="<?php esc_attr_e( 'Previous slide', 'wise-addons-for-elementor-pro' ); ?>"><?php $this->print_arrow( $s, 'prev' ); ?></button>
					<button type="button" class="wea-post-carousel__arrow wea-post-carousel__arrow--next" aria-label="<?php esc_attr_e( 'Next slide', 'wise-addons-for-elementor-pro' ); ?>"><?php $this->print_arrow( $s, 'next' ); ?></button>
				<?php endif; ?>
			</div>
			<?php if ( 'none' !== $pagination && $count > 1 ) : ?>
				<div class="wea-post-carousel__pagination wea-post-carousel__pagination--<?php echo esc_attr( $pagination ); ?>"></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
