<?php
/**
 * Settings view.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

$pcpo_post_types = PCPO_Admin::get_manageable_post_types();
$pcpo_enabled    = $pcpo_settings->get_enabled_post_types();
$pcpo_roles      = wp_roles()->roles;
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-admin-settings"></span>
		<?php esc_html_e( 'Power Custom Post Order — Settings', 'power-custom-post-order' ); ?>
	</h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'pcpo_settings_group' ); ?>

		<h2 class="pcpo-section-h"><?php esc_html_e( 'Scope', 'power-custom-post-order' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Enabled post types', 'power-custom-post-order' ); ?></label></th>
				<td>
					<?php foreach ( $pcpo_post_types as $pcpo_slug => $pcpo_obj ) : ?>
						<label class="pcpo-checkbox-line">
							<input type="checkbox" name="pcpo_enabled_post_types[]" value="<?php echo esc_attr( $pcpo_slug ); ?>" <?php checked( in_array( $pcpo_slug, $pcpo_enabled, true ) ); ?> />
							<?php echo esc_html( $pcpo_obj->labels->name ); ?> <code><?php echo esc_html( $pcpo_slug ); ?></code>
						</label><br />
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Only enabled post types will be affected by PCPO ordering.', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="pcpo-section-h"><?php esc_html_e( 'Behavior', 'power-custom-post-order' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="pcpo_override_explicit"><?php esc_html_e( 'Override explicit orderby', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_override_explicit" id="pcpo_override_explicit">
						<option value="no" <?php selected( 'no', get_option( 'pcpo_override_explicit', 'no' ) ); ?>><?php esc_html_e( 'No — respect the widget / theme', 'power-custom-post-order' ); ?></option>
						<option value="yes" <?php selected( 'yes', get_option( 'pcpo_override_explicit', 'no' ) ); ?>><?php esc_html_e( 'Yes — override and apply PCPO ordering', 'power-custom-post-order' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'When an Elementor / theme query already sets orderby (e.g. "by date"), should PCPO still apply?', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcpo_multi_term_strategy"><?php esc_html_e( 'Multi-term strategy', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_multi_term_strategy" id="pcpo_multi_term_strategy">
						<option value="primary" <?php selected( 'primary', get_option( 'pcpo_multi_term_strategy', 'primary' ) ); ?>><?php esc_html_e( 'Primary term (Yoast / RankMath / WC) with fallback to global', 'power-custom-post-order' ); ?></option>
						<option value="global" <?php selected( 'global', get_option( 'pcpo_multi_term_strategy', 'primary' ) ); ?>><?php esc_html_e( 'Always use the global order', 'power-custom-post-order' ); ?></option>
						<option value="first" <?php selected( 'first', get_option( 'pcpo_multi_term_strategy', 'primary' ) ); ?>><?php esc_html_e( 'Use the first term in the query', 'power-custom-post-order' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'When a query targets multiple terms, which ordering wins?', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcpo_sync_wc_menu_order"><?php esc_html_e( 'Sync to WooCommerce menu_order', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_sync_wc_menu_order" id="pcpo_sync_wc_menu_order">
						<option value="yes" <?php selected( 'yes', get_option( 'pcpo_sync_wc_menu_order', 'yes' ) ); ?>><?php esc_html_e( 'Yes (recommended) — keep wp_posts.menu_order in sync with global product order', 'power-custom-post-order' ); ?></option>
						<option value="no" <?php selected( 'no', get_option( 'pcpo_sync_wc_menu_order', 'yes' ) ); ?>><?php esc_html_e( 'No — only our own table', 'power-custom-post-order' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Recommended ON for WooCommerce so reports, exports and other plugins see your custom order.', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<tr>
			<th scope="row"><label for="pcpo_debug"><?php esc_html_e( 'Debug mode', 'power-custom-post-order' ); ?></label></th>
			<td>
				<select name="pcpo_debug" id="pcpo_debug">
					<option value="no" <?php selected( 'no', get_option( 'pcpo_debug', 'no' ) ); ?>><?php esc_html_e( 'Off', 'power-custom-post-order' ); ?></option>
					<option value="yes" <?php selected( 'yes', get_option( 'pcpo_debug', 'no' ) ); ?>><?php esc_html_e( 'On — record every query decision and dump as HTML comment in the front-end footer', 'power-custom-post-order' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'When on, PCPO records every query it sees (marked or skipped, with reason), the ORDER BY before and after rewriting, and the final SQL of marked queries. View by visiting any front-end page as a logged-in editor and inspecting the page source (Ctrl-U) — search for "PCPO DEBUG". When WP_DEBUG and PCPO_DEBUG are both on in wp-config.php, the same data is also written to the PHP error log so it survives page caches. Turn OFF on production once diagnosis is done.', 'power-custom-post-order' ); ?></p>
			</td>
		</tr>
		<?php if ( PCPO_WPML::is_active() ) : ?>
			<tr>
				<th scope="row"><label for="pcpo_wpml_sync"><?php esc_html_e( 'WPML — sync order across languages', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_wpml_sync" id="pcpo_wpml_sync">
						<option value="yes" <?php selected( 'yes', get_option( 'pcpo_wpml_sync', 'yes' ) ); ?>><?php esc_html_e( 'Yes (recommended) — mirror manual order, sticky and hidden flags onto translated posts / terms', 'power-custom-post-order' ); ?></option>
						<option value="no" <?php selected( 'no', get_option( 'pcpo_wpml_sync', 'yes' ) ); ?>><?php esc_html_e( 'No — order is stored against the translated post ID only (front-end may diverge between languages)', 'power-custom-post-order' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'WPML stores translations as separate posts. Without sync, an order set on the Hebrew product will not apply when a visitor switches to English. With sync on, every write is mirrored onto each sibling translation in the matching language\'s term.', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="pcpo_random_seed_per_user"><?php esc_html_e( 'Random seed', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_random_seed_per_user" id="pcpo_random_seed_per_user">
						<option value="no" <?php selected( 'no', get_option( 'pcpo_random_seed_per_user', 'no' ) ); ?>><?php esc_html_e( 'Per day (cache-friendly)', 'power-custom-post-order' ); ?></option>
						<option value="yes" <?php selected( 'yes', get_option( 'pcpo_random_seed_per_user', 'no' ) ); ?>><?php esc_html_e( 'Per user per day', 'power-custom-post-order' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<h2 class="pcpo-section-h"><?php esc_html_e( 'Permissions', 'power-custom-post-order' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="pcpo_admin_capability"><?php esc_html_e( 'Admin capability', 'power-custom-post-order' ); ?></label></th>
				<td>
					<input type="text" name="pcpo_admin_capability" id="pcpo_admin_capability" value="<?php echo esc_attr( get_option( 'pcpo_admin_capability', 'manage_options' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Required to access plugin settings, bulk actions, and templates.', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcpo_sortable_capability"><?php esc_html_e( 'Sort capability', 'power-custom-post-order' ); ?></label></th>
				<td>
					<input type="text" name="pcpo_sortable_capability" id="pcpo_sortable_capability" value="<?php echo esc_attr( get_option( 'pcpo_sortable_capability', 'edit_others_posts' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Required to reorder posts via drag-and-drop.', 'power-custom-post-order' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcpo_enable_role_overrides"><?php esc_html_e( 'Role-based ordering', 'power-custom-post-order' ); ?></label></th>
				<td>
					<select name="pcpo_enable_role_overrides" id="pcpo_enable_role_overrides">
						<option value="yes" <?php selected( 'yes', get_option( 'pcpo_enable_role_overrides', 'yes' ) ); ?>><?php esc_html_e( 'Enabled — different roles can see different orders', 'power-custom-post-order' ); ?></option>
						<option value="no" <?php selected( 'no', get_option( 'pcpo_enable_role_overrides', 'yes' ) ); ?>><?php esc_html_e( 'Disabled', 'power-custom-post-order' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Configure per-context role overrides on the Sort page. Logged-out users always see the default order mode.', 'power-custom-post-order' ); ?>
					</p>
					<details>
						<summary><?php esc_html_e( 'Available roles', 'power-custom-post-order' ); ?></summary>
						<ul>
							<?php foreach ( $pcpo_roles as $pcpo_role_slug => $pcpo_role ) : ?>
								<li><code><?php echo esc_html( $pcpo_role_slug ); ?></code> — <?php echo esc_html( translate_user_role( $pcpo_role['name'] ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				</td>
			</tr>
		</table>

		<h2 class="pcpo-section-h"><?php esc_html_e( 'UI', 'power-custom-post-order' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="pcpo_default_per_page"><?php esc_html_e( 'Sort page — items per page', 'power-custom-post-order' ); ?></label></th>
				<td>
					<input type="number" name="pcpo_default_per_page" id="pcpo_default_per_page" min="10" max="500" value="<?php echo esc_attr( (string) get_option( 'pcpo_default_per_page', 50 ) ); ?>" />
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
