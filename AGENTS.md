# Codex instructions

## Project goal

Build a safe, provider-independent WooCommerce AI assistant using the native WordPress 7.0 AI Client and Connectors APIs.

The first product is an administrator-facing catalogue assistant. It generates drafts for human review; it does not autonomously modify products, prices, stock, orders, customers, refunds, emails, or store settings.

## Required architecture

- Require WordPress 7.0+ and call `wp_ai_client_prompt()`.
- Do not add direct OpenAI, Anthropic, Google, or other provider SDKs.
- Do not store API keys. WordPress Connectors owns provider credentials.
- Browser code must call feature-specific plugin REST endpoints.
- Never expose an unrestricted prompt endpoint to JavaScript.
- Use WooCommerce CRUD objects instead of direct post/meta writes where practical.
- Use Action Scheduler for future bulk operations.
- Keep AI generation separate from applying changes.

## Security and privacy rules

- Every REST route needs an explicit `permission_callback`.
- Product operations require both `manage_woocommerce` and object-level edit permission.
- Require WordPress REST nonce authentication for wp-admin requests.
- Sanitize all request parameters and escape all rendered output.
- Do not send order, customer, payment, address, email, phone, account, or analytics identifiers to AI providers.
- Do not log prompts or responses by default.
- Never commit credentials, real customer data, production exports, or private store prompts.
- Treat AI output as untrusted data.

## Content-safety rules

Prompts must tell the model to use only supplied catalogue facts. Do not invent:

- ingredients or materials;
- certifications or awards;
- dimensions or compatibility;
- prices, discounts, or stock quantities;
- medical, therapeutic, regulatory, legal, or guaranteed outcomes.

Any feature that applies generated content must show a preview and explicit confirmation. Preserve the original value for rollback.

## Compatibility

- PHP 7.4+ syntax only unless the minimum requirement is deliberately raised.
- Follow WordPress PHP and JavaScript coding conventions.
- Keep strings translatable with text domain `inline-woocommerce-ai-assistant`.
- Account for simple, variable, grouped, and external products when product behavior is affected.
- Design language handling so WPML/Polylang support can be added without coupling core services to either plugin.
- Avoid assumptions about the active theme or WooCommerce product editor implementation.

## Repository structure

- `inline-woocommerce-ai-assistant.php`: bootstrap and constants only.
- `includes/class-plugin.php`: dependency checks and service wiring.
- `includes/class-ai-service.php`: product context, fixed prompts, WordPress AI Client calls.
- `includes/class-rest-controller.php`: narrowly scoped REST endpoints.
- `includes/class-product-metabox.php`: wp-admin product UI.
- `assets/`: browser assets only.
- `docs/`: architecture and decisions.

## Change workflow

1. Read the relevant existing classes before editing.
2. Keep each change narrowly scoped.
3. Add or update documentation when behavior or architecture changes.
4. Run PHP syntax checks for every PHP file.
5. Test permission failures, missing provider behavior, provider errors, empty responses, and successful generation.
6. Verify that no request contains customer or order data.
7. Open a pull request describing risks, test steps, and any deferred work.

## Definition of done

A change is complete only when:

- normal and failure paths are handled;
- security and capability checks are present;
- the user must review generated content before any mutation;
- output is translatable and accessible;
- no provider credential logic is added;
- manual test instructions are included in the pull request.
