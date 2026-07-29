<?php
/**
 * REST controller integration tests.
 *
 * @package Inline_Woo_AI
 */

/**
 * Tests route validation and WordPress capability boundaries.
 */
final class Inline_Woo_AI_REST_Controller_Test extends WP_UnitTestCase {
	/**
	 * Test prompt builder.
	 *
	 * @var Inline_Woo_AI_Test_Prompt_Builder
	 */
	private $builder;

	/**
	 * Controller under test.
	 *
	 * @var Inline_Woo_AI_REST_Controller
	 */
	private $controller;

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
		$service       = new Inline_Woo_AI_Service(
			static function ( string $prompt ) use ( $builder ) {
				$builder->prompt = $prompt;
				return $builder;
			}
		);
		$this->controller = new Inline_Woo_AI_REST_Controller( $service );

		$product = new WC_Product_Simple();
		$product->set_name( 'REST Integration Product' );
		$product->set_status( 'publish' );
		$this->product_id = $product->save();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->controller->register_hooks();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Remove route hooks between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Administrators can generate a draft.
	 *
	 * @return void
	 */
	public function test_administrator_can_generate(): void {
		$this->set_current_user_role( 'administrator' );

		$response = $this->dispatch_generation_request( $this->product_id, 'short_description' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->product_id, $data['product_id'] );
		$this->assertSame( '<p>Generated test draft.</p>', $data['suggestion'] );
	}

	/**
	 * Shop managers can generate a draft.
	 *
	 * @return void
	 */
	public function test_shop_manager_can_generate(): void {
		$this->set_current_user_role( 'shop_manager' );

		$response = $this->dispatch_generation_request( $this->product_id, 'benefits' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Customers without manage_woocommerce are denied.
	 *
	 * @return void
	 */
	public function test_customer_is_denied(): void {
		$this->set_current_user_role( 'customer' );

		$response = $this->dispatch_generation_request( $this->product_id, 'short_description' );
		$data     = $response->get_data();

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'inline_woo_ai_forbidden', $data['code'] );
	}

	/**
	 * manage_woocommerce alone is not enough without object edit permission.
	 *
	 * @return void
	 */
	public function test_object_level_edit_denial_is_enforced(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		$response = $this->dispatch_generation_request( $this->product_id, 'short_description' );
		$data     = $response->get_data();

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'inline_woo_ai_forbidden', $data['code'] );
	}

	/**
	 * Non-product objects are rejected even when editable.
	 *
	 * @return void
	 */
	public function test_non_product_object_is_rejected(): void {
		$this->set_current_user_role( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->dispatch_generation_request( $post_id, 'short_description' );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'inline_woo_ai_invalid_product', $data['code'] );
	}

	/**
	 * The route schema rejects unsupported tasks before callback execution.
	 *
	 * @return void
	 */
	public function test_route_rejects_invalid_task(): void {
		$this->set_current_user_role( 'administrator' );

		$response = $this->dispatch_generation_request( $this->product_id, 'delete_product' );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertSame( '', $this->builder->prompt );
	}

	/**
	 * Set the current test user by role.
	 *
	 * @param string $role WordPress role.
	 * @return int
	 */
	private function set_current_user_role( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Dispatch a generation request through the real REST server.
	 *
	 * @param int    $object_id Product or post ID.
	 * @param string $task      Generation task.
	 * @return WP_REST_Response
	 */
	private function dispatch_generation_request( int $object_id, string $task ): WP_REST_Response {
		$request = new WP_REST_Request(
			'POST',
			'/inline-woo-ai/v1/products/' . $object_id . '/generate'
		);
		$request->set_param( 'task', $task );

		return rest_do_request( $request );
	}
}
