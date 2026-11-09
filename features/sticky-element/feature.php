<?php
/**
 * Sticky Element: pin any widget, container, section or column while the page scrolls.
 *
 * The element keeps its normal place in the markup. script.js decides how to pin it:
 * - "Inside parent" uses native `position: sticky` when it can work, which costs
 *   nothing while scrolling.
 * - "Whole page", or a parent whose overflow would break native sticky, uses
 *   `position: fixed` with a same-size placeholder, so nothing below it jumps.
 *
 * The offset and z-index are CSS custom properties written by `selectors`, so they
 * follow Elementor's breakpoints and update live in the editor. The "stuck" style
 * applies through the `wea-is-stuck` class that the script toggles.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Element_Base;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Plugin;
use WiseAddons\Assets;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Sticky Element feature.
 */
class Sticky_Element extends Feature_Base {

	const SLUG = 'sticky-element';

	/**
	 * Switch control ID. Permanent.
	 */
	const ENABLE = 'wea_sticky_enable';

	/**
	 * Selector for the stuck state. Two classes beat Elementor's own background
	 * and padding rules, which print later with the same base specificity.
	 */
	const STUCK = '{{WRAPPER}}.wea-sticky--active.wea-is-stuck';

	/**
	 * Device options, built once.
	 *
	 * @var array|null
	 */
	private $devices = null;

