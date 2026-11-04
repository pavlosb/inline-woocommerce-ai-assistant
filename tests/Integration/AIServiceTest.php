<?php
/**
 * AI service integration tests.
 *
 * @package Inline_Woo_AI
 */

/**
 * Tests the product context and provider boundary in a real WP/WooCommerce runtime.
 */
final class Inline_Woo_AI_AI_Service_Test extends WP_UnitTestCase {
	/**
	 * Test prompt builder.
	 *
	 * @var Inline_Woo_AI_Test_Prompt_Builder
	 */
	private $builder;

	/**
	 * Service under test.
	 *
	 * @var Inline_Woo_AI_Service
	 */
	private $service;

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		WC_Install::create_roles();

		$this->builder = new Inline_Woo_AI_Test_Prompt_Builder();
		$builder       = $this->builder;
		$this->service = new Inline_Woo_AI_Service(
			static function ( string $prompt ) use ( $builder ) {
				$builder->prompt = $prompt;
				return $builder;
			}
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'Integration Test Product' );
		$product->set_sku( 'IWAI-TEST-001' );
		$product->set_short_description( '<p>Existing short description.</p>' );
		$product->set_description( '<p>Existing full description.</p>' );
		$product->set_status( 'publish' );
		$this->product_id = $product->save();
	}

	/**
	 * Clean up filters and users.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_supports_ai' );
		remove_all_filters( 'woocommerce_get_product_terms' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Invalid generation tasks fail before building a provider request.
	 *
	 * @return void
	 */
	public function test_invalid_task_returns_error(): void {
		$result = $this->service->generate( $this->product_id, 'delete_product' );

		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_invalid_task', $result->get_error_code() );
		$this->assertSame( '', $this->builder->prompt );
	}

	/**
	 * Missing products return a focused error.
	 *
	 * @return void
	 */
	public function test_missing_product_returns_error(): void {
		$result = $this->service->generate( 999999, 'short_description' );

		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_product_not_found', $result->get_error_code() );
	}

	/**
	 * Disabling AI prevents the prompt factory from running.
	 *
	 * @return void
	 */
	public function test_disabled_ai_support_prevents_generation(): void {
		add_filter( 'wp_supports_ai', '__return_false' );

		$result = $this->service->generate( $this->product_id, 'short_description' );

		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_disabled', $result->get_error_code() );
		$this->assertSame( '', $this->builder->prompt );
	}

	/**
	 * Unsupported configured models return a useful error.
	 *
	 * @return void
	 */
	public function test_unsupported_text_generation_returns_error(): void {
		$this->builder->supported = false;

		$result = $this->service->generate( $this->product_id, 'short_description' );

		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_text_generation_unsupported', $result->get_error_code() );
	}

	/**
	 * Provider errors pass through as WP_Error instances.
	 *
	 * @return void
	 */
	public function test_provider_error_is_returned(): void {
		$this->builder->result = new WP_Error( 'test_provider_error', 'Provider failed.' );

		$result = $this->service->generate( $this->product_id, 'short_description' );

		$this->assertWPError( $result );
		$this->assertSame( 'test_provider_error', $result->get_error_code() );
	}

	/**
	 * Empty provider responses are rejected.
	 *
	 * @return void
	 */
	public function test_empty_provider_response_is_rejected(): void {
		$this->builder->result = " \n\t ";

		$result = $this->service->generate( $this->product_id, 'short_description' );

		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_empty_response', $result->get_error_code() );
	}

	/**
	 * The prompt contains only the approved catalogue fields.
	 *
	 * @return void
	 */
	public function test_product_context_excludes_customer_and_order_data(): void {
		self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'private-customer@example.test',
			)
		);

		$result  = $this->service->generate( $this->product_id, 'short_description' );
		$context = $this->get_prompt_context();

		$this->assertSame( '<p>Generated test draft.</p>', $result );
		$this->assertSame(
			array(
				'language',
				'product_id',
				'product_type',
				'name',
				'sku',
				'current_short',
				'current_full',
				'categories',
				'tags',
				'attributes',
				'catalog_visibility',
				'stock_status',
			),
			array_keys( $context )
		);
		$this->assertArrayNotHasKey( 'customer', $context );
		$this->assertArrayNotHasKey( 'order', $context );
		$this->assertArrayNotHasKey( 'billing_email', $context );
		$this->assertSame( 'el-GR', $context['language'] );
		$this->assertStringNotContainsString( 'private-customer@example.test', $this->builder->prompt );
	}

	/**
	 * Generation explicitly targets Greek while preserving technical identifiers.
	 *
	 * @return void
	 */
	public function test_generation_explicitly_targets_greek(): void {
		$this->service->generate( $this->product_id, 'short_description' );

		$this->assertStringContainsString( 'Target language: Greek (el-GR).', $this->builder->prompt );
		$this->assertStringContainsString( 'natural, professional Greek', $this->builder->prompt );
		$this->assertStringContainsString( 'model numbers, SKUs, standards', $this->builder->prompt );
		$this->assertStringContainsString( 'Always produce the requested content in Greek (el-GR)', $this->builder->system_instruction );
		$this->assertStringContainsString( 'Preserve brand names, trademarks, product names', $this->builder->system_instruction );
	}

	/**
	 * Long catalogue descriptions are bounded before provider submission.
	 *
	 * @return void
	 */
	public function test_long_description_is_truncated(): void {
		$product = wc_get_product( $this->product_id );
		$product->set_description( str_repeat( 'x', 7000 ) );
		$product->save();

		$this->service->generate( $this->product_id, 'full_description' );
		$context = $this->get_prompt_context();

		$this->assertLessThan( 7000, strlen( $context['current_full'] ) );
		$this->assertStringContainsString( '…', $context['current_full'] );
	}

	/**
	 * Taxonomy lookup failures degrade to an empty attribute option list.
	 *
	 * @return void
	 */
	public function test_taxonomy_attribute_lookup_failure_is_handled(): void {
		$taxonomy = 'iwai_test_attribute';
		register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array( 'label' => 'IWAI Test Attribute' )
		);

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 1 );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( array() );
		$attribute->set_visible( true );

		$product = wc_get_product( $this->product_id );
		$product->set_attributes( array( $attribute ) );

		$callback = static function ( $terms, $product_id, $requested_taxonomy ) use ( $taxonomy ) {
			if ( $taxonomy === $requested_taxonomy ) {
				return new WP_Error( 'forced_taxonomy_failure', 'Forced taxonomy lookup failure.' );
			}
			return $terms;
		};
		add_filter( 'woocommerce_get_product_terms', $callback, 10, 3 );

		try {
			$method = new ReflectionMethod( Inline_Woo_AI_Service::class, 'build_product_context' );
			$method->setAccessible( true );
			$context = $method->invoke( $this->service, $product );
		} finally {
			remove_filter( 'woocommerce_get_product_terms', $callback, 10 );
			unregister_taxonomy( $taxonomy );
		}

		$this->assertNotEmpty( $context['attributes'] );
		$this->assertSame( array(), reset( $context['attributes'] ) );
	}

	/**
	 * Decode the JSON context embedded in the captured prompt.
	 *
	 * @return array<string, mixed>
	 */
	private function get_prompt_context(): array {
		$marker = "Use only the following product data:\n";
		$parts  = explode( $marker, $this->builder->prompt, 2 );

		$this->assertCount( 2, $parts, 'The prompt did not contain the expected context marker.' );

		$context = json_decode( $parts[1], true );
		$this->assertIsArray( $context, 'The product context was not valid JSON.' );

		return $context;
	}
}
