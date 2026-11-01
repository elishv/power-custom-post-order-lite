<?php
/**
 * Dashboard view.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$orders_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->orders_table}" );
$settings_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->settings_table}" );
$templates_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->templates_table}" );
$sticky_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->orders_table} WHERE is_sticky = 1" );
$hidden_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->orders_table} WHERE is_hidden = 1" );

$enabled_pts = $settings->get_enabled_post_types();
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-sort"></span>
		<?php esc_html_e( 'Power Custom Post Order', 'power-custom-post-order' ); ?>
		<small class="pcpo-version">v<?php echo esc_html( PCPO_VERSION ); ?></small>
	</h1>

	<p class="pcpo-lead">
		<?php esc_html_e( 'Drag-and-drop ordering for any post type, with per-taxonomy ordering, sticky / hidden posts, templates, CSV import-export, and a REST API. Works with every theme — including Elementor.', 'power-custom-post-order' ); ?>
	</p>

	<div class="pcpo-stat-grid">
		<div class="pcpo-stat">
			<div class="pcpo-stat__value"><?php echo esc_html( number_format_i18n( $orders_count ) ); ?></div>
			<div class="pcpo-stat__label"><?php esc_html_e( 'Ordered items', 'power-custom-post-order' ); ?></div>
		</div>
		<div class="pcpo-stat">
			<div class="pcpo-stat__value"><?php echo esc_html( number_format_i18n( $sticky_count ) ); ?></div>
			<div class="pcpo-stat__label"><?php esc_html_e( 'Pinned items', 'power-custom-post-order' ); ?></div>
		</div>
		<div class="pcpo-stat">
			<div class="pcpo-stat__value"><?php echo esc_html( number_format_i18n( $hidden_count ) ); ?></div>
			<div class="pcpo-stat__label"><?php esc_html_e( 'Hidden items', 'power-custom-post-order' ); ?></div>
		</div>
		<div class="pcpo-stat">
			<div class="pcpo-stat__value"><?php echo esc_html( number_format_i18n( $settings_count ) ); ?></div>
			<div class="pcpo-stat__label"><?php esc_html_e( 'Context rules', 'power-custom-post-order' ); ?></div>
		</div>
		<div class="pcpo-stat">
			<div class="pcpo-stat__value"><?php echo esc_html( number_format_i18n( $templates_count ) ); ?></div>
			<div class="pcpo-stat__label"><?php esc_html_e( 'Saved templates', 'power-custom-post-order' ); ?></div>
		</div>
	</div>

	<div class="pcpo-card-row">
		<div class="pcpo-card-block">
			<h2><?php esc_html_e( 'Quick start', 'power-custom-post-order' ); ?></h2>
			<ol class="pcpo-ol">
				<li><?php printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=pcpo-settings' ) ), esc_html__( 'Choose which post types to enable', 'power-custom-post-order' ) ); ?></li>
				<li><?php printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=pcpo-sort' ) ), esc_html__( 'Open the Sort Posts page and drag items into place', 'power-custom-post-order' ) ); ?></li>
				<li><?php esc_html_e( 'Use sticky / hidden flags per term as needed', 'power-custom-post-order' ); ?></li>
				<li><?php esc_html_e( 'Save the result as a Template to re-use across terms', 'power-custom-post-order' ); ?></li>
			</ol>
		</div>

		<div class="pcpo-card-block">
			<h2><?php esc_html_e( 'Enabled post types', 'power-custom-post-order' ); ?></h2>
			<ul class="pcpo-ul">
				<?php
				foreach ( $enabled_pts as $pt_slug ) :
					$pt_obj = get_post_type_object( $pt_slug );
					if ( ! $pt_obj ) {
						continue;
					}
					?>
					<li>
						<strong><?php echo esc_html( $pt_obj->labels->name ); ?></strong>
						<code><?php echo esc_html( $pt_slug ); ?></code>
						<a href="<?php echo esc_url( add_query_arg( 'post_type', $pt_slug, admin_url( 'admin.php?page=pcpo-sort' ) ) ); ?>">
							<?php esc_html_e( 'Sort →', 'power-custom-post-order' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="pcpo-card-block">
			<h2><?php esc_html_e( 'Integrations detected', 'power-custom-post-order' ); ?></h2>
			<ul class="pcpo-ul">
				<li>
					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
						<span class="dashicons dashicons-yes-alt pcpo-ok"></span> <?php esc_html_e( 'WooCommerce active — menu_order sync enabled', 'power-custom-post-order' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-minus pcpo-muted"></span> <?php esc_html_e( 'WooCommerce not detected', 'power-custom-post-order' ); ?>
					<?php endif; ?>
				</li>
				<li>
					<?php if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) : ?>
						<span class="dashicons dashicons-yes-alt pcpo-ok"></span> <?php esc_html_e( 'Elementor active — opt-in via Query ID "pcpo_ordering"', 'power-custom-post-order' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-minus pcpo-muted"></span> <?php esc_html_e( 'Elementor not detected', 'power-custom-post-order' ); ?>
					<?php endif; ?>
				</li>
				<li>
					<span class="dashicons dashicons-yes-alt pcpo-ok"></span> <?php esc_html_e( 'REST API enabled', 'power-custom-post-order' ); ?>
					<code>/wp-json/pcpo/v1/</code>
				</li>
			</ul>
		</div>
	</div>

	<div class="pcpo-card-block">
		<h2><?php esc_html_e( 'For developers', 'power-custom-post-order' ); ?></h2>
		<p><?php esc_html_e( 'Use these template tags in your theme:', 'power-custom-post-order' ); ?></p>
		<pre class="pcpo-code"><code>// Get posts in custom order
$posts = pcpo_get_posts( array(
    'post_type'  => 'product',
    'taxonomy'   => 'product_cat',
    'term'       => 'shoes',
    'order_mode' => 'manual',
) );

// Or use as a full WP_Query
$q = pcpo_the_query( array(
    'post_type' => 'product',
    'order_mode' => 'popularity',
) );
while ( $q->have_posts() ) { $q->the_post(); /* ... */ }
wp_reset_postdata();

// Or as shortcode
[pcpo_posts post_type="product" taxonomy="product_cat" term="shoes" limit="12"]</code></pre>
	</div>
</div>
