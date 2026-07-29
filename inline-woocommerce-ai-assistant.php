<?php
/**
 * Plugin Name: INLINE WooCommerce AI Assistant
 * Description: Generates reviewable WooCommerce product-content suggestions through the native WordPress AI Client.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: INLINE TECHNOLOGY CONSULTANTS
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: inline-woocommerce-ai-assistant
 */

defined( 'ABSPATH' ) || exit;

define( 'INLINE_WOO_AI_VERSION', '0.1.0' );
define( 'INLINE_WOO_AI_FILE', __FILE__ );
define( 'INLINE_WOO_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'INLINE_WOO_AI_URL', plugin_dir_url( __FILE__ ) );

require_once INLINE_WOO_AI_PATH . 'includes/class-ai-service.php';
require_once INLINE_WOO_AI_PATH . 'includes/class-rest-controller.php';
require_once INLINE_WOO_AI_PATH . 'includes/class-product-metabox.php';
require_once INLINE_WOO_AI_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new Inline_Woo_AI_Plugin();
		$plugin->boot();
	},
	20
);
