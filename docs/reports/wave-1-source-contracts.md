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
