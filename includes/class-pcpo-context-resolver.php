<?php
/**
 * Context resolver: figures out which (taxonomy, term_id) ordering applies
 * to a given WP_Query.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Context_Resolver
 *
 * Centralizes the logic for deciding which "context" — meaning which
 * (taxonomy, term_id) pair — to use when applying our ordering to a query.
 *
 * Resolution priority for any WP_Query:
 *   1. Term archive  — use the queried term directly.
 *   2. Query has tax_query with exactly one term  — use that term.
 *   3. Multiple tax_query terms  — apply the multi-term strategy:
 *        primary | global | first.
 *   4. No taxonomy context at all  — use global (taxonomy='', term_id=0).
 */
class PCPO_Context_Resolver {

	/**
	 * Resolve the context for a WP_Query.
	 *
	 * @param WP_Query $query The query.
	 * @return array{taxonomy:string,term_id:int} Always returns valid keys.
	 */
	public function resolve( $query ) {
		$context = array(
			'taxonomy' => '',
			'term_id'  => 0,
		);

		if ( ! $query instanceof WP_Query ) {
			return $context;
		}

		// 1. Term archive page.
		if ( $query->is_main_query() && ( $query->is_tax() || $query->is_category() || $query->is_tag() ) ) {
			$term = $query->get_queried_object();
			if ( $term instanceof WP_Term ) {
				return array(
					'taxonomy' => $term->taxonomy,
					'term_id'  => (int) $term->term_id,
				);
			}
		}

		// 2/3. tax_query inspection.
		$tax_query = $query->get( 'tax_query' );
		// Also handle the shortcut category_name / tag.
		$short_terms = $this->extract_shortcut_terms( $query );

		// Merge shortcut style into our analysis.
		$collected = $this->collect_tax_query_terms( $tax_query );
		$collected = array_merge( $collected, $short_terms );

		if ( count( $collected ) === 1 ) {
			$only = $collected[0];
			return array(
				'taxonomy' => $only['taxonomy'],
				'term_id'  => (int) $only['term_id'],
			);
		}

		if ( count( $collected ) > 1 ) {
			return $this->resolve_multi_term( $collected, $query );
		}

		// 4. No taxonomy context — global.
		return $context;
	}

	/**
	 * Recursively flatten a tax_query into a flat list of [taxonomy, term_id] pairs.
	 *
	 * @param mixed $tax_query Raw tax_query array.
	 * @return array<int,array{taxonomy:string,term_id:int}>
	 */
	private function collect_tax_query_terms( $tax_query ) {
		$out = array();
		if ( ! is_array( $tax_query ) ) {
			return $out;
		}
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			if ( ! is_array( $clause ) ) {
				continue;
			}
			// Nested tax_query.
			if ( ! isset( $clause['taxonomy'] ) ) {
				$out = array_merge( $out, $this->collect_tax_query_terms( $clause ) );
				continue;
			}
			$taxonomy = sanitize_key( $clause['taxonomy'] );
			$field    = isset( $clause['field'] ) ? $clause['field'] : 'term_id';
			$terms    = isset( $clause['terms'] ) ? (array) $clause['terms'] : array();

			foreach ( $terms as $t ) {
				$term_id = 0;
				if ( 'term_id' === $field || 'id' === $field ) {
					$term_id = absint( $t );
				} else {
					$wp_term = get_term_by( $field, $t, $taxonomy );
					if ( $wp_term && ! is_wp_error( $wp_term ) ) {
						$term_id = (int) $wp_term->term_id;
					}
				}
				if ( $term_id ) {
					$out[] = array(
						'taxonomy' => $taxonomy,
						'term_id'  => $term_id,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Pick up category_name=foo and tag=bar shortcuts that don't go through tax_query.
	 *
	 * @param WP_Query $query The query.
	 * @return array<int,array{taxonomy:string,term_id:int}>
	 */
	private function extract_shortcut_terms( $query ) {
		$out = array();
		$cat = $query->get( 'category_name' );
		if ( $cat ) {
			$slugs = array_filter( array_map( 'trim', explode( ',', $cat ) ) );
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, 'category' );
				if ( $term ) {
					$out[] = array(
						'taxonomy' => 'category',
						'term_id'  => (int) $term->term_id,
					);
				}
			}
		}
		$tag = $query->get( 'tag' );
		if ( $tag ) {
			$slugs = array_filter( array_map( 'trim', explode( ',', $tag ) ) );
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, 'post_tag' );
				if ( $term ) {
					$out[] = array(
						'taxonomy' => 'post_tag',
						'term_id'  => (int) $term->term_id,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Apply the multi-term strategy (Q2 answer: primary by default).
	 *
	 * @param array    $collected Collected terms.
	 * @param WP_Query $query The query (for context).
	 * @return array{taxonomy:string,term_id:int}
	 */
	private function resolve_multi_term( $collected, $query ) {
		$strategy = PCPO_Plugin::instance()->settings->get_multi_term_strategy();

		switch ( $strategy ) {
			case 'global':
				return array(
					'taxonomy' => '',
					'term_id'  => 0,
				);

			case 'first':
				$first = reset( $collected );
				return array(
					'taxonomy' => $first['taxonomy'],
					'term_id'  => $first['term_id'],
				);

			case 'primary':
			default:
				// We can't actually know a "primary term" for a *query* (that's a
				// per-post concept). When a query targets multiple terms, fall
				// back to global as the most predictable behavior.
				return array(
					'taxonomy' => '',
					'term_id'  => 0,
				);
		}
	}

	/**
	 * Get the "primary term" for a single POST, used at render time by
	 * archive pages / loops that show a single post in context.
	 *
	 * Supports:
	 *  - Yoast SEO (_yoast_wpseo_primary_{taxonomy})
	 *  - WooCommerce primary category (deprecated but still common)
	 *  - RankMath (rank_math_primary_{taxonomy})
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy Taxonomy to check.
	 * @return int|false Primary term ID or false.
	 */
	public function get_primary_term_for_post( $post_id, $taxonomy ) {
		$post_id  = absint( $post_id );
		$taxonomy = sanitize_key( $taxonomy );

		// Yoast.
		$primary = get_post_meta( $post_id, '_yoast_wpseo_primary_' . $taxonomy, true );
		if ( $primary ) {
			return (int) $primary;
		}
		// RankMath.
		$primary = get_post_meta( $post_id, 'rank_math_primary_' . $taxonomy, true );
		if ( $primary ) {
			return (int) $primary;
		}
		// WooCommerce primary category (legacy).
		if ( 'product_cat' === $taxonomy ) {
			$primary = get_post_meta( $post_id, '_yoast_wpseo_primary_product_cat', true );
			if ( $primary ) {
				return (int) $primary;
			}
		}
		/**
		 * Filter the resolved primary term ID.
		 *
		 * @param int|false $primary  Term ID or false.
		 * @param int       $post_id  Post ID.
		 * @param string    $taxonomy Taxonomy.
		 */
		return apply_filters( 'pcpo_primary_term_for_post', false, $post_id, $taxonomy );
	}
}
