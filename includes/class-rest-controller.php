<?php
/**
 * Feature-specific REST API controller.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the product generation endpoint.
 */
final class Inline_Woo_AI_REST_Controller {
	/**
	 * AI service.
	 *
	 * @var Inline_Woo_AI_Service
	 */
	private $ai_service;

	/**
	 * Constructor.
	 *
	 * @param Inline_Woo_AI_Service $ai_service AI service.
	 */
	public function __construct( Inline_Woo_AI_Service $ai_service ) {
		$this->ai_service = $ai_service;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'inline-woo-ai/v1',
			'/products/(?P<id>\d+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'task' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array_keys( $this->ai_service->get_tasks() ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Check endpoint permissions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );

		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error(
				'inline_woo_ai_forbidden',
				__( 'You are not allowed to generate AI content for this product.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error(
				'inline_woo_ai_invalid_product',
				__( 'The requested object is not a WooCommerce product.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Generate a product suggestion.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );
		$task       = sanitize_key( (string) $request->get_param( 'task' ) );
		$suggestion = $this->ai_service->generate( $product_id, $task );

		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		return rest_ensure_response(
			array(
				'product_id' => $product_id,
				'task'       => $task,
				'suggestion' => $suggestion,
			)
		);
	}
}
