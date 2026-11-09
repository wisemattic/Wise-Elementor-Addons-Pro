<?php
/**
 * Display Conditions: show or hide any widget, container, section or column
 * depending on who is visiting, when, on which page, and how they got there.
 *
 * Rules are checked on the server through Elementor's should_render filters, so a
 * hidden element is never sent to the browser. In the editor and its preview every
 * element is rendered, and elements with conditions get a small badge instead.
 *
 * Elements that use conditions are kept out of Elementor's element cache, so a
 * cached copy never freezes the result. A full-page cache is outside Elementor's
 * control; the panel explains that.
 *
 * The rules live in rules.php and can be extended with the
 * `wiseaddons_pro/display_conditions/rules` filter.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;
use Elementor\Element_Base;
use Elementor\Plugin;
use Elementor\Repeater;
use WiseAddons\Feature_Base;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/rules.php';

/**
 * Display Conditions feature.
 */
class Display_Conditions extends Feature_Base {

	const SLUG = 'display-conditions';

	/**
	 * Control IDs. Permanent.
	 */
	const ENABLE = 'wea_dc_enable';
	const MATCH  = 'wea_dc_match';
	const ACTION = 'wea_dc_action';
	const RULES  = 'wea_dc_rules';

	/**
	 * Rule definitions after the filter, keyed by slug.
	 *
	 * @var array|null
	 */
	private $rules = null;

	/**
	 * Repeater fields, built once and shared by every element type.
	 *
	 * @var array|null
	 */
	private $fields = null;

	/**
	 * Whether this request is the editor or its preview.
	 *
	 * @var bool|null
	 */
	private $editor = null;

	/**
	 * Hooks.
	 */
	protected function init() {
		$this->add_advanced_section( [ $this, 'register_controls' ] );

		foreach ( [ 'widget', 'container', 'section', 'column' ] as $type ) {
			add_filter( "elementor/frontend/{$type}/should_render", [ $this, 'should_render' ], 10, 2 );
		}

		// Keep elements with conditions out of Elementor's element cache.
		add_filter( 'elementor/element/is_dynamic_content', [ $this, 'is_dynamic_content' ], 10, 2 );
		add_filter( 'elementor/frontend/builder_content_data', [ $this, 'skip_element_cache' ] );

		// The rules repeater has its own control type (see control.php).
		add_action( 'elementor/controls/register', [ $this, 'register_control_type' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'editor_scripts' ] );

		// The badge that marks elements with conditions, in the editor preview only.
		add_action( 'elementor/preview/enqueue_styles', [ $this, 'enqueue_preview_style' ] );
	}

	/**
	 * Register the rules repeater control type.
	 *
	 * @param \Elementor\Controls_Manager $manager Controls manager.
	 */
	public function register_control_type( $manager ) {
		require_once __DIR__ . '/control.php';
		$manager->register( new Display_Conditions_Rules_Control() );
	}

	/**
	 * Editor: draw the rules control with Elementor's own repeater view.
	 */
	public function editor_scripts() {
		wp_add_inline_script(
			'elementor-editor',
			'window.addEventListener("elementor/init",function(){var e=window.elementor;if(e&&e.modules&&e.modules.controls&&e.modules.controls.Repeater){e.addControlView("wea-dc-rules",e.modules.controls.Repeater);}});'
		);
	}

