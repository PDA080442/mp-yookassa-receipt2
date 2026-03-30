<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Yookassa_Receipt2_Admin {
	private const PAGE_SLUG = 'mp-yookassa-receipt2';

	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'register_settings']);
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'MP YooKassa Receipt2',
			'YooKassa Receipt2',
			'manage_woocommerce',
			self::PAGE_SLUG,
			[self::class, 'render_page']
		);
	}

	public static function register_settings(): void {
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_SANDBOX, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_SHOP_ID, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_shop_id'],
			'default' => '',
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_SECRET_KEY, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_secret_key'],
			'default' => '',
		]);
		register_setting('mp_yookassa_receipt2', 'mp_yookassa_receipt2_debug', [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_MODE, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_mode'],
			'default' => 'full_payment',
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_SUBJECT, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_subject'],
			'default' => 'commodity',
		]);
		register_setting('mp_yookassa_receipt2', MP_Yookassa_Receipt2_Settings::OPTION_RULES, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_rules'],
			'default' => [],
		]);
	}

	public static function sanitize_checkbox($value): bool {
		return (bool) $value;
	}

	public static function sanitize_shop_id($value): string {
		return trim((string) $value);
	}

	public static function sanitize_secret_key($value): string {
		return trim((string) $value);
	}

	public static function sanitize_payment_mode($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Yookassa_Receipt2_Settings::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	public static function sanitize_payment_subject($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Yookassa_Receipt2_Settings::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules($rules): array {
		if (!is_array($rules)) {
			return [];
		}

		$allowed_modes = MP_Yookassa_Receipt2_Settings::allowed_payment_modes();
		$allowed_subjects = MP_Yookassa_Receipt2_Settings::allowed_payment_subjects();
		$valid_category_ids = self::get_valid_product_category_ids();
		$normalized = [];
		$skipped = 0;

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				$skipped++;
				continue;
			}
			$enabled = !empty($rule['enabled']);
			$priority = isset($rule['priority']) ? (int) $rule['priority'] : 100;
			$payment_mode = isset($rule['payment_mode']) ? trim((string) $rule['payment_mode']) : '';
			$payment_subject = isset($rule['payment_subject']) ? trim((string) $rule['payment_subject']) : '';
			if (!in_array($payment_mode, $allowed_modes, true) || !in_array($payment_subject, $allowed_subjects, true)) {
				$skipped++;
				continue;
			}

			$category_ids = [];
			if (!empty($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $cat_id) {
					$cat_id = (int) $cat_id;
					if ($cat_id > 0 && isset($valid_category_ids[$cat_id])) {
						$category_ids[] = $cat_id;
					}
				}
			}
			$category_ids = array_values(array_unique($category_ids));
			if (empty($category_ids)) {
				$skipped++;
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

		if ($skipped > 0) {
			add_settings_error(
				'mp_yookassa_receipt2',
				'mp_receipt2_rules_skipped',
				sprintf('Некоторые правила (%d) не сохранены: невалидные значения или категории.', $skipped),
				'warning'
			);
		}

		return $normalized;
	}

	/**
	 * @return array<int,bool>
	 */
	private static function get_valid_product_category_ids(): array {
		$result = [];
		$terms = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'ids',
		]);
		if (is_wp_error($terms) || !is_array($terms)) {
			return $result;
		}

		foreach ($terms as $term_id) {
			$term_id = (int) $term_id;
			if ($term_id > 0) {
				$result[$term_id] = true;
			}
		}

		return $result;
	}

	public static function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$api_check = self::handle_api_check();
		$order_debug = self::handle_order_debug();

		$enabled = MP_Yookassa_Receipt2_Settings::is_enabled();
		$sandbox = MP_Yookassa_Receipt2_Settings::is_sandbox();
		$shop_id = MP_Yookassa_Receipt2_Settings::get_shop_id();
		$secret = MP_Yookassa_Receipt2_Settings::get_secret_key();
		$debug = (bool) get_option('mp_yookassa_receipt2_debug', false);
		$default_mode = MP_Yookassa_Receipt2_Settings::get_default_payment_mode();
		$default_subject = MP_Yookassa_Receipt2_Settings::get_default_payment_subject();
		$rules = MP_Yookassa_Receipt2_Settings::get_rules();
		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		]);
		if (!is_array($categories)) {
			$categories = [];
		}
		$errors = MP_Yookassa_Receipt2_Settings::validate_for_api();
		$log_path = self::get_current_log_path();
		$log_tail = self::tail_log($log_path, 25);
		?>
		<div class="wrap">
			<h1>MP YooKassa Receipt2</h1>
			<p>Настройки отправки второго чека ЮKassa для сценариев оплаты подарочной картой.</p>

			<?php settings_errors('mp_yookassa_receipt2'); ?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:1400px;">
				<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;">
					<h2>Основные настройки</h2>
					<form method="post" action="options.php">
						<?php settings_fields('mp_yookassa_receipt2'); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">Включить плагин</th>
								<td><label><input type="checkbox" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_ENABLED); ?>" value="1" <?php checked($enabled); ?>> Да</label></td>
							</tr>
							<tr>
								<th scope="row">Sandbox (preprod)</th>
								<td><label><input type="checkbox" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_SANDBOX); ?>" value="1" <?php checked($sandbox); ?>> Использовать preprod API</label></td>
							</tr>
							<tr>
								<th scope="row">Shop ID</th>
								<td><input type="text" class="regular-text" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_SHOP_ID); ?>" value="<?php echo esc_attr($shop_id); ?>"></td>
							</tr>
							<tr>
								<th scope="row">Secret Key</th>
								<td><input type="password" class="regular-text" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_SECRET_KEY); ?>" value="<?php echo esc_attr($secret); ?>"></td>
							</tr>
							<tr>
								<th scope="row">Debug лог</th>
								<td><label><input type="checkbox" name="mp_yookassa_receipt2_debug" value="1" <?php checked($debug); ?>> Писать расширенный контекст в лог</label></td>
							</tr>
							<tr>
								<th scope="row">Default: Признак способа расчета</th>
								<td>
									<select name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_MODE); ?>">
										<?php foreach (MP_Yookassa_Receipt2_Settings::allowed_payment_modes() as $mode) : ?>
											<option value="<?php echo esc_attr($mode); ?>" <?php selected($default_mode, $mode); ?>><?php echo esc_html($mode); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">Default: Признак предмета расчета</th>
								<td>
									<select name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_SUBJECT); ?>">
										<?php foreach (MP_Yookassa_Receipt2_Settings::allowed_payment_subjects() as $subject) : ?>
											<option value="<?php echo esc_attr($subject); ?>" <?php selected($default_subject, $subject); ?>><?php echo esc_html($subject); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>

						<h3>Правила второго чека по категориям</h3>
						<p>Если правило подходит по категории, его значения заменяют default. Приоритет выше = применяется раньше.</p>

						<table class="widefat striped" id="mp-receipt2-rules-table">
							<thead>
								<tr>
									<th style="width:90px;">Вкл</th>
									<th style="width:120px;">Приоритет</th>
									<th>Категории</th>
									<th style="width:220px;">payment_mode</th>
									<th style="width:220px;">payment_subject</th>
									<th style="width:80px;">Удалить</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($rules as $idx => $rule) : ?>
									<tr>
										<td>
											<input type="hidden" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][enabled]'); ?>" value="0">
											<label><input type="checkbox" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][enabled]'); ?>" value="1" <?php checked(!empty($rule['enabled'])); ?>> Да</label>
										</td>
										<td><input type="number" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][priority]'); ?>" value="<?php echo esc_attr((string) (int) $rule['priority']); ?>" style="width:100px;"></td>
										<td>
											<select multiple size="5" name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][category_ids][]'); ?>" style="min-width:280px;">
												<?php foreach ($categories as $cat) : ?>
													<option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, (array) $rule['category_ids'], true)); ?>>
														<?php echo esc_html($cat->name . ' (#' . $cat->term_id . ')'); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td>
											<select name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][payment_mode]'); ?>">
												<?php foreach (MP_Yookassa_Receipt2_Settings::allowed_payment_modes() as $mode) : ?>
													<option value="<?php echo esc_attr($mode); ?>" <?php selected($rule['payment_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td>
											<select name="<?php echo esc_attr(MP_Yookassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][payment_subject]'); ?>">
												<?php foreach (MP_Yookassa_Receipt2_Settings::allowed_payment_subjects() as $subject) : ?>
													<option value="<?php echo esc_attr($subject); ?>" <?php selected($rule['payment_subject'], $subject); ?>><?php echo esc_html($subject); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><button type="button" class="button mp-receipt2-remove-rule">Удалить</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin-top:10px;">
							<button type="button" class="button" id="mp-receipt2-add-rule">Добавить правило</button>
						</p>

						<script>
						(function() {
							const table = document.getElementById('mp-receipt2-rules-table');
							const addBtn = document.getElementById('mp-receipt2-add-rule');
							if (!table || !addBtn) return;
							const tbody = table.querySelector('tbody');
							const categoriesHtml = <?php echo wp_json_encode(implode('', array_map(static function($cat) {
								return '<option value="' . (int) $cat->term_id . '">' . esc_html($cat->name . ' (#' . $cat->term_id . ')') . '</option>';
							}, $categories))); ?>;
							const modes = <?php echo wp_json_encode(MP_Yookassa_Receipt2_Settings::allowed_payment_modes()); ?>;
							const subjects = <?php echo wp_json_encode(MP_Yookassa_Receipt2_Settings::allowed_payment_subjects()); ?>;
							const optionName = <?php echo wp_json_encode(MP_Yookassa_Receipt2_Settings::OPTION_RULES); ?>;

							function buildOptions(items, selected) {
								return items.map((v) => '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>').join('');
							}

							function rowHtml(idx) {
								return '' +
								'<tr>' +
									'<td><input type="hidden" name="' + optionName + '[' + idx + '][enabled]" value="0"><label><input type="checkbox" name="' + optionName + '[' + idx + '][enabled]" value="1" checked> Да</label></td>' +
									'<td><input type="number" name="' + optionName + '[' + idx + '][priority]" value="100" style="width:100px;"></td>' +
									'<td><select multiple size="5" name="' + optionName + '[' + idx + '][category_ids][]" style="min-width:280px;">' + categoriesHtml + '</select></td>' +
									'<td><select name="' + optionName + '[' + idx + '][payment_mode]">' + buildOptions(modes, 'full_payment') + '</select></td>' +
									'<td><select name="' + optionName + '[' + idx + '][payment_subject]">' + buildOptions(subjects, 'commodity') + '</select></td>' +
									'<td><button type="button" class="button mp-receipt2-remove-rule">Удалить</button></td>' +
								'</tr>';
							}

							addBtn.addEventListener('click', function() {
								const idx = tbody.querySelectorAll('tr').length;
								tbody.insertAdjacentHTML('beforeend', rowHtml(idx));
							});

							tbody.addEventListener('click', function(e) {
								if (e.target && e.target.classList.contains('mp-receipt2-remove-rule')) {
									const tr = e.target.closest('tr');
									if (tr) tr.remove();
								}
							});
						})();
						</script>

						<?php submit_button('Сохранить настройки'); ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;">
					<h2>Диагностика</h2>
					<p><strong>API URL:</strong> <code><?php echo esc_html(MP_Yookassa_Receipt2_Settings::get_api_base_url()); ?></code></p>
					<p><strong>Статус плагина:</strong> <?php echo $enabled ? 'Включен' : 'Выключен'; ?></p>
					<?php if (!empty($errors)) : ?>
						<div style="background:#fff4e5;border-left:4px solid #dba617;padding:10px;">
							<strong>Есть проблемы конфигурации:</strong>
							<ul style="margin:8px 0 0 18px;">
								<?php foreach ($errors as $err) : ?>
									<li><?php echo esc_html($err); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php else : ?>
						<div style="background:#f0f6fc;border-left:4px solid #72aee6;padding:10px;">Конфигурация выглядит корректной.</div>
					<?php endif; ?>

					<form method="post" style="margin-top:16px;">
						<?php wp_nonce_field('mp_receipt2_api_check', 'mp_receipt2_api_check_nonce'); ?>
						<input type="hidden" name="mp_receipt2_action" value="api_check">
						<?php submit_button('Проверить доступ к API', 'secondary', 'submit', false); ?>
					</form>

					<?php if (!empty($api_check)) : ?>
						<div style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;">
							<?php if (!empty($api_check['ok'])) : ?>
								<strong>API доступен.</strong>
							<?php else : ?>
								<strong>API check: ошибка.</strong>
							<?php endif; ?>
							<div>HTTP: <?php echo esc_html((string) $api_check['status']); ?></div>
							<div><?php echo esc_html((string) $api_check['message']); ?></div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin-top:16px;max-width:1400px;">
				<h2>Инспектор заказа</h2>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
					<label for="mp_receipt2_order_id"><strong>ID заказа:</strong></label>
					<input id="mp_receipt2_order_id" type="number" min="1" name="mp_receipt2_order_id" value="<?php echo isset($_GET['mp_receipt2_order_id']) ? esc_attr((string) absint($_GET['mp_receipt2_order_id'])) : ''; ?>">
					<?php submit_button('Проверить заказ', 'secondary', 'submit', false); ?>
				</form>
				<?php if (!empty($order_debug)) : ?>
					<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-height:350px;overflow:auto;"><?php echo esc_html(wp_json_encode($order_debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
				<?php endif; ?>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin-top:16px;max-width:1400px;">
				<h2>Последние строки лога</h2>
				<p><strong>Файл:</strong> <code><?php echo esc_html($log_path); ?></code></p>
				<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-height:350px;overflow:auto;"><?php echo esc_html($log_tail); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array{ok:bool,status:int,message:string}|array{}
	 */
	private static function handle_api_check(): array {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			return [];
		}
		if (empty($_POST['mp_receipt2_action']) || $_POST['mp_receipt2_action'] !== 'api_check') {
			return [];
		}
		check_admin_referer('mp_receipt2_api_check', 'mp_receipt2_api_check_nonce');

		$url = MP_Yookassa_Receipt2_Settings::get_api_base_url();
		$shop_id = trim(MP_Yookassa_Receipt2_Settings::get_shop_id());
		$secret = trim(MP_Yookassa_Receipt2_Settings::get_secret_key());
		if ($shop_id === '' || $secret === '') {
			return ['ok' => false, 'status' => 0, 'message' => 'Заполните Shop ID и Secret Key'];
		}

		$response = wp_remote_request($url, [
			'method' => 'GET',
			'timeout' => 8,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($shop_id . ':' . $secret),
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			return ['ok' => false, 'status' => 0, 'message' => 'WP_Error: ' . $response->get_error_message()];
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$ok = $status > 0 && $status < 500; // 4xx here still means API reachable.
		$msg = 'Ответ получен';
		if (!$ok) {
			$msg = 'Сервис недоступен или ошибка сети';
		}
		return ['ok' => $ok, 'status' => $status, 'message' => $msg];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function handle_order_debug(): array {
		$order_id = isset($_GET['mp_receipt2_order_id']) ? absint($_GET['mp_receipt2_order_id']) : 0;
		if ($order_id <= 0 || !function_exists('wc_get_order')) {
			return [];
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			return ['error' => 'Order not found', 'order_id' => $order_id];
		}

		$resolved = MP_Yookassa_Receipt2_OrderLinks::resolve_for_order($order);
		$rules = MP_Yookassa_Receipt2_Settings::get_rules();
		$default_mode = MP_Yookassa_Receipt2_Settings::get_default_payment_mode();
		$default_subject = MP_Yookassa_Receipt2_Settings::get_default_payment_subject();

		$items_preview = [];
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			$cat_ids = self::get_product_category_ids_for_debug($product);
			$matched = self::resolve_rule_for_debug($cat_ids, $rules);
			$items_preview[] = [
				'item_id' => (int) $item_id,
				'name' => $item->get_name(),
				'product_id' => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'category_ids' => $cat_ids,
				'applied' => [
					'payment_mode' => $matched['payment_mode'] ?? $default_mode,
					'payment_subject' => $matched['payment_subject'] ?? $default_subject,
				],
				'matched_rule' => $matched ? [
					'priority' => $matched['priority'],
					'category_ids' => $matched['category_ids'],
					'enabled' => $matched['enabled'],
				] : null,
			];
		}

		return [
			'order_id' => $order_id,
			'status' => $order->get_status(),
			'resolved' => $resolved,
			'defaults' => [
				'payment_mode' => $default_mode,
				'payment_subject' => $default_subject,
			],
			'rules_count' => count($rules),
			'items_preview' => $items_preview,
			'meta' => [
				'mp_receipt2_sent' => get_post_meta($order_id, 'mp_receipt2_sent', true),
				'mp_receipt2_id' => get_post_meta($order_id, 'mp_receipt2_id', true),
				'mp_receipt2_error' => get_post_meta($order_id, 'mp_receipt2_error', true),
				'mp_receipt2_source_payment_id' => get_post_meta($order_id, 'mp_receipt2_source_payment_id', true),
				'mp_receipt2_settlement_amount' => get_post_meta($order_id, 'mp_receipt2_settlement_amount', true),
				'transaction_id' => method_exists($order, 'get_transaction_id') ? $order->get_transaction_id() : '',
			],
		];
	}

	private static function get_current_log_path(): string {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return rtrim($base, '/\\') . '/mp-yookassa-receipt2/receipt2-' . date('Y-m') . '.log';
	}

	private static function tail_log(string $path, int $lines = 25): string {
		if (!is_readable($path)) {
			return 'Лог пока не создан или недоступен для чтения.';
		}
		$content = (string) @file_get_contents($path);
		if ($content === '') {
			return 'Лог пуст.';
		}
		$rows = explode("\n", trim($content));
		$rows = array_slice($rows, -1 * max(1, $lines));
		return implode("\n", $rows);
	}

	/**
	 * @param WC_Product $product
	 * @return array<int,int>
	 */
	private static function get_product_category_ids_for_debug(WC_Product $product): array {
		$ids = [];
		if (method_exists($product, 'get_category_ids')) {
			$ids = array_map('intval', (array) $product->get_category_ids());
		}
		if (empty($ids) && $product->get_parent_id() > 0) {
			$parent = wc_get_product($product->get_parent_id());
			if ($parent && method_exists($parent, 'get_category_ids')) {
				$ids = array_map('intval', (array) $parent->get_category_ids());
			}
		}
		$ids = array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));
		return $ids;
	}

	/**
	 * @param array<int,int> $product_cat_ids
	 * @param array<int,array<string,mixed>> $rules
	 * @return array<string,mixed>
	 */
	private static function resolve_rule_for_debug(array $product_cat_ids, array $rules): array {
		if (empty($product_cat_ids) || empty($rules)) {
			return [];
		}
		foreach ($rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}
			$rule_cats = isset($rule['category_ids']) && is_array($rule['category_ids']) ? $rule['category_ids'] : [];
			if (empty($rule_cats)) {
				continue;
			}
			$intersect = array_intersect($product_cat_ids, array_map('intval', $rule_cats));
			if (!empty($intersect)) {
				return $rule;
			}
		}
		return [];
	}
}

