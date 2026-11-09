<?php
/**
 * Built-in rules for Display Conditions.
 *
 * Every rule is an array:
 *
 *     'slug' => [
 *         'label'    => 'Shown in the rule picker',
 *         'group'    => 'user' | 'device' | 'time' | 'content' | 'request' | 'woocommerce' | any label,
 *         'fields'   => [ 'field_id' => Elementor control args, ... ],
 *         'callback' => function ( array $rule, \Elementor\Element_Base $element ) { return true|false|null; },
 *     ]
 *
 * Field IDs share one repeater, so they must be unique across all rules. Each field
 * is shown only while its rule is picked. The callback receives the repeater item
 * (field ID => value) and returns whether the rule matches right now, or null when
 * the rule is incomplete and should be ignored. "Is not" is applied afterwards, so a
 * callback never has to handle it.
 *
 * Third-party code adds or removes rules with the `wiseaddons_pro/display_conditions/rules`
 * filter.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */

namespace WiseAddonsPro\Features;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Rule definitions and their checks.
 */
final class Display_Conditions_Rules {

	/**
	 * Parsed user agent for this request.
	 *
	 * @var array|null
	 */
	private static $agent = null;

	/**
	 * Labels of the rule groups, in picker order.
	 *
	 * @return array
	 */
	public static function groups() {
		return [
			'user'        => esc_html__( 'Visitor', 'wise-addons-for-elementor-pro' ),
			'device'      => esc_html__( 'Device and browser', 'wise-addons-for-elementor-pro' ),
			'time'        => esc_html__( 'Date and time', 'wise-addons-for-elementor-pro' ),
			'content'     => esc_html__( 'Page', 'wise-addons-for-elementor-pro' ),
			'request'     => esc_html__( 'URL and referrer', 'wise-addons-for-elementor-pro' ),
			'woocommerce' => esc_html__( 'WooCommerce', 'wise-addons-for-elementor-pro' ),
		];
	}

