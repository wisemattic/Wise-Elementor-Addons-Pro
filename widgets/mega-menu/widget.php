<?php
/**
 * Mega Menu widget.
 *
 * Prints a WordPress menu with normal multi-level dropdowns and, for chosen
 * top-level items, mega panels built from saved Elementor templates. Below a
 * breakpoint the same markup becomes an off-canvas or dropdown menu whose
 * sub-menus and mega panels open as accordions.
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
use Elementor\Icons_Manager;
use Elementor\Plugin;
use Elementor\Repeater;
use WiseAddons\Kses;
use WiseAddons\Widget_Base;
use WiseAddons\Widgets\Saved_Template;

defined( 'ABSPATH' ) || exit;

require_once WISEADDONS_PATH . 'includes/traits/trait-saved-template.php';
require_once __DIR__ . '/class-walker.php';

/**
 * Mega Menu.
 */
class Mega_Menu extends Widget_Base {

	use Saved_Template;

	const SLUG = 'mega-menu';

	/**
	 * Mega menus nested inside mega panels deeper than this render without panels.
	 */
	const MAX_DEPTH = 2;

	/**
	 * Mega menus being rendered right now (outermost is 1).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Cached "Menu › Item" options for the editor.
	 *
	 * @var array|null
	 */
	private static $item_options = null;

	/**
	 * The current page decides which item is highlighted, so the output is never cached.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Menus as [ term_id => name ].
	 *
	 * @return array
	 */
	protected static function menu_options() {
		$options = [];
		foreach ( (array) wp_get_nav_menus() as $menu ) {
			if ( isset( $menu->term_id ) ) {
				$options[ (string) $menu->term_id ] = $menu->name;
			}
		}
		return $options;
	}

