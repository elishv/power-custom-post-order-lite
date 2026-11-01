<?php
/**
 * Templates view: save and apply order templates.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

$templates          = $db->list_templates();
$enabled_post_types = $settings->get_enabled_post_types();

if ( ! empty( $_POST['pcpo_create_template'] ) && check_admin_referer( 'pcpo_create_template' ) ) {
	$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
	// The field is not named "post_type": WordPress reads that request key in admin.php and then fails with "Cannot load pcpo-templates".
	$post_type   = sanitize_key( wp_unslash( $_POST['pcpo_post_type'] ?? '' ) );
	$src_tax     = sanitize_key( wp_unslash( $_POST['source_taxonomy'] ?? '' ) );
	$src_term    = absint( wp_unslash( $_POST['source_term_id'] ?? 0 ) );

	if ( $name && $post_type ) {
		$rows       = $db->get_ordered_rows(
			array(
				'post_type' => $post_type,
				'taxonomy'  => $src_tax,
				'term_id'   => $src_term,
				'limit'     => 0,
			)
		);
		$order_data = array();
		foreach ( $rows as $row ) {
			$order_data[] = (int) $row->post_id;
		}
		$db->create_template(
			array(
				'name'        => $name,
				'description' => $description,
				'post_type'   => $post_type,
				'order_data'  => $order_data,
			)
		);
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Template created.', 'power-custom-post-order' ) . '</p></div>';
		$templates = $db->list_templates();
	}
}

if ( ! empty( $_POST['pcpo_delete_template'] ) && check_admin_referer( 'pcpo_delete_template' ) ) {
	$template_id = absint( wp_unslash( $_POST['template_id'] ?? 0 ) );
	if ( $template_id ) {
		$db->delete_template( $template_id );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Template deleted.', 'power-custom-post-order' ) . '</p></div>';
		$templates = $db->list_templates();
	}
}
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-portfolio"></span>
		<?php esc_html_e( 'Order Templates', 'power-custom-post-order' ); ?>
	</h1>

	<p class="pcpo-lead">
		<?php esc_html_e( 'Save the current order in a context as a reusable template, then apply it to other terms with one click.', 'power-custom-post-order' ); ?>
	</p>

	<div class="pcpo-card-row">
		<div class="pcpo-card-block">
			<h2><?php esc_html_e( 'Create new template', 'power-custom-post-order' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'pcpo_create_template' ); ?>
				<input type="hidden" name="pcpo_create_template" value="1" />
				<table class="form-table">
					<tr>
						<th><label for="pcpo-tmpl-name"><?php esc_html_e( 'Template name', 'power-custom-post-order' ); ?></label></th>
						<td><input type="text" name="name" id="pcpo-tmpl-name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="pcpo-tmpl-desc"><?php esc_html_e( 'Description', 'power-custom-post-order' ); ?></label></th>
						<td><textarea name="description" id="pcpo-tmpl-desc" rows="2" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th><label for="pcpo-tmpl-pt"><?php esc_html_e( 'Post type', 'power-custom-post-order' ); ?></label></th>
						<td>
							<select name="pcpo_post_type" id="pcpo-tmpl-pt" required>
								<option value=""><?php esc_html_e( '— Select —', 'power-custom-post-order' ); ?></option>
								<?php foreach ( $enabled_post_types as $pt ) :
									$pt_obj = get_post_type_object( $pt );
									if ( ! $pt_obj ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $pt_obj->labels->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="pcpo-tmpl-src-tax"><?php esc_html_e( 'Snapshot from taxonomy', 'power-custom-post-order' ); ?></label></th>
						<td>
							<input type="text" name="source_taxonomy" id="pcpo-tmpl-src-tax" class="regular-text" placeholder="<?php esc_attr_e( 'Empty = global', 'power-custom-post-order' ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="pcpo-tmpl-src-term"><?php esc_html_e( 'Term ID', 'power-custom-post-order' ); ?></label></th>
						<td>
							<input type="number" name="source_term_id" id="pcpo-tmpl-src-term" class="small-text" min="0" value="0" />
							<p class="description"><?php esc_html_e( '0 = global / no term context.', 'power-custom-post-order' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create template', 'power-custom-post-order' ), 'primary' ); ?>
			</form>
		</div>

		<div class="pcpo-card-block">
			<h2><?php esc_html_e( 'Saved templates', 'power-custom-post-order' ); ?></h2>
			<?php if ( ! $templates ) : ?>
				<p class="pcpo-empty"><?php esc_html_e( 'No templates yet.', 'power-custom-post-order' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'power-custom-post-order' ); ?></th>
							<th><?php esc_html_e( 'Post type', 'power-custom-post-order' ); ?></th>
							<th><?php esc_html_e( 'Items', 'power-custom-post-order' ); ?></th>
							<th><?php esc_html_e( 'Created', 'power-custom-post-order' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'power-custom-post-order' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $templates as $t ) :
							$item_count = is_array( json_decode( $t->order_data, true ) ) ? count( json_decode( $t->order_data, true ) ) : 0;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $t->name ); ?></strong>
									<?php if ( $t->description ) : ?>
										<br /><small><?php echo esc_html( $t->description ); ?></small>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $t->post_type ); ?></code></td>
								<td><?php echo esc_html( (string) $item_count ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $t->created_at ) ); ?></td>
								<td>
									<button type="button" class="button pcpo-apply-template" data-id="<?php echo esc_attr( (string) $t->id ); ?>">
										<?php esc_html_e( 'Apply…', 'power-custom-post-order' ); ?>
									</button>
									<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this template?', 'power-custom-post-order' ) ); ?>');">
										<?php wp_nonce_field( 'pcpo_delete_template' ); ?>
										<input type="hidden" name="pcpo_delete_template" value="1" />
										<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $t->id ); ?>" />
										<button class="button button-link-delete"><?php esc_html_e( 'Delete', 'power-custom-post-order' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<dialog id="pcpo-apply-template-dialog" class="pcpo-dialog">
		<form method="dialog">
			<h2><?php esc_html_e( 'Apply template to context', 'power-custom-post-order' ); ?></h2>
			<p>
				<label><?php esc_html_e( 'Taxonomy', 'power-custom-post-order' ); ?>
					<input type="text" name="taxonomy" class="regular-text" />
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Term ID', 'power-custom-post-order' ); ?>
					<input type="number" name="term_id" class="small-text" min="0" value="0" />
				</label>
			</p>
			<menu>
				<button value="cancel"><?php esc_html_e( 'Cancel', 'power-custom-post-order' ); ?></button>
				<button value="apply" class="button-primary"><?php esc_html_e( 'Apply', 'power-custom-post-order' ); ?></button>
			</menu>
		</form>
	</dialog>
</div>
