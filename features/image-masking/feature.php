<?php
/**
 * Image Masking: cut a widget, or only the images and videos inside it, into a shape.
 *
 * CSS only. Every control writes `mask-*` properties (with the `-webkit-` twins)
 * through `selectors`, so Elementor puts them in the page CSS and the editor
 * preview updates while the user picks a shape or drags a slider. No stylesheet
 * or script is loaded; the shape SVG is fetched by the browser only when a page
 * uses it.
 *
 * The built-in shapes live in shapes/*.svg. They use preserveAspectRatio="none",
 * so "Stretch" can fill any box, while "Contain" and "Cover" keep the shape's
 * own proportions from its viewBox.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Plugin;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Image Masking feature.
 */
class Image_Masking extends Feature_Base {

	const SLUG = 'image-masking';

	/**
	 * Hooks. Widgets only: masking a container would also clip the editor's handles.
	 */
	protected function init() {
		add_action( 'elementor/element/common/_section_style/after_section_end', [ $this, 'register_controls' ] );
	}

	/**
	 * Built-in shapes: file name (without .svg) => label.
	 *
	 * @return array
	 */
	public static function shapes() {
		return [
			'blob-soft'   => esc_html__( 'Soft Blob', 'wise-addons-for-elementor-pro' ),
			'blob-wavy'   => esc_html__( 'Wavy Blob', 'wise-addons-for-elementor-pro' ),
			'blob-pebble' => esc_html__( 'Pebble', 'wise-addons-for-elementor-pro' ),
			'circle'      => esc_html__( 'Circle', 'wise-addons-for-elementor-pro' ),
			'squircle'    => esc_html__( 'Squircle', 'wise-addons-for-elementor-pro' ),
			'arch'        => esc_html__( 'Arch', 'wise-addons-for-elementor-pro' ),
			'hexagon'     => esc_html__( 'Hexagon', 'wise-addons-for-elementor-pro' ),
			'triangle'    => esc_html__( 'Triangle', 'wise-addons-for-elementor-pro' ),
			'diamond'     => esc_html__( 'Diamond', 'wise-addons-for-elementor-pro' ),
			'star'        => esc_html__( 'Star', 'wise-addons-for-elementor-pro' ),
			'heart'       => esc_html__( 'Heart', 'wise-addons-for-elementor-pro' ),
			'badge'       => esc_html__( 'Badge', 'wise-addons-for-elementor-pro' ),
			'cloud'       => esc_html__( 'Cloud', 'wise-addons-for-elementor-pro' ),
			'wave'        => esc_html__( 'Wave', 'wise-addons-for-elementor-pro' ),
			'brush'       => esc_html__( 'Brush Stroke', 'wise-addons-for-elementor-pro' ),
		];
	}

	/**
	 * URL of a shape file.
	 *
	 * @param string $shape Shape key.
	 * @return string
	 */
	public static function shape_url( $shape ) {
		return plugin_dir_url( __FILE__ ) . 'shapes/' . $shape . '.svg';
	}

	/**
	 * Elements the mask is applied to. The target control adds `wea-im-to-widget`
	 * or `wea-im-to-media` to the wrapper, so only one half of the list matches.
	 *
	 * Media: images and videos. The Video widget's `.elementor-wrapper` holds the
	 * player and its image overlay, so it is masked as one box and the media
	 * inside it are skipped.
	 *
	 * @return string
	 */
	private function targets() {
		$media = '{{WRAPPER}}.wea-im-to-media';

		return implode(
			', ',
			[
				'{{WRAPPER}}.wea-im-to-widget',
				$media . ' img:not(.elementor-wrapper img)',
				$media . ' video:not(.elementor-wrapper video)',
				$media . ' iframe:not(.elementor-wrapper iframe)',
				$media . ' .elementor-wrapper',
			]
		);
	}

	/**
	 * Same declaration with and without the -webkit- prefix.
	 *
	 * @param string $property Property without "mask-" (image, size, position, repeat).
	 * @param string $value    CSS value.
	 * @return string
	 */
	private function decl( $property, $value ) {
		return '-webkit-mask-' . $property . ': ' . $value . '; mask-' . $property . ': ' . $value . ';';
	}

