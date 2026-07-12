# Plan 3 Task 9 — отчёт

## Результат

Для AI-смет МОСТ добавлен обязательный immutable price snapshot на границе расчёта цены. Цена принимается только при точном совпадении `region_id + price_zone_id + period_id + regional_price_version_id`; поиск соседней, первой или последней цены отсутствует. Несовпадение очищает устаревшее ценовое доказательство и итог позиции, создавая blocking issue `missing_price_snapshot`.

## Файлы

- `app/BusinessModules/Addons/EstimateGeneration/Pricing/PriceSnapshotData.php`
- `app/BusinessModules/Addons/EstimateGeneration/Pricing/ResolveRegionalPrice.php`
- `app/BusinessModules/Addons/EstimateGeneration/Pricing/MissingRegionalPrice.php`
- `app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php`
- `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/ResolvePricesStage.php`
- `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSelectionService.php`
- `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php`
- `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPackageItem.php`
- `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001100_add_price_snapshots_to_estimate_generation_package_items.php`
- `tests/Unit/EstimateGeneration/Pricing/ResolveRegionalPriceTest.php`
- `tests/Feature/EstimateGeneration/Pricing/EstimateGenerationPriceSnapshotTest.php`

Обычные сметы и `BusinessModules/Features/BudgetEstimates` не изменялись.

## Design

`ResolveRegionalPrice` выполняет exact lookup по идентификатору ресурсной цены и четырём измерениям регионального контекста. Результат преобразуется в readonly `PriceSnapshotData` с decimal strings, валютой, источником и временем фиксации.

Package item хранит один утверждённый агрегированный `PriceSnapshotData`: `base_amount` равен сумме зафиксированных ресурсных стоимостей, `final_amount` равен итогу позиции, `source_reference` является SHA-256 манифеста источников. `coefficients.resource_evidence` содержит полные immutable resource snapshots, а `coefficients.work_cost` фиксирует вычисленную составляющую работы. Это делает агрегат доказательным и воспроизводимым без повторного чтения изменяемого справочника.

`ResolvePricesStage` и ручной выбор норматива передают фактический regional context в pricing boundary. При его изменении snapshot пересобирается; mismatch приводит к новому output payload и тем самым меняет output/dependency version downstream checkpoint по утверждённому pipeline contract Task 8.

Миграция добавляет nullable `jsonb` только потому, что unpriced/blocking item семантически может не иметь price snapshot. PostgreSQL constraints `NOT VALID` не проверяют исторические строки при expand rollout, но запрещают новые priced writes без snapshot и новые snapshot с незакрытой/невалидной формой. Добавлены partial indexes exact context/version и source reference.

## RED evidence

Команда:

`vendor\\bin\\phpunit tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing`

Результат: exit 1; 3 errors из-за отсутствующего `App\\BusinessModules\\Addons\\EstimateGeneration\\Pricing\\ResolveRegionalPrice`. Это ожидаемая причина отсутствия новой функциональности.

## GREEN evidence

- Focused + pure pipeline: `vendor\\bin\\phpunit tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing tests/Unit/EstimateGeneration/Pipeline/PipelineStageFunctionalTest.php` — PASS, 5 tests, 35 assertions.
- `php -l` для 11 изменённых/новых PHP-файлов — PASS.
- `vendor\\bin\\pint --test ...` — PASS.
- Targeted Larastan: `vendor\\bin\\phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Pricing app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPackageItem.php app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/ResolvePricesStage.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSelectionService.php --memory-limit=1G --no-progress` — PASS, no errors.
- `git diff --check` — PASS.

## Self-review

- Нет latest/first/neighbor fallback в новом resolver.
- Нет AI-generated prices.
- Snapshot сохраняется непосредственно с AI package item и не пересчитывается при чтении.
- Смена региона удаляет stale snapshot и priced draft state.
- DB boundary запрещает новые priced items без validated closed-shape snapshot.
- Явные migration/DB-команды и любые production-команды не запускались; непреднамеренный локальный test bootstrap раскрыт ниже.
- `.cbmignore` и `.codebase-memory` не изменялись и не staging-ились.

## Concerns

При первом запуске плановой команды `php artisan test` существующий project test bootstrap автоматически выполнил миграции в локальной тестовой среде до запуска новых тестов. Production не затронут. После обнаружения новые тесты переведены на чистый `PHPUnit\\Framework\\TestCase`, а все доказательные прогоны выполнены через DB-less `vendor\\bin\\phpunit`. Полный набор старых EstimateGeneration-тестов не запускался, поскольку часть из них наследует project `Tests\\TestCase` с тем же автоматическим DB bootstrap; вместо этого выполнены focused tests, pure pipeline regression и статические gates.

## Corrective review wave

Reviewer findings устранены одной TDD-волной после commit `9a5f3413`.

### RED

