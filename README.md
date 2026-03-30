# MP YooKassa Receipt2 (Gift Cards)

Плагин отправляет **второй чек (чек зачёта предоплаты)** в ЮKassa для заказов, где оплата/частичная оплата прошла подарочной картой.

Для всех позиций второго чека плагин принудительно ставит:
- `payment_mode = full_payment`
- `payment_subject = commodity`

## 1) Что уже реализовано

- Автоматический запуск на статусе заказа `completed` (`woocommerce_order_status_completed`).
- Сбор `items` и `settlements` для запроса `POST /v3/receipts`.
- Отправка чека через API ЮKassa с `Idempotence-Key`.
- Логи в файл.
- Ручная переотправка из карточки заказа (WooCommerce Order Actions):
  - `Отправить второй чек ЮKassa повторно`.

## 2) Где лежат файлы

- `mp-yookassa-receipt2.php` — bootstrap + главный поток.
- `includes/class-mp-yookassa-receipt2-settings.php` — настройки.
- `includes/class-mp-yookassa-receipt2-logger.php` — логгер.
- `includes/class-mp-yookassa-receipt2-api-client.php` — клиент API ЮKassa.
- `includes/class-mp-yookassa-receipt2-order-links.php` — определение settlement-контекста + `source_payment_id`.
- `includes/class-mp-yookassa-receipt2-receipt-builder.php` — построение `items/settlements`.
- `admin/class-mp-yookassa-receipt2-admin.php` — админ-панель.

## 3) Минимальные требования для работы

Нужно, чтобы для заказа, который идёт в второй чек, были доступны:

1. Сумма зачёта подарочной картой.
2. `payment_id` исходного платежа ЮKassa (платёж, где была предоплата/покупка карты).

По умолчанию плагин ищет:
- `mp_receipt2_settlement_amount` (meta заказа) — сумма зачёта.
- `mp_receipt2_source_payment_id` (meta заказа) — исходный `payment_id`.

Если этих meta нет — можно отдать данные через фильтры (см. ниже).

## 4) Настройки (options / constants)

### WordPress options

- `mp_yookassa_receipt2_enabled` (`0/1`) — включение плагина.
- `mp_yookassa_receipt2_sandbox` (`0/1`) — preprod/prod URL.
- `mp_yookassa_receipt2_shop_id` — Shop ID.
- `mp_yookassa_receipt2_secret_key` — Secret Key.
- `mp_yookassa_receipt2_debug` (`0/1`) — расширенный лог.

### Константы (опционально)

Можно задать в `wp-config.php`:

- `MP_YOOKASSA_RECEIPT2_ENABLED`
- `MP_YOOKASSA_RECEIPT2_SANDBOX`
- `MP_YOOKASSA_RECEIPT2_SHOP_ID`
- `MP_YOOKASSA_RECEIPT2_SECRET_KEY`
- `MP_YOOKASSA_RECEIPT2_DEBUG`

## 5) Логи

Путь:
- `wp-content/uploads/mp-yookassa-receipt2/receipt2-YYYY-MM.log`

Типовые события:
- `settings_invalid`
- `skip_not_gift_card_settlement`
- `skip_missing_source_payment_id`
- `skip_empty_receipt_items`
- `receipt2_sent_success`
- `receipt2_sent_failed`

## 6) Метаданные заказа, которые пишет плагин

- `mp_receipt2_sent = yes` — второй чек успешно отправлен.
- `mp_receipt2_id` — ID чека ЮKassa.
- `mp_receipt2_idempotence_key` — ключ идемпотентности последней попытки.
- `mp_receipt2_error` — текст последней ошибки (если была).

## 7) Фильтры для интеграции с вашим gift-card потоком

### `mp_receipt2_order_links`

Полный override резолва контекста:

```php
add_filter('mp_receipt2_order_links', function ($override, $order) {
    return [
        'is_gift_card_settlement' => true,
        'settlement_amount' => 1500.00,
        'source_payment_id' => '24b94598-000f-5000-9000-1b68e7b15f3f',
    ];
}, 10, 2);
```

### `mp_receipt2_source_payment_id`

Если нужен только `payment_id`:

```php
add_filter('mp_receipt2_source_payment_id', function ($payment_id, $order, $resolved) {
    // Вернуть payment_id по своей логике.
    return $payment_id;
}, 10, 3);
```

### `mp_receipt2_items`, `mp_receipt2_settlements`, `mp_receipt2_vat_code`

Для кастомизации содержимого чека:
- корректировка позиций,
- корректировка settlements,
- кастомный маппинг НДС.

## 8) Ручная переотправка чека

1. Открыть заказ в админке WooCommerce.
2. В блоке действий заказа выбрать:
   - `Отправить второй чек ЮKassa повторно`.
3. Нажать кнопку применения действия.
4. Проверить заметку в заказе + логи.

## 9) Что важно понимать

- Без `source_payment_id` (исходного платежа ЮKassa) плагин второй чек не отправит.
- Если в заказе нет распознанного зачёта подарочной картой — плагин заказ пропустит.
- Точный маппинг НДС может потребовать донастройки через фильтр `mp_receipt2_vat_code`.