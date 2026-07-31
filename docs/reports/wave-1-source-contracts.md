# Wave 1 source contracts

## Status

G01, G04, G09 and G10 have identified business sources, but are blocked by source readiness: an immutable source snapshot and replay contract has not been evidenced. Their binding status is `blocked_by_source_readiness`, their provider is `null`, and they are not executable reports.

G06, G11, G12, G13 and G21-G24 remain blocked by an explicit source contract. Their binding status is `blocked_by_source_contract`, their provider is `null`, and they are not executable reports.

No Wave 1 candidate is implemented or registered in runtime.

## Source-readiness contracts

| Candidate | Identified business source | Missing readiness evidence | Admission evidence |
| --- | --- | --- | --- |
| G01 project_portfolio_health | Portfolio aggregation | Immutable as-of snapshot, replay cursor, and source-version retention | A persisted source snapshot can be replayed by `(organization_id, as_of, source_version)` with identical scoped and redacted rows. |
| G04 portfolio_liquidity | Liquidity forecast aggregation | Immutable forecast snapshot, scenario version, and replay cursor | A persisted snapshot can be replayed by `(organization_id, as_of, scenario_id, source_version)` without reading mutable current state. |
| G09 project_margin | Project-margin calculation | Approved close identity, immutable inputs, per-source watermark, formula/source version and replay cursor | An approved close snapshot reproduces rows from `(organization_id, reporting_period, close_version)` after later source changes. |
| G10 budget_plan_fact | Budget plan/fact calculation | Approved plan/fact close identity, immutable inputs, per-source watermark, scenario/source version and replay cursor | A persisted `(organization_id, reporting_period, scenario_id, source_version)` snapshot reproduces plan, fact and variance values. |

### G09 `project_margin` source snapshot contract (schema `1.0.0`)

`ProjectMarginSourceSnapshotWriter` is a pre-admission infrastructure writer. It calls the real `ProjectMarginReportService` and persists only through `ReportSourceSnapshotStore`; it is not a `ReportDataProvider`, `ReportRowQuery`, `ReportDrillDownProvider`, runtime binding, route or catalog registration.

| Contract element | G09 rule |
| --- | --- |
| Grain | One canonical aggregate per selected `group_by` tuple. `currency` is always present. Source drill entries retain the report service's attribution-line grain. |
| Allowed filters | `organization_id`, `period_start`, `period_end`, `budget_version_uuid`, `scenario_uuid`, `project_id`, `contract_id`, `responsibility_center_id`, `budget_article_id`, `counterparty_id`, `currency`, `group_by`. |
| Scope handling | `organization_id` must equal `ReportScope.organizationId`. The snapshot writer always treats `ReportScope.projectIds` as authoritative: an empty list adds `where 1 = 0`, while a non-empty list adds `whereIn(project_id, allowedIds)` to every normalized source union. A requested `project_id` must be in `allowedIds`; no per-project slicing or client-side merge is used. Legacy `report()` / `drillDown()` calls without an explicit scope retain their organization-wide behavior and are not used by the writer. |
| Canonical row and cursor identity | `row_key = margin:sha256(canonical group)`. Rows are ordered lexically by that key and persisted with a contiguous ordinal; snapshot cursors address that ordinal only. |
| Source fields | Canonical rows retain group IDs/month/currency, plan/forecast/actual/variance money blocks, quality, source counts/types and flags. Drill entries retain opaque attribution reference, source type, event/rule, period/date, signed amounts, statuses and flags. |
| Drill reference | Every row exposes only `{column_id: attributions, key: drill_down_key}`. The writer pages the real live drill endpoint until its `meta.total` is materialized, then the stored ordinal is the replay order. |
| Watermarks and hashes | `query_hash` binds the complete canonical report scope and allowed filters. `source_hash` binds canonical redacted rows, redacted drill entries and capture metadata. Current capture metadata contains selected budget/scenario versions, reporting period, source types and source-row count; it is not an upstream update watermark. `snapshot_hash` is sealed by the snapshot store. |
| Redaction | Rows exclude project, contract, counterparty, article and responsibility-center display names. Drill entries exclude raw line/source IDs, document numbers, titles, source URLs, route hints, permissions and nested labels; only `sha256(line_id)` is retained as the opaque attribution reference. |
| Freshness and close limitation | The current source service reads live budget, act, work, payment, warehouse and time-entry tables. `BudgetPeriodClosure` locks the budgeting period but retains only a management summary (counts and totals), not the selected versions, fact inputs, per-source update watermarks or a content hash. Its period may be reopened. `BudgetVersion` has an approval/activation workflow but no immutable materialization of lines and amounts, and it covers neither facts nor the other G09 sources. `EpmDataMartSnapshot` is a recalculation artifact, not an owner-approved close; newer recalculations supersede it and no retention policy is attached. A writer may set `as_of`/`stale_at`, but this does not establish a close policy or admission. |

