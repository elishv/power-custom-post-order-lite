<?php
/**
 * Database access layer for the plugin.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Database
 *
 * Single point of access to the plugin's tables. All SQL lives here so it
 * can be prepared, audited and unit-tested consistently. Every public method
 * is safe to call with untrusted input — sanitization happens at the boundary.
 *
 * Conventions:
 *  - taxonomy = '' AND term_id = 0  --> "global" / no taxonomy context
 *  - menu_order is signed INT so negative values can place items above others
 */
class PCPO_Database {

	/**
	 * Orders table name (with prefix).
	 *
	 * @var string
	 */
	public $orders_table;

	/**
	 * Settings table name.
	 *
	 * @var string
	 */
	public $settings_table;

	/**
	 * Templates table name.
	 *
	 * @var string
	 */
	public $templates_table;

	/**
	 * Constructor: cache table names.
	 */
	public function __construct() {
		global $wpdb;
		$this->orders_table    = $wpdb->prefix . 'pcpo_orders';
		$this->settings_table  = $wpdb->prefix . 'pcpo_settings';
		$this->templates_table = $wpdb->prefix . 'pcpo_templates';
	}

	/* ---------------------------------------------------------------------
	 * ORDERS
	 * ------------------------------------------------------------------- */

	/**
	 * Upsert an order row.
	 *
	 * @param array $data Required: post_id. Optional: post_type, taxonomy,
	 *                    term_id, menu_order, is_sticky, is_hidden.
	 * @return int|false Row ID on success, false on failure.
	 */
	public function upsert_order( $data ) {
		global $wpdb;

		$defaults = array(
			'post_id'    => 0,
			'post_type'  => '',
			'taxonomy'   => '',
			'term_id'    => 0,
			'menu_order' => 0,
			'is_sticky'  => 0,
			'is_hidden'  => 0,
		);
		$data     = wp_parse_args( $data, $defaults );

		$data['post_id']    = absint( $data['post_id'] );
		$data['post_type']  = sanitize_key( $data['post_type'] );
		$data['taxonomy']   = sanitize_key( $data['taxonomy'] );
		$data['term_id']    = absint( $data['term_id'] );
		$data['menu_order'] = (int) $data['menu_order'];
		$data['is_sticky']  = $data['is_sticky'] ? 1 : 0;
		$data['is_hidden']  = $data['is_hidden'] ? 1 : 0;

		if ( ! $data['post_id'] ) {
			return false;
		}

		if ( ! $data['post_type'] ) {
			$data['post_type'] = get_post_type( $data['post_id'] );
		}

		$now = current_time( 'mysql' );

		// Try INSERT ... ON DUPLICATE KEY UPDATE behavior via WP's wpdb.
		// We use a manual prepared query because $wpdb->replace can't do partial updates.
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->orders_table}
				(post_id, post_type, taxonomy, term_id, menu_order, is_sticky, is_hidden, created_at, updated_at)
			 VALUES (%d, %s, %s, %d, %d, %d, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE
				post_type  = VALUES(post_type),
				menu_order = VALUES(menu_order),
				is_sticky  = VALUES(is_sticky),
				is_hidden  = VALUES(is_hidden),
				updated_at = VALUES(updated_at)",
			$data['post_id'],
			$data['post_type'],
			$data['taxonomy'],
			$data['term_id'],
			$data['menu_order'],
			$data['is_sticky'],
			$data['is_hidden'],
			$now,
			$now
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			error_log( '[PCPO][DB] upsert_order failed: ' . $wpdb->last_error . ' :: data=' . wp_json_encode( $data ) );
			return false;
		}
		$this->invalidate_term_cache( $data['taxonomy'], $data['term_id'] );

		/**
		 * Fires after a row is upserted. Used by the WPML integration to
		 * mirror writes onto translated post/term pairs.
		 *
		 * @param array $data The normalized row data.
		 */
		do_action( 'pcpo_after_upsert_order', $data );