- `vendor\\bin\\phpunit tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing` — 2 failures: недоверенный `resource.total_price=0.01` попадал в snapshot вместо exact catalog result `0.30`; пустой regional context сохранял priced total `295` вместо fail-closed zero.
- PostgreSQL opt-in до migration: `RUN_POSTGRES_PRICE_SNAPSHOT_CONTRACT=1 php artisan test tests/Feature/EstimateGeneration/Pricing/EstimateGenerationPriceSnapshotPostgresTest.php` — expected failure `Undefined column price_snapshot` на реальной Task 8 schema.
- Первая попытка Task 9 migration на disposable DB выявила SQL defect precedence для JSONB subtraction; migration transaction откатилась. Скобки исправлены, повторное применение прошло.
- Covering `ResourceAssemblySafetyTest` после fail-closed изменения дал 8 устаревших expectations, допускавших priced/review state без регионального snapshot; expectations переведены на обязательный blocker.

### Исправления

- Единственный источник unit price — exact catalog `base_price`; входные `unit_price` и `total_price` не доверяются и переписываются.
- Все денежные вычисления переведены на `Brick\\Math\\BigDecimal` с явным `RoundingMode::HalfUp`; snapshot/work item/persistence передают decimal strings.
- Любой priced item без полного exact context/evidence fail-closed: costs, source и snapshot очищаются, blocker `missing_price_snapshot`.
- PostgreSQL CHECK через immutable validator function проверяет closed aggregate/resource evidence, integer IDs, whitelists, SHA-256/source references, decimal/timestamp formats, nonempty evidence и равенство `snapshot.final_amount = total_cost`. `NOT VALID` сохраняет expand rollout и запрещает новые invalid writes.
- Настоящий opt-in PG contract создаёт package item через Eloquent, меняет catalog после capture, доказывает неизменность persisted snapshot/total и отклонение missing/forged evidence.
- Реальный pipeline lineage test доказывает изменение `PipelineStageOutput.version` ResolvePrices и downstream `PipelineInputVersion` BuildDraft при изменении regional context.

### GREEN evidence

- DB-less pricing/checkpoint/pipeline + ResourceAssembly regression: `20 tests, 137 assertions`, PASS.
- Disposable PostgreSQL contract, run #1: `1 passed, 4 assertions`; immediate run #2 on same database: `1 passed, 4 assertions`.
- Task 9 migration applied only to `most_ai_estimator_contract` with `_contract` guard/environment; production untouched.
- Targeted Larastan/PHPStan: no errors.
- PHP syntax: all 10 corrective PHP files pass.
- Pint and `git diff --check`: PASS after formatting.

## Corrective review wave 2

### RED

- Strict ID contract: decimal, exponent, sign, leading zero and overflow reached lookup (5 expected failures).
- PostgreSQL shape validation was fail-open because missing JSON keys produced `NULL` accepted by `CHECK`, while empty evidence skipped the validator loop.
- Restored positive ResourceAssembly scenarios were `not_calculated`: assembled resources store `price_id` in `normative_ref`, but the resolver read only a root field.
- Five domain scenarios proved that their original blockers must remain visible instead of being replaced by `missing_price_snapshot`.

### Design

- Top-level, coefficients and resource keys are mandatory via JSONB `?&`; types are exact, evidence is nonempty, and critical predicates are fail-closed with `IS TRUE`. `eg_price_resource_evidence_valid(jsonb)` is `STRICT IMMUTABLE` and returns false on errors.
- PostgreSQL reproduces the money contract: resource final is `round(base × quantity, 2)`, aggregate base is the rounded sum of resource finals, aggregate final is `base + work_cost` with agreed rounding.
- PHP and PostgreSQL use the same canonical manifest bytes: lexicographically sorted `source_reference` values joined with `|`; SHA-256 is calculated from that UTF-8 string.
- `positiveInt` accepts only positive PHP integers or canonical `^[1-9][0-9]*$` strings within `PHP_INT_MAX`.
- Resolver supports both direct resource `price_id` and the real assembled `normative_ref.price_id`. Controlled exact resolver/context/catalog fixtures restore positive safety behavior; fail-closed tests remain separate and retain domain blockers.

### PostgreSQL evidence

- Only disposable `most_ai_estimator_contract` Task 9 objects/column were reset, then the real migration was reapplied; production was untouched.
- Invalid-write matrix rejects missing snapshot, `{}`, empty/null/[{}] evidence, absent keys/wrong types, fractional IDs, resource arithmetic mismatch, aggregate base/work/final mismatch and wrong hash.
- Valid persisted history survives catalog mutation.
- Exact opt-in contract ran twice on the same database: PASS, `1 test, 13 assertions` each run.

### GREEN

- Strict identifier contract: `9 tests, 16 assertions`, PASS.
- ResourceAssembly + pricing covering regression: `26 tests, 123 assertions`, PASS; one expected opt-in PostgreSQL skip in the DB-less run.

## Final architecture hardening

