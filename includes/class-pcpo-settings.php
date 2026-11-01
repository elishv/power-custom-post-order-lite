<?php
/**
 * Settings registry / facade.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Settings
 *
 * Wraps wp_options reads with sensible defaults and exposes the catalog of
 * supported order modes. This is also the canonical place to translate
 * an "order mode" (manual / title_asc / popularity / ...) into the SQL
 * ORDER BY clause WordPress should use.
 */
class PCPO_Settings {

	/**
	 * Catalog of supported order modes keyed by slug.
	 *
	 * Each entry: label, sql_orderby (for posts_clauses), requires_woocommerce (bool).
	 *
	 * @return array
	 */
	public function get_order_modes() {
		$modes = array(
			'manual'        => array(
				'label'   => __( 'Manual (drag & drop)', 'power-custom-post-order' ),
				'orderby' => null, // Handled specially via our JOIN.
			),
			'inherit'       => array(
				'label'   => __( 'Inherit from parent / global', 'power-custom-post-order' ),
				'orderby' => null,
			),
			'menu_order'    => array(
				'label'   => __( 'Default WordPress menu order', 'power-custom-post-order' ),
				'orderby' => 'menu_order',
			),
			'title_asc'     => array(
				'label'     => __( 'Title A → Z', 'power-custom-post-order' ),
				'orderby'   => 'post_title',
				'direction' => 'ASC',
			),
			'title_desc'    => array(
				'label'     => __( 'Title Z → A', 'power-custom-post-order' ),
				'orderby'   => 'post_title',
				'direction' => 'DESC',
			),
			'date_desc'     => array(
				'label'     => __( 'Date — newest first', 'power-custom-post-order' ),
				'orderby'   => 'post_date',
				'direction' => 'DESC',
			),
			'date_asc'      => array(
				'label'     => __( 'Date — oldest first', 'power-custom-post-order' ),
				'orderby'   => 'post_date',
				'direction' => 'ASC',
			),
			'modified_desc' => array(
				'label'     => __( 'Last modified — newest first', 'power-custom-post-order' ),
				'orderby'   => 'post_modified',
				'direction' => 'DESC',
			),
			'random'        => array(
				'label'   => __( 'Random (with daily seed)', 'power-custom-post-order' ),
				'orderby' => 'random',
			),
			// WooCommerce-only modes.
			'price_asc'     => array(
				'label'                => __( 'Price low → high', 'power-custom-post-order' ),
				'orderby'              => 'meta:_price',
				'direction'            => 'ASC',
				'requires_woocommerce' => true,
			),
			'price_desc'    => array(
				'label'                => __( 'Price high → low', 'power-custom-post-order' ),
				'orderby'              => 'meta:_price',
				'direction'            => 'DESC',
				'requires_woocommerce' => true,
			),
			'popularity'    => array(
				'label'                => __( 'Popularity (total sales)', 'power-custom-post-order' ),
				'orderby'              => 'meta:total_sales',
				'direction'            => 'DESC',
				'requires_woocommerce' => true,
			),
			'rating'        => array(
				'label'                => __( 'Average rating', 'power-custom-post-order' ),
				'orderby'              => 'meta:_wc_average_rating',
				'direction'            => 'DESC',
				'requires_woocommerce' => true,
			),
			'stock_status'  => array(
				'label'                => __( 'Stock status (in stock first)', 'power-custom-post-order' ),
				'orderby'              => 'meta:_stock_status',
				'direction'            => 'ASC',
				'requires_woocommerce' => true,
			),
		);
		/**
		 * Allow themes / addons to register additional order modes.
		 *
		 * @param array $modes Catalog.
		 */
		return apply_filters( 'pcpo_order_modes', $modes );
	}

	/**
	 * Get a single order mode definition.
	 *
	 * @param string $mode Mode slug.
	 * @return array|null
	 */
	public function get_mode( $mode ) {
		$catalog = $this->get_order_modes();
		return isset( $catalog[ $mode ] ) ? $catalog[ $mode ] : null;
	}

