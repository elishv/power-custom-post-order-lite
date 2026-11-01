<?php
/**
 * Sort page — the main drag-and-drop UI.
 *
 * @package PowerCustomPostOrder
 *
 * @var PCPO_Database $db
 * @var PCPO_Settings $settings
 */

defined( 'ABSPATH' ) || exit;

$pcpo_enabled_post_types = $pcpo_settings->get_enabled_post_types();
// Accept both the new (pcpo_*) arg names and the legacy ones, so admin-bar
// links and direct URLs both work. The pcpo_* names are required because
// post_type and taxonomy are reserved WordPress query vars — using them in
// admin.php?page=... causes "Cannot load pcpo-sort" on some setups.
// Read-only view parameters (which list to show); every change is saved through nonce-checked AJAX.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$pcpo_current_pt       = isset( $_GET['pcpo_pt'] ) ? sanitize_key( wp_unslash( $_GET['pcpo_pt'] ) ) : ( isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ( $pcpo_enabled_post_types ? reset( $pcpo_enabled_post_types ) : 'post' ) );
$pcpo_current_taxonomy = isset( $_GET['pcpo_tax'] ) ? sanitize_key( wp_unslash( $_GET['pcpo_tax'] ) ) : ( isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : apply_filters( 'pcpo_default_taxonomy_for_post_type', '', $pcpo_current_pt ) );
$pcpo_current_term     = isset( $_GET['pcpo_term'] ) ? absint( wp_unslash( $_GET['pcpo_term'] ) ) : ( isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0 );
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$pcpo_per_page         = (int) get_option( 'pcpo_default_per_page', 50 );

