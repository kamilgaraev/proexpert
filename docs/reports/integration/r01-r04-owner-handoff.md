# Передача owner-среза R01–R04

Документ фиксирует границу интеграции четырёх отчётов МОСТ:

- R01 `project_portfolio_health`;
- R02 `holding_performance`;
- R03 `intercompany_contract_flows`;
- R04 `portfolio_liquidity`.

Срез реализует owner-модели, immutable snapshots, формулы, typed providers и query
adapters из Plans 2/3. Он не публикует definitions, не изменяет active manifest и не
регистрирует runtime bindings. Объединение candidate-входов и атомарная активация
28/28 остаются ответственностью Plan 1c.

## Owner bindings

| Код | `ReportDataProvider` | `ReportRowQuery` / `ReportDrillDownProvider` | Readiness |
|---|---|---|---|
| `project_portfolio_health` | `ProjectPortfolioHealthProvider` | `BudgetingPortfolioQueryService` | prepared snapshot обязан существовать |
| `portfolio_liquidity` | `PortfolioLiquidityProvider` | `BudgetingPortfolioQueryService` | prepared snapshot обязан существовать |
| `holding_performance` | `HoldingPerformanceReportProvider` | `HoldingPerformanceRowQuery` | `HoldingPerformanceReadinessProbe` |
| `intercompany_contract_flows` | `IntercompanyContractFlowsReportProvider` | `IntercompanyContractFlowRowQuery` | `IntercompanyContractFlowReadinessProbe` |

В seven-field binding Plan 1c передаёт provider и query adapter раздельно; provider
реализует только `ReportDataProvider`. Nullable readiness probe используется только
для R02/R03. R01/R04 остаются fail-closed, пока Budgeting owner не создал prepared
snapshot с совпадающими `organization_id`, `definition_hash` и `query_hash`.

## Snapshot ingress

R01 получает `ProjectPortfolioProjectionResult` из
`CfoProjectPortfolioAggregator::buildResult()`. R04 получает recurring
`PortfolioLiquidityRow` из закреплённых Payment Calendar и Cash Gap owner sources.
Запись выполняется через `persistHealth()` и `persistLiquidity()` с непустым набором
typed `ReportSourceRef`, source hash и watermarks. Provider не пересчитывает legacy
dashboard и не подменяет отсутствующий prepared snapshot пустым результатом.

R02/R03 читают только `holding_allocation_fact_versions`. Online-проекция фиксирует
contract allocation после успешной транзакции и paid transaction по `paid_at`.
Accepted-accrual подключается только к каноническому
`ProductionAcceptanceTransitioned` из Plan 3 Task 10. До появления этого контракта
отсутствующее покрытие accepted-accrual не разрешается компенсировать чтением
изменяемых act-таблиц.

Историческая hierarchy version, currency и allocation method должны приходить из
детерминированного backfill. Значение `unresolved` и неизвестная currency сохраняются
как quality gap и не проходят readiness.

## Cursor и drill-down

Transport token принадлежит `Core/Reporting`. Owner adapters не декодируют и не
подписывают cursor или drill-down token. До завершения platform wiring:

- непустой `ReportCursor` в `page()` отклоняется `REPORT_CURSOR_INVALID`;
- raw `ReportDrillDownRequest` отклоняется `REPORT_CURSOR_INVALID`;
- после platform-проверки owner может принять только
  `ValidatedPortfolioDrillDownCell` или `ValidatedHoldingDrillDownCell`.

Plan 1c/Core integration должна передавать validated row key и column id в typed
owner method. Удалять fail-closed ветку до этой интеграции нельзя. Export читает
тот же immutable snapshot через `cursor()` и не имеет отдельного owner exporter.

## Миграции и порядок включения

Миграции только подготовлены и локально не запускаются:

1. `2026_07_26_000100_create_budgeting_portfolio_report_projections.php`;
2. `2026_07_26_020000_create_holding_allocation_fact_versions_table.php`;
3. `2026_07_26_020100_create_holding_performance_reporting_tables.php`;
4. `2026_07_26_020200_create_intercompany_contract_flow_reporting_tables.php`.

После применения в isolated CI сначала выполняются source/backfill проверки, затем
provider PostgreSQL contracts. Только complete/fresh snapshots с нулевыми
hierarchy/currency gaps могут войти в candidate evidence. Global registry, active
manifest и production publication этим срезом не изменяются.
