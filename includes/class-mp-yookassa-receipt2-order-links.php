<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Step 5: resolve gift-card settlement context and source YooKassa payment_id.
 *
 * Important:
 * - We cannot reliably infer "source payment_id of gift-card purchase" from every setup.
 * - This class provides strict extension points (filters + optional order meta keys).
 */
final class MP_Yookassa_Receipt2_OrderLinks {
	/**
	 * Optional order meta with source payment id of original gift-card purchase.
	 * This must be filled by your gift-card flow when card is issued/applied.
	 */
	private const META_SOURCE_PAYMENT_ID = 'mp_receipt2_source_payment_id';

	/**
	 * Optional order meta with explicit settlement amount covered by gift card.
	 */
	private const META_SETTLEMENT_AMOUNT = 'mp_receipt2_settlement_amount';

	/**
	 * @param WC_Order $order
	 * @return array{
	 *   is_gift_card_settlement:bool,
	 *   settlement_amount:float,
	 *   source_payment_id:string,
	 *   reason:string
	 * }
	 */
	public static function resolve_for_order(WC_Order $order): array {
		$order_id = (int) $order->get_id();

		$result = [
			'is_gift_card_settlement' => false,
			'settlement_amount' => 0.0,
			'source_payment_id' => '',
			'reason' => '',
		];

		/**
		 * Hard override for custom projects.
		 * Return array:
		 * [
		 *   'is_gift_card_settlement' => true,
		 *   'settlement_amount' => 123.45,
		 *   'source_payment_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
		 * ]
		 */
		$override = apply_filters('mp_receipt2_order_links', null, $order);
		if (is_array($override)) {
			$result['is_gift_card_settlement'] = !empty($override['is_gift_card_settlement']);
			$result['settlement_amount'] = isset($override['settlement_amount']) ? max(0.0, (float) $override['settlement_amount']) : 0.0;
			$result['source_payment_id'] = isset($override['source_payment_id']) ? trim((string) $override['source_payment_id']) : '';
			$result['reason'] = 'resolved_by_filter';
			return $result;
		}

		// 1) Settlement amount + card numbers from PW Gift Card order items (preferred).
		$pw_gc = self::detect_from_pw_gift_card_items($order);
		if ($pw_gc['settlement_amount'] > 0) {
			$result['settlement_amount'] = $pw_gc['settlement_amount'];
			$result['is_gift_card_settlement'] = true;
			$result['reason'] = 'detected_from_pw_gift_card_items';
		}

		// 2) Settlement amount from explicit order meta.
		$settlement_amount_meta = (float) get_post_meta($order_id, self::META_SETTLEMENT_AMOUNT, true);
		if ($settlement_amount_meta > 0 && !$result['is_gift_card_settlement']) {
			$result['settlement_amount'] = (float) wc_format_decimal($settlement_amount_meta, wc_get_price_decimals());
			$result['is_gift_card_settlement'] = true;
			$result['reason'] = 'detected_from_meta';
		}

		// 3) Fallback heuristic: detect gift-card discount from negative fee lines.
		if (!$result['is_gift_card_settlement']) {
			$fee_based_amount = self::detect_settlement_amount_from_fees($order);
			if ($fee_based_amount > 0) {
				$result['settlement_amount'] = $fee_based_amount;
				$result['is_gift_card_settlement'] = true;
				$result['reason'] = 'detected_from_fees';
			}
		}

		// 3) Resolve source payment id.
		$source_payment_id = trim((string) get_post_meta($order_id, self::META_SOURCE_PAYMENT_ID, true));
		if ($source_payment_id !== '') {
			$result['source_payment_id'] = $source_payment_id;
		}

		// 3.1) Try resolving source payment by used card numbers via WGPC table.
		if ($result['source_payment_id'] === '' && !empty($pw_gc['card_numbers'])) {
			$resolved_by_cards = self::resolve_payment_id_by_card_numbers($pw_gc['card_numbers']);
			if ($resolved_by_cards['payment_id'] !== '') {
				$result['source_payment_id'] = $resolved_by_cards['payment_id'];
				$result['reason'] = $resolved_by_cards['reason'];
			} elseif ($resolved_by_cards['reason'] !== '') {
				$result['reason'] = $resolved_by_cards['reason'];
			}
		}

		/**
		 * Fallback resolver hook.
		 * Useful when source payment id is stored outside order meta
		 * (e.g. by card number in custom table).
		 */
		if ($result['source_payment_id'] === '') {
			$source_payment_id = apply_filters('mp_receipt2_source_payment_id', '', $order, $result);
			$result['source_payment_id'] = trim((string) $source_payment_id);
		}

		if ($result['reason'] === '') {
			$result['reason'] = $result['is_gift_card_settlement'] ? 'detected' : 'not_detected';
		}

		return $result;
	}

