<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package Inline_Woo_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
		'_inline_woo_ai_pending_suggestions',
		'_inline_woo_ai_audit_history'
	)
);
