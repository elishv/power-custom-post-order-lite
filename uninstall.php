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
$pcpo_tables = array(
	$wpdb->prefix . 'pcpo_orders',
	$wpdb->prefix . 'pcpo_settings',
	$wpdb->prefix . 'pcpo_templates',
);

foreach ( $pcpo_tables as $pcpo_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $pcpo_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the plugin's own tables on uninstall.
}

// Remove our options.
$pcpo_options = array(
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

foreach ( $pcpo_options as $pcpo_option_name ) {
	delete_option( $pcpo_option_name );
	delete_site_option( $pcpo_option_name );
}

// Clear any scheduled events.
wp_clear_scheduled_hook( 'pcpo_daily_maintenance' );

// Clear cache.
wp_cache_flush();
