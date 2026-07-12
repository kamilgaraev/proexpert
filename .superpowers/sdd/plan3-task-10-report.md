# Plan 3 — Task 10: versioned learning datasets и benchmark runs

## Итог после полного ревью

Контракт AI Addons закрыт на application и PostgreSQL уровнях. Обычные сметы, CBM и их таблицы не изменялись.

- `recordApprovedExample` повторно загружает dataset по точным `id/organization_id/dataset_key/version`, а example — только через tenant-scoped relation. PostgreSQL composite FK закрепляет membership `(training_dataset_id, organization_id, dataset_version)`.
- Learning запись использует точную организацию и source identity проверенного example. Acceptance/regression остаются benchmark-only; обучение разрешено только approved development.
- `complete` и `fail` требуют `organizationId`, а terminal row блокируется по `(organization_id, uuid)`; чужой UUID возвращает domain 404 через `ModelNotFoundException`.
- `start` сериализован advisory transaction lock по `(organization_id, idempotency_key)`: тот же exact manifest возвращает один run, изменение любого pin/currency/dataset вызывает `benchmark_idempotency_manifest_conflict`.
- Версия dataset сериализована advisory transaction lock по `(organization_id, dataset_key)`.
- Inline results — непустой list, максимум 1000 cases, максимум 32 поля на case, максимум 1 MiB, recursive sensitive-key rejection. S3 result требует tenant prefix, реального чтения через `BenchmarkPrivateObjectStore`/`FileService`, точных size и SHA-256; local storage отсутствует.
- Migration `001800` меняет только AI training FK на `RESTRICT`, запрещает delete approved/archived dataset и каскад организации, разрешает только status-only `approved -> archived` и закрывает completed/failed/running CHECK без NULL/3VL лазеек. Manifest trigger теперь включает idempotency key и currency.
- Filament process доступен только для draft; terminal delete скрыт и запрещён resource policy; дублированный status удалён.
- Source-string tests удалены. Контракт доказывается behavior tests и реальным PostgreSQL.

## Проверки

- DB-less: `php artisan test tests/Unit/EstimateGeneration/Training tests/Feature/EstimateGeneration/Benchmark --exclude-group=postgres-contract` — **15 tests / 36 assertions, PASS**.
- PostgreSQL `_contract`, первый прогон `001700 + 001800`: **1 test / 31 assertions, PASS**.
- PostgreSQL `_contract`, второй последовательный прогон на той же БД: **1 test / 31 assertions, PASS**.
- Оба literal contention gate используют два `proc_open` процесса и pipe coordination `LOCKED/CONTINUE/DONE`, без sleeps: benchmark first-start и dataset version allocator.
- PHPStan по изменённым production-классам: **PASS, no errors**.
- Pint по изменённым PHP-файлам и `git diff --check`: **PASS**.
- Production migrations не запускались; использовалась только disposable БД `most_ai_estimator_contract`.

## Открытые замечания

Нет.
