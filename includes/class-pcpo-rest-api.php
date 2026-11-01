<?php
/**
 * REST API controller.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_REST_API
 *
 * Exposes the plugin's data via a clean REST API for headless / external use.
 *
 * Namespace: pcpo/v1
 *
 * Endpoints:
 *   GET    /orders                 List orders for a context.
 *   POST   /orders                 Upsert a single order row.
 *   PUT    /orders/bulk            Bulk-set order for an array of post IDs.
 *   DELETE /orders/(?P<id>\d+)     Delete a single order row.
 *
 *   GET    /settings               Read context settings.
 *   POST   /settings               Save context settings.
 *
 *   GET    /templates              List templates.
 *   POST   /templates              Create a template.
 *   POST   /templates/(?P<id>\d+)/apply  Apply a template to a context.
 *   DELETE /templates/(?P<id>\d+)  Delete a template.
 *
 *   GET    /modes                  List available order modes.
 *
 *   GET    /query                  Run a custom ordered query and return posts.
 */
class PCPO_REST_API {

	/**
	 * Namespace constant.
	 */
	const NS = 'pcpo/v1';

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
	}

	/**
	 * Register all routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_list_orders' ),
					'permission_callback' => array( $this, 'permission_read' ),
					'args'                => array(
						'post_type' => array( 'type' => 'string' ),
						'taxonomy'  => array( 'type' => 'string' ),
						'term_id'   => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_upsert_order' ),
					'permission_callback' => array( $this, 'permission_write' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/orders/bulk',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'rest_bulk_set_order' ),
				'permission_callback' => array( $this, 'permission_write' ),
			)
		);

		register_rest_route(
			self::NS,
			'/orders/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'rest_delete_order' ),
				'permission_callback' => array( $this, 'permission_write' ),
				'args'                => array(
					'taxonomy' => array( 'type' => 'string' ),
					'term_id'  => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_settings' ),
					'permission_callback' => array( $this, 'permission_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save_settings' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_list_templates' ),
					'permission_callback' => array( $this, 'permission_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_create_template' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/templates/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_apply_template' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'rest_delete_template' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/modes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_list_modes' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/query',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_run_query' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_type'      => array( 'type' => 'string' ),
					'taxonomy'       => array( 'type' => 'string' ),
					'term_id'        => array( 'type' => 'integer' ),
					'term'           => array( 'type' => 'string' ),
					'order_mode'     => array( 'type' => 'string' ),
					'posts_per_page' => array( 'type' => 'integer' ),
					'page'           => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * PERMISSIONS
	 * ---------------------------------------------------------------- */

	/**
	 * Read permission.
	 *
	 * @return bool
	 */
	public function permission_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Write permission.
	 *
	 * @return bool
	 */
	public function permission_write() {
		return current_user_can( $this->settings->get_sortable_capability() );
	}

	/**
	 * Admin permission.
	 *
	 * @return bool
	 */
	public function permission_admin() {
		return current_user_can( $this->settings->get_admin_capability() );
	}

	/* ------------------------------------------------------------------
	 * ORDERS
	 * ---------------------------------------------------------------- */

	/**
	 * List orders for a context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_list_orders( WP_REST_Request $request ) {
		$args = array(
			'post_type' => $request->get_param( 'post_type' ) ? sanitize_key( $request->get_param( 'post_type' ) ) : '',
			'taxonomy'  => $request->get_param( 'taxonomy' ) ? sanitize_key( $request->get_param( 'taxonomy' ) ) : '',
			'term_id'   => $request->get_param( 'term_id' ) ? absint( $request->get_param( 'term_id' ) ) : 0,
			'limit'     => $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 100,
			'offset'    => $request->get_param( 'offset' ) ? absint( $request->get_param( 'offset' ) ) : 0,
		);
		$rows = $this->db->get_ordered_rows( $args );
		return rest_ensure_response( $rows );
	}

	/**
	 * Upsert a single order row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_upsert_order( WP_REST_Request $request ) {
		$data = array(
			'post_id'    => absint( $request->get_param( 'post_id' ) ),
			'post_type'  => sanitize_key( (string) $request->get_param( 'post_type' ) ),
			'taxonomy'   => sanitize_key( (string) $request->get_param( 'taxonomy' ) ),
			'term_id'    => absint( $request->get_param( 'term_id' ) ),
			'menu_order' => (int) $request->get_param( 'menu_order' ),
			'is_sticky'  => $request->get_param( 'is_sticky' ) ? 1 : 0,
			'is_hidden'  => $request->get_param( 'is_hidden' ) ? 1 : 0,
		);
		if ( ! $data['post_id'] ) {
			return new WP_Error( 'pcpo_missing_post_id', __( 'post_id is required', 'power-custom-post-order' ), array( 'status' => 400 ) );
		}
		$id = $this->db->upsert_order( $data );
		return rest_ensure_response( array( 'id' => $id, 'data' => $data ) );
	}

	/**
	 * Bulk set order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_bulk_set_order( WP_REST_Request $request ) {
		$order    = (array) $request->get_param( 'order' );
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		$term_id  = absint( $request->get_param( 'term_id' ) );
		$offset   = absint( $request->get_param( 'offset' ) );
		$order    = array_map( 'absint', $order );
		$count    = $this->db->bulk_set_order( $order, $taxonomy, $term_id, $offset );
		return rest_ensure_response( array( 'updated' => $count ) );
	}

	/**
	 * Delete order row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_delete_order( WP_REST_Request $request ) {
		$post_id  = absint( $request->get_param( 'post_id' ) );
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		$term_id  = absint( $request->get_param( 'term_id' ) );
		$deleted  = $this->db->delete_order( $post_id, $taxonomy, $term_id );
		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/* ------------------------------------------------------------------
	 * SETTINGS
	 * ---------------------------------------------------------------- */

	/**
	 * Get context settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_get_settings( WP_REST_Request $request ) {
		$type  = sanitize_key( (string) $request->get_param( 'context_type' ) );
		$value = sanitize_text_field( (string) $request->get_param( 'context_value' ) );
		$row   = $this->db->get_context_settings( $type, $value );
		return rest_ensure_response( $row );
	}

	/**
	 * Save context settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_save_settings( WP_REST_Request $request ) {
		$args = array(
			'context_type'    => sanitize_key( (string) $request->get_param( 'context_type' ) ),
			'context_value'   => sanitize_text_field( (string) $request->get_param( 'context_value' ) ),
			'order_mode'      => sanitize_key( (string) $request->get_param( 'order_mode' ) ),
			'order_direction' => sanitize_key( (string) $request->get_param( 'order_direction' ) ),
			'role_overrides'  => (array) $request->get_param( 'role_overrides' ),
		);
		$saved = $this->db->save_context_settings( $args );
		return rest_ensure_response( array( 'saved' => (bool) $saved ) );
	}

	/* ------------------------------------------------------------------
	 * TEMPLATES
	 * ---------------------------------------------------------------- */

	/**
	 * List templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_list_templates( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$templates = $this->db->list_templates( $post_type );
		return rest_ensure_response( $templates );
	}

	/**
	 * Create a template — saves the CURRENT order for a context as a reusable template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_create_template( WP_REST_Request $request ) {
		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$description = wp_kses_post( (string) $request->get_param( 'description' ) );
		$post_type   = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$source_tax  = sanitize_key( (string) $request->get_param( 'source_taxonomy' ) );
		$source_term = absint( $request->get_param( 'source_term_id' ) );

		// Snapshot current order in the source context.
		$rows = $this->db->get_ordered_rows(
			array(
				'post_type' => $post_type,
				'taxonomy'  => $source_tax,
				'term_id'   => $source_term,
				'limit'     => 0,
			)
		);
		$order_data = array();
		foreach ( $rows as $row ) {
			$order_data[] = (int) $row->post_id;
		}

		$id = $this->db->create_template(
			array(
				'name'        => $name,
				'description' => $description,
				'post_type'   => $post_type,
				'order_data'  => $order_data,
			)
		);
		return rest_ensure_response( array( 'id' => $id, 'count' => count( $order_data ) ) );
	}

	/**
	 * Apply a template to a destination context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_apply_template( WP_REST_Request $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$taxonomy    = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		$term_id     = absint( $request->get_param( 'term_id' ) );
		$template    = $this->db->get_template( $template_id );
		if ( ! $template ) {
			return new WP_Error( 'pcpo_not_found', __( 'Template not found', 'power-custom-post-order' ), array( 'status' => 404 ) );
		}
		$ids   = json_decode( $template->order_data, true );
		$ids   = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		$count = $this->db->bulk_set_order( $ids, $taxonomy, $term_id, 0 );
		return rest_ensure_response(
			array(
				'applied' => $count,
				'context' => array( 'taxonomy' => $taxonomy, 'term_id' => $term_id ),
			)
		);
	}

	/**
	 * Delete a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_delete_template( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$deleted = $this->db->delete_template( $id );
		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/* ------------------------------------------------------------------
	 * MODES
	 * ---------------------------------------------------------------- */

	/**
	 * Available order modes.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_list_modes() {
		return rest_ensure_response( $this->settings->get_order_modes() );
	}

	/* ------------------------------------------------------------------
	 * QUERY
	 * ---------------------------------------------------------------- */

	/**
	 * Run a custom ordered query and return posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_run_query( WP_REST_Request $request ) {
		$post_type      = $request->get_param( 'post_type' ) ? sanitize_key( $request->get_param( 'post_type' ) ) : 'post';
		$taxonomy       = $request->get_param( 'taxonomy' ) ? sanitize_key( $request->get_param( 'taxonomy' ) ) : '';
		$term_id        = absint( $request->get_param( 'term_id' ) );
		$term_slug      = sanitize_title( (string) $request->get_param( 'term' ) );
		$posts_per_page = $request->get_param( 'posts_per_page' ) ? absint( $request->get_param( 'posts_per_page' ) ) : 10;
		$page           = $request->get_param( 'page' ) ? absint( $request->get_param( 'page' ) ) : 1;
		$order_mode     = $request->get_param( 'order_mode' ) ? sanitize_key( $request->get_param( 'order_mode' ) ) : 'manual';

		if ( $term_slug && ! $term_id && $taxonomy ) {
			$term = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = (int) $term->term_id;
			}
		}

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $posts_per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
			'pcpo_apply'     => array(
				'context'   => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'      => $order_mode,
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

		$q   = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => get_the_title( $p ),
				'link'      => get_permalink( $p ),
				'excerpt'   => get_the_excerpt( $p ),
				'thumbnail' => get_the_post_thumbnail_url( $p, 'medium' ),
				'date'      => get_the_date( '', $p ),
			);
		}
		return rest_ensure_response(
			array(
				'posts'        => $out,
				'total'        => (int) $q->found_posts,
				'total_pages'  => (int) $q->max_num_pages,
				'current_page' => $page,
			)
		);
	}
}