	/**
	 * Built-in rules.
	 *
	 * @return array
	 */
	public static function all() {
		$rules = [
			'login'      => [
				'label'    => esc_html__( 'Login status', 'wise-addons-for-elementor-pro' ),
				'group'    => 'user',
				'fields'   => [
					'login' => [
						'label'   => esc_html__( 'Visitor', 'wise-addons-for-elementor-pro' ),
						'type'    => Controls_Manager::SELECT,
						'default' => 'in',
						'options' => [
							'in'  => esc_html__( 'Logged in', 'wise-addons-for-elementor-pro' ),
							'out' => esc_html__( 'Logged out', 'wise-addons-for-elementor-pro' ),
						],
					],
				],
				'callback' => [ __CLASS__, 'check_login' ],
			],
			'role'       => [
				'label'    => esc_html__( 'User role', 'wise-addons-for-elementor-pro' ),
				'group'    => 'user',
				'fields'   => [
					'roles' => [
						'label'       => esc_html__( 'Roles', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => self::role_options(),
						'description' => esc_html__( 'Matches a logged-in user with any of these roles. Logged-out visitors never match.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_role' ],
			],
			'device'     => [
				'label'    => esc_html__( 'Device type (user agent)', 'wise-addons-for-elementor-pro' ),
				'group'    => 'device',
				'fields'   => [
					'device' => [
						'label'       => esc_html__( 'Device', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT,
						'default'     => 'mobile',
						'options'     => [
							'mobile'  => esc_html__( 'Phone or tablet', 'wise-addons-for-elementor-pro' ),
							'desktop' => esc_html__( 'Desktop or laptop', 'wise-addons-for-elementor-pro' ),
						],
						'description' => esc_html__( 'A best guess from the browser\'s user agent, made on the server. It does not look at the screen width, and some tablets (such as recent iPads) report themselves as desktops. To hide by screen width, use Responsive > Hide On.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_device' ],
			],
			'browser'    => [
				'label'    => esc_html__( 'Browser', 'wise-addons-for-elementor-pro' ),
				'group'    => 'device',
				'fields'   => [
					'browsers' => [
						'label'       => esc_html__( 'Browsers', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => [
							'chrome'  => esc_html__( 'Chrome', 'wise-addons-for-elementor-pro' ),
							'safari'  => esc_html__( 'Safari', 'wise-addons-for-elementor-pro' ),
							'firefox' => esc_html__( 'Firefox', 'wise-addons-for-elementor-pro' ),
							'edge'    => esc_html__( 'Microsoft Edge', 'wise-addons-for-elementor-pro' ),
							'opera'   => esc_html__( 'Opera', 'wise-addons-for-elementor-pro' ),
							'samsung' => esc_html__( 'Samsung Internet', 'wise-addons-for-elementor-pro' ),
							'ie'      => esc_html__( 'Internet Explorer', 'wise-addons-for-elementor-pro' ),
						],
						'description' => esc_html__( 'Read from the user agent, which browsers can change or hide.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_browser' ],
			],
			'os'         => [
				'label'    => esc_html__( 'Operating system', 'wise-addons-for-elementor-pro' ),
				'group'    => 'device',
				'fields'   => [
					'systems' => [
						'label'       => esc_html__( 'Systems', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => [
							'windows'  => esc_html__( 'Windows', 'wise-addons-for-elementor-pro' ),
							'macos'    => esc_html__( 'macOS', 'wise-addons-for-elementor-pro' ),
							'ios'      => esc_html__( 'iOS / iPadOS', 'wise-addons-for-elementor-pro' ),
							'android'  => esc_html__( 'Android', 'wise-addons-for-elementor-pro' ),
							'linux'    => esc_html__( 'Linux', 'wise-addons-for-elementor-pro' ),
							'chromeos' => esc_html__( 'ChromeOS', 'wise-addons-for-elementor-pro' ),
						],
					],
				],
				'callback' => [ __CLASS__, 'check_os' ],
			],
			'date'       => [
				'label'    => esc_html__( 'Date range', 'wise-addons-for-elementor-pro' ),
				'group'    => 'time',
				'fields'   => [
					'date_from' => [
						'label'       => esc_html__( 'From', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::DATE_TIME,
						'label_block' => false,
					],
					'date_to'   => [
						'label'       => esc_html__( 'To', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::DATE_TIME,
						'label_block' => false,
						'description' => self::timezone_note(),
					],
				],
				'callback' => [ __CLASS__, 'check_date' ],
			],
			'weekday'    => [
				'label'    => esc_html__( 'Day of the week', 'wise-addons-for-elementor-pro' ),
				'group'    => 'time',
				'fields'   => [
					'days' => [
						'label'       => esc_html__( 'Days', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => self::day_options(),
						'description' => self::timezone_note(),
					],
				],
				'callback' => [ __CLASS__, 'check_weekday' ],
			],
			'time'       => [
				'label'    => esc_html__( 'Time of day', 'wise-addons-for-elementor-pro' ),
				'group'    => 'time',
				'fields'   => [
					'time_from' => [
						'label'          => esc_html__( 'From', 'wise-addons-for-elementor-pro' ),
						'type'           => Controls_Manager::DATE_TIME,
						'label_block'    => false,
						'picker_options' => [
							'noCalendar' => true,
							'enableTime' => true,
							'time_24hr'  => true,
							'dateFormat' => 'H:i',
						],
						'placeholder'    => '09:00',
					],
					'time_to'   => [
						'label'          => esc_html__( 'To', 'wise-addons-for-elementor-pro' ),
						'type'           => Controls_Manager::DATE_TIME,
						'label_block'    => false,
						'picker_options' => [
							'noCalendar' => true,
							'enableTime' => true,
							'time_24hr'  => true,
							'dateFormat' => 'H:i',
						],
						'placeholder'    => '17:00',
						'description'    => esc_html__( 'The end time is not included. A range like 22:00 to 06:00 runs overnight. Uses the site timezone.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_time' ],
			],
			'page_type'  => [
				'label'    => esc_html__( 'Page type', 'wise-addons-for-elementor-pro' ),
				'group'    => 'content',
				'fields'   => [
					'page_types' => [
						'label'       => esc_html__( 'Page types', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => [
							'front'    => esc_html__( 'Front page', 'wise-addons-for-elementor-pro' ),
							'blog'     => esc_html__( 'Blog posts page', 'wise-addons-for-elementor-pro' ),
							'singular' => esc_html__( 'Any single post or page', 'wise-addons-for-elementor-pro' ),
							'single'   => esc_html__( 'Single post (any post type except pages)', 'wise-addons-for-elementor-pro' ),
							'page'     => esc_html__( 'Page', 'wise-addons-for-elementor-pro' ),
							'archive'  => esc_html__( 'Any archive', 'wise-addons-for-elementor-pro' ),
							'author'   => esc_html__( 'Author archive', 'wise-addons-for-elementor-pro' ),
							'date'     => esc_html__( 'Date archive', 'wise-addons-for-elementor-pro' ),
							'search'   => esc_html__( 'Search results', 'wise-addons-for-elementor-pro' ),
							'404'      => esc_html__( 'Not found (404)', 'wise-addons-for-elementor-pro' ),
						],
					],
				],
				'callback' => [ __CLASS__, 'check_page_type' ],
			],
			'post'       => [
				'label'    => esc_html__( 'Specific posts or pages', 'wise-addons-for-elementor-pro' ),
				'group'    => 'content',
				'fields'   => [
					'posts' => [
						'label'       => esc_html__( 'IDs or slugs', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'label_block' => true,
						'placeholder' => esc_html__( 'e.g. 42, about-us', 'wise-addons-for-elementor-pro' ),
						'description' => esc_html__( 'Separate with commas. Matches when the visitor is viewing one of these posts or pages.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_post' ],
			],
			'post_type'  => [
				'label'    => esc_html__( 'Post type (single view)', 'wise-addons-for-elementor-pro' ),
				'group'    => 'content',
				'fields'   => [
					'post_types' => [
						'label'       => esc_html__( 'Post types', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::SELECT2,
						'multiple'    => true,
						'label_block' => true,
						'options'     => self::post_type_options(),
					],
				],
				'callback' => [ __CLASS__, 'check_post_type' ],
			],
			'term'       => [
				'label'    => esc_html__( 'Category, tag or term', 'wise-addons-for-elementor-pro' ),
				'group'    => 'content',
				'fields'   => [
					'taxonomy'   => [
						'label'   => esc_html__( 'Taxonomy', 'wise-addons-for-elementor-pro' ),
						'type'    => Controls_Manager::SELECT,
						'default' => 'category',
						'options' => self::taxonomy_options(),
					],
					'terms'      => [
						'label'       => esc_html__( 'Term IDs or slugs', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'label_block' => true,
						'placeholder' => esc_html__( 'e.g. news, 12', 'wise-addons-for-elementor-pro' ),
						'description' => esc_html__( 'Separate with commas. Leave empty for any term.', 'wise-addons-for-elementor-pro' ),
					],
					'term_scope' => [
						'label'   => esc_html__( 'Where', 'wise-addons-for-elementor-pro' ),
						'type'    => Controls_Manager::SELECT,
						'default' => 'archive',
						'options' => [
							'archive' => esc_html__( 'On the term\'s archive page', 'wise-addons-for-elementor-pro' ),
							'single'  => esc_html__( 'On a single post that has the term', 'wise-addons-for-elementor-pro' ),
							'both'    => esc_html__( 'Either', 'wise-addons-for-elementor-pro' ),
						],
					],
				],
				'callback' => [ __CLASS__, 'check_term' ],
			],
			'url_param'  => [
				'label'    => esc_html__( 'URL parameter', 'wise-addons-for-elementor-pro' ),
				'group'    => 'request',
				'fields'   => [
					'param_key'   => [
						'label'       => esc_html__( 'Parameter', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'placeholder' => 'utm_source',
					],
					'param_value' => [
						'label'       => esc_html__( 'Value', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'placeholder' => esc_html__( 'Any value', 'wise-addons-for-elementor-pro' ),
						'description' => esc_html__( 'Leave empty to match whenever the parameter is in the URL. Otherwise the value must match exactly.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_url_param' ],
			],
			'referrer'   => [
				'label'    => esc_html__( 'Referrer', 'wise-addons-for-elementor-pro' ),
				'group'    => 'request',
				'fields'   => [
					'referrer' => [
						'label'       => esc_html__( 'Referrer contains', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'label_block' => true,
						'placeholder' => esc_html__( 'e.g. google., facebook.com', 'wise-addons-for-elementor-pro' ),
						'description' => esc_html__( 'Separate with commas; any one is enough. Many sites and browsers send only the domain, or no referrer at all.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_referrer' ],
			],
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$rules['cart_empty']   = [
				'label'    => esc_html__( 'Cart is empty', 'wise-addons-for-elementor-pro' ),
				'group'    => 'woocommerce',
				'fields'   => [],
				'callback' => [ __CLASS__, 'check_cart_empty' ],
			];
			$rules['cart_total']   = [
				'label'    => esc_html__( 'Cart subtotal', 'wise-addons-for-elementor-pro' ),
				'group'    => 'woocommerce',
				'fields'   => [
					'cart_min' => [
						'label'       => esc_html__( 'At least', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::NUMBER,
						'min'         => 0,
						'step'        => 0.01,
						'description' => esc_html__( 'Compared with the subtotal the cart shows, before shipping.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_cart_total' ],
			];
			$rules['cart_product'] = [
				'label'    => esc_html__( 'Product in cart', 'wise-addons-for-elementor-pro' ),
				'group'    => 'woocommerce',
				'fields'   => [
					'cart_products' => [
						'label'       => esc_html__( 'Product IDs', 'wise-addons-for-elementor-pro' ),
						'type'        => Controls_Manager::TEXT,
						'label_block' => true,
						'placeholder' => esc_html__( 'e.g. 12, 57', 'wise-addons-for-elementor-pro' ),
						'description' => esc_html__( 'Separate with commas; any one is enough. Variation IDs work too.', 'wise-addons-for-elementor-pro' ),
					],
				],
				'callback' => [ __CLASS__, 'check_cart_product' ],
			];
		}

		return $rules;
	}

	/* ------------------------------------------------------------------
	 * Options for the editor
	 * ------------------------------------------------------------------ */

	/**
	 * "Uses the site timezone" note with the zone name.
	 *
	 * @return string
	 */
	private static function timezone_note() {
		return sprintf(
			/* translators: %s: timezone name, e.g. Europe/London or UTC+6 */
			esc_html__( 'Uses the site timezone (%s), set in Settings > General.', 'wise-addons-for-elementor-pro' ),
			esc_html( wp_timezone_string() )
		);
	}

	/**
	 * User roles.
	 *
	 * @return array
	 */
	private static function role_options() {
		$options = [];
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$options[ $role ] = esc_html( translate_user_role( $name ) );
		}
		return $options;
	}

	/**
	 * Days of the week, keyed by ISO-8601 number (1 = Monday), starting on the site's first day.
	 *
	 * @return array
	 */
	private static function day_options() {
		global $wp_locale;

		$days  = [];
		$start = (int) get_option( 'start_of_week', 1 );

		for ( $i = 0; $i < 7; $i++ ) {
			$day = ( $start + $i ) % 7; // 0 = Sunday, as WP_Locale counts.
			$iso = 0 === $day ? 7 : $day;

			$days[ (string) $iso ] = esc_html( $wp_locale ? $wp_locale->get_weekday( $day ) : gmdate( 'l', strtotime( 'Sunday +' . $day . ' days' ) ) );
		}

		return $days;
	}

	/**
	 * Public post types.
	 *
	 * @return array
	 */
	private static function post_type_options() {
		$options = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			if ( in_array( $type->name, [ 'attachment', 'elementor_library', 'e-landing-page' ], true ) ) {
				continue;
			}
			$options[ $type->name ] = esc_html( $type->labels->singular_name );
		}
		return $options;
	}

	/**
	 * Public taxonomies.
	 *
	 * @return array
	 */
	private static function taxonomy_options() {
		$options = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( in_array( $tax->name, [ 'post_format', 'elementor_library_type', 'elementor_library_category' ], true ) ) {
				continue;
			}
			$options[ $tax->name ] = esc_html( $tax->labels->singular_name );
		}
		return $options;
	}

	/* ------------------------------------------------------------------
	 * Checks. Each returns true, false, or null to ignore the rule.
	 * ------------------------------------------------------------------ */

	/**
	 * Login status.
	 *
	 * @param array $rule Rule values.
	 * @return bool
	 */
	public static function check_login( array $rule ) {
		$want = isset( $rule['login'] ) && 'out' === $rule['login'] ? 'out' : 'in';
		return 'in' === $want ? is_user_logged_in() : ! is_user_logged_in();
	}

	/**
	 * User role.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_role( array $rule ) {
		$roles = self::str_list( $rule['roles'] ?? [] );
		if ( ! $roles ) {
			return null;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return (bool) array_intersect( $roles, (array) wp_get_current_user()->roles );
	}

	/**
	 * Device type from the user agent.
	 *
	 * @param array $rule Rule values.
	 * @return bool
	 */
	public static function check_device( array $rule ) {
		$want = isset( $rule['device'] ) && 'desktop' === $rule['device'] ? 'desktop' : 'mobile';
		return 'mobile' === $want ? wp_is_mobile() : ! wp_is_mobile();
	}

	/**
	 * Browser.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_browser( array $rule ) {
		$list = self::str_list( $rule['browsers'] ?? [] );
		if ( ! $list ) {
			return null;
		}
		return in_array( self::agent()['browser'], $list, true );
	}

	/**
	 * Operating system.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_os( array $rule ) {
		$list = self::str_list( $rule['systems'] ?? [] );
		if ( ! $list ) {
			return null;
		}
		return in_array( self::agent()['os'], $list, true );
	}

	/**
	 * Date range, in the site timezone. Either end may be empty.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_date( array $rule ) {
		$from = self::parse_date( $rule['date_from'] ?? '', false );
		$to   = self::parse_date( $rule['date_to'] ?? '', true );

		if ( ! $from && ! $to ) {
			return null;
		}

		$now = current_datetime();

		if ( $from && $now < $from ) {
			return false;
		}
		if ( $to && $now > $to ) {
			return false;
		}
		return true;
	}

	/**
	 * Day of the week, in the site timezone.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_weekday( array $rule ) {
		$days = self::str_list( $rule['days'] ?? [] );
		if ( ! $days ) {
			return null;
		}
		return in_array( current_datetime()->format( 'N' ), $days, true );
	}

	/**
	 * Time of day, in the site timezone. Start included, end excluded; a start after
	 * the end runs overnight. Equal times cover the whole day.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_time( array $rule ) {
		$from = self::parse_minutes( $rule['time_from'] ?? '' );
		$to   = self::parse_minutes( $rule['time_to'] ?? '' );

		if ( null === $from && null === $to ) {
			return null;
		}

		$from = null === $from ? 0 : $from;
		$to   = null === $to ? 1440 : $to;
		$now  = (int) current_datetime()->format( 'G' ) * 60 + (int) current_datetime()->format( 'i' );

		if ( $from === $to ) {
			return true;
		}
		if ( $from < $to ) {
			return $now >= $from && $now < $to;
		}
		return $now >= $from || $now < $to;
	}

	/**
	 * Page type.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_page_type( array $rule ) {
		$types = self::str_list( $rule['page_types'] ?? [] );
		if ( ! $types ) {
			return null;
		}

		$checks = [
			'front'    => 'is_front_page',
			'blog'     => 'is_home',
			'singular' => 'is_singular',
			'single'   => 'is_single',
			'page'     => 'is_page',
			'archive'  => 'is_archive',
			'author'   => 'is_author',
			'date'     => 'is_date',
			'search'   => 'is_search',
			'404'      => 'is_404',
		];

		foreach ( $types as $type ) {
			if ( isset( $checks[ $type ] ) && call_user_func( $checks[ $type ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Specific posts or pages, by ID or slug.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_post( array $rule ) {
		$items = self::id_or_slug_list( $rule['posts'] ?? '' );
		if ( ! $items ) {
			return null;
		}
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return in_array( (int) $post->ID, $items, true ) || in_array( $post->post_name, $items, true );
	}

	/**
	 * Post type of the single post being viewed.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_post_type( array $rule ) {
		$types = self::str_list( $rule['post_types'] ?? [] );
		if ( ! $types ) {
			return null;
		}
		return is_singular( $types );
	}

	/**
	 * Category, tag or custom term: on its archive, on a single post that has it, or either.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_term( array $rule ) {
		$tax = sanitize_key( (string) ( $rule['taxonomy'] ?? '' ) );
		if ( '' === $tax || ! taxonomy_exists( $tax ) ) {
			return null;
		}

		$terms = self::id_or_slug_list( $rule['terms'] ?? '' );
		$scope = isset( $rule['term_scope'] ) && in_array( $rule['term_scope'], [ 'single', 'both' ], true ) ? $rule['term_scope'] : 'archive';

		if ( 'single' !== $scope ) {
			if ( 'category' === $tax ) {
				$archive = is_category( $terms );
			} elseif ( 'post_tag' === $tax ) {
				$archive = is_tag( $terms );
			} else {
				$archive = is_tax( $tax, $terms );
			}
			if ( $archive ) {
				return true;
			}
		}

		if ( 'archive' !== $scope && is_singular() ) {
			return has_term( $terms ? $terms : '', $tax, get_queried_object_id() );
		}

		return false;
	}

	/**
	 * URL query parameter, optionally with an exact value.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_url_param( array $rule ) {
		$key = trim( (string) ( $rule['param_key'] ?? '' ) );
		if ( '' === $key ) {
			return null;
		}

		// PHP turns dots and spaces in parameter names into underscores.
		$key = str_replace( [ '.', ' ' ], '_', $key );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display check.
		if ( ! isset( $_GET[ $key ] ) ) {
			return false;
		}

		$want = trim( (string) ( $rule['param_value'] ?? '' ) );
		if ( '' === $want ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display check.
		$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below once it is known to be a string.
		if ( ! is_string( $value ) ) {
			return false;
		}

		return sanitize_text_field( $value ) === $want;
	}

	/**
	 * Referrer contains any of the given fragments (case-insensitive).
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_referrer( array $rule ) {
		$needles = self::str_list( $rule['referrer'] ?? '' );
		if ( ! $needles ) {
			return null;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? strtolower( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
		if ( '' === $referrer ) {
			return false;
		}

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $referrer, strtolower( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WooCommerce cart is empty.
	 *
	 * @return bool|null
	 */
	public static function check_cart_empty() {
		$cart = self::cart();
		return $cart ? $cart->is_empty() : null;
	}

	/**
	 * WooCommerce cart subtotal is at least an amount.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_cart_total( array $rule ) {
		if ( ! isset( $rule['cart_min'] ) || '' === $rule['cart_min'] || ! is_numeric( $rule['cart_min'] ) ) {
			return null;
		}
		$cart = self::cart();
		if ( ! $cart ) {
			return null;
		}
		return (float) $cart->get_displayed_subtotal() >= (float) $rule['cart_min'];
	}

	/**
	 * A WooCommerce product (or variation) is in the cart.
	 *
	 * @param array $rule Rule values.
	 * @return bool|null
	 */
	public static function check_cart_product( array $rule ) {
		$ids = array_filter( array_map( 'absint', self::str_list( $rule['cart_products'] ?? '' ) ) );
		if ( ! $ids ) {
			return null;
		}
		$cart = self::cart();
		if ( ! $cart ) {
			return null;
		}
		foreach ( $cart->get_cart() as $item ) {
			$product   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			if ( in_array( $product, $ids, true ) || ( $variation && in_array( $variation, $ids, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The WooCommerce cart when it is loaded.
	 *
	 * @return \WC_Cart|null
	 */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		return ( $wc && isset( $wc->cart ) && $wc->cart instanceof \WC_Cart ) ? $wc->cart : null;
	}

	/**
	 * Turn a comma list or an array into trimmed, non-empty strings.
	 *
	 * @param mixed $value Text or array.
	 * @return string[]
	 */
	public static function str_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$item = trim( sanitize_text_field( (string) $item ) );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A comma list where numbers become IDs and everything else becomes a slug.
	 *
	 * @param mixed $value Text.
	 * @return array
	 */
	private static function id_or_slug_list( $value ) {
		$out = [];
		foreach ( self::str_list( $value ) as $item ) {
			$out[] = ctype_digit( $item ) ? (int) $item : sanitize_title( $item );
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Parse a date control value in the site timezone.
	 *
	 * @param mixed $value  "Y-m-d H:i", "Y-m-d H:i:s" or "Y-m-d".
	 * @param bool  $is_end A date without a time means the end of that day.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_date( $value, $is_end ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value );
		$zone  = wp_timezone();

		foreach ( [ '!Y-m-d H:i', '!Y-m-d H:i:s' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $zone );
			if ( $date ) {
				return $date;
			}
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone );
		if ( $date ) {
			return $is_end ? $date->setTime( 23, 59, 59 ) : $date;
		}

		return null;
	}

	/**
	 * Minutes since midnight from "H:i".
	 *
	 * @param mixed $value Time text.
	 * @return int|null
	 */
	private static function parse_minutes( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\s*(\d{1,2}):(\d{2})/', $value, $m ) ) {
			return null;
		}
		$hours   = (int) $m[1];
		$minutes = (int) $m[2];
		if ( $hours > 23 || $minutes > 59 ) {
			return null;
		}
		return $hours * 60 + $minutes;
	}

	/**
	 * Browser and operating system from the user agent. Order matters: many browsers
	 * mention the engines they are built on.
	 *
	 * @return array { browser: string, os: string }
	 */
	private static function agent() {
		if ( null !== self::$agent ) {
			return self::$agent;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$browser  = '';
		$browsers = [
			'edge'    => '/\b(Edg|Edge|EdgA|EdgiOS)\//',
			'samsung' => '/\bSamsungBrowser\//',
			'opera'   => '/\b(OPR|Opera|OPX|OPT)\/|\bOpera\b/',
			'ie'      => '/\b(MSIE |Trident\/)/',
			'firefox' => '/\b(Firefox|FxiOS)\//',
			'chrome'  => '/\b(Chrome|CriOS|Chromium)\//',
			'safari'  => '/\bVersion\/[\d.]+.*\bSafari\//',
		];
		foreach ( $browsers as $name => $pattern ) {
			if ( preg_match( $pattern, $ua ) ) {
				$browser = $name;
				break;
			}
		}

		$os  = '';
		$oss = [
			'ios'      => '/\b(iPhone|iPad|iPod)\b/',
			'android'  => '/\bAndroid\b/',
			'chromeos' => '/\bCrOS\b/',
			'windows'  => '/\bWindows\b/',
			'macos'    => '/\bMac OS X\b|\bMacintosh\b/',
			'linux'    => '/\bLinux\b/',
		];
		foreach ( $oss as $name => $pattern ) {
			if ( preg_match( $pattern, $ua ) ) {
				$os = $name;
				break;
			}
		}

		self::$agent = [
			'browser' => $browser,
			'os'      => $os,
		];

		return self::$agent;
	}
}
