# Plan 3 / Task 6 — отчёт реализации

## Статус

Реализован API-контракт подтверждения геометрии с закрытой типизацией операций, tenant/project scope, optimistic concurrency, append-only моделью, пользовательским evidence и отдельным durable regeneration outbox. Обычные сметы и `ApplyGeneratedEstimate` не затронуты.

## RED / GREEN

- RED: `php artisan test tests/Unit/EstimateGeneration/Geometry/GeometryConfirmationCommandTest.php` — 2 FAIL, ожидаемая причина: отсутствовал `GeometryConfirmationCommand`.
- GREEN: тот же тест — 2 PASS, 2 assertions.
- Финальный DB-less набор: 11 PASS, 26 assertions.

## Реализация

- `GeometryConfirmationCommand` закрывает операции, пути и значения; запрещает JSON Pointer escapes, неизвестные поля, пустые и чрезмерные команды, нечисловые/бесконечные координаты и вырожденный масштаб.
- `ConfirmBuildingGeometry` блокирует tenant-scoped session/head, повторно проверяет state/input/model версии и lifecycle, применяет операции по стабильным ключам, прогоняет `NormalizedBuildingModelData`, создаёт append-only модель и user-input evidence, инвалидирует pipeline evidence/checkpoints старой input version.
- Контроллер возвращает `AdminResponse`, не раскрывает исключения, 404 не перечисляет чужие сущности, 409 обозначает stale CAS.
- Маршрут использует существующее право `estimate_generation.review`; добавлены русские переводы.
- В той же транзакции создаётся дедуплицированный pending intent; доставка выполняется только после commit. CAS-claim защищает от двойной доставки, failed/expired delivery восстанавливается плановой командой каждую минуту.

## Проверки

- DB-less API/command tests: 11 passed (26 assertions): route/RBAC/order, request/translation/response/outbox contracts, closed operations, unsafe pointer/value cases и idempotency key.
- RBAC/module/permission translation contracts: 46 passed (229 assertions).
- Larastan/PHPStan затронутых файлов: `[OK] No errors` с `--memory-limit=1G` (первый запуск при 128M исчерпал память).
- Pint: PASS после исправления 2 style issues.
- `php -l`: PASS для 5 новых PHP-файлов.
- `git diff --check`: PASS.
- PostgreSQL opt-in contention/rollback тесты не запускались; локальные миграции и production DB не использовались намеренно. Один ранний запуск Laravel `TestCase` активировал встроенные test-environment migration hooks; тест переведён на чистый DB-less `PHPUnit TestCase`, production не затронут.

## Ограничения и риски

- PostgreSQL CAS contention, immutable trigger и rollback-пути покрываются существующим opt-in inventory группы `postgres-contract`; локально не запускались по запрету задачи.

## Commit

`3b2d9f99c72bd8dc94d7ad9532eba072be4f5b7d` (до технического amend отчёта).
