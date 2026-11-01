=== Power Custom Post Order ===
Contributors: elis85
Donate link: https://a-in.co.il/plugins/post-order/
Tags: post order, sort posts, drag and drop, woocommerce sort, custom order
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag-and-drop ordering for any post type, per category or taxonomy term, with sticky and hidden posts, CSV import/export and a REST API.

== Description ==

Power Custom Post Order replaces WordPress's default ordering with a context-aware system: drag posts, pages, products or any custom post type into place, and set a different order for every category or taxonomy term. It works with themes and builders that use standard WordPress queries, including Elementor, and syncs with WooCommerce's native `menu_order`.

It orders posts *within* a term. It does not reorder the terms themselves.

* **Drag-and-drop Sort Posts screen**: thumbnails, search, pagination and instant AJAX saves.
* **A different order per term**: the global product list, /product-category/shoes and /product-category/bags can each have their own order.
* **14 order modes**: manual, inherit, menu_order, title A→Z and Z→A, newest and oldest, last modified and random (with a cache-friendly daily seed), plus the WooCommerce modes price low→high and high→low, popularity, rating and stock status.
* **Sticky and hidden posts**: pin posts to the top of a context, or hide a post on one term without unpublishing it.
* **Order templates**: save the order of one term and apply it to another.
* **CSV import / export** for backups and bulk edits.
* **Per-role ordering**: show different orders to different user roles.
* **For developers**: REST API (`pcpo/v1`), the `[pcpo_posts]` and `[pcpo_query]` shortcodes, and the `pcpo_get_posts()` / `pcpo_the_query()` template tags.
* **WooCommerce and Elementor**: HPOS compatible, `menu_order` sync, and Elementor support (automatic, or per widget with the `pcpo_ordering` Query ID).
* **WPML**: keeps the order in sync across translations (on by default when WPML is active).
* **Cache-aware**: purges supported page caches when the order changes.
* Hebrew translation and RTL stylesheet.

= More from A-in =

This plugin is complete on its own: no licence key, no locked features. Premium plugins, add-ons and support from the same developer are available at [a-in.co.il](https://a-in.co.il/plugins/).

== Installation ==

1. Upload the `power-custom-post-order` folder to `wp-content/plugins/` (or upload the ZIP via WP admin → Plugins → Add New → Upload).
2. Activate.
3. Visit **Post Order → Settings** and confirm which post types should be managed.
4. Visit **Post Order → Sort Posts** to start ordering.

== Frequently Asked Questions ==

= Does it work with WooCommerce? =
Yes. The plugin declares HPOS compatibility, syncs global product order to `wp_posts.menu_order` by default, and captures changes made via WooCommerce's native Products → Sort Products screen.

= Does it work with Elementor? =
Yes, in three ways: (1) automatically for any query whose post type is enabled, (2) per-widget opt-in by setting Query ID to `pcpo_ordering`, or (3) per-widget opt-out via a `pcpo_skip` filter. Editor preview is automatically excluded so editing UX stays predictable.

= My order doesn't show up on the front-end =
Check Settings → Override explicit orderby. If your theme / Elementor widget explicitly sets orderby, PCPO respects it by default. Switch this to "Yes" or change the widget's orderby to "default".

= Random order keeps changing =
That's the daily seed. Same seed for the whole day, so caches are stable; tomorrow it'll change. You can switch to per-user-per-day in Settings if you want each visitor to see a different stable order.

= Can I show different orders to different user roles? =
Yes. On the Sort page you can configure per-context role overrides. Logged-out users always see the default order mode.

= Is there a REST API? =
Yes — full namespace `/wp-json/pcpo/v1/` with endpoints for orders, settings, templates, modes, and custom queries.

= Does it support RTL languages? =
Yes — ships with Hebrew translation (he_IL) and a dedicated RTL stylesheet.

== Screenshots ==

1. The Sort Posts screen: drag-and-drop ordering with thumbnails, sticky and hidden toggles and position jump.
2. Dashboard with stats, detected integrations and a quick start guide.
3. Order templates: save an order and reuse it in another category.
4. Settings: scope, behaviour, permissions and UI.
5. Bulk actions for a whole context: shuffle, alphabetise, reverse or reset.

== Changelog ==

= 1.0.16 — 2026-10-31 =
* **Changed:** requires WordPress 6.2. Database queries now pass table and column names through `$wpdb->prepare()` with `%i` identifiers.
* **Changed:** diagnostic lines go to the PHP error log only when `WP_DEBUG` and `PCPO_DEBUG` are both true in wp-config.php.
* **Fixed:** debug loopback request no longer disables SSL verification.
* **Fixed:** escaping in the debug banner, translator comments and ordered placeholders in CSV import messages.
* **Fixed:** the "For developers" code sample on the dashboard showed pale bars behind each line.
* **Fixed:** Post Order → Templates → Create template failed with "Cannot load pcpo-templates" because the form field was named `post_type`, which WordPress's admin.php treats as a page selector. The field is now `pcpo_post_type`.
* **Fixed:** the actions column on the Sort Posts screen collapsed to a few pixels, hiding the sticky and hide buttons.
* **Docs:** readme Stable tag and Tested up to (7.1), GPL LICENSE file, description updated (themes and builders that use standard WordPress queries), screenshots, Plugin URI moved to a-in.co.il.

= 1.0.14 — 2026-05-20 =
* **Improved:** Debug output is now impossible to miss when enabled — three channels: (1) a fixed purple banner at the bottom-right of every front-end page (logged-in editors only) showing `N marked / N skipped / N events` with a link to the admin Debug page, (2) a `console.log` group in the browser DevTools console with the full event payload, (3) the existing HTML comment in page source. If you see the banner, PHP ran and PCPO captured the request — if you DON'T see it, the page was served from cache (or you're not logged in as an editor).

