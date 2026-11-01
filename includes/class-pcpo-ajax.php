<?php
/**
 * AJAX handlers for the plugin's admin UI.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

// Every public ajax_* handler calls $this->verify() first (check_ajax_referer + capability check).
// phpcs:disable WordPress.Security.NonceVerification.Missing

/**
 * Class PCPO_Ajax
 *
 * AJAX is used for the drag-and-drop sort UI (real-time save on drop) and for
 * bulk operations and quick toggles. All endpoints:
 *   - verify nonce (`pcpo_admin_nonce`)
 *   - verify capability
 *   - sanitize input
 *   - return JSON via wp_send_json_*
 *
 * The richer / more granular operations live on the REST API; AJAX handlers
 * here are for the admin UI's high-frequency interactions where wp-ajax is
 * faster than booting the REST infrastructure.
 */
class PCPO_Ajax {

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
	 * Constructor.
	 *
	 * @param PCPO_Database $db       Database wrapper.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		// Sort page operations.
		add_action( 'wp_ajax_pcpo_get_posts', array( $this, 'ajax_get_posts' ) );
		add_action( 'wp_ajax_pcpo_save_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_pcpo_toggle_sticky', array( $this, 'ajax_toggle_sticky' ) );
		add_action( 'wp_ajax_pcpo_toggle_hidden', array( $this, 'ajax_toggle_hidden' ) );
		add_action( 'wp_ajax_pcpo_move_to_position', array( $this, 'ajax_move_to_position' ) );

		// Bulk operations.
		add_action( 'wp_ajax_pcpo_bulk_shuffle', array( $this, 'ajax_bulk_shuffle' ) );
		add_action( 'wp_ajax_pcpo_bulk_alphabetize', array( $this, 'ajax_bulk_alphabetize' ) );
		add_action( 'wp_ajax_pcpo_bulk_reset', array( $this, 'ajax_bulk_reset' ) );
		add_action( 'wp_ajax_pcpo_bulk_reverse', array( $this, 'ajax_bulk_reverse' ) );

		// Context settings (order mode etc).
		add_action( 'wp_ajax_pcpo_save_context_settings', array( $this, 'ajax_save_context_settings' ) );

