<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings for mp-yookassa-receipt2.
 *
 * Step 2: config loading only (no admin UI yet).
 */
final class MP_Yookassa_Receipt2_Settings {
	const OPTION_ENABLED = 'mp_yookassa_receipt2_enabled';
	const OPTION_SANDBOX = 'mp_yookassa_receipt2_sandbox';
	const OPTION_SHOP_ID = 'mp_yookassa_receipt2_shop_id';
	const OPTION_SECRET_KEY = 'mp_yookassa_receipt2_secret_key';
	const OPTION_DEFAULT_PAYMENT_MODE = 'mp_yookassa_receipt2_default_payment_mode';
	const OPTION_DEFAULT_PAYMENT_SUBJECT = 'mp_yookassa_receipt2_default_payment_subject';
	const OPTION_RULES = 'mp_yookassa_receipt2_rules';

	/**
	 * @return bool
	 */
	public static function is_enabled(): bool {
		// Allow override via constants for quick testing.
		if (defined('MP_YOOKASSA_RECEIPT2_ENABLED')) {
			return (bool) MP_YOOKASSA_RECEIPT2_ENABLED;
		}

		return (bool) get_option(self::OPTION_ENABLED, false);
	}

	/**
	 * @return bool
	 */
	public static function is_sandbox(): bool {
		if (defined('MP_YOOKASSA_RECEIPT2_SANDBOX')) {
			return (bool) MP_YOOKASSA_RECEIPT2_SANDBOX;
		}

		return (bool) get_option(self::OPTION_SANDBOX, false);
	}

	/**
	 * @return string
	 */
	public static function get_shop_id(): string {
		if (defined('MP_YOOKASSA_RECEIPT2_SHOP_ID')) {
			return (string) MP_YOOKASSA_RECEIPT2_SHOP_ID;
		}

		$val = get_option(self::OPTION_SHOP_ID, '');
		return is_string($val) ? $val : '';
	}

	/**
	 * @return string
	 */
	public static function get_secret_key(): string {
		if (defined('MP_YOOKASSA_RECEIPT2_SECRET_KEY')) {
			return (string) MP_YOOKASSA_RECEIPT2_SECRET_KEY;
		}

		$val = get_option(self::OPTION_SECRET_KEY, '');
		return is_string($val) ? $val : '';
	}

	/**
	 * @return string
	 */
	public static function get_api_base_url(): string {
		return self::is_sandbox()
			? 'https://api-preprod.yookassa.ru/v3/receipts'
			: 'https://api.yookassa.ru/v3/receipts';
	}

	/**
	 * Allowed YooKassa payment_mode values for receipt items.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_payment_modes(): array {
		return [
			'full_payment',
			'full_prepayment',
			'advance',
			'partial_payment',
			'partial_prepayment',
			'credit',
			'credit_payment',
		];
	}

	/**
	 * Allowed YooKassa payment_subject values for receipt items.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_payment_subjects(): array {
		return [
			'commodity',
			'excise',
			'job',
			'service',
			'payment',
			'another',
		];
	}

	/**
	 * @return string
	 */
	public static function get_default_payment_mode(): string {
		$value = trim((string) get_option(self::OPTION_DEFAULT_PAYMENT_MODE, 'full_payment'));
		if (!in_array($value, self::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function get_default_payment_subject(): string {
		$value = trim((string) get_option(self::OPTION_DEFAULT_PAYMENT_SUBJECT, 'commodity'));
		if (!in_array($value, self::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * Rules structure:
	 * [
	 *   [
	 *     'enabled' => true,
	 *     'priority' => 100,
	 *     'category_ids' => [12, 15],
	 *     'payment_mode' => 'full_payment',
	 *     'payment_subject' => 'commodity',
	 *   ],
	 *   ...
	 * ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_rules(): array {
		$rules = get_option(self::OPTION_RULES, []);
		if (!is_array($rules)) {
			return [];
		}

		$normalized = [];
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$enabled = !empty($rule['enabled']);
			$priority = isset($rule['priority']) ? (int) $rule['priority'] : 100;
			$payment_mode = isset($rule['payment_mode']) ? trim((string) $rule['payment_mode']) : '';
			$payment_subject = isset($rule['payment_subject']) ? trim((string) $rule['payment_subject']) : '';

			if (!in_array($payment_mode, self::allowed_payment_modes(), true)) {
				continue;
			}
			if (!in_array($payment_subject, self::allowed_payment_subjects(), true)) {
				continue;
			}

			$category_ids = [];
			if (isset($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $id) {
					$id = (int) $id;
					if ($id > 0) {
						$category_ids[] = $id;
					}
				}
			}
			$category_ids = array_values(array_unique($category_ids));
			if (empty($category_ids)) {
				continue;
			}

			$normalized[] = [
				'enabled' => $enabled,
				'priority' => $priority,
				'category_ids' => $category_ids,
				'payment_mode' => $payment_mode,
				'payment_subject' => $payment_subject,
			];
		}

		usort($normalized, static function ($a, $b) {
			return ((int) $b['priority']) <=> ((int) $a['priority']);
		});

		return $normalized;
	}

	/**
	 * @return array<string> list of problems
	 */
	public static function validate_for_api(): array {
		$errors = [];

		if (!self::is_enabled()) {
			$errors[] = 'Plugin is disabled';
			return $errors;
		}

		$shopId = trim(self::get_shop_id());
		$secret = trim(self::get_secret_key());

		if ($shopId === '') {
			$errors[] = 'Missing shop_id (set option mp_yookassa_receipt2_shop_id or constant MP_YOOKASSA_RECEIPT2_SHOP_ID)';
		}
		if ($secret === '') {
			$errors[] = 'Missing secret_key (set option mp_yookassa_receipt2_secret_key or constant MP_YOOKASSA_RECEIPT2_SECRET_KEY)';
		}

		if (!in_array(self::get_default_payment_mode(), self::allowed_payment_modes(), true)) {
			$errors[] = 'Invalid default payment_mode';
		}
		if (!in_array(self::get_default_payment_subject(), self::allowed_payment_subjects(), true)) {
			$errors[] = 'Invalid default payment_subject';
		}

		return $errors;
	}
}

