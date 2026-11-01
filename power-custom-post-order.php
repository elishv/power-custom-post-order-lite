<?php
/**
 * Plugin Name:       Power Custom Post Order
 * Plugin URI:        https://a-in.co.il/plugins/post-order/
 * Description:       Drag-and-drop custom post ordering for any post type and WooCommerce products, with per-taxonomy ordering, order variations (manual, alphabetical, price, popularity, random, etc.), sticky posts, hidden posts, order templates, CSV import/export, per-role ordering, REST API, and shortcode/template tag for manual queries. Works with every theme and Elementor.
 * Version:           1.0.16
 * Author:            Eli Shvarzman
 * Author URI:        https://a-in.co.il/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power-custom-post-order
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'PCPO_VERSION', '1.0.16' );
define( 'PCPO_DB_VERSION', '1.0.0' );
define( 'PCPO_FILE', __FILE__ );
define( 'PCPO_PATH', plugin_dir_path( __FILE__ ) );
define( 'PCPO_URL', plugin_dir_url( __FILE__ ) );
define( 'PCPO_BASENAME', plugin_basename( __FILE__ ) );
define( 'PCPO_PREFIX', 'pcpo' );
define( 'PCPO_TEXTDOMAIN', 'power-custom-post-order' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 * We don't touch orders directly, but WooCommerce expects this declaration.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Autoloader for plugin classes.
 *
 * Class PCPO_Installer            -> includes/class-pcpo-installer.php
 * Class PCPO_Context_Resolver     -> includes/class-pcpo-context-resolver.php
 * Class PCPO_REST_API             -> includes/class-pcpo-rest-api.php
 *
 * Mapping: lowercase the class name then convert underscores to hyphens, then
 * prefix with "class-" inside the includes/ directory.
 *
 * @param string $class_name The class being loaded.
 * @return void
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'PCPO_' ) !== 0 ) {
			return;
		}
		// PCPO_Installer -> pcpo_installer -> pcpo-installer.
		$file_part = str_replace( '_', '-', strtolower( $class_name ) );
		$file_path = PCPO_PATH . 'includes/class-' . $file_part . '.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
		// Report it in the PHP error log when WP_DEBUG and PCPO_DEBUG are on.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'PCPO_DEBUG' ) && PCPO_DEBUG ) {
			error_log( '[PCPO][Autoloader] Class ' . $class_name . ' not found at expected path: ' . $file_path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with WP_DEBUG and PCPO_DEBUG on.
		}
	}
);

// Load helper functions (template tags) immediately so themes can use them everywhere.
require_once PCPO_PATH . 'includes/functions-template.php';

/**
 * Activation hook: create tables, set defaults, schedule events.
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once PCPO_PATH . 'includes/class-pcpo-installer.php';
		PCPO_Installer::activate();
	}
);

/**
 * Deactivation hook: clear scheduled events, keep data.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		require_once PCPO_PATH . 'includes/class-pcpo-installer.php';
		PCPO_Installer::deactivate();
	}
);

/**
 * Bootstrap the plugin after all plugins are loaded
 * so we can detect WooCommerce / Elementor presence.
 */
add_action(
	'plugins_loaded',
	function () {
		// DB schema upgrade check on every load (cheap).
		PCPO_Installer::maybe_upgrade();
		// Boot the singleton.
		PCPO_Plugin::instance();
	},
	5
);
