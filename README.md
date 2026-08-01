# Sortillus Lite for WooCommerce

Minimal WooCommerce connector for Sortillus.

## Features

- Connect or reconnect with a one-time activation token.
- Import all published WooCommerce product parents in background batches.
- Display store-level import progress and Sortillus connector health.
- Send a product offer after WooCommerce product creation, update, or stock-status changes.
- Optionally load the hosted Sortillus Shop Assistant widget; disabled by default.

The plugin intentionally does not include taxonomy synchronization, inbound callbacks, recommendations, semantic-search replacement, order export, or per-product sync metadata.

## Data flow

The connector uses `https://data.sortillus.com` for API requests and loads the shared widget from `https://admin.sortillus.com/shop-assistant/v1/widget.js`. WooCommerce products are represented as Sortillus offers and upserted through the existing `/api/v3/offers` endpoints.

## Installation

1. Copy `woo-sortillus-lite` to `wp-content/plugins/` or install a zip containing the directory.
2. Ensure WooCommerce is active, then activate **Sortillus Lite for WooCommerce**.
3. Open **Sortillus Lite**, paste the one-time activation token, and activate.
4. Click **Import Products**.
5. Enable the Shopping Assistant checkbox if the storefront chat button is wanted.

The full Woo Sortillus plugin and the lite plugin must not be active together.