		// Term list for the context selector.
		add_action( 'wp_ajax_pcpo_get_terms', array( $this, 'ajax_get_terms' ) );
		add_action( 'wp_ajax_pcpo_get_taxonomies', array( $this, 'ajax_get_taxonomies' ) );
	}

	/**
	 * Verify the AJAX request is authorized and respond with JSON error otherwise.
	 *
	 * @param string $required_cap Capability to require.
	 */
	private function verify( $required_cap = '' ) {
		if ( ! check_ajax_referer( 'pcpo_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'power-custom-post-order' ) ), 403 );
		}
		$cap = $required_cap ? $required_cap : $this->settings->get_sortable_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'power-custom-post-order' ) ), 403 );
		}
	}

	/**
	 * Get a paginated list of posts to display on the sort page.
	 *
	 * Expected POST: post_type, taxonomy (optional), term_id (optional),
	 *                page (default 1), per_page (default 50), search (optional).
	 */
	public function ajax_get_posts() {
		$this->verify();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$taxonomy  = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id   = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$page      = max( 1, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1 );
		$per_page  = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : (int) get_option( 'pcpo_default_per_page', 50 );
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$filters   = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['filters'] ) ) : array();

		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'power-custom-post-order' ) ), 400 );
		}

		// Build WP_Query args. We rely on our own query handler to apply ordering
		// based on our table, BUT for the sort UI we want to see CURRENT ordering
		// so we explicitly use our manual order. show_hidden lets the "Hidden only"
		// filter bypass the default WHERE is_hidden = 0 clause.
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
			'pcpo_skip'      => false,
			's'              => $search,
			'pcpo_apply'     => array(
				'context'     => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'        => 'manual',
				'direction'   => 'ASC',
				'post_type'   => $post_type,
				'show_hidden' => ! empty( $filters['hidden'] ),
			),
		);

		// Restrict to the term if provided.
		if ( $taxonomy && $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}

		// Apply quick filters.
		if ( $filters ) {
			global $wpdb;

			if ( ! empty( $filters['sticky'] ) || ! empty( $filters['hidden'] ) ) {
				$flag       = ! empty( $filters['sticky'] ) ? 'is_sticky' : 'is_hidden';
				$ids_sql    = $wpdb->prepare(
					"SELECT post_id FROM {$this->db->orders_table} WHERE {$flag} = 1 AND taxonomy = %s AND term_id = %d",
					$taxonomy,
					$term_id
				);
				$ids        = $wpdb->get_col( $ids_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $ids_sql is built with $wpdb->prepare() above; $flag is one of two fixed column names.
				$ids        = array_map( 'intval', (array) $ids );
				$args['post__in'] = $ids ? $ids : array( 0 );
			}

			if ( ! empty( $filters['modified_week'] ) ) {
				$args['date_query'] = array(
					array(
						'column' => 'post_modified',
						'after'  => '1 week ago',
					),
				);
			}

			if ( ! empty( $filters['instock'] ) && class_exists( 'WooCommerce' ) && 'product' === $post_type ) {
				$args['meta_query']   = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
				$args['meta_query'][] = array(
					'key'     => '_stock_status',
					'value'   => 'instock',
					'compare' => '=',
				);
			}
		}

		$q     = new WP_Query( $args );
		$posts = array();
		foreach ( $q->posts as $p ) {
			$order_row = $this->db->get_order( $p->ID, $taxonomy, $term_id );
			$posts[]   = array(
				'id'         => (int) $p->ID,
				'title'      => $p->post_title ? $p->post_title : sprintf( '(no title #%d)', $p->ID ),
				'status'     => $p->post_status,
				'type'       => $p->post_type,
				'thumbnail'  => get_the_post_thumbnail_url( $p->ID, array( 120, 120 ) ),
				'menu_order' => $order_row ? (int) $order_row->menu_order : 0,
				'is_sticky'  => $order_row ? (bool) $order_row->is_sticky : false,
				'is_hidden'  => $order_row ? (bool) $order_row->is_hidden : false,
				'edit_link'  => get_edit_post_link( $p->ID, 'raw' ),
				'view_link'  => get_permalink( $p->ID ),
				'date'       => get_the_date( '', $p ),
			);
		}

		wp_send_json_success(
			array(
				'posts'        => $posts,
				'total'        => (int) $q->found_posts,
				'total_pages'  => (int) $q->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			)
		);
	}

	/**
	 * Save the order of posts (after drag-and-drop).
	 *
	 * Expected POST: post_type, taxonomy, term_id, order[] (array of post IDs in order), page, per_page.
	 */
	public function ajax_save_order() {
		$this->verify();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$taxonomy  = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id   = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$order     = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : array();
		$page      = max( 1, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1 );
		$per_page  = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 50;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'No order provided.', 'power-custom-post-order' ) ), 400 );
		}

		$offset = ( $page - 1 ) * $per_page;
		$count  = $this->db->bulk_set_order( $order, $taxonomy, $term_id, $offset );

		// Fire post-update action so the WC sync (and others) can react.
		if ( ! $taxonomy && ! $term_id ) {
			foreach ( $order as $idx => $post_id ) {
				do_action( 'pcpo_after_global_product_update', (int) $post_id, $offset + (int) $idx );
			}
		}

		error_log( '[PCPO][AJAX] save_order ok updated=' . $count . ' taxonomy=' . $taxonomy . ' term_id=' . $term_id );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => $order ) );

		wp_send_json_success(
			array(
				'updated' => $count,
				'message' => __( 'Order saved.', 'power-custom-post-order' ),
			)
		);
	}

	/**
	 * Toggle the sticky flag on a single post in a context.
	 */
	public function ajax_toggle_sticky() {
		$this->verify();
		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$sticky   = isset( $_POST['sticky'] ) ? (bool) wp_unslash( $_POST['sticky'] ) : false;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post id' ), 400 );
		}
		$ok = $this->db->set_sticky( $post_id, $taxonomy, $term_id, $sticky );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => array( $post_id ) ) );

		wp_send_json_success(
			array(
				'updated' => $ok,
				'sticky'  => $sticky,
			)
		);
	}

	/**
	 * Toggle hidden flag on a single post in a context.
	 */
	public function ajax_toggle_hidden() {
		$this->verify();
		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$hidden   = isset( $_POST['hidden'] ) ? (bool) wp_unslash( $_POST['hidden'] ) : false;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post id' ), 400 );
		}
		$ok = $this->db->set_hidden( $post_id, $taxonomy, $term_id, $hidden );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => array( $post_id ) ) );

		wp_send_json_success(
			array(
				'updated' => $ok,
				'hidden'  => $hidden,
			)
		);
	}

	/**
	 * Move a single post to a specific position (1-based).
	 * Useful for large lists where drag-drop is impractical.
	 */
	public function ajax_move_to_position() {
		$this->verify();
		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$position = isset( $_POST['position'] ) ? max( 1, absint( wp_unslash( $_POST['position'] ) ) ) : 1;
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post id' ), 400 );
		}

		// Re-fetch all ordered post IDs in this context.
		$args = array(
			'post_type'      => $post_type ? $post_type : get_post_type( $post_id ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'pcpo_apply'     => array(
				'context'   => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'      => 'manual',
				'direction' => 'ASC',
				'post_type' => $post_type,
			),
		);
		if ( $taxonomy && $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}
		$ids = get_posts( $args );
		// Remove current position and re-insert at desired position.
		$ids       = array_values( array_diff( $ids, array( $post_id ) ) );
		$new_index = min( max( 0, $position - 1 ), count( $ids ) );
		array_splice( $ids, $new_index, 0, $post_id );

		$count = $this->db->bulk_set_order( $ids, $taxonomy, $term_id, 0 );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => $ids ) );

		wp_send_json_success(
			array(
				'updated' => $count,
				'new_order' => $ids,
			)
		);
	}

	/**
	 * Bulk: shuffle all posts in a context.
	 */
	public function ajax_bulk_shuffle() {
		$this->verify( $this->settings->get_admin_capability() );
		list( $post_type, $taxonomy, $term_id ) = $this->read_context();
		$ids = $this->get_all_ids_in_context( $post_type, $taxonomy, $term_id );
		shuffle( $ids );
		$count = $this->db->bulk_set_order( $ids, $taxonomy, $term_id, 0 );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => $ids ) );

		wp_send_json_success(
			array(
				'updated' => $count,
				'message' => __( 'Shuffled.', 'power-custom-post-order' ),
			)
		);
	}

	/**
	 * Bulk: alphabetize all posts in a context.
	 */
	public function ajax_bulk_alphabetize() {
		$this->verify( $this->settings->get_admin_capability() );
		list( $post_type, $taxonomy, $term_id ) = $this->read_context();
		$direction = isset( $_POST['direction'] ) && 'desc' === strtolower( wp_unslash( $_POST['direction'] ) ) ? 'DESC' : 'ASC';

		$ids = $this->get_all_ids_in_context( $post_type, $taxonomy, $term_id );

		// Get titles and sort.
		$pairs = array();
		foreach ( $ids as $id ) {
			$pairs[ $id ] = get_the_title( $id );
		}
		if ( 'DESC' === $direction ) {
			arsort( $pairs, SORT_NATURAL | SORT_FLAG_CASE );
		} else {
			asort( $pairs, SORT_NATURAL | SORT_FLAG_CASE );
		}
		$ordered = array_keys( $pairs );
		$count   = $this->db->bulk_set_order( $ordered, $taxonomy, $term_id, 0 );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => $ordered ) );

		wp_send_json_success(
			array(
				'updated' => $count,
				'message' => __( 'Alphabetized.', 'power-custom-post-order' ),
			)
		);
	}

	/**
	 * Bulk: reset (delete all orders) for a context.
	 */
	public function ajax_bulk_reset() {
		$this->verify( $this->settings->get_admin_capability() );
		list( $post_type, $taxonomy, $term_id ) = $this->read_context();
		$count = $this->db->reset_context( $taxonomy, $term_id, $post_type );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id ) );

		wp_send_json_success(
			array(
				'deleted' => $count,
				'message' => __( 'Order reset.', 'power-custom-post-order' ),
			)
		);
	}

	/**
	 * Bulk: reverse the current order.
	 */
	public function ajax_bulk_reverse() {
		$this->verify( $this->settings->get_admin_capability() );
		list( $post_type, $taxonomy, $term_id ) = $this->read_context();
		$ids   = $this->get_all_ids_in_context( $post_type, $taxonomy, $term_id );
		$ids   = array_reverse( $ids );
		$count = $this->db->bulk_set_order( $ids, $taxonomy, $term_id, 0 );

		do_action( 'pcpo_order_changed', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'ids' => $ids ) );

		wp_send_json_success(
			array(
				'updated' => $count,
				'message' => __( 'Order reversed.', 'power-custom-post-order' ),
			)
		);
	}

	/**
	 * Save context settings (order mode etc).
	 */
	public function ajax_save_context_settings() {
		$this->verify( $this->settings->get_admin_capability() );

		$context_type  = isset( $_POST['context_type'] ) ? sanitize_key( wp_unslash( $_POST['context_type'] ) ) : '';
		$context_value = isset( $_POST['context_value'] ) ? sanitize_text_field( wp_unslash( $_POST['context_value'] ) ) : '';
		$mode          = isset( $_POST['order_mode'] ) ? sanitize_key( wp_unslash( $_POST['order_mode'] ) ) : 'manual';
		$direction     = isset( $_POST['order_direction'] ) ? sanitize_key( wp_unslash( $_POST['order_direction'] ) ) : 'ASC';
		$role_raw      = isset( $_POST['role_overrides'] ) ? (array) wp_unslash( $_POST['role_overrides'] ) : array();

		$role_overrides = array();
		foreach ( $role_raw as $role => $val ) {
			$role = sanitize_key( $role );
			$val  = sanitize_text_field( $val );
			if ( $role && $val ) {
				$role_overrides[ $role ] = $val;
			}
		}

		$ok = $this->db->save_context_settings(
			array(
				'context_type'    => $context_type,
				'context_value'   => $context_value,
				'order_mode'      => $mode,
				'order_direction' => $direction,
				'role_overrides'  => $role_overrides,
			)
		);

		do_action( 'pcpo_order_changed', array( 'context_type' => $context_type, 'context_value' => $context_value ) );

		wp_send_json_success( array( 'saved' => (bool) $ok ) );
	}

	/**
	 * Get taxonomies attached to a post type (for the context picker).
	 */
	public function ajax_get_taxonomies() {
		$this->verify();
		$post_type  = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$out        = array();
		foreach ( $taxonomies as $t ) {
			if ( ! $t->public && ! $t->show_ui ) {
				continue;
			}
			$out[] = array(
				'slug' => $t->name,
				'name' => $t->labels->singular_name,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * Get terms for a taxonomy (for the context picker).
	 */
	public function ajax_get_terms() {
		$this->verify();
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$args     = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 100,
		);
		if ( $search ) {
			$args['search'] = $search;
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( array( 'message' => $terms->get_error_message() ), 400 );
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => $t->name,
				'count' => (int) $t->count,
			);
		}
		wp_send_json_success( $out );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Read context (post_type, taxonomy, term_id) from POST.
	 *
	 * @return array
	 */
	private function read_context() {
		return array(
			isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '',
			isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '',
			isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0,
		);
	}

	/**
	 * Get all post IDs in a context (used by bulk operations).
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $term_id   Term ID.
	 * @return array<int>
	 */
	private function get_all_ids_in_context( $post_type, $taxonomy, $term_id ) {
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
			'pcpo_skip'      => true,
		);
		if ( $taxonomy && $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}
		return get_posts( $args );
	}
}
