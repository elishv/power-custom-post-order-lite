<?php
/**
 * Main plugin singleton.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Plugin
 *
 * Main plugin orchestrator. Wires up all sub-modules and exposes them
 * as properties so other code can access shared instances:
 *
 *   PCPO_Plugin::instance()->db
 *   PCPO_Plugin::instance()->query_handler
 *   PCPO_Plugin::instance()->context_resolver
 */
class PCPO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PCPO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Database wrapper.
	 *
	 * @var PCPO_Database
	 */
	public $db;

	/**
	 * Context resolver (figures out which term/global order applies to a query).
	 *
	 * @var PCPO_Context_Resolver
	 */
	public $context_resolver;

	/**
	 * Query handler (intercepts WP_Query to inject our custom ordering).
	 *
	 * @var PCPO_Query_Handler
	 */
	public $query_handler;

	/**
	 * Settings registry.
	 *
	 * @var PCPO_Settings
	 */
	public $settings;

	/**
	 * Get / create singleton.
	 *
	 * @return PCPO_Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: boot everything.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load and instantiate core modules that are always needed.
	 */
	private function load_dependencies() {
		$this->db               = new PCPO_Database();
		$this->settings         = new PCPO_Settings();
		$this->context_resolver = new PCPO_Context_Resolver();
		$this->query_handler    = new PCPO_Query_Handler( $this->db, $this->context_resolver, $this->settings );
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		// i18n.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Boot admin only in admin context.
		if ( is_admin() ) {
			new PCPO_Admin( $this->db, $this->settings );
			new PCPO_Ajax( $this->db, $this->settings );
		}

		// REST API endpoints (work in both admin and frontend).
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );

		// Purge front-end page caches whenever the order changes, so cached
		// product archives reflect the new order immediately.
		add_action( 'pcpo_order_changed', array( $this, 'purge_front_caches' ), 10, 1 );

		// Admin-bar shortcut: "Sort this category" when viewing a term archive
		// on the front-end. Registered here (not in PCPO_Admin) because the
		// admin bar renders on the front-end where PCPO_Admin isn't loaded.
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_sort_link' ), 80 );

		// Debug — render captured records as an HTML comment in the page
		// footer (and on shutdown as a fallback for pages that don't fire
		// wp_footer). PCPO_Debug::is_enabled() is a no-op when off.
		add_action( 'wp_footer', array( 'PCPO_Debug', 'render_footer' ), PHP_INT_MAX );
		add_action( 'shutdown', array( 'PCPO_Debug', 'render_footer' ), PHP_INT_MAX );

		// Capture the actual SQL of queries we marked, so we can verify our
		// ORDER BY actually made it into the final SQL.
		add_filter( 'posts_request', array( $this, 'debug_capture_sql' ), PHP_INT_MAX, 2 );

		// Shortcode + manual query support.
		new PCPO_Shortcode( $this->db, $this->settings );

		// Conditional integrations.
		add_action(
			'plugins_loaded',
			function () {
				if ( class_exists( 'WooCommerce' ) ) {
					new PCPO_WooCommerce( $this->db, $this->settings );
				}
				if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
					new PCPO_Elementor( $this->db, $this->settings );
				}
				if ( PCPO_WPML::is_active() ) {
					new PCPO_WPML( $this->db, $this->settings );
				}
			},
			20
		);
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			PCPO_TEXTDOMAIN,
			false,
			dirname( PCPO_BASENAME ) . '/languages'
		);
	}

	/**
	 * Purge front-end page caches after an order change.
	 *
	 * Hooked to `pcpo_order_changed`. Without this, sites running a page
	 * cache (WP Rocket, LiteSpeed, W3TC, WP Super Cache, etc.) keep serving
	 * the old product order from cached HTML until the cache naturally
	 * expires — which looks like "ordering doesn't work" to the user.
	 *
	 * We also bust WooCommerce's product-loop transients and any object
	 * cache so the next uncached request rebuilds with the new order.
	 *
	 * @param array $context Optional context array (taxonomy, term_id, ids).
	 */
	public function purge_front_caches( $context = array() ) {
		// WP Rocket — clear the whole domain cache (order can affect many pages).
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// Breeze (Cloudways). The action also triggers the Cloudways Varnish purge.
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}
		if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
			Breeze_PurgeCache::breeze_cache_flush();
		}
		// Cloudways / generic Varnish purge hook.
		if ( has_action( 'breeze_clear_varnish' ) ) {
			do_action( 'breeze_clear_varnish' );
		}

		// LiteSpeed Cache.
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// WP Engine.
		if ( class_exists( 'WpeCommon' ) ) {
			if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
				WpeCommon::purge_memcached();
			}
			if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
				WpeCommon::purge_varnish_cache();
			}
		}

		// WooCommerce product-loop / term-count transients.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		/**
		 * Fires after PCPO has purged the known page caches. Lets site owners
		 * hook in custom cache layers (Cloudflare, Fastly, server cache, etc.).
		 *
		 * @param array $context Context array.
		 */
		do_action( 'pcpo_after_purge_caches', $context );
	}

	/**
	 * Debug capture: record the final SQL of marked queries AND of any
	 * unmarked query that touches the product post type — that's how we
	 * detect WoodMart / Elementor product widgets running secondary queries
	 * that PCPO didn't catch.
	 *
	 * @param string   $request The SQL request.
	 * @param WP_Query $query   The query.
	 * @return string
	 */
	public function debug_capture_sql( $request, $query ) {
		if ( ! PCPO_Debug::is_enabled() ) {
			return $request;
		}
		if ( ! ( $query instanceof WP_Query ) ) {
			return $request;
		}
		$apply      = $query->get( 'pcpo_apply' );
		$post_types = (array) ( $query->get( 'post_type' ) ?: array() );
		$is_product = in_array( 'product', $post_types, true )
			|| false !== strpos( $request, 'product_cat' )
			|| false !== strpos( $request, "'product'" );

		if ( ! $apply && ! $is_product ) {
			return $request;
		}

		// Extract the ORDER BY clause so the captured payload stays readable
		// (full WP product SQL can be ~3KB per row).
		$orderby = '';
		if ( preg_match( '/ORDER BY (.+?)(?: LIMIT |\s*$)/is', $request, $m ) ) {
			$orderby = trim( $m[1] );
		}

		PCPO_Debug::record(
			$apply ? 'posts_request/marked_query_sql' : 'posts_request/unmarked_product_sql',
			array(
				'orderby_extract' => $orderby,
				'mode'            => $apply['mode'] ?? null,
				'context'         => $apply['context'] ?? null,
				'is_main_query'   => $query->is_main_query(),
				'post_type'       => $post_types,
				'sql_length'      => strlen( $request ),
				'sql_excerpt'     => substr( $request, 0, 600 ) . ( strlen( $request ) > 600 ? '…' : '' ),
			)
		);
		return $request;
	}

	/**
	 * Add a "Sort this category" shortcut to the front-end admin bar when
	 * viewing a term archive (product category, tag, or any taxonomy term).
	 * Jumps straight to the Sort page pre-filtered to that exact context, so
	 * there's no guessing which context maps to which front-end page.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_admin_bar_sort_link( $wp_admin_bar ) {
		// Front-end term archives only.
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! ( is_tax() || is_category() || is_tag() ) ) {
			return;
		}
		if ( ! current_user_can( $this->settings->get_sortable_capability() ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		// Only show for taxonomies attached to an enabled post type.
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy ) {
			return;
		}
		$enabled   = $this->settings->get_enabled_post_types();
		$post_type = '';
		foreach ( (array) $taxonomy->object_type as $obj_type ) {
			if ( in_array( $obj_type, $enabled, true ) ) {
				$post_type = $obj_type;
				break;
			}
		}
		if ( ! $post_type ) {
			return;
		}

		// Use pcpo_* arg names — passing post_type / taxonomy directly makes
		// WP's admin router try to resolve pcpo-sort as a submenu of
		// edit.php?post_type=... and bail with "Cannot load pcpo-sort".
		$url = add_query_arg(
			array(
				'page'      => 'pcpo-sort',
				'pcpo_pt'   => $post_type,
				'pcpo_tax'  => $term->taxonomy,
				'pcpo_term' => $term->term_id,
			),
			admin_url( 'admin.php' )
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'pcpo-sort-this',
				/* translators: %s: term name */
				'title' => '<span class="ab-icon dashicons dashicons-sort" style="top:2px;"></span>' . sprintf(
					/* translators: %s: category / term name */
					__( 'Sort "%s"', 'power-custom-post-order' ),
					esc_html( $term->name )
				),
				'href'  => esc_url( $url ),
				'meta'  => array(
					'title' => __( 'Open this category in Power Custom Post Order', 'power-custom-post-order' ),
				),
			)
		);
	}

	/**
	 * Initialize REST API controller.
	 */
	public function init_rest_api() {
		$rest = new PCPO_REST_API( $this->db, $this->settings );
		$rest->register_routes();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
