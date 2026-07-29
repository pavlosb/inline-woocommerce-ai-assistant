# Architecture

## Decision 001: Use the native WordPress AI Client

The plugin requires WordPress 7.0 or newer and uses `wp_ai_client_prompt()` as its only AI-generation entry point.

Consequences:

- Provider plugins and credentials are managed through WordPress Connectors.
- This plugin has no provider SDK, API-key field, or provider-specific request code.
- Provider/model selection remains portable.
- AI Client errors are handled as `WP_Error` values.

## Decision 002: Feature-specific server endpoints

The browser does not call a generic AI endpoint. Each user-facing feature is represented by a constrained server-side operation.

Initial endpoint:

```text
POST /wp-json/inline-woo-ai/v1/products/{id}/generate
```

Accepted task values:

- `short_description`
- `full_description`
- `benefits`
- `faqs`
- `tags`

The endpoint requires:

- authenticated WordPress REST nonce;
- `manage_woocommerce`;
- object-level `edit_post` permission;
- a valid WooCommerce product ID.

## Decision 003: Product-only data boundary

The AI context is assembled from a `WC_Product` object and selected product taxonomies.

Included in the first milestone:

- name and SKU;
- product type;
- existing short and full description text;
- categories and tags;
- product attributes;
- catalogue visibility and stock-status label;
- site language.

Excluded:

- orders and order notes;
- customer accounts and addresses;
- emails and telephone numbers;
- payment information;
- analytics identifiers;
- exact stock quantities;
- wholesale/customer-specific pricing;
- arbitrary post metadata.

## Decision 004: Generation is separate from mutation

Version 0.1.0 returns a draft to a read-only preview field. The user can copy it, but the plugin does not write it to the product.

A later apply workflow must:

1. display a field-level diff;
2. require explicit confirmation;
3. write through WooCommerce CRUD APIs;
4. store the previous value and audit metadata;
5. provide undo.

## Request flow

```text
Product editor metabox
  -> REST nonce-authenticated POST
  -> capability and object checks
  -> WC_Product context builder
  -> fixed task template and system instruction
  -> wp_ai_client_prompt()
  -> configured AI provider
  -> text suggestion
  -> read-only preview
```

## Error behavior

Expected errors are returned without modifying product data:

- WordPress AI support disabled;
- no suitable/configured provider;
- provider timeout or API error;
- invalid task;
- invalid product;
- insufficient permission;
- empty provider response.

## Planned extensions

- field-level preview/apply/undo;
- brand profiles stored as administrator-approved templates;
- WPML/Polylang language resolution;
- Action Scheduler bulk generation;
- structured product-quality audits;
- semantic search and customer-facing recommendations as a separate module.
