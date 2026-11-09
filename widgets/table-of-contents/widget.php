<?php
/**
 * Table of Contents widget.
 *
 * PHP prints the box: a <nav> landmark, the header with an optional collapse button and an
 * empty body. script.js finds the headings in the chosen area, gives headings without an id
 * a unique slug id (existing ids are kept), builds the nested or flat list, and handles
 * smooth scrolling with an offset, focus, scroll spy and the collapse button. In the editor
 * the list is rebuilt whenever the page content changes.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use WiseAddons\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Table of Contents.
 */
class Table_Of_Contents extends Widget_Base {

	const SLUG = 'table-of-contents';

	/**
	 * Heading levels the widget can collect.
	 */
	const LEVELS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_source_controls();
		$this->register_behavior_controls();
		$this->register_box_style();
		$this->register_header_style();
		$this->register_list_style();
		$this->register_marker_style();
		$this->register_toggle_style();
	}

	/**
	 * Content: title, levels, layout.
	 */
	protected function register_content_controls() {
		$this->start_controls_section(
			'section_toc',
			[
				'label' => __( 'Table of Contents', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'title',
			[
				'label'       => __( 'Title', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Table of Contents', 'wise-addons-for-elementor-pro' ),
				'label_block' => true,
				'dynamic'     => [ 'active' => true ],
			]
		);

		$this->add_control(
			'title_tag',
			[
				'label'   => __( 'Title HTML Tag', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::heading_tag_options(),
				'default' => 'div',
			]
		);

		$this->add_control(
			'headings',
			[
				'label'       => __( 'Include Headings', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array_combine( self::LEVELS, array_map( 'strtoupper', self::LEVELS ) ),
				'default'     => [ 'h2', 'h3', 'h4' ],
				'separator'   => 'before',
			]
		);

		$this->add_control(
			'hierarchy',
			[
				'label'   => __( 'Hierarchy', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'nested' => __( 'Nested', 'wise-addons-for-elementor-pro' ),
					'flat'   => __( 'Flat', 'wise-addons-for-elementor-pro' ),
				],
				'default' => 'nested',
			]
		);

		$this->add_control(
			'marker',
			[
				'label'   => __( 'Markers', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'none'    => __( 'None', 'wise-addons-for-elementor-pro' ),
					'numbers' => __( 'Numbers', 'wise-addons-for-elementor-pro' ),
					'nested'  => __( 'Nested Numbers (1.1)', 'wise-addons-for-elementor-pro' ),
					'bullets' => __( 'Bullets', 'wise-addons-for-elementor-pro' ),
					'icon'    => __( 'Icon', 'wise-addons-for-elementor-pro' ),
				],
				'default' => 'numbers',
			]
		);

		$this->add_control(
			'marker_icon',
			[
				'label'     => __( 'Marker Icon', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::ICONS,
				'skin'      => 'inline',
				'default'   => [
					'value'   => 'fas fa-angle-right',
					'library' => 'fa-solid',
				],
				'condition' => [ 'marker' => 'icon' ],
			]
		);

		$this->add_control(
			'no_headings_text',
			[
				'label'       => __( 'No Headings Message', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No headings were found on this page.', 'wise-addons-for-elementor-pro' ),
				'label_block' => true,
				'description' => __( 'Shown only in the editor. On the live site the widget hides itself when there is nothing to list.', 'wise-addons-for-elementor-pro' ),
				'separator'   => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: where headings come from.
	 */
	protected function register_source_controls() {
		$this->start_controls_section(
			'section_source',
			[
				'label' => __( 'Headings Source', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'scope',
			[
				'label'   => __( 'Look For Headings In', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'content' => __( 'Post Content', 'wise-addons-for-elementor-pro' ),
					'page'    => __( 'Whole Page', 'wise-addons-for-elementor-pro' ),
					'custom'  => __( 'Custom CSS Selector', 'wise-addons-for-elementor-pro' ),
				],
				'default' => 'content',
			]
		);

		$this->add_control(
			'scope_selector',
			[
				'label'       => __( 'Container Selector', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '#article, .post-body',
				'label_block' => true,
				'ai'          => [ 'active' => false ],
				'condition'   => [ 'scope' => 'custom' ],
			]
		);

		$this->add_control(
			'exclude_selector',
			[
				'label'       => __( 'Exclude', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '.no-toc, .sidebar',
				'label_block' => true,
				'ai'          => [ 'active' => false ],
				'description' => __( 'CSS selectors, comma separated. Headings that match, or sit inside an element that matches, are left out. The class wea-toc-exclude always works.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: collapse, scrolling, size.
	 */
	protected function register_behavior_controls() {
		$this->start_controls_section(
			'section_behavior',
			[
				'label' => __( 'Behavior', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'collapsible',
			[
				'label'        => __( 'Collapsible', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'collapsed_on',
			[
				'label'       => __( 'Collapsed By Default On', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::device_options(),
				'default'     => [ 'mobile' ],
				'condition'   => [ 'collapsible' => 'yes' ],
			]
		);

		$this->add_control(
			'toggle_icon',
			[
				'label'       => __( 'Toggle Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'default'     => [
					'value'   => 'fas fa-chevron-down',
					'library' => 'fa-solid',
				],
				'description' => __( 'Turns upside down while the list is open.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'collapsible' => 'yes' ],
			]
		);

		$this->add_control(
			'smooth_scroll',
			[
				'label'        => __( 'Smooth Scroll', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			]
		);

		$this->add_responsive_control(
			'scroll_offset',
			[
				'label'       => __( 'Scroll Offset', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px' ],
				'range'       => [
					'px' => [
						'min' => 0,
						'max' => 300,
					],
				],
				'description' => __( 'Space left above the heading, for example the height of a sticky header. The WordPress toolbar is added automatically.', 'wise-addons-for-elementor-pro' ),
				'selectors'   => [
					'{{WRAPPER}}' => '--wea-toc-offset: {{SIZE}};',
				],
			]
		);

		$this->add_control(
			'scroll_spy',
			[
				'label'        => __( 'Highlight Current Heading', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_responsive_control(
			'max_height',
			[
				'label'      => __( 'List Max Height', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'vh', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 80,
						'max' => 1000,
					],
					'vh' => [
						'min' => 10,
						'max' => 100,
					],
				],
				'separator'  => 'before',
				'selectors'  => [
					'{{WRAPPER}} .wea-table-of-contents__body' => 'max-height: {{SIZE}}{{UNIT}}; overflow-y: auto;',
				],
			]
		);

		$this->add_control(
			'item_ellipsis',
			[
				'label'        => __( 'One Line Per Item', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Long headings are cut with an ellipsis.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the whole box.
	 */
	protected function register_box_style() {
		$root = '{{WRAPPER}} .wea-table-of-contents';

		$this->start_controls_section(
			'section_style_box',
			[
				'label' => __( 'Box', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => 'box_background',
				'types'    => [ 'classic', 'gradient' ],
				'exclude'  => [ 'image' ],
				'selector' => $root,
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'box_border',
				'selector' => $root,
			]
		);

		$this->add_responsive_control(
			'box_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'custom' ],
				'selectors'  => [
					$root => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'box_shadow',
				'selector' => $root,
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: header and title.
	 */
	protected function register_header_style() {
		$header = '{{WRAPPER}} .wea-table-of-contents__header';
		$title  = '{{WRAPPER}} .wea-table-of-contents__title';

		$this->start_controls_section(
			'section_style_header',
			[
				'label' => __( 'Header', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_PRIMARY ],
				'selector' => $title,
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => __( 'Title Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [ 'default' => Global_Colors::COLOR_SECONDARY ],
				'selectors' => [
					$title => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'header_background',
			[
				'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$header => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'header_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'selectors'  => [
					$header => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'separator_width',
			[
				'label'      => __( 'Separator Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 10,
					],
				],
				'separator'  => 'before',
				'selectors'  => [
					'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-sep-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'separator_color',
			[
				'label'     => __( 'Separator Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-sep-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: list and items.
	 */
	protected function register_list_style() {
		$link = '{{WRAPPER}} .wea-table-of-contents__link';

		$this->start_controls_section(
			'section_style_list',
			[
				'label' => __( 'List', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'item_typography',
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_TEXT ],
				'selector' => $link,
			]
		);

		$this->add_responsive_control(
			'list_padding',
			[
				'label'      => __( 'List Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'selectors'  => [
					'{{WRAPPER}} .wea-table-of-contents__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_gap',
			[
				'label'      => __( 'Space Between', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'indent',
			[
				'label'      => __( 'Nested Indent', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 80,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-indent: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [ 'hierarchy' => 'nested' ],
			]
		);

		$this->add_responsive_control(
			'item_padding',
			[
				'label'      => __( 'Item Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'selectors'  => [
					$link => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_radius',
			[
				'label'      => __( 'Item Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 30,
					],
				],
				'selectors'  => [
					$link => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->start_controls_tabs( 'item_state_tabs' );

		foreach ( [
			'normal' => [ __( 'Normal', 'wise-addons-for-elementor-pro' ), $link ],
			'hover'  => [ __( 'Hover', 'wise-addons-for-elementor-pro' ), $link . ':hover, ' . $link . ':focus-visible' ],
			'active' => [ __( 'Active', 'wise-addons-for-elementor-pro' ), '{{WRAPPER}} .wea-table-of-contents__item.is-active > .wea-table-of-contents__link' ],
		] as $state => $info ) {
			$this->start_controls_tab( 'item_state_' . $state, [ 'label' => $info[0] ] );

			$color = [
				'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$info[1] => 'color: {{VALUE}};',
				],
			];
			if ( 'normal' === $state ) {
				$color['global'] = [ 'default' => Global_Colors::COLOR_TEXT ];
			} else {
				$color['global'] = [ 'default' => Global_Colors::COLOR_ACCENT ];
			}
			$this->add_control( 'item_color_' . $state, $color );

			$this->add_control(
				'item_bg_' . $state,
				[
					'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => [
						$info[1] => 'background-color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'item_decoration_' . $state,
				[
					'label'     => __( 'Underline', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => [
						''          => __( 'Default', 'wise-addons-for-elementor-pro' ),
						'none'      => __( 'None', 'wise-addons-for-elementor-pro' ),
						'underline' => __( 'Underline', 'wise-addons-for-elementor-pro' ),
					],
					'default'   => '',
					'selectors' => [
						$info[1] => 'text-decoration: {{VALUE}};',
					],
				]
			);

			if ( 'active' === $state ) {
				$this->add_control(
					'item_weight_active',
					[
						'label'     => __( 'Font Weight', 'wise-addons-for-elementor-pro' ),
						'type'      => Controls_Manager::SELECT,
						'options'   => [
							''    => __( 'Default', 'wise-addons-for-elementor-pro' ),
							'400' => '400',
							'500' => '500',
							'600' => '600',
							'700' => '700',
						],
						'default'   => '',
						'selectors' => [
							$info[1] => 'font-weight: {{VALUE}};',
						],
					]
				);

				$this->add_control(
					'indicator_color',
					[
						'label'     => __( 'Indicator Color', 'wise-addons-for-elementor-pro' ),
						'type'      => Controls_Manager::COLOR,
						'selectors' => [
							'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-indicator: {{VALUE}};',
						],
					]
				);

				$this->add_control(
					'indicator_width',
					[
						'label'      => __( 'Indicator Width', 'wise-addons-for-elementor-pro' ),
						'type'       => Controls_Manager::SLIDER,
						'size_units' => [ 'px' ],
						'range'      => [
							'px' => [
								'min' => 0,
								'max' => 8,
							],
						],
						'selectors'  => [
							'{{WRAPPER}} .wea-table-of-contents' => '--wea-toc-indicator-width: {{SIZE}}{{UNIT}};',
						],
					]
				);
			}

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style: markers.
	 */
	protected function register_marker_style() {
		$root = '{{WRAPPER}} .wea-table-of-contents';

		$this->start_controls_section(
			'section_style_marker',
			[
				'label'     => __( 'Markers', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'marker!' => 'none' ],
			]
		);

		$this->add_control(
			'marker_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$root => '--wea-toc-marker-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'marker_color_active',
			[
				'label'     => __( 'Active Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$root => '--wea-toc-marker-active: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'marker_size',
			[
				'label'      => __( 'Size', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 4,
						'max' => 40,
					],
				],
				'selectors'  => [
					$root => '--wea-toc-marker-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'marker_gap',
			[
				'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 30,
					],
				],
				'selectors'  => [
					$root => '--wea-toc-marker-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: collapse button.
	 */
	protected function register_toggle_style() {
		$toggle = '{{WRAPPER}} .wea-table-of-contents__toggle';

		$this->start_controls_section(
			'section_style_toggle',
			[
				'label'     => __( 'Toggle Button', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'collapsible' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'toggle_icon_size',
			[
				'label'      => __( 'Icon Size', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 8,
						'max' => 40,
					],
				],
				'selectors'  => [
					$toggle => '--wea-toc-toggle-icon: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'toggle_size',
			[
				'label'      => __( 'Button Size', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 20,
						'max' => 64,
					],
				],
				'selectors'  => [
					$toggle => '--wea-toc-toggle-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'toggle_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'custom' ],
				'selectors'  => [
					$toggle => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->start_controls_tabs( 'toggle_state_tabs' );

		foreach ( [
			'normal' => [ __( 'Normal', 'wise-addons-for-elementor-pro' ), $toggle ],
			'hover'  => [ __( 'Hover', 'wise-addons-for-elementor-pro' ), $toggle . ':hover, ' . $toggle . ':focus-visible' ],
		] as $state => $info ) {
			$this->start_controls_tab( 'toggle_state_' . $state, [ 'label' => $info[0] ] );

			$this->add_control(
				'toggle_color_' . $state,
				[
					'label'     => __( 'Icon Color', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => [
						$info[1] => 'color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'toggle_bg_' . $state,
				[
					'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => [
						$info[1] => 'background-color: {{VALUE}};',
					],
				]
			);

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Devices for "Collapsed By Default On": desktop plus Elementor's active breakpoints.
	 *
	 * @return array
	 */
	private static function device_options() {
		$options = [ 'desktop' => __( 'Desktop', 'wise-addons-for-elementor-pro' ) ];

		$manager = isset( Plugin::$instance->breakpoints ) ? Plugin::$instance->breakpoints : null;
		if ( $manager && method_exists( $manager, 'get_active_breakpoints' ) ) {
			foreach ( $manager->get_active_breakpoints() as $name => $breakpoint ) {
				$options[ $name ] = method_exists( $breakpoint, 'get_label' ) ? $breakpoint->get_label() : ucfirst( $name );
			}
		} else {
			$options['tablet'] = __( 'Tablet', 'wise-addons-for-elementor-pro' );
			$options['mobile'] = __( 'Mobile', 'wise-addons-for-elementor-pro' );
		}

		return $options;
	}

	/**
	 * Inside the Elementor editor (preview or widget re-render)?
	 *
	 * @return bool
	 */
	private static function is_editor() {
		$elementor = Plugin::$instance;
		return ( $elementor->editor && $elementor->editor->is_edit_mode() ) || ( $elementor->preview && $elementor->preview->is_preview_mode() );
	}

	/**
	 * Selectors to try, in order, for the area that holds the headings.
	 *
	 * @param array $s Settings.
	 * @return string[]
	 */
	private function root_selectors( array $s ) {
		$scope = isset( $s['scope'] ) ? $s['scope'] : 'content';

		if ( 'page' === $scope ) {
			return [ 'body' ];
		}

		if ( 'custom' === $scope ) {
			$custom = isset( $s['scope_selector'] ) ? trim( wp_strip_all_tags( (string) $s['scope_selector'] ) ) : '';
			return '' !== $custom ? [ $custom ] : [ 'body' ];
		}

		// The post being shown: the queried post on the frontend, the edited document in the editor.
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( ! $post_id ) {
			$document = Plugin::$instance->documents->get_current();
			$post_id  = $document ? (int) $document->get_main_id() : 0;
		}

		$selectors = [];
		if ( $post_id ) {
			$selectors[] = '.elementor-' . $post_id . '[data-elementor-type]';
		}

		return array_merge(
			$selectors,
			[
				'[data-elementor-type="wp-post"]',
				'[data-elementor-type="wp-page"]',
				'[data-elementor-type="single-post"]',
				'[data-elementor-type="single-page"]',
				'[data-elementor-type="single"]',
				'.entry-content',
				'article',
				'main',
				'body',
			]
		);
	}

	/**
	 * Render.
	 */
	protected function render() {
		$s = $this->get_settings_for_display();

		$uid         = 'wea-toc-' . $this->get_id();
		$title       = isset( $s['title'] ) ? trim( (string) $s['title'] ) : '';
		$title_tag   = $this->safe_tag( isset( $s['title_tag'] ) ? $s['title_tag'] : 'div', 'div' );
		$levels      = array_values( array_intersect( self::LEVELS, isset( $s['headings'] ) ? (array) $s['headings'] : [] ) );
		$levels      = $levels ? $levels : [ 'h2', 'h3', 'h4' ];
		$hierarchy   = isset( $s['hierarchy'] ) && 'flat' === $s['hierarchy'] ? 'flat' : 'nested';
		$marker      = isset( $s['marker'] ) && in_array( $s['marker'], [ 'none', 'numbers', 'nested', 'bullets', 'icon' ], true ) ? $s['marker'] : 'numbers';
		$collapsible = ! empty( $s['collapsible'] ) && 'yes' === $s['collapsible'];
		$collapsed   = $collapsible && isset( $s['collapsed_on'] ) ? array_values( array_filter( (array) $s['collapsed_on'], 'is_string' ) ) : [];
		$exclude     = isset( $s['exclude_selector'] ) ? trim( wp_strip_all_tags( (string) $s['exclude_selector'] ) ) : '';
		$is_editor   = self::is_editor();

		$icon = 'icon' === $marker && ! empty( $s['marker_icon']['value'] ) ? $s['marker_icon'] : null;
		if ( 'icon' === $marker && ! $icon ) {
			$marker = 'bullets';
		}

		$options = [
			'levels'      => array_map(
				static function ( $tag ) {
					return (int) substr( $tag, 1 );
				},
				$levels
			),
			'scope'       => isset( $s['scope'] ) && in_array( $s['scope'], [ 'content', 'page', 'custom' ], true ) ? $s['scope'] : 'content',
			'root'        => $this->root_selectors( $s ),
			'exclude'     => $exclude,
			'hierarchy'   => $hierarchy,
			'marker'      => $marker,
			'collapsible' => $collapsible,
			'collapsedOn' => $collapsed,
			'smooth'      => ! empty( $s['smooth_scroll'] ) && 'yes' === $s['smooth_scroll'],
			'spy'         => ! empty( $s['scroll_spy'] ) && 'yes' === $s['scroll_spy'],
		];

		$this->add_render_attribute(
			'nav',
			[
				'class'       => [
					'wea-table-of-contents',
					'wea-table-of-contents--' . $hierarchy,
					'wea-table-of-contents--marker-' . $marker,
					$collapsible ? 'wea-table-of-contents--collapsible' : '',
					! empty( $s['item_ellipsis'] ) && 'yes' === $s['item_ellipsis'] ? 'wea-table-of-contents--ellipsis' : '',
				],
				'data-wea-toc' => wp_json_encode( $options ),
			]
		);

		if ( '' !== $title ) {
			$this->add_render_attribute( 'nav', 'aria-labelledby', $uid . '-title' );
		} else {
			$this->add_render_attribute( 'nav', 'aria-label', __( 'Table of contents', 'wise-addons-for-elementor-pro' ) );
		}

		$this->add_render_attribute(
			'title',
			[
				'class' => 'wea-table-of-contents__title',
				'id'    => $uid . '-title',
			]
		);
		$this->add_inline_editing_attributes( 'title', 'none' );

		$toggle_icon = $collapsible && ! empty( $s['toggle_icon']['value'] ) ? $s['toggle_icon'] : null;
		?>
		<nav <?php $this->print_render_attribute_string( 'nav' ); ?>>
			<?php if ( '' !== $title || $collapsible ) : ?>
				<div class="wea-table-of-contents__header">
					<?php if ( '' !== $title ) : ?>
						<<?php echo esc_html( $title_tag ); ?> <?php $this->print_render_attribute_string( 'title' ); ?>><?php echo esc_html( $title ); ?></<?php echo esc_html( $title_tag ); ?>>
					<?php endif; ?>
					<?php if ( $collapsible ) : ?>
						<button type="button" class="wea-table-of-contents__toggle" aria-expanded="true" aria-controls="<?php echo esc_attr( $uid . '-body' ); ?>"
							<?php if ( '' !== $title ) : ?>
								aria-labelledby="<?php echo esc_attr( $uid . '-title' ); ?>"
							<?php else : ?>
								aria-label="<?php esc_attr_e( 'Table of contents', 'wise-addons-for-elementor-pro' ); ?>"
							<?php endif; ?>
						>
							<span class="wea-icon wea-table-of-contents__toggle-icon" aria-hidden="true">
								<?php if ( $toggle_icon ) : ?>
									<?php $this->print_icon( $toggle_icon ); ?>
								<?php else : ?>
									<svg viewBox="0 0 24 24" width="1em" height="1em" focusable="false"><path d="M5.3 8.3a1 1 0 0 1 1.4 0L12 13.6l5.3-5.3a1 1 0 1 1 1.4 1.4l-6 6a1 1 0 0 1-1.4 0l-6-6a1 1 0 0 1 0-1.4Z"/></svg>
								<?php endif; ?>
							</span>
						</button>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="wea-table-of-contents__body" id="<?php echo esc_attr( $uid . '-body' ); ?>">
				<div class="wea-table-of-contents__placeholder" aria-hidden="true"><span></span><span></span><span></span></div>
				<?php if ( $is_editor ) : ?>
					<p class="wea-table-of-contents__empty" hidden><?php echo esc_html( isset( $s['no_headings_text'] ) ? (string) $s['no_headings_text'] : '' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $icon ) : ?>
				<template class="wea-table-of-contents__icon-template"><?php $this->print_icon( $icon ); ?></template>
			<?php endif; ?>
		</nav>
		<?php
	}
}
