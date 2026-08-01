# Исполнимый портфель отчётов МОСТ v1

## Статус и границы

Этот документ — единственный реестр из 28 управленческих отчётов, согласующий
backend-каталог `management-catalog.v1.yaml` и текущий каталог админки.
Он описывает **целевой** контракт, а не объявляет готовность существующих
страниц. На дату документа ни один из 28 отчётов не является
`publish-ready` в платформенном смысле: в authoritative backend-каталоге у
всех определений `delivery: not_implemented`, а у большинства не закрыт
source/formula contract. Поэтому каждый пункт ниже имеет статус
`migration-required` до прохождения per-code release admission.

`Экспорт: не подтверждён` означает, что UI, старый endpoint или сервис не
достаточны для обещания пользователю файла. Такой отчёт не должен показывать
доступный экспорт до подтверждённого renderer, асинхронной задачи, S3-артефакта
и проверки прав. В частности, HTTP 501 и service-only реализация не считаются
экспортом.

Общие обязательные критерии публикации каждого пункта:

1. Версионированные source, formula и report-definition contracts с ненулевыми
   fingerprints; source snapshot sealed и воспроизводим.
2. `view`, `export`, `drill` и sensitive поля проверяются backend-слоем через
   Reporting RBAC/ABAC; организационный и проектный scope не доверяется UI.
3. Drill-down возвращает только строки, из которых составлена агрегированная
   метрика, с cursor/pagination и тем же snapshot identity.
4. Экспорт имеет renderer, асинхронное выполнение, статус, аудит, S3-артефакт и
   повторяемую проверку содержимого; форматы в UI берутся из definition.
5. Per-code conformance, quality evidence, admission bundle и независимое
   ревью проходят до promotion. Любой недостающий источник, hash или право
   блокирует публикацию.

В таблицах: `V/E/D` — права view/export/drill; «Всеобщие» означает критерии
выше плюс указанную предметную проверку. Владелец — модуль, отвечающий за
источник и целевой adapter, а `Reporting Core` — за общую платформу.

## Портфель

