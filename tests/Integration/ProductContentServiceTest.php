<?php
/**
 * Product content apply and undo integration tests.
 *
 * @package Inline_Woo_AI
 */

/**
 * Exercises the mutation boundary through real WooCommerce CRUD and taxonomies.
 */
final class Inline_Woo_AI_Product_Content_Service_Test extends WP_UnitTestCase {
	/** @var Inline_Woo_AI_Suggestion_Store */
	private $store;

	/** @var Inline_Woo_AI_Product_Content_Service */
	private $service;

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

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$product = new WC_Product_Simple();
		$product->set_name( 'Apply and Undo Product' );
		$product->set_status( 'publish' );
		$product->set_short_description( '<p>Original short description.</p>' );
		$product->set_description( '<p>Original full description.</p>' );
		$this->product_id = $product->save();

		$this->store   = new Inline_Woo_AI_Suggestion_Store();
		$this->service = new Inline_Woo_AI_Product_Content_Service( $this->store );
	}

	/**
	 * Reset current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Applying sanitizes HTML, records metadata, and undo restores exact content.
	 *
	 * @return void
	 */
	public function test_apply_sanitizes_and_undo_restores_short_description(): void {
		$pending = $this->create_pending(
			'short_description',
			'<p onclick="bad()">Safe <strong>bold</strong><script>alert(1)</script><a href="https://example.com">link</a></p>'
		);

		$applied = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertIsArray( $applied );
		$this->assertNotEmpty( $applied['audit_id'] );

		$product = wc_get_product( $this->product_id );
		$value   = $product->get_short_description( 'edit' );
		$this->assertStringContainsString( '<strong>bold</strong>', $value );
		$this->assertStringNotContainsString( '<script', $value );
		$this->assertStringNotContainsString( 'onclick', $value );
		$this->assertStringNotContainsString( '<a ', $value );

		$history = $this->service->get_history( $this->product_id );
		$this->assertCount( 1, $history );
		$this->assertSame( 'test-provider', $history[0]['provider'] );
		$this->assertSame( 'test-model', $history[0]['model'] );
		$this->assertTrue( $history[0]['undoable'] );

		$undone = $this->service->undo( $this->product_id, $applied['audit_id'] );
		$this->assertIsArray( $undone );
		$this->assertSame( '<p>Original short description.</p>', $undone['restored_value'] );

		$product = wc_get_product( $this->product_id );
		$this->assertSame( '<p>Original short description.</p>', $product->get_short_description( 'edit' ) );
	}

	/**
	 * A product edit after generation invalidates the pending suggestion.
	 *
	 * @return void
	 */
	public function test_apply_rejects_stale_suggestion(): void {
		$pending = $this->create_pending( 'short_description', '<p>Generated value.</p>' );

		$product = wc_get_product( $this->product_id );
		$product->set_short_description( '<p>Manually changed after generation.</p>' );
		$product->save();

		$result = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_stale_suggestion', $result->get_error_code() );
		$this->assertSame( '<p>Manually changed after generation.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * Undo does not overwrite a later manual edit.
	 *
	 * @return void
	 */
	public function test_undo_rejects_manual_edit_conflict(): void {
		$pending = $this->create_pending( 'short_description', '<p>Generated value.</p>' );
		$applied = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertIsArray( $applied );

		$product = wc_get_product( $this->product_id );
		$product->set_short_description( '<p>Manual edit after apply.</p>' );
		$product->save();

		$result = $this->service->undo( $this->product_id, $applied['audit_id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_undo_conflict', $result->get_error_code() );
		$this->assertSame( '<p>Manual edit after apply.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * Generated tags are normalized and undo restores the original term set.
	 *
	 * @return void
	 */
	public function test_tags_apply_and_undo_restore_original_terms(): void {
		wp_set_object_terms( $this->product_id, array( 'Original Tag' ), 'product_tag', false );
		$pending = $this->create_pending( 'tags', '#New Tag, Another Tag, New Tag, ;' );

		$applied = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertIsArray( $applied );

		$names = wp_get_object_terms( $this->product_id, 'product_tag', array( 'fields' => 'names' ) );
		sort( $names, SORT_STRING );
		$this->assertSame( array( 'Another Tag', 'New Tag' ), $names );

		$undone = $this->service->undo( $this->product_id, $applied['audit_id'] );
		$this->assertIsArray( $undone );
		$this->assertSame(
			array( 'Original Tag' ),
			wp_get_object_terms( $this->product_id, 'product_tag', array( 'fields' => 'names' ) )
		);
	}

	/**
	 * Expired pending suggestions fail without changing the product.
	 *
	 * @return void
	 */
	public function test_expired_pending_suggestion_fails_safely(): void {
		$pending = $this->create_pending( 'short_description', '<p>Expired generated value.</p>' );
		$records = get_post_meta( $this->product_id, '_inline_woo_ai_pending_suggestions', true );
		$records[0]['expires_at'] = time() - 1;
		update_post_meta( $this->product_id, '_inline_woo_ai_pending_suggestions', $records );

		$result = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_suggestion_expired', $result->get_error_code() );
		$this->assertSame( '<p>Original short description.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * Expired audit records cannot be undone.
	 *
	 * @return void
	 */
	public function test_expired_audit_record_fails_safely(): void {
		$pending = $this->create_pending( 'short_description', '<p>Generated value.</p>' );
		$applied = $this->service->apply( $this->product_id, $pending['id'] );
		$this->assertIsArray( $applied );

		$records = get_post_meta( $this->product_id, '_inline_woo_ai_audit_history', true );
		$records[0]['undo_expires_at'] = time() - 1;
		update_post_meta( $this->product_id, '_inline_woo_ai_audit_history', $records );

		$result = $this->service->undo( $this->product_id, $applied['audit_id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'inline_woo_ai_audit_expired', $result->get_error_code() );
		$this->assertSame( '<p>Generated value.</p>', wc_get_product( $this->product_id )->get_short_description( 'edit' ) );
	}

	/**
	 * Create a pending suggestion from the current field snapshot.
	 *
	 * @param string $task Task key.
	 * @param string $suggestion Generated content.
	 * @return array<string,mixed>
	 */
	private function create_pending( string $task, string $suggestion ): array {
		$baseline = $this->service->get_snapshot( $this->product_id, $task );
		$this->assertIsArray( $baseline );

		return $this->store->create_pending(
			$this->product_id,
			$task,
			$suggestion,
			array(
				'provider' => 'test-provider',
				'model'    => 'test-model',
			),
			$baseline
		);
	}
}
