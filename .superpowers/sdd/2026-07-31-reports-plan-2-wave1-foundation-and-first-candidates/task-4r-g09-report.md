# Task 4R/G09 — source-readiness slice

## Status

IMPLEMENTED_PRE_ADMISSION. G09 remains `blocked_by_source_readiness`; its manifest and binding keep `provider: null`. No runtime `ReportDataProvider`, `ReportRowQuery`, `ReportDrillDownProvider`, route, UI or catalog registration was added.

## Delivered

- Added a scope-aware internal path to `ProjectMarginReportService`: a non-empty allowed project list is applied as `whereIn(project_id, ...)` to the normalized real-source query before aggregate and drill calculations. A requested single project outside this list is rejected by the writer.
- Added `ProjectMarginSourceSnapshotWriter`, which reads the live G09 report and all drill pages, materializes them through `ProjectMarginSourceSnapshotMaterializer`, then persists only with `ReportSourceSnapshotStore`.
- Added canonical row keys, persisted-order cursor identity, canonical query/source/snapshot hashes, selected version/period/source-count watermarks, and a restrictive redaction projection.
- Documented G09 grain, filters, scope behavior, rows, drill references, watermarks, redaction and the remaining admission gap in `docs/reports/wave-1-source-contracts.md`.
- Fix round 1: snapshot calls now distinguish their explicit scope from legacy calls. An empty `ReportScope.projectIds` is materialized as an empty source set, while a non-empty list is applied with `whereIn`; only legacy `report()` / `drillDown()` remain organization-wide.

## Admission boundary

The existing G09 service still reads live budget, act, completed-work, payment, warehouse and time-entry tables. It does not expose an owner-approved immutable period-close version, source-update watermark or retention policy. The writer's `as_of` and `stale_at` make a stored result replayable after persistence, but do not prove an approved closed-period source. Consequently G09 is intentionally not admitted.

## Verification

- `vendor/bin/phpunit tests/Unit/Budgeting/ProjectMarginProjectScopeFilterTest.php tests/Unit/Budgeting/ProjectMarginSourceSnapshotMaterializerTest.php tests/Unit/Budgeting/ProjectMarginCalculatorTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php` — passed, 28 tests / 90 assertions.
- `php -l` for all changed PHP files — passed.
- `php -d memory_limit=1G vendor/bin/phpstan analyse` for changed production PHP — passed with no errors.
- `git diff --check` — passed.

No database-backed snapshot-store integration test was run or added: this slice has DB-free normalization, hash, redaction and cursor tests, and local database access is prohibited by repository rules.
