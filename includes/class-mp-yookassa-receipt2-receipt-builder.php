<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Step 6: build receipt payload items/settlements.
 */
final class MP_Yookassa_Receipt2_ReceiptBuilder {
	/**
	 * @param WC_Order $order
	 * @param float $settlement_amount
	 * @return array{
	 *   items:array<int,array<string,mixed>>,
	 *   settlements:array<int,array<string,mixed>>,
	 *   total_items_amount:float,
	 *   warnings:array<int,string>
	 * }
	 */
	public static function build(WC_Order $order, float $settlement_amount): array {
		$items = [];
		$warnings = [];
		$total_items_amount = 0.0;

		foreach ($order->get_items('line_item') as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			if (self::is_gift_card_product($product)) {
				// Second receipt should not include gift-card item itself.
				continue;
			}

			$quantity = (float) $item->get_quantity();
			if ($quantity <= 0) {
				continue;
			}

			$line_total = (float) $item->get_total();
			if ($line_total <= 0) {
				continue;
			}

			$unit_amount = $line_total / $quantity;
			$unit_amount = (float) wc_format_decimal($unit_amount, wc_get_price_decimals());
			$quantity = (float) wc_format_decimal($quantity, 3);
			$line_amount = (float) wc_format_decimal($unit_amount * $quantity, wc_get_price_decimals());
			$total_items_amount += $line_amount;

			$items[] = [
				'description' => self::sanitize_description($item->get_name()),
				'quantity' => $quantity,
				'amount' => [
					'value' => self::money($line_amount),
					'currency' => $order->get_currency() ?: 'RUB',
				],
				'vat_code' => self::resolve_vat_code($item),
				'payment_mode' => 'full_payment',
				'payment_subject' => 'commodity',
			];
		}

		if (empty($items)) {
			$warnings[] = 'No non-gift-card line items included in receipt';
		}

		$total_items_amount = (float) wc_format_decimal($total_items_amount, wc_get_price_decimals());
		$settlement_amount = (float) wc_format_decimal(max(0.0, $settlement_amount), wc_get_price_decimals());

		if ($settlement_amount > $total_items_amount && $total_items_amount > 0) {
			$warnings[] = 'Settlement amount exceeds sum of receipt items; clamped to items total';
			$settlement_amount = $total_items_amount;
		}

		$settlements = [
			[
				'type' => 'prepayment',
				'amount' => [
					'value' => self::money($settlement_amount),
					'currency' => $order->get_currency() ?: 'RUB',
				],
			],
		];

		/**
		 * Last-mile customization if needed by current shop rules.
		 */
		$items = apply_filters('mp_receipt2_items', $items, $order, $settlement_amount);
		$settlements = apply_filters('mp_receipt2_settlements', $settlements, $order, $settlement_amount);

		return [
			'items' => $items,
			'settlements' => $settlements,
			'total_items_amount' => $total_items_amount,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private static function sanitize_description(string $name): string {
		$name = trim(wp_strip_all_tags($name));
		if ($name === '') {
			return 'Product';
		}
		if (mb_strlen($name) > 128) {
			return mb_substr($name, 0, 128);
		}

		return $name;
	}

	/**
	 * Basic VAT mapping placeholder.
	 * Can be overridden by filter `mp_receipt2_vat_code`.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return int
	 */
	private static function resolve_vat_code(WC_Order_Item_Product $item): int {
		$default = 1; // 20%
		$tax_class = '';
		$product = $item->get_product();
		if ($product) {
			$tax_class = (string) $product->get_tax_class();
		}

		$map = [
			'' => 1,
			'standard' => 1,
			'reduced-rate' => 2,
			'zero-rate' => 3,
		];
		$vat_code = isset($map[$tax_class]) ? (int) $map[$tax_class] : $default;

		return (int) apply_filters('mp_receipt2_vat_code', $vat_code, $item, $tax_class);
	}

	/**
	 * @param WC_Product $product
	 * @return bool
	 */
	private static function is_gift_card_product(WC_Product $product): bool {
		$product_to_check = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
		if (!$product_to_check) {
			return false;
		}

		$class_name = get_class($product_to_check);
		if ($class_name === 'WC_Product_PW_Gift_Card' || is_a($product_to_check, 'WC_Product_PW_Gift_Card')) {
			return true;
		}

		$type = method_exists($product_to_check, 'get_type') ? (string) $product_to_check->get_type() : '';
		if (strpos($type, 'gift') !== false) {
			return true;
		}

		$sku = method_exists($product_to_check, 'get_sku') ? (string) $product_to_check->get_sku() : '';
		if ($sku !== '' && stripos($sku, 'gift') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * @param float $amount
	 * @return string
	 */
	private static function money(float $amount): string {
		return number_format((float) wc_format_decimal($amount, wc_get_price_decimals()), 2, '.', '');
	}
}

