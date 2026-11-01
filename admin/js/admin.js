/*!
 * Power Custom Post Order — Admin JS
 * Eli Shvarzman
 *
 * Handles:
 *  - Sort page: drag-drop sortable, AJAX load/save, sticky/hidden toggles,
 *               move-to-position, search, pagination, mode switching.
 *  - Bulk actions: AJAX bulk operations.
 *  - Templates: apply-template dialog.
 *
 * Depends on jQuery + jQuery UI Sortable + WP dashicons + pcpoAdmin localized object.
 */
(function ($) {
	'use strict';

	if (typeof pcpoAdmin === 'undefined') {
		return;
	}

	/* ------------------------------------------------------------------
	 * Utilities
	 * ------------------------------------------------------------------ */
	function ajax(action, data) {
		return $.ajax({
			url: pcpoAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({ action: action, nonce: pcpoAdmin.nonce }, data || {})
		});
	}

	function setStatus($el, message, type) {
		$el.text(message)
			.removeClass('is-success is-error is-info')
			.addClass('is-' + (type || 'info'));
		if (type !== 'error') {
			setTimeout(function () {
				$el.text('').removeClass('is-success is-error is-info');
			}, 3000);
		}
	}

	function template(tpl, vars) {
		return tpl.replace(/\{\{(\w+)\}\}/g, function (_, k) {
			return typeof vars[k] !== 'undefined' && vars[k] !== null ? vars[k] : '';
		});
	}

	function debounce(fn, wait) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, wait);
		};
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/* ------------------------------------------------------------------
	 * SORT PAGE
	 * ------------------------------------------------------------------ */
	function initSortPage() {
		var $page = $('.pcpo-sort-wrap');
		if (!$page.length) { return; }

		var $postType    = $('#pcpo-post-type');
		var $taxonomy    = $('#pcpo-taxonomy');
		var $term        = $('#pcpo-term');
		var $search      = $('#pcpo-search');
		var $perPage     = $('#pcpo-per-page');
		var $orderMode   = $('#pcpo-order-mode');
		var $saveMode    = $('#pcpo-save-mode');
		var $sortable    = $('#pcpo-sortable');
		var $loading     = $('#pcpo-loading');
		var $empty       = $('#pcpo-empty');
		var $pagination  = $('#pcpo-pagination');
		var $status      = $('#pcpo-status');
		var $chips       = $('.pcpo-toolbar__chips .pcpo-chip[data-filter]');
		var $clearChip   = $('#pcpo-filters-clear');
		var rowTpl       = document.getElementById('pcpo-row-template').innerHTML;
		var currentPage  = 1;
		var totalPages   = 1;
		var firstLoad    = true;
		var activeFilters = {};

		function ctx() {
			return {
				post_type: $postType.val(),
				taxonomy: $taxonomy.val() || '',
				term_id: parseInt($term.val(), 10) || 0,
				page: currentPage,
				per_page: parseInt($perPage.val(), 10) || 50,
				search: $search.val() || '',
				filters: activeFilters
			};
		}

		function syncClearChip() {
			var any = false;
			for (var k in activeFilters) {
				if (activeFilters[k]) { any = true; break; }
			}
			$clearChip.prop('hidden', !any);
		}

		function loadTaxonomies(preselect) {
			$taxonomy.html('<option value="">' + pcpoAdmin.i18n.loading + '</option>');
			$term.html('<option value="0">—</option>');
			ajax('pcpo_get_taxonomies', { post_type: $postType.val() }).done(function (resp) {
				var $opts = $('<option value="">— Global (no taxonomy) —</option>');
				if (resp.success) {
					resp.data.forEach(function (t) {
						$opts = $opts.add($('<option/>').val(t.slug).text(t.name));
					});
				}
				$taxonomy.html($opts);
				if (preselect) {
					$taxonomy.val(preselect);
				}
				if ($taxonomy.val()) {
					// Pass the TERM's data-current (not the taxonomy's) to loadTerms.
					// Term IDs are numeric; taxonomy.data('current') is a slug like
					// 'product_cat' which would fail to .val() on the term <select>.
					loadTerms($term.data('current') || 0);
				}
			});
		}

		function loadTerms(preselect) {
			var tax = $taxonomy.val();
			$term.html('<option value="0">' + pcpoAdmin.i18n.loading + '</option>');
			if (!tax) {
				$term.html('<option value="0">— All terms (use global order) —</option>');
				return;
			}
			ajax('pcpo_get_terms', { taxonomy: tax }).done(function (resp) {
				var $opts = $('<option value="0">— All terms (use global order) —</option>');
				if (resp.success) {
					resp.data.forEach(function (t) {
						$opts = $opts.add($('<option/>').val(t.id).text(t.name + ' (' + t.count + ')'));
					});
				}
				$term.html($opts);
				if (preselect) {
					$term.val(preselect);
					// If a non-default term was preselected from the URL args,
					// reload posts + order mode. The init sequence calls
					// loadPosts() before this async response arrives, so the
					// list otherwise stays on the global (term=0) context.
					if (parseInt($term.val(), 10) > 0) {
						$term.trigger('change');
					}
				}
			});
		}

		function loadPosts() {
			$sortable.empty();
			$empty.attr('hidden', true);
			$loading.show();
			ajax('pcpo_get_posts', ctx())
				.done(function (resp) {
					$loading.hide();
					if (!resp.success) {
						setStatus($status, resp.data && resp.data.message ? resp.data.message : pcpoAdmin.i18n.error, 'error');
						return;
					}
					totalPages = resp.data.total_pages || 1;
					currentPage = resp.data.current_page || 1;
					renderRows(resp.data.posts);
					renderPagination(resp.data);
				})
				.fail(function () {
					$loading.hide();
					setStatus($status, pcpoAdmin.i18n.error, 'error');
				});
		}

		function renderRows(posts) {
			if (!posts || !posts.length) {
				$empty.removeAttr('hidden').text(pcpoAdmin.i18n.noResults);
				return;
			}
			var html = '';
			posts.forEach(function (p, idx) {
				var thumb = p.thumbnail
					? '<img src="' + escapeHtml(p.thumbnail) + '" width="60" height="60" alt="" loading="lazy" />'
					: '';
				html += template(rowTpl, {
					id: p.id,
					position: idx + 1 + (currentPage - 1) * ctx().per_page,
					thumb: thumb,
					title: escapeHtml(p.title),
					date: escapeHtml(p.date),
					type: escapeHtml(p.type),
					edit: p.edit_link || '#',
					view: p.view_link || '#'
				});
			});
			$sortable.html(html);
			// Apply state classes.
			posts.forEach(function (p) {
				var $row = $sortable.find('[data-id="' + p.id + '"]');
				if (p.is_sticky) {
					$row.addClass('is-sticky');
					$row.find('.pcpo-btn-sticky').addClass('is-active');
				}
				if (p.is_hidden) {
					$row.addClass('is-hidden');
					$row.find('.pcpo-btn-hide').addClass('is-active');
				}
			});

			if (firstLoad) {
				initSortable();
				firstLoad = false;
			}
		}

		function renderPagination(data) {
			$pagination.empty();
			if (!data.total_pages || data.total_pages <= 1) { return; }
			var html = '';
			html += '<button type="button" data-page="1" ' + (currentPage === 1 ? 'disabled' : '') + '>«</button>';
			html += '<button type="button" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '>‹</button>';
			var max = Math.min(data.total_pages, 8);
			var start = Math.max(1, currentPage - 3);
			var end = Math.min(data.total_pages, start + max - 1);
			for (var i = start; i <= end; i++) {
				html += '<button type="button" data-page="' + i + '" class="' + (i === currentPage ? 'is-current' : '') + '">' + i + '</button>';
			}
			html += '<button type="button" data-page="' + (currentPage + 1) + '" ' + (currentPage === data.total_pages ? 'disabled' : '') + '>›</button>';
			html += '<button type="button" data-page="' + data.total_pages + '" ' + (currentPage === data.total_pages ? 'disabled' : '') + '>»</button>';
			$pagination.html(html);
		}

		function initSortable() {
			$sortable.sortable({
				handle: '.pcpo-row__handle',
				placeholder: 'pcpo-row pcpo-row--placeholder',
				axis: 'y',
				tolerance: 'pointer',
				forceHelperSize: true,
				forcePlaceholderSize: true,
				// Preserve cell widths during drag — without this jQuery UI
				// Sortable clones the <tr> and the cell widths collapse to
				// content width, which makes the helper visually jump.
				helper: function (e, tr) {
					var $originals = tr.children();
					var $helper = tr.clone();
					$helper.children().each(function (i) {
						$(this).width($originals.eq(i).outerWidth());
					});
					return $helper;
				},
				start: function (e, ui) {
					// The placeholder is a single <tr> with no cells; match its
					// height so the layout doesn't jump.
					ui.placeholder.height(ui.item.outerHeight());
				},
				update: function () {
					saveOrder();
				}
			});
		}

		function saveOrder() {
			var ids = $sortable.find('.pcpo-row').map(function () { return $(this).data('id'); }).get();
			setStatus($status, pcpoAdmin.i18n.saving, 'info');
			var d = ctx();
			d.order = ids;
			ajax('pcpo_save_order', d).done(function (resp) {
				if (resp.success) {
					setStatus($status, pcpoAdmin.i18n.saved, 'success');
					$sortable.find('.pcpo-row').each(function (i, el) {
						$(el).find('.pcpo-row__order').text(i + 1 + (currentPage - 1) * d.per_page);
					});
				} else {
					setStatus($status, (resp.data && resp.data.message) || pcpoAdmin.i18n.error, 'error');
				}
			}).fail(function () {
				setStatus($status, pcpoAdmin.i18n.error, 'error');
			});
		}

		function loadContextMode() {
			var c = ctx();
			var ctxType = c.term_id ? 'term' : (c.taxonomy ? 'taxonomy' : 'post_type');
			var ctxValue = c.term_id ? (c.taxonomy + ':' + c.term_id) : (c.taxonomy || c.post_type);
			$.ajax({
				url: pcpoAdmin.restUrl + 'settings',
				method: 'GET',
				headers: { 'X-WP-Nonce': pcpoAdmin.restNonce },
				data: { context_type: ctxType, context_value: ctxValue }
			}).done(function (row) {
				if (row && row.order_mode) {
					$orderMode.val(row.order_mode);
				} else {
					$orderMode.val('manual');
				}
			});
		}

		function saveContextMode() {
			var c = ctx();
			var ctxType = c.term_id ? 'term' : (c.taxonomy ? 'taxonomy' : 'post_type');
			var ctxValue = c.term_id ? (c.taxonomy + ':' + c.term_id) : (c.taxonomy || c.post_type);
			setStatus($status, pcpoAdmin.i18n.saving, 'info');
			ajax('pcpo_save_context_settings', {
				context_type: ctxType,
				context_value: ctxValue,
				order_mode: $orderMode.val(),
				order_direction: 'ASC'
			}).done(function (resp) {
				if (resp.success) {
					setStatus($status, pcpoAdmin.i18n.saved, 'success');
				}
			});
		}

		/* Event bindings */
		$postType.on('change', function () {
			currentPage = 1;
			loadTaxonomies();
			loadContextMode();
			loadPosts();
		});

		$taxonomy.on('change', function () {
			currentPage = 1;
			loadTerms();
			loadContextMode();
			loadPosts();
		});

		$term.on('change', function () {
			currentPage = 1;
			loadContextMode();
			loadPosts();
		});

		$search.on('input', debounce(function () {
			currentPage = 1;
			loadPosts();
		}, 300));

		$perPage.on('change', function () {
			currentPage = 1;
			loadPosts();
		});

		$saveMode.on('click', saveContextMode);

		$chips.on('click', function () {
			var $btn = $(this);
			var key = $btn.data('filter');
			var on = !$btn.hasClass('is-active');
			$btn.toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
			if (on) {
				activeFilters[key] = 1;
			} else {
				delete activeFilters[key];
			}
			syncClearChip();
			currentPage = 1;
			loadPosts();
		});

		$clearChip.on('click', function () {
			activeFilters = {};
			$chips.removeClass('is-active').attr('aria-pressed', 'false');
			syncClearChip();
			currentPage = 1;
			loadPosts();
		});

		$pagination.on('click', 'button', function () {
			var p = parseInt($(this).data('page'), 10);
			if (!p || p === currentPage) { return; }
			currentPage = p;
			loadPosts();
		});

		// Sticky / hidden toggles + position input + quick actions delegated.
		$sortable.on('click', '.pcpo-btn-sticky', function () {
			var $row = $(this).closest('.pcpo-row');
			var sticky = !$row.hasClass('is-sticky');
			var c = ctx();
			ajax('pcpo_toggle_sticky', {
				post_id: $row.data('id'),
				taxonomy: c.taxonomy,
				term_id: c.term_id,
				sticky: sticky ? 1 : 0
			}).done(function (resp) {
				if (resp.success) {
					$row.toggleClass('is-sticky', sticky);
					$row.find('.pcpo-btn-sticky').toggleClass('is-active', sticky);
					setStatus($status, pcpoAdmin.i18n.saved, 'success');
				}
			});
		});

		$sortable.on('click', '.pcpo-btn-hide', function () {
			var $row = $(this).closest('.pcpo-row');
			var hidden = !$row.hasClass('is-hidden');
			var c = ctx();
			ajax('pcpo_toggle_hidden', {
				post_id: $row.data('id'),
				taxonomy: c.taxonomy,
				term_id: c.term_id,
				hidden: hidden ? 1 : 0
			}).done(function (resp) {
				if (resp.success) {
					$row.toggleClass('is-hidden', hidden);
					$row.find('.pcpo-btn-hide').toggleClass('is-active', hidden);
					setStatus($status, pcpoAdmin.i18n.saved, 'success');
				}
			});
		});

		// Click the order badge to edit position inline.
		$sortable.on('click', '.pcpo-row__order', function () {
			var $btn = $(this);
			if ($btn.hasClass('is-editing')) { return; }
			var currentPos = $btn.text().trim();
			var $input = $('<input type="number" min="1" class="pcpo-position-input" />')
				.val(currentPos)
				.data('original', currentPos)
				.data('row', $btn.closest('.pcpo-row'));
			$btn.addClass('is-editing').replaceWith($input);
			$input.focus().select();
		});

		function commitPositionEdit($input, apply) {
			var $row = $input.data('row');
			var original = $input.data('original');
			var newPos = parseInt($input.val(), 10);
			var $btn = $('<button type="button" class="pcpo-row__order" data-toggle="position"></button>')
				.attr('aria-label', pcpoAdmin.i18n.moveToPosition)
				.attr('title', pcpoAdmin.i18n.moveToPosition);

			if (!apply || !newPos || String(newPos) === String(original)) {
				$btn.text(original);
				$input.replaceWith($btn);
				return;
			}

			$btn.text(newPos);
			$input.replaceWith($btn);

			var c = ctx();
			setStatus($status, pcpoAdmin.i18n.saving, 'info');
			ajax('pcpo_move_to_position', {
				post_id: $row.data('id'),
				taxonomy: c.taxonomy,
				term_id: c.term_id,
				post_type: c.post_type,
				position: newPos
			}).done(function (resp) {
				if (resp.success) {
					setStatus($status, pcpoAdmin.i18n.saved, 'success');
					loadPosts();
				} else {
					setStatus($status, (resp.data && resp.data.message) || pcpoAdmin.i18n.error, 'error');
				}
			}).fail(function () {
				setStatus($status, pcpoAdmin.i18n.error, 'error');
			});
		}

		$sortable.on('keydown', '.pcpo-position-input', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				commitPositionEdit($(this), true);
			} else if (e.which === 27) {
				e.preventDefault();
				commitPositionEdit($(this), false);
			}
		});

		$sortable.on('blur', '.pcpo-position-input', function () {
			commitPositionEdit($(this), true);
		});

		// Quick action bar.
		$('#pcpo-action-shuffle').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmShuffle)) { return; }
			ajax('pcpo_bulk_shuffle', ctx()).done(function (resp) {
				if (resp.success) { setStatus($status, resp.data.message, 'success'); loadPosts(); }
			});
		});
		$('#pcpo-action-alpha-asc').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmAlphabetize)) { return; }
			ajax('pcpo_bulk_alphabetize', $.extend(ctx(), { direction: 'asc' })).done(function (resp) {
				if (resp.success) { setStatus($status, resp.data.message, 'success'); loadPosts(); }
			});
		});
		$('#pcpo-action-alpha-desc').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmAlphabetize)) { return; }
			ajax('pcpo_bulk_alphabetize', $.extend(ctx(), { direction: 'desc' })).done(function (resp) {
				if (resp.success) { setStatus($status, resp.data.message, 'success'); loadPosts(); }
			});
		});
		$('#pcpo-action-reverse').on('click', function () {
			ajax('pcpo_bulk_reverse', ctx()).done(function (resp) {
				if (resp.success) { setStatus($status, resp.data.message, 'success'); loadPosts(); }
			});
		});
		$('#pcpo-action-reset').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmReset)) { return; }
			ajax('pcpo_bulk_reset', ctx()).done(function (resp) {
				if (resp.success) { setStatus($status, resp.data.message, 'success'); loadPosts(); }
			});
		});

		// Initial.
		loadTaxonomies($taxonomy.data('current'));
		loadContextMode();
		loadPosts();
	}

	/* ------------------------------------------------------------------
	 * BULK PAGE
	 * ------------------------------------------------------------------ */
	function initBulkPage() {
		var $pt = $('#pcpo-bulk-pt');
		if (!$pt.length) { return; }
		var $tax    = $('#pcpo-bulk-tax');
		var $term   = $('#pcpo-bulk-term');
		var $status = $('#pcpo-bulk-status');

		function bulkCtx() {
			return {
				post_type: $pt.val(),
				taxonomy: $tax.val() || '',
				term_id: parseInt($term.val(), 10) || 0
			};
		}

		function loadTax() {
			ajax('pcpo_get_taxonomies', { post_type: $pt.val() }).done(function (resp) {
				var $opts = $('<option value="">— Global —</option>');
				if (resp.success) {
					resp.data.forEach(function (t) {
						$opts = $opts.add($('<option/>').val(t.slug).text(t.name));
					});
				}
				$tax.html($opts);
				$term.html('<option value="0">— All —</option>');
			});
		}
		function loadTerms() {
			if (!$tax.val()) { $term.html('<option value="0">— All —</option>'); return; }
			ajax('pcpo_get_terms', { taxonomy: $tax.val() }).done(function (resp) {
				var $opts = $('<option value="0">— All —</option>');
				if (resp.success) {
					resp.data.forEach(function (t) {
						$opts = $opts.add($('<option/>').val(t.id).text(t.name + ' (' + t.count + ')'));
					});
				}
				$term.html($opts);
			});
		}

		$pt.on('change', loadTax);
		$tax.on('change', loadTerms);

		$('#pcpo-bulk-shuffle').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmShuffle)) { return; }
			ajax('pcpo_bulk_shuffle', bulkCtx()).done(function (r) {
				if (r.success) { setStatus($status, r.data.message, 'success'); }
			});
		});
		$('#pcpo-bulk-alpha-asc').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmAlphabetize)) { return; }
			ajax('pcpo_bulk_alphabetize', $.extend(bulkCtx(), { direction: 'asc' })).done(function (r) {
				if (r.success) { setStatus($status, r.data.message, 'success'); }
			});
		});
		$('#pcpo-bulk-alpha-desc').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmAlphabetize)) { return; }
			ajax('pcpo_bulk_alphabetize', $.extend(bulkCtx(), { direction: 'desc' })).done(function (r) {
				if (r.success) { setStatus($status, r.data.message, 'success'); }
			});
		});
		$('#pcpo-bulk-reverse').on('click', function () {
			ajax('pcpo_bulk_reverse', bulkCtx()).done(function (r) {
				if (r.success) { setStatus($status, r.data.message, 'success'); }
			});
		});
		$('#pcpo-bulk-reset').on('click', function () {
			if (!confirm(pcpoAdmin.i18n.confirmReset)) { return; }
			ajax('pcpo_bulk_reset', bulkCtx()).done(function (r) {
				if (r.success) { setStatus($status, r.data.message, 'success'); }
			});
		});

		loadTax();
	}

	/* ------------------------------------------------------------------
	 * TEMPLATES PAGE — Apply dialog
	 * ------------------------------------------------------------------ */
	function initTemplatesPage() {
		var $dialog = $('#pcpo-apply-template-dialog');
		if (!$dialog.length) { return; }

		var activeTemplateId = 0;
		$('.pcpo-apply-template').on('click', function () {
			activeTemplateId = parseInt($(this).data('id'), 10);
			var dlg = $dialog[0];
			if (typeof dlg.showModal === 'function') {
				dlg.showModal();
			} else {
				$dialog.attr('open', '');
			}
		});

		$dialog.on('close', function () {
			if ($dialog[0].returnValue !== 'apply') { return; }
			var tax = $dialog.find('[name="taxonomy"]').val();
			var term = parseInt($dialog.find('[name="term_id"]').val(), 10) || 0;
			$.ajax({
				url: pcpoAdmin.restUrl + 'templates/' + activeTemplateId + '/apply',
				method: 'POST',
				headers: { 'X-WP-Nonce': pcpoAdmin.restNonce },
				data: { taxonomy: tax, term_id: term }
			}).done(function (resp) {
				alert('Applied to ' + resp.applied + ' items.');
				location.reload();
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------ */
	$(function () {
		initSortPage();
		initBulkPage();
		initTemplatesPage();
	});
}(jQuery));
