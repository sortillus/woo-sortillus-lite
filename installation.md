# Installing Sortillus Lite for WooCommerce

This guide covers installing the plugin, connecting your store to Sortillus, importing your catalog, and enabling the shopping assistant.

## Requirements

- WordPress 6.0 or later.
- PHP 7.4 or later.
- WooCommerce 5.0 or later, installed and active.
- A WordPress administrator account.
- The Sortillus Lite plugin ZIP file, such as `woo-sortillus-lite-1.1.0.zip`.
- A Sortillus account. You can create one during setup below.

If the full **Woo Sortillus** plugin is active, deactivate it before activating Sortillus Lite. The two plugins must not run together.

## 1. Install and activate the WordPress plugin

1. Sign in to your WordPress administration area.
2. Go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the Sortillus Lite ZIP file and click **Install Now**.
4. When installation finishes, click **Activate Plugin**.
5. Open **Sortillus Lite** in the WordPress admin sidebar.

The plugin does not automatically open its setup page after activation. Open it yourself to complete the connection.

## 2. Create or sign in to your Sortillus account

1. In the plugin's **Connection** section, click **Register or sign in to Sortillus**. This opens Sortillus in a new tab.
2. Create an account at [Sortillus registration](https://admin.sortillus.com/users/sign_up), or sign in with your existing account.
3. If you created an account, confirm your email address using the confirmation email.
4. Open **API** in Sortillus, or go directly to the [Sortillus API page](https://admin.sortillus.com/api/documentation).
5. Find **Commerce Connector Activation** and click **Generate Activation Token**.
6. Copy the generated token. It is valid for one use and expires at the time shown on the page.

Use the token from **Commerce Connector Activation**.

## 3. Connect your WooCommerce store

1. Return to **Sortillus Lite** in WordPress.
2. Paste the copied token into **Activation token**.
3. Click **Activate**.
4. Check that the page displays **Connected to Sortillus.** and the connection status changes to **Connected**.

Activating the WordPress plugin and connecting it to Sortillus are separate steps. Both are required before importing your catalog.

## 4. Import categories, then products

1. In **Category Import**, click **Import Categories**.
2. Wait until the page reports that categories have been imported. The import includes empty categories.
3. When **Import Products** becomes available, click it.
4. Follow the progress in **Product Sync Status** and check for any reported errors.

Product import runs in background batches and imports published WooCommerce products. After setup, the plugin sends product offers when products are created or updated, or their stock status changes.

After the initial import, new and edited product categories are sent automatically in the background. Run **Import Categories** again if you need to rebuild the full category catalog.

## 5. Enable the shopping assistant, if wanted

The shopping assistant is disabled by default.

1. In **Shopping Assistant**, select **Show the Sortillus assistant/chat button on the storefront**.
2. Click **Save**.
3. Visit your storefront and check that the assistant button appears. It appears beside a recognized header product-search form, or as a floating button if no matching search form is found.

## Troubleshooting

- **The plugin will not activate:** Check that WooCommerce is installed and active, and that your site meets the requirements above.
- **The plugin says it is paused:** Deactivate the full Woo Sortillus plugin, then return to Sortillus Lite.
- **Commerce Connector Activation is missing in Sortillus:** Make sure you are signed in and have confirmed your account's email address.
- **The activation token has expired or was already used:** Generate a new token in Sortillus and try again.
- **Import Products is missing:** Complete **Import Categories** first. If category import reports an error, resolve it and retry.
- **An existing connection asks you to reconnect for category import:** Generate a new activation token, paste it into **Reconnect with a new activation token**, and click **Reconnect**. Then run **Import Categories**.
- **Connection requests fail:** Check the error shown on the plugin page. Your server must allow outgoing HTTPS requests to `data.sortillus.com`.
- **The assistant button is missing:** Check that the connection status is **Connected** and that you selected the assistant checkbox and clicked **Save**.

If something goes wrong or you need help, contact [support@sortillus.com](mailto:support@sortillus.com). Include your store URL and any error message shown.