$pcpo_context_modes = $pcpo_settings->get_order_modes();
?>
<div class="wrap pcpo-wrap pcpo-sort-wrap">
	<h1 class="pcpo-page-title">
		<span class="dashicons dashicons-sort" aria-hidden="true"></span>
		<?php esc_html_e( 'Sort Posts', 'power-custom-post-order' ); ?>
	</h1>

	<div class="pcpo-toolbar" role="region" aria-label="<?php esc_attr_e( 'Filters and actions', 'power-custom-post-order' ); ?>">
		<div class="pcpo-toolbar__row pcpo-toolbar__filters">
			<div class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Post type', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-post-type" class="pcpo-select">
					<?php foreach ( $pcpo_enabled_post_types as $pcpo_pt ) :
						$pcpo_pt_obj = get_post_type_object( $pcpo_pt );
						if ( ! $pcpo_pt_obj ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $pcpo_pt ); ?>" <?php selected( $pcpo_pt, $pcpo_current_pt ); ?>>
							<?php echo esc_html( $pcpo_pt_obj->labels->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Taxonomy', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-taxonomy" class="pcpo-select" data-current="<?php echo esc_attr( $pcpo_current_taxonomy ); ?>">
					<option value=""><?php esc_html_e( '— Global —', 'power-custom-post-order' ); ?></option>
				</select>
			</div>

			<div class="pcpo-field">
				<span class="pcpo-field__label"><?php esc_html_e( 'Term', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-term" class="pcpo-select" data-current="<?php echo esc_attr( $pcpo_current_term ); ?>">
					<option value="0"><?php esc_html_e( '— All terms —', 'power-custom-post-order' ); ?></option>
				</select>
			</div>

			<div class="pcpo-field pcpo-field--grow">
				<span class="pcpo-field__label"><?php esc_html_e( 'Search', 'power-custom-post-order' ); ?></span>
				<input type="search" id="pcpo-search" class="pcpo-input" placeholder="<?php esc_attr_e( 'Search by title…', 'power-custom-post-order' ); ?>" />
			</div>

			<div class="pcpo-field pcpo-field--narrow">
				<span class="pcpo-field__label"><?php esc_html_e( 'Per page', 'power-custom-post-order' ); ?></span>
				<select id="pcpo-per-page" class="pcpo-select">
					<?php foreach ( array( 20, 50, 100, 200 ) as $pcpo_opt ) : ?>
						<option value="<?php echo esc_attr( (string) $pcpo_opt ); ?>" <?php selected( $pcpo_opt, $pcpo_per_page ); ?>><?php echo esc_html( (string) $pcpo_opt ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="pcpo-toolbar__row pcpo-toolbar__chips" role="group" aria-label="<?php esc_attr_e( 'Quick filters', 'power-custom-post-order' ); ?>">
			<button type="button" class="pcpo-chip" data-filter="sticky" aria-pressed="false">
				<span class="dashicons dashicons-sticky" aria-hidden="true"></span>
				<?php esc_html_e( 'Sticky', 'power-custom-post-order' ); ?>
			</button>
			<button type="button" class="pcpo-chip" data-filter="hidden" aria-pressed="false">
				<span class="dashicons dashicons-hidden" aria-hidden="true"></span>
				<?php esc_html_e( 'Hidden', 'power-custom-post-order' ); ?>
			</button>
			<button type="button" class="pcpo-chip" data-filter="modified_week" aria-pressed="false">
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Modified this week', 'power-custom-post-order' ); ?>
			</button>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<button type="button" class="pcpo-chip pcpo-chip--wc" data-filter="instock" aria-pressed="false">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'In stock', 'power-custom-post-order' ); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="pcpo-chip pcpo-chip--clear" id="pcpo-filters-clear" hidden>
				<?php esc_html_e( 'Clear', 'power-custom-post-order' ); ?>
			</button>
		</div>

		<div class="pcpo-toolbar__row pcpo-toolbar__actions">
			<div class="pcpo-mode-block">
				<div class="pcpo-field pcpo-field--grow">
					<span class="pcpo-field__label"><?php esc_html_e( 'Order mode', 'power-custom-post-order' ); ?></span>
					<select id="pcpo-order-mode" class="pcpo-select">
						<?php foreach ( $pcpo_context_modes as $pcpo_slug => $pcpo_def ) :
							if ( ! empty( $pcpo_def['requires_woocommerce'] ) && ! class_exists( 'WooCommerce' ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $pcpo_slug ); ?>"><?php echo esc_html( $pcpo_def['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="button" class="button button-primary pcpo-save-mode-btn" id="pcpo-save-mode"><?php esc_html_e( 'Save', 'power-custom-post-order' ); ?></button>
			</div>

			<div class="pcpo-quick-actions" role="group" aria-label="<?php esc_attr_e( 'Bulk actions', 'power-custom-post-order' ); ?>">
				<button type="button" class="pcpo-qa-btn" id="pcpo-action-shuffle" title="<?php esc_attr_e( 'Shuffle', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-randomize" aria-hidden="true"></span>
					<span class="pcpo-qa-btn__label"><?php esc_html_e( 'Shuffle', 'power-custom-post-order' ); ?></span>
				</button>
				<button type="button" class="pcpo-qa-btn" id="pcpo-action-alpha-asc" title="A → Z">
					<span class="dashicons dashicons-arrow-down-alt" aria-hidden="true"></span>
					<span class="pcpo-qa-btn__label">A → Z</span>
				</button>
				<button type="button" class="pcpo-qa-btn" id="pcpo-action-alpha-desc" title="Z → A">
					<span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span>
					<span class="pcpo-qa-btn__label">Z → A</span>
				</button>
				<button type="button" class="pcpo-qa-btn" id="pcpo-action-reverse" title="<?php esc_attr_e( 'Reverse', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
					<span class="pcpo-qa-btn__label"><?php esc_html_e( 'Reverse', 'power-custom-post-order' ); ?></span>
				</button>
				<button type="button" class="pcpo-qa-btn pcpo-qa-btn--danger" id="pcpo-action-reset" title="<?php esc_attr_e( 'Reset', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<span class="pcpo-qa-btn__label"><?php esc_html_e( 'Reset', 'power-custom-post-order' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<div class="pcpo-status" id="pcpo-status" role="status" aria-live="polite"></div>

	<div class="pcpo-sort-area">
		<div class="pcpo-loading" id="pcpo-loading"><?php esc_html_e( 'Loading…', 'power-custom-post-order' ); ?></div>
		<table class="pcpo-sortable" id="pcpo-sortable-wrap" data-context-pt="<?php echo esc_attr( $pcpo_current_pt ); ?>">
			<tbody id="pcpo-sortable"></tbody>
		</table>
		<div class="pcpo-empty" id="pcpo-empty" hidden><?php esc_html_e( 'No posts match the current filter.', 'power-custom-post-order' ); ?></div>
	</div>

	<div class="pcpo-pagination" id="pcpo-pagination"></div>

	<template id="pcpo-row-template">
		<tr class="pcpo-row" data-id="{{id}}">
			<td class="pcpo-cell pcpo-cell--handle">
				<span class="pcpo-row__handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'power-custom-post-order' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-menu" aria-hidden="true"></span>
				</span>
			</td>
			<td class="pcpo-cell pcpo-cell--pos">
				<button type="button" class="pcpo-row__order" data-toggle="position" aria-label="<?php esc_attr_e( 'Move to position', 'power-custom-post-order' ); ?>" title="<?php esc_attr_e( 'Click to move to position', 'power-custom-post-order' ); ?>">{{position}}</button>
			</td>
			<td class="pcpo-cell pcpo-cell--thumb">
				<span class="pcpo-row__thumb">{{thumb}}</span>
			</td>
			<td class="pcpo-cell pcpo-cell--main">
				<a class="pcpo-row__title" href="{{edit}}" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Edit', 'power-custom-post-order' ); ?>">{{title}}</a>
				<span class="pcpo-row__meta">
					<span class="pcpo-row__date">{{date}}</span>
					<span class="pcpo-row__sep">·</span>
					<code class="pcpo-row__type">{{type}}</code>
					<span class="pcpo-row__sep">·</span>
					<code class="pcpo-row__id">#{{id}}</code>
				</span>
			</td>
			<td class="pcpo-cell pcpo-cell--actions">
				<button type="button" class="pcpo-icon-btn pcpo-btn-sticky" data-toggle="sticky" aria-label="<?php esc_attr_e( 'Pin to top', 'power-custom-post-order' ); ?>" title="<?php esc_attr_e( 'Pin to top', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-sticky" aria-hidden="true"></span>
				</button>
				<button type="button" class="pcpo-icon-btn pcpo-btn-hide" data-toggle="hidden" aria-label="<?php esc_attr_e( 'Hide in this context', 'power-custom-post-order' ); ?>" title="<?php esc_attr_e( 'Hide in this context', 'power-custom-post-order' ); ?>">
					<span class="dashicons dashicons-hidden" aria-hidden="true"></span>
				</button>
			</td>
		</tr>
	</template>
</div>
