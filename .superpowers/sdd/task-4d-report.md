# Task 4D — отчёт о завершении commercial billing API

## Исправление cadence планировщика и точного billing anchor

### Изменения

- Due-процессор коммерческого биллинга запускается каждую минуту с московской временной зоной, `withoutOverlapping(120)` и `onOneServer()`.
- До точного `current_period_end_at` процессор не изменяет доступ; первый тик после произвольного anchor создаёт ровно один renewal cycle.
- В момент anchor сохраняемые пакеты переводятся в grace-backstop без разрыва доступа, а удаляемые scheduled-пакеты истекают и не возвращаются в grace после отмены платежа.
- Сверка pending-платежей ограничена пятиминутным интервалом.
- Повтор отменённого платежа остаётся отдельным ежедневным окном в `03:00 Europe/Moscow`.

### RED → GREEN evidence

- Scheduler: `dailyAt('03:00')` → `everyMinute()` с production locks.
- Grace retry: второй платёж в 00:01 → единственный повтор не ранее 03:00 московской даты.
- Reconciliation: lookup на каждом тике → первый lookup через пять минут.
- Anchor 14:00: разрыв доступа → отсутствие ранней мутации и активный сохранённый пакет после первого post-anchor тика.
- Reduced contour cancellation: удалённый пакет возвращался в grace → grace получает только snapshot целевого заказа.

### Проверки

- Полный Task 4D regression: `OK (106 tests, 539 assertions)`.
- Renewal suite: `OK (15 tests, 81 assertions)`.
- `php -l`: 6/6 изменённых PHP-файлов без синтаксических ошибок.
- Larastan/PHPStan по двум изменённым production services: `[OK] No errors`.
- Pint по 6 изменённым PHP-файлам: PASS.
- `git diff --check`: без ошибок.

### Ограничения

- Миграции и локальные DB-команды вне изолированной SQLite-схемы PHPUnit не запускались.
- Live YooKassa и production scheduler не вызывались; секреты провайдера не использовались.

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

## Fix-wave по Important findings ревью

### Исправления

- Renewal теперь сравнивает текущее время с точным `current_period_end_at`/`apply_at`, а не только с московским календарным днём. До точного anchor не создаются order/payment, не вызывается gateway и не завершается доступ для empty contour.
- Checkout проверяет grace до возврата ранее созданного idempotent purchase intent. Повтор intent без provider ID больше не вызывает новый provider side effect во время grace.
- Поздний `payment.succeeded` по purchase order во время grace не активирует контур и не переводит account в active. Payment фиксируется как succeeded без confirmation URL/сохранённого метода, webhook event получает `manual_review`, а order и grace entitlements сохраняются для безопасной ручной сверки/возврата.
- Concurrency exception mapping расширен на PostgreSQL/MySQL serialization/deadlock SQLSTATE (`40001`, `40P01`) после исчерпания трёх transaction retries.
- Добавлен отдельный API-тест запрета schedule в grace.

### Дополнительный RED → GREEN evidence

- Partial scheduled contour с anchor `14:00`: в RED renewal order создавался в `03:00` того же дня; после fix до anchor order/payment/gateway отсутствуют, на anchor используется reduced contour, включая immediate-success gateway.
- Empty scheduled contour с anchor `14:00`: в RED account становился free в `03:00`; после fix доступ active до anchor и expired ровно при обработке на anchor.
- Retry ранее созданного purchase intent: в RED повторно вызывал gateway во время grace; после fix возвращает business conflict без нового provider call.
- Late purchase success: в RED возвращал `processed` и активировал purchase contour; после fix возвращает `manual_review`, сохраняет grace и не меняет entitlements.
- Exhausted PostgreSQL deadlock simulation: в RED API возвращал 500; после fix возвращает 409. Первый transient deadlock отдельно подтвердил встроенный retry, поэтому тест намеренно исчерпывает все три попытки.
- Static PostgreSQL contract в RED не находил deadlock SQLSTATE; после fix подтверждает row locks, оба unique guard и conflict states.

