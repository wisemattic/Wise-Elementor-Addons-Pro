<?php
/**
 * Horizontal Scroll: the direct children of a container (or the columns of a
 * section) become panels in one row. While the element is pinned, scrolling
 * down slides the row sideways.
 *
 * No markup is moved. The panels are `position: sticky` inside the element, the
 * row gets the height the slide needs, and script.js sets each panel's
 * `translate` from the scroll position. So the Elementor editor keeps working
 * on the same DOM (drag and drop, inline editing) while the effect runs.
 *
 * Controls write CSS custom properties through `selectors`. The Layout control
 * is responsive, so Elementor's own breakpoints decide where the effect runs
 * (default: desktop and tablet; phones stack). script.js reads the computed
 * value, so custom breakpoints work too.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Plugin;
use WiseAddons\Assets;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Horizontal Scroll feature.
 */
class Horizontal_Scroll extends Feature_Base {

	const SLUG = 'horizontal-scroll';

	/**
	 * Switch control ID. Permanent.
	 */
	const CONTROL = 'wea_hs_enable';

	/**
	 * Hooks: Advanced tab of containers and sections.
	 */
	protected function init() {
		add_action( 'elementor/element/container/section_layout/after_section_end', [ $this, 'register_controls' ] );
		add_action( 'elementor/element/section/section_advanced/after_section_end', [ $this, 'register_controls' ] );

		add_action( 'elementor/frontend/before_render', [ $this, 'before_render' ] );
		add_filter( 'elementor/frontend/builder_content_data', [ $this, 'scan_builder_content' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_for_current_document' ], 20 );
		add_action( 'elementor/preview/enqueue_styles', [ $this, 'enqueue' ] );
		add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Load the stylesheet and script (once).
	 */
	public function enqueue() {
		$this->enqueue_style();
		$this->enqueue_script();
	}

	/**
	 * Put the stylesheet in <head> when the main document uses the effect, so the
	 * row is laid out before the first paint.
	 */
	public function enqueue_for_current_document() {
		if ( ! is_singular() ) {
			return;
		}

		$document = Plugin::$instance->documents->get_doc_for_frontend( get_the_ID() );

		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return;
		}

		$data = $document->get_elements_data();

		if ( is_array( $data ) && $this->data_uses_effect( $data ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Check every builder document (page, theme templates, popups) before it prints.
	 * Runs even when Elementor serves the document from its element cache.
	 *
	 * @param mixed $data Elements data.
	 * @return mixed Unchanged data.
	 */
	public function scan_builder_content( $data ) {
		if ( is_array( $data ) && ! wp_script_is( Assets::feature_handle( self::SLUG ), 'enqueued' ) && $this->data_uses_effect( $data ) ) {
			$this->enqueue();
		}
		return $data;
	}

	/**
	 * Does any container or section in the tree have the effect switched on?
	 *
	 * @param array $elements Elementor elements data.
	 * @return bool
	 */
	private function data_uses_effect( array $elements ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['settings'][ self::CONTROL ] ) && 'yes' === $element['settings'][ self::CONTROL ] ) {
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->data_uses_effect( $element['elements'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load the assets for elements that use the effect.
	 *
	 * @param \Elementor\Element_Base $element Element about to render.
	 */
	public function before_render( $element ) {
		if ( ! in_array( $element->get_type(), [ 'container', 'section' ], true ) ) {
			return;
		}
		if ( 'yes' === $element->get_settings( self::CONTROL ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Advanced tab section.
	 *
	 * @param \Elementor\Element_Base $element Container or section.
	 */
	public function register_controls( $element ) {
		$is_section = 'section' === $element->get_type();

		$element->start_controls_section(
			'wea_hs_section',
			[
				'label' => esc_html__( 'Wise Horizontal Scroll', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->add_control(
			self::CONTROL,
			[
				'label'        => esc_html__( 'Horizontal Scroll', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'prefix_class' => 'wea-hs-',
				'description'  => $is_section ?
					esc_html__( 'The columns become panels in one row. The section pins to the screen and scrolling down slides the panels sideways.', 'wise-addons-for-elementor-pro' ) :
					esc_html__( 'The elements inside this container become panels in one row. The container pins to the screen and scrolling down slides the panels sideways.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$on = [ self::CONTROL => 'yes' ];

		$element->add_responsive_control(
			'wea_hs_mode',
			[
				'label'          => esc_html__( 'Layout', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => 'scroll',
				'tablet_default' => 'scroll',
				'mobile_default' => 'stack',
				'options'        => [
					'scroll' => esc_html__( 'Horizontal scroll', 'wise-addons-for-elementor-pro' ),
					'swipe'  => esc_html__( 'Swipe row', 'wise-addons-for-elementor-pro' ),
					'stack'  => esc_html__( 'Normal (stacked)', 'wise-addons-for-elementor-pro' ),
				],
				'description'    => esc_html__( 'Pick per device. Swipe row is a native sideways scroll, handy on phones. Visitors who ask for reduced motion get the normal layout.', 'wise-addons-for-elementor-pro' ),
				'condition'      => $on,
				'selectors'      => [ '{{WRAPPER}}' => '--wea-hs-mode: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_hs_width',
			[
				'label'                => esc_html__( 'Panel Width', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'full',
				'separator'            => 'before',
				'options'              => [
					'full'   => esc_html__( 'Full width', 'wise-addons-for-elementor-pro' ),
					'auto'   => esc_html__( 'Element width', 'wise-addons-for-elementor-pro' ),
					'custom' => esc_html__( 'Custom', 'wise-addons-for-elementor-pro' ),
				],
				'selectors_dictionary' => [
					'full'   => '100%',
					'auto'   => 'auto',
					'custom' => 'var(--wea-hs-pw-custom, 80%)',
				],
				'condition'            => $on,
				'selectors'            => [ '{{WRAPPER}}' => '--wea-hs-pw: {{VALUE}};' ],
			]
		);

		$element->add_responsive_control(
			'wea_hs_width_custom',
			[
				'label'      => esc_html__( 'Width', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ '%', 'px', 'vw', 'custom' ],
				'range'      => [
					'%'  => [
						'min' => 10,
						'max' => 200,
					],
					'px' => [
						'min' => 100,
						'max' => 2400,
					],
					'vw' => [
						'min' => 10,
						'max' => 150,
					],
				],
				'default'    => [
					'unit' => '%',
					'size' => 80,
				],
				'condition'  => $on + [ 'wea_hs_width' => 'custom' ],
				'selectors'  => [ '{{WRAPPER}}' => '--wea-hs-pw-custom: {{SIZE}}{{UNIT}};' ],
			]
		);

		$element->add_responsive_control(
			'wea_hs_gap',
			[
				'label'       => esc_html__( 'Gap', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'range'       => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'description' => $is_section ? '' : esc_html__( 'Empty: the container gap.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $on,
				'selectors'   => [ '{{WRAPPER}}' => '--wea-hs-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$element->add_control(
			'wea_hs_speed',
			[
				'label'       => esc_html__( 'Scroll Speed', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'range'       => [
					'px' => [
						'min'  => 0.25,
						'max'  => 4,
						'step' => 0.05,
					],
				],
				'default'     => [ 'size' => 1 ],
				'separator'   => 'before',
				'description' => esc_html__( 'Above 1 the row moves faster than the page and the pinned part is shorter.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $on,
				'selectors'   => [ '{{WRAPPER}}' => '--wea-hs-speed: {{SIZE}};' ],
			]
		);

		$element->add_responsive_control(
			'wea_hs_offset',
			[
				'label'       => esc_html__( 'Top Offset', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px' ],
				'range'       => [
					'px' => [
						'min' => 0,
						'max' => 300,
					],
				],
				'description' => esc_html__( 'Room for a sticky header. The WordPress toolbar is added on its own.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $on,
				'selectors'   => [ '{{WRAPPER}}' => '--wea-hs-offset: {{SIZE}};' ],
			]
		);

		$element->add_control(
			'wea_hs_snap',
			[
				'label'        => esc_html__( 'Snap to Panels', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'condition'    => $on,
				'selectors'    => [ '{{WRAPPER}}' => '--wea-hs-snap: x mandatory;' ],
			]
		);

		$element->add_control(
			'wea_hs_progress',
			[
				'label'        => esc_html__( 'Progress Bar', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'separator'    => 'before',
				'condition'    => $on,
				'selectors'    => [ '{{WRAPPER}}' => '--wea-hs-bar: on;' ],
			]
		);

		$bar = $on + [ 'wea_hs_progress' => 'yes' ];

		$element->add_control(
			'wea_hs_progress_color',
			[
				'label'     => esc_html__( 'Bar Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [ 'default' => Global_Colors::COLOR_ACCENT ],
				'condition' => $bar,
				'selectors' => [ '{{WRAPPER}}' => '--wea-hs-bar-color: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_hs_progress_track',
			[
				'label'     => esc_html__( 'Track Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => $bar,
				'selectors' => [ '{{WRAPPER}}' => '--wea-hs-bar-bg: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_hs_progress_height',
			[
				'label'      => esc_html__( 'Bar Height', 'wise-addons-for-elementor-pro' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 20,
					],
				],
				'condition'  => $bar,
				'selectors'  => [ '{{WRAPPER}}' => '--wea-hs-bar-h: {{SIZE}};' ],
			]
		);

		$element->add_control(
			'wea_hs_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Keep each panel within the screen height. Pinning needs every parent element to keep Overflow on Default.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
				'separator'       => 'before',
				'condition'       => $on,
			]
		);

		$element->end_controls_section();
	}
}