| № | Код, статус, владелец | Источник и grain | Формула | V/E/D | Drill-down | Экспорт | Критерии доверия и acceptance |
|---:|---|---|---|---|---|---|---|
| 1 | `project_portfolio_health` — migration-required; Budgeting + Multi-organization | Project, budget/schedule health; `project_currency_as_of` | Индекс здоровья: только versioned weighting статусов бюджета, сроков и рисков; сейчас formula ready, source partial | `budgeting.portfolio_dashboard.view` / `.export` / view | проект → показатель → первичная запись | не подтверждён | Всеобщие; агрегат равен сумме/взвешенному расчёту project rows на одном `as_of` |
| 2 | `holding_performance` — migration-required; Multi-organization | Организации, проекты, финансовые периоды; `organization_project_currency_period` | Контракт формулы требуется | `multi-organization.reports.kpi` / `.export` / view | холдинг → организация → проект | не подтверждён | Всеобщие; консолидация исключает межорганизационные дубли |
| 3 | `intercompany_contract_flows` — migration-required; Multi-organization + Contracts | Межорганизационные allocation и договоры; `allocation_counterparty_period` | Контракт формулы требуется | `multi-organization.reports.financial` / `.export` / view | allocation → договор → проводка/событие | не подтверждён | Всеобщие; eliminations прозрачны и сверяемы с исходными allocation |
| 4 | `portfolio_liquidity` — migration-required; Payments + Budgeting | Денежные события, forecast; `day_project_currency_scenario` | Cash gap = доступный остаток + притоки − обязательства; formula ready, source aggregation required | `budgeting.cfo.view` / `budgeting.cash_gap.export` / view | день → проект → обязательство/денежное событие | не подтверждён | Всеобщие; валюта, scenario и cut-off входят в snapshot identity |
| 5 | `project_evm_control` — migration-required; Schedule + Budgeting | Task baseline, accepted work, cost; `task_baseline_status_date` | Контракт CPI/SPI/EAC требуется | `reports.project_control.view` / `.export` / view; sensitive: `budgeting.wip_forecast.view_sensitive_costs` | проект → WBS → task → baseline/факт | не подтверждён | Всеобщие; sensitive cost не попадает в drill без отдельного права |
| 6 | `baseline_schedule_variance` — migration-required; Schedule Management | Task baseline/revision; `task_baseline_as_of` | Variance = текущая дата/длительность − baseline; formula ready | `schedule.view` / `schedule.reports.export` / view | project → schedule → task → revision | не подтверждён | Всеобщие; baseline revision и `as_of` обязательны, zero baseline не маскируется |
| 7 | `lookahead_readiness` — migration-required; Schedule Management | Constraint, task, planning window; `constraint_task_window` | Policy-based readiness; policy contract required | `schedule.view` / `schedule.reports.export` / view | окно → задача → ограничение → evidence event | не подтверждён | Всеобщие; готовность объясняется набором незакрытых ограничений |
| 8 | `accepted_production_progress` — migration-required; Act Reporting + Workflow | Принятые работы/акты; `accepted_work_day` | Сумма только принятых объёмов и стоимости; source event required | `reports.production_progress.view` / `.export` / view; sensitive cost right как в №5 | период → акт → работа → acceptance event | не подтверждён | Всеобщие; отклонённые/черновые работы не влияют на итог |
| 9 | `project_margin` — migration-required; Budgeting | Project, article, budget/fact; `project_article_currency_period` | Margin = revenue − direct cost; formula ready, source partial | `budgeting.project_margin.view` / `.export` / view | проект → статья → revenue/cost entry | не подтверждён | Всеобщие; сумма статей равна project margin по currency/period |
| 10 | `budget_plan_fact` — migration-required; Budgeting | Budget periods/articles/fact; `budget_period_article_currency` | Variance = fact − plan; variance % только при ненулевом plan | `budgeting.plan_fact.view` / `.export` / view | бюджет → период → статья → plan/fact entry | не подтверждён | Всеобщие; source/formula marked ready, но delivery отсутствует |
| 11 | `wip_completion_forecast` — migration-required; Budgeting | Forecast provider, WIP; `forecast_provider_currency` | Контракт forecast/EAC требуется | `budgeting.wip_forecast.view` / `.export` / view; sensitive `budgeting.wip_forecast.view_sensitive_costs`; audit `.view_audit` | forecast → provider → source assumption | не подтверждён | Всеобщие; forecast version, source provider и sensitive disclosure фиксируются |
| 12 | `contract_settlement_exposure` — migration-required; Contracts + Payments | Contract allocation/direction/currency; `allocation_direction_currency` | Contractual exposure formula required | `contracts.management_report.view` / `.export` / view | договор → allocation → invoice/payment/milestone | не подтверждён | Всеобщие; дебет/кредит и валюта не смешиваются |
| 13 | `management_pnl` — migration-required; Budgeting + Finance | Organization, article, period, scenario; `organization_article_period_scenario` | Policy/source formula required | `budgeting.management_pnl.view` / `.export` / view | P&L line → article → source entry | не подтверждён | Всеобщие; scenario и accounting policy version закреплены в snapshot |
| 14 | `change_claim_contingency` — migration-required; Change Management | Change version/allocation/currency; `change_version_allocation_currency` | Contract required | `change-management.view` / `change-management.reports.export` / view | change → version → claim → contingency allocation | не подтверждён | Всеобщие; superseded version не смешивается с active version |
| 15 | `procurement_cycle` — migration-required; Procurement | Request line/process; `request_line_process` | Lead time = завершение этапа − старт; R15 candidate, admission ещё не publish | `procurement.dashboard.view` / `procurement.reports.export` / view; audit `procurement.audit.view` | request → line → event/decision → proposal | CSV/PDF/XLSX только после R15 admission | Всеобщие; четыре R15 bundle documents, official hashes и conformance обязательны |
| 16 | `supplier_award_competitiveness` — migration-required; Procurement | Decision/proposal/currency; `decision_proposal_currency` | Contract required: comparison awarded vs eligible proposals | `procurement.supplier_proposals.view` / `procurement.reports.export` / view; sensitive `procurement.proposal_decisions.view` | decision → proposal → supplier | не подтверждён | Всеобщие; hidden proposal pricing не выдаётся без sensitive right |
| 17 | `supply_reliability` — migration-required; Procurement + Warehouse | Purchase order line/promise; `purchase_order_line_promise` | On-time rate = on-time received / due received; contract required | `procurement.purchase_orders.view` / `procurement.reports.export` / view | order → line → promise → receipt | не подтверждён | Всеобщие; cancelled lines имеют отдельный policy outcome |
| 18 | `inventory_risk` — migration-required; Warehouse | Material/warehouse/day; `material_warehouse_day` | Risk policy: shortage, ageing, excess; contract required | `warehouse.advanced.view` / `warehouse.reports.export` / view; sensitive `warehouse.view_custody` | material → warehouse → movement/reservation | не подтверждён | Всеобщие; custody data раскрывается только с sensitive right |
| 19 | `workforce_capacity` — migration-required; Workforce Management | Staff unit/project/month; `staff_unit_project_month` | Capacity variance = available capacity − demand; formula ready | `workforce.view` / `workforce.reports.export` / view | project → unit → employee/cohort → evidence item | не подтверждён | Всеобщие; frozen snapshot и range descriptor обязательны |
| 20 | `attendance_execution` — migration-required; Workforce + Time Tracking | Employee/shift/day; `employee_shift_day` | Approved attendance vs planned shift; formula ready | `workforce.view` / `workforce.reports.export` / view; sensitive `workforce.audit.view` | shift → employee → approved time entry | не подтверждён | Всеобщие; only approved entries enter KPI; audit data gated |
| 21 | `project_labor_cost` — migration-required; Time Tracking + Budgeting | Approved entry/employee/day; `approved_entry_employee_day` | Labor cost = approved hours × approved rate policy; formula ready | `time_tracking.view` / `time_tracking.reports.export` / view; sensitive `time_tracking.cost.view` | project → entry → employee/rate evidence | не подтверждён | Всеобщие; rate disclosure separately authorized; hours reconcile to entries |
| 22 | `payroll_readiness` — migration-required; Workforce Management | Period/employee/issue; `period_employee_issue` | Readiness = policy evaluation of payroll evidence; formula ready | `workforce.view` / `workforce.reports.export` / view; audit `workforce.audit.view` | period → employee → readiness issue → evidence | не подтверждён | Всеобщие; snapshot kind/policy version and issue state are immutable |
| 23 | `quality_defect_flow` — migration-required; Quality Control | Defect transition/project; `defect_transition_project` | Policy/aggregation contract required | `quality-control.defects.view` / `quality-control.reports.export` / view | project → defect → transition → corrective action | не подтверждён | Всеобщие; lead time uses transition timestamps, not UI status |
| 24 | `safety_incident_actions` — migration-required; Safety Management | Incident/action/site/day; `incident_action_site_day` | Policy/aggregation contract required | `safety-management.view` / `safety-management.reports.export` / view | site → incident → action → closure evidence | не подтверждён | Всеобщие; closed/overdue action state derives from authoritative events |
| 25 | `workforce_admission` — migration-required; Safety + Workforce | Person/site/requirement/day; `person_site_requirement_day` | Compliance rate = valid requirements / applicable requirements; formula ready | `safety-management.view` / `safety-management.reports.export` / view; sensitive `safety-management.medical.view` | site → person → requirement → certificate/evidence | не подтверждён | Всеобщие; medical evidence stays hidden without sensitive right |
| 26 | `handover_readiness` — migration-required; Handover Acceptance + Executive Documentation | Gate/location/package; `gate_location_package` | Contract required | `reports.project_readiness.view` / `.export` / view | project → handover gate → package → missing document/action | не подтверждён | Всеобщие; readiness shows exact blocking evidence and approved package state |
| 27 | `contractor_scorecard` — migration-required; Contractor Marketplace + Procurement | Contractor/category/cohort; `contractor_category_cohort` | Policy/aggregation contract required | `contractor_marketplace.profile.view` / `contractor_marketplace.reports.export` / view | contractor → award/contract → KPI evidence | не подтверждён | Всеобщие; cohort policy version, sample size and excluded records disclosed |
| 28 | `customer_sla` — migration-required; Customer + Site Requests | Request event/customer; `request_event_customer` | SLA = elapsed business time vs committed target; contract required | `customer.sla_report.view` / `.export` / view | customer → request → event → SLA calendar rule | не подтверждён | Всеобщие; timezone, calendar and pause policy included in snapshot |

