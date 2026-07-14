# Task 4C — автопродление и льготный период

## Статус

DONE

## Реализовано

- Renewal cycle уникален для коммерческого аккаунта и целевого периода; период и семидневный grace фиксируются при создании и не сдвигаются поздним платежом.
- Заказ продления хранит неизменяемый снимок текущего контура и цены. Платежи переведены в one-to-many с явными ролями и номерами попыток.
- YooKassa saved-method payment выполняется отдельным DTO/методом без redirect confirmation, с новым ключом только для новой дневной попытки.
- Engine создаёт не более одной попытки на московскую дату, повторяет тот же intent/key после транспортной неопределённости, сверяет pending и terminal ответы через authoritative `getPayment()` и общий webhook transition.
- Direct `succeeded`/`canceled` response больше не зависит от доставки webhook: выполняется немедленная reconciliation; при ошибке terminal payment остаётся с `terminal_at=null` и повторно сверяется тем же provider id без нового списания.
- Поздний duplicate webhook после scheduler transition идемпотентен: повторно не активирует контур и не создаёт второе уведомление.
- День 7 приостанавливает коммерческий аккаунт и завершает только платные source rows без восьмой попытки. Отключение автопродления не отзывает оплаченный/grace доступ досрочно.
- LK API чтения и отключения защищён JWT, membership/interface и `billing.view`/`billing.manage`; provider method id наружу не выдаётся.
- Renewal schedule зарегистрирован ровно один раз на `03:00 Europe/Moscow` с overlap/server lock.
- Trial lifecycle вынесен в отдельную idempotent hourly-команду. Она отправляет trial-ending в окне 23–24 часа и завершает уже истёкшие trial rows, не затрагивая платежи, grace и immutable usage ledger.
- Все billing/trial уведомления адресуются ответственному пользователю только через `channels=['in_app']` и дедуплицируются отдельным ключом.

## TDD и исправления финального аудита

- Lost-webhook RED: direct `succeeded` ожидал один вызов authoritative processor, фактически было 0.
- Lost-webhook GREEN: два новых сценария `succeeded`/`canceled`, 6 assertions; полный renewal suite — 11 tests, 45 assertions.
- Late webhook race: первый renewal success возвращает `processed`, поздний webhook — `duplicate`; cycle остаётся `paid`, notification count остаётся 1.
- Trial scheduler RED: отдельная hourly-регистрация отсутствовала (`0 !== 1`).
- Trial scheduler GREEN: renewal и trial lifecycle имеют независимые расписания; focused static suite — 2 tests, 9 assertions.

## Свежие focused/regression проверки

- `APP_ENV=testing php artisan test tests/Feature/Billing/CommercialRenewalServiceTest.php --stop-on-failure` — 11 tests, 45 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Api/V1/Landing/CommercialCheckoutControllerTest.php --stop-on-failure` — 9 tests, 32 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Api/V1/Landing/OrganizationPackageControllerTest.php --stop-on-failure` — 9 tests, 46 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Billing/CommercialBillingNotificationServiceTest.php --stop-on-failure` — 1 test, 31 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Billing/CommercialWebhookServiceTest.php --stop-on-failure` — 26 tests, 156 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Api/V1/YooKassaWebhookControllerTest.php tests/Feature/Billing/CommercialWebhookTransactionRunnerTest.php --stop-on-failure` — 7 tests, 13 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Billing/CommercialCheckoutServiceTest.php --stop-on-failure` — 10 tests, 40 assertions, exit 0.
- `APP_ENV=testing php artisan test tests/Feature/Billing/PackageTrialServiceTest.php --stop-on-failure` — 10 tests, 42 assertions, exit 0.
- Финальный gateway/schema + notifications запуск — 18 tests, 106 assertions, exit 0; gateway/schema отдельно составляют 17 tests, 75 assertions.

Laravel runner помечает тесты предупреждениями из-за существующих `file_get_contents(...)` для отсутствующих локальных package-файлов. Все перечисленные процессы завершились exit 0; schema tests проходят без этих предупреждений.

## Quality gates

- PHPStan/Larastan: `APP_ENV=testing`, 18 изменённых production PHP-файлов, `--memory-limit=1G`, `[OK] No errors`.
- Pint `--test`: 31 изменённый PHP-файл, PASS. Четыре untracked `review-*.diff` не включались.
- `php -l`: 31/31 изменённых PHP-файлов без syntax errors.
- `git diff --check`: PASS.
- Миграции, DB artisan-команды, dev server и реальные HTTP-запросы к YooKassa не запускались.

## Self-review

- Внешний HTTP не выполняется внутри DB transaction; prepare/send/reconcile разделены на короткие фазы.
- Terminal create response не может породить второй intent при сбое reconciliation.
- Webhook остаётся единственным authoritative transition для entitlement; scheduler использует тот же processor.
- Fixed billing anchor, day-6 end date, day-7 suspension, exact package/full-suite contour и conservative unknown reason покрыты тестами.
- Trial hourly flow отделён от платежей и не создаёт renewal cycles/payments.
- В staging не включаются четыре review diff артефакта.

