<?php
/**
 * Runtime diagnostics for "why isn't the order showing on the front?".
 *
 * @package PowerCustomPostOrder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PCPO_Debug
 *
 * When enabled (Settings → "Debug mode"), captures every decision PCPO makes
 * during a request:
 *   - which queries pre_get_posts saw, with their post types, raw orderby,
 *     queried object, and shopper sort
 *   - whether PCPO marked or skipped each query (and why)
 *   - the ORDER BY clause before and after PCPO rewrites it
 *   - the final SQL that ran for marked queries
 *
 * The captured records are dumped as an HTML comment in the front-end footer
 * for logged-in editors only, AND mirrored to error_log so they survive page
 * cache. Look at the page source (Ctrl-U) and search for "PCPO DEBUG".
 *
 * Cost when disabled: a single get_option() at the start of each record() call.
 * Cost when enabled: ~one array push per query / clause filter. Negligible.
 */
class PCPO_Debug {

	/**
	 * Captured events for the current request.
	 *
	 * @var array<int,array>
	 */
	private static $records = array();

	/**
	 * Cached enabled flag (avoid re-reading the option for every record).
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Whether we've already rendered for this request (wp_footer + shutdown
	 * could both fire; only output once).
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Whether debug mode is on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = 'yes' === get_option( 'pcpo_debug', 'no' );
		}
		return self::$enabled;
	}

	/**
	 * Record an event. Cheap no-op when debug is off.
	 *
	 * @param string $section Identifier (e.g. "maybe_mark_query/skip").
	 * @param array  $data    Arbitrary diagnostic payload.
	 */
	public static function record( $section, $data = array() ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::$records[] = array(
			't'       => round( ( microtime( true ) - ( defined( 'WP_START_TIMESTAMP' ) ? WP_START_TIMESTAMP : ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) ) ) ) * 1000, 2 ),
			'section' => $section,
			'data'    => $data,
		);
		// Mirror to the PHP error log (only with WP_DEBUG and PCPO_DEBUG on) so it
		// survives even if the page is cached and the HTML comment never reaches the browser.
		self::log( '[PCPO][DEBUG] ' . $section . ' :: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Whether lines may be written to the PHP error log: only when the site sets
	 * both WP_DEBUG and PCPO_DEBUG to true in wp-config.php.
	 *
	 * @return bool
	 */
	public static function log_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'PCPO_DEBUG' ) && PCPO_DEBUG;
	}

	/**
	 * Write one line to the PHP error log, only when log_enabled().
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		if ( ! self::log_enabled() ) {
			return;
		}
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with WP_DEBUG and PCPO_DEBUG on.
	}

	/**
	 * All captured records (for the admin bar viewer or REST endpoint).
	 *
	 * @return array<int,array>
	 */
	public static function get_records() {
		return self::$records;
	}

	/**
	 * Persist the current request's captured records to a transient so the
	 * admin can view them from the Debug page — survives page caching, no
	 * need to View Source on the front-end.
	 *
	 * Stores the latest capture per URL (keyed by md5 of the path) plus a
	 * rolling "last 10 requests" list.
	 */
	public static function save_to_transient() {
		if ( ! self::is_enabled() || empty( self::$records ) ) {
			return;
		}

		$path     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$capture  = array(
			'url'     => $path,
			'time'    => time(),
			'events'  => self::$records,
			'summary' => self::build_summary(),
		);
		$captures = (array) get_option( 'pcpo_debug_captures', array() );
		// Prepend new capture, keep only last 10.
		array_unshift( $captures, $capture );
		$captures = array_slice( $captures, 0, 10 );
		update_option( 'pcpo_debug_captures', $captures, false );
	}

	/**
	 * Read persisted captures (for the admin Debug page).
	 *
	 * @return array<int,array>
	 */
	public static function get_captures() {
		return (array) get_option( 'pcpo_debug_captures', array() );
	}

	/**
	 * Wipe persisted captures.
	 */
	public static function clear_captures() {
		delete_option( 'pcpo_debug_captures' );
	}

	/**
	 * Render the captured records on the front-end:
	 *  - Persist to transient so the admin Debug page can show them even
	 *    when the page is cached.
	 *  - Print a small fixed visible banner so the editor immediately sees
	 *    debug is running and how many events were captured.
	 *  - console.log() the full JSON so it's accessible in DevTools.
	 *  - HTML comment as a fallback for View Source.
	 */
	public static function render_footer() {
		if ( self::$rendered ) {
			return;
		}
		if ( ! self::is_enabled() || is_admin() ) {
			return;
		}
		// Always persist the capture (even if we won't echo HTML for this user).
		self::save_to_transient();

		if ( ! is_user_logged_in() ) {
			return;
		}
		$cap = PCPO_Plugin::instance()->settings->get_sortable_capability();
		if ( ! current_user_can( $cap ) ) {
			return;
		}

		self::$rendered = true;

		$summary = self::build_summary();
		$payload = array(
			'version' => PCPO_VERSION,
			'url'     => isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', // phpcs:ignore
			'summary' => $summary,
			'events'  => self::$records,
		);
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$event_count   = count( self::$records );
		$marked_count  = isset( $summary['queries_marked'] ) ? (int) $summary['queries_marked'] : 0;
		$skipped_count = isset( $summary['queries_skipped'] ) ? (int) $summary['queries_skipped'] : 0;
		$debug_url     = admin_url( 'admin.php?page=pcpo-debug' );

		// 1) HTML comment (for View Source).
		$comment_json = str_replace( '-->', '-- >', $json );
		echo "\n<!-- ====================================================================\n";
		echo '     PCPO DEBUG v' . esc_html( PCPO_VERSION ) . "\n";
		echo '     ' . (int) $event_count . ' events, ' . (int) $marked_count . ' marked, ' . (int) $skipped_count . " skipped\n";
		echo "     Full view: WP Admin -> Post Order -> Debug\n";
		echo "     ====================================================================\n";
		echo $comment_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n     ==================================================================== -->\n";

		// 2) console.log so the editor sees it in DevTools immediately.
		$script_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		echo '<script>(function(){try{var d=' . $script_json . ';' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON safely encoded.
			. 'console.groupCollapsed("%cPCPO DEBUG%c v" + d.version + "  •  " + (d.summary.queries_marked||0) + " marked, " + (d.summary.queries_skipped||0) + " skipped  •  " + d.events.length + " events", "background:#6c41dc;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700", "");'
			. 'console.log("URL:", d.url);'
			. 'console.log("Summary:", d.summary);'
			. 'console.log("Events:", d.events);'
			. 'console.log("Admin page:", ' . wp_json_encode( $debug_url ) . ');'
			. 'console.groupEnd();}catch(e){console.error("PCPO DEBUG render error", e);}})();</script>' . "\n";

		// 3) Fixed visible banner — impossible to miss, only for editors.
		$banner_text = sprintf(
			/* translators: 1: marked, 2: skipped, 3: total */
			'PCPO DEBUG: %1$d marked / %2$d skipped / %3$d events',
			$marked_count,
			$skipped_count,
			$event_count
		);
		echo '<div id="pcpo-debug-banner" style="position:fixed !important;bottom:0 !important;right:0 !important;z-index:2147483647 !important;background:#6c41dc !important;color:#fff !important;padding:8px 14px !important;font:600 12px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif !important;border-radius:6px 0 0 0 !important;box-shadow:0 -2px 12px rgba(0,0,0,0.2) !important;direction:ltr !important;">'
			. esc_html( $banner_text )
			. ' &nbsp;'
			. '<a href="' . esc_url( $debug_url ) . '" target="_blank" style="color:#fff !important;text-decoration:underline !important;">Open Debug page</a>'
			. ' &nbsp; <span style="opacity:.7;cursor:pointer;" onclick="this.parentNode.style.display=\'none\'">×</span>'
			. '</div>' . "\n";
	}

	/**
	 * Build a high-level summary of what happened — useful first-glance answer.
	 *
	 * @return array
	 */
	private static function build_summary() {
		$marked  = array();
		$skipped = array();
		$applied = array();
		$sql     = array();

		foreach ( self::$records as $r ) {
			if ( 'maybe_mark_query/mark' === $r['section'] ) {
				$marked[] = $r['data'];
			}
			if ( 0 === strpos( $r['section'], 'maybe_mark_query/skip' ) ) {
				$skipped[] = $r['data'];
			}
			if ( 'apply_clauses/after_orderby' === $r['section'] ) {
				$applied[] = $r['data'];
			}
			if ( 'posts_request/marked_query_sql' === $r['section'] ) {
				$sql[] = $r['data'];
			}
		}

		return array(
			'queries_marked'        => count( $marked ),
			'queries_skipped'       => count( $skipped ),
			'clauses_rewrites'      => count( $applied ),
			'marked_sample'         => array_slice( $marked, 0, 3 ),
			'skipped_sample'        => array_slice( $skipped, 0, 5 ),
			'final_sql_sample'      => array_slice( $sql, 0, 3 ),
			'override_explicit'     => 'yes' === get_option( 'pcpo_override_explicit', 'no' ),
			'enabled_post_types'    => get_option( 'pcpo_enabled_post_types', array() ),
			'sync_wc_menu_order'    => 'yes' === get_option( 'pcpo_sync_wc_menu_order', 'yes' ),
			'shopper_orderby_param' => isset( $_GET['orderby'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ?? '' ) : '(none)', // phpcs:ignore
			'queried_object'        => self::describe_queried_object(),
		);
	}

	/**
	 * Friendly description of what WordPress thinks the current page is.
	 *
	 * @return array
	 */
	private static function describe_queried_object() {
		$obj = get_queried_object();
		if ( $obj instanceof WP_Term ) {
			return array( 'type' => 'WP_Term', 'taxonomy' => $obj->taxonomy, 'term_id' => $obj->term_id, 'slug' => $obj->slug, 'name' => $obj->name );
		}
		if ( $obj instanceof WP_Post ) {
			return array( 'type' => 'WP_Post', 'post_type' => $obj->post_type, 'id' => $obj->ID, 'title' => $obj->post_title );
		}
		if ( $obj instanceof WP_Post_Type ) {
			return array( 'type' => 'WP_Post_Type', 'name' => $obj->name );
		}
		return array( 'type' => gettype( $obj ) );
	}
}
