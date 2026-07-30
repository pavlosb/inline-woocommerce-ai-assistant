<?php
/**
 * WooCommerce product editor metabox.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the product-editor AI assistant interface.
 */
final class Inline_Woo_AI_Product_Metabox {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the product metabox.
	 *
	 * @return void
	 */
	public function add_metabox(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_meta_box(
			'inline-woo-ai-assistant',
			__( 'INLINE AI Assistant', 'inline-woocommerce-ai-assistant' ),
			array( $this, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the product metabox.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public function render_metabox( WP_Post $post ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		?>
		<div class="inline-woo-ai" data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<p><?php esc_html_e( 'Generate a draft from the current product data. Generation never changes the product.', 'inline-woocommerce-ai-assistant' ); ?></p>

			<label for="inline-woo-ai-task"><strong><?php esc_html_e( 'Content type', 'inline-woocommerce-ai-assistant' ); ?></strong></label>
			<select id="inline-woo-ai-task" class="widefat">
				<option value="short_description"><?php esc_html_e( 'Short description', 'inline-woocommerce-ai-assistant' ); ?></option>
				<option value="full_description"><?php esc_html_e( 'Full description', 'inline-woocommerce-ai-assistant' ); ?></option>
				<option value="benefits"><?php esc_html_e( 'Benefits list', 'inline-woocommerce-ai-assistant' ); ?></option>
				<option value="faqs"><?php esc_html_e( 'Product FAQs', 'inline-woocommerce-ai-assistant' ); ?></option>
				<option value="tags"><?php esc_html_e( 'Product tags', 'inline-woocommerce-ai-assistant' ); ?></option>
			</select>

			<p>
				<button type="button" class="button button-primary inline-woo-ai-generate">
					<?php esc_html_e( 'Generate draft', 'inline-woocommerce-ai-assistant' ); ?>
				</button>
				<span class="spinner"></span>
			</p>

			<div class="inline-woo-ai-result" hidden>
				<p class="inline-woo-ai-target"><strong></strong></p>

				<label for="inline-woo-ai-current"><strong><?php esc_html_e( 'Current value', 'inline-woocommerce-ai-assistant' ); ?></strong></label>
				<textarea id="inline-woo-ai-current" class="widefat" rows="7" readonly></textarea>

				<label for="inline-woo-ai-suggestion"><strong><?php esc_html_e( 'Generated value', 'inline-woocommerce-ai-assistant' ); ?></strong></label>
				<textarea id="inline-woo-ai-suggestion" class="widefat" rows="10" readonly></textarea>

				<p class="inline-woo-ai-actions">
					<button type="button" class="button inline-woo-ai-copy">
						<?php esc_html_e( 'Copy', 'inline-woocommerce-ai-assistant' ); ?>
					</button>
					<button type="button" class="button button-primary inline-woo-ai-apply">
						<?php esc_html_e( 'Apply to product', 'inline-woocommerce-ai-assistant' ); ?>
					</button>
					<button type="button" class="button inline-woo-ai-undo" hidden>
						<?php esc_html_e( 'Undo', 'inline-woocommerce-ai-assistant' ); ?>
					</button>
				</p>
			</div>

			<p class="inline-woo-ai-status" role="status" aria-live="polite"></p>

			<div class="inline-woo-ai-history">
				<hr>
				<p><strong><?php esc_html_e( 'Recent AI changes', 'inline-woocommerce-ai-assistant' ); ?></strong></p>
				<p class="inline-woo-ai-history-empty"><?php esc_html_e( 'No AI changes have been applied to this product.', 'inline-woocommerce-ai-assistant' ); ?></p>
				<ul hidden></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue metabox assets on product edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_enqueue_style(
			'inline-woo-ai-product-metabox',
			INLINE_WOO_AI_URL . 'assets/css/product-metabox.css',
			array(),
			INLINE_WOO_AI_VERSION
		);

		wp_enqueue_script(
			'inline-woo-ai-product-metabox',
			INLINE_WOO_AI_URL . 'assets/js/product-metabox.js',
			array(),
			INLINE_WOO_AI_VERSION,
			true
		);

		wp_localize_script(
			'inline-woo-ai-product-metabox',
			'InlineWooAI',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'restRoot' => esc_url_raw( rest_url( 'inline-woo-ai/v1/products/' ) ),
				'i18n'     => array(
					'generating'   => __( 'Generating draft…', 'inline-woocommerce-ai-assistant' ),
					'generated'    => __( 'Draft generated. Compare the values before applying.', 'inline-woocommerce-ai-assistant' ),
					'applying'     => __( 'Applying suggestion…', 'inline-woocommerce-ai-assistant' ),
					'applied'      => __( 'Suggestion applied. Reload the editor before manually editing this field.', 'inline-woocommerce-ai-assistant' ),
					'undoing'      => __( 'Restoring previous value…', 'inline-woocommerce-ai-assistant' ),
					'undone'       => __( 'Previous value restored.', 'inline-woocommerce-ai-assistant' ),
					'copied'       => __( 'Suggestion copied.', 'inline-woocommerce-ai-assistant' ),
					'copyFailed'   => __( 'Could not copy automatically. Select and copy the text manually.', 'inline-woocommerce-ai-assistant' ),
					'generateError'=> __( 'The draft could not be generated.', 'inline-woocommerce-ai-assistant' ),
					'applyError'   => __( 'The suggestion could not be applied.', 'inline-woocommerce-ai-assistant' ),
					'undoError'    => __( 'The previous value could not be restored.', 'inline-woocommerce-ai-assistant' ),
					'confirmApply' => __( 'Apply this generated value to %s? The current value will be stored for Undo.', 'inline-woocommerce-ai-assistant' ),
					'confirmUndo'  => __( 'Restore the value that existed before this AI change?', 'inline-woocommerce-ai-assistant' ),
					'undo'         => __( 'Undo', 'inline-woocommerce-ai-assistant' ),
					'appliedBy'    => __( 'Applied by %1$s on %2$s', 'inline-woocommerce-ai-assistant' ),
				),
			)
		);
	}
}