G09 therefore remains `blocked_by_source_readiness` with a `null` provider. Admission still requires owner-approved close/version retention, a source freshness watermark, retention policy and a replay acceptance test showing a closed-period snapshot remains identical after upstream mutation.

### G10 `budget_plan_fact` source snapshot contract (schema `1.0.0`)

`PlanFactSourceSnapshotWriter` is a pre-admission infrastructure writer. It uses `PlanFactReportService` through its internal scoped snapshot contract and persists only through `ReportSourceSnapshotStore`; it is not a `ReportDataProvider`, `ReportRowQuery`, `ReportDrillDownProvider`, runtime binding, route or catalog registration.

| Contract element | G10 rule |
| --- | --- |
| Grain | One canonical plan/fact aggregate per selected `group_by` tuple. `currency` is always present. Drill entries retain the plan/fact source-document grain for payment transactions, reservations and active payment documents. |
| Allowed filters | `organization_id`, `period_start`, `period_end`, `budget_version_uuid`, `scenario_uuid`, `project_id`, `responsibility_center_id`, `budget_article_id`, `counterparty_id`, `currency`, `group_by`. |
| Period, version and as-of | The report period is inclusive. The chosen budget version must overlap it and fixes the plan/forecast inputs; its scenario fixes the scenario. `as_of` is the writer timestamp for the captured live result, not an approved financial close or a source-version timestamp. |
| Scope handling | `organization_id` must equal `ReportScope.organizationId`. The writer passes `ReportScope.projectIds` only to `reportForProjectScope()` and `drillDownForProjectScope()`. A non-empty set adds `whereIn` to every plan, actual, reservation, document, coverage and drill source query. An empty set adds `where 1 = 0`; it never means all projects. A requested `project_id` must be in the allowed set. Legacy `report()` and `drillDown()` retain their organization-wide behavior and are not used by the writer. |
| Canonical row and cursor identity | `row_key = plan_fact:sha256(canonical group)`. Rows are ordered lexically by that key and persisted with a contiguous ordinal; snapshot cursors address that ordinal only. |
| Source fields | Canonical rows retain group identifiers, currency, plan, forecast, actual, commitment, variance, variance percent and risk level. Drill entries retain only type, opaque source reference, date, amount, currency, status and variance contribution. |
| Drill reference | Every row exposes `{column_id: sources, key: sha256(drill_down_key)}`. The writer pages the real scoped drill method until `meta.total` is materialized; stored drill ordinal is the replay order. |
| Watermarks and hashes | `query_hash` binds the canonical scope and allowed filters. `source_hash` binds canonical redacted rows, redacted drill entries and capture metadata. Current capture metadata records the selected budget/scenario UUIDs, period, source aggregate-row counts and canonical row count; aggregate-row counts are not upstream update watermarks. `snapshot_hash` is sealed by the snapshot store. |
| Redaction | Rows exclude article, responsibility-center, project, counterparty and scenario display payloads. Drill entries exclude raw IDs, numbers, titles, route hints and source URLs; `sha256(source_type|source_id)` is the only retained source reference. |
| Freshness and close limitation | `PlanFactReportService` currently reads mutable budget, payment transaction, reservation, payment document and schedule tables. `BudgetPeriodClosure` locks budget edits but does not capture the active version identifiers, plan rows, factual inputs, per-source update watermarks or a content hash; the period may be reopened. A `BudgetVersion` approval/activation records lifecycle timestamps but is not a retained immutable plan snapshot and does not version facts, reservations or documents. `EpmDataMartSnapshot` is recalculated from live services, superseded on the next run and has no owner approval or retention policy. A writer may set `as_of`/`stale_at`, but this is only a captured live result and does not establish a close policy or admission. |

