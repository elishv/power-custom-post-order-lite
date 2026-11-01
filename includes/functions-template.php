<?php
/**
 * Template tag functions — for theme developers and other plugins.
 *
 * These functions are deliberately namespaced with `pcpo_` rather than placed
 * in a class so they're easy to call from theme template files.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pcpo_get_posts' ) ) :
	/**
	 * Get posts in custom order. Drop-in replacement for get_posts() but with
	 * PCPO ordering applied.
	 *
	 * Example:
	 *   $products = pcpo_get_posts( array(
	 *       'post_type'      => 'product',
	 *       'taxonomy'       => 'product_cat',
	 *       'term'           => 'shoes',  // slug, name, or ID
	 *       'order_mode'     => 'manual', // or 'popularity', 'random', etc.
	 *       'posts_per_page' => 12,
	 *   ) );
	 *
	 * @param array $args See $defaults.
	 * @return WP_Post[]
	 */
	function pcpo_get_posts( $args = array() ) {
		$defaults = array(
			'post_type'      => 'post',
			'taxonomy'       => '',
			'term'           => '', // Slug, name, or numeric ID.
			'term_id'        => 0,
			'order_mode'     => 'manual',
			'order_direction' => 'ASC',
			'posts_per_page' => 10,
			'paged'          => 1,
			'post_status'    => 'publish',
		);
		$args     = wp_parse_args( $args, $defaults );

		// Normalize term.
		$term_id  = absint( $args['term_id'] );
		$taxonomy = sanitize_key( $args['taxonomy'] );
		if ( ! $term_id && $args['term'] && $taxonomy ) {
			if ( is_numeric( $args['term'] ) ) {
				$term_id = absint( $args['term'] );
			} else {
				$t = get_term_by( 'slug', $args['term'], $taxonomy );
				if ( ! $t ) {
					$t = get_term_by( 'name', $args['term'], $taxonomy );
				}
				if ( $t && ! is_wp_error( $t ) ) {
					$term_id = (int) $t->term_id;
				}
			}
		}

		$wp_args = array(
			'post_type'      => sanitize_key( $args['post_type'] ),
			'posts_per_page' => (int) $args['posts_per_page'],
			'paged'          => max( 1, (int) $args['paged'] ),
			'post_status'    => $args['post_status'],
			'pcpo_apply'     => array(
				'context'   => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'      => sanitize_key( $args['order_mode'] ),
				'direction' => sanitize_key( $args['order_direction'] ),
				'post_type' => sanitize_key( $args['post_type'] ),
			),
		);
		if ( $taxonomy && $term_id ) {
			$wp_args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}

		$q = new WP_Query( $wp_args );
		return $q->posts;
	}
endif;

if ( ! function_exists( 'pcpo_the_query' ) ) :
	/**
	 * Get a WP_Query object with PCPO ordering applied (so you can use
	 * have_posts/the_post loops in your template).
	 *
	 * @param array $args Same shape as pcpo_get_posts().
	 * @return WP_Query
	 */
	function pcpo_the_query( $args = array() ) {
		$defaults = array(
			'post_type'      => 'post',
			'taxonomy'       => '',
			'term'           => '',
			'term_id'        => 0,
			'order_mode'     => 'manual',
			'order_direction' => 'ASC',
			'posts_per_page' => 10,
			'paged'          => 1,
			'post_status'    => 'publish',
		);
		$args     = wp_parse_args( $args, $defaults );

		$term_id  = absint( $args['term_id'] );
		$taxonomy = sanitize_key( $args['taxonomy'] );
		if ( ! $term_id && $args['term'] && $taxonomy ) {
			if ( is_numeric( $args['term'] ) ) {
				$term_id = absint( $args['term'] );
			} else {
				$t = get_term_by( 'slug', $args['term'], $taxonomy );
				if ( ! $t ) {
					$t = get_term_by( 'name', $args['term'], $taxonomy );
				}
				if ( $t && ! is_wp_error( $t ) ) {
					$term_id = (int) $t->term_id;
				}
			}
		}

		$wp_args = array(
			'post_type'      => sanitize_key( $args['post_type'] ),
			'posts_per_page' => (int) $args['posts_per_page'],
			'paged'          => max( 1, (int) $args['paged'] ),
			'post_status'    => $args['post_status'],
			'pcpo_apply'     => array(
				'context'   => array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
				'mode'      => sanitize_key( $args['order_mode'] ),
				'direction' => sanitize_key( $args['order_direction'] ),
				'post_type' => sanitize_key( $args['post_type'] ),
			),
		);
		if ( $taxonomy && $term_id ) {
			$wp_args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}

		return new WP_Query( $wp_args );
	}
endif;

if ( ! function_exists( 'pcpo_apply_to_query' ) ) :
	/**
	 * Helper for theme devs: take any args array and add the pcpo_apply key.
	 * Useful when integrating with existing query-builder functions.
	 *
	 * @param array  $args         WP_Query args.
	 * @param string $order_mode   Order mode.
	 * @param string $taxonomy     Taxonomy.
	 * @param int    $term_id      Term ID.
	 * @param string $direction    Direction.
	 * @return array Augmented args.
	 */
	function pcpo_apply_to_query( $args, $order_mode = 'manual', $taxonomy = '', $term_id = 0, $direction = 'ASC' ) {
		$args['pcpo_apply'] = array(
			'context'   => array(
				'taxonomy' => sanitize_key( $taxonomy ),
				'term_id'  => absint( $term_id ),
			),
			'mode'      => sanitize_key( $order_mode ),
			'direction' => sanitize_key( $direction ),
			'post_type' => isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post',
		);
		return $args;
	}
endif;

if ( ! function_exists( 'pcpo_is_sticky' ) ) :
	/**
	 * Check whether a post is pinned in a given context.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @return bool
	 */
	function pcpo_is_sticky( $post_id, $taxonomy = '', $term_id = 0 ) {
		if ( ! class_exists( 'PCPO_Plugin' ) ) {
			return false;
		}
		$row = PCPO_Plugin::instance()->db->get_order( $post_id, $taxonomy, $term_id );
		return $row && (bool) $row->is_sticky;
	}
endif;

if ( ! function_exists( 'pcpo_is_hidden' ) ) :
	/**
	 * Check whether a post is hidden in a given context.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 * @return bool
	 */
	function pcpo_is_hidden( $post_id, $taxonomy = '', $term_id = 0 ) {
		if ( ! class_exists( 'PCPO_Plugin' ) ) {
			return false;
		}
		$row = PCPO_Plugin::instance()->db->get_order( $post_id, $taxonomy, $term_id );
		return $row && (bool) $row->is_hidden;
	}
endif;