	/**
	 * Detect settlement amount and card numbers from PW Gift Card order item type.
	 *
	 * @param WC_Order $order
	 * @return array{settlement_amount:float,card_numbers:array<int,string>}
	 */
	private static function detect_from_pw_gift_card_items(WC_Order $order): array {
		$amount = 0.0;
		$card_numbers = [];

		$lines = $order->get_items('pw_gift_card');
		foreach ($lines as $line) {
			$line_amount = 0.0;
			if (method_exists($line, 'get_amount')) {
				$line_amount = (float) $line->get_amount();
			}

			if ($line_amount > 0) {
				$amount += $line_amount;
			}

			$card = '';
			if (method_exists($line, 'get_card_number')) {
				$card = trim((string) $line->get_card_number());
			}
			if ($card !== '') {
				$card_numbers[] = $card;
			}
		}

		$card_numbers = array_values(array_unique($card_numbers));
		$amount = (float) wc_format_decimal($amount, wc_get_price_decimals());

		return [
			'settlement_amount' => $amount,
			'card_numbers' => $card_numbers,
		];
	}

	/**
	 * Resolve source YooKassa payment id by used card numbers:
	 * card_number -> wgpc table row -> issuance order_id -> transaction_id.
	 *
	 * @param array<int,string> $card_numbers
	 * @return array{payment_id:string,reason:string}
	 */
	private static function resolve_payment_id_by_card_numbers(array $card_numbers): array {
		global $wpdb;

		if (empty($card_numbers) || !isset($wpdb)) {
			return ['payment_id' => '', 'reason' => 'no_card_numbers_for_source_lookup'];
		}

		$table_name = '';
		if (function_exists('wgpc_get_table_name')) {
			$table_name = (string) wgpc_get_table_name();
		}
		if ($table_name === '') {
			$table_name = $wpdb->prefix . 'mpgc_physical_cards';
		}

		$payment_ids = [];
		foreach ($card_numbers as $card_number) {
			$order_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT order_id FROM {$table_name} WHERE card_number = %s LIMIT 1",
					$card_number
				)
			);
			if ($order_id <= 0) {
				continue;
			}

			$issuance_order = wc_get_order($order_id);
			if (!$issuance_order) {
				continue;
			}

			$payment_id = trim((string) $issuance_order->get_transaction_id());
			if ($payment_id === '') {
				$payment_id = trim((string) get_post_meta($order_id, '_transaction_id', true));
			}
			if ($payment_id !== '') {
				$payment_ids[] = $payment_id;
			}
		}

		$payment_ids = array_values(array_unique($payment_ids));
		if (count($payment_ids) === 1) {
			return ['payment_id' => $payment_ids[0], 'reason' => 'resolved_by_card_number_issuance_order'];
		}
		if (count($payment_ids) > 1) {
			return ['payment_id' => '', 'reason' => 'multiple_source_payment_ids_found'];
		}

		return ['payment_id' => '', 'reason' => 'source_payment_id_not_found_by_card_number'];
	}

	/**
	 * Detect gift-card covered amount from fee lines.
	 * Common pattern: negative fee line with name containing "gift card" / "pwgc".
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	private static function detect_settlement_amount_from_fees(WC_Order $order): float {
		$amount = 0.0;
		$fees = $order->get_items('fee');

		foreach ($fees as $fee) {
			$total = (float) $fee->get_total();
			$raw_name = (string) $fee->get_name();
			$name = function_exists('mb_strtolower') ? mb_strtolower($raw_name) : strtolower($raw_name);
			$is_gift_card_line = strpos($name, 'gift card') !== false
				|| strpos($name, 'pwgc') !== false
				|| strpos($name, 'подароч') !== false;

			if ($is_gift_card_line && $total < 0) {
				$amount += abs($total);
			}
		}

		return (float) wc_format_decimal($amount, wc_get_price_decimals());
	}
}