G10 therefore remains `blocked_by_source_readiness` with a `null` provider. Admission still requires owner-approved plan/fact close and source-version retention, source freshness watermarks, retention policy, and a replay acceptance test showing a closed-period snapshot remains identical after upstream mutation.

### G09/G10 close and source-version decision (verified 2026-07-31)

No existing Budgeting model is an authoritative approved-close source for either candidate. The following nearby concepts must not be used as substitutes:

| Existing concept | What it proves | Why it cannot admit G09 or G10 |
| --- | --- | --- |
| `BudgetVersion` | A plan version passed its workflow and has `approved_at` / `activated_at`. | It has no immutable materialized line/amount content hash or retention rule, may be affected by an allowed period reopen, and does not version actuals, payments, reservations, documents, warehouse movements, acts or time entries. |
| `BudgetPeriodClosure` | Budget changes were blocked at one moment and a management summary was recorded. | Its metadata stores counts and totals, not the complete selected version set, source rows, source update cutoffs, content hash, retention horizon or factual source state. A later reopen creates a new lifecycle event rather than a protected source version. |
| `EpmDataMartSnapshot` | A live-service payload was generated with a derived `source_hash` and freshness data. | It is a recalculation artifact, not owner-approved close evidence. It is superseded on the next recalculation and has neither a close identity nor a documented retention policy or per-source update watermarks. |

The next source-owner change must introduce an explicit approved-close record, outside the Reporting runtime, with at least: immutable `close_id`; organization and inclusive reporting period; selected plan/scenario version identities; a source watermark and cutoff for every factual source used by the candidate; formula/source-schema version; canonical content hash; approver and approval time; lifecycle/restatement relation; and a retention deadline or policy reference. A G09/G10 writer may consume that record only after it has a source-derived replay test proving that later upstream mutations do not alter the approved-close result. Until then, capture metadata, `as_of`, `stale_at`, `source_hash`, `BudgetVersion`, `BudgetPeriodClosure` and `EpmDataMartSnapshot` remain non-admission evidence.

## Source contracts

| Candidate | Upstream owner | Required source fields | Grain | Scope key | Freshness rule | Acceptance test |
| --- | --- | --- | --- | --- | --- | --- |
| G06 baseline_schedule_variance | Project planning | project_id, baseline_start_at, baseline_finish_at, current_start_at, current_finish_at, baseline_version, approved_at | Project and approved baseline version | organization_id + project_id | Approved baseline changes are available no later than the next reporting refresh; incomplete or unapproved baselines are rejected. | For one project with two baseline versions, the report selects only the latest approved version and returns the signed date variance. |
| G11 wip_completion_forecast | Project finance | project_id, reporting_period, earned_value, actual_cost, estimate_at_completion, forecast_date, currency | Project and reporting period | organization_id + project_id + reporting_period | Forecast is refreshed at period close and after an approved forecast revision; an older forecast cannot overwrite a newer one. | A closed-period fixture verifies WIP, completion percentage, and forecast values from one approved snapshot. |
| G21 workforce_capacity | Workforce management | employee_id, capacity_date, planned_hours, availability_hours, assignment_project_id, absence_hours | Employee, assignment, and calendar day | organization_id + employee_id + capacity_date | Calendar changes and approved absences are visible by the next reporting refresh. | A fixture with assignments and absences verifies capacity aggregation without cross-organization rows. |
| G22 attendance_execution | Time tracking | employee_id, work_date, planned_hours, actual_hours, attendance_status, approved_at | Employee and work date | organization_id + employee_id + work_date | Only approved attendance is included; corrections are visible by the next reporting refresh. | A corrected attendance row replaces the prior approved value and updates execution hours once. |
| G23 project_labor_cost | Time tracking and payroll | project_id, employee_id, work_date, approved_hours, labor_rate, rate_currency, rate_effective_at | Project, employee, and work date | organization_id + project_id + employee_id + work_date | Approved hours and the rate effective for the work date are used; later rate changes do not rewrite closed periods. | A period fixture verifies project labor cost with two effective rates and no use of unapproved hours. |
| G24 payroll_readiness | Payroll | employee_id, payroll_period, payroll_status, required_input_status, approved_at, payment_date | Employee and payroll period | organization_id + employee_id + payroll_period | Readiness changes after approval or required-input correction and is final only after payroll-period close. | A payroll-period fixture verifies that missing required input is not ready and approved complete input is ready. |

