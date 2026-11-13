<?php
/**
 * Debug view — shows the most recent PCPO_Debug captures.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

// Handle "clear" submit.
if ( isset( $_POST['pcpo_clear_debug'] ) && check_admin_referer( 'pcpo_clear_debug' ) ) {
	PCPO_Debug::clear_captures();
	echo '<div class="notice notice-success"><p>' . esc_html__( 'Cleared.', 'power-custom-post-order' ) . '</p></div>';
}

// Handle "test URL" submit — sends an authenticated HTTP request to the given
// URL with a cache-buster, which forces PHP to run (bypassing Breeze / WP
// Rocket page cache) and triggers PCPO_Debug to capture + save.
if ( isset( $_POST['pcpo_test_url'] ) && check_admin_referer( 'pcpo_test_url' ) ) {
	$pcpo_test_url = isset( $_POST['pcpo_test_url_value'] ) ? esc_url_raw( wp_unslash( $_POST['pcpo_test_url_value'] ) ) : '';
	// Only this site's own URLs: the request carries the admin's auth cookies.
	if ( $pcpo_test_url && strtolower( (string) wp_parse_url( $pcpo_test_url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Only URLs on this site can be tested.', 'power-custom-post-order' ) . '</p></div>';
		$pcpo_test_url = '';
	}
	if ( $pcpo_test_url ) {
		// Cache buster as an unknown query var — page caches don't have it
		// cached, so they pass through to PHP.
		$pcpo_bust_url = add_query_arg( 'pcpo_nocache', wp_generate_password( 10, false ), $pcpo_test_url );

		// Forward the current user's auth cookies so the remote request runs
		// as a logged-in editor (caches bypass cache for logged-in users).
		$pcpo_cookies = array();
		foreach ( array( LOGGED_IN_COOKIE, AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $pcpo_cname ) {
			if ( ! empty( $_COOKIE[ $pcpo_cname ] ) ) {
				$pcpo_cookies[] = new WP_Http_Cookie(
					array(
						'name'  => $pcpo_cname,
						'value' => $_COOKIE[ $pcpo_cname ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The admin's own auth cookie, passed through unchanged to a loopback request to this site; altering it would invalidate it.
					)
				);
			}
		}

		$pcpo_response = wp_remote_get(
			$pcpo_bust_url,
			array(
				'timeout'     => 30,
				'cookies'     => $pcpo_cookies,
				'redirection' => 5,
				'user-agent'  => 'PCPO-Debug/' . PCPO_VERSION,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $pcpo_response ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $pcpo_response->get_error_message() ) . '</p></div>';
		} else {
			$pcpo_code = (int) wp_remote_retrieve_response_code( $pcpo_response );
			$pcpo_body = wp_remote_retrieve_body( $pcpo_response );
			$pcpo_has_banner = false !== strpos( $pcpo_body, 'pcpo-debug-banner' );
			$pcpo_has_marker = false !== strpos( $pcpo_body, 'PCPO DEBUG' );
			echo '<div class="notice notice-success"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: URL, 2: HTTP status code */
					__( 'Sent request to %1$s — HTTP %2$d.', 'power-custom-post-order' ),
					$pcpo_bust_url,
					$pcpo_code
				)
			);
			echo ' ';
			if ( $pcpo_has_banner || $pcpo_has_marker ) {
				echo '<strong>' . esc_html__( 'PCPO debug output detected in the response. Capture below.', 'power-custom-post-order' ) . '</strong>';
			} else {
				echo '<strong>' . esc_html__( 'NO PCPO debug output in the response — the page may still be cached. Try the URL in a private window while logged in, or temporarily disable Breeze.', 'power-custom-post-order' ) . '</strong>';
			}
			echo '</p></div>';
		}
	}
}

