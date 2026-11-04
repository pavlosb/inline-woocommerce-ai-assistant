=== INLINE WooCommerce AI Assistant ===
Contributors: pavlosb
Tags: woocommerce, artificial intelligence, ai, product descriptions, content
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate, compare, apply, audit, and undo WooCommerce product-content drafts through the native WordPress AI Client.

== Description ==

INLINE WooCommerce AI Assistant adds an administrator-only assistant to the WooCommerce product editor.

It can generate draft suggestions for:

* Short descriptions.
* Full descriptions.
* Benefits lists.
* Product FAQs.
* Product tags.

The plugin uses the provider-independent WordPress AI Client introduced in WordPress 7.0. Provider installation, selection, and API credentials remain under Settings > Connectors. Generated editorial content defaults explicitly to Greek (el-GR), while brand names, product names, model identifiers, SKUs, standards, software names, protocol names, and established technical terms are preserved when appropriate.

Generation never changes product data. A generated value is stored temporarily on the server and shown beside the current target field. Applying requires explicit confirmation, uses WooCommerce CRUD or product-tag taxonomy APIs, and records the prior and applied values in protected product audit history.

Undo is available for seven days when the target field has not been edited since the AI suggestion was applied. Stale drafts and conflicting undo requests fail without overwriting product data.

== Requirements ==

* WordPress 7.0 or newer.
* WooCommerce.
* PHP 7.4 or newer.
* A configured AI provider in Settings > Connectors.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate WooCommerce.
3. Install and configure a compatible AI provider under Settings > Connectors.
4. Activate INLINE WooCommerce AI Assistant.
5. Edit a WooCommerce product and use the INLINE AI Assistant metabox.

== Frequently Asked Questions ==

= Does the plugin store my provider API key? =

No. Provider credentials are managed by the WordPress Connectors API.

= What language does it generate? =

Version 0.2.1 explicitly generates editorial content in Greek (el-GR). Brand names and technical identifiers remain unchanged when appropriate.

= Does it automatically overwrite product content? =

No. Generation is read-only. Applying a server-stored suggestion requires a visible confirmation.

= Can an AI apply overwrite a later manual edit? =

No. Apply rejects drafts generated from an older field value, and Undo refuses to overwrite a field that changed after the AI apply operation.

= Where are benefits and FAQs applied? =

They replace the WooCommerce full description. The target field is displayed before confirmation.

= Does it send order or customer data to an AI provider? =

No. The generation service is limited to selected product catalogue fields.

== Changelog ==

= 0.2.1 =

* Made Greek (el-GR) the explicit default generation language.
* Added prompt rules to preserve brand names, model identifiers, SKUs, standards, software names, protocol names, and established technical terms when appropriate.
* Added integration coverage for the explicit Greek generation contract.

= 0.2.0 =

* Added current/generated field comparison and explicit Apply confirmation.
* Added server-side pending suggestion IDs with a 24-hour expiry.
* Added task-specific HTML sanitization and product-tag parsing.
* Added protected product audit history with user, timestamp, and provider/model metadata when available.
* Added conflict-safe Undo with a seven-day window.
* Added stale-draft, expired-record, permission, sanitization, tags, apply, history, and undo integration tests.

= 0.1.1 =

* Removed forced temperature and token parameters for compatibility with automatically selected AI models.
* Added a text-generation support check before sending the request.

= 0.1.0 =

* Initial product editor assistant scaffold.
* Added fixed product-content generation tasks.
* Added secured feature-specific REST endpoint.
* Added generation preview and copy interface.
