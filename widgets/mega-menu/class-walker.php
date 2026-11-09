<?php
/**
 * Menu walker for the Mega Menu widget.
 *
 * Prints each menu item as a link plus, when it has a dropdown or a mega panel,
 * a separate toggle button that carries the ARIA state. Mega panels are left as
 * placeholders that the widget fills with the rendered template while printing.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Walker for wp_nav_menu().
 */
class Mega_Menu_Walker extends \Walker_Nav_Menu {

	/**
	 * Prefix for element IDs, unique per widget.
	 *
	 * @var string
	 */
	private $uid;

	/**
	 * Extras for top-level items, keyed by menu item ID.
	 * Keys: rid (repeater _id), panel (bool), width, align, icon (HTML), badge.
	 *
	 * @var array
	 */
	private $extras;

	/**
	 * Indicator markup printed inside every toggle button.
	 *
	 * @var string
	 */
	private $indicator;

	/**
	 * Item that opened each depth's sub-menu, keyed by depth.
	 *
	 * @var array
	 */
	private $parents = [];

	/**
	 * Constructor.
	 *
	 * @param string $uid       ID prefix.
	 * @param array  $extras    Extras for top-level items.
	 * @param string $indicator Indicator markup (static SVG).
	 */
	public function __construct( $uid, array $extras, $indicator ) {
		$this->uid       = (string) $uid;
		$this->extras    = $extras;
		$this->indicator = (string) $indicator;
	}

	/**
	 * Placeholder that marks where a mega panel's template goes.
	 *
	 * @param int $item_id Menu item ID.
	 * @return string
	 */
	public static function placeholder( $item_id ) {
		return '<!--wea-mm-panel:' . absint( $item_id ) . '-->';
	}

	/**
	 * ID of an item's sub-menu list.
	 *
	 * @param int $item_id Menu item ID.
	 * @return string
	 */
	private function sub_id( $item_id ) {
		return $this->uid . '-sub-' . absint( $item_id );
	}

	/**
	 * ID of an item's mega panel.
	 *
	 * @param int $item_id Menu item ID.
	 * @return string
	 */
	private function mega_id( $item_id ) {
		return $this->uid . '-mega-' . absint( $item_id );
	}

	/**
	 * Extras of a top-level item, or null.
	 *
	 * @param \WP_Post $item  Menu item.
	 * @param int      $depth Depth.
	 * @return array|null
	 */
	private function extras_for( $item, $depth ) {
		$id = isset( $item->ID ) ? (int) $item->ID : 0;
		return ( 0 === (int) $depth && isset( $this->extras[ $id ] ) ) ? $this->extras[ $id ] : null;
	}

	/**
	 * Start a sub-menu list.
	 *
	 * @param string    $output Output.
	 * @param int       $depth  Depth of the parent item.
	 * @param \stdClass $args   wp_nav_menu() arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$parent  = isset( $this->parents[ $depth ] ) ? $this->parents[ $depth ] : null;
		$classes = [ 'wea-mega-menu__sub', 'wea-mega-menu__sub--depth-' . ( (int) $depth + 1 ) ];
		$id      = $parent ? ' id="' . esc_attr( $this->sub_id( $parent->ID ) ) . '"' : '';

		$output .= '<ul class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $id . '>';
	}

	/**
	 * End a sub-menu list.
	 *
	 * @param string    $output Output.
	 * @param int       $depth  Depth of the parent item.
	 * @param \stdClass $args   wp_nav_menu() arguments.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '</ul>';
	}

	/**
	 * Start an item: the list item, its link and its toggle button.
	 *
	 * @param string    $output            Output.
	 * @param \WP_Post  $data_object       Menu item.
	 * @param int       $depth             Depth.
	 * @param \stdClass $args              wp_nav_menu() arguments.
	 * @param int       $current_object_id Current item ID.
	 */
	public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
		$item     = $data_object;
		$depth    = (int) $depth;
		$args     = is_object( $args ) ? $args : (object) (array) $args;
		$extras   = $this->extras_for( $item, $depth );
		$has_sub  = ! empty( $this->has_children );
		$has_mega = $extras && ! empty( $extras['panel'] );

		if ( $has_sub ) {
			$this->parents[ $depth ] = $item;
		}

