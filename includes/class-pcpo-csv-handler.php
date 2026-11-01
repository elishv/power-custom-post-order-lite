<?php
/**
 * CSV import / export handler.
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_CSV_Handler
 *
 * Importing and exporting plugin order data as CSV.
 *
 * Format:
 *   post_id, post_type, taxonomy, term_id, menu_order, is_sticky, is_hidden
 *
 *  - taxonomy/term_id empty => global row.
 *  - one row per (post, taxonomy, term) combination.
 */
class PCPO_CSV_Handler {

	/**
	 * Database.
	 *
	 * @var PCPO_Database
	 */
	private $db;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->db = PCPO_Plugin::instance()->db;
	}

	/**
	 * Generate a CSV export. Returns the CSV content as a string (or writes to a stream).
	 *
	 * @param array $filters Optional: post_type, taxonomy, term_id.
	 * @return string CSV content.
	 */
	public function export( array $filters = array() ) {
		$filters = wp_parse_args(
			$filters,
			array(
				'post_type' => '',
				'taxonomy'  => null, // Null = all; '' = global only.
				'term_id'   => null,
			)
		);

		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( $filters['post_type'] ) {
			$where[]  = 'post_type = %s';
			$params[] = sanitize_key( $filters['post_type'] );
		}
		if ( null !== $filters['taxonomy'] ) {
			$where[]  = 'taxonomy = %s';
			$params[] = sanitize_key( $filters['taxonomy'] );
		}
		if ( null !== $filters['term_id'] ) {
			$where[]  = 'term_id = %d';
			$params[] = absint( $filters['term_id'] );
		}

		// Table name goes through %i; the WHERE list only holds fixed column = placeholder pairs.
		array_unshift( $params, $this->db->orders_table );
		$sql = 'SELECT post_id, post_type, taxonomy, term_id, menu_order, is_sticky, is_hidden FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY post_type, taxonomy, term_id, menu_order';
		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from fixed SQL fragments above; every value is a placeholder.
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above.

		$out  = "post_id,post_type,taxonomy,term_id,menu_order,is_sticky,is_hidden,post_title\r\n";
		foreach ( $rows as $r ) {
			$title = get_the_title( (int) $r['post_id'] );
			$out  .= sprintf(
				"%d,%s,%s,%d,%d,%d,%d,%s\r\n",
				(int) $r['post_id'],
				$this->csv_escape( $r['post_type'] ),
				$this->csv_escape( $r['taxonomy'] ),
				(int) $r['term_id'],
				(int) $r['menu_order'],
				(int) $r['is_sticky'],
				(int) $r['is_hidden'],
				$this->csv_escape( $title )
			);
		}
		return $out;
	}

	/**
	 * Import from CSV content.
	 *
	 * @param string $csv_content Raw CSV text.
	 * @param array  $options     Options: dry_run (bool), update_only (bool).
	 * @return array Result: imported, skipped, errors.
	 */
	public function import( $csv_content, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'dry_run'     => false,
				'update_only' => false,
			)
		);

		$lines    = preg_split( '/\r\n|\r|\n/', trim( $csv_content ) );
		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		if ( empty( $lines ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Empty file.', 'power-custom-post-order' ) ),
			);
		}

		// Detect header.
		$first  = str_getcsv( array_shift( $lines ) );
		$header = array_map( 'strtolower', array_map( 'trim', $first ) );
		$cols   = array_flip( $header );

		$required = array( 'post_id', 'menu_order' );
		foreach ( $required as $col ) {
			if ( ! isset( $cols[ $col ] ) ) {
				return array(
					'imported' => 0,
					'skipped'  => 0,
					/* translators: %s: CSV column name */
					'errors'   => array( sprintf( __( 'Missing required column: %s', 'power-custom-post-order' ), $col ) ),
				);
			}
		}

		foreach ( $lines as $line_num => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = str_getcsv( $line );
			if ( empty( $row ) ) {
				continue;
			}

			$post_id    = isset( $cols['post_id'] ) ? absint( $row[ $cols['post_id'] ] ) : 0;
			$post_type  = isset( $cols['post_type'] ) ? sanitize_key( $row[ $cols['post_type'] ] ) : '';
			$taxonomy   = isset( $cols['taxonomy'] ) ? sanitize_key( $row[ $cols['taxonomy'] ] ) : '';
			$term_id    = isset( $cols['term_id'] ) ? absint( $row[ $cols['term_id'] ] ) : 0;
			$menu_order = isset( $cols['menu_order'] ) ? (int) $row[ $cols['menu_order'] ] : 0;
			$is_sticky  = isset( $cols['is_sticky'] ) ? ( $row[ $cols['is_sticky'] ] ? 1 : 0 ) : 0;
			$is_hidden  = isset( $cols['is_hidden'] ) ? ( $row[ $cols['is_hidden'] ] ? 1 : 0 ) : 0;

			if ( ! $post_id ) {
				++$skipped;
				/* translators: %d: CSV line number */
				$errors[] = sprintf( __( 'Line %d: missing post_id', 'power-custom-post-order' ), $line_num + 2 );
				continue;
			}
			if ( ! get_post( $post_id ) ) {
				++$skipped;
				/* translators: 1: CSV line number, 2: post ID */
				$errors[] = sprintf( __( 'Line %1$d: post ID %2$d not found', 'power-custom-post-order' ), $line_num + 2, $post_id );
				continue;
			}
			if ( $options['update_only'] ) {
				$existing = $this->db->get_order( $post_id, $taxonomy, $term_id );
				if ( ! $existing ) {
					++$skipped;
					continue;
				}
			}

			if ( $options['dry_run'] ) {
				++$imported;
				continue;
			}

			$ok = $this->db->upsert_order(
				array(
					'post_id'    => $post_id,
					'post_type'  => $post_type,
					'taxonomy'   => $taxonomy,
					'term_id'    => $term_id,
					'menu_order' => $menu_order,
					'is_sticky'  => $is_sticky,
					'is_hidden'  => $is_hidden,
				)
			);
			if ( $ok ) {
				++$imported;
			} else {
				++$skipped;
				/* translators: %d: CSV line number */
				$errors[] = sprintf( __( 'Line %d: DB error', 'power-custom-post-order' ), $line_num + 2 );
			}
		}

		PCPO_Debug::log( '[PCPO][CSV] import imported=' . $imported . ' skipped=' . $skipped . ' errors=' . count( $errors ) );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Stream CSV download to the browser.
	 *
	 * @param array $filters Filters.
	 */
	public function stream_download( array $filters = array() ) {
		$filename = 'pcpo-orders-' . gmdate( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * CSV-escape a value.
	 *
	 * @param string $val Value.
	 * @return string
	 */
	private function csv_escape( $val ) {
		$val = (string) $val;
		if ( false !== strpbrk( $val, ",\"\r\n" ) ) {
			$val = '"' . str_replace( '"', '""', $val ) . '"';
		}
		return $val;
	}
}
