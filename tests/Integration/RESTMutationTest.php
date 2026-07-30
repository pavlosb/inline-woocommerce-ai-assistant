<?php
/**
 * REST mutation endpoint integration tests.
 *
 * @package Inline_Woo_AI
 */

/**
 * Tests permission and response boundaries for apply, history, and undo routes.
 */
final class Inline_Woo_AI_REST_Mutation_Test extends WP_UnitTestCase {
	/** @var Inline_Woo_AI_Suggestion_Store */
	private $store;

	/** @var Inline_Woo_AI_Product_Content_Service */
	private $content_service;

	/** @var Inline_Woo_AI_REST_Controller */
	private $controller;

	/** @var int */
	private $product_id;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		WC_Install::create_roles();

		$product = new WC_Product_Simple();
		$product->set_name( 'REST Mutation Product' );
		$product->set_status( 'publish' );
		$product->set_short_description( '<p>REST original.</p>' );
		$this->product_id = $product->save();

		$builder = new Inline_Woo_AI_Test_Prompt_Builder();
		$service = new Inline_Woo_AI_Service(
			static function ( string $prompt ) use ( $builder ) {
				$builder->prompt = $prompt;
				return $builder;
			}
		);

		$this->store           = new Inline_Woo_AI_Suggestion_Store();
		$this->content_service = new Inline_Woo_AI_Product_Content_Service( $this->store );
		$this->controller      = new Inline_Woo_AI_REST_Controller( $service, $this->store, $this->content_service );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->controller->register_hooks();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Clean up hooks and current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * An administrator can apply and undo through the real REST server.
	 *
	 * @return void
	 */
	public function test_administrator_can_apply_and_undo(): void {
		$this->set_current_user_role( 'administrator' );
		$pending = $this->create_pending( '<p>REST generated.</p>' );

		$apply_response = $this->dispatch( 'apply', array( 'suggestion_id' => $pending['id'] ) );
		$apply_data     = $apply_response->get_data();

		$this->assertSame( 200, $apply_response->get_status() );
		$this->assertNotEmpty( $apply_data['audit_id'] );
		$this->assertSame( '<p>REST generated.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );

		$undo_response = $this->dispatch( 'undo', array( 'audit_id' => $apply_data['audit_id'] ) );
		$this->assertSame( 200, $undo_response->get_status() );
		$this->assertSame( '<p>REST original.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * A customer cannot apply a valid pending suggestion.
	 *
	 * @return void
	 */
	public function test_customer_is_denied_apply_permission(): void {
		$this->set_current_user_role( 'administrator' );
		$pending = $this->create_pending( '<p>Denied value.</p>' );
		$this->set_current_user_role( 'customer' );

		$response = $this->dispatch( 'apply', array( 'suggestion_id' => $pending['id'] ) );
		$data     = $response->get_data();

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'inline_woo_ai_forbidden', $data['code'] );
		$this->assertSame( '<p>REST original.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * The history route returns the audit created by apply.
	 *
	 * @return void
	 */
	public function test_history_route_returns_recent_apply(): void {
		$this->set_current_user_role( 'administrator' );
		$pending = $this->create_pending( '<p>History value.</p>' );
		$apply   = $this->dispatch( 'apply', array( 'suggestion_id' => $pending['id'] ) );
		$this->assertSame( 200, $apply->get_status() );

		$response = $this->dispatch( 'history', array(), 'GET' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['history'] );
		$this->assertSame( 'short_description', $data['history'][0]['task'] );
		$this->assertTrue( $data['history'][0]['undoable'] );
	}

	/**
	 * UUID validation rejects malformed mutation identifiers.
	 *
	 * @return void
	 */
	public function test_apply_rejects_malformed_suggestion_id(): void {
		$this->set_current_user_role( 'administrator' );
		$response = $this->dispatch( 'apply', array( 'suggestion_id' => 'not-a-uuid' ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * Create a pending short-description suggestion.
	 *
	 * @param string $suggestion Generated value.
	 * @return array<string,mixed>
	 */
	private function create_pending( string $suggestion ): array {
		$baseline = $this->content_service->get_snapshot( $this->product_id, 'short_description' );
		$this->assertIsArray( $baseline );

		return $this->store->create_pending(
			$this->product_id,
			'short_description',
			$suggestion,
			array(),
			$baseline
		);
	}

	/**
	 * Dispatch one assistant route through WordPress REST.
	 *
	 * @param string              $action Route action.
	 * @param array<string,mixed> $params Request parameters.
	 * @param string              $method HTTP method.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $action, array $params, string $method = 'POST' ): WP_REST_Response {
		$request = new WP_REST_Request(
			$method,
			'/inline-woo-ai/v1/products/' . $this->product_id . '/' . $action
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Set current user by role.
	 *
	 * @param string $role WordPress role.
	 * @return int
	 */
	private function set_current_user_role( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}
}
