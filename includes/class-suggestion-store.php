<?php
/**
 * Server-side suggestion and audit storage.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores pending AI suggestions and immutable apply audit records in product meta.
 */
final class Inline_Woo_AI_Suggestion_Store {
	private const PENDING_META = '_inline_woo_ai_pending_suggestions';
	private const AUDIT_META   = '_inline_woo_ai_audit_history';
	private const PENDING_TTL  = 86400;
	private const AUDIT_TTL    = 604800;
	private const MAX_PENDING  = 10;
	private const MAX_AUDIT    = 20;

	/**
	 * Store a pending suggestion that may later be applied.
	 *
	 * @param int                 $product_id Product ID.
	 * @param string              $task Generation task.
	 * @param string              $suggestion Generated content.
	 * @param array<string,mixed> $metadata Provider/model metadata when available.
	 * @param array<string,mixed> $baseline Current target-field state before generation.
	 * @return array<string,mixed>
	 */
	public function create_pending( int $product_id, string $task, string $suggestion, array $metadata, array $baseline ): array {
		$now     = time();
		$records = $this->get_pending_records( $product_id );
		$records = $this->remove_expired_pending( $records, $now );

		$record = array(
			'id'               => wp_generate_uuid4(),
			'task'             => sanitize_key( $task ),
			'suggestion'       => $suggestion,
			'provider'         => isset( $metadata['provider'] ) ? sanitize_text_field( (string) $metadata['provider'] ) : '',
			'model'            => isset( $metadata['model'] ) ? sanitize_text_field( (string) $metadata['model'] ) : '',
			'created_by'       => get_current_user_id(),
			'created_at'       => $now,
			'expires_at'       => $now + self::PENDING_TTL,
			'baseline_raw'     => $baseline['raw'] ?? '',
			'baseline_display' => isset( $baseline['display'] ) ? (string) $baseline['display'] : '',
			'field'            => isset( $baseline['field'] ) ? sanitize_key( (string) $baseline['field'] ) : '',
			'target_label'     => isset( $baseline['target_label'] ) ? sanitize_text_field( (string) $baseline['target_label'] ) : '',
		);

		array_unshift( $records, $record );
		$records = array_slice( $records, 0, self::MAX_PENDING );
		update_post_meta( $product_id, self::PENDING_META, $records );

		return $record;
	}

	/**
	 * Return one non-expired pending suggestion.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $suggestion_id Suggestion UUID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_pending( int $product_id, string $suggestion_id ) {
		$now      = time();
		$records  = $this->get_pending_records( $product_id );
		$filtered = $this->remove_expired_pending( $records, $now );

		if ( count( $filtered ) !== count( $records ) ) {
			update_post_meta( $product_id, self::PENDING_META, $filtered );
		}

		foreach ( $filtered as $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], $suggestion_id ) ) {
				return $record;
			}
		}

		return new WP_Error(
			'inline_woo_ai_suggestion_expired',
			__( 'The generated suggestion is invalid or has expired. Generate a new draft.', 'inline-woocommerce-ai-assistant' ),
			array( 'status' => 410 )
		);
	}

	/**
	 * Remove a pending suggestion after a successful apply.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $suggestion_id Suggestion UUID.
	 * @return void
	 */
	public function consume_pending( int $product_id, string $suggestion_id ): void {
		$records = array_values(
			array_filter(
				$this->get_pending_records( $product_id ),
				static function ( array $record ) use ( $suggestion_id ): bool {
					return ! isset( $record['id'] ) || ! hash_equals( (string) $record['id'], $suggestion_id );
				}
			)
		);

		update_post_meta( $product_id, self::PENDING_META, $records );
	}

