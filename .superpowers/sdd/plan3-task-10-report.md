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

## Final architecture hardening 002100

Status: **DONE — 0 open findings**.

- Queue dispatch leaves the dataset in `draft`; the unique job atomically claims `draft -> processing` with a lease token, expiry and attempt. Middleware supports releases/retries, and a scheduled reclaimer CAS-resets exact expired leases and redispatches them.
- Application approval and PostgreSQL constraint triggers require a nonempty corpus whose every example is accepted and has a complete `reviewed_by/reviewed_at` pair, plus a complete dataset `approved_by/approved_at` pair. Late inserts and mutations of approved examples are rejected.
- Benchmark objects are written only through `FileService` using conditional S3 `PutObject` with `If-None-Match: *`. PutObject ETag/VersionId are authoritative; 409/412 conflicts read the existing object and require exact hash/size/content-type equality. Missing client capability fails closed. A newly created run-owned object is removed if its DB transition fails.
- Fresh DB-less gate: **90 tests / 293 assertions, PASS**. PostgreSQL 002100 contract with down/up, adversarial approval and expired-lease recovery: **1 test / 45 assertions, PASS twice consecutively on the same disposable database**. Targeted PHPStan, Pint and `git diff --check`: PASS. Production migrations were not executed.

## Edge-hardening 001900

- Terminal retry сравнивает канонический полный payload; совпадение возвращает исходную запись и timestamp, расхождение даёт `benchmark_terminal_payload_conflict`.
- External result закреплён immutable content-addressed S3 key с UUID run и SHA-256; в строке хранятся size, checksum, ETag/version и content type.
- `001900` закрывает JSONB null/3VL, inline/external/failed/running storage invariants, immutable manifest/result metadata, gap-free dataset chain и reviewed-example content immutability.
- `appendVersion` повторно загружает exact source под lock. Processing принимает только CAS-claimed `processing`; stale review job отклоняется, processing failure становится `rejected`.
- Approved learning error paths больше не меняют example. Privacy gate нормализует sensitive keys и ограничивает depth/node count.
- DB-less: 18 tests / 39 assertions. PostgreSQL: 32 assertions, два последовательных down/up цикла на disposable БД. PHPStan, Pint и `git diff --check`: PASS.
