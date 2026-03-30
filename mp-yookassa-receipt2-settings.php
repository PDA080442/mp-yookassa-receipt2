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

		return $errors;
	}
}

