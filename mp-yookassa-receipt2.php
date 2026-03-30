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
		// Step 2: settings loader.
		if (!class_exists('MP_Yookassa_Receipt2_Settings')) {
			require_once __DIR__ . '/mp-yookassa-receipt2-settings.php';
		}

		// Step 3: logger loader.
		if (!class_exists('MP_Yookassa_Receipt2_Logger')) {
			require_once __DIR__ . '/mp-yookassa-receipt2-logger.php';
		}

		// Step 4: API client loader.
		if (!class_exists('MP_Yookassa_Receipt2_ApiClient')) {
			require_once __DIR__ . '/mp-yookassa-receipt2-api-client.php';
		}

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

		if (class_exists('MP_Yookassa_Receipt2_Settings')) {
			$errors = MP_Yookassa_Receipt2_Settings::validate_for_api();
			if (!empty($errors)) {
				// Skip if plugin is disabled or misconfigured.
				if (class_exists('MP_Yookassa_Receipt2_Logger')) {
					MP_Yookassa_Receipt2_Logger::log('ERROR', (int) $order_id, 'settings_invalid', [
						'errors' => $errors,
					]);
				}
				return;
			}
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

		// Temporary debug log until we add actual sending logic.
		if (class_exists('MP_Yookassa_Receipt2_Logger')) {
			MP_Yookassa_Receipt2_Logger::log('DEBUG', (int) $order_id, 'woocommerce_order_status_completed_fired', []);
		}
	}
}

add_action('plugins_loaded', [MP_Yookassa_Receipt2_Plugin::class, 'init']);

