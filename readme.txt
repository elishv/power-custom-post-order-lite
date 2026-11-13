=== Power Custom Post Order ===
Contributors: elis85
Donate link: https://a-in.co.il/plugins/post-order/
Tags: post order, sort posts, drag and drop, woocommerce sort, custom order
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.17
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

= 1.0.17 — 2026-11-13 =
* **Security:** the Debug page's "Test URL" check only accepts URLs on this site, because the test request carries the administrator's login cookies.

= 1.0.16 — 2026-10-31 =
* **Changed:** requires WordPress 6.2. Database queries now pass table and column names through `$wpdb->prepare()` with `%i` identifiers.
* **Changed:** diagnostic lines go to the PHP error log only when `WP_DEBUG` and `PCPO_DEBUG` are both true in wp-config.php.
* **Fixed:** debug loopback request no longer disables SSL verification.
* **Fixed:** escaping in the debug banner, translator comments and ordered placeholders in CSV import messages.
* **Fixed:** the "For developers" code sample on the dashboard showed pale bars behind each line.
* **Fixed:** Post Order → Templates → Create template failed with "Cannot load pcpo-templates" because the form field was named `post_type`, which WordPress's admin.php treats as a page selector. The field is now `pcpo_post_type`.
* **Fixed:** the actions column on the Sort Posts screen collapsed to a few pixels, hiding the sticky and hide buttons.
* **Docs:** readme Stable tag and Tested up to (7.1), GPL LICENSE file, description updated (themes and builders that use standard WordPress queries), screenshots, Plugin URI moved to a-in.co.il.

Older versions: see changelog.txt.

== Upgrade Notice ==

= 1.0.17 =
Security: the Debug page only tests URLs on your own site.
