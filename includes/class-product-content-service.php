<?php
/**
 * Product content mutation and undo service.
 *
 * @package Inline_Woo_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies generated suggestions through WooCommerce CRUD and WordPress taxonomy APIs.
 */
final class Inline_Woo_AI_Product_Content_Service {
	/**
	 * Suggestion store.
	 *
	 * @var Inline_Woo_AI_Suggestion_Store
	 */
	private $store;

	/**
	 * Task-to-field mapping.
	 *
	 * @var array<string,string>
	 */
	private const TASK_FIELDS = array(
		'short_description' => 'short_description',
		'full_description'  => 'description',
		'benefits'          => 'description',
		'faqs'              => 'description',
		'tags'              => 'tags',
	);

	/**
	 * Constructor.
	 *
	 * @param Inline_Woo_AI_Suggestion_Store $store Suggestion and audit store.
	 */
	public function __construct( Inline_Woo_AI_Suggestion_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Return the current target-field state for a generation task.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $task Generation task.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_snapshot( int $product_id, string $task ) {
		$field = $this->get_field_for_task( $task );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'inline_woo_ai_product_not_found',
				__( 'The WooCommerce product could not be found.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 404 )
			);
		}

		$state                 = $this->read_state( $product, $field );
		$state['field']        = $field;
		$state['target_label'] = $this->get_target_label( $task );

		return $state;
	}

