# Task 4D — отчёт о завершении commercial billing API

## Реализация

- Добавлены защищённые LK endpoints:
  - `POST /api/v1/landing/billing/commercial/quote` (`billing.view`);
  - `GET /api/v1/landing/billing/commercial/orders/{publicId}` (`billing.view`);
  - `GET /api/v1/landing/billing/commercial/history` (`billing.view`);
  - `POST /api/v1/landing/billing/commercial/contour/schedule` (`billing.manage`).
- Quote использует только серверный текущий оплаченный контур и серверные границы периода. Клиентские current contour/period поля запрещены в Form Request.
- Quote, checkout и отложенная смена контура блокируются во время grace с переведённым HTTP 409.
- Order polling tenant-isolated, не меняет entitlements, возвращает безопасный confirmation URL только для незавершённого используемого payment attempt и не раскрывает provider payload/payment method/provider IDs.
- History tenant-isolated, newest-first, paginated, с типизированными безопасными payment/refund данными и refund summary.
- Отложенное уменьшение контура хранится отдельной неизменяемой записью на фиксированный billing anchor. Блокировка организации/аккаунта и уникальные ограничения защищают от повторов и гонок.
- Nightly renewal использует scheduled contour. Частичное уменьшение формирует renewal order по новому контуру; полное удаление на anchor завершает доступ без нулевого платежа. Оплаченный текущий период до anchor сохраняется.
- Добавлены русские пользовательские сообщения через `trans_message(...)`. Баланс, legacy plans, admin API и уведомления не добавлялись.

## Затронутые файлы

- Новый controller: `app/Http/Controllers/Api/V1/Landing/Billing/CommercialBillingController.php`.
- Новые requests: `CommercialQuoteRequest`, `CommercialHistoryRequest`, `CommercialContourScheduleRequest`.
- Новые services: `CommercialBillingQueryService`, `CommercialContourChangeService`.
- Новые model/exception: `CommercialContourChange`, `CommercialBillingConflictException`.
- Новая migration: `database/migrations/2026_07_14_000004_create_commercial_contour_changes_table.php`.
- Обновлены routes, billing translations, commercial order/account relations, checkout grace guard и renewal integration.
- Расширены `CommercialCheckoutControllerTest` и `CommercialRenewalServiceTest`.

## TDD evidence

RED наблюдался до production-кода/исправлений:

- quote endpoint: ожидаемый 200, фактический 404;
- scheduled reduced contour: renewal order содержал старые `planning-schedules` + `machinery` вместо только `machinery`;
- refunded order status: `paid_at` был `null` вместо времени успешной оплаты;
- quote с клиентскими current contour/period: ожидаемый 422, фактический 200.

GREEN:

- отдельные новые сценарии после минимальной реализации прошли;
- финальный focused regression suite: `OK (93 tests, 452 assertions)`.

## Команды и результаты

- PHPUnit focused billing regression:
  - `vendor/bin/phpunit` для calculator, checkout service/controller, webhook service/transaction runner, renewal и grace;
  - результат: 93/93 теста, 452 assertions, exit 0.
- `php -l` для изменённых PHP-файлов, включая migration: без синтаксических ошибок.
- Larastan/PHPStan по 12 изменённым production PHP-файлам с `--memory-limit=1G --no-progress`: `[OK] No errors`, exit 0.
- Pint `--test` по 17 изменённым PHP-файлам: PASS.
- `git diff --check`: без ошибок.
- Миграции и любые локальные DB-команды вне PHPUnit test environment не запускались.

## Self-review

- Проверены tenant predicates на quote/order/history/schedule и составные tenant FK в migration.
- Проверено отсутствие `safe_response`, payment method ID, provider payment/refund IDs и client idempotency keys в LK read payloads.
- Проверены permission boundaries: read endpoints находятся под `billing.view`, checkout/schedule — под `billing.manage`.
- Проверены idempotency и concurrency guards: row locks, immutable target/apply_at, уникальность organization+client key и account+anchor.
- Проверено сохранение текущего доступа при scheduled removal и применение контура ровно на renewal anchor.
- Self-review дополнительно выявил и исправил сохранение `paid_at` у refunded order и запрет клиентских period/current contour полей в quote.

## Concerns

- Migration только статически проверена и должна быть применена штатным deployment pipeline до использования новых endpoints.
- Интеграция не проверялась против live YooKassa: Task 4D не меняет gateway и не использует секрет провайдера.
