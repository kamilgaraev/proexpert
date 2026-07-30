# Task 9 — subscriptions lifecycle

## Реализовано

- Добавлена конфигурация подписок, закрытые enum-типы и immutable DTO для зафиксированного execution input и delivery.
- Добавлена миграция `000007` с tenant foreign keys, ограничениями состояний, уникальностью calendar/manual delivery и индексами планировщика.
- Добавлены порты подписок, Eloquent records, Laravel dispatcher/job и расчёт следующего запуска с monthly overflow.
- `ReportSubscriptionExecutionInput` сериализуется через canonical JSON и проверяет хеш закреплённых байтов при rehydration.

## Проверки

- `php -l` для всех добавленных PHP-файлов: успешно.

## Продолжение реализации

- Добавлен `ReportSubscriptionExecutionContextFactory`: он вызывает async `CurrentReportScopeAuthorizer::authorizeForOrganization`, а затем стандартный `ReportExecutionContextFactory::fromCurrentAuthorization`; HTTP request не используется.
- Добавлены Eloquent stores, jobs планирования/истечения/очистки, регистрация в `routes/console.php` и bindings provider.
- Планировщик выбирает due-подписки под `FOR UPDATE SKIP LOCKED`, закрепляет байты/хеш/версию delivery, меняет next-run в одной транзакции и dispatch выполняет после commit.
- Processor использует закреплённые bytes для run/export, ограничивает polling интервалом конфигурации и выполняет повторные попытки.

## Ограничения / блокер

Не реализованы Coordinator CRUD/manual run и durable notifier/receipt persistence. Для полной exactly-once нотификации требуется отдельная durable таблица receipt либо расширение notifications-модуля с уникальным idempotency hash; текущая миграция уже содержит уникальный notification key на delivery, но это не заменяет receipt при crash между внешней доставкой и записью delivery.

Также не добавлены целевые тесты/PHPStan/Pint: текущий блок требует доведения доменных переходов и notifier до завершённого контракта, иначе эти проверки не дали бы достоверного подтверждения полной lifecycle-семантики.

Не запускались миграции, DB-команды и интеграционные Postgres-тесты согласно ограничениям задачи.
