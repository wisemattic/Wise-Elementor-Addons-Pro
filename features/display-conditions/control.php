<?php
/**
 * Repeater control for the Display Conditions rules.
 *
 * It is Elementor's own repeater under its own type name. Elementor still treats a
 * widget with exactly one control of type "repeater" as a legacy widget and points
 * the editor's `container.children` at that repeater (with a deprecation warning on
 * every read). Adding a plain repeater to every widget would trigger that on all
 * widgets that have no repeater of their own. A separate type keeps the repeater
 * behaviour (PHP checks `instanceof Control_Repeater`, the editor checks
 * `is_repeater`) without that side effect. The editor view is mapped to Elementor's
 * repeater view in Display_Conditions::editor_scripts().
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Control_Repeater;

defined( 'ABSPATH' ) || exit;

/**
 * Rules repeater control.
 */
class Display_Conditions_Rules_Control extends Control_Repeater {

	/**
	 * Control type.
	 */
	const TYPE = 'wea-dc-rules';

	/**
	 * Control type.
	 *
	 * @return string
	 */
	public function get_type() {
		return self::TYPE;
	}

	/**
	 * Same settings as the repeater, plus its class so the panel styles it the same way.
	 *
	 * @return array
	 */
	protected function get_default_settings() {
		return array_merge(
			parent::get_default_settings(),
			[ 'classes' => 'elementor-control-type-repeater' ]
		);
	}
}