	/**
	 * Hooks.
	 */
	protected function init() {
		$this->add_advanced_section( [ $this, 'register_controls' ] );

		add_action( 'elementor/frontend/before_render', [ $this, 'before_render' ] );
		add_filter( 'elementor/frontend/builder_content_data', [ $this, 'scan_builder_content' ] );
		add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Load the script (once).
	 */
	public function enqueue() {
		$this->enqueue_script();
	}

	/**
	 * Devices from Elementor's active breakpoints, largest first.
	 *
	 * @return array key => label
	 */
	private function devices() {
		if ( null !== $this->devices ) {
			return $this->devices;
		}

		$larger  = [];
		$smaller = [];

		$breakpoints = isset( Plugin::$instance->breakpoints ) ? Plugin::$instance->breakpoints->get_active_breakpoints() : [];

		foreach ( $breakpoints as $key => $breakpoint ) {
			$entry = [
				'label' => esc_html( $breakpoint->get_label() ),
				'value' => (int) $breakpoint->get_value(),
			];
			if ( 'min' === $breakpoint->get_direction() ) {
				$larger[ $key ] = $entry;
			} else {
				$smaller[ $key ] = $entry;
			}
		}

		$by_size = static function ( $a, $b ) {
			return $b['value'] - $a['value'];
		};
		uasort( $larger, $by_size );
		uasort( $smaller, $by_size );

		$this->devices = wp_list_pluck( $larger, 'label' )
			+ [ 'desktop' => esc_html__( 'Desktop', 'wise-addons-for-elementor-pro' ) ]
			+ wp_list_pluck( $smaller, 'label' );

		return $this->devices;
	}

	/**
	 * Advanced tab section.
	 *
	 * @param Element_Base $element Widget, container, section or column.
	 */
	public function register_controls( $element ) {
		$element->start_controls_section(
			'wea_section_sticky',
			[
				'label' => esc_html__( 'Wise Sticky', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->add_control(
			self::ENABLE,
			[
				'label'          => esc_html__( 'Sticky', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SWITCHER,
				'return_value'   => 'yes',
				'style_transfer' => false,
			]
		);

		$on = [ self::ENABLE => 'yes' ];

		$element->add_control(
			'wea_sticky_position',
			[
				'label'          => esc_html__( 'Stick To', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::CHOOSE,
				'default'        => 'top',
				'toggle'         => false,
				'options'        => [
					'top'    => [
						'title' => esc_html__( 'Top', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-top',
					],
					'bottom' => [
						'title' => esc_html__( 'Bottom', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-bottom',
					],
				],
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_responsive_control(
			'wea_sticky_offset',
			[
				'label'      => esc_html__( 'Offset', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 500,
					],
				],
				'condition'  => $on,
				'selectors'  => [ '{{WRAPPER}}' => '--wea-sticky-offset: {{SIZE}}px;' ],
			]
		);

		$devices = $this->devices();

		$element->add_control(
			'wea_sticky_devices',
			[
				'label'          => esc_html__( 'Devices', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SELECT2,
				'multiple'       => true,
				'label_block'    => true,
				'options'        => $devices,
				'default'        => array_keys( $devices ),
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			'wea_sticky_scope',
			[
				'label'          => esc_html__( 'Stay Within', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => 'page',
				'options'        => [
					'page'   => esc_html__( 'Whole page', 'wise-addons-for-elementor-pro' ),
					'parent' => esc_html__( 'Parent element', 'wise-addons-for-elementor-pro' ),
				],
				'description'    => esc_html__( '"Parent element" lets go when the end of the parent container, column or section scrolls past. Use "Whole page" for headers.', 'wise-addons-for-elementor-pro' ),
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			'wea_sticky_z',
			[
				'label'       => esc_html__( 'Z-Index', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 99999,
				'placeholder' => '99',
				'condition'   => $on,
				'selectors'   => [ '{{WRAPPER}}' => '--wea-sticky-z: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_sticky_hide',
			[
				'label'          => esc_html__( 'Hide on Scroll Down', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SWITCHER,
				'return_value'   => 'yes',
				'description'    => esc_html__( 'Slides out while visitors scroll down and comes back as soon as they scroll up. Handy for headers.', 'wise-addons-for-elementor-pro' ),
				'condition'      => $on + [ 'wea_sticky_position' => 'top' ],
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			'wea_sticky_stuck_heading',
			[
				'label'     => esc_html__( 'While Stuck', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $on,
			]
		);

		// Columns paint their background and padding on the inner wrap; containers read
		// padding from custom properties (boxed ones put it on .e-con-inner).
		$type    = $element->get_type();
		$box     = 'column' === $type ? self::STUCK . ' > .elementor-element-populated' : self::STUCK;
		$padding = 'container' === $type
			? '--padding-top: {{TOP}}{{UNIT}}; --padding-right: {{RIGHT}}{{UNIT}}; --padding-bottom: {{BOTTOM}}{{UNIT}}; --padding-left: {{LEFT}}{{UNIT}};'
			: 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};';

		$element->add_control(
			'wea_sticky_stuck_bg',
			[
				'label'     => esc_html__( 'Background Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => $on,
				'selectors' => [ $box => 'background-color: {{VALUE}}; background-image: none;' ],
			]
		);

		$element->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'      => 'wea_sticky_stuck_shadow',
				'condition' => $on,
				'selector'  => self::STUCK,
			]
		);

		$element->add_responsive_control(
			'wea_sticky_stuck_padding',
			[
				'label'      => esc_html__( 'Padding', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', '%' ],
				'condition'  => $on,
				'selectors'  => [ $box => $padding ],
			]
		);

		$element->add_control(
			'wea_sticky_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The "While Stuck" style applies through the wea-is-stuck class, which you can also use in your own CSS. Sticky works in the editor preview too; switch devices to check each one.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => $on,
			]
		);

		$element->end_controls_section();
	}

	/**
	 * Pass the settings the script needs and load it.
	 *
	 * @param Element_Base $element Element about to render.
	 */
	public function before_render( $element ) {
		$raw = $element->get_data( 'settings' );
		if ( ! is_array( $raw ) || empty( $raw[ self::ENABLE ] ) || 'yes' !== $raw[ self::ENABLE ] ) {
			return;
		}

		$settings = $element->get_settings_for_display();
		$position = isset( $settings['wea_sticky_position'] ) && 'bottom' === $settings['wea_sticky_position'] ? 'bottom' : 'top';
		$chosen   = isset( $settings['wea_sticky_devices'] ) ? (array) $settings['wea_sticky_devices'] : array_keys( $this->devices() );

		$config = [
			'pos'   => $position,
			'dev'   => array_values( array_intersect( array_map( 'strval', $chosen ), array_keys( $this->devices() ) ) ),
			'scope' => isset( $settings['wea_sticky_scope'] ) && 'parent' === $settings['wea_sticky_scope'] ? 'parent' : 'page',
			'hide'  => ( 'top' === $position && isset( $settings['wea_sticky_hide'] ) && 'yes' === $settings['wea_sticky_hide'] ) ? 1 : 0,
		];

		// Escaped by Elementor when the attribute string prints.
		$element->add_render_attribute( '_wrapper', 'data-wea-sticky', wp_json_encode( $config ) );

		$this->enqueue();
	}

	/**
	 * Check every builder document before it prints, so the script also loads when
	 * Elementor serves the markup from its element cache.
	 *
	 * @param mixed $data Elements data.
	 * @return mixed Unchanged data.
	 */
	public function scan_builder_content( $data ) {
		if ( is_array( $data ) && ! wp_script_is( Assets::feature_handle( self::SLUG ), 'enqueued' ) && $this->data_uses_sticky( $data ) ) {
			$this->enqueue();
		}
		return $data;
	}

	/**
	 * Does any element in the tree have sticky switched on?
	 *
	 * @param array $elements Elementor elements data.
	 * @return bool
	 */
	private function data_uses_sticky( array $elements ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'][ self::ENABLE ] ) && 'yes' === $element['settings'][ self::ENABLE ] ) {
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->data_uses_sticky( $element['elements'] ) ) {
				return true;
			}
		}
		return false;
	}
}
