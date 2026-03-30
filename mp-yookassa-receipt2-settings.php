<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Step 2: Configuration/settings loader.
 *
 * Note: To keep this mini-plugin simple, we read settings from wp_options.
 * Admin UI can be added later (step 7/9 in the plan).
 */
final class MP_Yookassa_Receipt2_Settings {
	const OPT_ENABLED = 'mp_receipt2_enabled';
	const OPT_SANDBOX = 'mp_receipt2_sandbox';
	const OPT_SHOP_ID = 'mp_receipt2_shop_id';
	const OPT_SECRET_KEY = 'mp_receipt2_secret_key';
	const OPT_ENDPOINT = 'mp_receipt2_receipts_endpoint';

	public static function is_enabled(): bool {
		return (string) get_option(self::OPT_ENABLED, '0') === '1';
	}

	public static function is_sandbox(): bool {
		return (string) get_option(self::OPT_SANDBOX, '0') === '1';
	}

	/**
	 * If endpoint differs in your environment, override it via option or filter.
	 */
	public static function receipts_endpoint(): string {
		$endpoint = (string) get_option(self::OPT_ENDPOINT, 'https://api.yookassa.ru/v3/receipts');
		$endpoint = trim($endpoint);
		if ($endpoint === '') {
			$endpoint = 'https://api.yookassa.ru/v3/receipts';
		}

		return (string) apply_filters('mp_receipt2_receipts_endpoint', $endpoint, self::is_sandbox());
	}

	public static function shop_id(): string {
		return (string) trim((string) get_option(self::OPT_SHOP_ID, ''));
	}

	public static function secret_key(): string {
		return (string) get_option(self::OPT_SECRET_KEY, '');
	}

	/**
	 * Validate required settings for API calls.
	 *
	 * @return string[] Array of error messages. Empty array means OK.
	 */
	public static function validate_for_api(): array {
		$errors = [];

		if (!self::is_enabled()) {
			$errors[] = 'Plugin disabled.';
			return $errors;
		}

		if (self::shop_id() === '') {
			$errors[] = 'Missing shop_id (option mp_receipt2_shop_id).';
		}

		if (self::secret_key() === '') {
			$errors[] = 'Missing secret_key (option mp_receipt2_secret_key).';
		}

		return $errors;
	}
}

