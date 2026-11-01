<?php
/**
 * Installer / upgrader for plugin tables.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Installer
 *
 * Creates and upgrades the plugin's database tables. We use three tables:
 *
 *  - pcpo_orders     The per-(post, taxonomy, term) ordering data
 *                    (with sticky and hidden flags).
 *  - pcpo_settings   Per-context configuration (post type / taxonomy / term
 *                    order modes, role overrides, etc.).
 *  - pcpo_templates  Saved order templates that can be applied to terms.
 */
class PCPO_Installer {

	/**
	 * Activation handler.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_default_options();
		self::set_db_version();
		// Clear any object cache so subsequent reads see fresh data.
		wp_cache_flush();
		// Schedule daily random-seed reset (used for cached random ordering).
		if ( ! wp_next_scheduled( 'pcpo_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'pcpo_daily_maintenance' );
		}
	}

	/**
	 * Deactivation handler. Keeps data, removes schedules.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'pcpo_daily_maintenance' );
	}

	/**
	 * Check stored DB version vs. constant and run upgrade if needed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'pcpo_db_version', '0' );
		if ( version_compare( $installed, PCPO_DB_VERSION, '<' ) ) {
			self::create_tables();
			self::set_db_version();
			do_action( 'pcpo_after_db_upgrade', $installed, PCPO_DB_VERSION );
		}
	}

	/**
	 * Persist current DB version.
	 */
	private static function set_db_version() {
		update_option( 'pcpo_db_version', PCPO_DB_VERSION );
	}

	/**
	 * Create the plugin's tables. Safe to call repeatedly thanks to dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$orders_table    = $wpdb->prefix . 'pcpo_orders';
		$settings_table  = $wpdb->prefix . 'pcpo_settings';
		$templates_table = $wpdb->prefix . 'pcpo_templates';

		// Core ordering table.
		// taxonomy/term_id both 0/empty mean "global / no taxonomy context".
		$orders_sql = "CREATE TABLE {$orders_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			post_type VARCHAR(50) NOT NULL DEFAULT '',
			taxonomy VARCHAR(50) NOT NULL DEFAULT '',
			term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			menu_order INT(11) NOT NULL DEFAULT 0,
			is_sticky TINYINT(1) NOT NULL DEFAULT 0,
			is_hidden TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_post_term (post_id, taxonomy, term_id),
			KEY idx_term_order (taxonomy, term_id, menu_order),
			KEY idx_post_type (post_type, taxonomy, term_id),
			KEY idx_sticky (is_sticky, taxonomy, term_id),
			KEY idx_hidden (is_hidden, taxonomy, term_id)
		) {$charset_collate};";

		// Settings: order mode + role overrides per context.
		// context_type: 'post_type' | 'taxonomy' | 'term'.
		// context_value: post type slug, "taxonomy:slug" or "taxonomy:term_id".
		$settings_sql = "CREATE TABLE {$settings_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			context_type VARCHAR(20) NOT NULL DEFAULT '',
			context_value VARCHAR(255) NOT NULL DEFAULT '',
			order_mode VARCHAR(50) NOT NULL DEFAULT 'manual',
			order_direction VARCHAR(4) NOT NULL DEFAULT 'ASC',
			role_overrides LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_context (context_type, context_value)
		) {$charset_collate};";

		// Saved order templates.
		$templates_sql = "CREATE TABLE {$templates_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			post_type VARCHAR(50) NOT NULL DEFAULT '',
			description TEXT NULL,
			order_data LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_post_type (post_type)
		) {$charset_collate};";

		dbDelta( $orders_sql );
		dbDelta( $settings_sql );
		dbDelta( $templates_sql );
	}

	/**
	 * Seed default plugin options (only sets values that don't already exist).
	 */
	private static function seed_default_options() {
		$defaults = array(
			'pcpo_enabled_post_types'    => array( 'post', 'page', 'product' ),
			'pcpo_override_explicit'     => 'no',  // The Q3 toggle: override widgets that explicitly set orderby.
			'pcpo_sync_wc_menu_order'    => 'yes', // Q1 answer: sync to WC menu_order.
			'pcpo_multi_term_strategy'   => 'primary', // Q2 answer: Yoast/WC primary term + fallback to global.
			'pcpo_admin_capability'      => 'manage_options',
			'pcpo_sortable_capability'   => 'edit_others_posts',
			'pcpo_random_seed_per_user'  => 'no',
			'pcpo_default_per_page'      => 50,
			'pcpo_enable_role_overrides' => 'yes',
		);
		foreach ( $defaults as $option_name => $default_value ) {
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $default_value );
			}
		}
	}
}