## Review fixes — календарь автопродления

- Календарь переведён с exact timestamp на неизменяемую московскую `billing_due_date`. Due+0 запускается в 03:00 календарной даты даже при `current_period_end_at=14:00`; попытки разрешены только due+0…due+6, due+7 в 03:00 приостанавливает доступ без восьмой попытки. Exact target period timestamps не изменяются.
- Contractual grace deadline хранится как начало due+7 Europe/Moscow, явно конвертированное в UTC. Delayed suspension больше не перезаписывает его фактическим временем scheduler.
- Opt-out до первой неуспешной попытки не создаёт cycle/grace/payment. До exact paid end доступ остаётся active, после exact end естественно становится suspended/expired с пустыми grace fields и неизменённым entitlement end.
- Lost-response webhook связывает ровно единственную незаписанную попытку соответствующего renewal cycle/order. Attempt #2 `succeeded`/`canceled` связывается корректно; несколько незаписанных кандидатов возвращают retryable non-200 без binding/event marker.
- Retry разрешён только явному allowlist финансовых/временных причин. Revoked, expired/invalid/restricted card, fraud и unknown консервативно отключают дальнейшие списания.
- Full-suite renewal активирует только `selected_package_slugs` неизменяемого order snapshot; изменение live catalog после создания cycle не расширяет оплаченный контур.
- Schema усилена unique order→renewal cycle, тройным order/account/organization FK, role/cycle CHECK и cycle/order payment FK. Отдельные SQLite runtime tests подтверждают unique/FK/CHECK нарушения.
- Batch exception логируется безопасно: commercial account id, exception class и code без provider payload, method id и ключей.

### RED/GREEN

- Calendar RED: cycle отсутствовал в due-date 03:00 при exact end 14:00; opt-out после exact end оставался active. GREEN focused: 2 tests, 15 assertions.
- Attempt #2/schema RED: binder выбирал первый payment заказа; isolated fixtures дополнительно сохраняли устаревший one-payment unique. GREEN binding/reason/snapshot matrix: 9 tests, 54 assertions.
- Runtime constraints GREEN: 4 runtime tests плюс static renewal schema test, 19 assertions.

### Свежая матрица после review fixes

- Renewal engine: 11 tests, 53 assertions, exit 0.
- Commercial webhook: 35 tests, 210 assertions, exit 0.
- Checkout service: 10 tests, 40 assertions, exit 0.
- Protected checkout/renewal API: 9 tests, 32 assertions, exit 0.
- Organization packages API: 9 tests, 46 assertions, exit 0.
- Gateway + static schema + runtime constraints: 21 tests, 84 assertions, exit 0.
- Notifications + trial lifecycle: 11 tests, 73 assertions, exit 0.
- Webhook controller + transaction race: 7 tests, 13 assertions, exit 0.
- PHPStan/Larastan: `APP_ENV=testing`, 4 изменённых production PHP-файла, `--memory-limit=1G`, `[OK] No errors`.

## Re-review fixes — доступ в grace и московские upcoming dates

- `OrganizationPackageSubscription::isActive()` и `scopeActive()` теперь обрабатывают `grace` отдельно от immutable paid period: эффективная граница берётся из tenant-matched commercial account `grace_ends_at`. Active/scheduled paid, corporate и trial сохранили прежние независимые правила.
- `OrganizationEntitlementService` и `PackageService` уже использовали `scopeActive()`. Ручные обходы в checkout current contour и renewal contour переведены на тот же scope, поэтому grace contour считается текущим до contractual deadline и исчезает строго на нём.
- Интеграционный тест через реальный `OrganizationEntitlementService` подтверждает доступ до due, после canceled в grace, tenant isolation, отсутствие paid entitlement на deadline при сохранении free foundation и day-6 success с exact target end.
- Upcoming 3/1 days больше не используют DB `whereDate`. Для каждой московской целевой даты строится полуинтервал `[startOfDay, nextStartOfDay)`, обе границы явно сохраняются в UTC. Due в 00:30 Moscow корректно уведомляется на московские -3/-1, но не -4/-2, повторные запуски дедуплицируются.

### RED/GREEN и проверки re-review

- RED: grace row исчезал из реального entitlement сразу после immutable `current_period_end_at`; GREEN grace integration — 1 test, 10 assertions.
- Moscow upcoming focused + existing notification lifecycle — 2 tests, 35 assertions; совместный финальный focused запуск с grace — 3 tests, 45 assertions.
- Existing organization package entitlement regression — 11 tests, 30 assertions.
- Renewal engine regression — 11 tests, 53 assertions.
- Checkout regression — 10 tests, 40 assertions.
- Webhook/day-6 regression — 35 tests, 210 assertions.
- PHPStan/Larastan: `APP_ENV=testing`, 4 production PHP-файла, `--memory-limit=1G`, `[OK] No errors`.
- Pint `--test`: 7 изменённых PHP-файлов, PASS; `php -l`: 7/7; `git diff --check`: PASS.