	/**
	 * Rule definitions: built-in rules plus whatever the filter adds.
	 *
	 * @return array
	 */
	public function rules() {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		/**
		 * Filter the Display Conditions rules.
		 *
		 * Each rule: [ 'label' => string, 'group' => string, 'fields' => array of Elementor
		 * control args keyed by a unique field ID, 'callback' => callable( array $rule,
		 * Element_Base $element ): bool|null ]. See rules.php for the full contract.
		 *
		 * @param array $rules Rules keyed by slug.
		 */
		$rules = apply_filters( 'wiseaddons_pro/display_conditions/rules', Display_Conditions_Rules::all() );

		$this->rules = [];
		foreach ( (array) $rules as $slug => $rule ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $rule ) || empty( $rule['label'] ) || empty( $rule['callback'] ) || ! is_callable( $rule['callback'] ) ) {
				continue;
			}
			$rule['fields']       = isset( $rule['fields'] ) && is_array( $rule['fields'] ) ? $rule['fields'] : [];
			$rule['group']        = isset( $rule['group'] ) ? (string) $rule['group'] : '';
			$this->rules[ $slug ] = $rule;
		}

		return $this->rules;
	}

	/**
	 * Advanced tab section.
	 *
	 * @param Element_Base $element Widget, container, section or column.
	 */
	public function register_controls( $element ) {
		$element->start_controls_section(
			'wea_section_display_conditions',
			[
				'label' => esc_html__( 'Wise Display Conditions', 'wise-addons-for-elementor-pro' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->add_control(
			self::ENABLE,
			[
				'label'          => esc_html__( 'Display Conditions', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SWITCHER,
				'return_value'   => 'yes',
				'prefix_class'   => 'wea-dc-',
				'style_transfer' => false,
			]
		);

		$on = [ self::ENABLE => 'yes' ];

		$element->add_control(
			self::ACTION,
			[
				'label'          => esc_html__( 'Action', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::CHOOSE,
				'default'        => 'show',
				'toggle'         => false,
				'options'        => [
					'show' => [
						'title' => esc_html__( 'Show', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-preview-medium',
					],
					'hide' => [
						'title' => esc_html__( 'Hide', 'wise-addons-for-elementor-pro' ),
						'icon'  => 'eicon-ban',
					],
				],
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			self::MATCH,
			[
				'label'          => esc_html__( 'When', 'wise-addons-for-elementor-pro' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => 'all',
				'options'        => [
					'all' => esc_html__( 'All rules match', 'wise-addons-for-elementor-pro' ),
					'any' => esc_html__( 'Any rule matches', 'wise-addons-for-elementor-pro' ),
				],
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			self::RULES,
			[
				'label'          => esc_html__( 'Rules', 'wise-addons-for-elementor-pro' ),
				'type'           => 'wea-dc-rules',
				'fields'         => $this->repeater_fields(),
				'default'        => [],
				'prevent_empty'  => false,
				'title_field'    => $this->title_field(),
				'condition'      => $on,
				'render_type'    => 'none',
				'style_transfer' => false,
			]
		);

		$element->add_control(
			'wea_dc_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Rules are checked on the server each time the page is built. The editor always shows the element and marks it with a badge. Rules without a value are ignored. With a full-page cache (cache plugin, host or CDN), rules for date, time, URL parameter, referrer, device, browser and operating system apply to the cached copy, so visitors see the result of whoever loaded it first until the cache refreshes. Login and role rules are fine, because logged-in users normally skip that cache.', 'wise-addons-for-elementor-pro' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => $on,
			]
		);

		$element->end_controls_section();
	}

	/**
	 * Repeater fields: rule picker, "is / is not", then every rule's own fields.
	 *
	 * @return array
	 */
	private function repeater_fields() {
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		$repeater = new Repeater();
		$rules    = $this->rules();
		$labels   = Display_Conditions_Rules::groups();
		$groups   = [];

		foreach ( $rules as $slug => $rule ) {
			$group = $rule['group'];
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = [
					'label'   => isset( $labels[ $group ] ) ? $labels[ $group ] : ( '' !== $group ? esc_html( $group ) : esc_html__( 'Other', 'wise-addons-for-elementor-pro' ) ),
					'options' => [],
				];
			}
			$groups[ $group ]['options'][ $slug ] = esc_html( wp_strip_all_tags( $rule['label'] ) );
		}

		// Known groups first, in their own order.
		$ordered = [];
		foreach ( array_keys( $labels ) as $group ) {
			if ( isset( $groups[ $group ] ) ) {
				$ordered[] = $groups[ $group ];
				unset( $groups[ $group ] );
			}
		}
		$ordered = array_merge( $ordered, array_values( $groups ) );

		$repeater->add_control(
			'rule',
			[
				'label'       => esc_html__( 'Rule', 'wise-addons-for-elementor-pro' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => isset( $rules['login'] ) ? 'login' : (string) key( $rules ),
				'groups'      => $ordered,
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'is',
			[
				'label'   => esc_html__( 'Condition', 'wise-addons-for-elementor-pro' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'is',
				'options' => [
					'is'  => esc_html__( 'Is', 'wise-addons-for-elementor-pro' ),
					'not' => esc_html__( 'Is not', 'wise-addons-for-elementor-pro' ),
				],
			]
		);

		$used = [ 'rule' => true, 'is' => true ];

		foreach ( $rules as $slug => $rule ) {
			foreach ( $rule['fields'] as $id => $args ) {
				$id = sanitize_key( (string) $id );
				if ( '' === $id || isset( $used[ $id ] ) || ! is_array( $args ) || empty( $args['type'] ) ) {
					continue;
				}
				$used[ $id ] = true;

				$args['condition'] = array_merge(
					isset( $args['condition'] ) && is_array( $args['condition'] ) ? $args['condition'] : [],
					[ 'rule' => $slug ]
				);

				$repeater->add_control( $id, $args );
			}
		}

		$this->fields = $repeater->get_controls();

		return $this->fields;
	}

	/**
	 * Repeater row title: the rule's label, with "Not:" in front when negated.
	 *
	 * @return string Underscore template.
	 */
	private function title_field() {
		$labels = [];
		foreach ( $this->rules() as $slug => $rule ) {
			$labels[ $slug ] = esc_html( wp_strip_all_tags( $rule['label'] ) );
		}

		$map = wp_json_encode( $labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$not = wp_json_encode( esc_html__( 'Not:', 'wise-addons-for-elementor-pro' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		return '<# var weaDcLabels = ' . $map . '; #>'
			. '<# if ( "not" === is ) { #>{{{ ' . $not . ' }}} <# } #>'
			. '<# if ( weaDcLabels[ rule ] ) { #>{{{ weaDcLabels[ rule ] }}}<# } else { #>{{ rule }}<# } #>';
	}

	/**
	 * Decide whether an element prints on the live site.
	 *
	 * @param bool         $should_render Current decision.
	 * @param Element_Base $element       Element.
	 * @return bool
	 */
	public function should_render( $should_render, $element ) {
		if ( ! $should_render || ! $element instanceof Element_Base || ! $this->uses_conditions( $element->get_data( 'settings' ) ) ) {
			return $should_render;
		}

		if ( $this->is_editor() ) {
			return true;
		}

		return $this->is_visible( $element );
	}

	/**
	 * Evaluate an element's rules.
	 *
	 * @param Element_Base $element Element with conditions switched on.
	 * @return bool
	 */
	public function is_visible( Element_Base $element ) {
		$settings = $element->get_settings_for_display();
		$items    = isset( $settings[ self::RULES ] ) && is_array( $settings[ self::RULES ] ) ? $settings[ self::RULES ] : [];
		$any      = isset( $settings[ self::MATCH ] ) && 'any' === $settings[ self::MATCH ];
		$show     = ! ( isset( $settings[ self::ACTION ] ) && 'hide' === $settings[ self::ACTION ] );

		$checked = 0;
		$matched = ! $any; // ALL starts true and looks for a miss; ANY starts false and looks for a hit.

		foreach ( $items as $item ) {
			$result = is_array( $item ) ? $this->check_rule( $item, $element ) : null;
			if ( null === $result ) {
				continue;
			}

			++$checked;

			if ( $any && $result ) {
				$matched = true;
				break;
			}
			if ( ! $any && ! $result ) {
				$matched = false;
				break;
			}
		}

		// No usable rules: leave the element alone.
		$visible = $checked ? ( $show ? $matched : ! $matched ) : true;

		/**
		 * Filter the final visibility of an element with display conditions.
		 *
		 * @param bool         $visible Whether the element prints.
		 * @param Element_Base $element Element.
		 * @param bool         $matched Whether the rules matched (before Show/Hide).
		 */
		return (bool) apply_filters( 'wiseaddons_pro/display_conditions/visible', $visible, $element, $checked ? $matched : null );
	}

	/**
	 * Run one rule.
	 *
	 * @param array        $item    Repeater item.
	 * @param Element_Base $element Element.
	 * @return bool|null Null when the rule is unknown or incomplete.
	 */
	private function check_rule( array $item, Element_Base $element ) {
		$rules = $this->rules();
		$slug  = isset( $item['rule'] ) && is_string( $item['rule'] ) ? $item['rule'] : '';

		if ( ! isset( $rules[ $slug ] ) ) {
			return null;
		}

		$result = call_user_func( $rules[ $slug ]['callback'], $item, $element );

		if ( null === $result ) {
			return null;
		}

		$result = (bool) $result;

		return ( isset( $item['is'] ) && 'not' === $item['is'] ) ? ! $result : $result;
	}

	/**
	 * Does raw element settings data have conditions switched on?
	 *
	 * @param mixed $settings Raw settings.
	 * @return bool
	 */
	private function uses_conditions( $settings ) {
		return is_array( $settings ) && isset( $settings[ self::ENABLE ] ) && 'yes' === $settings[ self::ENABLE ];
	}

	/**
	 * Editor, editor preview, or an editor AJAX render.
	 *
	 * @return bool
	 */
	private function is_editor() {
		if ( null !== $this->editor ) {
			return $this->editor;
		}

		$plugin = Plugin::$instance;
		$editor = ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() )
			|| ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );

		// Theme templates printed around the previewed document.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks for presence.
		if ( ! $editor && isset( $_GET['elementor-preview'] ) && current_user_can( 'edit_posts' ) ) {
			$editor = true;
		}

		// Don't cache "false" before the main query is set up.
		if ( $editor || did_action( 'wp' ) ) {
			$this->editor = $editor;
		}

		return $editor;
	}

	/**
	 * Make Elementor render elements with conditions fresh on every request.
	 *
	 * @param bool         $is_dynamic Current value.
	 * @param array        $raw_data   Element raw data.
	 * @return bool
	 */
	public function is_dynamic_content( $is_dynamic, $raw_data = [] ) {
		if ( ! $is_dynamic && is_array( $raw_data ) && $this->uses_conditions( $raw_data['settings'] ?? null ) ) {
			return true;
		}
		return $is_dynamic;
	}

	/**
	 * Widgets can be set to "Cache: Active" by hand, which Elementor checks before the
	 * dynamic-content filter. Turn the element cache off for elements with conditions
	 * before Elementor prints the document.
	 *
	 * @param mixed $data Elements data.
	 * @return mixed
	 */
	public function skip_element_cache( $data ) {
		if ( ! is_array( $data ) || 'disable' === get_option( 'elementor_element_cache_ttl', '' ) ) {
			return $data;
		}
		return $this->mark_uncached( $data );
	}

	/**
	 * Walk the element tree and set `_element_cache` to "Inactive" where conditions are on.
	 *
	 * @param array $elements Elements data.
	 * @return array
	 */
	private function mark_uncached( array $elements ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( $this->uses_conditions( $element['settings'] ?? null ) ) {
				$elements[ $index ]['settings']['_element_cache'] = 'yes';
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $index ]['elements'] = $this->mark_uncached( $element['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Badge styles for the editor preview.
	 */
	public function enqueue_preview_style() {
		$this->enqueue_style();
	}
}
