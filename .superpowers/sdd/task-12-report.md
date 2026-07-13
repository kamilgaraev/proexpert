# Task 12 — Filament usage, errors и queue resources AI-сметчика МОСТ

Статус: `DONE_WITH_CONCERNS`

## Реализовано

- Добавлены отдельные read-only Filament-ресурсы затрат, ошибок и pipeline checkpoints в группе AI-сметчика с сортировкой `3/4/5`, ограниченной пагинацией `25/50/100` и индекс-адресуемыми scalar-фильтрами.
- Usage выбирает только идентификаторы scope/session и безопасные поля provider/model/stage/tokens/images/pages/duration/attempt/status/snapshot cost/currency. `price_snapshot` и provider payload не выбираются.
- Failure выбирает строгую базовую проекцию и извлекает из `safe_context` только 12 закрытых scalar-ключей. `FailureDiagnosticsPresenter` повторно проверяет имя, формат и диапазон каждого значения; произвольные ключи, prompt, request/response, headers, credentials и stack trace не попадают в UI.
- Checkpoint выбирает только безопасное состояние очереди. Output payload, metrics, warnings, claim token, error message/fingerprint не выбираются и не отображаются.
- Все три ресурса доступны по `estimate_generation.monitor`, не имеют create/edit/delete/bulk mutation. Операционные actions отдельно требуют `estimate_generation.operate`.
- Retry/cancel checkpoint не меняют checkpoint или очередь из Filament callback и делегируют принятому `OperateEstimateGenerationSession` с tenant/state/idempotency/audit guards.
- Mark-resolved делегирует новому `ResolveEstimateGenerationFailure`. Сервис повторно проверяет permission, UUID/tenant scope, ожидаемую latest occurrence sequence и наличие ровно той active occurrence, затем append-only добавляет resolution event и audit event. Идемпотентный повтор возвращает сохранённый результат без второго resolution event.
- Обычные сметы не изменялись.

## TDD и privacy

- RED: целевой DB-less запуск остановился на отсутствующем `AdminFailureResolutionAuthorizer`, что подтвердило новую application-service границу.
- GREEN: focused Task 12 suite — `14 tests / 124 assertions`.
- Privacy fixture содержит production-shaped `Authorization`, `api_key`, cookie/header, prompt, request/response body, stack trace, произвольный HTML и секрет в allowlisted key; presenter возвращает только валидные `timeout`, `5xx`, `504`, `attempt=3`.
- Расширенная Filament regression включает существующую матрицу RBAC Task 10, dashboard/session Task 11 и новые resources: `66 tests / 555 assertions`.

## Gates

- Targeted PHPStan/Larastan с `memory_limit=1G`: `[OK] No errors`.
- Pint: все затронутые PHP-файлы проходят.
- `php -l`: все затронутые PHP-файлы без синтаксических ошибок.
- Class-load smoke: ресурсы и application services загружаются.
- `git diff --check`: чисто.
- Миграции, seeders, tinker, RefreshDatabase и любые тесты/команды с подключением к БД не запускались.

## Concern

Фактические PostgreSQL query plans и конкурентное добавление resolution event локально намеренно не проверялись из-за DB-less ограничения задачи. Перед production-включением требуется staging/production-sized gate: проверить `EXPLAIN (ANALYZE, BUFFERS)` для периодных и tenant/status фильтров и конкурентный сценарий «новая occurrence между открытием страницы и подтверждением». Ожидаемая sequence и блокировка identity должны вернуть state conflict либо создать ровно одно resolution event.