## Source and formula contracts

| Candidate | Formula inputs | Sign convention | Period-close rule | Upstream owner | Required source fields | Grain | Scope key | Freshness rule | Acceptance test |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| G12 contract_settlement_exposure | Contract value, approved change value, invoiced amount, received payment, approved retention, recognized liability, reporting-date FX rate | Positive value means expected cash outflow or liability exposure; negative value means net receivable. | A closed period uses the approved close snapshot; post-close corrections require a new approved close version and never mutate the prior result. | Contract and finance | contract_id, project_id, reporting_period, contract_value, change_value, invoiced_amount, paid_amount, retention_amount, liability_amount, currency, fx_rate, approved_at | Contract and reporting period | organization_id + contract_id + reporting_period | Contract and settlement changes are visible by the next reporting refresh; close snapshots are immutable. | A multi-currency closed-period fixture verifies formula inputs, FX conversion, and the stated sign convention. |
| G13 management_pnl | Recognized revenue, direct labor cost, materials cost, subcontractor cost, overhead allocation, other income, other expense | Revenue and other income are positive; costs and expenses are negative; profit equals the signed sum. | A period is executable only after an approved financial close; restatements create a new close version. | Management accounting | organization_id, project_id, reporting_period, recognized_revenue, direct_labor_cost, materials_cost, subcontractor_cost, overhead_amount, other_income, other_expense, close_version, approved_at | Organization, project, and reporting period | organization_id + reporting_period + project_id | Values refresh after an approved close or restatement; an unapproved ledger state is excluded. | A closed-period fixture verifies the signed P&L sum, the exclusion of unapproved postings, and selection of the latest approved close version. |

## Admission rule

A source-contract-blocked candidate can receive a provider only after its owner supplies the stated fields at the stated grain and scope, the freshness and close rules are enforced, and the corresponding acceptance test passes. A source-readiness-blocked candidate additionally requires an immutable source snapshot and replay contract with the stated admission evidence. Until admission is complete, every Wave 1 candidate keeps a `null` provider and remains outside runtime publication and registration.

## Immutable source snapshot foundation

The source-snapshot store is a persistence boundary, not a Wave 1 provider. A snapshot header identifies one immutable source result by `snapshot_id`, `source_kind`, `report_code`, `schema_version`, `organization_id`, canonical report scope, canonical query hash, `as_of`, source hash, watermarks, generation/staleness timestamps, status, row counts and content hash. Rows and drill rows are stored separately and are always keyed by that same `snapshot_id`.

Only a `ready` snapshot may be read. Reads must match organization, complete canonical scope, report code, source kind, schema version and query hash. Expired snapshots, snapshots past `stale_at` when stale reads are not explicitly allowed, non-ready headers, unknown IDs and invalid payload hashes are rejected. Cursor and drill reads address snapshot rows only; they must not query mutable business models.

Persistence seals the header after row insertion. PostgreSQL constraints and triggers prevent a ready or expired header, its rows or its drill rows from being changed or appended. Replay ordering is the persisted ordinal with row-key uniqueness; drill ordering is persisted per `(snapshot_id, row_key, column_id, ordinal)`.

This foundation alone is not candidate admission evidence. G01, G04, G09 and G10 remain `blocked_by_source_readiness` with `provider: null` until each owner supplies a source-specific writer, retention policy, redaction rules and a replay acceptance test against the real upstream source. No route, UI, catalog registration or production report provider is created by this contract.