## Инвентарь текущей админки и правила миграции

Текущий `publishedReportDefinitions.ts` содержит 11 UI-кодов. Это не доказывает
platform publication и не заменяет 28 definitions выше. До появления
authoritative backend contract у этих страниц нельзя оставлять в UI придуманные
источники, формулы или форматы экспорта.

| Текущий код админки | Состояние | Целевое действие |
|---|---|---|
| `act-reports` | legacy/UI-only metadata | Мигрировать в `accepted_production_progress`; отдельно сохранить операционный список актов как экран, не как управленческий агрегат. |
| `contractor-summary`, `contractor-detail` | legacy API/export path | Сохранить как operational drill/templates; для управленческого каталога сопоставить с `contractor_scorecard` только после нового source/formula contract. |
| `contract-payments`, `contractor-settlements` | legacy API path | Разделить на operational registers и `contract_settlement_exposure`; не объявлять `excel/pdf`, пока renderer не доказан. |
| `warehouse-stock`, `material-movements` | legacy pages | Сохранить как operational inventory views; риск-агрегат реализовать отдельным `inventory_risk`. |
| `official-material-usage` | выделенный endpoint/XLSX link | Оставить отдельным migration adapter: endpoint и XLSX link требуют contract/quality/admission; не считать частью `inventory_risk` без подтверждённой семантики. |
| `time-tracking` | legacy page | Разделить attendance и labor cost на №20–21; запись времени остаётся operational drill. |
| `project-profitability` | UI связан с budgeting permission | Мигрировать в `project_margin`; права и export только из backend definition. |
| `project-timelines` | legacy page | Мигрировать в `baseline_schedule_variance`; детальные сроки остаются drill. |

## Порядок реализации и ownership

1. Сначала завершить platform admission и R15 `procurement_cycle` как эталон:
   definition, source adapter, conformance, rows/drill/export, release bundle.
2. Затем Wave 1 с наиболее зрелыми source/formula: `budget_plan_fact`,
   `project_margin`, `baseline_schedule_variance`, `project_labor_cost`,
   `payroll_readiness`, `workforce_capacity`, `attendance_execution`.
3. После каждого source contract — добавить/обновить definition, backend adapter,
   permissions translations, admin metadata/route и tests единым вертикальным
   блоком. Не публиковать UI code только потому, что существует legacy page.
4. Wave 2/3 выполняются только после закрытия перечисленных source/policy/event
   gaps; это не backlog-долг, а явный admission gate.

## Проверяемость портфеля

Портфель считается реализованным только когда 28 definitions присутствуют в
authoritative catalog, каждая имеет non-placeholder sources/formula/fingerprints,
свой adapter и conformance evidence, проверяемые V/E/D права, подтверждённые
formats, UI entry и migrated/retired legacy path. Счётчик UI-карточек,
существование service class или 501 endpoint не являются доказательством.
