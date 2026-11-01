<?php
/**
 * Query handler: the engine that actually changes how WP_Query orders posts.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Query_Handler
 *
 * Hooks into pre_get_posts (to mark which queries we want to influence) and
 * posts_clauses (to actually rewrite SQL: JOIN our ordering table, add ORDER BY).
 *
 * The two-step design is deliberate:
 *   1. pre_get_posts decides eligibility and sets `$query->set('pcpo_apply', $context)`.
 *   2. posts_clauses reads that flag and rewrites the SQL.
 *
 * That separation lets other plugins (or this plugin itself, e.g. the shortcode)
 * mark a query as eligible without us having to re-do detection on every clause.
 */
class PCPO_Query_Handler {

	/**
	 * Database wrapper.
	 *
	 * @var PCPO_Database
	 */
	private $db;

	/**
	 * Context resolver.
	 *
	 * @var PCPO_Context_Resolver
	 */
	private $resolver;

	/**
	 * Settings.
	 *
	 * @var PCPO_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PCPO_Database         $db       Database wrapper.
	 * @param PCPO_Context_Resolver $resolver Context resolver.
	 * @param PCPO_Settings         $settings Settings.
	 */
	public function __construct( $db, $resolver, $settings ) {
		$this->db       = $db;
		$this->resolver = $resolver;
		$this->settings = $settings;

		// Mark queries that should be affected. Late priority so we evaluate
		// after WooCommerce / themes have set post_type, tax_query, etc.
		add_action( 'pre_get_posts', array( $this, 'maybe_mark_query' ), PHP_INT_MAX );

		// Rewrite SQL for marked queries. We run at PHP_INT_MAX so our ORDER BY
		// is the LAST one written — this is how we beat WooCommerce's catalog
		// ordering, WoodMart, and any other plugin that filters posts_clauses.
		// posts_clauses is the final clause filter WP applies (it runs after
		// the individual posts_orderby filter), so winning here means winning
		// the whole query. apply_clauses() only touches queries we've marked
		// (it bails when pcpo_apply isn't set), so this is safe for everything
		// else on the site.
		add_filter( 'posts_clauses', array( $this, 'apply_clauses' ), PHP_INT_MAX, 2 );

		// Cleanup orders when a post is permanently deleted.
		add_action( 'deleted_post', array( $this, 'cleanup_post_orders' ), 10, 1 );
	}