= 1.0.13 — 2026-05-20 =
* **New:** Debug page (Post Order → Debug) shows the last 10 front-end captures with summary and full event timeline — no more digging through View Source. Capture is persisted to a transient so cached pages still produce readable diagnostics.
* **Improved:** The debug SQL capture now ALSO records UNMARKED product queries (extracts the ORDER BY clause + first 600 chars of SQL). This is how we'll spot WoodMart / Elementor product widgets running secondary queries that PCPO didn't get to mark.

= 1.0.12 — 2026-05-20 =
* **Fix:** Opening the Sort page via the admin-bar "Sort this category" link kept loading in the global / "All terms" context — the term from the URL was never preselected. Root cause: a JS bug was passing the **taxonomy's** `data-current` (a slug like `product_cat`) into the term selector, where the options have numeric IDs, so nothing matched. The fix passes the term's own `data-current` value. Also triggers a reload after preselect so the posts list and order mode refresh into the right context (they had run with the default global context before the async preselect arrived). This was likely the **direct cause** of front vs. admin order divergence — sorting was happening in the global context, not the category the admin-bar link claimed to open.

= 1.0.11 — 2026-05-20 =
* **Fix:** Admin-bar "Sort this category" shortcut produced "Cannot load pcpo-sort." on some installs because the URL used `post_type=` / `taxonomy=` — reserved WordPress query vars that confuse the admin router. Renamed to `pcpo_pt` / `pcpo_tax` / `pcpo_term`. The sort page accepts both old and new names for backward compatibility.
* **New:** Debug mode (Settings → Debug mode → On). Captures every decision PCPO makes for a request and dumps it as an HTML comment in the front-end footer (visible only to logged-in editors via View Source). For each query: post types, raw orderby, queried object, shopper sort param, mark/skip decision (with the reason), context resolved, mode resolved, ORDER BY before and after PCPO rewrites it, and the final SQL of marked queries. Same data also mirrored to `error_log`. Search the page source for "PCPO DEBUG".

= 1.0.10 — 2026-05-20 =
* **Fix:** Front-end product order didn't match the admin on stores using WoodMart / Elementor. Those page-builders render the catalog with an explicit `orderby` baked into the widget query (a theme default, not a shopper choice), which PCPO was treating as "explicit" and skipping. Now, for WooCommerce product queries, PCPO applies its order by default and only steps aside when the shopper actively picks a sort from the "Sort by" dropdown (`?orderby=…`). Non-product queries keep the previous, conservative behavior.
* **New:** "Sort this category" admin-bar shortcut. When you're viewing a product category (or any term archive) on the front-end as a logged-in editor, the admin bar shows a one-click link that opens the Sort page pre-filtered to that exact category — no more guessing which context maps to which page.

