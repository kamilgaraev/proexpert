# Task 6: удаление старой тарифной модели

## Результат

Из runtime backend МОСТ удалена старая модель тарифных планов и подписок. Единственным источником коммерческого доступа остаются `OrganizationCommercialAccount`, `OrganizationPackageSubscription`, `CommercialOrder` и `CommercialPayment`.

Изменение затронуло 102 файла: удалено около 17 800 строк старой реализации, включая модели, сервисы, контроллеры, middleware, команды продления, Filament-ресурсы, уведомления и демонстрационный сценарий старого биллинга.

## Удалённые поверхности

- `SubscriptionPlan` и `OrganizationSubscription`, их сервисы, репозитории, API-контроллеры и ресурсы.
- Лимиты пользователей, проектов и приглашений, зависевшие от старого плана.
- `BillingEngine`, `ModuleManager`, ручное платное включение модулей и старые команды renew/convert.
- Старые Filament-экраны планов, подписок и ручных активаций.
- Старые billing email/push/admin уведомления. Коммерческие in-app уведомления нового контура сохранены.
- Старый demo seeder, полностью зависевший от тарифных планов.

`OrganizationModuleActivation` сохранена как техническое хранилище состояния, настроек и usage-метрик. Из модели и миграции удалены поля, связывавшие её с оплатой и старой подпиской.

## Миграция данных

`2026_07_15_000001_remove_legacy_billing_runtime.php` выполняется после миграций нового коммерческого контура и:

- удаляет внешние ключи старой подписки из платежей и журнала баланса;
- переводит реферальные награды на `commercial_order_id` и `commercial_payment_id`;
- удаляет billing-поля технических активаций модулей;
- удаляет таблицы `subscription_plans`, `organization_subscriptions` и связанные legacy-таблицы.

Миграция намеренно деструктивна для legacy-данных. `down()` восстанавливает только минимальную пустую структуру для аварийного отката кода и не может восстановить удалённые коммерческие данные. Перед production-деплоем требуется резервная копия.

## Реферальная программа

Реферальный сценарий сохранён и переведён на первый авторитетно оплаченный `CommercialOrder`:

- награда создаётся один раз после `CommercialPayment` со статусом `succeeded`;
- начисление остаётся pending до окончания оплаченного периода;
- authoritative refund/cancel отменяет награду;
- начисление идемпотентно и проводится через существующий bonus balance ledger.

## Проверки

- Architecture contract: 11 тестов, 24 утверждения — пройдено.
- Referral, package entitlement и commercial webhook: 58 поведенческих тестов; вместе с architecture contract — 69 тестов и 334 утверждения без assertion failures.
- PHPUnit отображает предупреждения только из-за отсутствующего `.env` в изолированном worktree; assertion failures отсутствуют.
- PHP syntax: 50 изменённых PHP-файлов — ошибок нет до форматирования; после форматирования проверка повторена.
- Laravel Pint `--dirty`: 52 файла, форматирование успешно.
- PHPStan/Larastan по 9 ключевым runtime-файлам с `--memory-limit=1G`: ошибок нет.
- Container/route smoke с `APP_ENV=testing`, `YOOKASSA_MODE=mock`, `memory_limit=1G`: успешно; у `/api/v1/landing/modules` осталось 6 read-only/access routes.
- Поиск в `app`, `bootstrap`, `config`, `routes`, `database/seeders` не находит runtime-ссылок на старые модели, таблицы и сервисы.

Расширенный набор invitation/dashboard тестов был остановлен после более чем пяти минут работы без вывода; процесс продолжал потреблять CPU, assertion failure зафиксирован не был.

## Межрепозиторная проверка

Read-only поиск в техническом каталоге админки обнаружил оставшиеся старые вызовы в `src/services/billingService.ts`: `/billing/subscriptions/{id}/resume`, `/process-past-due`, `/sync-status`. Они находятся вне backend worktree и должны быть удалены задачей очистки админки перед общим merge.
