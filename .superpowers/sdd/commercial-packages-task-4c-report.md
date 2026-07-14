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
