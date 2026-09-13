# Sortillus Lite for WooCommerce

Minimal WooCommerce connector for Sortillus.

See the [installation and setup instructions](installation.md) for requirements, connection, catalog import, and troubleshooting.

## Features

- Connect or reconnect with a one-time activation token.
- Import WooCommerce product categories, including empty categories, in parent-first background batches. Product import becomes available after every category is confirmed saved.
- Import all published WooCommerce product parents in background batches.
- Display store-level import progress and Sortillus connector health.
- Send a product offer after WooCommerce product creation, update, or stock-status changes.
- Automatically send created or updated product categories, including their ancestors, in parent-first order.
- Optionally load the hosted Sortillus Shop Assistant widget; disabled by default. The plugin places its launcher next to a recognized header product-search form and falls back to a floating button when no search form is available.

The plugin intentionally does not include category deletion sync, automatic tag sync, inbound callbacks, recommendations, semantic-search replacement, order export, or per-product sync metadata.

## Data flow

The connector uses `https://data.sortillus.com` for API requests and loads the shared widget from `https://admin.sortillus.com/shop-assistant/v1/widget.js`. WooCommerce products are represented as Sortillus offers and upserted through the existing `/api/v3/offers` endpoints.

API requests require HTTPS on `data.sortillus.com`, using the default HTTPS port, and do not follow redirects. Assistant session requests must originate from the configured WordPress home or site URL. Additional hostnames must be configured as the appropriate WordPress URL before using the assistant through them.

## Installation

1. Copy `woo-sortillus-lite` to `wp-content/plugins/` or install a zip containing the directory.
2. Ensure WooCommerce is active, then activate **Sortillus Lite for WooCommerce**.
3. Open **Sortillus Lite**, paste the one-time activation token, and activate.
4. Click **Import Categories** and wait for completion.
5. Click **Import Products** when the button appears.
6. Enable the Shopping Assistant checkbox if the storefront chat button is wanted.

The full Woo Sortillus plugin and the lite plugin must not be active together.

Themes with custom header markup can override the detected search element with the `woo_sortillus_lite_assistant_desktop_selector` and `woo_sortillus_lite_assistant_mobile_selector` filters.

Category import uses `POST /api/v3/shop/categories/batch` with the connector token. Rails creates categories in that installation's domain with the `woocommerce` source platform. Existing connections need to reconnect with a new activation token to grant `taxonomy:write`. Partial category responses keep product import locked; retrying upserts the categories safely. Missing offer categories are ignored by Rails; existing categories are still assigned.

Once connected, creating or editing a product category queues an automatic update with a five-second delay. Delivery depends on WooCommerce Action Scheduler. The update includes the category's current name, description, and parent hierarchy, including empty categories. Temporary API failures retry up to five times; final errors appear in the category status area. Automatic updates do not replace the initial category import or mark it complete.

## Tests

Run `php tests/package-test.php`, `php tests/offer-builder-test.php`, `php tests/sync-test.php`, `php tests/category-client-test.php`, `php tests/assistant-security-test.php`, and `node tests/admin-test.js`.