	/**
	 * Get enabled post types (settings page selection).
	 *
	 * @return array
	 */
	public function get_enabled_post_types() {
		$option = get_option( 'pcpo_enabled_post_types', array( 'post', 'page', 'product' ) );
		if ( ! is_array( $option ) ) {
			$option = array( 'post' );
		}
		/**
		 * Filter the list of post types the plugin operates on.
		 *
		 * @param array $option Post type slugs.
		 */
		return apply_filters( 'pcpo_enabled_post_types', $option );
	}

	/**
	 * Check whether a given post type is enabled.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public function is_post_type_enabled( $post_type ) {
		return in_array( $post_type, $this->get_enabled_post_types(), true );
	}

	/**
	 * Should we override queries that explicitly set orderby?
	 * (Answer to Q3 — user picked "global toggle in plugin settings".)
	 *
	 * @return bool
	 */
	public function should_override_explicit() {
		return 'yes' === get_option( 'pcpo_override_explicit', 'no' );
	}

	/**
	 * Should we sync product global order back to WC's menu_order?
	 *
	 * @return bool
	 */
	public function should_sync_wc_menu_order() {
		return 'yes' === get_option( 'pcpo_sync_wc_menu_order', 'yes' );
	}

	/**
	 * Multi-term conflict strategy.
	 *
	 * @return string 'primary' | 'global' | 'first'
	 */
	public function get_multi_term_strategy() {
		$val   = get_option( 'pcpo_multi_term_strategy', 'primary' );
		$valid = array( 'primary', 'global', 'first' );
		return in_array( $val, $valid, true ) ? $val : 'primary';
	}

	/**
	 * Capability required to access admin pages.
	 *
	 * @return string
	 */
	public function get_admin_capability() {
		return apply_filters( 'pcpo_admin_capability', get_option( 'pcpo_admin_capability', 'manage_options' ) );
	}

	/**
	 * Capability required to sort posts (more permissive).
	 *
	 * @return string
	 */
	public function get_sortable_capability() {
		return apply_filters( 'pcpo_sortable_capability', get_option( 'pcpo_sortable_capability', 'edit_others_posts' ) );
	}

	/**
	 * Get the effective order mode for a given context, resolved against the
	 * current user's role (if role overrides are configured).
	 *
	 * @param string $context_type  'post_type' | 'taxonomy' | 'term'.
	 * @param string $context_value Context value.
	 * @return array{mode:string,direction:string}
	 */
	public function resolve_mode_for_context( $context_type, $context_value ) {
		$db       = PCPO_Plugin::instance()->db;
		$settings = $db->get_context_settings( $context_type, $context_value );

		$mode      = 'manual';
		$direction = 'ASC';

		if ( $settings ) {
			$mode      = $settings->order_mode ? $settings->order_mode : 'manual';
			$direction = $settings->order_direction ? $settings->order_direction : 'ASC';

			// Per-role override resolution.
			if ( 'yes' === get_option( 'pcpo_enable_role_overrides', 'yes' ) && ! empty( $settings->role_overrides ) ) {
				$role_overrides = json_decode( $settings->role_overrides, true );
				if ( is_array( $role_overrides ) && is_user_logged_in() ) {
					$user = wp_get_current_user();
					foreach ( (array) $user->roles as $role ) {
						if ( ! empty( $role_overrides[ $role ] ) ) {
							$override_mode = $role_overrides[ $role ];
							// Allow override of just the mode, or a mode|direction pair.
							if ( strpos( $override_mode, '|' ) !== false ) {
								list( $mode, $direction ) = explode( '|', $override_mode, 2 );
							} else {
								$mode = $override_mode;
							}
							break;
						}
					}
				}
			}
		}

		return array(
			'mode'      => $mode,
			'direction' => $direction,
		);
	}
}
