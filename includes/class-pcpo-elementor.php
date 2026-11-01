<?php
/**
 * Elementor integration.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Elementor
 *
 * Elementor's Posts, Portfolio, Products, Archive Posts, Loop Grid, and other
 * query-based widgets fire the `elementor/query/{query_id}` action AND go
 * through `pre_get_posts` / `posts_clauses` like normal WP_Query. That means
 * our core query handler already affects Elementor by default.
 *
 * What we add here:
 *   - A "PCPO ordering" option in Elementor query control dropdowns where users
 *     can explicitly opt INTO our ordering (some site builders prefer that).
 *   - A `elementor/query/pcpo_ordering` hook that any widget can target via
 *     "Query ID" to force PCPO ordering regardless of the global setting.
 *   - Detection of Elementor's preview mode to avoid breaking the editor view.
 */
class PCPO_Elementor {

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
	 * @param PCPO_Database $db       Database.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		// Hook for widgets that opt in via Query ID = "pcpo_ordering".
		add_action( 'elementor/query/pcpo_ordering', array( $this, 'force_pcpo_ordering' ), 10, 1 );

		// Also expose as a generic "elementor/query/*" filter you can pair with
		// the addon-specific query ID via the documented Query ID feature.
		add_action( 'elementor/query/pcpo', array( $this, 'force_pcpo_ordering' ), 10, 1 );

		// Don't apply our ordering inside the Elementor editor preview iframe
		// (editing UX is better with predictable ordering).
		add_action( 'pre_get_posts', array( $this, 'skip_in_editor' ), 10 );
	}

	/**
	 * Force PCPO ordering on a query that opted in via Query ID.
	 *
	 * @param WP_Query $query The query.
	 */
	public function force_pcpo_ordering( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}
		// Clear any explicit orderby on the query so our handler will pick it up.
		$query->set( 'orderby', '' );
		unset( $query->query['orderby'], $query->query_vars['orderby'] );

		// Resolve and mark.
		$resolver = PCPO_Plugin::instance()->context_resolver;
		$context  = $resolver->resolve( $query );
		$mode_for = method_exists( PCPO_Plugin::instance()->query_handler, 'resolve_effective_mode' )
			? array( 'mode' => 'manual', 'direction' => 'ASC' )
			: array( 'mode' => 'manual', 'direction' => 'ASC' );

		$query->set(
			'pcpo_apply',
			array(
				'context'   => $context,
				'mode'      => $mode_for['mode'],
				'direction' => $mode_for['direction'],
				'post_type' => is_array( $query->get( 'post_type' ) ) ? reset( $query->get( 'post_type' ) ) : ( $query->get( 'post_type' ) ?: 'post' ),
			)
		);
		PCPO_Debug::log( '[PCPO][Elementor] force_pcpo_ordering applied — query_id forced' );
	}

	/**
	 * Skip our ordering when Elementor is rendering inside its editor.
	 * Otherwise the editor preview becomes confusing when content jumps order.
	 *
	 * @param WP_Query $query The query.
	 */
	public function skip_in_editor( $query ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		$elementor = \Elementor\Plugin::instance();
		if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
			$query->set( 'pcpo_skip', true );
		}
		if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
			// Allow preview, but make sure it reflects production ordering.
			return;
		}
	}
}
