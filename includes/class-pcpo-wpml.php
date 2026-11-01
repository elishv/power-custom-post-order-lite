<?php
/**
 * WPML integration.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_WPML
 *
 * Mirrors order / sticky / hidden writes onto sibling translations so a
 * manual order set on the Hebrew product page also lands on its English,
 * French, etc. counterparts.
 *
 * Activation rules:
 *  - WPML must be active (Sitepress class loaded).
 *  - The pcpo_wpml_sync option must be 'yes' (default 'yes' when WPML is
 *    active, so behavior is on by default for sites that need it).
 *
 * Loop prevention: a static depth counter guards the recursive upsert
 * calls. The translation lookup itself is cheap (one filter call per
 * language) and results are memoized for the request.
 */
class PCPO_WPML {

	/**
	 * @var PCPO_Database
	 */
	private $db;

	/**
	 * @var PCPO_Settings
	 */
	private $settings;

	/**
	 * Depth guard so the upsert we issue inside the hook doesn't fire the
	 * hook again recursively.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Per-request memoization of translation lookups, keyed by
	 * "{element_type}:{id}". Value is map { lang_code => translated_id }.
	 *
	 * @var array<string, array<string, int>>
	 */
	private $cache = array();

	/**
	 * Constructor — wires up hooks. Caller MUST check is_active() first.
	 *
	 * @param PCPO_Database $db       DB layer.
	 * @param PCPO_Settings $settings Settings.
	 */
	public function __construct( PCPO_Database $db, PCPO_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;

		add_action( 'pcpo_after_upsert_order', array( $this, 'mirror_upsert' ), 10, 1 );
		add_action( 'pcpo_after_delete_order', array( $this, 'mirror_delete' ), 10, 3 );
		add_action( 'pcpo_after_reset_context', array( $this, 'mirror_reset' ), 10, 3 );
	}

	/**
	 * Whether WPML is loaded enough for us to use its APIs.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'SitePress' ) || defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * Whether sync is enabled (option + WPML active).
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( ! self::is_active() ) {
			return false;
		}
		return 'yes' === get_option( 'pcpo_wpml_sync', 'yes' );
	}

	/**
	 * Mirror an upsert onto translated post/term pairs.
	 *
	 * @param array $data Normalized row data from PCPO_Database::upsert_order().
	 */
	public function mirror_upsert( $data ) {
		if ( ! $this->is_enabled() || $this->depth > 0 ) {
			return;
		}

		$post_id   = (int) $data['post_id'];
		$post_type = $data['post_type'] ? $data['post_type'] : get_post_type( $post_id );
		$taxonomy  = (string) $data['taxonomy'];
		$term_id   = (int) $data['term_id'];

		if ( ! $post_type ) {
			return;
		}

		$post_siblings = $this->get_post_siblings( $post_id, $post_type );
		$term_siblings = $taxonomy && $term_id ? $this->get_term_siblings( $term_id, $taxonomy ) : array();
		$source_lang   = $this->get_element_language( 'post_' . $post_type, $post_id );

		if ( empty( $post_siblings ) ) {
			return;
		}

		$this->depth++;
		try {
			foreach ( $post_siblings as $lang => $sibling_post_id ) {
				if ( $lang === $source_lang || (int) $sibling_post_id === $post_id ) {
					continue;
				}

				// If we have a term context, look up the sibling term in this language.
				// If there's no sibling term (taxonomy not translated), skip — writing
				// against the source term_id would corrupt the data.
				$sibling_term_id = 0;
				if ( $taxonomy && $term_id ) {
					if ( empty( $term_siblings[ $lang ] ) ) {
						continue;
					}
					$sibling_term_id = (int) $term_siblings[ $lang ];
				}

				$this->db->upsert_order(
					array(
						'post_id'    => (int) $sibling_post_id,
						'post_type'  => $post_type,
						'taxonomy'   => $taxonomy,
						'term_id'    => $sibling_term_id,
						'menu_order' => (int) $data['menu_order'],
						'is_sticky'  => (int) $data['is_sticky'],
						'is_hidden'  => (int) $data['is_hidden'],
					)
				);
			}
		} finally {
			$this->depth--;
		}
	}

