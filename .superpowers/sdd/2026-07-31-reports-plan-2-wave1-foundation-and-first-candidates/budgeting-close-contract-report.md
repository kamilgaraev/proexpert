# Budgeting approved close contract

## Scope

Implemented the owner-owned immutable close/source-version boundary for the G09 and G10 Budgeting sources. The change is intentionally outside the Reporting runtime: no `ReportDataProvider`, admission binding, route, UI, manifest-state change or writer wiring was added.

## Delivered contract

- `BudgetingReportSourceCloseIdentity` fixes organization, inclusive period, scenario and plan identities.
- `CreateBudgetingReportSourceClose` requires an ULID close ID, non-empty immutable source manifest, per-source cutoffs/watermarks/schema versions, formula version, SHA-256 canonical content hash, approver, approval time, retention deadline and optional restatement target.
- The storage port/service creates only approved close input and validates a retained approved close for a future source writer; neither reads live Budgeting data nor recalculates a report.
- PostgreSQL storage keeps close headers separately from source watermarks, permits one active approved close per identity, permits a replacement only as a named restatement, and protects source content/watermarks from update or deletion. The prior close can only make a one-way lifecycle transition to `restated` or `expired`.

## Admission status

The G09/G10 contract/storage blocker is partially removed. Both candidates remain `blocked_by_source_readiness`, have `provider: null`, and are not implemented or executable. Admission still requires source-writer validation against this close, CI PostgreSQL migration/constraint evidence, and a source-derived replay-after-upstream-mutation acceptance test.

## Verification boundary

The unit suite covers canonical hashing, hash rejection, active-close uniqueness, explicit restatement and read validation. PostgreSQL migration/trigger behavior is intentionally not executed locally; it is a CI deployment evidence gate.
