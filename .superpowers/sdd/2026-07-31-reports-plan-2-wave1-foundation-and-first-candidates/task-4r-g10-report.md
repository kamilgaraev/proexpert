# Task 4R/G10 — `budget_plan_fact` source readiness

## Status

`READY_FOR_PRE_ADMISSION_ONLY`

G10 remains `blocked_by_source_readiness` with `provider: null`. No runtime provider, row query, drill-down provider, route, UI, admission or manifest promotion was added.

## Implemented slice

- Added `PlanFactSourceSnapshotWriter`, `PlanFactSourceSnapshotMaterializer` and request/reader contracts for source snapshots.
- Added a scoped internal path to `PlanFactReportService`. `report()` and `drillDown()` keep their legacy organization-wide path; the writer exclusively calls `reportForProjectScope()` and `drillDownForProjectScope()`.
- A non-empty project scope restricts every plan/fact aggregate, coverage and drill source query. An empty scope adds `where 1 = 0`, so it cannot broaden into organization-wide data.
- Canonical snapshot rows use `plan_fact:sha256(canonical_group)` and persisted ordinals. Raw document/source IDs, labels, titles, numbers and route hints are excluded from snapshot rows and drills.
- Extended the Wave 1 source-contract documentation with G10 grain, filters, scope, version/as-of, cursor, watermarks, hash, drill and redaction rules.

## Remaining admission gap

`PlanFactReportService` reads mutable budget, payment, reservation, document and schedule sources. The service does not currently expose an approved immutable close/version or upstream update watermarks. `as_of` therefore identifies only when the live result was captured. Owner-approved close/version retention, source freshness watermarks, a retention policy and a mutation/replay acceptance test remain required before G10 admission.

## Validation

- `php vendor/bin/phpunit tests/Unit/Budgeting/PlanFactSourceSnapshotTest.php tests/Unit/Budgeting/PlanFactCalculatorTest.php` — passed (6 tests, 49 assertions).
- `php vendor/bin/phpstan analyse --memory-limit=1G` for the six changed production PHP files — passed.
- `php -l` for changed PHP files — passed.
- `git diff --check` — passed.
