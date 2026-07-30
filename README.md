# INLINE WooCommerce AI Assistant

A provider-independent AI product-content assistant for WooCommerce, built on the native WordPress 7.0 AI Client and Connectors APIs.

## Status

Version 0.2.0 adds a controlled single-product apply workflow with server-side suggestion IDs, field comparison, audit history, and conflict-safe undo.

## Principles

- Use `wp_ai_client_prompt()` rather than calling a provider API directly.
- Keep provider credentials in WordPress **Settings → Connectors**.
- Expose narrowly scoped, server-side REST endpoints; never expose arbitrary AI prompting to the browser.
- Require explicit human review and confirmation before changing product data.
- Use WooCommerce CRUD APIs for product text and WordPress taxonomy APIs for product tags.
- Do not send customer, order, payment, or other personal data to AI providers.

## Current scope

- Product-editor assistant metabox.
- Generate a short description, full description, benefits, FAQs, or tags.
- Use current WooCommerce product data as controlled context.
- Compare the current field value with the generated value.
- Apply only a server-stored, non-expired suggestion after confirmation.
- Store the prior value, applied value, task, user, provider/model metadata when available, and timestamp in product audit history.
- Undo within seven days when the field has not changed since the AI apply operation.
- Reject stale suggestions when the product field changes after generation.
- Permission, REST nonce, sanitization, error, and AI-availability handling.

## Requirements

- WordPress 7.0 or newer.
- WooCommerce.
- PHP 7.4 or newer.
- At least one AI provider configured through **Settings → Connectors**.

## Architecture

```text
WooCommerce product editor
        ↓
Feature-specific WordPress REST endpoints
        ↓
Pending suggestion store (24-hour expiry)
        ↓
WooCommerce CRUD / product-tag taxonomy APIs
        ↓
Product audit history + conflict-safe undo (7-day window)

Generation path:
Product context → WordPress AI Client → configured provider connector
```

Generation and mutation are separate requests. The browser receives a suggestion ID, not authority to submit an arbitrary replacement value. Apply and Undo resolve their values from protected product metadata on the server.

## Task targets

- Short description → WooCommerce short description.
- Full description → WooCommerce full description.
- Benefits → replaces the WooCommerce full description with the generated benefits list.
- FAQs → replaces the WooCommerce full description with the generated FAQs.
- Tags → replaces product tags through WordPress taxonomy APIs.

The target field is displayed before Apply and requires explicit confirmation.

## Roadmap

1. Safe single-product draft generation.
2. Preview, apply, audit history, and undo.
3. Brand profiles and prompt templates.
4. WPML-aware generation.
5. Action Scheduler bulk jobs.
6. Product catalogue quality audits.
7. Semantic product discovery and recommendations.

## Development

Repository-specific Codex guidance is provided in `AGENTS.md`.

```bash
npm install
composer install
npm run env:start
npm run test:php
npm run env:destroy
```

## Testing

The repository includes a wp-env integration suite pinned to WordPress 7.0.2 and WooCommerce 10.9.4. It runs on PHP 7.4 and 8.3 without provider credentials or external AI calls.

Coverage includes generation boundaries, REST permissions, task validation, apply sanitization, stale suggestions, tag replacement, audit history, exact undo, expired records, and manual-edit conflict protection.

See [`docs/testing.md`](docs/testing.md) for local commands and coverage details.

## License

GPL-2.0-or-later.
