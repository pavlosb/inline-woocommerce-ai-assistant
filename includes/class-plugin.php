<?php
/**
 * Main plugin coordinator.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin services and compatibility checks.
 */
final class Inline_Woo_AI_Plugin {
	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_ai_client_notice' ) );
			return;
		}

		$ai_service      = new Inline_Woo_AI_Service();
		$suggestion_store = new Inline_Woo_AI_Suggestion_Store();
		$content_service  = new Inline_Woo_AI_Product_Content_Service( $suggestion_store );
		$rest_controller  = new Inline_Woo_AI_REST_Controller( $ai_service, $suggestion_store, $content_service );
		$product_metabox  = new Inline_Woo_AI_Product_Metabox();

		$rest_controller->register_hooks();
		$product_metabox->register_hooks();

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			add_action( 'admin_notices', array( $this, 'render_ai_disabled_notice' ) );
		}
	}

	/**
	 * Render a WooCommerce dependency notice.
	 *
	 * @return void
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'INLINE WooCommerce AI Assistant requires WooCommerce to be active.', 'inline-woocommerce-ai-assistant' )
		);
	}

	/**
	 * Render a WordPress AI Client dependency notice.
	 *
	 * @return void
	 */
	public function render_missing_ai_client_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'INLINE WooCommerce AI Assistant requires WordPress 7.0 or newer with the native AI Client.', 'inline-woocommerce-ai-assistant' )
		);
	}

	/**
	 * Render a notice when AI support is disabled for the environment.
	 *
	 * @return void
	 */
	public function render_ai_disabled_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'AI support is disabled in this WordPress environment. Product suggestions cannot be generated.', 'inline-woocommerce-ai-assistant' )
		);
	}
}