	/**
	 * Create an apply audit record.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $data Audit data.
	 * @return array<string,mixed>
	 */
	public function record_apply( int $product_id, array $data ): array {
		$now    = time();
		$record = array(
			'id'              => wp_generate_uuid4(),
			'suggestion_id'   => isset( $data['suggestion_id'] ) ? sanitize_text_field( (string) $data['suggestion_id'] ) : '',
			'task'            => isset( $data['task'] ) ? sanitize_key( (string) $data['task'] ) : '',
			'field'           => isset( $data['field'] ) ? sanitize_key( (string) $data['field'] ) : '',
			'target_label'    => isset( $data['target_label'] ) ? sanitize_text_field( (string) $data['target_label'] ) : '',
			'before_raw'      => $data['before_raw'] ?? '',
			'before_display'  => isset( $data['before_display'] ) ? (string) $data['before_display'] : '',
			'after_raw'       => $data['after_raw'] ?? '',
			'after_display'   => isset( $data['after_display'] ) ? (string) $data['after_display'] : '',
			'provider'        => isset( $data['provider'] ) ? sanitize_text_field( (string) $data['provider'] ) : '',
			'model'           => isset( $data['model'] ) ? sanitize_text_field( (string) $data['model'] ) : '',
			'applied_by'      => get_current_user_id(),
			'applied_at'      => $now,
			'undo_expires_at' => $now + self::AUDIT_TTL,
			'undone_at'       => 0,
			'undone_by'       => 0,
		);

		$records = $this->get_audit_records( $product_id );
		array_unshift( $records, $record );
		$records = array_slice( $records, 0, self::MAX_AUDIT );
		update_post_meta( $product_id, self::AUDIT_META, $records );

		return $record;
	}

	/**
	 * Return an audit record that can still be undone.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $audit_id Audit UUID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_undoable_audit( int $product_id, string $audit_id ) {
		foreach ( $this->get_audit_records( $product_id ) as $record ) {
			if ( ! isset( $record['id'] ) || ! hash_equals( (string) $record['id'], $audit_id ) ) {
				continue;
			}

			if ( ! empty( $record['undone_at'] ) ) {
				return new WP_Error(
					'inline_woo_ai_already_undone',
					__( 'This change has already been undone.', 'inline-woocommerce-ai-assistant' ),
					array( 'status' => 409 )
				);
			}

			if ( empty( $record['undo_expires_at'] ) || (int) $record['undo_expires_at'] < time() ) {
				return new WP_Error(
					'inline_woo_ai_audit_expired',
					__( 'The undo window for this change has expired.', 'inline-woocommerce-ai-assistant' ),
					array( 'status' => 410 )
				);
			}

			return $record;
		}

		return new WP_Error(
			'inline_woo_ai_audit_not_found',
			__( 'The requested audit record could not be found.', 'inline-woocommerce-ai-assistant' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Mark an audit record as undone.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $audit_id Audit UUID.
	 * @return void
	 */
	public function mark_undone( int $product_id, string $audit_id ): void {
		$records = $this->get_audit_records( $product_id );

		foreach ( $records as &$record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], $audit_id ) ) {
				$record['undone_at'] = time();
				$record['undone_by'] = get_current_user_id();
				break;
			}
		}
		unset( $record );

		update_post_meta( $product_id, self::AUDIT_META, $records );
	}

	/**
	 * Return audit records newest first.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_history( int $product_id ): array {
		return $this->get_audit_records( $product_id );
	}

	/**
	 * Read pending records.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_records( int $product_id ): array {
		$records = get_post_meta( $product_id, self::PENDING_META, true );
		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : array();
	}

	/**
	 * Read audit records.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_audit_records( int $product_id ): array {
		$records = get_post_meta( $product_id, self::AUDIT_META, true );
		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : array();
	}

	/**
	 * Remove expired pending suggestions.
	 *
	 * @param array<int,array<string,mixed>> $records Pending records.
	 * @param int                            $now Current timestamp.
	 * @return array<int,array<string,mixed>>
	 */
	private function remove_expired_pending( array $records, int $now ): array {
		return array_values(
			array_filter(
				$records,
				static function ( array $record ) use ( $now ): bool {
					return ! empty( $record['expires_at'] ) && (int) $record['expires_at'] >= $now;
				}
			)
		);
	}
}
