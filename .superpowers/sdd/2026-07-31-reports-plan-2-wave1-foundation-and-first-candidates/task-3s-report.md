# Task 3S — immutable source snapshot foundation

## Result

Implemented an isolated source-snapshot persistence contract for reporting. The slice contains immutable header, row and drill-row value objects, a write/read store port, an Eloquent implementation, persistence models and PostgreSQL schema. It is not registered in the application container or runtime reporting catalog.

The header binds source kind, report code, schema version, organization, canonical scope and query identity, as-of time, source hash, watermarks, freshness timestamps, lifecycle status, row counts and content hash. Reads reject not-ready, expired, stale-without-override and scope/identity mismatches. Pagination and drill queries use only snapshot-bound row tables and persisted ordinal ordering. The migration prohibits changes to ready/expired headers and child rows through database triggers.

## Deliberate scope boundary

No G01/G04/G09/G10 provider, source writer, route, UI, runtime registration or synthetic data was added. All Wave 1 candidates remain blocked and have no provider.

## Verification

- `vendor/bin/phpunit tests/Unit/Reporting/SourceSnapshots/ReportSourceSnapshotContractTest.php tests/Unit/Reporting/SourceSnapshots/ReportSourceSnapshotMigrationContractTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php` — 24 tests, 66 assertions, passed.
- `php -l` for all changed PHP files — passed.
- `php -d memory_limit=1G vendor/bin/phpstan analyse` for changed production snapshot PHP — passed with no errors.
- `git diff --check` — passed.

Migrations and database-backed tests were intentionally not run under the repository rule that prohibits local database access.

## Fix round 1

- Header JSONB attributes now pass native arrays into Eloquent casts, preventing double JSON serialization during persistence.
- The ready-immutability trigger branches by `TG_OP`; delete paths read and return `OLD`, while insert/update paths read and return `NEW`.
- Added a PostgreSQL-gated integration test for `persistReady`, header/page/drill reads and database rejection of a ready-row mutation. It is intentionally not executed locally.
