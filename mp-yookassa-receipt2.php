<?php
/**
 * Plugin Name: MP YooKassa Receipt2 (Gift Cards)
 * Description: Sends the second fiscal receipt (settlement receipt) with fixed payment_mode/payment_subject for gift-card scenarios.
 * Version: 0.1.0
 * Author: Metaphysics Parfum (custom)
 */

if (!defined('ABSPATH')) {
	exit;
}

// Main plugin bootstrap class (step 1: hook wiring only).
final class MP_Yookassa_Receipt2_Plugin {
	const VERSION = '0.1.0';

	public static function init(): void {
		// Register trigger for "delivery/fulfillment" stage.
		// (Step 2/next steps will implement actual logic.)
		add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed'], 20, 1);
	}

	/**
	 * Hook handler for order status completed.
	 * For now it only validates prerequisites and logs that the hook fired.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function on_order_completed($order_id): void {
		if (!function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order((int) $order_id);
		if (!$order) {
			return;
		}

		// Avoid duplicate processing (actual sending logic comes later).
		$already_sent = get_post_meta($order_id, 'mp_receipt2_sent', true);
		if ($already_sent === 'yes') {
			return;
		}

		// Temporary debug log until we add a dedicated logger (step 3).
		error_log(sprintf('[mp-yookassa-receipt2] completed hook fired: order_id=%d', (int) $order_id));
	}
}

add_action('plugins_loaded', [MP_Yookassa_Receipt2_Plugin::class, 'init']);

