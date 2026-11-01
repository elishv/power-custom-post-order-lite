<?php
/**
 * Admin: menu registration, page rendering, asset enqueueing.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Admin
 *
 * Registers the plugin's admin pages and orchestrates rendering. Actual page
 * markup lives in admin/views/ — this class is the controller.
 *
 * Pages:
 *   Dashboard       — Overview, stats, quick actions.
 *   Sort Posts      — The main drag-and-drop UI.
 *   Bulk Actions    — Shuffle / alphabetize / reset / move-to-top etc.
 *   Templates       — Save and apply order templates.
 *   Import / Export — CSV in/out.
 *   Settings        — Global plugin settings.
 */
class PCPO_Admin {

	/**
	 * Database.
	 *
	 * @var PCPO_Database
	 */
	private $db;

	/**
	 * Settings.
	 *
	 * @var PCPO_Settings
	 */
	private $settings;

	/**
	 * Menu slug constants.
	 */
	const MENU_SLUG = 'pcpo-dashboard';

	/**
	 * Constructor.
	 *
	 * @param PCPO_Database $db       Database wrapper.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Add link to settings on the plugins.php row.
		add_filter( 'plugin_action_links_' . PCPO_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register admin menu and sub-menus.
	 */
	public function register_menu() {
		$cap = $this->settings->get_admin_capability();

		add_menu_page(
			__( 'Power Custom Post Order', 'power-custom-post-order' ),
			__( 'Post Order', 'power-custom-post-order' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-sort',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'power-custom-post-order' ),
			__( 'Dashboard', 'power-custom-post-order' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Sort Posts', 'power-custom-post-order' ),
			__( 'Sort Posts', 'power-custom-post-order' ),
			$this->settings->get_sortable_capability(),
			'pcpo-sort',
			array( $this, 'render_sort_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bulk Actions', 'power-custom-post-order' ),
			__( 'Bulk Actions', 'power-custom-post-order' ),
			$cap,
			'pcpo-bulk',
			array( $this, 'render_bulk_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Order Templates', 'power-custom-post-order' ),
			__( 'Templates', 'power-custom-post-order' ),
			$cap,
			'pcpo-templates',
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Import / Export', 'power-custom-post-order' ),
			__( 'Import / Export', 'power-custom-post-order' ),
			$cap,
			'pcpo-importexport',
			array( $this, 'render_importexport_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'power-custom-post-order' ),
			__( 'Settings', 'power-custom-post-order' ),
			$cap,
			'pcpo-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Debug', 'power-custom-post-order' ),
			__( 'Debug', 'power-custom-post-order' ),
			$cap,
			'pcpo-debug',
			array( $this, 'render_debug_page' )
		);
	}

	/**
	 * Enqueue admin CSS / JS on our pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only on our pages.
		if ( false === strpos( (string) $hook, 'pcpo' ) ) {
			return;
		}

		// Use filemtime() as the cache buster so every CSS / JS save invalidates
		// caches (including WP Rocket / browser caches) automatically — the static
		// PCPO_VERSION isn't enough on sites where admin assets are aggressively
		// cached. Fall back to PCPO_VERSION if the file isn't readable.
		$css_file = PCPO_PATH . 'admin/css/admin.css';
		$js_file  = PCPO_PATH . 'admin/js/admin.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : PCPO_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : PCPO_VERSION;

		wp_enqueue_style(
			'pcpo-admin',
			PCPO_URL . 'admin/css/admin.css',
			array(),
			$css_ver
		);
		// RTL stylesheet auto-loads via WP if locale is RTL because we ship admin-rtl.css.
		wp_style_add_data( 'pcpo-admin', 'rtl', 'replace' );

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'pcpo-admin',
			PCPO_URL . 'admin/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			$js_ver,
			true
		);

		wp_localize_script(
			'pcpo-admin',
			'pcpoAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => esc_url_raw( rest_url( 'pcpo/v1/' ) ),
				'nonce'   => wp_create_nonce( 'pcpo_admin_nonce' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'isRtl'   => is_rtl(),
				'i18n'    => array(
					'saving'      => __( 'Saving…', 'power-custom-post-order' ),
					'saved'       => __( 'Order saved', 'power-custom-post-order' ),
					'error'       => __( 'Something went wrong. Check the browser console.', 'power-custom-post-order' ),
					'confirmReset' => __( 'Reset order for this context? This will remove your manual ordering and revert to default.', 'power-custom-post-order' ),
					'confirmShuffle' => __( 'Shuffle ALL items in this context randomly?', 'power-custom-post-order' ),
					'confirmAlphabetize' => __( 'Re-order alphabetically? Your current manual order will be overwritten.', 'power-custom-post-order' ),
					'pinned'      => __( 'Pinned', 'power-custom-post-order' ),
					'hidden'      => __( 'Hidden', 'power-custom-post-order' ),
					'movedToTop'  => __( 'Moved to top', 'power-custom-post-order' ),
					'movedToBottom' => __( 'Moved to bottom', 'power-custom-post-order' ),
					'noResults'   => __( 'No posts match your filters.', 'power-custom-post-order' ),
					'loading'     => __( 'Loading…', 'power-custom-post-order' ),
					'moveToPosition' => __( 'Move to position', 'power-custom-post-order' ),
				),
			)
		);
	}

	/**
	 * Register settings via the Settings API for the settings page.
	 */
	public function register_settings() {
		register_setting( 'pcpo_settings_group', 'pcpo_enabled_post_types', array( 'sanitize_callback' => array( $this, 'sanitize_post_type_array' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_override_explicit', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_sync_wc_menu_order', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_wpml_sync', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_debug', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_multi_term_strategy', array( 'sanitize_callback' => array( $this, 'sanitize_multi_term' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_admin_capability', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'pcpo_settings_group', 'pcpo_sortable_capability', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'pcpo_settings_group', 'pcpo_random_seed_per_user', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'pcpo_settings_group', 'pcpo_default_per_page', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'pcpo_settings_group', 'pcpo_enable_role_overrides', array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
	}

	/**
	 * Sanitize "yes" / "no" option.
	 *
	 * @param mixed $val Value.
	 * @return string
	 */
	public function sanitize_yes_no( $val ) {
		return ( 'yes' === $val || true === $val || 1 === (int) $val ) ? 'yes' : 'no';
	}

	/**
	 * Sanitize post type list.
	 *
	 * @param mixed $val Array of slugs.
	 * @return array
	 */
	public function sanitize_post_type_array( $val ) {
		if ( ! is_array( $val ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'sanitize_key', $val ) ) );
	}

	/**
	 * Sanitize multi-term strategy.
	 *
	 * @param mixed $val Value.
	 * @return string
	 */
	public function sanitize_multi_term( $val ) {
		return in_array( $val, array( 'primary', 'global', 'first' ), true ) ? $val : 'primary';
	}

	/**
	 * Plugin action link on plugins.php.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=pcpo-settings' ) ) . '">' . esc_html__( 'Settings', 'power-custom-post-order' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/* ------------------------------------------------------------------
	 * PAGE RENDERERS
	 * ---------------------------------------------------------------- */

	/**
	 * Render dashboard page.
	 */
	public function render_dashboard() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Render sort page.
	 */
	public function render_sort_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/sort.php';
	}

	/**
	 * Render bulk actions page.
	 */
	public function render_bulk_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/bulk.php';
	}

	/**
	 * Render templates page.
	 */
	public function render_templates_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/templates.php';
	}

	/**
	 * Render import/export page.
	 */
	public function render_importexport_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/importexport.php';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/settings.php';
	}

	/**
	 * Render debug page.
	 */
	public function render_debug_page() {
		$db       = $this->db;
		$settings = $this->settings;
		require PCPO_PATH . 'admin/views/debug.php';
	}

	/**
	 * Helper: get all public post types we let the user manage.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public static function get_manageable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		// Exclude attachments — generally not useful to order.
		unset( $types['attachment'] );
		return $types;
	}
}
