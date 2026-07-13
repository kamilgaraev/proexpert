# Plan 3 final F2 — online migrations report

## Status

`DONE`

## Scope and phases

Scope was expanded to the complete AI-estimator training chain `001700`–`002200` and its isolated tests/provisioner. Ordinary estimates are untouched.

Implemented so far: non-transactional migration execution, bounded lock/statement timeouts, stable primary-key batches without OFFSET, validated helper CHECK constraints before `SET NOT NULL`, concurrent indexes, and replacement FK add/validate/drop/rename ordering.

## Lock analysis

- Nullable column additions require a short `ACCESS EXCLUSIVE` lock bounded by `lock_timeout`.
- `ADD CHECK/FK ... NOT VALID` avoids scanning existing rows; validation is a separate statement with bounded `statement_timeout`.
- `SET NOT NULL` follows a validated `CHECK (column IS NOT NULL)` to avoid another table scan, while its short catalog lock remains bounded.
- `CREATE INDEX CONCURRENTLY` runs outside Laravel migration transactions.
- Restrictive replacement FKs are added and validated before the legacy FK is removed, so referential protection has no gap.

## Rollback boundary

After production writes use versioned membership and benchmark contracts, lossless downgrade to pre-`001700` is impossible. Migration `002200` now closes the supported boundary by failing explicitly with `estimate_generation_training_benchmark_migration_is_forward_only` before any destructive rollback action.

## TDD evidence

RED command:

`vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php`

Expected result: 1 failure at `001700`, missing `$withinTransaction = false`.

GREEN focused command:

`vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php tests\Unit\EstimateGeneration\Support\EstimateGenerationContractDatabaseProvisionerTest.php`

Result: `OK (6 tests, 65 assertions)`.

PHPStan first hit the configured 128M limit. Re-run with `--memory-limit=1G`: `[OK] No errors`.

Pint: 8 files checked, one style issue fixed in the new test.

## PostgreSQL attestation

Disposable container attestation passed with a random ephemeral non-superuser role. Password and marker values were not printed. Final command `tests\Runtime\run-training-benchmark-contract.ps1 -Passes 2` completed twice on the same disposable PostgreSQL instance; each pass provisioned from the sealed pre-subject inventory and reported `OK (6 tests, 103 assertions)`. The suite covers all four actual production backfill methods with injected interruption/resume, real invalid concurrent-index recovery and `indisvalid`/`indisready` assertions, final schema/domain invariants, and concurrent business writers. Cleanup terminated only ephemeral-role sessions, revoked all ephemeral grants, dropped the role, restored the established runner function ACL, and verified role count zero.

## Commits

`4591024e fix[lk]: сделаны online-миграции обучения AI-сметчика`

## Final verification

- Pint: `PASS`, 11 files.
- DB-less architecture/inventory: `OK (6 tests, 65 assertions)`.
- PHPStan with `--memory-limit=1G`: `[OK] No errors`.
- PostgreSQL behavioral gate, pass 1: `OK (6 tests, 103 assertions)`.
- PostgreSQL behavioral gate, pass 2: `OK (6 tests, 103 assertions)`.
- `git diff --check` and cached diff check: clean.

## Final review-wave ordinal oracle

The same-catalog checkpoint discovery/replay oracle was replaced with a reset/replay ordinal oracle. `TrainingBenchmarkOnlineMigrationRuntime` now maintains a global per-process statement ordinal. The test-only `ESTIMATE_CONTRACT_INTERRUPT_ORDINAL` hook is accepted only with `RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT=1` and an exact `_contract` database attestation, and throws immediately after the selected real production statement.

The PowerShell launcher now performs two fresh discovery resets until the checkpoint total converges, then performs a new sealed pre-subject provision for every ordinal. Each run applies the real subject migrations in order, retries the exact interrupted `up()`, performs the immediate idempotent repeat, and checks the final invariants. Creation and adoption/retry paths are covered without a manually maintained checkpoint-name list. The converged inventory is **295 statement ordinals**.

RED evidence:

- The previous same-catalog oracle could not re-enter creation-only branches after a successful discovery migration.
- `vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php` failed because `ESTIMATE_CONTRACT_INTERRUPT_ORDINAL` and `observedCheckpointCount` did not exist.
- The first full ordinal run exposed ordinal `137`: interruption occurred in the immediate idempotent repeat outside the retry wrapper (`estimate_generation_online_migration_interrupted_ordinal:137:001700.column.dataset.dataset_key.adopted`). The repeat now uses the same retry wrapper as the first migration pass.
- The focused rerun then exposed final gate ordering: the adoption race test was executed against a newly reset pre-subject schema. The gate now runs the runtime tests on pre-subject state, then the real contract migration and adoption tests together.

GREEN evidence on the final frozen files:

