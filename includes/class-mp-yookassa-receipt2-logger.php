<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Step 3: file logger.
 *
 * Writes logs into wp-content/uploads/mp-yookassa-receipt2/ as monthly files.
 */
final class MP_Yookassa_Receipt2_Logger {
	private const DIR_SLUG = 'mp-yookassa-receipt2';
	private const OPT_DEBUG = 'mp_yookassa_receipt2_debug';

	public static function is_debug(): bool {
		if (defined('MP_YOOKASSA_RECEIPT2_DEBUG')) {
			return (bool) MP_YOOKASSA_RECEIPT2_DEBUG;
		}

		return (bool) get_option(self::OPT_DEBUG, false);
	}

	private static function log_dir(): string {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . self::DIR_SLUG;
	}

	private static function ensure_dir_exists(): void {
		$dir = self::log_dir();
		if (!is_dir($dir)) {
			// wp_mkdir_p handles recursive mkdir and permissions.
			if (function_exists('wp_mkdir_p')) {
				wp_mkdir_p($dir);
			} else {
				@mkdir($dir, 0755, true);
			}
		}
	}

	/**
	 * @param string $level INFO|DEBUG|ERROR
	 * @param int|string $order_id
	 * @param string $action
	 * @param array<string,mixed> $context
	 * @return void
	 */
	public static function log(string $level, $order_id, string $action, array $context = []): void {
		self::ensure_dir_exists();

		$level = strtoupper(trim($level));
		if (!in_array($level, ['INFO', 'DEBUG', 'ERROR'], true)) {
			$level = 'INFO';
		}

		$ts = date('Y-m-d H:i:s');

		$context_out = $context;
		if (!$context_out || !self::is_debug()) {
			// In non-debug mode keep context minimal to reduce sensitive data leakage.
			$context_out = [];
		}

		$ctx_json = '';
		if (!empty($context_out)) {
			$ctx_json = json_encode($context_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($ctx_json === false) {
				$ctx_json = '';
			}
		}

		$order_part = (string) $order_id;
		$line = sprintf(
			"[%s] %s order_id=%s action=%s%s\n",
			$ts,
			$level,
			$order_part,
			$action,
			$ctx_json !== '' ? ' context=' . $ctx_json : ''
		);

		$filename = 'receipt2-' . date('Y-m') . '.log';
		$path = self::log_dir() . DIRECTORY_SEPARATOR . $filename;

		// File locking is not critical here; just append.
		@file_put_contents($path, $line, FILE_APPEND);
	}
}

