=== INLINE WooCommerce AI Assistant ===
Contributors: pavlosb
Tags: woocommerce, artificial intelligence, ai, product descriptions, content
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate reviewable WooCommerce product-content drafts through the native WordPress AI Client.

== Description ==

INLINE WooCommerce AI Assistant adds an administrator-only assistant to the WooCommerce product editor.

The initial version can generate draft suggestions for:

* Short descriptions.
* Full descriptions.
* Benefits lists.
* Product FAQs.
* Product tags.

The plugin uses the provider-independent WordPress AI Client introduced in WordPress 7.0. Provider installation, selection, and API credentials remain under Settings > Connectors.

Generated content is displayed for review and copying. It is not automatically written to the product.

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

= Does it automatically overwrite product content? =

No. Version 0.1.0 only generates a reviewable draft and provides a copy button.

= Does it send order or customer data to an AI provider? =

No. The generation service is limited to selected product catalogue fields.

== Changelog ==

= 0.1.0 =

* Initial product editor assistant scaffold.
* Added fixed product-content generation tasks.
* Added secured feature-specific REST endpoint.
* Added generation preview and copy interface.