		// Classes from WordPress and other plugins, then ours.
		$wp_classes   = empty( $item->classes ) ? [] : (array) $item->classes;
		$wp_classes[] = 'menu-item-' . $item->ID;
		$wp_classes   = (array) apply_filters( 'nav_menu_css_class', array_filter( $wp_classes ), $item, $args, $depth ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core menu hook.

		$current  = ! empty( $item->current ) || in_array( 'current-menu-item', $wp_classes, true );
		$ancestor = ! empty( $item->current_item_ancestor ) || ! empty( $item->current_item_parent ) || (bool) array_intersect( [ 'current-menu-ancestor', 'current-menu-parent' ], $wp_classes );

		$classes = [
			'wea-mega-menu__item',
			0 === $depth ? 'wea-mega-menu__item--top' : 'wea-mega-menu__item--sub',
			$has_sub ? 'has-sub' : '',
			$has_mega ? 'has-mega' : '',
			$current ? 'is-current' : '',
			$ancestor ? 'is-current-ancestor' : '',
			$extras && ! empty( $extras['rid'] ) ? 'elementor-repeater-item-' . $extras['rid'] : '',
		];
		$classes = array_unique( array_filter( array_map( 'strval', array_merge( $classes, $wp_classes ) ) ) );

		$output .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		// Link attributes, built and filtered the way core does.
		$atts           = [];
		$atts['title']  = ! empty( $item->attr_title ) ? $item->attr_title : '';
		$atts['target'] = ! empty( $item->target ) ? $item->target : '';
		if ( '_blank' === $atts['target'] && empty( $item->xfn ) ) {
			$atts['rel'] = 'noopener';
		} else {
			$atts['rel'] = ! empty( $item->xfn ) ? $item->xfn : '';
		}
		$atts['href']         = ! empty( $item->url ) ? $item->url : '';
		$atts['aria-current'] = $current ? 'page' : '';
		$atts                 = (array) apply_filters( 'nav_menu_link_attributes', $atts, $item, $args, $depth ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core menu hook.
		$atts['class']        = trim( 'wea-mega-menu__link ' . ( isset( $atts['class'] ) && is_scalar( $atts['class'] ) ? (string) $atts['class'] : '' ) );

		$attributes = '';
		foreach ( $atts as $attr => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value || false === $value || ! preg_match( '/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', (string) $attr ) ) {
				continue;
			}
			$value       = 'href' === $attr ? esc_url( (string) $value ) : esc_attr( (string) $value );
			$attributes .= ' ' . $attr . '="' . $value . '"';
		}

		$title = apply_filters( 'the_title', $item->title, $item->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$title = apply_filters( 'nav_menu_item_title', $title, $item, $args, $depth ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core menu hook.
		$title = (string) $title;

		$output .= '<div class="wea-mega-menu__row">';
		$output .= '<a' . $attributes . '>';
		if ( $extras && '' !== $extras['icon'] ) {
			$output .= '<span class="wea-icon wea-mega-menu__icon" aria-hidden="true">' . $extras['icon'] . '</span>';
		}
		$output .= '<span class="wea-mega-menu__label">' . wp_kses_post( $title ) . '</span>';
		if ( $extras && '' !== $extras['badge'] ) {
			$output .= '<span class="wea-mega-menu__badge">' . esc_html( $extras['badge'] ) . '</span>';
		}
		$output .= '</a>';

		if ( $has_sub || $has_mega ) {
			$plain = trim( wp_strip_all_tags( $title ) );
			/* translators: %s: menu item title. */
			$label = sprintf( __( '%s submenu', 'wise-addons-for-elementor-pro' ), '' !== $plain ? $plain : __( 'Item', 'wise-addons-for-elementor-pro' ) );

			$output .= '<button type="button" class="wea-mega-menu__arrow" aria-expanded="false" aria-haspopup="true"';
			$output .= ' aria-controls="' . esc_attr( $has_mega ? $this->mega_id( $item->ID ) : $this->sub_id( $item->ID ) ) . '"';
			if ( $has_mega && $has_sub ) {
				// Lets the script point the button at the normal sub-menu when mega panels are off on mobile.
				$output .= ' data-sub="' . esc_attr( $this->sub_id( $item->ID ) ) . '"';
			}
			$output .= ' aria-label="' . esc_attr( $label ) . '">' . $this->indicator . '</button>';
		}

		$output .= '</div>';
	}

	/**
	 * End an item. Top-level items with a mega panel get the panel after their sub-menu.
	 *
	 * @param string    $output      Output.
	 * @param \WP_Post  $data_object Menu item.
	 * @param int       $depth       Depth.
	 * @param \stdClass $args        wp_nav_menu() arguments.
	 */
	public function end_el( &$output, $data_object, $depth = 0, $args = null ) {
		$extras = $this->extras_for( $data_object, $depth );

		if ( $extras && ! empty( $extras['panel'] ) ) {
			$classes = [ 'wea-mega-menu__mega', 'wea-mega-menu__mega--' . $extras['width'], 'wea-mega-menu__mega--' . ( isset( $extras['source'] ) && 'cards' === $extras['source'] ? 'cards' : 'template' ) ];

			$output .= '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . esc_attr( $this->mega_id( $data_object->ID ) ) . '"';
			$output .= ' data-width="' . esc_attr( $extras['width'] ) . '" data-align="' . esc_attr( $extras['align'] ) . '">';
			$output .= '<div class="wea-mega-menu__mega-inner">' . self::placeholder( $data_object->ID ) . '</div>';
			$output .= '</div>';
		}

		$output .= '</li>';
	}
}
