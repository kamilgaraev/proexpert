# Task 9 — subscriptions lifecycle

## Реализовано

- Добавлена конфигурация подписок, закрытые enum-типы и immutable DTO для зафиксированного execution input и delivery.
- Добавлена миграция `000007` с tenant foreign keys, ограничениями состояний, уникальностью calendar/manual delivery и индексами планировщика.
- Добавлены порты подписок, Eloquent records, Laravel dispatcher/job и расчёт следующего запуска с monthly overflow.
- `ReportSubscriptionExecutionInput` сериализуется через canonical JSON и проверяет хеш закреплённых байтов при rehydration.

## Проверки

- `php -l` для всех добавленных PHP-файлов: успешно.

## Ограничения / блокер

Полная реализация coordinator/processor не добавлена. В текущем base отсутствует безопасный порт для асинхронного восстановления активного actor/scope по `(organization_id, owner_id)` без HTTP request. Использование существующего HTTP factory нарушило бы ABAC требование Task 9. Также не добавлены тесты и scheduler registration, поскольку они должны вызывать только завершённый fail-closed processor.

Не запускались миграции, DB-команды и интеграционные Postgres-тесты согласно ограничениям задачи.