	/**
	 * Mirror a delete onto translated post/term pairs.
	 *
	 * @param int    $post_id  Source post ID.
	 * @param string $taxonomy Taxonomy or ''.
	 * @param int    $term_id  Term ID or 0.
	 */
	public function mirror_delete( $post_id, $taxonomy, $term_id ) {
		if ( ! $this->is_enabled() || $this->depth > 0 ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return;
		}

		$post_siblings = $this->get_post_siblings( (int) $post_id, $post_type );
		$term_siblings = $taxonomy && $term_id ? $this->get_term_siblings( (int) $term_id, $taxonomy ) : array();
		$source_lang   = $this->get_element_language( 'post_' . $post_type, (int) $post_id );

		$this->depth++;
		try {
			foreach ( $post_siblings as $lang => $sibling_post_id ) {
				if ( $lang === $source_lang || (int) $sibling_post_id === (int) $post_id ) {
					continue;
				}
				$sibling_term_id = 0;
				if ( $taxonomy && $term_id ) {
					if ( empty( $term_siblings[ $lang ] ) ) {
						continue;
					}
					$sibling_term_id = (int) $term_siblings[ $lang ];
				}
				$this->db->delete_order( (int) $sibling_post_id, $taxonomy, $sibling_term_id );
			}
		} finally {
			$this->depth--;
		}
	}

	/**
	 * Mirror a reset_context onto translated terms (only when a term context
	 * was reset — global resets are language-agnostic).
	 *
	 * @param string $taxonomy  Taxonomy or ''.
	 * @param int    $term_id   Term ID or 0.
	 * @param string $post_type Post type or ''.
	 */
	public function mirror_reset( $taxonomy, $term_id, $post_type ) {
		if ( ! $this->is_enabled() || $this->depth > 0 ) {
			return;
		}
		if ( ! $taxonomy || ! $term_id ) {
			return;
		}

		$term_siblings = $this->get_term_siblings( (int) $term_id, $taxonomy );
		$source_lang   = $this->get_element_language( 'tax_' . $taxonomy, (int) $term_id );

		$this->depth++;
		try {
			foreach ( $term_siblings as $lang => $sibling_term_id ) {
				if ( $lang === $source_lang || (int) $sibling_term_id === (int) $term_id ) {
					continue;
				}
				$this->db->reset_context( $taxonomy, (int) $sibling_term_id, $post_type );
			}
		} finally {
			$this->depth--;
		}
	}

	/**
	 * Return all translations of a post keyed by language code.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array<string, int>
	 */
	private function get_post_siblings( $post_id, $post_type ) {
		$cache_key = 'post_' . $post_type . ':' . $post_id;
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}
		$this->cache[ $cache_key ] = $this->lookup_siblings( 'post_' . $post_type, $post_id );
		return $this->cache[ $cache_key ];
	}

	/**
	 * Return all translations of a term keyed by language code.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, int>
	 */
	private function get_term_siblings( $term_id, $taxonomy ) {
		$cache_key = 'tax_' . $taxonomy . ':' . $term_id;
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}
		$this->cache[ $cache_key ] = $this->lookup_siblings( 'tax_' . $taxonomy, $term_id );
		return $this->cache[ $cache_key ];
	}

	/**
	 * Query the icl_translations table directly for sibling IDs.
	 *
	 * @param string $element_type e.g. "post_product" or "tax_product_cat".
	 * @param int    $element_id   The wp_posts.ID or wp_terms.term_taxonomy_id (post handled below).
	 * @return array<string, int> language_code => element_id (post_id for posts, term_id for taxes).
	 */
	private function lookup_siblings( $element_type, $element_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';

		if ( 0 === strpos( $element_type, 'post_' ) ) {
			$trid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT trid FROM {$table} WHERE element_type = %s AND element_id = %d LIMIT 1",
					$element_type,
					$element_id
				)
			);
		} elseif ( 0 === strpos( $element_type, 'tax_' ) ) {
			// For taxes, icl_translations stores term_taxonomy_id, not term_id.
			$ttid = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
					$element_id
				)
			);
			if ( ! $ttid ) {
				return array();
			}
			$trid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT trid FROM {$table} WHERE element_type = %s AND element_id = %d LIMIT 1",
					$element_type,
					$ttid
				)
			);
		} else {
			return array();
		}

		if ( ! $trid ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT language_code, element_id FROM {$table} WHERE trid = %d AND element_type = %s",
				(int) $trid,
				$element_type
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->element_id;
			if ( 0 === strpos( $element_type, 'tax_' ) ) {
				// Convert term_taxonomy_id back to term_id for tax rows.
				$id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
						$id
					)
				);
			}
			if ( $id ) {
				$out[ $row->language_code ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Get the language code of a single element.
	 *
	 * @param string $element_type e.g. "post_product" or "tax_product_cat".
	 * @param int    $element_id   The post_id / term_id.
	 * @return string Language code or ''.
	 */
	private function get_element_language( $element_type, $element_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';

		if ( 0 === strpos( $element_type, 'tax_' ) ) {
			$element_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
					$element_id
				)
			);
			if ( ! $element_id ) {
				return '';
			}
		}

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language_code FROM {$table} WHERE element_type = %s AND element_id = %d LIMIT 1",
				$element_type,
				$element_id
			)
		);
	}
}