	/**
	 * Decide whether a query should use our ordering, and if so resolve its context.
	 *
	 * @param WP_Query $query The query (passed by reference by WP).
	 */
	public function maybe_mark_query( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		// Skip admin queries entirely (those are handled by our own admin UI).
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Debug — capture every front-end query we see, even ones we skip.
		$post_types_raw = (array) ( $query->get( 'post_type' ) ?: array( 'post' ) );
		$raw_orderby    = isset( $query->query['orderby'] ) ? $query->query['orderby'] : '';
		$shopper_sorted = isset( $_GET['orderby'] ) && '' !== $_GET['orderby']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		PCPO_Debug::record(
			'maybe_mark_query/enter',
			array(
				'is_main_query'    => $query->is_main_query(),
				'post_type'        => $post_types_raw,
				'is_tax'           => $query->is_tax(),
				'is_category'      => $query->is_category(),
				'is_tag'           => $query->is_tag(),
				'is_post_type_arc' => $query->is_post_type_archive(),
				'queried_obj_id'   => $query->get_queried_object_id(),
				'raw_orderby'      => '' === $raw_orderby ? '(empty)' : $raw_orderby,
				'queryvars_orderby' => $query->get( 'orderby' ),
				'shopper_orderby'  => isset( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : '(not set)', // phpcs:ignore
				'tax_query'        => $query->get( 'tax_query' ),
			)
		);

		// Skip if explicitly opted out by another developer.
		if ( true === $query->get( 'pcpo_skip', false ) ) {
			PCPO_Debug::record( 'maybe_mark_query/skip', array( 'reason' => 'pcpo_skip flag set on query' ) );
			return;
		}

		// Determine post type(s). If none specified, assume 'post' (WP default).
		$post_types = $post_types_raw;
		$enabled    = $this->settings->get_enabled_post_types();
		$applicable = array_intersect( $post_types, $enabled );
		if ( empty( $applicable ) && ! in_array( 'any', $post_types, true ) ) {
			PCPO_Debug::record(
				'maybe_mark_query/skip',
				array(
					'reason'             => 'post_type not in enabled list',
					'query_post_types'   => $post_types,
					'enabled_post_types' => $enabled,
				)
			);
			return;
		}

		$is_product_query = in_array( 'product', $post_types, true );

		if ( $raw_orderby && ! $this->settings->should_override_explicit() ) {
			// For WooCommerce product queries, an "explicit" orderby in the
			// query args is almost always a theme / page-builder default
			// (WoodMart, Elementor Loop Grid, etc.) — NOT the shopper's choice.
			// We want PCPO to be authoritative for the catalog's default order,
			// so we only bail when the shopper actually picked a sort, or when
			// this isn't a product query.
			if ( ! $is_product_query || $shopper_sorted ) {
				PCPO_Debug::record(
					'maybe_mark_query/skip',
					array(
						'reason'              => 'explicit orderby, override disabled',
						'raw_orderby'         => $raw_orderby,
						'is_product_query'    => $is_product_query,
						'shopper_sorted'      => $shopper_sorted,
						'override_explicit'   => $this->settings->should_override_explicit(),
					)
				);
				error_log( '[PCPO][QH] skipping query — explicit orderby=' . wp_json_encode( $raw_orderby ) . ' and override disabled' );
				return;
			}
		}

		// Resolve the context (which term ordering to use).
		$context = $this->resolver->resolve( $query );

		// Find the effective order mode based on context.
		$resolved = $this->resolve_effective_mode( $context, $post_types );

		// If mode is "inherit" we have nothing to override with.
		if ( 'inherit' === $resolved['mode'] ) {
			PCPO_Debug::record(
				'maybe_mark_query/skip',
				array(
					'reason'  => 'mode resolved to inherit',
					'context' => $context,
				)
			);
			return;
		}

		// Mark this query as handled by PCPO so posts_clauses can pick it up.
		$query->set(
			'pcpo_apply',
			array(
				'context'   => $context,
				'mode'      => $resolved['mode'],
				'direction' => $resolved['direction'],
				'post_type' => $applicable ? reset( $applicable ) : reset( $post_types ),
			)
		);

		PCPO_Debug::record(
			'maybe_mark_query/mark',
			array(
				'context'   => $context,
				'mode'      => $resolved['mode'],
				'direction' => $resolved['direction'],
				'post_type' => $applicable ? reset( $applicable ) : reset( $post_types ),
			)
		);

		/**
		 * Action fired when a query is marked for PCPO ordering.
		 *
		 * @param WP_Query $query    The query.
		 * @param array    $context  Resolved context.
		 * @param array    $resolved Mode/direction.
		 */
		do_action( 'pcpo_query_marked', $query, $context, $resolved );
	}

	/**
	 * Resolve the effective order mode for a context. Falls through:
	 *   term-specific → taxonomy-wide → post-type-wide → 'manual' default.
	 *
	 * @param array $context Resolved context.
	 * @param array $post_types Post types in the query.
	 * @return array{mode:string,direction:string}
	 */
	private function resolve_effective_mode( $context, $post_types ) {
		// Term level.
		if ( $context['taxonomy'] && $context['term_id'] ) {
			$cv  = $context['taxonomy'] . ':' . $context['term_id'];
			$out = $this->settings->resolve_mode_for_context( 'term', $cv );
			if ( 'inherit' !== $out['mode'] ) {
				return $out;
			}
		}

		// Taxonomy level.
		if ( $context['taxonomy'] ) {
			$out = $this->settings->resolve_mode_for_context( 'taxonomy', $context['taxonomy'] );
			if ( 'inherit' !== $out['mode'] ) {
				return $out;
			}
		}

		// Post type level.
		$post_type = reset( $post_types );
		$out       = $this->settings->resolve_mode_for_context( 'post_type', $post_type );
		return $out;
	}

	/**
	 * The actual SQL rewriter. Runs on every WP_Query but bails fast if not marked.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query The query.
	 * @return array Modified clauses.
	 */
	public function apply_clauses( $clauses, $query ) {
		$apply = $query->get( 'pcpo_apply' );
		if ( ! is_array( $apply ) || empty( $apply['mode'] ) ) {
			return $clauses;
		}

		PCPO_Debug::record(
			'apply_clauses/before',
			array(
				'mode'    => $apply['mode'],
				'context' => $apply['context'],
				'orderby' => isset( $clauses['orderby'] ) ? $clauses['orderby'] : '(none)',
			)
		);

		global $wpdb;
		$context     = $apply['context'];
		$mode        = $apply['mode'];
		$direction   = isset( $apply['direction'] ) ? strtoupper( $apply['direction'] ) : 'ASC';
		$tax         = sanitize_key( $context['taxonomy'] );
		$term_id     = absint( $context['term_id'] );
		$orders_tbl  = $this->db->orders_table;
		$mode_def    = $this->settings->get_mode( $mode );
		$has_context = ( $tax && $term_id );

		// Always JOIN our table so we can read sticky/hidden flags regardless of mode.
		// LEFT JOIN matched on (post_id, taxonomy, term_id).
		// If no context, we match the global rows (taxonomy='' AND term_id=0).
		$join_alias = 'pcpo';
		$join_sql   = $wpdb->prepare(
			" LEFT JOIN {$orders_tbl} {$join_alias} ON {$join_alias}.post_id = {$wpdb->posts}.ID AND {$join_alias}.taxonomy = %s AND {$join_alias}.term_id = %d ",
			$tax,
			$term_id
		);
		// Also LEFT JOIN the global row so we can fall back to it for sticky/hidden if context join is null.
		$global_alias = 'pcpo_global';
		if ( $has_context ) {
			$join_sql .= $wpdb->prepare(
				" LEFT JOIN {$orders_tbl} {$global_alias} ON {$global_alias}.post_id = {$wpdb->posts}.ID AND {$global_alias}.taxonomy = %s AND {$global_alias}.term_id = %d ",
				'',
				0
			);
		}
		$clauses['join'] = ( isset( $clauses['join'] ) ? $clauses['join'] : '' ) . $join_sql;

		// Hide rows flagged as hidden in this context — unless explicitly asked
		// to include them (Sort page "Hidden only" filter).
		if ( empty( $apply['show_hidden'] ) ) {
			$hidden_where     = " AND COALESCE({$join_alias}.is_hidden, 0) = 0 ";
			$clauses['where'] = ( isset( $clauses['where'] ) ? $clauses['where'] : '' ) . $hidden_where;
		}

		// Sticky-first sort key (sticky stays at top regardless of mode).
		// If context join has a row that's sticky -> use that, else fall back to global.
		$sticky_expr = $has_context
			? "GREATEST(COALESCE({$join_alias}.is_sticky, 0), COALESCE({$global_alias}.is_sticky, 0))"
			: "COALESCE({$join_alias}.is_sticky, 0)";

		// Build the ORDER BY based on mode.
		switch ( $mode ) {
			case 'manual':
				// Order: sticky DESC, our menu_order ASC (NULL last), then post ID.
				// Use a big number for NULL menu_order to push un-ordered posts to the bottom.
				$order_expr        = "COALESCE({$join_alias}.menu_order, 999999)";
				$clauses['orderby'] = "{$sticky_expr} DESC, {$order_expr} ASC, {$wpdb->posts}.ID ASC";
				break;

			case 'menu_order':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.ID ASC";
				break;

			case 'title_asc':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.post_title ASC";
				break;
			case 'title_desc':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.post_title DESC";
				break;
			case 'date_desc':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.post_date DESC";
				break;
			case 'date_asc':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.post_date ASC";
				break;
			case 'modified_desc':
				$clauses['orderby'] = "{$sticky_expr} DESC, {$wpdb->posts}.post_modified DESC";
				break;
			case 'random':
				// Stable random per day so caches don't thrash.
				$seed = (int) gmdate( 'Ymd' );
				if ( 'yes' === get_option( 'pcpo_random_seed_per_user', 'no' ) && is_user_logged_in() ) {
					$seed = $seed + (int) get_current_user_id();
				}
				$clauses['orderby'] = "{$sticky_expr} DESC, RAND({$seed})";
				break;

			case 'price_asc':
			case 'price_desc':
			case 'popularity':
			case 'rating':
			case 'stock_status':
				$clauses = $this->add_meta_order( $clauses, $mode_def, $sticky_expr, $direction );
				break;

			default:
				// Custom modes — fire a filter so addons can implement them.
				$clauses = apply_filters( 'pcpo_apply_custom_order', $clauses, $mode, $direction, $sticky_expr, $context, $query );
				break;
		}

		PCPO_Debug::record(
			'apply_clauses/after_orderby',
			array(
				'mode'    => $mode,
				'orderby' => isset( $clauses['orderby'] ) ? $clauses['orderby'] : '(none)',
				'join'    => isset( $clauses['join'] ) ? $clauses['join'] : '',
				'where'   => isset( $clauses['where'] ) ? $clauses['where'] : '',
			)
		);

		return $clauses;
	}

	/**
	 * Add a meta-based ORDER BY (for price, popularity, rating, etc.).
	 *
	 * @param array  $clauses     Query clauses.
	 * @param array  $mode_def    Mode definition (from get_mode()).
	 * @param string $sticky_expr Sticky-first SQL expression.
	 * @param string $direction   Direction.
	 * @return array Clauses.
	 */
	private function add_meta_order( $clauses, $mode_def, $sticky_expr, $direction ) {
		global $wpdb;

		if ( empty( $mode_def['orderby'] ) || strpos( $mode_def['orderby'], 'meta:' ) !== 0 ) {
			return $clauses;
		}
		$meta_key  = substr( $mode_def['orderby'], 5 );
		$direction = 'DESC' === $direction ? 'DESC' : ( isset( $mode_def['direction'] ) ? $mode_def['direction'] : 'ASC' );

		$alias            = 'pcpo_meta';
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s ",
			$meta_key
		);

		// Cast numerically for prices/sales/ratings, alphabetically for stock_status.
		$is_numeric = in_array( $meta_key, array( '_price', 'total_sales', '_wc_average_rating' ), true );
		$value_expr = $is_numeric ? "CAST({$alias}.meta_value AS DECIMAL(20,6))" : "{$alias}.meta_value";

		$clauses['orderby'] = "{$sticky_expr} DESC, {$value_expr} {$direction}";
		return $clauses;
	}

	/**
	 * Cleanup orders rows when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function cleanup_post_orders( $post_id ) {
		$deleted = $this->db->delete_orders_for_post( $post_id );
		if ( $deleted ) {
			error_log( '[PCPO][QH] cleanup_post_orders post_id=' . $post_id . ' deleted=' . $deleted );
		}
	}
}