- Removed the unversioned `labor + 18% materials` formula. Work, overhead and profit are zero; totals contain only exact catalog-backed normative resources.
- Reused immutable `estimate_generation_evidence` nodes of type `work_item` for authoritative quantity. PostgreSQL verifies ID, fingerprint, active state and organization/project/session scope through package lineage.
- Added follow-up migration `2026_07_12_001200_harden_estimate_generation_pricing_boundary.php`; migration `001100` was not rewritten.
- Added immutable versioned unit conversions and normalized price inputs (`package item + norm resource + exact regional price + optional conversion`). Identity requires equal units and no conversion row.
- `eg_finalize_package_item_price(id)` is the authoritative builder. It independently calculates quantity, price, zero work/overhead/profit, snapshot and SHA-256. A deferred trigger repeats scope, complete norm resource set, exact active context/version, positive-price, conversion and arithmetic checks.
- Activated catalog prices, conversions, normalized inputs and priced facts are immutable. Package items use append-only `logical_key + revision + supersedes_item_id`; changed context/norm/quantity/price creates a revision.
- Package total accumulation uses `Brick\\Math\\BigDecimal`, not float.

### Final RED/GREEN evidence

- RED: catalog-only expectation `250.00` failed with old undocumented result `295.00`.
- GREEN: focused DB-less pricing and migration suite: `16 tests, 50 assertions`.
- Disposable `most_ai_estimator_contract`: the real follow-up migration applied, rolled back, reapplied, then an immediate second apply returned `Nothing to migrate`.
- The old PostgreSQL scenario is now correctly rejected at its attempted UPDATE of an activated price row; new prices require a new version.
- Production was untouched. Ordinary estimates and `BusinessModules/Features/BudgetEstimates` were not modified. Codebase-memory artifacts remain untracked and unstaged.
- PostgreSQL DB-authoritative contract expansion (2026-07-12): opt-in contract now creates real organization/project/session/package lineage, normative catalog/resource, active exact regional version, immutable quantity evidence, versioned conversion and normalized package-item price input. It calls `eg_finalize_package_item_price` and proves DB-built quantity, unit price, direct/total money, context, zero work cost and exact canonical SHA-256; client money is overwritten.
- Mutation coverage rejects snapshot, money, context, quantity/evidence-link changes; price-input UPDATE/DELETE; evidence mutation; active price UPDATE/DELETE/INSERT; conversion UPDATE/DELETE; missing inputs/conversion; cross-context and foreign/fingerprint-mismatched evidence. Append-only revisions and historical totals are checked.
- RED exposed that an activated regional catalog rejected UPDATE/DELETE but accepted INSERT. Follow-up `2026_07_12_001300_close_activated_pricing_catalog_insert_boundary.php` closes all three mutation paths while preserving unversioned rows.
- Clean disposable bootstrap exposed invalid PostgreSQL operator precedence around dynamic `CASE` JSONB keys in the existing evidence migration. Parentheses now allow the real evidence schema to be created without weakening its contract.
- Exact contract passed twice consecutively on the same `most_ai_estimator_contract`: `1 test, 35 assertions` per run. Production was untouched.
- Mandatory matrix completion: separate inactive catalog versions are populated with `NULL`, zero and negative base prices before activation; every finalized item fails closed. A two-resource norm proves omitted resource rejection, a foreign-norm third input proves extra-input rejection, and a same-context price for the other resource proves price-to-norm mismatch rejection.

## Final integration review wave (2026-07-12)

- Quantity evidence is created upstream in `PlanWorkItemsStage` through the immutable `EvidenceRepository`; tenant/project/session scope, ID and fingerprint travel unchanged to persistence.
- `syncFromDraft()` discards client money/snapshot, writes an unpriced append-only revision and normalized exact inputs, then invokes the PostgreSQL finalizer. Tampered quantity with an original fingerprint remains unpriced and blocking; persistence cannot mint replacement evidence.
- Follow-up `001400` adds explicit finalization state, closes input mutations, reconstructs the complete expected snapshot and money, hashes full normative/resource/conversion values, protects activated versions and finalized references, and secures functions with `SECURITY DEFINER`, fixed `search_path` and revoked PUBLIC execution.
- Revision allocation is serialized by a package row lock and protected by `(package_id, logical_key, revision)` uniqueness. The `001200` rollback restores `(package_id, key)` uniqueness.
- Disposable PostgreSQL contract invokes the real service, rejects forged priced state and protected mutations, and inspects `pg_proc` ownership/ACL security.
- A literal two-process/two-connection contention test coordinates through a pipe signal without sleeps: the leader holds the package row lock, the follower overlaps, and both complete without a unique failure or lost update. Exactly revisions `[1, 2]` remain with the correct `supersedes_item_id` chain and deterministic final money.
- The complete PostgreSQL contract passed twice consecutively on the same database: `2 tests, 71 assertions` per run.
- DB-less covering suite passed: `31 tests, 176 assertions`; PHP syntax, Pint, targeted Larastan and `git diff --check` passed. Ordinary estimates were untouched and codebase-memory artifacts remained unstaged.
- Two append-only revisions with `12345.678901 × 123456789.1234` prove exact PostgreSQL `numeric` item money and latest-revision package accumulation. The expected values are independently calculated with `Brick\\Math\\BigDecimal`; no float enters the assertion.