		return $wpdb->insert_id ? (int) $wpdb->insert_id : true;
	}

	/**
	 * Bulk update menu_order for a list of post IDs in a given context.
	 * Order in the array IS the order on the page (index 0 = top).
	 *
	 * @param array  $post_ids Ordered list of post IDs.
	 * @param string $taxonomy Taxonomy or '' for global.
	 * @param int    $term_id  Term ID or 0 for global.
	 * @param int    $offset   Starting menu_order value (default 0). Useful for pagination.
	 * @return int Number of rows touched.
	 */
	public function bulk_set_order( array $post_ids, $taxonomy = '', $term_id = 0, $offset = 0 ) {
		$taxonomy = sanitize_key( $taxonomy );
		$term_id  = absint( $term_id );
		$count    = 0;
		foreach ( $post_ids as $position => $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				continue;
			}
			$ok = $this->upsert_order(
				array(
					'post_id'    => $post_id,
					'taxonomy'   => $taxonomy,
					'term_id'    => $term_id,
					'menu_order' => $offset + (int) $position,
				)
			);
			if ( $ok ) {
				++$count;
			}
		}
		error_log( '[PCPO][DB] bulk_set_order touched=' . $count . ' taxonomy=' . $taxonomy . ' term_id=' . $term_id );
		return $count;
	}

	/**
	 * Get a single order row.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @return object|null Row or null.
	 */
	public function get_order( $post_id, $taxonomy = '', $term_id = 0 ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->orders_table} WHERE post_id = %d AND taxonomy = %s AND term_id = %d LIMIT 1",
				absint( $post_id ),
				sanitize_key( $taxonomy ),
				absint( $term_id )
			)
		);
	}

	/**
	 * Get ordered rows for a context (used by admin sort UI).
	 *
	 * @param array $args post_type, taxonomy, term_id, include_hidden, limit, offset.
	 * @return array<int, object>
	 */
	public function get_ordered_rows( array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'post_type'      => '',
				'taxonomy'       => '',
				'term_id'        => 0,
				'include_hidden' => true,
				'limit'          => 0,
				'offset'         => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();
		if ( $args['post_type'] ) {
			$where[]  = 'post_type = %s';
			$params[] = sanitize_key( $args['post_type'] );
		}
		$where[]  = 'taxonomy = %s';
		$params[] = sanitize_key( $args['taxonomy'] );
		$where[]  = 'term_id = %d';
		$params[] = absint( $args['term_id'] );
		if ( ! $args['include_hidden'] ) {
			$where[] = 'is_hidden = 0';
		}

		$sql = 'SELECT * FROM ' . $this->orders_table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY is_sticky DESC, menu_order ASC, id ASC';
		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = absint( $args['limit'] );
			$params[] = absint( $args['offset'] );
		}
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Toggle sticky flag on a single row.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @param bool   $sticky   New value.
	 * @return bool Success.
	 */
	public function set_sticky( $post_id, $taxonomy, $term_id, $sticky ) {
		return (bool) $this->upsert_order(
			array(
				'post_id'   => $post_id,
				'taxonomy'  => $taxonomy,
				'term_id'   => $term_id,
				'is_sticky' => $sticky ? 1 : 0,
			)
		);
	}

	/**
	 * Toggle hidden flag on a single row.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @param bool   $hidden   New value.
	 * @return bool Success.
	 */
	public function set_hidden( $post_id, $taxonomy, $term_id, $hidden ) {
		return (bool) $this->upsert_order(
			array(
				'post_id'   => $post_id,
				'taxonomy'  => $taxonomy,
				'term_id'   => $term_id,
				'is_hidden' => $hidden ? 1 : 0,
			)
		);
	}

	/**
	 * Delete an order row entirely.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @return int Rows deleted.
	 */
	public function delete_order( $post_id, $taxonomy = '', $term_id = 0 ) {
		global $wpdb;
		$deleted = $wpdb->delete(
			$this->orders_table,
			array(
				'post_id'  => absint( $post_id ),
				'taxonomy' => sanitize_key( $taxonomy ),
				'term_id'  => absint( $term_id ),
			),
			array( '%d', '%s', '%d' )
		);
		$this->invalidate_term_cache( $taxonomy, $term_id );

		do_action( 'pcpo_after_delete_order', absint( $post_id ), sanitize_key( $taxonomy ), absint( $term_id ) );

		return (int) $deleted;
	}

	/**
	 * Delete all orders for a given post (called when a post is permanently deleted).
	 *
	 * @param int $post_id Post ID.
	 * @return int Rows deleted.
	 */
	public function delete_orders_for_post( $post_id ) {
		global $wpdb;
		return (int) $wpdb->delete(
			$this->orders_table,
			array( 'post_id' => absint( $post_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Delete all orders in a context (used by Reset).
	 *
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @param string $post_type Optional post type filter.
	 * @return int Rows deleted.
	 */
	public function reset_context( $taxonomy = '', $term_id = 0, $post_type = '' ) {
		global $wpdb;
		$where  = array(
			'taxonomy' => sanitize_key( $taxonomy ),
			'term_id'  => absint( $term_id ),
		);
		$format = array( '%s', '%d' );
		if ( $post_type ) {
			$where['post_type'] = sanitize_key( $post_type );
			$format[]           = '%s';
		}
		$deleted = $wpdb->delete( $this->orders_table, $where, $format );
		$this->invalidate_term_cache( $taxonomy, $term_id );

		do_action( 'pcpo_after_reset_context', sanitize_key( $taxonomy ), absint( $term_id ), sanitize_key( $post_type ) );

		return (int) $deleted;
	}

	/* ---------------------------------------------------------------------
	 * SETTINGS (per-context)
	 * ------------------------------------------------------------------- */

	/**
	 * Get context settings.
	 *
	 * @param string $context_type 'post_type' | 'taxonomy' | 'term'.
	 * @param string $context_value The value (e.g. 'product' or 'product_cat:42').
	 * @return object|null
	 */
	public function get_context_settings( $context_type, $context_value ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->settings_table} WHERE context_type = %s AND context_value = %s LIMIT 1",
				sanitize_key( $context_type ),
				sanitize_text_field( $context_value )
			)
		);
	}

	/**
	 * Save context settings.
	 *
	 * @param array $args context_type, context_value, order_mode, order_direction, role_overrides.
	 * @return int|false Row ID or false.
	 */
	public function save_context_settings( $args ) {
		global $wpdb;
		$defaults = array(
			'context_type'    => '',
			'context_value'   => '',
			'order_mode'      => 'manual',
			'order_direction' => 'ASC',
			'role_overrides'  => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$role_overrides = null;
		if ( is_array( $args['role_overrides'] ) ) {
			$role_overrides = wp_json_encode( $args['role_overrides'] );
		} elseif ( is_string( $args['role_overrides'] ) ) {
			$role_overrides = $args['role_overrides'];
		}

		$sql = $wpdb->prepare(
			"INSERT INTO {$this->settings_table}
				(context_type, context_value, order_mode, order_direction, role_overrides, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
				order_mode      = VALUES(order_mode),
				order_direction = VALUES(order_direction),
				role_overrides  = VALUES(role_overrides),
				updated_at      = VALUES(updated_at)",
			sanitize_key( $args['context_type'] ),
			sanitize_text_field( $args['context_value'] ),
			sanitize_key( $args['order_mode'] ),
			'DESC' === strtoupper( $args['order_direction'] ) ? 'DESC' : 'ASC',
			$role_overrides,
			current_time( 'mysql' )
		);
		return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete context settings.
	 *
	 * @param string $context_type  Context type.
	 * @param string $context_value Context value.
	 * @return int Rows deleted.
	 */
	public function delete_context_settings( $context_type, $context_value ) {
		global $wpdb;
		return (int) $wpdb->delete(
			$this->settings_table,
			array(
				'context_type'  => sanitize_key( $context_type ),
				'context_value' => sanitize_text_field( $context_value ),
			),
			array( '%s', '%s' )
		);
	}

	/* ---------------------------------------------------------------------
	 * TEMPLATES
	 * ------------------------------------------------------------------- */

	/**
	 * Create an order template (snapshot of a current order).
	 *
	 * @param array $args name, post_type, description, order_data (array of post_ids in order).
	 * @return int|false Row ID.
	 */
	public function create_template( $args ) {
		global $wpdb;
		$defaults = array(
			'name'        => '',
			'post_type'   => '',
			'description' => '',
			'order_data'  => array(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$result = $wpdb->insert(
			$this->templates_table,
			array(
				'name'        => sanitize_text_field( $args['name'] ),
				'post_type'   => sanitize_key( $args['post_type'] ),
				'description' => wp_kses_post( $args['description'] ),
				'order_data'  => wp_json_encode( array_map( 'absint', (array) $args['order_data'] ) ),
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a single template by ID.
	 *
	 * @param int $template_id Template ID.
	 * @return object|null
	 */
	public function get_template( $template_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->templates_table} WHERE id = %d", absint( $template_id ) )
		);
	}

	/**
	 * List all templates, optionally filtered by post type.
	 *
	 * @param string $post_type Post type or '' for all.
	 * @return array<int, object>
	 */
	public function list_templates( $post_type = '' ) {
		global $wpdb;
		if ( $post_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->templates_table} WHERE post_type = %s ORDER BY name ASC",
					sanitize_key( $post_type )
				)
			);
		}
		return $wpdb->get_results( "SELECT * FROM {$this->templates_table} ORDER BY name ASC" );
	}

	/**
	 * Delete a template.
	 *
	 * @param int $template_id Template ID.
	 * @return int Rows deleted.
	 */
	public function delete_template( $template_id ) {
		global $wpdb;
		return (int) $wpdb->delete(
			$this->templates_table,
			array( 'id' => absint( $template_id ) ),
			array( '%d' )
		);
	}

	/* ---------------------------------------------------------------------
	 * CACHE
	 * ------------------------------------------------------------------- */

	/**
	 * Invalidate the WP object cache group used for term ordering lookups.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 */
	private function invalidate_term_cache( $taxonomy, $term_id ) {
		wp_cache_delete( "pcpo_order_{$taxonomy}_{$term_id}", 'pcpo' );
		// Bump a global incrementing key so callers can bust their caches en masse.
		$current = (int) get_option( 'pcpo_cache_version', 1 );
		update_option( 'pcpo_cache_version', $current + 1, false );
	}
}