	/**
	 * Top-level items of every menu as [ item_id => "Menu › Item" ]. Only built for the editor panel.
	 *
	 * @return array
	 */
	protected static function menu_item_options() {
		if ( null !== self::$item_options ) {
			return self::$item_options;
		}

		self::$item_options = [];

		if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return self::$item_options;
		}

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
			if ( ! $items ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( 0 !== (int) $item->menu_item_parent ) {
					continue;
				}
				$title = trim( wp_strip_all_tags( (string) $item->title ) );
				if ( '' === $title ) {
					$title = __( '(no title)', 'wise-addons-for-elementor-pro' );
				}
				self::$item_options[ (string) $item->ID ] = $menu->name . ' › ' . $title;
			}
		}

		return self::$item_options;
	}

	/**
	 * A string as a JavaScript literal that is safe inside an Elementor panel template.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function js_string( $value ) {
		$json = (string) wp_json_encode( (string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		// Braces and # would end the <# #> and {{ }} template tags early.
		return str_replace( [ '{', '}', '#' ], [ '{', '}', '#' ], $json );
	}

	/**
	 * Repeater row title: the chosen item's "Menu › Item" label.
	 *
	 * @return string
	 */
	private static function item_title_field() {
		$pairs = [];
		foreach ( self::menu_item_options() as $id => $label ) {
			$pairs[] = self::js_string( $id ) . ':' . self::js_string( $label );
		}
		$fallback = self::js_string( __( 'Choose a menu item', 'wise-addons-for-elementor-pro' ) );

		return '<# var weaMmItems = {' . implode( ',', $pairs ) . '}; #>{{ weaMmItems[ menu_item ] || ' . $fallback . ' }}';
	}

	/**
	 * Breakpoint choices from Elementor's active breakpoints, largest first.
	 *
	 * @return array
	 */
	protected static function breakpoint_options() {
		$options = [];
		$manager = isset( Plugin::$instance->breakpoints ) ? Plugin::$instance->breakpoints : null;

		if ( $manager ) {
			$active = array_reverse( $manager->get_active_breakpoints(), true );
			foreach ( $active as $name => $breakpoint ) {
				if ( 'widescreen' === $name ) {
					continue;
				}
				/* translators: 1: device name, 2: width in pixels. */
				$options[ $name ] = sprintf( __( '%1$s and below (%2$dpx)', 'wise-addons-for-elementor-pro' ), $breakpoint->get_label(), (int) $breakpoint->get_value() );
			}
		}

		if ( ! $options ) {
			$options = [
				'tablet' => __( 'Tablet and below (1024px)', 'wise-addons-for-elementor-pro' ),
				'mobile' => __( 'Mobile and below (767px)', 'wise-addons-for-elementor-pro' ),
			];
		}

		$options['none'] = __( 'None (always the desktop menu)', 'wise-addons-for-elementor-pro' );

		return $options;
	}

	/**
	 * Width in pixels of a breakpoint name. 0 means no mobile menu.
	 *
	 * @param string $name Breakpoint name.
	 * @return int
	 */
	protected function breakpoint_px( $name ) {
		if ( 'none' === $name ) {
			return 0;
		}
		$manager = isset( Plugin::$instance->breakpoints ) ? Plugin::$instance->breakpoints : null;
		if ( $manager ) {
			$active = $manager->get_active_breakpoints();
			if ( isset( $active[ $name ] ) && 'widescreen' !== $name ) {
				return (int) $active[ $name ]->get_value();
			}
		}
		return 'mobile' === $name ? 767 : 1024;
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->register_menu_content();
		$this->register_mega_content();
		$this->register_dropdown_content();
		$this->register_mobile_content();

		$this->register_menu_style();
		$this->register_icons_style();
		$this->register_dropdown_style();
		$this->register_mega_style();
		$this->register_badge_style();
		$this->register_toggle_style();
		$this->register_mobile_style();
	}

	/**
	 * Content: menu and layout.
	 */
	protected function register_menu_content() {
		$root  = '{{WRAPPER}} .wea-mega-menu';
		$menus = self::menu_options();
		$link  = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( admin_url( 'nav-menus.php' ) ),
			esc_html__( 'Manage menus', 'wise-addons-for-elementor-pro' )
		);

		$this->start_controls_section(
			'section_menu',
			[
				'label' => __( 'Menu', 'wise-addons-for-elementor-pro' ),
			]
		);

		if ( ! $menus ) {
			$this->add_control(
				'menu_notice',
				[
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'There are no menus yet. Create one, add your pages and links, then choose it here.', 'wise-addons-for-elementor-pro' ) . ' ' . sprintf(
						'<a href="%s" target="_blank" rel="noopener">%s</a>',
						esc_url( admin_url( 'nav-menus.php?action=edit&menu=0' ) ),
						esc_html__( 'Create a menu', 'wise-addons-for-elementor-pro' )
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				]
			);
		}

		$this->add_control(
			'menu',
			[
				'label'       => __( 'Menu', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $menus,
				'default'     => $menus ? (string) array_key_first( $menus ) : '',
				'description' => $link,
			]
		);

		$this->add_control(
			'layout',
			[
				'label'     => __( 'Layout', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'horizontal',
				'options'   => [
					'horizontal' => __( 'Horizontal', 'wise-addons-for-elementor-pro' ),
					'vertical'   => __( 'Vertical', 'wise-addons-for-elementor-pro' ),
				],
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			'menu_align',
			[
				'label'                => __( 'Alignment', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => [
					'start'   => [
						'title' => __( 'Start', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-start-h',
					],
					'center'  => [
						'title' => __( 'Center', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-center-h',
					],
					'end'     => [
						'title' => __( 'End', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-end-h',
					],
					'stretch' => [
						'title' => __( 'Justified', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-stretch-h',
					],
				],
				'selectors_dictionary' => [
					'start'   => '--wea-mm-justify: flex-start; --wea-mm-grow: 0; --wea-mm-text: flex-start;',
					'center'  => '--wea-mm-justify: center; --wea-mm-grow: 0; --wea-mm-text: center;',
					'end'     => '--wea-mm-justify: flex-end; --wea-mm-grow: 0; --wea-mm-text: flex-end;',
					'stretch' => '--wea-mm-justify: space-between; --wea-mm-grow: 1; --wea-mm-text: center;',
				],
				'selectors'            => [
					$root => '{{VALUE}}',
				],
			]
		);

		$this->add_control(
			'indicator',
			[
				'label'       => __( 'Sub-menu Indicator', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'chevron',
				'options'     => [
					'chevron' => __( 'Chevron', 'wise-addons-for-elementor-pro' ),
					'caret'   => __( 'Caret', 'wise-addons-for-elementor-pro' ),
					'plus'    => __( 'Plus', 'wise-addons-for-elementor-pro' ),
					'none'    => __( 'None', 'wise-addons-for-elementor-pro' ),
				],
				'description' => __( 'With None the toggle button stays reachable by keyboard and shows when focused. The mobile menu always shows it.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'nav_label',
			[
				'label'       => __( 'Accessible Name', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Main', 'wise-addons-for-elementor-pro' ),
				'description' => __( 'Read by screen readers for this navigation. Leave empty to use the menu name.', 'wise-addons-for-elementor-pro' ),
				'ai'          => [ 'active' => false ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: mega panels, icons and badges for top-level items.
	 */
	protected function register_mega_content() {
		$this->start_controls_section(
			'section_mega_items',
			[
				'label' => __( 'Mega Items', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'mega_items_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Pick a top-level item of the chosen menu and a saved template to show as its mega panel. Items without a template keep their normal dropdown and can still get an icon or a badge.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'menu_item',
			[
				'label'       => __( 'Menu Item', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => static::menu_item_options(),
				'default'     => '',
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'template_id',
			[
				'label'       => __( 'Mega Panel Template', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => [ '' => __( 'None (normal dropdown)', 'wise-addons-for-elementor-pro' ) ] + static::saved_template_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Create templates under Templates > Saved Templates.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$repeater->add_control(
			'panel_width',
			[
				'label'     => __( 'Panel Width', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'container',
				'options'   => [
					'container' => __( 'Container width', 'wise-addons-for-elementor-pro' ),
					'full'      => __( 'Full width', 'wise-addons-for-elementor-pro' ),
					'custom'    => __( 'Custom', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [ 'template_id!' => '' ],
			]
		);

		$repeater->add_responsive_control(
			'panel_custom_width',
			[
				'label'      => __( 'Custom Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'vw' ],
				'range'      => [
					'px' => [
						'min' => 200,
						'max' => 1600,
					],
					'vw' => [
						'min' => 10,
						'max' => 100,
					],
				],
				'default'    => [
					'unit' => 'px',
					'size' => 640,
				],
				'selectors'  => [
					'{{WRAPPER}} {{CURRENT_ITEM}} > .wea-mega-menu__mega' => '--wea-mm-cw: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [
					'template_id!' => '',
					'panel_width'  => 'custom',
				],
			]
		);

		$repeater->add_control(
			'panel_align',
			[
				'label'     => __( 'Panel Alignment', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'start',
				'toggle'    => false,
				'options'   => [
					'start'  => [
						'title' => __( 'Start of item', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-start-h',
					],
					'center' => [
						'title' => __( 'Center of item', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-center-h',
					],
					'end'    => [
						'title' => __( 'End of item', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-align-end-h',
					],
				],
				'condition' => [
					'template_id!' => '',
					'panel_width'  => 'custom',
				],
			]
		);

		$repeater->add_control(
			'icon',
			[
				'label'       => __( 'Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'separator'   => 'before',
			]
		);

		$repeater->add_control(
			'badge_text',
			[
				'label'       => __( 'Badge', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'New', 'wise-addons-for-elementor-pro' ),
				'dynamic'     => [ 'active' => true ],
			]
		);

		$repeater->add_control(
			'badge_color',
			[
				'label'     => __( 'Badge Text Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}} > .wea-mega-menu__row .wea-mega-menu__badge' => 'color: {{VALUE}};',
				],
				'condition' => [ 'badge_text!' => '' ],
			]
		);

		$repeater->add_control(
			'badge_bg',
			[
				'label'     => __( 'Badge Background', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}} > .wea-mega-menu__row .wea-mega-menu__badge' => 'background-color: {{VALUE}};',
				],
				'condition' => [ 'badge_text!' => '' ],
			]
		);

		$this->add_control(
			'mega_items',
			[
				'label'         => __( 'Items', 'wise-addons-for-elementor-pro' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'default'       => [],
				'title_field'   => self::item_title_field(),
				'prevent_empty' => false,
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: how dropdowns and mega panels open.
	 */
	protected function register_dropdown_content() {
		$this->start_controls_section(
			'section_dropdown_settings',
			[
				'label' => __( 'Dropdowns', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'trigger',
			[
				'label'   => __( 'Open On', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'hover',
				'options' => [
					'hover' => __( 'Hover', 'wise-addons-for-elementor-pro' ),
					'click' => __( 'Click', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$this->add_control(
			'hover_delay',
			[
				'label'       => __( 'Open Delay (ms)', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 120,
				'min'         => 0,
				'max'         => 1000,
				'step'        => 10,
				'description' => __( 'A short pause stops menus from flashing open while the pointer passes over them.', 'wise-addons-for-elementor-pro' ),
				'condition'   => [ 'trigger' => 'hover' ],
			]
		);

		$this->add_control(
			'close_delay',
			[
				'label'     => __( 'Close Delay (ms)', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 300,
				'min'       => 0,
				'max'       => 2000,
				'step'      => 10,
				'condition' => [ 'trigger' => 'hover' ],
			]
		);

		// Not "animation": Elementor reads that ID as an entrance animation and hides the widget until it runs.
		$this->add_control(
			'dropdown_animation',
			[
				'label'   => __( 'Animation', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'slide',
				'options' => [
					'fade'  => __( 'Fade', 'wise-addons-for-elementor-pro' ),
					'slide' => __( 'Slide up', 'wise-addons-for-elementor-pro' ),
					'none'  => __( 'None', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$this->add_control(
			'dropdown_duration',
			[
				'label'      => __( 'Animation Duration (ms)', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min'  => 50,
						'max'  => 800,
						'step' => 10,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-dur: {{SIZE}}ms;',
				],
				'condition'  => [ 'dropdown_animation!' => 'none' ],
			]
		);

		$this->add_control(
			'editor_open',
			[
				'label'        => __( 'Show First Mega Panel in Editor', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Keeps the first mega panel open while you style it. Visitors never see this.', 'wise-addons-for-elementor-pro' ),
				'separator'    => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Content: mobile menu.
	 */
	protected function register_mobile_content() {
		$has_mobile = [ 'breakpoint!' => 'none' ];

		$this->start_controls_section(
			'section_mobile',
			[
				'label' => __( 'Mobile Menu', 'wise-addons-for-elementor-pro' ),
			]
		);

		$this->add_control(
			'breakpoint',
			[
				'label'   => __( 'Breakpoint', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'tablet',
				'options' => static::breakpoint_options(),
			]
		);

		$this->add_control(
			'mobile_style',
			[
				'label'     => __( 'Style', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'offcanvas',
				'options'   => [
					'offcanvas' => __( 'Off-canvas panel', 'wise-addons-for-elementor-pro' ),
					'dropdown'  => __( 'Dropdown below', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $has_mobile,
			]
		);

		$this->add_control(
			'offcanvas_side',
			[
				'label'     => __( 'Slide In From', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'right',
				'toggle'    => false,
				'options'   => [
					'left'  => [
						'title' => __( 'Left', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-h-align-left',
					],
					'right' => [
						'title' => __( 'Right', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'condition' => $has_mobile + [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'dropdown_full',
			[
				'label'        => __( 'Full Width', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Stretches the dropdown to the width of the screen.', 'wise-addons-for-elementor-pro' ),
				'condition'    => $has_mobile + [ 'mobile_style' => 'dropdown' ],
			]
		);

		$this->add_control(
			'mobile_mega',
			[
				'label'        => __( 'Show Mega Panels', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Turn off to show the normal sub-items of mega items instead of their templates.', 'wise-addons-for-elementor-pro' ),
				'condition'    => $has_mobile,
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
					'value'   => 'fas fa-bars',
					'library' => 'fa-solid',
				],
				'separator'   => 'before',
				'condition'   => $has_mobile,
			]
		);

		$this->add_control(
			'close_icon',
			[
				'label'       => __( 'Close Icon', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'default'     => [
					'value'   => 'fas fa-times',
					'library' => 'fa-solid',
				],
				'condition'   => $has_mobile,
			]
		);

		$this->add_control(
			'toggle_label',
			[
				'label'       => __( 'Toggle Label', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Menu', 'wise-addons-for-elementor-pro' ),
				'description' => __( 'Also the name screen readers announce for the button and the panel.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $has_mobile,
			]
		);

		$this->add_control(
			'toggle_label_show',
			[
				'label'        => __( 'Show Label', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'condition'    => $has_mobile,
			]
		);

		$this->add_responsive_control(
			'toggle_align',
			[
				'label'                => __( 'Toggle Alignment', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => [
					'start'  => [
						'title' => __( 'Start', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-h-align-left',
					],
					'center' => [
						'title' => __( 'Center', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-h-align-center',
					],
					'end'    => [
						'title' => __( 'End', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'selectors_dictionary' => [
					'start'  => 'flex-start',
					'center' => 'center',
					'end'    => 'flex-end',
				],
				'selectors'            => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-toggle-align: {{VALUE}};',
				],
				'condition'            => $has_mobile,
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Add Normal / Hover / Active color tabs that set custom properties on the root.
	 *
	 * @param string $prefix Control ID prefix.
	 * @param array  $tabs   [ state => [ label, [ control suffix => [ label, property, global or '' ] ] ] ].
	 */
	private function add_state_color_tabs( $prefix, array $tabs ) {
		$this->start_controls_tabs( $prefix . '_tabs' );

		foreach ( $tabs as $state => $tab ) {
			$this->start_controls_tab(
				$prefix . '_tab_' . $state,
				[
					'label' => $tab[0],
				]
			);

			foreach ( $tab[1] as $id => $control ) {
				$args = [
					'label'     => $control[0],
					'type'      => Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .wea-mega-menu' => $control[1] . ': {{VALUE}};',
					],
				];
				if ( ! empty( $control[2] ) ) {
					$args['global'] = [ 'default' => $control[2] ];
				}
				if ( ! empty( $control[3] ) ) {
					$args['condition'] = $control[3];
				}
				$this->add_control( $id, $args );
			}

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();
	}

	/**
	 * Padding control that writes four custom properties.
	 *
	 * @param string $id     Control ID.
	 * @param string $label  Label.
	 * @param string $prefix Custom property prefix, e.g. --wea-mm-p.
	 * @param array  $extra  Extra control args.
	 */
	private function add_padding_vars( $id, $label, $prefix, array $extra = [] ) {
		$this->add_responsive_control(
			$id,
			$extra + [
				'label'      => $label,
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => $prefix . 't: {{TOP}}{{UNIT}}; ' . $prefix . 'e: {{RIGHT}}{{UNIT}}; ' . $prefix . 'b: {{BOTTOM}}{{UNIT}}; ' . $prefix . 's: {{LEFT}}{{UNIT}};',
				],
			]
		);
	}

	/**
	 * Style: top-level items.
	 */
	protected function register_menu_style() {
		$desk = '{{WRAPPER}} .wea-mega-menu:not(.is-mobile)';
		$has  = [ 'pointer!' => 'none' ];

		$this->start_controls_section(
			'section_style_menu',
			[
				'label' => __( 'Menu Bar', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'menu_typography',
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_PRIMARY ],
				'selector' => '{{WRAPPER}} .wea-mega-menu__item--top > .wea-mega-menu__row',
			]
		);

		$this->add_control(
			'menu_bar_bg',
			[
				'label'     => __( 'Bar Background', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$desk . ' .wea-mega-menu__menu' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_state_color_tabs(
			'menu_colors',
			[
				'normal' => [
					__( 'Normal', 'wise-addons-for-elementor-pro' ),
					[
						'menu_color' => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-c', Global_Colors::COLOR_TEXT ],
						'menu_bg'    => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-bg', '' ],
					],
				],
				'hover'  => [
					__( 'Hover', 'wise-addons-for-elementor-pro' ),
					[
						'menu_color_hover'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-c-h', Global_Colors::COLOR_ACCENT ],
						'menu_bg_hover'      => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-bg-h', '' ],
						'menu_pointer_color' => [ __( 'Pointer Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-ptr-h', Global_Colors::COLOR_ACCENT, $has ],
					],
				],
				'active' => [
					__( 'Active', 'wise-addons-for-elementor-pro' ),
					[
						'menu_color_active'         => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-c-a', Global_Colors::COLOR_ACCENT ],
						'menu_bg_active'            => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-bg-a', '' ],
						'menu_pointer_color_active' => [ __( 'Pointer Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-ptr-a', '', $has ],
					],
				],
			]
		);

		$this->add_control(
			'menu_active_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Hover colors also show while a dropdown is open. Active colors mark the current page and its parents.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
			]
		);

		$this->add_padding_vars(
			'menu_item_padding',
			__( 'Item Padding', 'wise-addons-for-elementor-pro' ),
			'--wea-mm-p',
			[ 'separator' => 'before' ]
		);

		$this->add_responsive_control(
			'menu_gap',
			[
				'label'      => __( 'Space Between Items', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 80,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'menu_item_radius',
			[
				'label'      => __( 'Item Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'selectors'  => [
					$desk . ' .wea-mega-menu__item--top > .wea-mega-menu__row' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'pointer',
			[
				'label'     => __( 'Pointer', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'underline',
				'options'   => [
					'none'      => __( 'None', 'wise-addons-for-elementor-pro' ),
					'underline' => __( 'Underline', 'wise-addons-for-elementor-pro' ),
					'overline'  => __( 'Overline', 'wise-addons-for-elementor-pro' ),
					'double'    => __( 'Double line', 'wise-addons-for-elementor-pro' ),
					'framed'    => __( 'Frame', 'wise-addons-for-elementor-pro' ),
					'dot'       => __( 'Dot', 'wise-addons-for-elementor-pro' ),
				],
				'separator' => 'before',
			]
		);

		$this->add_control(
			'pointer_animation',
			[
				'label'     => __( 'Pointer Animation', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'grow',
				'options'   => [
					'grow'  => __( 'Grow from center', 'wise-addons-for-elementor-pro' ),
					'slide' => __( 'Slide from start', 'wise-addons-for-elementor-pro' ),
					'fade'  => __( 'Fade', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => [ 'pointer' => [ 'underline', 'overline', 'double' ] ],
			]
		);

		$this->add_control(
			'pointer_width',
			[
				'label'      => __( 'Pointer Thickness', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 12,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-ptr-w: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [ 'pointer' => [ 'underline', 'overline', 'double', 'framed', 'dot' ] ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: item icons and sub-menu indicators.
	 */
	protected function register_icons_style() {
		$this->start_controls_section(
			'section_style_icons',
			[
				'label' => __( 'Icons & Indicators', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$sliders = [
			'icon_size'      => [ __( 'Icon Size', 'wise-addons-for-elementor-pro' ), '--wea-mm-icon-size', 8, 48 ],
			'icon_gap'       => [ __( 'Icon Spacing', 'wise-addons-for-elementor-pro' ), '--wea-mm-icon-gap', 0, 30 ],
			'indicator_size' => [ __( 'Indicator Size', 'wise-addons-for-elementor-pro' ), '--wea-mm-ind-size', 6, 30 ],
			'indicator_gap'  => [ __( 'Indicator Spacing', 'wise-addons-for-elementor-pro' ), '--wea-mm-ind-gap', 0, 30 ],
		];

		foreach ( $sliders as $id => $slider ) {
			$this->add_responsive_control(
				$id,
				[
					'label'      => $slider[0],
					'type'       => Controls_Manager::SLIDER,
					'size_units' => [ 'px', 'em', 'rem' ],
					'range'      => [
						'px' => [
							'min' => $slider[2],
							'max' => $slider[3],
						],
					],
					'selectors'  => [
						'{{WRAPPER}} .wea-mega-menu' => $slider[1] . ': {{SIZE}}{{UNIT}};',
					],
				]
			);
		}

		$this->add_control(
			'icon_color',
			[
				'label'       => __( 'Icon Color', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::COLOR,
				'description' => __( 'Leave empty to follow the text color.', 'wise-addons-for-elementor-pro' ),
				'selectors'   => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-icon-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: normal dropdowns.
	 */
	protected function register_dropdown_style() {
		$sub = '{{WRAPPER}} .wea-mega-menu:not(.is-mobile) .wea-mega-menu__sub';

		$this->start_controls_section(
			'section_style_dropdown',
			[
				'label' => __( 'Dropdown', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'dropdown_bg',
			[
				'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$sub => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'dropdown_width',
			[
				'label'      => __( 'Min Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 120,
						'max' => 480,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-dd-w: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'dropdown_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					$sub => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'dropdown_border',
				'selector' => $sub,
			]
		);

		$this->add_responsive_control(
			'dropdown_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem' ],
				'selectors'  => [
					$sub => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'dropdown_shadow',
				'selector' => $sub,
			]
		);

		$this->add_responsive_control(
			'dropdown_offset',
			[
				'label'      => __( 'Distance', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 60,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-dd-offset: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'dropdown_items_heading',
			[
				'label'     => __( 'Items', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'dropdown_typography',
				'global'   => [ 'default' => Global_Typography::TYPOGRAPHY_TEXT ],
				'selector' => '{{WRAPPER}} .wea-mega-menu__sub .wea-mega-menu__row',
			]
		);

		$this->add_padding_vars( 'dropdown_item_padding', __( 'Item Padding', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-p' );

		$this->add_state_color_tabs(
			'dropdown_colors',
			[
				'normal' => [
					__( 'Normal', 'wise-addons-for-elementor-pro' ),
					[
						'dropdown_color'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-c', Global_Colors::COLOR_TEXT ],
						'dropdown_item_bg' => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-ibg', '' ],
					],
				],
				'hover'  => [
					__( 'Hover', 'wise-addons-for-elementor-pro' ),
					[
						'dropdown_color_hover'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-c-h', Global_Colors::COLOR_ACCENT ],
						'dropdown_item_bg_hover' => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-ibg-h', '' ],
					],
				],
				'active' => [
					__( 'Active', 'wise-addons-for-elementor-pro' ),
					[
						'dropdown_color_active'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-c-a', Global_Colors::COLOR_ACCENT ],
						'dropdown_item_bg_active' => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-dd-ibg-a', '' ],
					],
				],
			]
		);

		$this->add_control(
			'dropdown_divider',
			[
				'label'     => __( 'Divider Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-dd-div: {{VALUE}};',
				],
				'separator' => 'before',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: mega panels on desktop.
	 */
	protected function register_mega_style() {
		$mega = '{{WRAPPER}} .wea-mega-menu:not(.is-mobile) .wea-mega-menu__mega';

		$this->start_controls_section(
			'section_style_mega',
			[
				'label' => __( 'Mega Panel', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => 'mega_background',
				'types'    => [ 'classic', 'gradient' ],
				'exclude'  => [ 'image' ],
				'selector' => $mega,
			]
		);

		$this->add_responsive_control(
			'mega_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', '%' ],
				'selectors'  => [
					$mega => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'mega_border',
				'selector' => $mega,
			]
		);

		$this->add_responsive_control(
			'mega_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem' ],
				'selectors'  => [
					$mega => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'mega_shadow',
				'selector' => $mega,
			]
		);

		$this->add_responsive_control(
			'mega_offset',
			[
				'label'       => __( 'Top Offset', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px' ],
				'range'       => [
					'px' => [
						'min' => 0,
						'max' => 120,
					],
				],
				'description' => __( 'Space between the menu bar and the panel, for example to start the panel at the bottom of a taller header.', 'wise-addons-for-elementor-pro' ),
				'selectors'   => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-mega-offset: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: badges.
	 */
	protected function register_badge_style() {
		$badge = '{{WRAPPER}} .wea-mega-menu__badge';

		$this->start_controls_section(
			'section_style_badge',
			[
				'label' => __( 'Badge', 'wise-addons-for-elementor-pro' ),
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

		$this->add_control(
			'badge_text_color',
			[
				'label'     => __( 'Text Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$badge => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'badge_background',
			[
				'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [ 'default' => Global_Colors::COLOR_ACCENT ],
				'selectors' => [
					$badge => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					$badge => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 30,
					],
				],
				'selectors'  => [
					$badge => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_gap',
			[
				'label'      => __( 'Spacing', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 30,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-badge-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'badge_position',
			[
				'label'                => __( 'Position', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'inline',
				'options'              => [
					'inline' => __( 'Next to the label', 'wise-addons-for-elementor-pro' ),
					'raised' => __( 'Raised', 'wise-addons-for-elementor-pro' ),
				],
				'selectors_dictionary' => [
					'inline' => '--wea-mm-badge-shift: 0;',
					'raised' => '--wea-mm-badge-shift: -0.75em;',
				],
				'selectors'            => [
					'{{WRAPPER}} .wea-mega-menu' => '{{VALUE}}',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: hamburger toggle.
	 */
	protected function register_toggle_style() {
		$toggle = '{{WRAPPER}} .wea-mega-menu__toggle';

		$this->start_controls_section(
			'section_style_toggle',
			[
				'label'     => __( 'Toggle Button', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'breakpoint!' => 'none' ],
			]
		);

		$this->add_responsive_control(
			'toggle_size',
			[
				'label'      => __( 'Icon Size', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 12,
						'max' => 60,
					],
				],
				'selectors'  => [
					$toggle => '--wea-mm-toggle-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'toggle_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors'  => [
					$toggle => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'toggle_label_typography',
				'selector'  => $toggle . ' .wea-mega-menu__toggle-label',
				'condition' => [ 'toggle_label_show' => 'yes' ],
			]
		);

		$this->add_state_color_tabs(
			'toggle_colors',
			[
				'normal' => [
					__( 'Normal', 'wise-addons-for-elementor-pro' ),
					[
						'toggle_color'        => [ __( 'Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-c', Global_Colors::COLOR_TEXT ],
						'toggle_bg'           => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-bg', '' ],
						'toggle_border_color' => [ __( 'Border Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-bc', '' ],
					],
				],
				'hover'  => [
					__( 'Hover', 'wise-addons-for-elementor-pro' ),
					[
						'toggle_color_hover'        => [ __( 'Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-c-h', '' ],
						'toggle_bg_hover'           => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-bg-h', '' ],
						'toggle_border_color_hover' => [ __( 'Border Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-tg-bc-h', '' ],
					],
				],
			]
		);

		$this->add_responsive_control(
			'toggle_border_width',
			[
				'label'      => __( 'Border Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 10,
					],
				],
				'selectors'  => [
					$toggle => 'border-width: {{SIZE}}{{UNIT}};',
				],
				'separator'  => 'before',
			]
		);

		$this->add_responsive_control(
			'toggle_radius',
			[
				'label'      => __( 'Border Radius', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 50,
					],
				],
				'selectors'  => [
					$toggle => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style: mobile panel (off-canvas or dropdown) and its items.
	 */
	protected function register_mobile_style() {
		$mob   = '{{WRAPPER}} .wea-mega-menu.is-mobile';
		$panel = $mob . ' .wea-mega-menu__panel';
		$close = '{{WRAPPER}} .wea-mega-menu__close';

		$this->start_controls_section(
			'section_style_mobile',
			[
				'label'     => __( 'Mobile Panel', 'wise-addons-for-elementor-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'breakpoint!' => 'none' ],
			]
		);

		$this->add_responsive_control(
			'mobile_panel_width',
			[
				'label'      => __( 'Off-canvas Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'vw', '%' ],
				'range'      => [
					'px' => [
						'min' => 200,
						'max' => 640,
					],
					'vw' => [
						'min' => 30,
						'max' => 100,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-oc-w: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'mobile_panel_bg',
			[
				'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$panel => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'mobile_panel_padding',
			[
				'label'      => __( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					$panel => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'mobile_panel_shadow',
				'selector' => $panel,
			]
		);

		$this->add_control(
			'mobile_overlay',
			[
				'label'     => __( 'Overlay Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-mega-menu__overlay' => 'background-color: {{VALUE}};',
				],
				'condition' => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'close_heading',
			[
				'label'     => __( 'Close Button', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_responsive_control(
			'close_size',
			[
				'label'      => __( 'Size', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 10,
						'max' => 48,
					],
				],
				'selectors'  => [
					$close => 'font-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'close_color',
			[
				'label'     => __( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-close-c: {{VALUE}};',
				],
				'condition' => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'close_bg',
			[
				'label'     => __( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-close-bg: {{VALUE}};',
				],
				'condition' => [ 'mobile_style' => 'offcanvas' ],
			]
		);

		$this->add_control(
			'mobile_items_heading',
			[
				'label'     => __( 'Items', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'mobile_typography',
				'selector' => $mob . ' .wea-mega-menu__row',
			]
		);

		$this->add_padding_vars( 'mobile_item_padding', __( 'Item Padding', 'wise-addons-for-elementor-pro' ), '--wea-mm-m-p' );

		$this->add_state_color_tabs(
			'mobile_colors',
			[
				'normal' => [
					__( 'Normal', 'wise-addons-for-elementor-pro' ),
					[
						'mobile_color'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-m-c', '' ],
						'mobile_item_bg' => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-m-bg', '' ],
					],
				],
				'active' => [
					__( 'Active', 'wise-addons-for-elementor-pro' ),
					[
						'mobile_color_active'   => [ __( 'Text Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-m-c-a', '' ],
						'mobile_item_bg_active' => [ __( 'Background Color', 'wise-addons-for-elementor-pro' ), '--wea-mm-m-bg-a', '' ],
					],
				],
			]
		);

		$this->add_control(
			'mobile_divider',
			[
				'label'     => __( 'Divider Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-m-div: {{VALUE}};',
				],
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			'mobile_indent',
			[
				'label'      => __( 'Sub-menu Indent', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 60,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .wea-mega-menu' => '--wea-mm-m-indent: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Allowed HTML for the menu markup: the shared list plus aria-haspopup on buttons.
	 *
	 * @return array
	 */
	private static function menu_kses() {
		static $allowed = null;
		if ( null === $allowed ) {
			$allowed                            = Kses::allowed_html();
			$allowed['button']                  = isset( $allowed['button'] ) ? $allowed['button'] : [];
			$allowed['button']['type']          = true;
			$allowed['button']['aria-haspopup'] = true;
		}
		return $allowed;
	}

	/**
	 * Indicator SVG for toggle buttons.
	 *
	 * @param string $type chevron, caret, plus or none.
	 * @return string
	 */
	private static function indicator_svg( $type ) {
		switch ( $type ) {
			case 'caret':
				$shape = '<path d="M6 9.5h12l-6 6.5z" fill="currentColor"/>';
				break;
			case 'plus':
				$shape = '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>';
				break;
			default:
				$shape = '<path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>';
		}
		return '<svg class="wea-mega-menu__indicator" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' . $shape . '</svg>';
	}

	/**
	 * Print an icon control value, or a built-in SVG when it is empty.
	 *
	 * @param array  $icon     Icons control value.
	 * @param string $fallback bars or close.
	 */
	private function print_toggle_icon( $icon, $fallback ) {
		if ( is_array( $icon ) && ! empty( $icon['value'] ) ) {
			$this->print_icon( $icon );
			return;
		}
		$path = 'close' === $fallback ? 'M6 6l12 12M18 6L6 18' : 'M4 7h16M4 12h16M4 17h16';
		echo '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="' . esc_attr( $path ) . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
	}

	/**
	 * The chosen menu, or the first menu when the choice is gone.
	 *
	 * @param string|int $menu_id Menu term ID.
	 * @return \WP_Term|null
	 */
	protected function current_menu( $menu_id ) {
		$menu = $menu_id ? wp_get_nav_menu_object( absint( $menu_id ) ) : false;
		if ( $menu ) {
			return $menu;
		}
		$menus = wp_get_nav_menus();
		return $menus ? reset( $menus ) : null;
	}

	/**
	 * Editor-only notice with an optional link. Visitors never see it.
	 *
	 * @param string $message Message.
	 * @param string $url     Link URL.
	 * @param string $label   Link text.
	 */
	private function print_editor_notice( $message, $url = '', $label = '' ) {
		if ( ! $this->wea_is_edit_mode() ) {
			return;
		}
		echo '<div class="elementor-alert elementor-alert-info" role="note">' . esc_html( $message );
		if ( $url && $label ) {
			echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Render.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$menu = $this->current_menu( isset( $s['menu'] ) ? $s['menu'] : '' );

		if ( ! $menu ) {
			$this->print_editor_notice(
				__( 'Mega Menu: there are no menus yet.', 'wise-addons-for-elementor-pro' ),
				admin_url( 'nav-menus.php?action=edit&menu=0' ),
				__( 'Create a menu', 'wise-addons-for-elementor-pro' )
			);
			return;
		}

		$items = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
		if ( ! $items ) {
			$this->print_editor_notice(
				__( 'Mega Menu: this menu has no items yet.', 'wise-addons-for-elementor-pro' ),
				admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id ),
				__( 'Edit the menu', 'wise-addons-for-elementor-pro' )
			);
			return;
		}

		++self::$depth;
		try {
			$this->render_menu( $s, $menu, $items );
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Icons, badges and rendered mega templates for the menu's top-level items.
	 *
	 * @param array      $s     Settings.
	 * @param \WP_Post[] $items Menu items.
	 * @return array [ extras keyed by item ID, template HTML keyed by item ID ]
	 */
	protected function collect_extras( array $s, array $items ) {
		$top = [];
		foreach ( $items as $item ) {
			if ( 0 === (int) $item->menu_item_parent ) {
				$top[ (int) $item->ID ] = true;
			}
		}

		$rows         = isset( $s['mega_items'] ) && is_array( $s['mega_items'] ) ? $s['mega_items'] : [];
		$allow_panels = self::$depth <= self::MAX_DEPTH;
		$extras       = [];
		$templates    = [];
		$cache        = [];

		foreach ( $rows as $row ) {
			$id = isset( $row['menu_item'] ) ? absint( $row['menu_item'] ) : 0;
			if ( ! $id || ! isset( $top[ $id ] ) || isset( $extras[ $id ] ) ) {
				continue;
			}

			$width = isset( $row['panel_width'] ) && in_array( $row['panel_width'], [ 'container', 'full', 'custom' ], true ) ? $row['panel_width'] : 'container';
			$align = isset( $row['panel_align'] ) && in_array( $row['panel_align'], [ 'start', 'center', 'end' ], true ) ? $row['panel_align'] : 'start';
			$tid   = isset( $row['template_id'] ) ? absint( $row['template_id'] ) : 0;
			$html  = '';

			// Each template renders once per menu; the stack in Saved_Template stops a panel from including itself.
			if ( $tid && $allow_panels ) {
				if ( ! isset( $cache[ $tid ] ) ) {
					$cache[ $tid ] = $this->saved_template_html( $tid );
				}
				$html = $cache[ $tid ];
			}

			$icon = '';
			if ( ! empty( $row['icon']['value'] ) ) {
				$icon = (string) Icons_Manager::try_get_icon_html( $row['icon'], [ 'aria-hidden' => 'true' ] );
			}

			$extras[ $id ] = [
				'rid'   => isset( $row['_id'] ) ? sanitize_html_class( $row['_id'] ) : '',
				'panel' => '' !== trim( $html ),
				'width' => $width,
				'align' => $align,
				'icon'  => $icon,
				'badge' => isset( $row['badge_text'] ) ? trim( wp_strip_all_tags( (string) $row['badge_text'] ) ) : '',
			];

			if ( $extras[ $id ]['panel'] ) {
				$templates[ $id ] = $html;
			}
		}

		return [ $extras, $templates ];
	}

	/**
	 * Print the menu.
	 *
	 * @param array      $s     Settings.
	 * @param \WP_Term   $menu  Menu.
	 * @param \WP_Post[] $items Menu items.
	 */
	protected function render_menu( array $s, $menu, array $items ) {
		$uid       = 'wea-mm-' . $this->get_id();
		$layout    = isset( $s['layout'] ) && 'vertical' === $s['layout'] ? 'vertical' : 'horizontal';
		$trigger   = isset( $s['trigger'] ) && 'click' === $s['trigger'] ? 'click' : 'hover';
		$animation = isset( $s['dropdown_animation'] ) && in_array( $s['dropdown_animation'], [ 'fade', 'slide', 'none' ], true ) ? $s['dropdown_animation'] : 'slide';
		$indicator = isset( $s['indicator'] ) && in_array( $s['indicator'], [ 'chevron', 'caret', 'plus', 'none' ], true ) ? $s['indicator'] : 'chevron';
		$pointer   = isset( $s['pointer'] ) && in_array( $s['pointer'], [ 'none', 'underline', 'overline', 'double', 'framed', 'dot' ], true ) ? $s['pointer'] : 'underline';
		$p_anim    = isset( $s['pointer_animation'] ) && in_array( $s['pointer_animation'], [ 'grow', 'slide', 'fade' ], true ) ? $s['pointer_animation'] : 'grow';
		$bp        = $this->breakpoint_px( isset( $s['breakpoint'] ) ? (string) $s['breakpoint'] : 'tablet' );
		$m_style   = isset( $s['mobile_style'] ) && 'dropdown' === $s['mobile_style'] ? 'dropdown' : 'offcanvas';
		$side      = isset( $s['offcanvas_side'] ) && 'left' === $s['offcanvas_side'] ? 'left' : 'right';
		$m_mega    = ! isset( $s['mobile_mega'] ) || 'yes' === $s['mobile_mega'];
		$full      = ! isset( $s['dropdown_full'] ) || 'yes' === $s['dropdown_full'];
		$label     = isset( $s['toggle_label'] ) && '' !== trim( (string) $s['toggle_label'] ) ? trim( (string) $s['toggle_label'] ) : __( 'Menu', 'wise-addons-for-elementor-pro' );
		$nav_label = isset( $s['nav_label'] ) && '' !== trim( (string) $s['nav_label'] ) ? trim( (string) $s['nav_label'] ) : $menu->name;
		$offcanvas = $bp && 'offcanvas' === $m_style;

		list( $extras, $templates ) = $this->collect_extras( $s, $items );

		$walker = new Mega_Menu_Walker( $uid, $extras, self::indicator_svg( $indicator ) );

		$menu_html = wp_nav_menu(
			[
				'menu'          => (int) $menu->term_id,
				'container'     => false,
				'menu_id'       => $uid . '-list',
				'menu_class'    => 'wea-mega-menu__menu',
				'items_wrap'    => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				'fallback_cb'   => false,
				'echo'          => false,
				'depth'         => 0,
				'walker'        => $walker,
				'wea_mega_menu' => true,
			]
		);

		$this->add_render_attribute(
			'root',
			[
				'id'                  => $uid,
				'class'               => [
					'wea-mega-menu',
					'wea-mega-menu--' . $layout,
					'wea-mega-menu--trigger-' . $trigger,
					'wea-mega-menu--anim-' . $animation,
					'wea-mega-menu--pointer-' . $pointer,
					'wea-mega-menu--ptr-' . $p_anim,
					'wea-mega-menu--indicator-' . $indicator,
					$bp ? 'wea-mega-menu--mobile-' . $m_style : '',
					$offcanvas ? 'wea-mega-menu--side-' . $side : '',
					$m_mega ? 'wea-mega-menu--mega-mobile' : '',
				],
				'data-wea-mega-menu'  => wp_json_encode(
					[
						'bp'         => $bp,
						'trigger'    => $trigger,
						'layout'     => $layout,
						'openDelay'  => isset( $s['hover_delay'] ) && is_numeric( $s['hover_delay'] ) ? max( 0, min( 1000, (int) $s['hover_delay'] ) ) : 120,
						'closeDelay' => isset( $s['close_delay'] ) && is_numeric( $s['close_delay'] ) ? max( 0, min( 2000, (int) $s['close_delay'] ) ) : 300,
						'animation'  => $animation,
						'mobile'     => $bp ? $m_style : '',
						'megaMobile' => $m_mega,
						'full'       => $full,
						'label'      => $label,
						'editorOpen' => ! empty( $s['editor_open'] ) && 'yes' === $s['editor_open'],
					]
				),
			]
		);
		?>
		<div <?php $this->print_render_attribute_string( 'root' ); ?>>
			<?php
			if ( $bp ) {
				// Before the script runs, show the toggle instead of the menu below the breakpoint, so nothing jumps.
				$scope = '#' . $uid . ':not(.is-init)';
				$css   = '@media (max-width:' . $bp . 'px){' . $scope . ' .wea-mega-menu__toggle{display:inline-flex}' . $scope . ' .wea-mega-menu__panel{display:none}}';
				echo '<style>' . esc_html( $css ) . '</style>';
				?>
				<button type="button" class="wea-mega-menu__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid . '-panel' ); ?>">
					<span class="wea-mega-menu__toggle-icon wea-mega-menu__toggle-icon--open" aria-hidden="true"><?php $this->print_toggle_icon( isset( $s['toggle_icon'] ) ? $s['toggle_icon'] : [], 'bars' ); ?></span>
					<span class="wea-mega-menu__toggle-icon wea-mega-menu__toggle-icon--close" aria-hidden="true"><?php $this->print_toggle_icon( isset( $s['close_icon'] ) ? $s['close_icon'] : [], 'close' ); ?></span>
					<span class="wea-mega-menu__toggle-label<?php echo ! empty( $s['toggle_label_show'] ) && 'yes' === $s['toggle_label_show'] ? '' : ' wea-sr-only'; ?>"><?php echo esc_html( $label ); ?></span>
				</button>
				<?php if ( $offcanvas ) : ?>
					<div class="wea-mega-menu__overlay" aria-hidden="true"></div>
				<?php endif; ?>
				<?php
			}
			?>
			<div class="wea-mega-menu__panel" id="<?php echo esc_attr( $uid . '-panel' ); ?>">
				<?php if ( $offcanvas ) : ?>
					<div class="wea-mega-menu__panel-head">
						<button type="button" class="wea-mega-menu__close">
							<span class="wea-icon" aria-hidden="true"><?php $this->print_toggle_icon( isset( $s['close_icon'] ) ? $s['close_icon'] : [], 'close' ); ?></span>
							<span class="wea-sr-only"><?php esc_html_e( 'Close menu', 'wise-addons-for-elementor-pro' ); ?></span>
						</button>
					</div>
				<?php endif; ?>
				<nav class="wea-mega-menu__nav" aria-label="<?php echo esc_attr( $nav_label ); ?>">
					<?php
					$allowed = self::menu_kses();
					$parts   = preg_split( '/<!--wea-mm-panel:(\d+)-->/', (string) $menu_html, -1, PREG_SPLIT_DELIM_CAPTURE );
					foreach ( (array) $parts as $i => $part ) {
						if ( $i % 2 ) {
							$item_id = (int) $part;
							if ( isset( $templates[ $item_id ] ) ) {
								$this->print_saved_template_item_html( $templates[ $item_id ] );
							}
						} else {
							echo wp_kses( $part, $allowed );
						}
					}
					?>
				</nav>
			</div>
		</div>
		<?php
	}
}
