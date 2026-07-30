# Integration testing

The integration suite runs inside a real WordPress and WooCommerce environment while replacing the AI prompt builder with a deterministic in-memory test double. No provider credentials are required, and no external AI request is made.

## Pinned platform

- WordPress 7.0.2
- WooCommerce 10.9.4
- PHP 7.4 and 8.3 in GitHub Actions

The versions are defined in `.wp-env.json` and the workflow matrix.

## Local requirements

- Docker Desktop or Docker Engine
- Node.js 22 or newer
- npm
- Composer 2

## Run locally

From the repository root:

```bash
npm install
composer install
npm run env:start
npm run test:php
```

Stop the environment when finished:

```bash
npm run env:stop
```

To remove the containers, volumes, and downloaded test environment:

```bash
npm run env:destroy
```

## What is covered

The suite verifies:

- administrator and shop-manager generation access;
- denial without `manage_woocommerce`;
- object-level product edit permission;
- invalid products and generation tasks;
- disabled AI support;
- unsupported text-generation capability;
- provider errors and empty responses;
- the approved product-only context boundary;
- taxonomy attribute lookup failures;
- long-description truncation;
- real WordPress REST route validation and response handling;
- mutation permission denial;
- server-side pending suggestion IDs;
- task-specific HTML sanitization;
- stale-draft rejection;
- WooCommerce short/full description writes;
- product-tag parsing, replacement, and restoration;
- audit history including provider/model metadata when supplied;
- exact undo restoration;
- undo conflict protection after a manual edit;
- expired pending suggestions and expired audit records.

## AI isolation

`Inline_Woo_AI_Service` accepts an optional prompt-builder factory. Production code does not pass one, so it continues to call `wp_ai_client_prompt()`. Tests inject `Inline_Woo_AI_Test_Prompt_Builder`, which captures the prompt and returns a configured result entirely in memory.

Apply and Undo tests do not call the AI service. They create pending suggestions directly in the protected server-side store and exercise real WooCommerce CRUD, product-tag taxonomy APIs, product meta, and WordPress REST routing.