= 1.0.9 — 2026-05-20 =
* **Fix:** Guarantee PCPO's ordering beats WooCommerce, WoodMart, and any other plugin. The `posts_clauses` filter (and `pre_get_posts` marking) now run at `PHP_INT_MAX` instead of `999`, so PCPO writes the ORDER BY **last**. `posts_clauses` is the final clause filter WordPress applies, so winning there wins the whole query. The handler still only touches queries it has marked, so nothing else on the site is affected.
* **New:** Added Breeze (Cloudways) to the cache-purge list — clears Breeze's page cache and triggers the Cloudways Varnish purge on every order change.

= 1.0.8 — 2026-05-20 =
* **Fix:** Order changes made in the admin were not appearing on the front-end when a page cache was active (WP Rocket, LiteSpeed, W3TC, WP Super Cache, SiteGround, WP Engine). The plugin now purges the page cache automatically whenever the order changes (drag-drop save, move-to-position, shuffle / alphabetize / reverse / reset, sticky / hidden toggles, and order-mode changes).
* **New:** `pcpo_order_changed` action fires on every order mutation; the cache purge is hooked to it. Added a `pcpo_after_purge_caches` action so site owners can flush custom cache layers (Cloudflare, Fastly, server cache).
* **Note:** If you still don't see the new order on a cached page, confirm your caching/CDN is one of the supported layers, or hook `pcpo_after_purge_caches` to clear yours.

= 1.0.7 — 2026-05-20 =
* **Fix:** Converted the Sort list to a real <table> so the row layout can't be broken by theme CSS (WoodMart) overriding flex/grid display rules. Switched asset cache-busting to filemtime() so WP Rocket / browsers always fetch fresh admin CSS/JS.

= 1.0.6 — 2026-05-20 =
* **Improved:** Moved the row grid into a nested wrapper isolated from theme `<li>` rules, dropped the view-as-anchor button, and added an `all: revert` reset on row descendants.

= 1.0.5 — 2026-05-20 =
* **Fix:** Sort page rows were not laying out as a single flex line on WoodMart-themed admins — the "view" action button was being displaced to its own line above each row, and the toolbar was sprawled across too many strips.
* **Improved:** Switched the row layout from flex to **CSS Grid with named areas** (`"handle pos thumb main actions"`). Each row child is placed by `grid-area` name rather than source order, so any theme rule that changes `display`, `float`, or `order` on a child can't push it out of its assigned cell.
* **Improved:** Aggressive reset block under `body .pcpo-wrap .pcpo-row > *` neutralizes `position`, `transform`, `float`, and pseudo-element overlays that WoodMart's product admin CSS injects. This is what was floating the view icon above each row.
* **Improved:** Tightened toolbar — chips no longer use the "Filters:" label, "Clear" chip auto-pushes to the trailing edge, bulk action buttons (`Shuffle / A→Z / Z→A / Reverse / Reset`) use a consistent unified style (no more WP `.button` overrides fighting our radii), all strips share a clean 34px control height.
* **Improved:** Increased thumbnail size to 64×64 with checkered placeholder when no image is set, action buttons to 34×34 with 18px icons, position badge to a clickable purple-on-hover pill.

= 1.0.4 — 2026-05-20 =
* **Fix:** The Sort page list was not filling the available content width on WoodMart-themed admins — rows hugged one side with large empty space next to them, and the row backgrounds looked disconnected.
* **Improved:** The `.pcpo-sortable` list, `.pcpo-sort-area`, and `.pcpo-wrap` are now hard-pinned to `width: 100%` with `!important` rules scoped under `body`, defeating WoodMart's `ul`/`li` width and display overrides that were collapsing the list to its content width.
* **Improved:** Switched the row layout's responsive triggers from viewport `@media` queries to CSS container queries (`@container pcpo-sort`). The layout now reacts to the SORT AREA's actual width, not the browser viewport — so when WoodMart sidebars eat horizontal space, the row still degrades gracefully.
* **Improved:** Stronger card design — zebra striping on alternate rows, larger 56×56 thumbnails with placeholder pattern when no image is set, larger 32×32 action buttons, bolder typography, and a more pronounced sticky-row indicator (full 4px left bar instead of a gradient).
* **Improved:** Added a `@supports not (container-type)` fallback with viewport media queries for older browsers.

