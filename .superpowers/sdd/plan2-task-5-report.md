# Plan 2 Task 5 — отчёт реализации

## Реализовано

- Добавлен immutable usage ledger МОСТ с tenant-scoped schema, canonical attempt/correlation UUID, idempotent insert и collision rejection.
- Добавлен decimal-string BCMath calculator: cached subtraction, reasoning modes, image/page units, half-up scale 8, missing tariff и overflow guards.
- Добавлены strict operation/price/rerank DTO без prompt, body, filename, path, secrets, PII и document content.
- OCR `Http::retry` заменён explicit wire loop. Каждая HTTP/connection/malformed/success попытка записывается отдельно; retry ограничен connection/408/429/5xx, terminal request/auth errors не маршрутизируются на следующую модель.
- Reranker переведён на EstimateGeneration-owned single-model wire client; shared AI Assistant provider/fallback не используется для paid reranking.
- Unit и whole-document OCR получают strict tenant/session context; unit attempt identity включает claim token и attempt count.
- Generic `UsageTracker` и `OcrUsageLogger` удалены из EstimateGeneration accounting.
- Добавлен opt-in PostgreSQL contract test; локально не запускался согласно запрету на DB-команды.

## TDD evidence

- RED: `php artisan test tests/Unit/EstimateGeneration/Observability/AiCostCalculatorTest.php tests/Unit/EstimateGeneration/Observability/AiUsageAttemptExecutorTest.php` — 5 expected failures: отсутствующие production classes.
- GREEN/final: `php artisan test tests/Unit/EstimateGeneration/Observability tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php tests/Unit/EstimateGeneration/Pipeline` — 80 passed, 300 assertions.
- `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration --memory-limit=1G --no-progress` — exit 0, no reported errors.
- `vendor/bin/pint --dirty` — 34 files checked, 9 style issues fixed.
- `php -l` для всех changed/untracked PHP — no syntax errors.
- `git diff --check` — exit 0.

## Не запускалось

- Миграции и любые локальные DB-команды.
- `tests/Feature/EstimateGeneration/EstimateGenerationUsageLedgerPostgresTest.php` — только opt-in PostgreSQL environment.

## Review concern

- PostgreSQL contract test после corrective pass использует disposable tenant/session/document/page/unit fixture, две независимые connection, committed-winner barrier, idempotent no-op второй записи и session-cascade cleanup. Отдельный focused test покрывает collision, tenant nullable bypass, UUID, counters, ordinal, status/HTTP, image/detail, pricing/snapshot, UPDATE, direct DELETE и lifecycle cascade через savepoints.

## Corrective pass после финального review

- `page_id` добавлен end-to-end: DTO fingerprint, model relation/cast, store, composite tenant FK/index/check и rollback order.
- Весь recorder measurement construction OCR/reranker помещён в безопасную границу: ошибка context/counters/snapshot/DTO/store/logging не маскирует provider result/error.
- `usage_available=false` сохраняется как `unavailable` с нулевыми counters и NULL historical cost, а не как измеренный бесплатный вызов.
- Reranker missing/invalid JSON и reported-model mismatch классифицируются как ровно одна `malformed_response` попытка.
- OCR принимает только strict JSON; arbitrary non-empty text и model mismatch — malformed с отдельной строкой и контролируемым route fallback.
- `AiOperationContext` проверяет optional scope IDs и совместимость stage/operation.
- Fresh final DB-less gate: `92 passed (352 assertions)`.
- Fresh targeted/full module PHPStan с `--memory-limit=1G`: exit 0, no reported errors.
- Fresh Pint: 13 files, 5 style issues fixed; последующий `php -l` и `git diff --check` — exit 0.
- PostgreSQL opt-in suite не запускался локально согласно запрету.

## Second corrective pass

- Page-unit execution now reserves the authoritative `estimate_generation_document_pages` row with Laravel 11 `createOrFirst`, compare-and-set links `processing_unit_id`, verifies tenant/session/document/unit scope, and passes the real page ID through `DocumentUnitExecutionContext` and OCR operation context. Whole-document OCR remains explicitly page-null.
- Physical invocation identity is fresh for every actual OCR/reranker client call. Attempt UUIDs derive from physical invocation + model/wire ordinal; logical correlation remains stable for the claim. Replaying an actual paid invocation therefore creates distinct attempt IDs, while retrying the same immutable DTO remains idempotent.
- PostgreSQL contention actors now both call `EloquentAiUsageStore::record()` on independent connections with identical `AiUsageData`; the first commits, the second unblocks successfully, and the contract asserts one row/fingerprint.
- PG matrix expanded for stage/operation, status/HTTP, UUID, counters, image detail, required counter tariffs, snapshot decimals/currency, provider/model identifiers, page/document mismatch, decimal overflow, collision, direct mutation and lifecycle cascade. Fixture IDs are registered incrementally and cleaned after partial setup failure.
- Fresh final DB-less gate: `112 passed (437 assertions)`.
- Fresh module/test PHPStan with `--memory-limit=1G`: `[OK] No errors`.
- Fresh Pint: 11 files, 2 style issues fixed; post-format `php -l` and `git diff --check` succeeded.
- PostgreSQL opt-in suite was authored/static-checked only and was not executed locally.

## Third corrective pass

- `executionContext()` now runs claim revalidation and page reservation in one DB transaction. The processing unit is locked with `lockForUpdate`; current document/source, Running status, token, tenant scope and live lease are checked immediately before any page query/write.
- Lost, expired, replaced-source or status-changed ownership throws typed `unit_claim_lost`; pure guard tests cover all four states before reservation.
- Existing populated or lineage-bearing pages are locked and preserved without mutation, then rejected with typed `unit_page_lineage_conflict` before paid OCR. The caller must use the existing source-replacement/evidence invalidation workflow; no evidence is cleared directly.
- Empty page reservations alone may attach the current processing unit/source. Reservation conflicts throw `unit_page_reservation_conflict`.
- Publish locks unit and page in one transaction and only writes an exact empty reservation owned by the same unit/source; lineage presence aborts publication without page/evidence mutation.
- Fresh final DB-less gate: `121 passed (462 assertions)`.
- Fresh focused PHPStan 1G: `[OK] No errors`; Pint checked 7 files and fixed 3 style issues; post-format PHP syntax and `git diff --check` succeeded.

## Fourth corrective pass

- Page reservation keeps the unit-first lock order and uses Laravel `createOrFirst()` for an absent page, then reselects the winning row with `lockForUpdate`. A concurrent loser therefore validates the committed winner and receives the typed `unit_page_reservation_conflict` without leaking a unique-constraint error or mutating the page.
- Reservation eligibility is represented by an explicit positive state containing every persisted page-result field: output version, dimensions, rotation, languages, text/hash, confidence, raw payload path, normalized payload, quality flags and lineage. Meaningful falsey values such as zero dimensions, rotation and confidence are protected as existing results.
- A pristine reservation owned by the exact same unit and source remains idempotently valid; a different unit or source is rejected with a typed conflict.
- Fresh final DB-less gate: `130 passed (480 assertions)`.
- Fresh focused PHPStan with `--memory-limit=1G`: exit 0, no reported errors. Pint checked 5 files and fixed 1 style issue; post-format PHP syntax and `git diff --check` succeeded.
- PostgreSQL opt-in tests and migrations were not executed locally.
