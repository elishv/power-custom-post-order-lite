<?php
/**
 * Import / Export view.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

$pcpo_enabled_post_types = $pcpo_settings->get_enabled_post_types();

// Handle download.
if ( ! empty( $_POST['pcpo_export'] ) && check_admin_referer( 'pcpo_export' ) ) {
	$pcpo_filter_pt  = sanitize_key( wp_unslash( $_POST['filter_post_type'] ?? '' ) );
	$pcpo_filter_tax = isset( $_POST['filter_taxonomy_raw'] ) && '' === $_POST['filter_taxonomy_raw'] ? null : sanitize_key( wp_unslash( $_POST['filter_taxonomy'] ?? '' ) );

	$pcpo_csv     = new PCPO_CSV_Handler();
	$pcpo_filters = array(
		'post_type' => $pcpo_filter_pt,
	);
	if ( ! empty( $_POST['filter_taxonomy_active'] ) ) {
		$pcpo_filters['taxonomy'] = $pcpo_filter_tax;
	}
	$pcpo_csv->stream_download( $pcpo_filters );
	exit;
}

// Handle import.
$pcpo_import_result = null;
if ( ! empty( $_POST['pcpo_import'] ) && check_admin_referer( 'pcpo_import' ) && ! empty( $_FILES['pcpo_csv']['tmp_name'] ) ) {
	$pcpo_tmp = $_FILES['pcpo_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's upload temp path; checked with is_uploaded_file() below.
	if ( is_uploaded_file( $pcpo_tmp ) ) {
		$pcpo_csv_content = file_get_contents( $pcpo_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pcpo_handler     = new PCPO_CSV_Handler();
		$pcpo_import_result = $pcpo_handler->import(
			$pcpo_csv_content,
			array(
				'dry_run'     => ! empty( $_POST['dry_run'] ),
				'update_only' => ! empty( $_POST['update_only'] ),
			)
		);
	}
}
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-database-export"></span>
		<?php esc_html_e( 'Import / Export', 'power-custom-post-order' ); ?>
	</h1>

	<?php if ( $pcpo_import_result ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: imported, 2: skipped, 3: errors */
					esc_html__( 'Imported: %1$d, Skipped: %2$d, Errors: %3$d', 'power-custom-post-order' ),
					(int) $pcpo_import_result['imported'],
					(int) $pcpo_import_result['skipped'],
					(int) count( $pcpo_import_result['errors'] )
				);
				?>
			</p>
			<?php if ( $pcpo_import_result['errors'] ) : ?>
				<details>
					<summary><?php esc_html_e( 'View errors', 'power-custom-post-order' ); ?></summary>
					<ul>
						<?php foreach ( array_slice( $pcpo_import_result['errors'], 0, 50 ) as $pcpo_err ) : ?>
							<li><?php echo esc_html( $pcpo_err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="pcpo-card-row">
		<div class="pcpo-card-block">
			<h2><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export to CSV', 'power-custom-post-order' ); ?></h2>
			<p><?php esc_html_e( 'Download a CSV snapshot of your current ordering data. Use this for backups, migrations, or to edit in Excel/Sheets.', 'power-custom-post-order' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'pcpo_export' ); ?>
				<input type="hidden" name="pcpo_export" value="1" />
				<p>
					<label><?php esc_html_e( 'Filter by post type', 'power-custom-post-order' ); ?>
						<select name="filter_post_type">
							<option value=""><?php esc_html_e( '— All —', 'power-custom-post-order' ); ?></option>
							<?php foreach ( $pcpo_enabled_post_types as $pcpo_pt ) :
								$pcpo_pt_obj = get_post_type_object( $pcpo_pt );
								if ( ! $pcpo_pt_obj ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $pcpo_pt ); ?>"><?php echo esc_html( $pcpo_pt_obj->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="filter_taxonomy_active" value="1" />
						<?php esc_html_e( 'Restrict to specific taxonomy', 'power-custom-post-order' ); ?>
					</label>
					<input type="text" name="filter_taxonomy" placeholder="<?php esc_attr_e( 'e.g. product_cat (empty = global only)', 'power-custom-post-order' ); ?>" class="regular-text" />
					<input type="hidden" name="filter_taxonomy_raw" value="" />
				</p>
				<?php submit_button( __( 'Download CSV', 'power-custom-post-order' ), 'primary' ); ?>
			</form>
		</div>

		<div class="pcpo-card-block">
			<h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import from CSV', 'power-custom-post-order' ); ?></h2>
			<p>
				<?php esc_html_e( 'Upload a CSV with columns: post_id, post_type, taxonomy, term_id, menu_order, is_sticky, is_hidden. Only post_id and menu_order are required.', 'power-custom-post-order' ); ?>
			</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'pcpo_import' ); ?>
				<input type="hidden" name="pcpo_import" value="1" />
				<p>
					<input type="file" name="pcpo_csv" accept=".csv,text/csv" required />
				</p>
				<p>
					<label>
						<input type="checkbox" name="dry_run" value="1" />
						<?php esc_html_e( 'Dry run (parse only, do not save)', 'power-custom-post-order' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="update_only" value="1" />
						<?php esc_html_e( 'Update only — skip new rows', 'power-custom-post-order' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Import CSV', 'power-custom-post-order' ), 'primary' ); ?>
			</form>

			<details>
				<summary><?php esc_html_e( 'Show CSV format example', 'power-custom-post-order' ); ?></summary>
				<pre class="pcpo-code">post_id,post_type,taxonomy,term_id,menu_order,is_sticky,is_hidden
142,product,product_cat,18,0,1,0
167,product,product_cat,18,1,0,0
189,product,product_cat,18,2,0,0
142,product,,0,5,0,0
</pre>
			</details>
		</div>
	</div>
</div>
