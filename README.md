# INLINE WooCommerce AI Assistant

A provider-independent AI product-content assistant for WooCommerce, built on the native WordPress 7.0 AI Client and Connectors APIs.

## Status

Early development. The first milestone provides an admin-only product editor assistant that generates draft suggestions without automatically modifying product data.

## Principles

- Use `wp_ai_client_prompt()` rather than calling a provider API directly.
- Keep provider credentials in WordPress **Settings → Connectors**.
- Expose narrowly scoped, server-side REST endpoints; never expose arbitrary AI prompting to the browser.
- Require explicit human review before changing product data.
- Use WooCommerce CRUD APIs for product operations.
- Do not send customer, order, payment, or other personal data to AI providers.

## Initial scope

- Product-editor assistant metabox.
- Generate a short description, full description, benefits, FAQs, or tags.
- Use current WooCommerce product data as controlled context.
- Preview and copy generated text.
- Permission, nonce, error, and AI-availability handling.

## Requirements

- WordPress 7.0 or newer.
- WooCommerce.
- PHP 7.4 or newer.
- At least one AI provider configured through **Settings → Connectors**.

## Architecture

```text
WooCommerce product editor
        ↓
Feature-specific WordPress REST endpoint
        ↓
INLINE prompt and product-context service
        ↓
WordPress AI Client
        ↓
Configured WordPress AI provider connector
```

## Roadmap

1. Safe single-product draft generation.
2. Preview, diff, apply, and undo history.
3. Brand profiles and prompt templates.
4. WPML-aware generation.
5. Action Scheduler bulk jobs.
6. Product catalogue quality audits.
7. Semantic product discovery and recommendations.

## Development

Repository-specific Codex guidance is provided in `AGENTS.md`.

## License

GPL-2.0-or-later.
