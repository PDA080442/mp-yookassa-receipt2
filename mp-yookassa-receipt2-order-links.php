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

		// 1) Settlement amount from explicit order meta (preferred).
		$settlement_amount_meta = (float) get_post_meta($order_id, self::META_SETTLEMENT_AMOUNT, true);
		if ($settlement_amount_meta > 0) {
			$result['settlement_amount'] = (float) wc_format_decimal($settlement_amount_meta, wc_get_price_decimals());
			$result['is_gift_card_settlement'] = true;
		}

		// 2) Fallback heuristic: detect gift-card discount from negative fee lines.
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
			$name = mb_strtolower((string) $fee->get_name());
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