= 1.0.3 — 2026-05-20 =
* **Improved:** Redesigned the Sort page. The toolbar is now split into a filters strip and an actions strip, so labels and selects no longer crowd each other on narrow content widths.
* **Improved:** Sort rows switched from a fixed grid template to a flex layout with `min-width: 0` on the title cell — the title and meta now truncate cleanly instead of pushing the action buttons off the row.
* **Improved:** The drag handle, order badge, and action icons are smaller and more consistent. The "view post" link is revealed on row hover, reducing visual noise at rest.
* **Improved:** The always-on position input was replaced with a clickable order badge — click the number to edit it inline, press Enter to commit or Escape to cancel.
* **Improved:** Added responsive breakpoints at 1100px / 900px / 700px so the row stays usable when the WP content area is squeezed by admin sidebars (WoodMart) or on tablet widths.
* **Improved:** Bulk action buttons (Shuffle / A→Z / Z→A / Reverse / Reset) collapse to icon-only on narrow widths.
* **New:** Quick filter chips on the Sort page — Sticky only / Hidden only / Modified this week / In stock only (WooCommerce). Lets you narrow a large catalog down to the products that actually need manual reordering.
* **New:** WPML integration. When WPML is active, manual order / sticky / hidden writes are mirrored onto each sibling translation in the matching language's term, so the order you set on the Hebrew product also applies when a visitor switches to English. Toggleable from Settings → "WPML — sync order across languages" (on by default). The mirror runs through new `pcpo_after_upsert_order`, `pcpo_after_delete_order`, and `pcpo_after_reset_context` action hooks, which other integrations can subscribe to as well.

= 1.0.2 — 2026-05-20 =
* **Fix:** Sort page UI was breaking on sites with aggressive admin CSS (WoodMart theme + heavy YITH/admin plugin stacks). Product thumbnails rendered at full natural size and the grid layout collapsed into a vertical stack. Resolved with high-specificity defensive `!important` rules.
* **Fix:** `<img>` thumbnails now ship with explicit `width="60" height="60"` HTML attributes, so they survive theme rules like `img { max-width: 100% }`.
* **Improved:** Request a 120×120 source image instead of WP's default `'thumbnail'` size (which themes commonly redefine to large dimensions), then display crisply at 60×60 for retina screens.
* **Improved:** Added `loading="lazy"` on row thumbnails to ease the load when paginating 50+ items.

= 1.0.1 — 2026-05-20 =
* **Fix:** Autoloader was looking for `class-{name}.php` instead of `class-pcpo-{name}.php`, causing a fatal "Class PCPO_Installer not found" error on every front-end and admin page load. Resolved.
* Added an `error_log()` warning in the autoloader when `WP_DEBUG` is on, so any future class-not-found case is logged rather than silently failed.

= 1.0.0 — 2026-05-20 =
* Initial release.
* Drag-and-drop UI with per-taxonomy contexts.
* 14 order modes (manual, inherit, menu_order, title asc/desc, date asc/desc, modified, random, WC price/popularity/rating/stock).
* Sticky and hidden posts per context.
* Order templates with copy-between-terms.
* CSV import / export with dry-run mode.
* Full REST API at /wp-json/pcpo/v1/.
* Per-role ordering overrides.
* Bulk actions: shuffle, alphabetize, reverse, reset.
* `[pcpo_posts]` and `[pcpo_query]` shortcodes.
* Template tags: `pcpo_get_posts()`, `pcpo_the_query()`, `pcpo_apply_to_query()`, `pcpo_is_sticky()`, `pcpo_is_hidden()`.
* WooCommerce HPOS compatibility + bi-directional menu_order sync.
* Elementor Query ID opt-in and editor-preview skip.
* Hebrew translation (he_IL) + RTL stylesheet.

== Upgrade Notice ==

= 1.0.5 =
Sort page row layout is now a CSS Grid with named areas + aggressive resets on row descendants, defeating WoodMart's admin overlays that were floating the view icon above each row and breaking the inline layout. Toolbar tightened to fewer strips.

= 1.0.4 =
Fixes Sort page rows not filling the available width on WoodMart and other themes that override admin `ul`/`li` styles. Switches to container queries so the row layout adapts to its actual width.

= 1.0.3 =
Cleaner, more compact Sort page UI with a redesigned toolbar, click-to-edit order badge, and proper responsive behavior on narrow content widths.

= 1.0.2 =
UI fix for sites running heavy admin stylesheets (WoodMart, YITH stacks). The Sort page grid now lays out correctly even when other plugins try to override its CSS.

= 1.0.1 =
Critical fix: resolves "Class PCPO_Installer not found" fatal on every page load caused by a broken autoloader path. Update immediately.

= 1.0.0 =
Initial release.