$pcpo_enabled  = 'yes' === get_option( 'pcpo_debug', 'no' );
$pcpo_captures = PCPO_Debug::get_captures();
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-search"></span>
		<?php esc_html_e( 'PCPO Debug', 'power-custom-post-order' ); ?>
	</h1>

	<?php if ( ! $pcpo_enabled ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Debug mode is OFF.', 'power-custom-post-order' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to settings */
					esc_html__( 'Turn it on under %s, then use the "Test URL" form below or visit a page directly. Remember to turn it off again on production.', 'power-custom-post-order' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=pcpo-settings' ) ) . '">' . esc_html__( 'Settings → Debug mode', 'power-custom-post-order' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="pcpo-card-block" style="margin-bottom: 16px;">
		<h2 style="margin: 0 0 10px;"><?php esc_html_e( 'Capture a URL right now', 'power-custom-post-order' ); ?></h2>
		<p style="margin: 0 0 12px; color: #4b5563;"><?php esc_html_e( 'Sends a fresh authenticated request to the URL with a cache-buster, which forces page caches (Breeze, WP Rocket) to pass through to PHP. The resulting PCPO debug capture appears below.', 'power-custom-post-order' ); ?></p>
		<form method="post" style="display: flex; gap: 8px; align-items: stretch;">
			<?php wp_nonce_field( 'pcpo_test_url' ); ?>
			<input
				type="url"
				name="pcpo_test_url_value"
				class="regular-text"
				placeholder="<?php echo esc_attr( home_url( '/product-category/sale/' ) ); ?>"
				value="<?php echo isset( $_POST['pcpo_test_url_value'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['pcpo_test_url_value'] ) ) ) : esc_attr( home_url( '/product-category/sale/' ) ); ?>"
				style="flex: 1 1 auto;"
				required
			/>
			<button type="submit" name="pcpo_test_url" value="1" class="button button-primary">
				<?php esc_html_e( 'Capture now', 'power-custom-post-order' ); ?>
			</button>
		</form>
	</div>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=pcpo-debug' ) ); ?>" class="button">
			<?php esc_html_e( 'Refresh', 'power-custom-post-order' ); ?>
		</a>
		<form method="post" style="display:inline-block; margin-left: 8px;">
			<?php wp_nonce_field( 'pcpo_clear_debug' ); ?>
			<button type="submit" name="pcpo_clear_debug" value="1" class="button button-secondary">
				<?php esc_html_e( 'Clear all captures', 'power-custom-post-order' ); ?>
			</button>
		</form>
	</p>

	<?php if ( empty( $pcpo_captures ) ) : ?>
		<div class="pcpo-card-block">
			<p><?php esc_html_e( 'No captures yet. Use "Capture a URL right now" above, or visit a front-end page while debug is on.', 'power-custom-post-order' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $pcpo_captures as $pcpo_i => $pcpo_capture ) : ?>
			<div class="pcpo-card-block" style="margin-bottom: 16px;">
				<h2 style="margin: 0 0 6px;">
					<code style="font-size: 13px;"><?php echo esc_html( $pcpo_capture['url'] ); ?></code>
				</h2>
				<p style="margin: 0 0 12px; color: #6b7280;">
					<?php echo esc_html( gmdate( 'Y-m-d H:i:s', $pcpo_capture['time'] ) ); ?> UTC
					&middot;
					<?php echo (int) ( isset( $pcpo_capture['events'] ) ? count( $pcpo_capture['events'] ) : 0 ); ?>
					<?php esc_html_e( 'events captured', 'power-custom-post-order' ); ?>
				</p>

				<?php if ( ! empty( $pcpo_capture['summary'] ) ) : ?>
					<h3 style="margin: 0 0 6px;"><?php esc_html_e( 'Summary', 'power-custom-post-order' ); ?></h3>
					<pre class="pcpo-code" style="max-height: 360px; overflow: auto; white-space: pre-wrap;"><?php
						echo esc_html( wp_json_encode( $pcpo_capture['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					?></pre>
				<?php endif; ?>

				<details style="margin-top: 12px;">
					<summary style="cursor: pointer; user-select: none; font-weight: 600;">
						<?php esc_html_e( 'Full event timeline (click to expand)', 'power-custom-post-order' ); ?>
					</summary>
					<pre class="pcpo-code" style="max-height: 500px; overflow: auto; white-space: pre-wrap; margin-top: 8px;"><?php
						echo esc_html( wp_json_encode( $pcpo_capture['events'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					?></pre>
				</details>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

