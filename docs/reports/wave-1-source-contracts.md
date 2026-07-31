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
| G09 project_margin | Project-margin calculation | Immutable period-close inputs, formula/source version, and replay cursor | An approved close snapshot reproduces rows from `(organization_id, reporting_period, close_version)` after later source changes. |
| G10 budget_plan_fact | Budget plan/fact calculation | Immutable plan/fact snapshot, scenario version, and replay cursor | A persisted `(organization_id, reporting_period, scenario_id, source_version)` snapshot reproduces plan, fact, and variance values. |

### G09 `project_margin` source snapshot contract (schema `1.0.0`)

`ProjectMarginSourceSnapshotWriter` is a pre-admission infrastructure writer. It calls the real `ProjectMarginReportService` and persists only through `ReportSourceSnapshotStore`; it is not a `ReportDataProvider`, `ReportRowQuery`, `ReportDrillDownProvider`, runtime binding, route or catalog registration.

| Contract element | G09 rule |
| --- | --- |
| Grain | One canonical aggregate per selected `group_by` tuple. `currency` is always present. Source drill entries retain the report service's attribution-line grain. |
| Allowed filters | `organization_id`, `period_start`, `period_end`, `budget_version_uuid`, `scenario_uuid`, `project_id`, `contract_id`, `responsibility_center_id`, `budget_article_id`, `counterparty_id`, `currency`, `group_by`. |
| Scope handling | `organization_id` must equal `ReportScope.organizationId`. An empty `ReportScope.projectIds` keeps the service's organization-wide behavior. For a non-empty scope, the writer calls the internal `reportForProjectScope()` / `drillDownForProjectScope()` methods, which add `whereIn(project_id, allowedIds)` to every normalized source union. A requested `project_id` must be in `allowedIds`; no per-project slicing or client-side merge is used. |
| Canonical row and cursor identity | `row_key = margin:sha256(canonical group)`. Rows are ordered lexically by that key and persisted with a contiguous ordinal; snapshot cursors address that ordinal only. |
| Source fields | Canonical rows retain group IDs/month/currency, plan/forecast/actual/variance money blocks, quality, source counts/types and flags. Drill entries retain opaque attribution reference, source type, event/rule, period/date, signed amounts, statuses and flags. |
| Drill reference | Every row exposes only `{column_id: attributions, key: drill_down_key}`. The writer pages the real live drill endpoint until its `meta.total` is materialized, then the stored ordinal is the replay order. |
| Watermarks and hashes | `query_hash` binds the complete canonical report scope and allowed filters. `source_hash` binds canonical redacted rows, redacted drill entries and watermarks. Watermarks contain selected budget/scenario versions, reporting period, source types and source-row count. `snapshot_hash` is sealed by the snapshot store. |
| Redaction | Rows exclude project, contract, counterparty, article and responsibility-center display names. Drill entries exclude raw line/source IDs, document numbers, titles, source URLs, route hints, permissions and nested labels; only `sha256(line_id)` is retained as the opaque attribution reference. |
| Freshness and close limitation | The current source service reads live budget, act, work, payment, warehouse and time-entry tables. Its payload has no approved immutable period-close version nor source update watermark. A writer may set `as_of`/`stale_at`, but this does not establish a close policy or admission. |

G09 therefore remains `blocked_by_source_readiness` with a `null` provider. Admission still requires owner-approved close/version retention, a source freshness watermark, retention policy and a replay acceptance test showing a closed-period snapshot remains identical after upstream mutation.

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