	/**
	 * Apply one stored suggestion and create an audit record.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $suggestion_id Pending suggestion UUID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply( int $product_id, string $suggestion_id ) {
		$pending = $this->store->get_pending( $product_id, $suggestion_id );
		if ( is_wp_error( $pending ) ) {
			return $pending;
		}

		$task  = isset( $pending['task'] ) ? sanitize_key( (string) $pending['task'] ) : '';
		$field = $this->get_field_for_task( $task );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'inline_woo_ai_product_not_found',
				__( 'The WooCommerce product could not be found.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 404 )
			);
		}

		$current = $this->read_state( $product, $field );
		if ( ! $this->states_match( $current['raw'], $pending['baseline_raw'] ?? '' ) ) {
			return new WP_Error(
				'inline_woo_ai_stale_suggestion',
				__( 'The product field changed after this draft was generated. Generate a new draft before applying.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 409 )
			);
		}

		$sanitized = $this->sanitize_suggestion( $task, isset( $pending['suggestion'] ) ? (string) $pending['suggestion'] : '' );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		if ( $this->states_match( $current['raw'], $sanitized['raw'] ) ) {
			return new WP_Error(
				'inline_woo_ai_no_change',
				__( 'The generated suggestion is identical to the current product value.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 409 )
			);
		}

		$write_result = $this->write_state( $product, $field, $sanitized['raw'] );
		if ( is_wp_error( $write_result ) ) {
			return $write_result;
		}

		$after = $this->read_state( $product, $field );
		$audit = $this->store->record_apply(
			$product_id,
			array(
				'suggestion_id'  => $suggestion_id,
				'task'           => $task,
				'field'          => $field,
				'target_label'   => $this->get_target_label( $task ),
				'before_raw'     => $current['raw'],
				'before_display' => $current['display'],
				'after_raw'      => $after['raw'],
				'after_display'  => $after['display'],
				'provider'       => $pending['provider'] ?? '',
				'model'          => $pending['model'] ?? '',
			)
		);

		$this->store->consume_pending( $product_id, $suggestion_id );

		return array(
			'audit_id'        => $audit['id'],
			'task'            => $task,
			'field'           => $field,
			'target_label'    => $audit['target_label'],
			'before_value'    => $current['display'],
			'applied_value'   => $after['display'],
			'undo_expires_at' => $audit['undo_expires_at'],
		);
	}

	/**
	 * Undo one audited apply operation.
	 *
	 * Undo is refused if the current field no longer matches the value originally
	 * applied by the assistant, preventing later manual edits from being overwritten.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $audit_id Audit UUID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function undo( int $product_id, string $audit_id ) {
		$audit = $this->store->get_undoable_audit( $product_id, $audit_id );
		if ( is_wp_error( $audit ) ) {
			return $audit;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'inline_woo_ai_product_not_found',
				__( 'The WooCommerce product could not be found.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 404 )
			);
		}

		$field   = isset( $audit['field'] ) ? sanitize_key( (string) $audit['field'] ) : '';
		$current = $this->read_state( $product, $field );

		if ( ! $this->states_match( $current['raw'], $audit['after_raw'] ?? '' ) ) {
			return new WP_Error(
				'inline_woo_ai_undo_conflict',
				__( 'The product field has changed since this AI suggestion was applied. Undo was not performed.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 409 )
			);
		}

		$write_result = $this->write_state( $product, $field, $audit['before_raw'] ?? '' );
		if ( is_wp_error( $write_result ) ) {
			return $write_result;
		}

		$this->store->mark_undone( $product_id, $audit_id );
		$restored = $this->read_state( $product, $field );

		return array(
			'audit_id'      => $audit_id,
			'task'          => isset( $audit['task'] ) ? (string) $audit['task'] : '',
			'target_label'  => isset( $audit['target_label'] ) ? (string) $audit['target_label'] : '',
			'restored_value' => $restored['display'],
		);
	}

	/**
	 * Return recent audit history with safe undo status.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit Maximum records.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function get_history( int $product_id, int $limit = 10 ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'inline_woo_ai_product_not_found',
				__( 'The WooCommerce product could not be found.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 404 )
			);
		}

		$history = array();
		foreach ( array_slice( $this->store->get_history( $product_id ), 0, max( 1, $limit ) ) as $record ) {
			$field       = isset( $record['field'] ) ? sanitize_key( (string) $record['field'] ) : '';
			$current     = $this->read_state( $product, $field );
			$is_expired  = empty( $record['undo_expires_at'] ) || (int) $record['undo_expires_at'] < time();
			$is_undone   = ! empty( $record['undone_at'] );
			$is_unchanged = $this->states_match( $current['raw'], $record['after_raw'] ?? '' );
			$user        = ! empty( $record['applied_by'] ) ? get_userdata( (int) $record['applied_by'] ) : false;

			$history[] = array(
				'audit_id'       => isset( $record['id'] ) ? (string) $record['id'] : '',
				'task'           => isset( $record['task'] ) ? (string) $record['task'] : '',
				'target_label'   => isset( $record['target_label'] ) ? (string) $record['target_label'] : '',
				'before_preview' => $this->make_preview( isset( $record['before_display'] ) ? (string) $record['before_display'] : '' ),
				'after_preview'  => $this->make_preview( isset( $record['after_display'] ) ? (string) $record['after_display'] : '' ),
				'provider'       => isset( $record['provider'] ) ? (string) $record['provider'] : '',
				'model'          => isset( $record['model'] ) ? (string) $record['model'] : '',
				'applied_by'     => $user ? $user->display_name : '',
				'applied_at'     => isset( $record['applied_at'] ) ? (int) $record['applied_at'] : 0,
				'undone_at'      => isset( $record['undone_at'] ) ? (int) $record['undone_at'] : 0,
				'undoable'       => ! $is_expired && ! $is_undone && $is_unchanged,
			);
		}

		return $history;
	}

	/**
	 * Return mapped product field for a task.
	 *
	 * @param string $task Task key.
	 * @return string|WP_Error
	 */
	private function get_field_for_task( string $task ) {
		if ( ! isset( self::TASK_FIELDS[ $task ] ) ) {
			return new WP_Error(
				'inline_woo_ai_invalid_task',
				__( 'The requested AI generation task is not supported.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		return self::TASK_FIELDS[ $task ];
	}

	/**
	 * Human-readable target label.
	 *
	 * @param string $task Task key.
	 * @return string
	 */
	private function get_target_label( string $task ): string {
		$labels = array(
			'short_description' => __( 'Short description', 'inline-woocommerce-ai-assistant' ),
			'full_description'  => __( 'Full description', 'inline-woocommerce-ai-assistant' ),
			'benefits'          => __( 'Full description (replaced by benefits list)', 'inline-woocommerce-ai-assistant' ),
			'faqs'              => __( 'Full description (replaced by FAQs)', 'inline-woocommerce-ai-assistant' ),
			'tags'              => __( 'Product tags', 'inline-woocommerce-ai-assistant' ),
		);

		return isset( $labels[ $task ] ) ? $labels[ $task ] : __( 'Product field', 'inline-woocommerce-ai-assistant' );
	}

	/**
	 * Read a product field as a raw value plus display text.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $field Field key.
	 * @return array{raw:mixed,display:string}
	 */
	private function read_state( WC_Product $product, string $field ): array {
		if ( 'tags' === $field ) {
			$ids   = wp_get_object_terms( $product->get_id(), 'product_tag', array( 'fields' => 'ids' ) );
			$names = wp_get_object_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

			if ( is_wp_error( $ids ) ) {
				$ids = array();
			}
			if ( is_wp_error( $names ) ) {
				$names = array();
			}

			$ids = array_values( array_map( 'intval', $ids ) );
			sort( $ids, SORT_NUMERIC );

			return array(
				'raw'     => $ids,
				'display' => implode( ', ', array_map( 'strval', $names ) ),
			);
		}

		if ( 'short_description' === $field ) {
			$value = $product->get_short_description( 'edit' );
		} else {
			$value = $product->get_description( 'edit' );
		}

		return array(
			'raw'     => (string) $value,
			'display' => (string) $value,
		);
	}

	/**
	 * Write a product field.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $field Field key.
	 * @param mixed      $raw Raw value.
	 * @return true|WP_Error
	 */
	private function write_state( WC_Product $product, string $field, $raw ) {
		if ( 'tags' === $field ) {
			$term_ids = is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
			$result   = wp_set_object_terms( $product->get_id(), $term_ids, 'product_tag', false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$product->save();
			wc_delete_product_transients( $product->get_id() );
			return true;
		}

		if ( 'short_description' === $field ) {
			$product->set_short_description( (string) $raw );
		} elseif ( 'description' === $field ) {
			$product->set_description( (string) $raw );
		} else {
			return new WP_Error(
				'inline_woo_ai_invalid_field',
				__( 'The target product field is not supported.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		$product->save();
		wc_delete_product_transients( $product->get_id() );
		return true;
	}

	/**
	 * Sanitize generated content for its specific task.
	 *
	 * @param string $task Task key.
	 * @param string $suggestion Generated content.
	 * @return array{raw:mixed,display:string}|WP_Error
	 */
	private function sanitize_suggestion( string $task, string $suggestion ) {
		if ( 'tags' === $task ) {
			$names = $this->parse_tags( $suggestion );
			if ( empty( $names ) ) {
				return new WP_Error(
					'inline_woo_ai_empty_suggestion',
					__( 'The generated tag suggestion did not contain any valid tags.', 'inline-woocommerce-ai-assistant' ),
					array( 'status' => 400 )
				);
			}

			$result = wp_set_object_terms( 0, array(), 'product_tag', false );
			unset( $result );

			$term_ids = array();
			foreach ( $names as $name ) {
				$term = term_exists( $name, 'product_tag' );
				if ( ! $term ) {
					$term = wp_insert_term( $name, 'product_tag' );
				}
				if ( is_wp_error( $term ) ) {
					return $term;
				}
				$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
			sort( $term_ids, SORT_NUMERIC );

			return array(
				'raw'     => array_values( array_unique( $term_ids ) ),
				'display' => implode( ', ', $names ),
			);
		}

		$allowed = $this->get_allowed_html( $task );
		$html    = trim( wp_kses( $suggestion, $allowed ) );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error(
				'inline_woo_ai_empty_suggestion',
				__( 'The generated suggestion was empty after sanitization.', 'inline-woocommerce-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'raw'     => $html,
			'display' => $html,
		);
	}

	/**
	 * Task-specific HTML allowlist.
	 *
	 * @param string $task Task key.
	 * @return array<string,array<string,bool>>
	 */
	private function get_allowed_html( string $task ): array {
		$inline = array(
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);

		if ( 'short_description' === $task ) {
			return array_merge( $inline, array( 'p' => array() ) );
		}
		if ( 'benefits' === $task ) {
			return array_merge( $inline, array( 'ul' => array(), 'li' => array() ) );
		}
		if ( 'faqs' === $task ) {
			return array_merge( $inline, array( 'h3' => array(), 'p' => array() ) );
		}

		return array_merge(
			$inline,
			array(
				'p'  => array(),
				'h2' => array(),
				'h3' => array(),
				'ul' => array(),
				'ol' => array(),
				'li' => array(),
			)
		);
	}

	/**
	 * Parse up to ten comma/newline-separated tags.
	 *
	 * @param string $suggestion Generated text.
	 * @return array<int,string>
	 */
	private function parse_tags( string $suggestion ): array {
		$parts  = preg_split( '/[\r\n,;]+/u', $suggestion ) ?: array();
		$tags   = array();
		$lookup = array();

		foreach ( $parts as $part ) {
			$tag = trim( sanitize_text_field( wp_strip_all_tags( ltrim( trim( (string) $part ), '#' ) ) ) );
			if ( '' === $tag ) {
				continue;
			}

			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );
			if ( isset( $lookup[ $key ] ) ) {
				continue;
			}

			$lookup[ $key ] = true;
			$tags[]         = $tag;
			if ( 10 === count( $tags ) ) {
				break;
			}
		}

		return $tags;
	}

	/**
	 * Compare scalar or taxonomy-array states.
	 *
	 * @param mixed $left First state.
	 * @param mixed $right Second state.
	 * @return bool
	 */
	private function states_match( $left, $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			$left  = is_array( $left ) ? array_values( array_map( 'intval', $left ) ) : array();
			$right = is_array( $right ) ? array_values( array_map( 'intval', $right ) ) : array();
			sort( $left, SORT_NUMERIC );
			sort( $right, SORT_NUMERIC );
			return $left === $right;
		}

		return (string) $left === (string) $right;
	}

	/**
	 * Create a compact plain-text history preview.
	 *
	 * @param string $value Stored display value.
	 * @return string
	 */
	private function make_preview( string $value ): string {
		return wp_html_excerpt( trim( wp_strip_all_tags( $value ) ), 180, '…' );
	}
}
