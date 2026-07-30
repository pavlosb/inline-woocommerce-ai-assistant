<?php
/**
 * Feature-specific REST API controller.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers product generation, apply, history, and undo endpoints.
 */
final class Inline_Woo_AI_REST_Controller {
	/** @var Inline_Woo_AI_Service */
	private $ai_service;

	/** @var Inline_Woo_AI_Suggestion_Store */
	private $suggestion_store;

	/** @var Inline_Woo_AI_Product_Content_Service */
	private $content_service;

	/**
	 * Constructor.
	 *
	 * Optional dependencies preserve the simple constructor used by older tests
	 * while production passes shared service instances explicitly.
	 *
	 * @param Inline_Woo_AI_Service                      $ai_service AI service.
	 * @param Inline_Woo_AI_Suggestion_Store|null        $suggestion_store Suggestion store.
	 * @param Inline_Woo_AI_Product_Content_Service|null $content_service Product content service.
	 */
	public function __construct(
		Inline_Woo_AI_Service $ai_service,
		?Inline_Woo_AI_Suggestion_Store $suggestion_store = null,
		?Inline_Woo_AI_Product_Content_Service $content_service = null
	) {
		$this->ai_service       = $ai_service;
		$this->suggestion_store = $suggestion_store ?: new Inline_Woo_AI_Suggestion_Store();
		$this->content_service  = $content_service ?: new Inline_Woo_AI_Product_Content_Service( $this->suggestion_store );
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
					'id'   => $this->get_id_argument(),
					'task' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array_keys( $this->ai_service->get_tasks() ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'inline-woo-ai/v1',
			'/products/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'            => $this->get_id_argument(),
					'suggestion_id' => $this->get_uuid_argument(),
				),
			)
		);

		register_rest_route(
			'inline-woo-ai/v1',
			'/products/(?P<id>\d+)/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'       => $this->get_id_argument(),
					'audit_id' => $this->get_uuid_argument(),
				),
			)
		);

		register_rest_route(
			'inline-woo-ai/v1',
			'/products/(?P<id>\d+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => $this->get_id_argument(),
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
				__( 'You are not allowed to use the AI assistant for this product.', 'inline-woocommerce-ai-assistant' ),
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
	 * Generate and store a pending product suggestion.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );
		$task       = sanitize_key( (string) $request->get_param( 'task' ) );
		$baseline   = $this->content_service->get_snapshot( $product_id, $task );

		if ( is_wp_error( $baseline ) ) {
			return $baseline;
		}

		$suggestion = $this->ai_service->generate( $product_id, $task );
		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		/**
		 * Filter provider/model metadata attached to the pending suggestion.
		 *
		 * The native AI Client does not guarantee these values for every provider,
		 * so the default is empty and connector integrations may supply them.
		 *
		 * @param array<string,string> $metadata Product suggestion metadata.
		 * @param int                  $product_id Product ID.
		 * @param string               $task Generation task.
		 */
		$metadata = apply_filters( 'inline_woo_ai_generation_metadata', array(), $product_id, $task );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$pending = $this->suggestion_store->create_pending(
			$product_id,
			$task,
			$suggestion,
			$metadata,
			$baseline
		);

		return rest_ensure_response(
			array(
				'product_id'    => $product_id,
				'task'          => $task,
				'suggestion_id' => $pending['id'],
				'suggestion'    => $suggestion,
				'current_value' => $baseline['display'],
				'target_label'  => $baseline['target_label'],
				'expires_at'    => $pending['expires_at'],
			)
		);
	}

	/**
	 * Apply one stored suggestion.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$result = $this->content_service->apply(
			absint( $request['id'] ),
			sanitize_text_field( (string) $request->get_param( 'suggestion_id' ) )
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Undo one audited apply operation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$result = $this->content_service->undo(
			absint( $request['id'] ),
			sanitize_text_field( (string) $request->get_param( 'audit_id' ) )
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Return recent apply/undo history.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function history( WP_REST_Request $request ) {
		$result = $this->content_service->get_history( absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'history' => $result ) );
	}

	/**
	 * Product ID argument schema.
	 *
	 * @return array<string,mixed>
	 */
	private function get_id_argument(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * UUID argument schema.
	 *
	 * @return array<string,mixed>
	 */
	private function get_uuid_argument(): array {
		return array(
			'required'          => true,
			'type'              => 'string',
			'format'            => 'uuid',
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}
}
