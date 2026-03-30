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

		// Step 5: order links resolver (gift-card settlement + source payment id).
		if (!class_exists('MP_Yookassa_Receipt2_OrderLinks')) {
			require_once __DIR__ . '/mp-yookassa-receipt2-order-links.php';
		}

		// Step 6: receipt builder.
		if (!class_exists('MP_Yookassa_Receipt2_ReceiptBuilder')) {
			require_once __DIR__ . '/mp-yookassa-receipt2-receipt-builder.php';
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

		$resolved = MP_Yookassa_Receipt2_OrderLinks::resolve_for_order($order);
		if (empty($resolved['is_gift_card_settlement'])) {
			MP_Yookassa_Receipt2_Logger::log('DEBUG', (int) $order_id, 'skip_not_gift_card_settlement', [
				'reason' => $resolved['reason'],
			]);
			return;
		}

		if (empty($resolved['source_payment_id'])) {
			MP_Yookassa_Receipt2_Logger::log('ERROR', (int) $order_id, 'skip_missing_source_payment_id', [
				'reason' => $resolved['reason'],
				'settlement_amount' => $resolved['settlement_amount'],
			]);
			return;
		}

		$receipt_data = MP_Yookassa_Receipt2_ReceiptBuilder::build($order, (float) $resolved['settlement_amount']);
		$items_count = is_array($receipt_data['items']) ? count($receipt_data['items']) : 0;
		if ($items_count < 1) {
			MP_Yookassa_Receipt2_Logger::log('ERROR', (int) $order_id, 'skip_empty_receipt_items', [
				'settlement_amount' => $resolved['settlement_amount'],
				'source_payment_id' => $resolved['source_payment_id'],
				'warnings' => $receipt_data['warnings'],
			]);
			update_post_meta((int) $order_id, 'mp_receipt2_error', 'Empty receipt items');
			return;
		}

		$api_result = MP_Yookassa_Receipt2_ApiClient::send_receipt(
			(string) $resolved['source_payment_id'],
			[
				'items' => $receipt_data['items'],
				'settlements' => $receipt_data['settlements'],
			]
		);

		if (!empty($api_result['ok'])) {
			update_post_meta((int) $order_id, 'mp_receipt2_sent', 'yes');
			update_post_meta((int) $order_id, 'mp_receipt2_id', (string) $api_result['receipt_id']);
			update_post_meta((int) $order_id, 'mp_receipt2_idempotence_key', (string) $api_result['idempotence_key']);
			delete_post_meta((int) $order_id, 'mp_receipt2_error');

			MP_Yookassa_Receipt2_Logger::log('INFO', (int) $order_id, 'receipt2_sent_success', [
				'source_payment_id' => $resolved['source_payment_id'],
				'receipt_id' => $api_result['receipt_id'],
				'status_code' => $api_result['status_code'],
				'idempotence_key' => $api_result['idempotence_key'],
				'items_count' => $items_count,
				'total_items_amount' => $receipt_data['total_items_amount'],
				'settlement_amount' => $resolved['settlement_amount'],
				'warnings' => $receipt_data['warnings'],
			]);
			return;
		}

		$error_message = isset($api_result['error']) && is_string($api_result['error']) && $api_result['error'] !== ''
			? $api_result['error']
			: 'Unknown send_receipt error';

		update_post_meta((int) $order_id, 'mp_receipt2_error', $error_message);
		update_post_meta((int) $order_id, 'mp_receipt2_idempotence_key', (string) $api_result['idempotence_key']);

		MP_Yookassa_Receipt2_Logger::log('ERROR', (int) $order_id, 'receipt2_sent_failed', [
			'source_payment_id' => $resolved['source_payment_id'],
			'status_code' => $api_result['status_code'],
			'idempotence_key' => $api_result['idempotence_key'],
			'error' => $error_message,
			'response_body' => $api_result['response_body'],
			'items_count' => $items_count,
			'total_items_amount' => $receipt_data['total_items_amount'],
			'settlement_amount' => $resolved['settlement_amount'],
			'warnings' => $receipt_data['warnings'],
		]);

		// Keep mp_receipt2_sent unset on failure to allow manual re-trigger.
	}
}

add_action('plugins_loaded', [MP_Yookassa_Receipt2_Plugin::class, 'init']);

