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

## Corrective review

- Отсутствовала высота этажа → добавлен закрытый путь `/floors/{floor_key}/height_m` и стабильное разрешение floor key.
- Разрешённые операции не имели полного поведенческого покрытия → `BuildingGeometryMutatorTest` реально применяет все 12 вариантов к нормализованной модели, проверяет evidence и чужой ключ.
- Повтор значения создавал новую историю → provisional normalized content сравнивается с locked head до evidence/model/outbox и отклоняется как no-op.
- Инвалидация была неполной → выделен `GeometryDependencyInvalidator`: recursive evidence closure, checkpoints, processing units, packages и items старой input version; исходные evidence не затрагиваются.
- Evidence не содержал полный provenance → фиксируются actor/time, операции/scale, source evidence IDs и old/new state/input/model versions; итоговая модель ссылается на user evidence.
- Не было общего лимита → до транзакции действуют 256 KiB raw/aggregate limits, 100 операций, 2 000 точек, 500 точек на polygon и закрытые scalar/string contracts.
- Ответ расходился со snapshot → контроллер использует `SessionOperationalSnapshotBuilder`, добавляет geometry extension и tenant-scoped `ETag`/`Cache-Control`.
- Outbox мог ложно стать delivered → dispatcher возвращает enqueue acknowledgement; false/exception переводит intent в recoverable failed. Recovery сообщает claimed/delivered/failed.
- Лог был недостаточным → добавлены exception, opaque failure ID, organization/project/session/actor и ограниченные metadata запроса.
- Оркестратор был перегружен → отдельно выделены typed mutator и dependency invalidator.

### Corrective verification

- DB-less behavioral/API/RBAC/building-model/retry suite: **127 passed, 459 assertions**.
- Larastan затронутого модуля: **No errors** (`--memory-limit=1G`).
- Pint: PASS; `git diff --check`: PASS.
- PostgreSQL opt-in inventory: `EstimateGenerationGeometryPostgresTest` (`postgres-contract`) проверяет composite tenant FK, outbox idempotency/claim index, immutable model/evidence triggers, rollback invariant и CAS-supporting unique indexes. Не запускался локально: требуется `RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1` и изолированный PostgreSQL; миграции/DB локально не запускались.
- Corrective commit: `a626cbcbf6b830974ff57d82a120b3fdcafa3e61` до технического amend отчёта.

## Second corrective review

- Legacy wall compatibility: `type` и `material` стали независимо optional при строгом required/allowed key contract; отдельные regression cases начинают с payload без обоих полей, изменяют каждое поле раздельно и подтверждают canonical roundtrip.
- Evidence ID: небезопасный `max(id)+1` удалён; production использует атомарный PostgreSQL sequence reservation, неподдерживаемый driver закрывается явной ошибкой без конкурентной коллизии.
- Package dependency: добавлена типизированная индексируемая колонка `estimate_generation_packages.input_version`, PostgreSQL constraint/backfill только для `estimate_generation_v2`, persistence заполняет колонку и metadata; invalidator использует точное равенство, произвольный recursive JSON scan удалён.
- Rollback seam: добавлен production-noop `GeometryConfirmationFaultInjector`; opt-in test внедряет сбой после staged invalidation и проверяет полный rollback evidence/model/state/outbox.
- PostgreSQL behavioral inventory расширен реальным admin POST, permission deny/allow, ETag/snapshot/history/outbox assertions, same-version winner/typed stale loser, idempotent append, false enqueue recovery и acknowledged delivery. Группа `postgres-contract` не запускалась локально согласно запрету.
- Auth/error matrix в opt-in endpoint test: foreign project 404; stale state/model/input 409; no-op, unsafe path, oversized body и applied lifecycle 422; ordinary estimate sentinel остаётся неизменным.
- PostgreSQL inventory: **7 test methods**, включая forked independent-process CAS loser, real post-invalidation fault rollback и recoverable outbox delivery. Статически проверены Pint, PHPStan и `php -l`; запуск PostgreSQL намеренно не выполнялся.

## Third corrective review

- Contention test теперь создаёт два независимых дочерних процесса/соединения. Winner останавливается barrier-инъектором после tenant/session/model locks, loser начинает запрос на исходных версиях и блокируется; после release фиксируются ровно один winner, typed stale loser, один state increment/model/evidence/outbox и отсутствие loser effects.
- Пустой rollback test заменён попыткой изменения immutable building model под транзакцией с обязательным trigger rejection и проверкой неизменного content version.
- Fixture содержит source evidence, pipeline root + transitive child edge, completed checkpoint, pending processing unit, exact-input package/item, different-input package и обычную estimate sentinel. Fault после staged invalidation сравнивает counts, state и полный derivative-state snapshot до/после rollback.
- Реальный endpoint inventory проверяет unauthenticated/denied, foreign organization/project 404, stale state/model/input, cancelled/applied lifecycle, no-op/unsafe/oversize, success ETag + Cache-Control + snapshot/geometry/invalidation payload, полную user-evidence provenance, immutable old history, точную invalidation и неизменную обычную смету.
- Outbox inventory проверяет duplicate append, false/throw acknowledgement, non-expired delivering lease, expired reclaim и acknowledged exactly-once delivered transition. PostgreSQL/PCNTL guards разделены.
- Отдельный outbox contention test создаёт один pending intent и два синхронизированных процесса/соединения перед CAS claim; durable audit dispatcher подтверждает ровно один enqueue, результаты claim `[false,true]`, итоговый status `delivered` и отсутствие duplicate job.
- Локально: **104 passed, 303 assertions** для DB-less geometry/building-model/package/RBAC набора; Pint PASS, PHPStan No errors, `php -l` PASS, diff-check PASS. PostgreSQL/PCNTL inventory не запускался по явному запрету.
- Итоговый PostgreSQL inventory: **8 реальных test methods**, не запускался локально; причина — требуется явный `RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1`, изолированный PostgreSQL и PCNTL/Unix sockets для двух contention cases.