- `tests\Runtime\run-training-benchmark-contract.ps1 -Passes 1 -OnlyOrdinal 137`: discovery `295`/`295`, ordinal `137` `OK (1 test, 61 assertions)`, runtime `OK (4 tests, 30 assertions)`, contract plus adoption `OK (4 tests, 92 assertions)`, cleanup completed.
- `tests\Runtime\run-training-benchmark-contract.ps1 -Passes 1`: two stable discovery resets at `295`, all ordinals `1..295` passed, runtime `OK (4 tests, 30 assertions)`, contract plus adoption `OK (4 tests, 92 assertions)`, `training benchmark ordinal convergence pass 1 completed at 295 checkpoints`, cleanup completed.
- A second full pass on the same disposable instance was started and reached ordinals `1..10` green. The project owner explicitly waived the remaining second pass because of its duration and accepted the repeatability gate. It is not claimed as a completed pass. The runner was stopped, the exact ephemeral role was removed, the ephemeral role count was verified as `0`, and the guard-function ACL was verified as `{most_contract_guard=X/most_contract_guard,most_contract_runner=X/most_contract_guard}`.
- `tests\Runtime\test-training-contract-launcher-attestation.ps1`: `launcher attestation zero-mutation and environment restoration verified`.
- `vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php tests\Unit\EstimateGeneration\Support\EstimateGenerationContractDatabaseProvisionerTest.php`: `OK (6 tests, 106 assertions)`.
- `vendor\bin\pint --test` on the ten changed PHP files: `PASS`.
- `vendor\bin\phpstan analyse --memory-limit=1G` on the ten changed PHP files: `[OK] No errors`.
- `php -l` on every changed/new PHP file: no syntax errors.
- `git diff --check`: clean.

The launcher also retains the review-wave hardening for read-only pre-attestation before mutation, environment restoration, full fresh-chain provisioning, exact index definition comparison (ordering, NULLS, operator class, collation, INCLUDE, predicate), schema-qualified constraint catalog operations, partial second-timeout-SET restoration, and per-migration architecture policy.

Final ordinal review-wave implementation commit: `33e5961e fix[lk]: завершён ordinal-контроль online-миграций`.

## Final narrow review fixes

- Removed the unused legacy `runIdempotentPhase()` and generic public `backfill()` APIs. The production chain uses only the four stable high-water backfill methods.
- Invalid concurrent-index recovery now drops `expected_schema.index_name` explicitly and no longer depends on `search_path`.
- Added a PostgreSQL behavior case that creates an invalid unique index in a non-public schema and proves that the exact schema-qualified object is dropped and rebuilt valid.
- Replaced aggregate helper-presence assertions with an explicit per-migration applicability map for `ensureConstraint`, `validateConstraint`, and `swapValidatedConstraint`.

Narrow TDD evidence:

- RED: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php` failed because the runtime still exposed `runIdempotentPhase()`.
- GREEN DB-less: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php tests\Unit\EstimateGeneration\Support\EstimateGenerationContractDatabaseProvisionerTest.php`: `OK (6 tests, 117 assertions)`.
- Focused PostgreSQL: `tests\Runtime\run-training-benchmark-contract.ps1 -Passes 1 -OnlyOrdinal 1`: stable discovery `295`, ordinal smoke `OK (1 test, 61 assertions)`, runtime including non-public invalid-index recovery `OK (4 tests, 31 assertions)`, contract plus adoption `OK (4 tests, 92 assertions)`, cleanup completed.
- Pint on the three narrow PHP files: `PASS`.
- PHPStan on the three narrow PHP files: `[OK] No errors`.
- `php -l` on the three narrow PHP files and `git diff --check`: clean.
- No additional full 295 replay was run; the project owner waived another repeat, and these changes are limited to removal of unreachable APIs, schema qualification in the helper, and source/behavior tests.

Narrow review-fix commit: `d9080f09 fix[lk]: уточнены helper-ы online-миграций`.
- `.cbmignore` and `.codebase-memory/` were never staged or modified.

## Review fix wave

Commit: `32f983e3 fix[lk]: устранены разрывы online-миграций AI-сметчика`.

RED evidence:

- Strengthened architecture policy initially failed because real migrations did not restore session settings in `finally`.
- First real immediate retry failed on duplicate/catalog adoption paths; after conversion, each migration runs twice immediately.
- Sequential real migration fault injection now interrupts and resumes 18 structure/backfill/index/constraint checkpoints across `001700`–`002200`.
- Exact catalog comparison initially failed because PostgreSQL appends `NOT VALID` to the probe definition; validation state is now compared separately from canonical definition.
- Index matrix exposed an out-of-public probe-schema cleanup dependency; the test now drops the schema before ephemeral-role revocation.

GREEN evidence after the final immutable code state:

- `run-training-benchmark-contract.ps1 -Passes 2`: pass 1 and pass 2 each `OK (7 tests, 136 assertions)`, no risky tests or warnings.
- Actual window writers prove dataset identity, unreviewed example, membership, and processing lease fences.
- Closed-state replacement uses a validated temporary constraint followed by an atomic short lock/drop/rename transaction.
- Index contract covers invalid recovery, catalog validity/readiness, exact columns/order, uniqueness, table and schema identity.
- Timeout restoration covers success and injected exception on the same connection with exact original values.
- Pint: `PASS`, 11 files.
- DB-less architecture/inventory: `OK (6 tests, 83 assertions)`.
- PHPStan with `--memory-limit=1G`: `[OK] No errors`.
- `git diff --check` and cached diff check: clean.
