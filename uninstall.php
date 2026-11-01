<?php
/**
 * Uninstall handler — clean up everything on plugin deletion.
 *
 * @package PowerCustomPostOrder
 */

// Only run when explicitly uninstalled (not deactivated).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop our tables.
$tables = array(
	$wpdb->prefix . 'pcpo_orders',
	$wpdb->prefix . 'pcpo_settings',
	$wpdb->prefix . 'pcpo_templates',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// Remove our options.
$options = array(
	'pcpo_db_version',
	'pcpo_enabled_post_types',
	'pcpo_override_explicit',
	'pcpo_sync_wc_menu_order',
	'pcpo_multi_term_strategy',
	'pcpo_admin_capability',
	'pcpo_sortable_capability',
	'pcpo_random_seed_per_user',
	'pcpo_default_per_page',
	'pcpo_enable_role_overrides',
	'pcpo_cache_version',
);

foreach ( $options as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

// Clear any scheduled events.
wp_clear_scheduled_hook( 'pcpo_daily_maintenance' );

// Clear cache.
wp_cache_flush();