	/**
	 * `device_args` that repeat a condition for each breakpoint, so a responsive
	 * control shows when the same device's select has the matching value.
	 *
	 * @param string $key   Select control ID.
	 * @param string $value Value that shows the dependent control.
	 * @return array
	 */
	private function device_conditions( $key, $value ) {
		$args = [];

		foreach ( array_keys( Plugin::$instance->breakpoints->get_active_breakpoints() ) as $device ) {
			$args[ $device ] = [
				'condition' => [
					'wea_im_enable'        => 'yes',
					$key . '_' . $device => $value,
				],
			];
		}

		return $args;
	}

	/**
	 * Advanced tab section.
	 *
	 * @param \Elementor\Element_Base $element Widget.
	 */
	public function register_controls( $element ) {
		$targets = $this->targets();
		$on      = [ 'wea_im_enable' => 'yes' ];

		$element->start_controls_section(
			'wea_im_section',
			[
				'label' => esc_html__( 'Wise Image Masking', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->add_control(
			'wea_im_enable',
			[
				'label'        => esc_html__( 'Image Masking', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'prefix_class' => 'wea-im-',
				'description'  => esc_html__( 'Shows the element only inside a shape. Works best on Image, Image Box and Video widgets.', 'wise-addons-for-elementor-pro' ),
			]
		);

		$element->add_control(
			'wea_im_target',
			[
				'label'        => esc_html__( 'Apply To', 'wise-addons-for-elementor-pro' ),
				'type'         => Controls_Manager::SELECT,
				'default'      => 'widget',
				'options'      => [
					'widget' => esc_html__( 'Whole widget', 'wise-addons-for-elementor-pro' ),
					'media'  => esc_html__( 'Images and videos only', 'wise-addons-for-elementor-pro' ),
				],
				'prefix_class' => 'wea-im-to-',
				'condition'    => $on,
			]
		);

		$element->add_control(
			'wea_im_source',
			[
				'label'     => esc_html__( 'Mask', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'shape',
				'toggle'    => false,
				'options'   => [
					'shape' => [
						'title' => esc_html__( 'Built-in shape', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-star',
					],
					'image' => [
						'title' => esc_html__( 'Custom image (SVG or PNG)', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-image',
					],
				],
				'condition' => $on,
			]
		);

		$images     = [];
		$dictionary = [];
		foreach ( self::shapes() as $key => $label ) {
			$url              = esc_url( self::shape_url( $key ) );
			$images[ $key ]   = [
				'title' => $label,
				'image' => $url,
			];
			$dictionary[ $key ] = 'url("' . $url . '")';
		}

		$element->add_control(
			'wea_im_shape',
			[
				'label'                => esc_html__( 'Shape', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::VISUAL_CHOICE,
				'label_block'          => true,
				'columns'              => 5,
				'toggle'               => false,
				'default'              => 'blob-soft',
				'options'              => $images,
				'selectors_dictionary' => $dictionary,
				'selectors'            => [ $targets => $this->decl( 'image', '{{VALUE}}' ) ],
				'condition'            => $on + [ 'wea_im_source' => 'shape' ],
			]
		);

		$element->add_control(
			'wea_im_image',
			[
				'label'       => esc_html__( 'Mask Image', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => [ 'image', 'svg' ],
				'dynamic'     => [ 'active' => true ],
				'description' => esc_html__( 'Opaque parts of the image show the element, transparent parts hide it. Use an SVG or a PNG with transparency from this site.', 'wise-addons-for-elementor-pro' ),
				'selectors'   => [ $targets => $this->decl( 'image', 'url("{{URL}}")' ) ],
				'condition'   => $on + [ 'wea_im_source' => 'image' ],
			]
		);

		$element->add_responsive_control(
			'wea_im_size',
			[
				'label'                => esc_html__( 'Size', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'contain',
				'separator'            => 'before',
				'options'              => [
					'contain' => esc_html__( 'Contain (whole shape)', 'wise-addons-for-elementor-pro' ),
					'cover'   => esc_html__( 'Cover (fill, crop shape)', 'wise-addons-for-elementor-pro' ),
					'stretch' => esc_html__( 'Stretch to fit', 'wise-addons-for-elementor-pro' ),
					'custom'  => esc_html__( 'Custom', 'wise-addons-for-elementor-pro' ),
				],
				'selectors_dictionary' => [
					'contain' => 'contain',
					'cover'   => 'cover',
					'stretch' => '100% 100%',
					'custom'  => 'var(--wea-im-scale, 100%) auto',
				],
				'selectors'            => [ $targets => $this->decl( 'size', '{{VALUE}}' ) ],
				'condition'            => $on,
			]
		);

		$element->add_responsive_control(
			'wea_im_scale',
			[
				'label'       => esc_html__( 'Width', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ '%', 'px', 'vw', 'custom' ],
				'range'       => [
					'%'  => [
						'min' => 0,
						'max' => 300,
					],
					'px' => [
						'min' => 0,
						'max' => 1500,
					],
					'vw' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'default'     => [
					'unit' => '%',
					'size' => 100,
				],
				'selectors'   => [ '{{WRAPPER}}' => '--wea-im-scale: {{SIZE}}{{UNIT}};' ],
				'condition'   => $on + [ 'wea_im_size' => 'custom' ],
				'device_args' => $this->device_conditions( 'wea_im_size', 'custom' ),
			]
		);

		$element->add_responsive_control(
			'wea_im_position',
			[
				'label'                => esc_html__( 'Position', 'wise-addons-for-elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'center center',
				'options'              => [
					'center center' => esc_html__( 'Center Center', 'wise-addons-for-elementor-pro' ),
					'center left'   => esc_html__( 'Center Left', 'wise-addons-for-elementor-pro' ),
					'center right'  => esc_html__( 'Center Right', 'wise-addons-for-elementor-pro' ),
					'top center'    => esc_html__( 'Top Center', 'wise-addons-for-elementor-pro' ),
					'top left'      => esc_html__( 'Top Left', 'wise-addons-for-elementor-pro' ),
					'top right'     => esc_html__( 'Top Right', 'wise-addons-for-elementor-pro' ),
					'bottom center' => esc_html__( 'Bottom Center', 'wise-addons-for-elementor-pro' ),
					'bottom left'   => esc_html__( 'Bottom Left', 'wise-addons-for-elementor-pro' ),
					'bottom right'  => esc_html__( 'Bottom Right', 'wise-addons-for-elementor-pro' ),
					'custom'        => esc_html__( 'Custom', 'wise-addons-for-elementor-pro' ),
				],
				'selectors_dictionary' => [
					'custom' => 'var(--wea-im-x, 50%) var(--wea-im-y, 50%)',
				],
				'selectors'            => [ $targets => $this->decl( 'position', '{{VALUE}}' ) ],
				'condition'            => $on,
			]
		);

		foreach ( [ 'x' => esc_html__( 'X Position', 'wise-addons-for-elementor-pro' ), 'y' => esc_html__( 'Y Position', 'wise-addons-for-elementor-pro' ) ] as $axis => $label ) {
			$element->add_responsive_control(
				'wea_im_' . $axis,
				[
					'label'       => $label,
					'type'        => Controls_Manager::SLIDER,
					'size_units'  => [ '%', 'px', 'custom' ],
					'range'       => [
						'%'  => [
							'min' => -100,
							'max' => 200,
						],
						'px' => [
							'min' => -800,
							'max' => 800,
						],
					],
					'default'     => [
						'unit' => '%',
						'size' => 50,
					],
					'selectors'   => [ '{{WRAPPER}}' => '--wea-im-' . $axis . ': {{SIZE}}{{UNIT}};' ],
					'condition'   => $on + [ 'wea_im_position' => 'custom' ],
					'device_args' => $this->device_conditions( 'wea_im_position', 'custom' ),
				]
			);
		}

		$element->add_control(
			'wea_im_repeat',
			[
				'label'     => esc_html__( 'Repeat', 'wise-addons-for-elementor-pro' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'no-repeat',
				'options'   => [
					'no-repeat' => esc_html__( 'No repeat', 'wise-addons-for-elementor-pro' ),
					'repeat'    => esc_html__( 'Repeat', 'wise-addons-for-elementor-pro' ),
					'repeat-x'  => esc_html__( 'Repeat horizontally', 'wise-addons-for-elementor-pro' ),
					'repeat-y'  => esc_html__( 'Repeat vertically', 'wise-addons-for-elementor-pro' ),
					'space'     => esc_html__( 'Repeat with space', 'wise-addons-for-elementor-pro' ),
					'round'     => esc_html__( 'Repeat and fit', 'wise-addons-for-elementor-pro' ),
				],
				'selectors' => [ $targets => $this->decl( 'repeat', '{{VALUE}}' ) ],
				'condition' => $on,
			]
		);

		$element->end_controls_section();
	}
}
