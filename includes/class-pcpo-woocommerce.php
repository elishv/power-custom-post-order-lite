<?php
/**
 * WooCommerce integration: bi-directional sync with menu_order
 * and additional WC-aware behaviors.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_WooCommerce
 *
 * Handles:
 *   - Syncing our global product orders to WC's wp_posts.menu_order
 *     (Q1 answer: "Yes — sync global product order to WC menu_order").
 *   - Picking up changes made on WC's native "Sort Products" page and importing
 *     them into our table so the two stay aligned.
 *   - Honoring WooCommerce's "Default product sorting" setting unless our
 *     plugin has a more specific rule for that context.
 */
class PCPO_WooCommerce {

	/**
	 * Database wrapper.
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
	 * Constructor.
	 *
	 * @param PCPO_Database $db       Database wrapper.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		if ( ! $this->settings->should_sync_wc_menu_order() ) {
			return;
		}

		// When our table is updated for a product globally, push to wp_posts.menu_order.
		add_action( 'pcpo_after_global_product_update', array( $this, 'sync_to_wc_menu_order' ), 10, 2 );

		// When WC's Sort Products page updates menu_order, mirror it into our table.
		add_action( 'woocommerce_after_product_object_save', array( $this, 'capture_wc_menu_order_change' ), 10, 1 );

		// Default to product_cat as the most relevant taxonomy for the admin sort page.
		add_filter( 'pcpo_default_taxonomy_for_post_type', array( $this, 'default_product_taxonomy' ), 10, 2 );
	}

	/**
	 * Write our table's global menu_order for a product back to wp_posts.menu_order.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $menu_order Menu order value.
	 */
	public function sync_to_wc_menu_order( $post_id, $menu_order ) {
		$post_id = absint( $post_id );
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		// Use $wpdb directly to avoid triggering save_post → infinite loop with capture below.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'menu_order' => (int) $menu_order ),
			array( 'ID' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );
		PCPO_Debug::log( '[PCPO][WC] synced wp_posts.menu_order post_id=' . $post_id . ' value=' . (int) $menu_order );
	}

	/**
	 * If a product's menu_order changes outside of our plugin (e.g. via WC's
	 * Sort Products page), reflect that in our global row so we don't drift.
	 *
	 * @param WC_Product $product The saved product.
	 */
	public function capture_wc_menu_order_change( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$post_id = $product->get_id();
		// Avoid recursion: only pull if there's no current global row OR it differs.
		$existing = $this->db->get_order( $post_id, '', 0 );
		$wp_menu  = (int) get_post_field( 'menu_order', $post_id );
		if ( ! $existing || (int) $existing->menu_order !== $wp_menu ) {
			$this->db->upsert_order(
				array(
					'post_id'    => $post_id,
					'post_type'  => 'product',
					'taxonomy'   => '',
					'term_id'    => 0,
					'menu_order' => $wp_menu,
				)
			);
		}
	}

	/**
	 * Default to product_cat for products in the admin sort UI.
	 *
	 * @param string $taxonomy  Current default.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function default_product_taxonomy( $taxonomy, $post_type ) {
		if ( 'product' === $post_type && ! $taxonomy ) {
			return 'product_cat';
		}
		return $taxonomy;
	}
}
