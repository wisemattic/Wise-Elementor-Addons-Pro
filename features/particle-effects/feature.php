<?php
/**
 * Particle Effects: an animated canvas of particles behind (or in front of) the
 * content of a container or section.
 *
 * Every control writes a CSS custom property on the element through `selectors`.
 * script.js reads those properties with getComputedStyle(), so:
 * - responsive values (particle count) follow Elementor's own breakpoints,
 * - global colors arrive already resolved,
 * - the editor preview updates the moment a control changes,
 * and no settings have to be printed into the markup. Empty controls fall back
 * to the chosen preset in script.js.
 *
 * The stylesheet and script load only on pages where an element has the effect.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Plugin;
use WiseAddons\Assets;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Particle Effects feature.
 */
class Particle_Effects extends Feature_Base {

	const SLUG = 'particle-effects';

	/**
	 * Switch control ID. Permanent.
	 */
	const CONTROL = 'wea_pe_enable';

	/**
	 * Hooks.
	 */
	protected function init() {
		foreach ( [ 'container', 'section' ] as $type ) {
			add_action( "elementor/element/{$type}/section_background_overlay/after_section_end", [ $this, 'register_controls' ] );
		}

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
	 * Put the stylesheet in <head> when the main document uses the effect.
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
	 * Slider definition shared by the plain number sliders.
	 *
	 * @param string $label     Label.
	 * @param float  $min       Minimum.
	 * @param float  $max       Maximum.
	 * @param float  $step      Step.
	 * @param string $var       Custom property (without --wea-pe-).
	 * @param array  $condition Condition.
	 * @param string $unit      Unit appended to the value ('' or 'px').
	 * @return array
	 */
	private function slider( $label, $min, $max, $step, $var, array $condition, $unit = '' ) {
		return [
			'label'       => $label,
			'type'        => Controls_Manager::SLIDER,
			'range'       => [
				'px' => [
					'min'  => $min,
					'max'  => $max,
					'step' => $step,
				],
			],
			'placeholder' => esc_html__( 'Preset', 'wise-addons-for-elementor-pro' ),
			'condition'   => $condition,
			'selectors'   => [ '{{WRAPPER}}' => '--wea-pe-' . $var . ': {{SIZE}}' . $unit . ';' ],
		];
	}

	/**
	 * Style tab section, after Background Overlay.
	 *
	 * @param \Elementor\Element_Base $element Container or section.
	 */
	public function register_controls( $element ) {
		$element->start_controls_section(
			'wea_pe_section',
			[
				'label' => esc_html__( 'Wise Particle Effects', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$element->add_control(
			self::CONTROL,
			[
				'label'        => esc_html__( 'Particles', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'prefix_class' => 'wea-pe-',
				'description'  => esc_html__( 'Draws moving particles over the background. They pause off-screen, and visitors who ask for reduced motion see them still.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$on = [ self::CONTROL => 'yes' ];

		$element->add_control(
			'wea_pe_preset',
			[
				'label'     => esc_html__( 'Preset', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'network',
				'options'   => [
					'network'  => esc_html__( 'Network (linked dots)', 'wise-addons-for-elementor-pro' ),
					'bubbles'  => esc_html__( 'Bubbles', 'wise-addons-for-elementor-pro' ),
					'snow'     => esc_html__( 'Snow', 'wise-addons-for-elementor-pro' ),
					'stars'    => esc_html__( 'Twinkling stars', 'wise-addons-for-elementor-pro' ),
					'confetti' => esc_html__( 'Confetti', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $on,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-preset: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_pe_preset_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Each preset sets its own look. Leave a control empty to keep the preset value.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => $on,
			]
		);

		$count                = $this->slider( esc_html__( 'Particle Count', 'wise-addons-for-elementor-pro' ), 0, 300, 1, 'count', $on );
		$count['separator']   = 'before';
		$count['description'] = esc_html__( 'Empty: set by the preset and scaled to the element size, so phones get fewer. 0 turns particles off on that device.', 'wise-addons-for-elementor-pro' );
		$element->add_responsive_control( 'wea_pe_count', $count );

		$element->add_control(
			'wea_pe_colors_heading',
			[
				'label'     => esc_html__( 'Colors', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $on,
			]
		);

		foreach ( [ 1, 2, 3 ] as $i ) {
			$element->add_control(
				'wea_pe_color_' . $i,
				[
					/* translators: %d: color number. */
					'label'     => sprintf( esc_html__( 'Color %d', 'wise-addons-for-elementor-pro' ), $i ),
					'type'      => Controls_Manager::COLOR,
					'condition' => $on,
					'selectors' => [ '{{WRAPPER}}' => '--wea-pe-c' . $i . ': {{VALUE}};' ],
				]
			);
		}

		$element->add_control(
			'wea_pe_shape',
			[
				'label'     => esc_html__( 'Shape', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'separator' => 'before',
				'options'   => [
					''         => esc_html__( 'Preset', 'wise-addons-for-elementor-pro' ),
					'circle'   => esc_html__( 'Circle', 'wise-addons-for-elementor-pro' ),
					'square'   => esc_html__( 'Square', 'wise-addons-for-elementor-pro' ),
					'triangle' => esc_html__( 'Triangle', 'wise-addons-for-elementor-pro' ),
					'star'     => esc_html__( 'Star', 'wise-addons-for-elementor-pro' ),
					'confetti' => esc_html__( 'Mixed (confetti)', 'wise-addons-for-elementor-pro' ),
					'image'    => esc_html__( 'Image', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $on,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-shape: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_pe_image',
			[
				'label'       => esc_html__( 'Image', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => [ 'image', 'svg' ],
				'description' => esc_html__( 'A small PNG or SVG from this site works best. Colors do not apply to images.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $on + [ 'wea_pe_shape' => 'image' ],
				'selectors'   => [ '{{WRAPPER}}' => '--wea-pe-img: url("{{URL}}");' ],
			]
		);

		$element->add_control( 'wea_pe_size_min', $this->slider( esc_html__( 'Min Size', 'wise-addons-for-elementor-pro' ), 0.5, 80, 0.5, 'size-min', $on ) );
		$element->add_control( 'wea_pe_size_max', $this->slider( esc_html__( 'Max Size', 'wise-addons-for-elementor-pro' ), 0.5, 120, 0.5, 'size-max', $on ) );
		$element->add_control( 'wea_pe_opacity', $this->slider( esc_html__( 'Opacity', 'wise-addons-for-elementor-pro' ), 0.05, 1, 0.05, 'opacity', $on ) );

		$speed                = $this->slider( esc_html__( 'Speed', 'wise-addons-for-elementor-pro' ), 0, 5, 0.1, 'speed', $on );
		$speed['separator']   = 'before';
		$speed['description'] = esc_html__( '1 is the preset speed, 0 stops the movement.', 'wise-addons-for-elementor-pro' );
		$element->add_control( 'wea_pe_speed', $speed );

		$element->add_control(
			'wea_pe_direction',
			[
				'label'     => esc_html__( 'Direction', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''             => esc_html__( 'Preset', 'wise-addons-for-elementor-pro' ),
					'none'         => esc_html__( 'Random', 'wise-addons-for-elementor-pro' ),
					'top'          => esc_html__( 'Up', 'wise-addons-for-elementor-pro' ),
					'top-right'    => esc_html__( 'Up right', 'wise-addons-for-elementor-pro' ),
					'right'        => esc_html__( 'Right', 'wise-addons-for-elementor-pro' ),
					'bottom-right' => esc_html__( 'Down right', 'wise-addons-for-elementor-pro' ),
					'bottom'       => esc_html__( 'Down', 'wise-addons-for-elementor-pro' ),
					'bottom-left'  => esc_html__( 'Down left', 'wise-addons-for-elementor-pro' ),
					'left'         => esc_html__( 'Left', 'wise-addons-for-elementor-pro' ),
					'top-left'     => esc_html__( 'Up left', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $on,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-dir: {{VALUE}};' ],
			]
		);

		/* Links (Network) */
		$links = $on + [ 'wea_pe_preset' => 'network' ];

		$element->add_control(
			'wea_pe_links_heading',
			[
				'label'     => esc_html__( 'Link Lines', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $links,
			]
		);

		$element->add_control( 'wea_pe_link_distance', $this->slider( esc_html__( 'Distance', 'wise-addons-for-elementor-pro' ), 0, 400, 5, 'link-dist', $links ) );

		$element->add_control(
			'wea_pe_link_color',
			[
				'label'     => esc_html__( 'Color', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => $links,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-link-color: {{VALUE}};' ],
			]
		);

		$element->add_control( 'wea_pe_link_opacity', $this->slider( esc_html__( 'Opacity', 'wise-addons-for-elementor-pro' ), 0, 1, 0.05, 'link-opacity', $links ) );
		$element->add_control( 'wea_pe_link_width', $this->slider( esc_html__( 'Width', 'wise-addons-for-elementor-pro' ), 0.25, 6, 0.25, 'link-width', $links ) );

		/* Interactivity */
		$element->add_control(
			'wea_pe_hover',
			[
				'label'     => esc_html__( 'On Hover', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'none',
				'separator' => 'before',
				'options'   => [
					'none'    => esc_html__( 'Nothing', 'wise-addons-for-elementor-pro' ),
					'grab'    => esc_html__( 'Grab (link to pointer)', 'wise-addons-for-elementor-pro' ),
					'repulse' => esc_html__( 'Repulse (push away)', 'wise-addons-for-elementor-pro' ),
					'bubble'  => esc_html__( 'Bubble (grow)', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $on,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-hover: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_pe_hover_radius',
			$this->slider(
				esc_html__( 'Hover Radius', 'wise-addons-for-elementor-pro' ),
				20,
				400,
				5,
				'radius',
				$on + [ 'wea_pe_hover!' => 'none' ]
			)
		);

		$element->add_control(
			'wea_pe_click',
			[
				'label'     => esc_html__( 'On Click', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'none',
				'options'   => [
					'none' => esc_html__( 'Nothing', 'wise-addons-for-elementor-pro' ),
					'push' => esc_html__( 'Add particles', 'wise-addons-for-elementor-pro' ),
				],
				'condition' => $on,
				'selectors' => [ '{{WRAPPER}}' => '--wea-pe-click: {{VALUE}};' ],
			]
		);

		/* Layer and devices */
		$element->add_control(
			'wea_pe_layer',
			[
				'label'                => esc_html__( 'Layer', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'default'              => 'behind',
				'toggle'               => false,
				'separator'            => 'before',
				'options'              => [
					'behind' => [
						'title' => esc_html__( 'Behind the content', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-bottom',
					],
					'front'  => [
						'title' => esc_html__( 'In front of the content', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-v-align-top',
					],
				],
				'selectors_dictionary' => [
					'behind' => '0',
					'front'  => '20',
				],
				'condition'            => $on,
				'selectors'            => [ '{{WRAPPER}}' => '--wea-pe-z: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_pe_z',
			[
				'label'       => esc_html__( 'Z-Index', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => -10,
				'max'         => 9999,
				'description' => esc_html__( 'Optional. Overrides the layer for unusual stacking.', 'wise-addons-for-elementor-pro' ),
				'condition'   => $on,
				'selectors'   => [ '{{WRAPPER}} > .wea-pe-canvas' => 'z-index: {{VALUE}};' ],
			]
		);

		$element->add_control(
			'wea_pe_mobile_off',
			[
				'label'        => esc_html__( 'Turn Off on Mobile', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'condition'    => $on,
				'selectors'    => [ '{{WRAPPER}}' => '--wea-pe-mobile: off;' ],
			]
		);

		$element->end_controls_section();
	}
}