### Проверки fix-wave

- Focused regression suite расширен static contract: `OK (103 tests, 526 assertions)`.
- Покрывающие focused tests: renewal 13/13; checkout grace retry; late purchase webhook; schedule-in-grace; exhausted-deadlock conflict — GREEN.
- `php -l`: 9/9 изменённых PHP-файлов без ошибок.
- Larastan/PHPStan: 4 изменённых production services, `[OK] No errors`.
- Pint `--test`: 9/9 файлов PASS.
- `git diff --check`: без ошибок.

### Ограничение concurrency-проверки

- SQLite не доказывает реальную PostgreSQL-конкуренцию двух соединений и row-level locking. Поэтому реальная race не заявляется: проверены production PostgreSQL unique/lock contracts и детерминированная ветка exhausted deadlock через `QueryException` с SQLSTATE `40P01`.

## Финальный fix-wave по re-review

### Исправления

- Renewal snapshot больше не зависит от `active()` и текущего времени: контур читается tenant-safe по account, organization, источникам `paid_package`/`full_suite`/`corporate`, разрешённым статусам и точному `current_period_end_at = billing anchor`.
- Добавлено вычисляемое техническое окно `commercial_offers.renewal_processing_window_minutes = 5`. Оно сохраняет доступ только для eligible auto-renew account между exact anchor и первым scheduler tick, не меняет persisted status и автоматически истекает при остановленном scheduler.
- Первый due tick переводит целевой контур в обычный семидневный grace до provider/reconciliation side effect. Corporate-источник меняется только если входит в renewal target; unrelated corporate access сохраняется.
- Due query выбирает только actionable now: future anchors, pending reconciliation до `next_attempt_at` и ночные retries до 03:00 не занимают limit. Сортировка по due action, last attempt и id обеспечивает продвижение очереди без OFFSET.
- Повторные transport failures одного provider intent ограничены пятиминутным интервалом и продвигают `next_attempt_at`/`last_attempt_at`.
- Добавлены production-индексы account due scan и current-cycle actionable lookup; миграция локально не запускалась.

### RED → GREEN evidence

- Ordinary и full-suite renewal на первом post-anchor tick в RED не создавали order из-за пустого time-sensitive snapshot; в GREEN создают точный immutable order/payment/grace contour, включая целевой corporate source.
- Технический backstop в RED возвращал `false` сразу после anchor; в GREEN `isActive()` и `scopeActive()` дают одинаковый tenant-safe доступ до пяти минут и отзывают его точно на границе без persisted-мутаций.
- При 100 non-actionable grace cycles lower IDs в RED due account за limit не обрабатывался; в GREEN он обрабатывается на текущем тике.
- Второй transport failure в RED занимал следующий минутный тик; в GREEN следующий retry допускается только через пять минут с тем же локальным intent/idempotency key.
- Полный gate выявил и затем подтвердил исправление regression, при котором unrelated corporate row ошибочно истекал.

### Финальные проверки

- Полный Task 4D regression: `OK (111 tests, 571 assertions)`.
- Focused renewal/grace/entitlement: renewal `18/18 (100 assertions)`, grace `2/2 (20 assertions)`, общий entitlement `11/11 (30 assertions)`.
- `php -l`: 9/9 изменённых PHP-файлов без синтаксических ошибок.
- Larastan/PHPStan по модели, двум production services и новой migration: `[OK] No errors`.
- Pint `--test`: 9/9 файлов PASS.
- `git diff --check`: без ошибок.

### Ограничения

- Новая миграция проверена статически, но не запускалась.
- PostgreSQL execution plan и реальное конкурентное поведение нескольких соединений локально не измерялись; fair progression доказан реальным SQLite DB query с количеством строк больше batch limit.
- Live YooKassa, production scheduler и production данные не вызывались; секреты провайдера не использовались.
