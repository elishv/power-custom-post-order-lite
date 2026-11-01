<?php
/**
 * Bulk actions view.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

$pcpo_enabled_post_types = $pcpo_settings->get_enabled_post_types();
?>
<div class="wrap pcpo-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-controls-repeat"></span>
		<?php esc_html_e( 'Bulk Actions', 'power-custom-post-order' ); ?>
	</h1>

	<p class="pcpo-lead">
		<?php esc_html_e( 'Apply an action across all posts in a given context. Useful for one-off reorganization or seasonal resets.', 'power-custom-post-order' ); ?>
	</p>

	<div class="pcpo-card-block">
		<h2><?php esc_html_e( 'Choose context', 'power-custom-post-order' ); ?></h2>
		<div class="pcpo-toolbar__row">
			<label class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Post type', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-bulk-pt" class="pcpo-select">
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
			<label class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Taxonomy', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-bulk-tax" class="pcpo-select">
					<option value=""><?php esc_html_e( '— Global —', 'power-custom-post-order' ); ?></option>
				</select>
			</label>
			<label class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Term', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-bulk-term" class="pcpo-select">
					<option value="0"><?php esc_html_e( '— All —', 'power-custom-post-order' ); ?></option>
				</select>
			</label>
		</div>
	</div>

	<div class="pcpo-card-row">
		<div class="pcpo-card-block pcpo-card-action">
			<h3><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Shuffle', 'power-custom-post-order' ); ?></h3>
			<p><?php esc_html_e( 'Randomly reorder all posts in the selected context. Saved as a manual order.', 'power-custom-post-order' ); ?></p>
			<button class="button button-primary" id="pcpo-bulk-shuffle"><?php esc_html_e( 'Shuffle now', 'power-custom-post-order' ); ?></button>
		</div>
		<div class="pcpo-card-block pcpo-card-action">
			<h3><span class="dashicons dashicons-arrow-down-alt"></span> <?php esc_html_e( 'Alphabetize A → Z', 'power-custom-post-order' ); ?></h3>
			<p><?php esc_html_e( 'Sort by title in ascending order, then save as the manual order.', 'power-custom-post-order' ); ?></p>
			<button class="button button-primary" id="pcpo-bulk-alpha-asc"><?php esc_html_e( 'Alphabetize A → Z', 'power-custom-post-order' ); ?></button>
		</div>
		<div class="pcpo-card-block pcpo-card-action">
			<h3><span class="dashicons dashicons-arrow-up-alt"></span> <?php esc_html_e( 'Alphabetize Z → A', 'power-custom-post-order' ); ?></h3>
			<p><?php esc_html_e( 'Sort by title in descending order, then save as the manual order.', 'power-custom-post-order' ); ?></p>
			<button class="button button-primary" id="pcpo-bulk-alpha-desc"><?php esc_html_e( 'Alphabetize Z → A', 'power-custom-post-order' ); ?></button>
		</div>
		<div class="pcpo-card-block pcpo-card-action">
			<h3><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Reverse order', 'power-custom-post-order' ); ?></h3>
			<p><?php esc_html_e( 'Flip the current manual order end-to-end.', 'power-custom-post-order' ); ?></p>
			<button class="button button-primary" id="pcpo-bulk-reverse"><?php esc_html_e( 'Reverse', 'power-custom-post-order' ); ?></button>
		</div>
		<div class="pcpo-card-block pcpo-card-action">
			<h3><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Reset', 'power-custom-post-order' ); ?></h3>
			<p><?php esc_html_e( 'Delete all stored ordering data for this context. Posts will fall back to the default WordPress ordering.', 'power-custom-post-order' ); ?></p>
			<button class="button button-link-delete" id="pcpo-bulk-reset"><?php esc_html_e( 'Reset this context', 'power-custom-post-order' ); ?></button>
		</div>
	</div>

	<div class="pcpo-status" id="pcpo-bulk-status" role="status" aria-live="polite"></div>
</div>
