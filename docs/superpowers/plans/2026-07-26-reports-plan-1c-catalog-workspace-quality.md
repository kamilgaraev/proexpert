# План 1c: catalog, workspace и quality gates платформы отчётности МОСТ

> **Для agentic workers:** REQUIRED SUB-SKILL: используйте `superpowers:subagent-driven-development` или `superpowers:executing-plans`. Выполняйте Tasks 1–12 строго по порядку; checkbox-шаги являются обязательными review checkpoints, каждая задача завершается отдельным commit.

**Goal:** Реализовать typed manifest и publication boundary МОСТ, номинально разделённые candidate/published registries, единственный immutable runtime binding map, generated catalog contracts, server workspace, saved views, полный lifecycle subscriptions и fail-closed platform/release evidence поверх неизменяемых Plan 1a и Plan 1b.

**Architecture:** `management-catalog.v1.yaml` является единственным источником identity, семи `catalog_group`, capabilities, versions, permissions и readiness 28 управленческих отчётов. Candidate registry возвращает только `CandidateReportDefinition`; promotion создаёт published bytes, после чего published registry возвращает только `PublishedReportDefinition` и assembler формирует единственный `ReportDefinitionBindingMap`, напрямую потребляемый Plan 1b. Workspace, saved views и subscriptions server-owned и tenant-scoped; generated artifacts byte-locked к raw manifest SHA-256. Plan 1c не реализует domain formulas, projections, row queries, materialization, export renderers, S3 или execution state machines.

**Tech Stack:** PHP 8.2, Laravel 11.50, PostgreSQL, Laravel Queue contracts, Symfony YAML, `opis/json-schema` 2.6.0 из Plan 1a lock, PHPUnit, Larastan/PHPStan, deterministic PHP generators.

---

## Жёсткие границы

- Работать только в отдельной ветке от актуального `main`.
- Новые PHP files используют `declare(strict_types=1);`, PSR-12 и `trans_message('key')`.
- Не изменять canonical DTO/ports/resources Plan 1a и execution/storage contracts Plan 1b round 3.
- Canonical namespace всех reporting symbols — только `App\BusinessModules\Core\Reporting`; второй reporting namespace запрещён.
- Plan 1b существует и напрямую потребляет Plan 1a `ReportDefinitionRegistry` и `ReportDefinitionBindingMap`; Plan 1c не создаёт execution resolver, alias, adapter map или второй binding protocol.
- Не создавать второй binding protocol: `register(binding)` и `assemble(publishedRegistry)` остаются единственными runtime methods.
- Candidate validation не создаёт `ReportDefinitionBindingMap` и не активирует runtime.
- Production manifest содержит ровно 28 management identities; `official_material_usage_m29` находится только в official manifest.
- Published definition требует ready source/formula, verified delivery, complete conformance evidence, resolvable seven-field binding и byte locks.
- Общий `ReportDefinition` создаётся factory и используется только как payload; registries возвращают номинальные wrappers, а consumers явно вызывают `payload()`.
- Семь закрытых catalog groups: `portfolio`, `projects`, `finance`, `procurement_warehouse`, `team`, `quality_safety`, `partners_customers`; каждая непуста и имеет deterministic order.
- Organization/owner берутся из `ReportExecutionContext`; requests не принимают `organization_id` или `owner_id`.
- Workspace recent reports ограничены 10 published codes; favourites уникальны; display preferences и порядок сохраняются сервером.
- Subscription v1 поддерживает только `in_app`, published definition, reproducible snapshot и active saved view.
- Subscription deliveries используют exact Plan 1a `CreateReportRunData`, `CreateReportExportData` и `IdempotencyKey`, проходят current reauthorization на каждой чувствительной фазе и завершаются только `notified|failed|expired`.
- `opis/json-schema` не добавляется повторно: Plan 1c потребляет exact locked package 2.6.0 и только `Opis\JsonSchema\CompliantValidator`.
- Локально разрешены unit/architecture tests, scoped PHPStan, syntax и offline generator `--check`.
- Миграции, DB CLI, tinker, dev server, build, browser/auth smoke и production access запрещены.
- PostgreSQL constraints, scheduler concurrency и feature API matrix запускаются только в CI.
- Missing, skipped, stale или hash-mismatched evidence блокирует publication и release completion.
- Plan 1c platform completion имеет status только `platform_passed`; `release_passed` невозможен до exactly 28 published definitions, 28 bindings, conformance/byte locks, Plans 2–3 evidence и всех QG-01–QG-14.
- В задачах нет постоянного обходного контура, parallel runtime registry, domain provider implementation или UI code.

## Canonical prerequisites

Plan 1a предоставляет и Plan 1c не переопределяет:

```php
interface ReportDefinitionRegistry
{
    public function published(string $code): PublishedReportDefinition;

    public function publishedCodes(): array;

    public function manifestSha256(): Sha256Hash;
}

interface CandidateReportDefinitionRegistry
{
    public function candidate(string $code): CandidateReportDefinition;

    public function candidateCodes(): array;
}

interface ReportDefinitionBindingAssembler
{
    public function register(ReportDefinitionBinding $binding): void;

    public function assemble(ReportDefinitionRegistry $publishedRegistry): ReportDefinitionBindingMap;
}

interface ReportDefinitionCandidateValidator
{
    public function validate(
        CandidateReportDefinitionRegistry $candidateRegistry,
        iterable $bindings,
    ): ReportCandidateValidationResult;
}
```

`CandidateReportDefinitionRegistry` не наследует `ReportDefinitionRegistry`. Factory создаёт общий payload `ReportDefinition`; candidate/published registries оборачивают его соответственно в `CandidateReportDefinition`/`PublishedReportDefinition`. Любая проверка содержимого сначала получает wrapper и вызывает `payload()`.

Execution/source conformance использует только Plan 1a owner ports:

```php
interface ReportDataProvider
{
    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef;

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): ReportResult;
}
```

Plan 1b round 3 не экспортирует отдельный execution resolver и запрещает его добавление. Его coordinators/jobs получают exact Plan 1a registry/map:

```php
ReportDefinitionRegistry::published(string $code): PublishedReportDefinition;
ReportDefinitionBindingMap::get(string $code): ReportDefinitionBinding;
```

Plan 1c container wiring создаёт один singleton published registry и один singleton map через `ReportDefinitionBindingAssembler::assemble()`. Plan 1b использует эти два instances напрямую; candidate registry не внедряется ни в один execution service.

Subscriptions вызывают только exact Plan 1a action ports, реализованные Plan 1b:

```php
CreateReportRunAction::handle(
    ReportExecutionContext $context,
    CreateReportRunData $data,
    IdempotencyKey $idempotencyKey,
): ReportRun;

GetReportRunAction::handle(
    ReportExecutionContext $context,
    string $runId,
): ReportRun;

CreateReportExportAction::handle(
    ReportExecutionContext $context,
    string $runId,
    CreateReportExportData $data,
    IdempotencyKey $idempotencyKey,
): ReportExport;

GetReportExportAction::handle(
    ReportExecutionContext $context,
    string $exportId,
): ReportExport;
```

Subscription code не вызывает Plan 1b coordinator, stores, models, queue backend, renderer, `FileService` или domain provider напрямую.

## Task ownership

| Task | Deliverable |
|---|---|
| 1 | Opis-backed Draft 2020-12 validator, exact management/official YAML, семь groups и strict schemas |
| 2 | Typed loader, hash, management/candidate/published/official registries |
| 3 | Generic source/formula conformance harness и evidence schema |
| 4 | Candidate validator, exact-set assembler, singleton published registry/map wiring для Plan 1b |
| 5 | Semantic version policy, promotion service, byte locks и publication ledger |
| 6 | Семигрупповой backend/frontend catalog, permissions/translations generator и catalog action |
| 7 | Server workspace recent/favourites/display preferences |
| 8 | Saved-view persistence, migration lifecycle и API |
| 9 | Subscription aggregate, persistence, scheduler, phased delivery, notifier, audit/telemetry |
| 10 | Subscription API, dedicated cursor page/resources, RBAC и run-now idempotency |
| 11 | Offline quality-gate failure model, platform/final gates и release evidence |
| 12 | Cross-plan prerequisite proof и `platform_passed` completion evidence |

## Preflight

Run: `git branch --show-current`

Expected: branch не равна `main`.

Run: `git status --short`

Expected: пустой вывод.

Run: `vendor/bin/phpunit tests/Architecture/Reporting/PlanOneAHandoffContractTest.php`

Expected: Plan 1a contract lock PASS. Если test отсутствует, Plan 1c не начинать.

Run: `composer show --locked opis/json-schema`

Expected: package version exactly `2.6.0`; никакая dependency mutation не выполняется.

Run: `Test-Path build/reports/plan-1b-completion.json`

Expected: `True`; artifact остаётся ignored/untracked и будет проверен по schema/digest в Task 12, а не по одному наличию.

---

### Task 1: Opis-backed exact manifests, семь catalog groups и strict schemas

**Files:**

- Create: `app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml`
- Create: `app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json`
- Create: `app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml`
- Create: `app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.schema.json`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportCatalogGroup.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSourceReadiness.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportFormulaReadiness.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportDeliveryReadiness.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/OfficialDocumentDefinition.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Validation/Draft202012SchemaValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Validation/ReportSchemaValidationException.php`
- Test: `tests/Unit/Reporting/Validation/Draft202012SchemaValidatorTest.php`
- Test: `tests/Architecture/Reporting/ReportManifestIdentityContractTest.php`

**Interfaces consumed:**

- Plan 1a locked dependency `opis/json-schema` exact version `2.6.0`.
- `Opis\JsonSchema\CompliantValidator::validate(object $data, object $schema): ValidationResult`.
- Plan 1a `ReportPublicationReadiness` and `Sha256Hash`.

**Interfaces produced:**

```php
enum ReportCatalogGroup: string
{
    case PORTFOLIO = 'portfolio';
    case PROJECTS = 'projects';
    case FINANCE = 'finance';
    case PROCUREMENT_WAREHOUSE = 'procurement_warehouse';
    case TEAM = 'team';
    case QUALITY_SAFETY = 'quality_safety';
    case PARTNERS_CUSTOMERS = 'partners_customers';

    public static function ordered(): array
    {
        return [
            self::PORTFOLIO,
            self::PROJECTS,
            self::FINANCE,
            self::PROCUREMENT_WAREHOUSE,
            self::TEAM,
            self::QUALITY_SAFETY,
            self::PARTNERS_CUSTOMERS,
        ];
    }
}
```

```php
final class Draft202012SchemaValidator
{
    public function __construct(private CompliantValidator $validator)
    {
    }

    public function validate(object $document, object $schema): ValidationResult
    {
        return $this->validator->validate($document, $schema);
    }

    public function assertValid(object $document, object $schema, string $schemaId): void
    {
        if (!$this->validate($document, $schema)->isValid()) {
            throw new ReportSchemaValidationException($schemaId);
        }
    }
}

final class ReportSchemaValidationException extends RuntimeException
{
    public function __construct(public readonly string $schemaId)
    {
        parent::__construct('report_schema_invalid');
    }
}
```

`ReportSchemaValidationException` является internal loader/offline failure, содержит только allowlisted schema ID и никогда не переносит Opis error tree, document fragment или manifest values в HTTP/log context.

**Schema contract:** management root содержит только `catalog`, `contract_version`, `definitions`; contract равен `1.0.0`. Definition требует `code,title_key,catalog_group,category,grain,wave,filters,columns,sorts,formats,versions,permissions,readiness,capabilities`. `catalog_group` — закрытый enum из семи значений выше, `category` остаётся отдельным техническим facet. Versions требуют `contract,formula,source_schema,renderer`. Permission policy содержит arrays `view,export,sensitive,audit`. Readiness содержит `source,formula,delivery,publication`. Candidate/published definitions требуют non-empty filters/columns/sorts/formats; published дополнительно требует source/formula `ready`, delivery `verified`. Official root содержит один M-29 и не принимает management codes.

```php
enum ReportSourceReadiness: string
{
    case READY = 'ready';
    case PARTIAL = 'partial';
    case AGGREGATION_REQUIRED = 'aggregation_required';
    case EVENT_REQUIRED = 'event_required';
    case BLOCKED_BY_SOURCE = 'blocked_by_source';
}

enum ReportFormulaReadiness: string
{
    case READY = 'ready';
    case CONTRACT_REQUIRED = 'contract_required';
    case POLICY_REQUIRED = 'policy_required';
    case BLOCKED_BY_SOURCE = 'blocked_by_source';
}

enum ReportDeliveryReadiness: string
{
    case NOT_IMPLEMENTED = 'not_implemented';
    case VERIFIED = 'verified';
}
```

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "most.management-catalog.v1",
  "type": "object",
  "additionalProperties": false,
  "required": ["catalog", "contract_version", "definitions"],
  "properties": {
    "catalog": {"const": "management-catalog.v1"},
    "contract_version": {"const": "1.0.0"},
    "definitions": {
      "type": "array",
      "minItems": 28,
      "maxItems": 28,
      "items": {"$ref": "#/$defs/definition"}
    }
  },
  "$defs": {
    "version": {"type": "string", "pattern": "^[0-9]+\\.[0-9]+\\.[0-9]+$"},
    "permissions": {
      "type": "object",
      "additionalProperties": false,
      "required": ["view", "export", "sensitive", "audit"],
      "properties": {
        "view": {"type": "array", "minItems": 1, "items": {"type": "string"}, "uniqueItems": true},
        "export": {"type": "array", "minItems": 1, "items": {"type": "string"}, "uniqueItems": true},
        "sensitive": {"type": "array", "items": {"type": "string"}, "uniqueItems": true},
        "audit": {"type": "array", "items": {"type": "string"}, "uniqueItems": true}
      }
    },
    "versions": {
      "type": "object",
      "additionalProperties": false,
      "required": ["contract", "formula", "source_schema", "renderer"],
      "properties": {
        "contract": {"$ref": "#/$defs/version"},
        "formula": {"$ref": "#/$defs/version"},
        "source_schema": {"$ref": "#/$defs/version"},
        "renderer": {"$ref": "#/$defs/version"}
      }
    },
    "readiness": {
      "type": "object",
      "additionalProperties": false,
      "required": ["source", "formula", "delivery", "publication"],
      "properties": {
        "source": {"enum": ["ready", "partial", "aggregation_required", "event_required", "blocked_by_source"]},
        "formula": {"enum": ["ready", "contract_required", "policy_required", "blocked_by_source"]},
        "delivery": {"enum": ["not_implemented", "verified"]},
        "publication": {"enum": ["draft", "candidate", "published", "blocked"]}
      }
    },
    "capabilities": {
      "type": "object",
      "additionalProperties": false,
      "required": ["supports_subscriptions", "reproducible_scheduled_snapshot"],
      "properties": {
        "supports_subscriptions": {"type": "boolean"},
        "reproducible_scheduled_snapshot": {"type": "boolean"}
      }
    },
    "definition": {
      "type": "object",
      "additionalProperties": false,
      "required": ["code", "title_key", "catalog_group", "category", "grain", "wave", "filters", "columns", "sorts", "formats", "versions", "permissions", "readiness", "capabilities"],
      "properties": {
        "code": {"type": "string", "pattern": "^[a-z][a-z0-9_]{2,63}$"},
        "title_key": {"type": "string", "pattern": "^reports\\.catalog\\.[a-z][a-z0-9_]+$"},
        "catalog_group": {"enum": ["portfolio", "projects", "finance", "procurement_warehouse", "team", "quality_safety", "partners_customers"]},
        "category": {"type": "string", "minLength": 2},
        "grain": {"type": "string", "minLength": 2},
        "wave": {"enum": [1, 2, 3]},
        "filters": {"type": "array", "items": {"type": "object"}},
        "columns": {"type": "array", "items": {"type": "object"}},
        "sorts": {"type": "array", "items": {"type": "object"}},
        "formats": {"type": "array", "items": {"enum": ["csv", "xlsx", "pdf"]}, "uniqueItems": true},
        "versions": {"$ref": "#/$defs/versions"},
        "permissions": {"$ref": "#/$defs/permissions"},
        "readiness": {"$ref": "#/$defs/readiness"},
        "capabilities": {"$ref": "#/$defs/capabilities"}
      },
      "allOf": [
        {
          "if": {"properties": {"readiness": {"properties": {"publication": {"enum": ["candidate", "published"]}}}}},
          "then": {"properties": {"filters": {"minItems": 1}, "columns": {"minItems": 1}, "sorts": {"minItems": 1}, "formats": {"minItems": 1}}}
        },
        {
          "if": {"properties": {"readiness": {"properties": {"publication": {"const": "published"}}}}},
          "then": {"properties": {"readiness": {"properties": {"source": {"const": "ready"}, "formula": {"const": "ready"}, "delivery": {"const": "verified"}}}}
        }
      ]
    }
  }
}
```

Production YAML содержит header и следующие exact identity blocks. Empty capabilities допустимы только при `draft|blocked`; Plans 2–3 заменяют их candidate-ready content перед conformance. Exact group mapping byte-lock:

| Group | Exact management codes |
|---|---|
| `portfolio` | `project_portfolio_health`, `holding_performance` |
| `projects` | `project_evm_control`, `baseline_schedule_variance`, `lookahead_readiness`, `accepted_production_progress`, `change_claim_contingency`, `handover_readiness` |
| `finance` | `intercompany_contract_flows`, `portfolio_liquidity`, `project_margin`, `budget_plan_fact`, `wip_completion_forecast`, `contract_settlement_exposure`, `management_pnl` |
| `procurement_warehouse` | `procurement_cycle`, `supplier_award_competitiveness`, `supply_reliability`, `inventory_risk` |
| `team` | `workforce_capacity`, `attendance_execution`, `project_labor_cost`, `payroll_readiness` |
| `quality_safety` | `quality_defect_flow`, `safety_incident_actions`, `workforce_admission` |
| `partners_customers` | `contractor_scorecard`, `customer_sla` |

```yaml
catalog: management-catalog.v1
contract_version: 1.0.0
definitions:
  - {code: project_portfolio_health, title_key: reports.catalog.project_portfolio_health, catalog_group: portfolio, category: portfolio, grain: project_currency_as_of, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.portfolio_dashboard.view], export: [budgeting.portfolio_dashboard.export], sensitive: [], audit: []}, readiness: {source: partial, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: holding_performance, title_key: reports.catalog.holding_performance, catalog_group: portfolio, category: portfolio, grain: organization_project_currency_period, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [multi-organization.reports.kpi], export: [multi-organization.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: intercompany_contract_flows, title_key: reports.catalog.intercompany_contract_flows, catalog_group: finance, category: finance, grain: allocation_counterparty_period, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [multi-organization.reports.financial], export: [multi-organization.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: portfolio_liquidity, title_key: reports.catalog.portfolio_liquidity, catalog_group: finance, category: finance, grain: day_project_currency_scenario, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.cfo.view], export: [budgeting.cash_gap.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: project_evm_control, title_key: reports.catalog.project_evm_control, catalog_group: projects, category: control, grain: task_baseline_status_date, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [reports.project_control.view], export: [reports.project_control.export], sensitive: [budgeting.wip_forecast.view_sensitive_costs], audit: []}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: baseline_schedule_variance, title_key: reports.catalog.baseline_schedule_variance, catalog_group: projects, category: schedule, grain: task_baseline_as_of, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [schedule.view], export: [schedule.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: lookahead_readiness, title_key: reports.catalog.lookahead_readiness, catalog_group: projects, category: schedule, grain: constraint_task_window, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [schedule.view], export: [schedule.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: policy_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: accepted_production_progress, title_key: reports.catalog.accepted_production_progress, catalog_group: projects, category: production, grain: accepted_work_day, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [reports.production_progress.view], export: [reports.production_progress.export], sensitive: [budgeting.wip_forecast.view_sensitive_costs], audit: []}, readiness: {source: event_required, formula: blocked_by_source, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: project_margin, title_key: reports.catalog.project_margin, catalog_group: finance, category: finance, grain: project_article_currency_period, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.project_margin.view], export: [budgeting.project_margin.export], sensitive: [], audit: []}, readiness: {source: partial, formula: ready, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: budget_plan_fact, title_key: reports.catalog.budget_plan_fact, catalog_group: finance, category: finance, grain: budget_period_article_currency, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.plan_fact.view], export: [budgeting.plan_fact.export], sensitive: [], audit: []}, readiness: {source: ready, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: wip_completion_forecast, title_key: reports.catalog.wip_completion_forecast, catalog_group: finance, category: finance, grain: forecast_provider_currency, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.wip_forecast.view], export: [budgeting.wip_forecast.export], sensitive: [budgeting.wip_forecast.view_sensitive_costs], audit: [budgeting.wip_forecast.view_audit]}, readiness: {source: partial, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: contract_settlement_exposure, title_key: reports.catalog.contract_settlement_exposure, catalog_group: finance, category: finance, grain: allocation_direction_currency, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [contracts.management_report.view], export: [contracts.management_report.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: management_pnl, title_key: reports.catalog.management_pnl, catalog_group: finance, category: finance, grain: organization_article_period_scenario, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [budgeting.management_pnl.view], export: [budgeting.management_pnl.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: policy_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: change_claim_contingency, title_key: reports.catalog.change_claim_contingency, catalog_group: projects, category: control, grain: change_version_allocation_currency, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [change-management.view], export: [change-management.reports.export], sensitive: [], audit: []}, readiness: {source: event_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: procurement_cycle, title_key: reports.catalog.procurement_cycle, catalog_group: procurement_warehouse, category: procurement, grain: request_line_process, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [procurement.dashboard.view], export: [procurement.reports.export], sensitive: [], audit: [procurement.audit.view]}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: supplier_award_competitiveness, title_key: reports.catalog.supplier_award_competitiveness, catalog_group: procurement_warehouse, category: procurement, grain: decision_proposal_currency, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [procurement.supplier_proposals.view], export: [procurement.reports.export], sensitive: [procurement.proposal_decisions.view], audit: []}, readiness: {source: aggregation_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: supply_reliability, title_key: reports.catalog.supply_reliability, catalog_group: procurement_warehouse, category: procurement, grain: purchase_order_line_promise, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [procurement.purchase_orders.view], export: [procurement.reports.export], sensitive: [], audit: []}, readiness: {source: event_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: inventory_risk, title_key: reports.catalog.inventory_risk, catalog_group: procurement_warehouse, category: warehouse, grain: material_warehouse_day, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [warehouse.advanced.view], export: [warehouse.reports.export], sensitive: [warehouse.view_custody], audit: []}, readiness: {source: event_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: workforce_capacity, title_key: reports.catalog.workforce_capacity, catalog_group: team, category: workforce, grain: staff_unit_project_month, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [workforce.view], export: [workforce.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: attendance_execution, title_key: reports.catalog.attendance_execution, catalog_group: team, category: workforce, grain: employee_shift_day, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [workforce.view], export: [workforce.reports.export], sensitive: [workforce.audit.view], audit: []}, readiness: {source: aggregation_required, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: project_labor_cost, title_key: reports.catalog.project_labor_cost, catalog_group: team, category: workforce, grain: approved_entry_employee_day, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [time_tracking.view], export: [time_tracking.reports.export], sensitive: [time_tracking.cost.view], audit: []}, readiness: {source: ready, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: payroll_readiness, title_key: reports.catalog.payroll_readiness, catalog_group: team, category: workforce, grain: period_employee_issue, wave: 1, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [workforce.view], export: [workforce.reports.export], sensitive: [], audit: [workforce.audit.view]}, readiness: {source: ready, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: quality_defect_flow, title_key: reports.catalog.quality_defect_flow, catalog_group: quality_safety, category: quality_safety, grain: defect_transition_project, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [quality-control.defects.view], export: [quality-control.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: policy_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: safety_incident_actions, title_key: reports.catalog.safety_incident_actions, catalog_group: quality_safety, category: quality_safety, grain: incident_action_site_day, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [safety-management.view], export: [safety-management.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: policy_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: workforce_admission, title_key: reports.catalog.workforce_admission, catalog_group: quality_safety, category: quality_safety, grain: person_site_requirement_day, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [safety-management.view], export: [safety-management.reports.export], sensitive: [safety-management.medical.view], audit: []}, readiness: {source: aggregation_required, formula: ready, delivery: not_implemented, publication: draft}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: handover_readiness, title_key: reports.catalog.handover_readiness, catalog_group: projects, category: handover, grain: gate_location_package, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [reports.project_readiness.view], export: [reports.project_readiness.export], sensitive: [], audit: []}, readiness: {source: event_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: contractor_scorecard, title_key: reports.catalog.contractor_scorecard, catalog_group: partners_customers, category: partners, grain: contractor_category_cohort, wave: 2, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [contractor_marketplace.profile.view], export: [contractor_marketplace.reports.export], sensitive: [], audit: []}, readiness: {source: aggregation_required, formula: policy_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
  - {code: customer_sla, title_key: reports.catalog.customer_sla, catalog_group: partners_customers, category: customers, grain: request_event_customer, wave: 3, filters: [], columns: [], sorts: [], formats: [], versions: {contract: 1.0.0, formula: 0.0.0, source_schema: 0.0.0, renderer: 1.0.0}, permissions: {view: [customer.sla_report.view], export: [customer.sla_report.export], sensitive: [], audit: []}, readiness: {source: event_required, formula: contract_required, delivery: not_implemented, publication: blocked}, capabilities: {supports_subscriptions: false, reproducible_scheduled_snapshot: false}}
```

Official YAML:

```yaml
catalog: official-document-catalog.v1
contract_version: 1.0.0
definitions:
  - code: official_material_usage_m29
    title_key: reports.official.official_material_usage_m29
    renderer_version: 1.0.0
    publication_readiness: blocked
    legal_retention_policy: unassigned
    seal_requires: [opening_balance, receipts, actual_consumption, approved_normative_consumption, closing_balance, source_refs, versioned_coefficients]
```

Official schema root:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "most.official-document-catalog.v1",
  "type": "object",
  "additionalProperties": false,
  "required": ["catalog", "contract_version", "definitions"],
  "properties": {
    "catalog": {"const": "official-document-catalog.v1"},
    "contract_version": {"const": "1.0.0"},
    "definitions": {
      "type": "array",
      "minItems": 1,
      "maxItems": 1,
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["code", "title_key", "renderer_version", "publication_readiness", "legal_retention_policy", "seal_requires"],
        "properties": {
          "code": {"const": "official_material_usage_m29"},
          "title_key": {"const": "reports.official.official_material_usage_m29"},
          "renderer_version": {"type": "string", "pattern": "^[0-9]+\\.[0-9]+\\.[0-9]+$"},
          "publication_readiness": {"enum": ["blocked", "candidate", "published"]},
          "legal_retention_policy": {"type": "string", "minLength": 2},
          "seal_requires": {"type": "array", "minItems": 7, "uniqueItems": true, "items": {"type": "string"}}
        }
      }
    }
  }
}
```

- [ ] **RED:** создать Opis и identity tests до manifests/validator.

```php
public function test_management_and_official_documents_are_valid_draft_2020_12(): void
{
    $validator = new Draft202012SchemaValidator(new CompliantValidator());

    self::assertTrue($validator->validate(
        $this->yamlObject('management-catalog.v1.yaml'),
        $this->jsonObject('management-catalog.v1.schema.json'),
    )->isValid());
    self::assertTrue($validator->validate(
        $this->yamlObject('official-document-catalog.v1.yaml'),
        $this->jsonObject('official-document-catalog.v1.schema.json'),
    )->isValid());
}

public function test_unknown_field_and_eighth_catalog_group_fail_closed(): void
{
    $document = $this->yamlObject('management-catalog.v1.yaml');
    $document->unexpected = true;
    self::assertFalse($this->validator()->validate($document, $this->managementSchema())->isValid());

    $document = $this->yamlObject('management-catalog.v1.yaml');
    $document->definitions[0]->catalog_group = 'operations';
    self::assertFalse($this->validator()->validate($document, $this->managementSchema())->isValid());
}
```

Identity test additionally asserts exact 28 unique codes, waves `12/10/6`, exact seven non-empty groups and order, table mapping above, M-29 separation, valid UTF-8, exact required keys and no duplicate permissions/formats.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Validation/Draft202012SchemaValidatorTest.php tests/Architecture/Reporting/ReportManifestIdentityContractTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator" not found`.

- [ ] **GREEN:** создать validator, exception, enums, schemas, manifests и official DTO ровно по contracts выше.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Validation/Draft202012SchemaValidatorTest.php tests/Architecture/Reporting/ReportManifestIdentityContractTest.php`

Expected GREEN: `OK (14 tests, 178 assertions)`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/Enums app/BusinessModules/Core/Reporting/Domain/DTO/OfficialDocumentDefinition.php app/BusinessModules/Core/Reporting/Infrastructure/Validation --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.schema.json app/BusinessModules/Core/Reporting/Domain/Enums/ReportCatalogGroup.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSourceReadiness.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportFormulaReadiness.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportDeliveryReadiness.php app/BusinessModules/Core/Reporting/Domain/DTO/OfficialDocumentDefinition.php app/BusinessModules/Core/Reporting/Infrastructure/Validation tests/Unit/Reporting/Validation/Draft202012SchemaValidatorTest.php tests/Architecture/Reporting/ReportManifestIdentityContractTest.php`

Run: `git commit -m "feat[reports]: добавлены канонические manifests отчётности"`

### Task 2: Fail-closed loader, metadata и nominal registries

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/LoadedReportManifest.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogMetadata.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSchedulingCapability.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportCatalogMetadataRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSchedulingCapabilityRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/ReportManifestSemanticValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/ReportDefinitionFactory.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/YamlReportManifestLoader.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/YamlCandidateReportDefinitionRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/PublishedReportDefinitionRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/ManifestReportCatalogMetadataRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/ManifestReportSchedulingCapabilityRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/OfficialDocumentDefinitionRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/ReportPermissionCatalog.php`
- Create: `tests/Fixtures/Reporting/Manifest/management.valid.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.duplicate-code.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.unknown-permission.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.invalid-readiness.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.invalid-group.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.candidate-empty-capability.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/management.contains-m29.yaml`
- Create: `tests/Fixtures/Reporting/Manifest/official.valid.yaml`
- Test: `tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php`
- Test: `tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php`

**Interfaces consumed:**

- Task 1 `Draft202012SchemaValidator`, both schemas and readiness/group enums.
- Plan 1a `ReportDefinition`, `CandidateReportDefinition`, `PublishedReportDefinition`, both registry interfaces, `ReportPermissionPolicy`, `ReportPublicationReadiness`, `Sha256Hash`, `CanonicalJson`, `ReportContractException`, `ReportErrorCode`.

**Interfaces produced:**

```php
final readonly class LoadedReportManifest
{
    public function __construct(
        public string $catalog,
        public string $contractVersion,
        public Sha256Hash $bytesHash,
        public array $definitions,
    ) {
    }
}

final readonly class ReportCatalogMetadata
{
    public function __construct(
        public string $code,
        public string $titleKey,
        public ReportCatalogGroup $catalogGroup,
        public string $category,
        public string $grain,
        public int $wave,
        public int $manifestOrdinal,
    ) {
    }
}

final readonly class ReportSchedulingCapability
{
    public function __construct(
        public string $code,
        public bool $supportsSubscriptions,
        public bool $reproducibleScheduledSnapshot,
    ) {
    }
}
```

`LoadedReportManifest::definitions` — ordered `list<array<string,mixed>>`, exactly 28 management rows or exactly one official row; constructor rejects other catalog/count combinations and duplicate codes. Loader assigns zero-based `manifestOrdinal` from raw YAML list position without sorting. Management ordinals are unique and contiguous `0..27`; official ordinal is `0`. The ordinal is typed catalog metadata, not a user-visible wire field and not derived later from registry iteration order.

```php
interface ReportCatalogMetadataRegistry
{
    public function published(string $code): ReportCatalogMetadata;
}

interface ReportSchedulingCapabilityRegistry
{
    public function published(string $code): ReportSchedulingCapability;
}

final class YamlReportManifestLoader
{
    public function __construct(
        private Draft202012SchemaValidator $schemas,
        private ReportManifestSemanticValidator $semantics,
        private ReportPermissionCatalog $permissions,
    ) {
    }

    public function loadManagement(string $path, string $schemaPath): LoadedReportManifest;

    public function loadOfficial(string $path, string $schemaPath): LoadedReportManifest;
}

final class ReportDefinitionFactory
{
    public function fromManifest(array $row): ReportDefinition;

    public function metadataFromManifest(
        array $row,
        int $manifestOrdinal,
    ): ReportCatalogMetadata;

    public function schedulingFromManifest(array $row): ReportSchedulingCapability;
}
```

Loader выполняет exact sequence: один `file_get_contents`, UTF-8 check, raw-byte SHA-256, Symfony YAML parse, array→object conversion без изменения values, Task 1 Opis validation, duplicate/M-29/permission/group semantic checks, immutable `LoadedReportManifest`. Registry enumerates the unchanged row list and passes its zero-based index only to `metadataFromManifest()`; ordinal is never injected into raw definition data. `ReportDefinitionFactory::fromManifest()` создаёт общий Plan 1a payload и вычисляет `definitionHash` из `CanonicalJson` raw definition без resolved Russian strings or ordinal; translated text и position в semantic hash не входят.

Nominal registries:

```php
final class YamlCandidateReportDefinitionRegistry implements CandidateReportDefinitionRegistry
{
    public function __construct(
        LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
    ) {
    }

    public function candidate(string $code): CandidateReportDefinition;

    public function candidateCodes(): array;
}

final class PublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    public function __construct(
        LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
    ) {
    }

    public function published(string $code): PublishedReportDefinition;

    public function publishedCodes(): array;

    public function manifestSha256(): Sha256Hash;
}
```

Candidate constructor indexes only rows with `publication=candidate`, calls `new CandidateReportDefinition($factory->fromManifest($row))` and returns that wrapper. Published constructor indexes only rows with `publication=published`, calls `new PublishedReportDefinition(...)` and returns that wrapper. `draft|blocked|unknown` lookup uses `REPORT_NOT_FOUND`. Neither registry returns common `ReportDefinition`; metadata/scheduling registries accept the same published code set and fail when their bytes hash differs from the published registry hash.

`ReportPermissionCatalog::assertKnownAndTranslated(array $permissionSlugs): void` checks each slug against RoleDefinitions/module permission sources and `lang/ru/permissions.php`; it never writes roles. `ReportManifestSemanticValidator::assertManagement(array $document): void` validates exact code/group table, waves `12/10/6`, readiness/capability conditionals, unique IDs and nontechnical title/permission keys. `assertOfficial()` permits only M-29.

- [ ] **RED:** создать loader и nominal-boundary tests.

```php
public function test_candidate_and_published_registries_return_nominal_wrappers(): void
{
    $candidate = $this->candidateRegistry()->candidate('candidate_report');
    $published = $this->publishedRegistry()->published('published_report');

    self::assertInstanceOf(CandidateReportDefinition::class, $candidate);
    self::assertInstanceOf(PublishedReportDefinition::class, $published);
    self::assertInstanceOf(ReportDefinition::class, $candidate->payload());
    self::assertInstanceOf(ReportDefinition::class, $published->payload());
}

public function test_published_lookup_never_exposes_candidate_or_blocked_payload(): void
{
    foreach (['candidate_report', 'blocked_report', 'unknown_report'] as $code) {
        try {
            $this->publishedRegistry()->published($code);
            self::fail($code);
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->code);
        }
    }
}

public function test_metadata_preserves_explicit_manifest_ordinal(): void
{
    $metadata = $this->metadataRegistryFromCodes([
        'project_portfolio_health',
        'holding_performance',
        'accepted_production_progress',
    ]);

    self::assertSame(0, $metadata->published('project_portfolio_health')->manifestOrdinal);
    self::assertSame(1, $metadata->published('holding_performance')->manifestOrdinal);
    self::assertSame(2, $metadata->published('accepted_production_progress')->manifestOrdinal);
}
```

Fixture matrix covers valid management/official, invalid UTF-8, schema unknown field, invalid group, duplicate code, unknown/untranslated permission, invalid readiness, candidate empty capability and management M-29. It also proves ordinals are unique/contiguous and preserve the non-lexicographic YAML list order.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader" not found`.

- [ ] **GREEN:** реализовать exact loader, factories, semantic checks, metadata/capability registries и nominal lifecycle registries.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php`

Expected GREEN: `OK (24 tests, 103 assertions)`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Infrastructure/Catalog app/BusinessModules/Core/Reporting/Application/Catalog/ReportPermissionCatalog.php app/BusinessModules/Core/Reporting/Domain/DTO/LoadedReportManifest.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogMetadata.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSchedulingCapability.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportCatalogMetadataRegistry.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSchedulingCapabilityRegistry.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/DTO/LoadedReportManifest.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogMetadata.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSchedulingCapability.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportCatalogMetadataRegistry.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSchedulingCapabilityRegistry.php app/BusinessModules/Core/Reporting/Infrastructure/Catalog app/BusinessModules/Core/Reporting/Application/Catalog/ReportPermissionCatalog.php tests/Fixtures/Reporting/Manifest tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php`

Run: `git commit -m "feat[reports]: добавлена fail-closed загрузка nominal catalog"`

### Task 3: Candidate-only source/formula conformance и executable evidence

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportConformanceFixture.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSourceConformanceEvidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportFormulaConformanceEvidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionConformanceEvidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportConformanceEvidenceRepository.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Conformance/ReportSourceConformanceHarness.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Conformance/FilesystemReportConformanceEvidenceRepository.php`
- Create: `docs/reports/contracts/report-conformance-evidence.schema.json`
- Create: `tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json`
- Create: `tests/Support/Reporting/ReportConformanceFixtureBuilder.php`
- Test: `tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php`
- Test: `tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php`

**Interfaces consumed:**

- Plan 1a nominal `CandidateReportDefinition`, common `ReportDefinition` payload, `ReportDefinitionBinding`, exact owner-port arities, `ReportExecutionContext`, `ReportQuery`, `ReportProgress`, `ReportWindowSort`, `ReportDrillDownRequest`, `Sha256Hash`, `CanonicalJson`.
- Task 1 `Draft202012SchemaValidator`.

**Interfaces produced:**

```php
final readonly class ReportConformanceFixture
{
    public function __construct(
        public Sha256Hash $fixtureHash,
        public int $expectedRowCount,
        public ReportWindowSort $sort,
        public int $pageLimit,
        public int $cursorChunkSize,
        public ReportDrillDownRequest $drillDown,
        public Sha256Hash $expectedTotalsHash,
    ) {
    }
}

final readonly class ReportSourceConformanceEvidence
{
    public function __construct(
        public Sha256Hash $sourceHash,
        public string $snapshotKind,
        public string $snapshotId,
        public int $rowCount,
        public Sha256Hash $rowsHash,
        public bool $passed,
        public array $assertionCodes,
    ) {
    }
}

final readonly class ReportFormulaConformanceEvidence
{
    public function __construct(
        public string $formulaVersion,
        public Sha256Hash $totalsHash,
        public bool $passed,
        public array $assertionCodes,
    ) {
    }
}

final readonly class ReportDefinitionConformanceEvidence
{
    public function __construct(
        public string $code,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $sourceSchemaVersion,
        public Sha256Hash $fixtureHash,
        public ReportSourceConformanceEvidence $source,
        public ReportFormulaConformanceEvidence $formula,
        public array $componentClassHashes,
        public int $assertionCount,
        public string $status,
        public string $commitSha,
        public DateTimeImmutable $generatedAt,
    ) {
    }

    public function passed(): bool;

    public function digest(): Sha256Hash;
}

interface ReportConformanceEvidenceRepository
{
    public function get(
        string $code,
        Sha256Hash $definitionHash,
        Sha256Hash $fixtureHash,
    ): ReportDefinitionConformanceEvidence;

    public function put(ReportDefinitionConformanceEvidence $evidence): void;
}
```

All lists above are typed/unique/sorted; limits are `1..100` for page and `1..5000` for cursor; `status` is `passed|failed`; `passed()` requires source/formula true and every assertion passed. Digest is SHA-256 of `CanonicalJson` excluding only the digest itself. Repository path is `build/reports/conformance/{code}/{definitionHash}/{fixtureHash}.json`, validates schema before write and after reread, uses atomic rename, and never commits generated evidence.

```php
final class ReportSourceConformanceHarness
{
    public function verify(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportConformanceFixture $fixture,
        string $commitSha,
        DateTimeImmutable $generatedAt,
    ): ReportDefinitionConformanceEvidence {
        $definition = $candidate->payload();
        $this->assertIdentity($definition, $binding, $query);

        $progress = new ReportProgress(0);
        $snapshot = $binding->dataProvider->materialize($context, $query, $progress);
        $result = $binding->dataProvider->result($context, $snapshot);
        $page = $binding->rowQuery->page(
            $context,
            $snapshot,
            $fixture->sort,
            null,
            $fixture->pageLimit,
        );
        $rows = $binding->rowQuery->cursor(
            $context,
            $snapshot,
            $fixture->sort,
            $fixture->cursorChunkSize,
        );
        $drill = $binding->drillDownProvider->drillDown(
            $context,
            $snapshot,
            $fixture->drillDown,
        );

        return $this->evidence(
            $candidate,
            $binding,
            $fixture,
            $snapshot,
            $result,
            $page,
            $rows,
            $drill,
            $commitSha,
            $generatedAt,
        );
    }
}
```

Harness принимает только candidate wrapper и сразу вызывает `payload()`. Он не обращается к published registry/assembler/map. Checks: code/hash/contract/formula/source-schema equality; scope equality; immutable snapshot; exact row count; unique `row_key`; page/cursor semantic equality; totals/quality/provenance equality; signed resource links; sensitive redaction. `unavailable`, changed source/query/definition hash, owner-scope drift, nonfinite values or row leakage produce failed evidence, not a runtime map.

Evidence schema is Draft 2020-12, `additionalProperties:false` recursively, requires all constructor fields, forbids keys `rows`, `filters`, `query`, `url`, `pii`, and is executed through Task 1 Opis adapter.

- [ ] **RED:** создать candidate-only happy/negative tests и positive/negative schema tests.

```php
public function test_published_wrapper_cannot_enter_candidate_harness(): void
{
    $method = new ReflectionMethod(ReportSourceConformanceHarness::class, 'verify');
    self::assertSame(
        CandidateReportDefinition::class,
        $method->getParameters()[0]->getType()?->getName(),
    );
}

public function test_evidence_with_raw_filters_fails_schema(): void
{
    $document = $this->validEvidence();
    $document->filters = ['project_id' => 1];
    self::assertFalse($this->validator()->validate($document, $this->schema())->isValid());
}
```

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Conformance\ReportSourceConformanceHarness" not found`.

- [ ] **GREEN:** реализовать DTO, candidate-only harness, filesystem repository и strict Opis-validated schema.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php`

Expected GREEN: `OK (18 tests, 87 assertions)`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Conformance app/BusinessModules/Core/Reporting/Infrastructure/Conformance app/BusinessModules/Core/Reporting/Domain/DTO/ReportConformanceFixture.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSourceConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportFormulaConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportConformanceEvidenceRepository.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/DTO/ReportConformanceFixture.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSourceConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportFormulaConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionConformanceEvidence.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportConformanceEvidenceRepository.php app/BusinessModules/Core/Reporting/Application/Conformance app/BusinessModules/Core/Reporting/Infrastructure/Conformance docs/reports/contracts/report-conformance-evidence.schema.json tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json tests/Support/Reporting/ReportConformanceFixtureBuilder.php tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php`

Run: `git commit -m "test[reports]: добавлен candidate source-conformance contract"`

### Task 4: Exact-set candidate validation, published assembly и Plan 1b container bridge

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/ReportBindingCompatibilityChecker.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/ReportCodeSetComparator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/StrictReportDefinitionCandidateValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/ImmutableReportDefinitionBindingAssembler.php`
- Create: `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Unit/Reporting/Catalog/ReportCodeSetComparatorTest.php`
- Test: `tests/Unit/Reporting/Catalog/ReportDefinitionCandidateValidatorTest.php`
- Test: `tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php`
- Test: `tests/Architecture/Reporting/CandidatePublishedBoundaryTest.php`
- Test: `tests/Architecture/Reporting/ReportingCatalogBindingsTest.php`
- Test: `tests/Contract/Reporting/PlanOneBPublishedBindingConsumptionTest.php`

**Interfaces consumed:**

- Plan 1a exact `ReportDefinitionCandidateValidator`, `ReportDefinitionBindingAssembler`, nominal registries/wrappers, `ReportDefinitionBinding`, `ReportDefinitionBindingMap`, `ReportCandidateValidationItem`, `ReportCandidateValidationResult`.
- Task 3 evidence repository.
- Plan 1b round-3 direct consumption of `ReportDefinitionRegistry` and `ReportDefinitionBindingMap`; no execution resolver symbol.

**Compatibility boundary:**

```php
final class ReportBindingCompatibilityChecker
{
    public function candidate(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
        ReportDefinitionConformanceEvidence $evidence,
    ): ReportCandidateValidationItem {
        $definition = $candidate->payload();

        return $this->checkCandidate($definition, $binding, $evidence);
    }

    public function runtime(
        PublishedReportDefinition $published,
        ReportDefinitionBinding $binding,
    ): void {
        $definition = $published->payload();

        $this->assertCodeHashVersionAndReadiness($definition, $binding);
    }
}
```

Both methods require equal code, definition hash and contract version. Candidate additionally requires passed evidence for exact definition+fixture hash and all three provider class hashes. Runtime additionally executes nullable readiness probe and rejects false. Common `ReportDefinition` is never a registry return type.

```php
final class StrictReportDefinitionCandidateValidator implements ReportDefinitionCandidateValidator
{
    public function __construct(
        private ReportConformanceEvidenceRepository $evidence,
        private ReportBindingCompatibilityChecker $compatibility,
        private ReportCodeSetComparator $codes,
    ) {
    }

    public function validate(
        CandidateReportDefinitionRegistry $candidateRegistry,
        iterable $bindings,
    ): ReportCandidateValidationResult {
        $byCode = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof ReportDefinitionBinding) {
                throw new InvalidArgumentException('candidate_binding_type_invalid');
            }
            if (array_key_exists($binding->code, $byCode)) {
                throw new LogicException('candidate_binding_duplicate');
            }
            $byCode[$binding->code] = $binding;
        }

        $candidateCodes = $this->codes->validate(
            $candidateRegistry->candidateCodes(),
            'candidate_code',
        );
        $bindingCodes = $this->codes->validate(
            array_keys($byCode),
            'candidate_binding_code',
        );
        if (!$this->codes->equal($candidateCodes, $bindingCodes)) {
            throw new LogicException('candidate_binding_set_mismatch');
        }

        $items = [];
        foreach ($candidateCodes as $code) {
            $candidate = $candidateRegistry->candidate($code);
            $proof = $this->evidence->get(
                $code,
                $candidate->definitionHash,
                $this->fixtureHashFor($code),
            );
            $items[] = $this->compatibility->candidate($candidate, $byCode[$code], $proof);
        }

        return new ReportCandidateValidationResult($items);
    }
}
```

```php
final class ReportCodeSetComparator
{
    public function validate(array $codes, string $subject): array
    {
        $seen = [];
        foreach ($codes as $code) {
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code) !== 1) {
                throw new InvalidArgumentException("{$subject}_invalid");
            }
            if (array_key_exists($code, $seen)) {
                throw new LogicException("{$subject}_duplicate");
            }
            $seen[$code] = true;
        }

        return array_values($codes);
    }

    public function equal(array $left, array $right): bool
    {
        $leftSet = $left;
        $rightSet = $right;
        sort($leftSet, SORT_STRING);
        sort($rightSet, SORT_STRING);

        return $leftSet === $rightSet;
    }
}
```

`validate()` rejects non-string, unsafe and duplicate codes independently, then returns the original deterministic order unchanged. `equal()` sorts only copies for equality comparison. Original candidate, manifest and registration order is never required to be lexicographic and is never mutated by set validation. Therefore every candidate has exactly one binding/evidence item; missing, extra, duplicate, wrong-type or unbound candidate input cannot be ignored. Validator never creates or returns `ReportDefinitionBindingMap`.

```php
final class ImmutableReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
{
    private array $bindings = [];
    private bool $frozen = false;

    public function __construct(
        private ReportBindingCompatibilityChecker $compatibility,
        private ReportCodeSetComparator $codes,
    ) {
    }

    public function register(ReportDefinitionBinding $binding): void
    {
        if ($this->frozen || array_key_exists($binding->code, $this->bindings)) {
            throw new LogicException('binding_registration_closed');
        }

        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $publishedRegistry): ReportDefinitionBindingMap
    {
        $publishedCodes = $this->codes->validate(
            $publishedRegistry->publishedCodes(),
            'published_code',
        );
        $registeredCodes = $this->codes->validate(
            array_keys($this->bindings),
            'registered_binding_code',
        );
        if (!$this->codes->equal($publishedCodes, $registeredCodes)) {
            throw new LogicException('published_binding_set_mismatch');
        }

        $resolved = [];
        foreach ($publishedCodes as $code) {
            $published = $publishedRegistry->published($code);
            $binding = $this->bindings[$code];
            $this->compatibility->runtime($published, $binding);
            $resolved[$code] = $binding;
        }

        $this->frozen = true;

        return new ReportDefinitionBindingMap($resolved);
    }
}
```

Exact order-independent set comparison occurs before freeze and before returning a map, so both missing and extra registered bindings fail. Type, safety and duplicate validation are distinct failures and run before comparison. `$resolved` follows the original `publishedCodes()` order, which the YAML registry preserves as manifest order; registration order does not affect map order. Failed assembly leaves registration open; freeze happens only after successful set/compatibility validation. A second container resolution returns the same frozen map instance through the singleton, not a rebuilt map.

**Authoritative container wiring:**

```php
final class ReportingCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportDefinitionRegistry::class, PublishedReportDefinitionRegistry::class);
        $this->app->singleton(CandidateReportDefinitionRegistry::class, YamlCandidateReportDefinitionRegistry::class);
        $this->app->singleton(ReportDefinitionBindingAssembler::class, ImmutableReportDefinitionBindingAssembler::class);
        $this->app->singleton(ReportDefinitionCandidateValidator::class, StrictReportDefinitionCandidateValidator::class);
        $this->app->singleton(
            ReportDefinitionBindingMap::class,
            fn (Application $app): ReportDefinitionBindingMap => $app
                ->make(ReportDefinitionBindingAssembler::class)
                ->assemble($app->make(ReportDefinitionRegistry::class)),
        );
    }
}
```

`bootstrap/providers.php` registers this provider exactly once after `ReportingExecutionServiceProvider::class` and before Plans 2–3 owner binding providers. Owner providers register final release bindings through the single Plan 1a assembler before the map singleton is first resolved. Platform phase resolves an empty map only when both published and registered sets are empty; final release requires exact 28/28.

The current Plan 1b contract intentionally has no additional execution resolver. Contract test boots Contracts→Execution→Catalog providers, replaces execution stores/dispatchers with deterministic fakes, supplies one published wrapper and one seven-field binding, invokes run/rows/drill/export handlers and proves each uses the exact singleton registry/map binding. Candidate registry is a throwing spy with zero calls.

- [ ] **RED:** create exact-set, combined-interface and real Plan 1b consumption tests.

```php
public function test_extra_registered_binding_fails_before_map_creation(): void
{
    $assembler = $this->assemblerWithBindings(['published_code', 'extra_code']);

    $this->expectExceptionMessage('published_binding_set_mismatch');
    $assembler->assemble($this->publishedRegistry(['published_code']));
}

public function test_non_lexicographic_manifest_order_is_accepted_and_preserved(): void
{
    $codes = [
        'project_portfolio_health',
        'holding_performance',
        'accepted_production_progress',
    ];
    $assembler = $this->assemblerWithBindings(array_reverse($codes));

    $map = $assembler->assemble($this->publishedRegistry($codes));

    self::assertSame($codes, array_keys($map->all()));
}

public function test_duplicate_registry_codes_fail_before_set_comparison(): void
{
    $this->expectExceptionMessage('published_code_duplicate');
    $this->assemblerWithBindings(['one'])->assemble(
        $this->publishedRegistry(['one', 'one']),
    );
}

public function test_wrong_type_registry_code_fails_before_set_comparison(): void
{
    $this->expectExceptionMessage('published_code_invalid');
    $this->assemblerWithBindings(['one'])->assemble(
        $this->publishedRegistry(['one', 42]),
    );
}

public function test_combined_registry_cannot_return_candidate_from_published_method(): void
{
    $candidate = $this->candidateDefinition('candidate_code');
    $registry = new class($candidate) implements ReportDefinitionRegistry, CandidateReportDefinitionRegistry {
        public function __construct(
            private readonly CandidateReportDefinition $definition,
        ) {
        }

        public function published(string $code): PublishedReportDefinition
        {
            return $this->candidate($code);
        }

        public function publishedCodes(): array
        {
            return [$this->definition->code];
        }

        public function manifestSha256(): Sha256Hash
        {
            return new Sha256Hash(str_repeat('a', 64));
        }

        public function candidate(string $code): CandidateReportDefinition
        {
            self::assertSame($this->definition->code, $code);

            return $this->definition;
        }

        public function candidateCodes(): array
        {
            return [$this->definition->code];
        }
    };

    $this->expectException(TypeError::class);
    $registry->published('candidate_code');
}
```

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/ReportCodeSetComparatorTest.php tests/Unit/Reporting/Catalog/ReportDefinitionCandidateValidatorTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php tests/Architecture/Reporting/CandidatePublishedBoundaryTest.php tests/Architecture/Reporting/ReportingCatalogBindingsTest.php tests/Contract/Reporting/PlanOneBPublishedBindingConsumptionTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportDefinitionBindingAssembler" not found`.

- [ ] **GREEN:** implement exact nominal compatibility, set-equality validator/assembler and authoritative singleton wiring.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/ReportCodeSetComparatorTest.php tests/Unit/Reporting/Catalog/ReportDefinitionCandidateValidatorTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php tests/Architecture/Reporting/CandidatePublishedBoundaryTest.php tests/Architecture/Reporting/ReportingCatalogBindingsTest.php tests/Contract/Reporting/PlanOneBPublishedBindingConsumptionTest.php`

Expected GREEN: `OK (28 tests, 139 assertions)`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Catalog/ReportBindingCompatibilityChecker.php app/BusinessModules/Core/Reporting/Application/Catalog/ReportCodeSetComparator.php app/BusinessModules/Core/Reporting/Application/Catalog/StrictReportDefinitionCandidateValidator.php app/BusinessModules/Core/Reporting/Application/Catalog/ImmutableReportDefinitionBindingAssembler.php app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Application/Catalog/ReportBindingCompatibilityChecker.php app/BusinessModules/Core/Reporting/Application/Catalog/ReportCodeSetComparator.php app/BusinessModules/Core/Reporting/Application/Catalog/StrictReportDefinitionCandidateValidator.php app/BusinessModules/Core/Reporting/Application/Catalog/ImmutableReportDefinitionBindingAssembler.php app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php bootstrap/providers.php tests/Unit/Reporting/Catalog/ReportCodeSetComparatorTest.php tests/Unit/Reporting/Catalog/ReportDefinitionCandidateValidatorTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php tests/Architecture/Reporting/CandidatePublishedBoundaryTest.php tests/Architecture/Reporting/ReportingCatalogBindingsTest.php tests/Contract/Reporting/PlanOneBPublishedBindingConsumptionTest.php`

Run: `git commit -m "feat[reports]: активирован единственный published binding map"`

### Task 5: Semantic versions, nominal promotion, byte locks и publication ledger

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionSemanticDiff.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportPublicationLock.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/PublishedDefinitionRelease.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Publication/ReportDefinitionVersionPolicy.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Publication/ReportManifestPromotionService.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Publication/FilesystemReportPublicationLedger.php`
- Create: `docs/reports/contracts/report-publication-lock.schema.json`
- Create: `docs/reports/contracts/report-publication-ledger.schema.json`
- Create: `docs/reports/contracts/report-candidate-validation.schema.json`
- Create: `scripts/reporting/promote-report-definition.php`
- Create: `tests/Fixtures/Reporting/Publication/candidate.valid.yaml`
- Create: `tests/Fixtures/Reporting/Publication/candidate.valid.sha256`
- Create: `tests/Fixtures/Reporting/Publication/candidate-validation.valid.json`
- Create: `tests/Fixtures/Reporting/Publication/published.expected.yaml`
- Create: `tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json`
- Create: `tests/Support/Reporting/Publication/ReportCandidateValidationFixtureBuilder.php`
- Test: `tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php`
- Test: `tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php`
- Test: `tests/Unit/Reporting/Publication/ReportCandidateValidationFixtureBuilderTest.php`
- Test: `tests/Architecture/Reporting/ReportPublicationSchemaTest.php`

**Interfaces consumed:**

- Task 2 loaded manifest and nominal registries.
- Task 3 exact conformance evidence/digest.
- Task 4 passed `ReportCandidateValidationResult`.
- Plan 1a `CandidateReportDefinition`, `PublishedReportDefinition`, `Sha256Hash`, `CanonicalJson`.
- Task 1 Opis adapter.

**Interfaces produced:**

```php
final readonly class ReportDefinitionSemanticDiff
{
    public function __construct(
        public bool $formulaChanged,
        public bool $sourceSchemaChanged,
        public bool $contractChanged,
        public bool $rendererChanged,
        public bool $permissionsChanged,
        public bool $readinessChanged,
    ) {
    }
}

final readonly class ReportPublicationLock
{
    public function __construct(
        public string $code,
        public Sha256Hash $previousManifestHash,
        public Sha256Hash $candidateManifestHash,
        public Sha256Hash $publishedManifestHash,
        public Sha256Hash $definitionHash,
        public Sha256Hash $conformanceHash,
        public string $releaseSha,
        public DateTimeImmutable $publishedAt,
    ) {
    }

    public function digest(): Sha256Hash;
}

final readonly class PublishedDefinitionRelease
{
    public function __construct(
        public PublishedReportDefinition $published,
        public ReportPublicationLock $lock,
        public string $publishedBytes,
        public Sha256Hash $publishedBytesHash,
    ) {
    }
}
```

All SHA/release/timestamp fields are constructor-validated; `publishedBytesHash` equals raw bytes and `published->definitionHash` equals lock definition hash.

```php
final class ReportManifestPromotionService
{
    public function promote(
        LoadedReportManifest $current,
        LoadedReportManifest $candidateManifest,
        CandidateReportDefinition $candidate,
        ReportCandidateValidationResult $validation,
        ReportDefinitionConformanceEvidence $conformance,
        Sha256Hash $expectedCandidateBytes,
        string $releaseSha,
        DateTimeImmutable $publishedAt,
    ): PublishedDefinitionRelease;
}
```

Promotion verifies:

1. candidate wrapper payload/code/hash equals candidate manifest row;
2. raw candidate bytes equal expected SHA-256;
3. validation has exactly one passed item for every candidate and target item passed;
4. conformance code/definition/fixture hash and digest match;
5. source/formula are `ready`, delivery `verified`, publication exactly `candidate`;
6. semantic version policy below passes;
7. no unrelated definition raw canonical block changes;
8. rendered output changes only target `publication:candidate→published`;
9. output passes Task 1 Opis schema and Task 2 semantic loader;
10. reloaded `PublishedReportDefinitionRegistry::published($code)` returns a `PublishedReportDefinition`, whose `payload()` equals target payload except readiness;
11. lock/ledger schemas pass before atomic ledger append.

Version policy:

- formula semantics change requires greater `formulaVersion`;
- source schema/filter/grain change requires greater `sourceSchemaVersion`;
- API filter/column/sort/format/group contract change requires greater `contractVersion`;
- renderer-only change requires greater `rendererVersion`;
- permission/readiness-only change preserves semantic versions;
- a version bump without matching changed dimension/evidence is rejected.

Ledger event ID is `reports:definition:{code}:published:{definitionHash}`; repeated identical event is idempotent, conflicting bytes fail. Script requires explicit `--current`, `--candidate`, `--candidate-sha256`, `--validation`, `--conformance`, `--release-sha`, `--published-at`, `--output`, `--lock-output`, `--check`. In `--check` it writes nothing; normal mode writes new files in the target directory and atomically renames only after reread/hash/schema verification. Plans 2–3 may supply candidate inputs/evidence but never call publication service or construct published wrappers.

**Deterministic candidate fixture provenance:**

`candidate.valid.yaml` is strict UTF-8 without BOM, uses LF only and ends in exactly one LF. `candidate.valid.sha256` contains exactly `^[0-9a-f]{64}\n$`: the 64 lowercase hex characters are SHA-256 of the complete raw `candidate.valid.yaml` byte sequence, including that YAML's one terminal LF; the checksum file itself has exactly one terminal LF and no other byte.

`ReportCandidateValidationFixtureBuilder` receives the loaded candidate manifest, its isolated `CandidateReportDefinitionRegistry` and the real keyed `array<string,ReportDefinitionBinding>`. It calls the concrete Task 4 `StrictReportDefinitionCandidateValidator` itself, refuses a failed result, and serializes only that returned `ReportCandidateValidationResult` through `CanonicalJson`. It also emits the checksum bytes above; neither checksum nor validation status/code/hash can be supplied by the caller.

`candidate-validation.valid.json` is that exact canonical byte sequence. Its strict Draft 2020-12 schema is `report-candidate-validation.schema.json`, recursively closes every object and has exact root members `artifact_id`, `schema_version`, `status`, `candidate_manifest`, `items`. Constants are `report_candidate_validation`, `1.0.0`, `passed`; `candidate_manifest` has exactly fixed path `tests/Fixtures/Reporting/Publication/candidate.valid.yaml`, raw-byte `sha256` and exact ordered candidate codes. `items` preserves that order and serializes every Task 4 item with exactly `code`, `definition_hash`, `passed`, `failure_codes`; every item is passed and has an empty failure-code list. Code set, order and definition hashes must equal the candidate wrappers parsed from the reread YAML bytes. The fixture test rebuilds both files from the concrete Task 4 validator and requires byte-for-byte equality with the tracked fixtures.

`promote-report-definition.php` canonicalizes all three fixture paths under the repository root, rereads raw candidate/checksum/validation bytes, validates the JSON through the Task 1 Opis adapter, requires canonical decode→encode byte equality, and independently recomputes the candidate manifest SHA/code/definition-hash tuple before constructing the typed result. A caller-authored `passed` scalar, digest-only item, alternate path, noncanonical JSON or validation document that differs from the Task 4-generated fixture is never accepted.

- [ ] **RED:** create semantic, nominal promotion, candidate provenance and Opis schema tests.

```php
public function test_promotion_returns_published_wrapper_only_after_reloading_output_bytes(): void
{
    $release = $this->service->promote(...$this->validArguments());

    self::assertInstanceOf(PublishedReportDefinition::class, $release->published);
    self::assertSame('published', $release->published->payload()->publicationReadiness->value);
    self::assertSame(
        hash('sha256', $release->publishedBytes),
        $release->publishedBytesHash->value,
    );
}

public function test_candidate_checksum_and_validation_are_exact_validator_outputs(): void
{
    $generated = $this->fixtureBuilder->build(
        $this->loadedCandidateManifest(),
        $this->candidateRegistry(),
        $this->candidateBindings(),
    );

    self::assertSame(
        file_get_contents($this->candidateChecksumPath()),
        $generated->checksumBytes,
    );
    self::assertSame(
        file_get_contents($this->candidateValidationPath()),
        $generated->validationBytes,
    );
}
```

Negative cases: candidate YAML without exactly one LF, CRLF/BOM candidate bytes, uppercase checksum, checksum without/with extra LF, checksum digest mismatch, changed candidate bytes with unchanged checksum/validation, noncanonical validation JSON, unknown/missing validation field, alternate candidate path, failed/missing/extra/duplicate/reordered validation item, code or definition-hash mismatch, caller-invented passed item differing from a rerun of the concrete Task 4 validator, stale fixture evidence, changed unrelated block, formula/source/contract/renderer version drift, published output with second changed field, unknown lock field and wrong ledger enum.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php tests/Unit/Reporting/Publication/ReportCandidateValidationFixtureBuilderTest.php tests/Architecture/Reporting/ReportPublicationSchemaTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Publication\ReportManifestPromotionService" not found`.

- [ ] **GREEN:** implement deterministic nominal promotion, validator-derived candidate fixtures, exact schemas, lock/ledger and offline script.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php tests/Unit/Reporting/Publication/ReportCandidateValidationFixtureBuilderTest.php tests/Architecture/Reporting/ReportPublicationSchemaTest.php`

Expected GREEN: all semantic, promotion, byte-provenance and strict-schema cases pass; regenerated checksum/validation bytes equal the tracked fixtures exactly.

Run: `php scripts/reporting/promote-report-definition.php --current=tests/Fixtures/Reporting/Manifest/management.valid.yaml --candidate=tests/Fixtures/Reporting/Publication/candidate.valid.yaml --candidate-sha256=tests/Fixtures/Reporting/Publication/candidate.valid.sha256 --validation=tests/Fixtures/Reporting/Publication/candidate-validation.valid.json --conformance=tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json --release-sha=1111111111111111111111111111111111111111 --published-at=2026-07-26T00:00:00Z --output=tests/Fixtures/Reporting/Publication/published.expected.yaml --lock-output=tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json --check`

Expected: `promotion-check: PASS`; fixture files unchanged.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Publication app/BusinessModules/Core/Reporting/Infrastructure/Publication app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionSemanticDiff.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportPublicationLock.php app/BusinessModules/Core/Reporting/Domain/DTO/PublishedDefinitionRelease.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionSemanticDiff.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportPublicationLock.php app/BusinessModules/Core/Reporting/Domain/DTO/PublishedDefinitionRelease.php app/BusinessModules/Core/Reporting/Application/Publication app/BusinessModules/Core/Reporting/Infrastructure/Publication docs/reports/contracts/report-publication-lock.schema.json docs/reports/contracts/report-publication-ledger.schema.json docs/reports/contracts/report-candidate-validation.schema.json scripts/reporting/promote-report-definition.php tests/Fixtures/Reporting/Publication/candidate.valid.yaml tests/Fixtures/Reporting/Publication/candidate.valid.sha256 tests/Fixtures/Reporting/Publication/candidate-validation.valid.json tests/Fixtures/Reporting/Publication/published.expected.yaml tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json tests/Support/Reporting/Publication/ReportCandidateValidationFixtureBuilder.php tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php tests/Unit/Reporting/Publication/ReportCandidateValidationFixtureBuilderTest.php tests/Architecture/Reporting/ReportPublicationSchemaTest.php`

Run: `git commit -m "feat[reports]: добавлена nominal публикация definitions"`

### Task 6: Generated seven-group catalog, translations и stable resource lock

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogDefinitionView.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/GetReportCatalogHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Generation/ReportCatalogArtifactGenerator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Generation/ReportPermissionTranslationGenerator.php`
- Create: `scripts/reporting/generate-reporting-contracts.php`
- Create: `docs/reports/generated/reporting-catalog.v1.json`
- Create: `docs/reports/generated/reporting-catalog.v1.d.ts`
- Create: `docs/reports/generated/report-permissions.v1.json`
- Create: `docs/reports/contracts/reporting-generation.lock.json`
- Create: `tests/Fixtures/Reporting/Wire/report-catalog-resource.v1.json`
- Modify: `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php`
- Modify: `lang/ru/reports.php`
- Modify: `tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json`
- Test: `tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php`
- Test: `tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php`
- Test: `tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php`

**Interfaces consumed:**

- Plan 1a `GetReportCatalogAction`, `ReportCatalogView`, `ReportAccessService`, `ReportOperation::VIEW`, `ReportVisibility`.
- Task 2 published nominal registry, metadata registry and scheduling registry.
- Task 1 exact group enum/order and Plan 1a catalog resource/schema fixture.

**Interfaces produced:**

```php
final readonly class ReportCatalogDefinitionView
{
    public function __construct(
        public string $code,
        public string $titleKey,
        public ReportCatalogGroup $catalogGroup,
        public string $category,
        public string $grain,
        public int $wave,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $rendererVersion,
        public ReportPermissionPolicy $permissionPolicy,
        public array $filters,
        public array $columns,
        public array $sorts,
        public array $formats,
        public ReportSchedulingCapability $scheduling,
        public ReportVisibility $visibility,
    ) {
    }

    public static function from(
        PublishedReportDefinition $published,
        ReportCatalogMetadata $metadata,
        ReportSchedulingCapability $scheduling,
        ReportVisibility $visibility,
    ): self {
        $definition = $published->payload();

        return new self(
            $definition->code,
            $metadata->titleKey,
            $metadata->catalogGroup,
            $metadata->category,
            $metadata->grain,
            $metadata->wave,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
            $definition->permissionPolicy,
            $definition->filters,
            $definition->columns,
            $definition->sorts,
            $definition->formats,
            $scheduling,
            $visibility,
        );
    }
}
```

```php
final class GetReportCatalogHandler implements GetReportCatalogAction
{
    public function __construct(
        private ReportDefinitionRegistry $registry,
        private ReportCatalogMetadataRegistry $metadata,
        private ReportSchedulingCapabilityRegistry $scheduling,
        private ReportAccessService $access,
    ) {
    }

    public function handle(ReportExecutionContext $context): ReportCatalogView
    {
        $metadataByCode = [];
        foreach ($this->registry->publishedCodes() as $code) {
            $metadataByCode[$code] = $this->metadata->published($code);
        }
        $groupRanks = array_flip(array_map(
            static fn (ReportCatalogGroup $group): string => $group->value,
            ReportCatalogGroup::ordered(),
        ));
        $codes = array_keys($metadataByCode);
        usort(
            $codes,
            static fn (string $left, string $right): int => [
                $groupRanks[$metadataByCode[$left]->catalogGroup->value],
                $metadataByCode[$left]->manifestOrdinal,
            ] <=> [
                $groupRanks[$metadataByCode[$right]->catalogGroup->value],
                $metadataByCode[$right]->manifestOrdinal,
            ],
        );

        $definitions = [];
        foreach ($codes as $code) {
            $published = $this->registry->published($code);
            $payload = $published->payload();

            try {
                $visibility = $this->access->assertOperation(
                    $context,
                    $payload,
                    ReportOperation::VIEW,
                    null,
                );
            } catch (ReportContractException $exception) {
                if ($exception->code === ReportErrorCode::REPORT_SCOPE_FORBIDDEN) {
                    continue;
                }
                throw $exception;
            }

            $definitions[] = ReportCatalogDefinitionView::from(
                $published,
                $metadataByCode[$code],
                $this->scheduling->published($code),
                $visibility,
            );
        }

        return new ReportCatalogView(
            '1.0.0',
            $this->registry->manifestSha256(),
            $definitions,
        );
    }
}
```

Unauthorized definition is omitted without revealing its code; unexpected errors propagate. Ordering uses exact Task 1 group rank plus explicit Task 2 `manifestOrdinal`; it does not depend on registry iteration or lexicographic code order. Duplicate ordinal inside one group, ordinal outside `0..27` or unknown group fails during manifest loading. `manifestOrdinal` is omitted from the wire resource. Provider binds `GetReportCatalogAction::class` to `GetReportCatalogHandler::class`; Plan 1b intentionally leaves that port unbound.

Generator accepts `phase=platform|release`, raw manifest bytes/hash, published registry, metadata and scheduling registries. It produces:

- backend/frontend JSON with identical published codes, exact `catalog_group`, versions, permissions, capabilities and hashes;
- TypeScript unions for all seven groups and published codes without handwritten IDs;
- Russian group/title/permission artifact;
- Plan 1a `ReportCatalogResource` fixture;
- lock with phase, manifest/resource/permission/translation/group-order hashes and published count.

Generated catalog:

```json
{
  "contract_version": "1.0.0",
  "phase": "platform",
  "manifest_sha256": "64 lowercase hexadecimal characters",
  "catalog_group_order": [
    "portfolio",
    "projects",
    "finance",
    "procurement_warehouse",
    "team",
    "quality_safety",
    "partners_customers"
  ],
  "definitions": [
    {
      "code": "published_code",
      "catalog_group": "finance",
      "category": "finance",
      "definition_hash": "64 lowercase hexadecimal characters",
      "versions": {
        "contract": "1.0.0",
        "formula": "1.0.0",
        "source_schema": "1.0.0",
        "renderer": "1.0.0"
      },
      "permissions": {
        "view": ["known.slug"],
        "export": ["known.export"],
        "sensitive": [],
        "audit": []
      },
      "capabilities": {
        "filters": [],
        "columns": [],
        "sorts": [],
        "formats": ["csv"],
        "supports_subscriptions": false,
        "reproducible_scheduled_snapshot": false
      }
    }
  ]
}
```

Platform generation permits `0..28` published entries and writes phase `platform`; it is not release evidence. Release generation requires exactly 28 distinct published entries, exact 28 bindings, all seven groups non-empty and phase `release`. Permission generator validates every slug/title/group translation and never writes role assignments.

- [ ] **RED:** create nominal catalog, seven-group order, phase and translation tests.

```php
public function test_catalog_handler_unwraps_published_definition_and_orders_seven_groups(): void
{
    $view = $this->handler->handle($this->context);

    self::assertSame(
        ReportCatalogGroup::ordered(),
        $this->distinctGroups($view->definitions),
    );
    self::assertSame(0, $this->candidateRegistrySpy->calls());
}

public function test_catalog_uses_manifest_ordinal_when_registry_codes_are_shuffled(): void
{
    $view = $this->handlerWithRegistryOrder([
        'accepted_production_progress',
        'holding_performance',
        'project_portfolio_health',
    ])->handle($this->context);

    self::assertSame(
        [
            'project_portfolio_health',
            'holding_performance',
            'accepted_production_progress',
        ],
        array_column($view->definitions, 'code'),
    );
}

public function test_release_generation_rejects_twenty_seven_published_codes(): void
{
    $this->expectExceptionMessage('release_catalog_count_invalid');
    $this->generator->generate('release', $this->registryWithCount(27), $this->inputs);
}
```

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Generation\ReportCatalogArtifactGenerator" not found`.

- [ ] **GREEN:** implement action, provider binding, seven-group generators/artifacts and exact Russian group/title keys.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php`

Expected GREEN: `OK (20 tests, 156 assertions)`.

Run: `php scripts/reporting/generate-reporting-contracts.php --phase=platform --check`

Expected: `reporting-contracts: clean`; no file changes.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogDefinitionView.php app/BusinessModules/Core/Reporting/Application/Catalog/GetReportCatalogHandler.php app/BusinessModules/Core/Reporting/Application/Generation app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogDefinitionView.php app/BusinessModules/Core/Reporting/Application/Catalog/GetReportCatalogHandler.php app/BusinessModules/Core/Reporting/Application/Generation app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php scripts/reporting/generate-reporting-contracts.php docs/reports/generated docs/reports/contracts/reporting-generation.lock.json lang/ru/reports.php tests/Fixtures/Reporting/Wire/report-catalog-resource.v1.json tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php`

Run: `git commit -m "feat[reports]: добавлена семигрупповая генерация catalog"`

### Task 7: Server workspace recent, favourites и display preferences

**Files:**

- Create: `database/migrations/2026_07_26_000005_create_report_workspace_preferences_table.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspaceDisplayPreferences.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspacePreferences.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportWorkspacePreferencesStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportWorkspacePreferencesRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportWorkspacePreferencesStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Workspace/ReportWorkspacePreferencesService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Workspace/GetReportWorkspaceAction.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Workspace/RecordRecentReportAction.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Workspace/SetFavouriteReportsAction.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Workspace/UpdateReportWorkspacePreferencesAction.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/RecordRecentReportRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/SetReportWorkspaceFavouritesRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportWorkspacePreferencesRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportWorkspacePreferencesResource.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportWorkspacePreferencesController.php`
- Modify: `app/BusinessModules/Core/Reporting/routes.php`
- Test: `tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php`
- Test: `tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php`
- Test: `tests/Integration/Reporting/ReportWorkspacePreferencesPostgresTest.php`

**Interfaces consumed:**

- Plan 1a `ReportExecutionContext`, `ReportDefinitionRegistry`, `ReportAccessService`, `ReportOperation::VIEW|MANAGE`, `AdminResponse`, `ReportFormRequest`.
- Task 1 group enum/order.

**Interfaces produced:**

```php
final readonly class ReportWorkspaceDisplayPreferences
{
    public function __construct(
        public array $catalogGroupOrder,
        public array $collapsedCatalogGroups,
        public string $landingSection,
    ) {
    }
}

final readonly class ReportWorkspacePreferences
{
    public function __construct(
        public array $recentReportCodes,
        public array $favouriteReportCodes,
        public ReportWorkspaceDisplayPreferences $display,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
```

`catalogGroupOrder` is a permutation of all seven enum values; collapsed groups are a unique subset; `landingSection` is `catalog|recent|favourites|saved_views|exports`. Recent/favourites are unique safe published codes; recent max 10. Organization/owner never appear in resource DTO.

```php
interface ReportWorkspacePreferencesStore
{
    public function get(
        int $organizationId,
        int $ownerId,
    ): ?ReportWorkspacePreferences;

    public function updateLocked(
        int $organizationId,
        int $ownerId,
        Closure $change,
    ): ReportWorkspacePreferences;
}

final class ReportWorkspacePreferencesService
{
    public function get(ReportExecutionContext $context): ReportWorkspacePreferences;

    public function recordRecent(
        ReportExecutionContext $context,
        string $reportCode,
    ): ReportWorkspacePreferences;

    public function setFavourites(
        ReportExecutionContext $context,
        array $codes,
    ): ReportWorkspacePreferences;

    public function updateDisplay(
        ReportExecutionContext $context,
        ReportWorkspaceDisplayPreferences $display,
    ): ReportWorkspacePreferences;
}
```

Store predicates every read/update by `context->scope->organizationId` and `context->actor->id`, locks one row in a transaction and creates default preferences on first write. Default group order is Task 1 order, collapsed list empty, landing section `catalog`.

Service resolves each code through `ReportDefinitionRegistry::published()`, explicitly calls `payload()` and authorizes current `VIEW`. Mutations additionally authorize `MANAGE`. `recordRecent` removes prior occurrence, prepends and slices to 10. Favourites deduplicate while preserving request order; nonpublished/unauthorized codes fail without storage mutation.

Migration:

```php
Schema::create('report_workspace_preferences', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('organization_id');
    $table->unsignedBigInteger('owner_id');
    $table->jsonb('recent_report_codes')->default('[]');
    $table->jsonb('favourite_report_codes')->default('[]');
    $table->jsonb('display_preferences')->default('{}');
    $table->timestampsTz();
    $table->unique(
        ['organization_id', 'owner_id'],
        'report_workspace_preferences_owner_unique',
    );
    $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
    $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
});
```

Routes:

```text
GET   /api/v1/admin/reports/workspace
POST  /api/v1/admin/reports/workspace/recent/{reportCode}
PUT   /api/v1/admin/reports/workspace/favourites
PATCH /api/v1/admin/reports/workspace/preferences
```

Controller methods accept one request, build context, call one action, wrap `ReportWorkspacePreferencesResource` in `AdminResponse`. Resource fields are exactly:

```json
{
  "recent_report_codes": [],
  "favourite_report_codes": [],
  "display_preferences": {
    "catalog_group_order": [],
    "collapsed_catalog_groups": [],
    "landing_section": "catalog"
  },
  "updated_at": "RFC 3339"
}
```

All three mutation requests inherit Plan 1a forbidden fields and additionally prohibit `owner_id`; route code is safe-pattern validated. No client timestamp/order default is trusted.

- [ ] **RED:** create DTO/service/route tests before migration/store/API.

```php
public function test_eleventh_recent_evicts_oldest_without_crossing_tenant(): void
{
    foreach ($this->publishedCodes(11) as $code) {
        $result = $this->service->recordRecent($this->orgOne, $code);
    }

    self::assertCount(10, $result->recentReportCodes);
    self::assertSame([], $this->service->get($this->orgTwo)->recentReportCodes);
}
```

Cases include duplicate favourites/order, invalid seven-group permutation, nonpublished code, revoked definition permission, missing `reports.manage`, foreign owner input and two concurrent first writes.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Workspace\ReportWorkspacePreferencesService" not found`.

- [ ] **GREEN:** implement exact DTO/store/service/actions/requests/resource/controller/routes and migration contract.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php`

Expected GREEN: `OK (20 tests, 86 assertions)`.

CI-only Run: `vendor/bin/phpunit tests/Integration/Reporting/ReportWorkspacePreferencesPostgresTest.php`

Expected CI GREEN: `OK (8 tests, 32 assertions)`.

Run: `php -l database/migrations/2026_07_26_000005_create_report_workspace_preferences_table.php`

Expected: no syntax errors; migration is not executed locally.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Workspace app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspaceDisplayPreferences.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspacePreferences.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportWorkspacePreferencesStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportWorkspacePreferencesRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportWorkspacePreferencesStore.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportWorkspacePreferencesController.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/RecordRecentReportRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/SetReportWorkspaceFavouritesRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportWorkspacePreferencesRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportWorkspacePreferencesResource.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- database/migrations/2026_07_26_000005_create_report_workspace_preferences_table.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspaceDisplayPreferences.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportWorkspacePreferences.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportWorkspacePreferencesStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportWorkspacePreferencesRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportWorkspacePreferencesStore.php app/BusinessModules/Core/Reporting/Application/Workspace app/BusinessModules/Core/Reporting/Http/Admin/Requests/RecordRecentReportRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/SetReportWorkspaceFavouritesRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportWorkspacePreferencesRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportWorkspacePreferencesResource.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportWorkspacePreferencesController.php app/BusinessModules/Core/Reporting/routes.php tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php tests/Integration/Reporting/ReportWorkspacePreferencesPostgresTest.php`

Run: `git commit -m "feat[reports]: добавлен серверный workspace отчётов"`

### Task 8: Saved views persistence, migration lifecycle и cursor API

**Files:**

- Create: `database/migrations/2026_07_26_000006_create_report_saved_views_table.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSavedViewStatus.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewWindow.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewPage.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/CreateReportSavedViewData.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/UpdateReportSavedViewData.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportSavedViewRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSavedViewStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSavedViewCursorCodec.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/ReportSavedViewService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/ListReportSavedViewsHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/CreateReportSavedViewHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/GetReportSavedViewHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/UpdateReportSavedViewHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/DeleteReportSavedViewHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/SavedViews/SetDefaultReportSavedViewHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSavedViewsRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSavedViewRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSavedViewRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSavedViewRouteRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSavedViewPageResource.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSavedViewController.php`
- Modify: `app/BusinessModules/Core/Reporting/routes.php`
- Test: `tests/Unit/Reporting/SavedViews/ReportSavedViewServiceTest.php`
- Test: `tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php`
- Test: `tests/Architecture/Reporting/ReportSavedViewRouteContractTest.php`
- Test: `tests/Integration/Reporting/ReportSavedViewPostgresTest.php`

**Interfaces consumed:**

- Plan 1a `ReportSavedView`, `ReportSavedViewResource`, normalizers, `ReportExecutionContext`, published registry/access, `AdminResponse`, `ReportFormRequest`.

**Interfaces produced:**

```php
enum ReportSavedViewStatus: string
{
    case ACTIVE = 'active';
    case NEEDS_MIGRATION = 'needs_migration';
}

final readonly class ReportSavedViewWindow
{
    public function __construct(
        public ?string $cursor,
        public int $limit,
        public ?string $reportCode,
    ) {
    }
}

final readonly class ReportSavedViewPage
{
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $limit,
        public bool $hasMore,
    ) {
    }
}
```

Limit is `1..100`; items are `list<ReportSavedView>` with unique ULIDs; no exact total. Cursor is opaque/signed and contains organization, owner, last `created_at`, last ULID, optional report code and expiry; wrong tenant/filter/signature maps to `REPORT_CURSOR_INVALID`.

```php
final readonly class CreateReportSavedViewData
{
    public function __construct(
        public string $reportCode,
        public string $name,
        public string $visibility,
        public ReportFilterSet $filters,
        public array $comparison,
        public ReportWindowSort $sort,
        public array $columns,
        public bool $isDefault,
    ) {
    }
}

final readonly class UpdateReportSavedViewData
{
    public function __construct(public array $changes)
    {
    }
}
```

Create validates safe name up to 120, `private|organization`, unique allowlisted columns. Update changes contain only `name`, `visibility`, `filters`, `comparison`, `sort`, `columns`; at least one key; values are already normalized typed values.

```php
interface ReportSavedViewStore
{
    public function list(
        int $organizationId,
        int $ownerId,
        ReportSavedViewWindow $window,
    ): ReportSavedViewPage;

    public function getVisible(
        int $organizationId,
        int $ownerId,
        string $id,
    ): ReportSavedView;

    public function create(
        int $organizationId,
        int $ownerId,
        CreateReportSavedViewData $data,
        string $contractVersion,
    ): ReportSavedView;

    public function updateLocked(
        int $organizationId,
        int $ownerId,
        string $id,
        UpdateReportSavedViewData $data,
    ): ReportSavedView;

    public function setDefaultLocked(
        int $organizationId,
        int $ownerId,
        string $id,
    ): ReportSavedView;

    public function softDeleteLocked(
        int $organizationId,
        int $ownerId,
        string $id,
    ): void;
}
```

Service resolves nominal published wrapper, calls `payload()`, normalizes filters/sort/columns using Plan 1a and checks current `VIEW`; mutations require `MANAGE`. Contract mismatch marks `NEEDS_MIGRATION`; such a view can be read/updated/deleted but cannot create run/subscription until migrated. Organization-shared rows are visible only to authorized current organization; foreign/missing/deleted return identical `REPORT_NOT_FOUND`.

Migration body:

```php
Schema::create('report_saved_views', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->unsignedBigInteger('organization_id');
    $table->unsignedBigInteger('owner_id');
    $table->string('report_code', 64);
    $table->string('contract_version', 32);
    $table->string('name', 120);
    $table->string('visibility', 24)->default('private');
    $table->jsonb('filters_json');
    $table->jsonb('comparison_json')->default('{}');
    $table->jsonb('sort_json');
    $table->jsonb('columns_json');
    $table->string('status', 32)->default('active');
    $table->boolean('is_default')->default(false);
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(
        ['organization_id', 'owner_id', 'report_code', 'created_at', 'id'],
        'report_saved_views_owner_cursor_index',
    );
});
DB::statement(
    "CREATE UNIQUE INDEX report_saved_views_default_unique
     ON report_saved_views (organization_id, owner_id, report_code)
     WHERE is_default = true AND deleted_at IS NULL"
);
DB::statement(
    "ALTER TABLE report_saved_views
     ADD CONSTRAINT report_saved_views_status_check
     CHECK (status IN ('active','needs_migration'))"
);
```

Routes exactly match final API: GET/POST `/saved-views`, GET/PATCH/DELETE `/saved-views/{savedViewId}`, POST `/saved-views/{savedViewId}/set-default`. Route request validates ULID. List resource returns `items` and meta `limit,next_cursor,has_more`; single routes reuse Plan 1a `ReportSavedViewResource`.

- [ ] **RED:** create cursor/default/migration/sharing/tenant tests.

```php
public function test_list_uses_saved_view_page_not_report_rows_page(): void
{
    $method = new ReflectionMethod(ListReportSavedViewsHandler::class, 'handle');
    self::assertSame(
        ReportSavedViewPage::class,
        $method->getReturnType()?->getName(),
    );
}
```

Run: `vendor/bin/phpunit tests/Unit/Reporting/SavedViews/ReportSavedViewServiceTest.php tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php tests/Architecture/Reporting/ReportSavedViewRouteContractTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewService" not found`.

- [ ] **GREEN:** implement full DTO/store/service/handlers/cursor/requests/resources/controller/routes and migration.

Run: `vendor/bin/phpunit tests/Unit/Reporting/SavedViews/ReportSavedViewServiceTest.php tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php tests/Architecture/Reporting/ReportSavedViewRouteContractTest.php`

Expected GREEN: `OK (24 tests, 109 assertions)`.

CI-only Run: `vendor/bin/phpunit tests/Integration/Reporting/ReportSavedViewPostgresTest.php`

Expected CI GREEN: `OK (10 tests, 41 assertions)`.

Run: `php -l database/migrations/2026_07_26_000006_create_report_saved_views_table.php`

Expected: no syntax errors; migration is not executed locally.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/SavedViews app/BusinessModules/Core/Reporting/Domain/Enums/ReportSavedViewStatus.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewWindow.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewPage.php app/BusinessModules/Core/Reporting/Domain/DTO/CreateReportSavedViewData.php app/BusinessModules/Core/Reporting/Domain/DTO/UpdateReportSavedViewData.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportSavedViewRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSavedViewStore.php app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSavedViewCursorCodec.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSavedViewsRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSavedViewRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSavedViewRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSavedViewRouteRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSavedViewPageResource.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSavedViewController.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- database/migrations/2026_07_26_000006_create_report_saved_views_table.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSavedViewStatus.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewWindow.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewPage.php app/BusinessModules/Core/Reporting/Domain/DTO/CreateReportSavedViewData.php app/BusinessModules/Core/Reporting/Domain/DTO/UpdateReportSavedViewData.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportSavedViewRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSavedViewStore.php app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSavedViewCursorCodec.php app/BusinessModules/Core/Reporting/Application/SavedViews app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSavedViewsRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSavedViewRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSavedViewRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSavedViewRouteRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSavedViewPageResource.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSavedViewController.php app/BusinessModules/Core/Reporting/routes.php tests/Unit/Reporting/SavedViews/ReportSavedViewServiceTest.php tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php tests/Architecture/Reporting/ReportSavedViewRouteContractTest.php tests/Integration/Reporting/ReportSavedViewPostgresTest.php`

Run: `git commit -m "feat[reports]: добавлены сохранённые виды отчётов"`

### Task 9: Полный lifecycle подписок, scheduler, polling и exactly-once notification

**Files:**

- Create: `database/migrations/2026_07_26_000007_create_report_subscriptions_tables.php`
- Create: `config/reporting_subscriptions.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionStatus.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionDeliveryStatus.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionFrequency.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionTrigger.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionExecutionInput.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscription.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionDelivery.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionNotificationReceipt.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/CreateReportSubscriptionData.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/UpdateReportSubscriptionData.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionStore.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/InAppReportSubscriptionNotifier.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionEventRecorder.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportSubscriptionRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportSubscriptionDeliveryRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSubscriptionStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSubscriptionDeliveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportSubscriptionDeliveryDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Notifications/PersistedInAppReportSubscriptionNotifier.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/ImmutableAuditReportSubscriptionEventRecorder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionExecutionContextFactory.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionScheduleCalculator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionPeriodResolver.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionCoordinator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionDeliveryProcessor.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/ScheduleDueReportSubscriptionsJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/DeliverReportSubscriptionJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/ExpireReportSubscriptionExecutionsJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/PruneReportSubscriptionDeliveriesJob.php`
- Modify: `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php`
- Test: `tests/Unit/Reporting/Subscriptions/ReportSubscriptionScheduleCalculatorTest.php`
- Test: `tests/Unit/Reporting/Subscriptions/ReportSubscriptionCoordinatorTest.php`
- Test: `tests/Unit/Reporting/Subscriptions/ReportSubscriptionDeliveryProcessorTest.php`
- Test: `tests/Unit/Reporting/Subscriptions/DeliverReportSubscriptionJobTest.php`
- Test: `tests/Architecture/Reporting/ReportSubscriptionActionPortContractTest.php`
- Test: `tests/Integration/Reporting/ReportSubscriptionPostgresTest.php`

**Closed enums and immutable input:**

```php
enum ReportSubscriptionStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case DISABLED = 'disabled';
    case DELETED = 'deleted';
}

enum ReportSubscriptionDeliveryStatus: string
{
    case SCHEDULED = 'scheduled';
    case BUILDING_RUN = 'building_run';
    case BUILDING_EXPORT = 'building_export';
    case READY = 'ready';
    case NOTIFIED = 'notified';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
}

enum ReportSubscriptionFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
}

enum ReportSubscriptionTrigger: string
{
    case CALENDAR = 'calendar';
    case MANUAL = 'manual';
}
```

`ReportSubscriptionExecutionInput` is the only adapter between persisted versioned subscription context and Plan 1a action inputs. It stores typed, normalized values rather than request arrays:

```php
final readonly class ReportSubscriptionExecutionInput
{
    public function __construct(
        public string $reportCode,
        public ReportFilterSet $filters,
        public array $comparison,
        public string $locale,
        public string $savedViewId,
        public string $format,
        public array $columns,
        public ReportWindowSort $sort,
        public DateTimeZone $timezone,
        public array $periodPolicy,
        public string $contractVersion,
        public Sha256Hash $definitionHash,
    ) {
    }

    public function runData(DateTimeImmutable $asOf): CreateReportRunData
    {
        return new CreateReportRunData(
            $this->reportCode,
            $this->filters,
            $this->comparison,
            $asOf,
            $this->locale,
            $this->savedViewId,
        );
    }

    public function exportData(): CreateReportExportData
    {
        return new CreateReportExportData(
            $this->format,
            $this->columns,
            $this->sort,
            $this->locale,
            $this->timezone,
        );
    }

    public function canonicalBytes(): string;

    public function digest(): Sha256Hash;

    public static function fromCanonicalBytes(string $bytes): self;
}
```

The DTO rejects a non-ULID saved view, a format outside `csv|xlsx|pdf`, duplicate columns, an invalid locale, an invalid period policy, or a definition hash/version mismatch while rehydrating. `canonicalBytes()` uses `CanonicalJson`; `digest()` hashes those exact bytes; `fromCanonicalBytes()` rejects invalid UTF-8/JSON, unknown or missing keys and non-canonical bytes by decode→re-encode byte equality. `CreateReportSubscriptionData` has exactly `savedViewId`, `frequency`, nullable `weekday`, nullable `dayOfMonth`, `localTime`, `timezone`, `periodPolicy`, and `format`. `UpdateReportSubscriptionData` carries a non-empty normalized `changes` map restricted to those same scheduling fields; it never accepts actor, organization, status, channel, recipients, report code, query context, definition version, failure count, or next run.

```php
final readonly class ReportSubscriptionDelivery
{
    public function __construct(
        public string $id,
        public int $organizationId,
        public int $ownerId,
        public string $subscriptionId,
        public ReportSubscriptionTrigger $trigger,
        public ?Sha256Hash $triggerKeyHash,
        public ?Sha256Hash $manualRequestHash,
        public DateTimeImmutable $scheduledFor,
        public string $executionInputBytes,
        public Sha256Hash $executionInputHash,
        public int $subscriptionVersion,
        public ReportSubscriptionDeliveryStatus $status,
        public int $attempt,
        public ?string $runId,
        public ?string $exportId,
        public ?string $notificationReceiptId,
        public ?string $safeErrorCode,
        public DateTimeImmutable $executionExpiresAt,
        public DateTimeImmutable $retentionDeleteAfter,
    ) {
        $manualHashesPresent = $this->triggerKeyHash !== null
            && $this->manualRequestHash !== null;
        if (
            ($this->trigger === ReportSubscriptionTrigger::MANUAL)
            !== $manualHashesPresent
        ) {
            throw new InvalidArgumentException('delivery_manual_hashes_invalid');
        }
        if (!hash_equals(
            $this->executionInputHash->value,
            hash('sha256', $this->executionInputBytes),
        )) {
            throw new InvalidArgumentException('delivery_execution_input_hash_mismatch');
        }
        if ($this->subscriptionVersion < 1) {
            throw new InvalidArgumentException('delivery_subscription_version_invalid');
        }
        if ($this->retentionDeleteAfter <= $this->executionExpiresAt) {
            throw new InvalidArgumentException('delivery_retention_window_invalid');
        }
    }

    public function executionInput(): ReportSubscriptionExecutionInput
    {
        return ReportSubscriptionExecutionInput::fromCanonicalBytes(
            $this->executionInputBytes,
        );
    }

    public function runIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:run:v1");
    }

    public function exportIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:export:v1");
    }

    public function notificationIdempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey("reports-subscription:{$this->id}:notify:v1");
    }
}
```

Every method above returns the exact Plan 1a `IdempotencyKey` value object. The `runData()`/`exportData()` methods belong to `ReportSubscriptionExecutionInput`, return the exact Plan 1a DTO classes and are covered by reflection tests for namespace, return type and constructor arity. Delivery constructor pins the exact execution-input bytes/hash and subscription transition version captured inside the scheduling transaction; updates affect only future deliveries.

**Persistence contract:**

`report_subscriptions` contains ULID, organization/owner/saved-view IDs, report code, schedule fields, `in_app` channel, status/disabled reason/failure count/next run, canonical versioned execution input bytes plus its SHA-256, definition hash/contract version, transition version, timestamps and soft delete. `report_subscription_deliveries` copies immutable execution-input bytes/hash and subscription version at scheduling; it also contains owner, trigger/manual request hashes, scheduled time, run/export references, attempt, lifecycle status, notification key hash/receipt/notified time, safe error code, retry time, 24-hour execution deadline, 90-day cleanup deadline and timestamps.

```php
Schema::create('report_subscriptions', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->unsignedBigInteger('organization_id');
    $table->unsignedBigInteger('owner_id');
    $table->ulid('saved_view_id');
    $table->string('report_code', 64);
    $table->string('frequency', 16);
    $table->unsignedTinyInteger('weekday')->nullable();
    $table->unsignedTinyInteger('day_of_month')->nullable();
    $table->time('local_time');
    $table->string('timezone', 64);
    $table->jsonb('period_policy_json');
    $table->string('format', 8);
    $table->string('channel', 16)->default('in_app');
    $table->string('status', 16)->default('active');
    $table->string('disabled_reason', 64)->nullable();
    $table->unsignedSmallInteger('consecutive_failures')->default(0);
    $table->timestampTz('next_run_at')->nullable();
    $table->text('execution_input_bytes');
    $table->char('execution_input_sha256', 64);
    $table->char('definition_sha256', 64);
    $table->string('contract_version', 32);
    $table->unsignedBigInteger('transition_version')->default(1);
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(
        ['status', 'next_run_at', 'id'],
        'report_subscriptions_due_index',
    );
    $table->index(
        ['organization_id', 'owner_id', 'next_run_at', 'id'],
        'report_subscriptions_owner_cursor_index',
    );
});

Schema::create('report_subscription_deliveries', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->unsignedBigInteger('organization_id');
    $table->unsignedBigInteger('owner_id');
    $table->ulid('subscription_id');
    $table->string('trigger', 16);
    $table->char('trigger_key_hash', 64)->nullable();
    $table->char('manual_request_sha256', 64)->nullable();
    $table->timestampTz('scheduled_for');
    $table->text('execution_input_bytes');
    $table->char('execution_input_sha256', 64);
    $table->unsignedBigInteger('subscription_version');
    $table->ulid('run_id')->nullable();
    $table->ulid('export_id')->nullable();
    $table->unsignedSmallInteger('attempt')->default(0);
    $table->string('status', 24)->default('scheduled');
    $table->char('notification_key_hash', 64)->nullable();
    $table->string('notification_receipt_id', 128)->nullable();
    $table->timestampTz('notified_at')->nullable();
    $table->string('safe_error_code', 64)->nullable();
    $table->timestampTz('retry_at')->nullable();
    $table->timestampTz('execution_expires_at');
    $table->timestampTz('retention_delete_after');
    $table->timestampsTz();
    $table->unique(
        ['subscription_id', 'scheduled_for'],
        'report_subscription_delivery_schedule_unique',
    );
    $table->unique(
        ['notification_key_hash'],
        'report_subscription_delivery_notification_unique',
    );
    $table->index(
        ['status', 'retry_at', 'id'],
        'report_subscription_delivery_dispatch_index',
    );
    $table->index(
        ['status', 'execution_expires_at', 'id'],
        'report_subscription_execution_expiry_index',
    );
    $table->index(
        ['retention_delete_after', 'id'],
        'report_subscription_retention_index',
    );
});

DB::statement(
    "CREATE UNIQUE INDEX report_subscription_manual_idempotency_unique
     ON report_subscription_deliveries
        (subscription_id, trigger_key_hash)
     WHERE trigger_key_hash IS NOT NULL"
);

DB::statement(
    "ALTER TABLE report_subscriptions
     ADD CONSTRAINT report_subscriptions_status_check
     CHECK (status IN ('active','paused','disabled','deleted'))"
);
DB::statement(
    "ALTER TABLE report_subscriptions
     ADD CONSTRAINT report_subscriptions_channel_check
     CHECK (channel = 'in_app')"
);
DB::statement(
    "ALTER TABLE report_subscription_deliveries
     ADD CONSTRAINT report_subscription_deliveries_status_check
     CHECK (status IN ('scheduled','building_run','building_export','ready','notified','failed','expired'))"
);
DB::statement(
    "ALTER TABLE report_subscription_deliveries
     ADD CONSTRAINT report_subscription_deliveries_manual_hash_check
     CHECK (
       (
         trigger = 'manual'
         AND trigger_key_hash IS NOT NULL
         AND manual_request_sha256 IS NOT NULL
       )
       OR (
         trigger = 'calendar'
         AND trigger_key_hash IS NULL
         AND manual_request_sha256 IS NULL
       )
     )"
);
```

Foreign keys target the existing organization/user/saved-view/run/export tables using the actual table names confirmed during implementation; subscription and delivery deletion never cascades into Plan 1b runs, exports or S3 artifacts. The migration is syntax-checked only and is executed only in isolated CI.

**Exact ports:**

```php
interface ReportSubscriptionDeliveryDispatcher
{
    public function dispatch(string $deliveryId, int $delaySeconds): void;
}

interface InAppReportSubscriptionNotifier
{
    public function notify(
        ReportExecutionContext $context,
        ReportSubscription $subscription,
        ReportSubscriptionDelivery $delivery,
        ReportExport $export,
        IdempotencyKey $key,
    ): ReportSubscriptionNotificationReceipt;
}

interface ReportSubscriptionEventRecorder
{
    public function record(
        string $eventCode,
        ReportExecutionContext $context,
        string $subjectType,
        string $subjectId,
        int $transitionVersion,
        array $safeEvidence,
    ): void;
}
```

`ReportSubscriptionStore` exposes `getForActor()`, `lock()`, `create()`, `updateLocked()`, `transitionLocked()`, `selectDueLocked(DateTimeImmutable $now, int $limit): array`, `advanceNextRunLocked()` and `resetFailuresLocked()`. `ReportSubscriptionDeliveryStore` exposes:

```php
public function lockManualByScope(
    string $subscriptionId,
    Sha256Hash $triggerKeyHash,
): ?ReportSubscriptionDelivery;

public function createCalendarScheduledLocked(
    ReportSubscription $subscription,
    DateTimeImmutable $scheduledFor,
    string $executionInputBytes,
    Sha256Hash $executionInputHash,
    int $subscriptionVersion,
): ReportSubscriptionDelivery;

public function insertManualScheduledOnConflictLocked(
    ReportSubscription $subscription,
    DateTimeImmutable $scheduledFor,
    Sha256Hash $triggerKeyHash,
    Sha256Hash $manualRequestHash,
    string $executionInputBytes,
    Sha256Hash $executionInputHash,
    int $subscriptionVersion,
): ?string;

public function expireExecutionsDueLocked(
    DateTimeImmutable $now,
    int $limit,
): array;

public function pruneTerminalDueLocked(
    DateTimeImmutable $now,
    int $limit,
): int;
```

It also exposes `lockWithSubscription()`, `beginAttemptLocked()`, `attachRunLocked()`, `attachExportLocked()`, `markReadyLocked()`, `markNotifiedLocked()`, `rescheduleRetryLocked()` and `markFailedLocked()`. Every mutation takes expected current status and transition version; an affected-row count other than one fails closed. Store implementations always include organization scope. `lockManualByScope()` selects by the exact unique tuple `(subscription_id, trigger_key_hash)` with `FOR UPDATE`; organization/owner predicates are repeated as tenant guards from the already locked and authorized subscription, not as a different idempotency key.

**Coordinator and scheduling invariants:**

- Create/update resolves the saved view through `ReportSavedViewStore`, resolves `ReportDefinitionRegistry::published($reportCode)`, calls `payload()`, requires active view, `supportsSubscriptions=true`, reproducible scheduled snapshot, current `ReportOperation::VIEW` and `ReportOperation::MANAGE`, and stores the exact normalized versioned execution input.
- The only channel is `in_app`; no recipient input exists.
- Transitions are exactly `active↔paused`, `active|paused→disabled`, `disabled→active` after full current reauthorization, and any non-deleted state to `deleted`.
- Permission revocation, deleted actor, scope loss, definition removal, definition hash drift or saved-view `needs_migration` disables the subscription with `permission_revoked` or `definition_changed`; no future calendar delivery is created.
- Daily/weekly/monthly schedule validation is exhaustive: daily forbids weekday/day-of-month, weekly requires ISO weekday `1..7`, monthly requires day `1..31`. The calculator uses the subscription IANA timezone and documented policy: nonexistent local DST time advances to the first valid instant; ambiguous local time uses the earlier UTC instant; monthly overflow uses the last calendar day.
- `ScheduleDueReportSubscriptionsJob` opens a transaction, selects active due rows with `FOR UPDATE SKIP LOCKED`, rereads and hashes subscription execution-input bytes, copies those bytes/hash plus `transition_version` into one calendar delivery for the persisted `next_run_at`, advances `next_run_at`, commits, and only then dispatches each delivery. Unique `(subscription_id,scheduled_for)` makes scheduler retries harmless.
- Manual idempotency scope is exactly `(subscription_id, IdempotencyKey::hash)`. The coordinator first locks and authorizes that subscription for the current organization/owner, captures immutable execution-input bytes/hash/version, and computes `manual_request_sha256` as SHA-256 of canonical `{operation:"run-now",subscription_id,execution_input_sha256,subscription_version}`. At PostgreSQL `READ COMMITTED`, `insertManualScheduledOnConflictLocked()` executes exactly this non-throwing statement with bound values:

```sql
INSERT INTO report_subscription_deliveries (
    id,
    organization_id,
    owner_id,
    subscription_id,
    trigger,
    trigger_key_hash,
    manual_request_sha256,
    scheduled_for,
    execution_input_bytes,
    execution_input_sha256,
    subscription_version,
    status,
    attempt,
    execution_expires_at,
    retention_delete_after,
    created_at,
    updated_at
) VALUES (
    :id,
    :organization_id,
    :owner_id,
    :subscription_id,
    'manual',
    :trigger_key_hash,
    :manual_request_sha256,
    :scheduled_for,
    :execution_input_bytes,
    :execution_input_sha256,
    :subscription_version,
    'scheduled',
    0,
    :execution_expires_at,
    :retention_delete_after,
    :created_at,
    :updated_at
)
ON CONFLICT (subscription_id, trigger_key_hash)
WHERE trigger_key_hash IS NOT NULL
DO NOTHING
RETURNING id
```

If `RETURNING` yields an ID, that delivery is dispatched only after commit. If it yields no row, no SQL error occurred and the same transaction remains healthy: the coordinator calls `lockManualByScope(subscriptionId, triggerKeyHash)`, requires the row to exist and rechecks its organization/owner against the locked subscription. It compares `trigger_key_hash`, `execution_input_sha256` and `manual_request_sha256` with `hash_equals()` and compares `subscription_version` exactly. Equal pinned input/trigger hashes and version replay the same delivery ID; any difference returns `REPORT_IDEMPOTENCY_CONFLICT`. The implementation never catches SQLSTATE `23505`, never issues a query in a failed transaction, and treats any unexpected database error as a normal transaction rollback. Manual run copies immutable input bytes/hash/version and never changes `next_run_at`.

**Complete delivery state machine:**

```text
scheduled
  -> building_run       CreateReportRunAction(context, CreateReportRunData, IdempotencyKey)
building_run
  -> building_run       GetReportRunAction; queued/materializing; delayed redispatch
  -> building_export    ready run; CreateReportExportAction(context, run id, CreateReportExportData, IdempotencyKey)
building_export
  -> building_export    GetReportExportAction; queued/running/uploading; delayed redispatch
  -> ready              ready export
ready
  -> notified           notifier(context, subscription, delivery, export, IdempotencyKey)
scheduled|building_run|building_export|ready
  -> scheduled          retryable failure and attempt < 5
  -> failed             non-retryable failure or exhausted attempt budget
any non-terminal
  -> expired            execution TTL reached
```

`ReportSubscriptionDeliveryProcessor::process(string $deliveryId): void` is the only state-machine owner. At every invocation it locks and reloads delivery/subscription, verifies execution-input bytes against the pinned hash, decodes only `$delivery->executionInput()`, rebuilds context without `request()`, reloads current actor and scope, resolves the nominal published wrapper, calls `payload()`, verifies the current definition hash/contract against the pinned input, and asserts current `VIEW`, `RUN`, `EXPORT` and `MANAGE` as required for that phase. A later subscription update is intentionally ignored for run/export input of this delivery. The Plan 1a action implementations also reauthorize; this processor-level check prevents stale permission state from triggering them.

```php
final class ReportSubscriptionDeliveryProcessor
{
    public function process(string $deliveryId): void
    {
        $delivery = $this->deliveries->lockWithSubscription($deliveryId);

        if ($delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED) {
            $this->startRun($delivery);
            return;
        }

        if ($delivery->status === ReportSubscriptionDeliveryStatus::BUILDING_RUN) {
            $this->pollRun($delivery);
            return;
        }

        if ($delivery->status === ReportSubscriptionDeliveryStatus::BUILDING_EXPORT) {
            $this->pollExport($delivery);
            return;
        }

        if ($delivery->status === ReportSubscriptionDeliveryStatus::READY) {
            $this->notify($delivery);
        }
    }
}
```

`startRun()` increments `attempt` once and calls:

```php
$run = $this->createRuns->handle(
    $context,
    $delivery->executionInput()->runData(
        $this->periods->asOf(
            $delivery->executionInput(),
            $delivery->scheduledFor,
        ),
    ),
    $delivery->runIdempotencyKey(),
);
```

`pollRun()` calls `GetReportRunAction::handle($context, $delivery->runId)`. `QUEUED|MATERIALIZING` redispatch with the DTO `pollAfterMs` clamped to `1000..30000` milliseconds and does not increment the delivery attempt. `READY` calls:

```php
$export = $this->createExports->handle(
    $context,
    $run->id,
    $delivery->executionInput()->exportData(),
    $delivery->exportIdempotencyKey(),
);
```

`pollExport()` calls `GetReportExportAction::handle($context, $delivery->exportId)`. `QUEUED|RUNNING|UPLOADING` uses the same bounded poll/re-dispatch rule without attempt increment. `READY` persists the ready transition and redispatches immediately. Run/export `FAILED|CANCELLED|EXPIRED` enter the common retry decision; no Plan 1b store, queue, coordinator, renderer or FileService is called directly.

At `READY`, the processor reloads current authorization once more and calls the exact notifier port. The notifier is idempotent by `notificationIdempotencyKey()->hash`; after a crash it returns the same receipt. `markNotifiedLocked()` atomically requires status `ready`, persists the same notification key hash/receipt ID/notified time, and emits `reports.subscription.delivery_notified`. Thus concurrent jobs or a retry after notifier success cannot create a second in-app notification.

`config/reporting_subscriptions.php` is exact:

```php
return [
    'queue' => 'reports-subscriptions',
    'max_attempts' => 5,
    'backoff_seconds' => [60, 300, 900, 1800],
    'execution_ttl_seconds' => 86400,
    'retention_days' => 90,
    'poll_min_ms' => 1000,
    'poll_max_ms' => 30000,
    'scheduler_batch_size' => 100,
];
```

Only retryable Plan 1a descriptors return to `scheduled`; deterministic run/export keys recover already-created Plan 1b objects, while every retry rebuilds DTOs exclusively from the same pinned delivery bytes. Terminal Plan 1b `FAILED|CANCELLED|EXPIRED` statuses fail the delivery rather than silently changing input. Authorization failures disable the subscription and fail delivery with safe code `REPORT_SCOPE_FORBIDDEN`. Unexpected exceptions are safely logged without message/query/filter data and fail with `REPORT_INTERNAL_ERROR` after the configured budget.

`execution_expires_at` is fixed at scheduling to `scheduled_for + 86400 seconds`. `ExpireReportSubscriptionExecutionsJob` transitions only `scheduled|building_run|building_export|ready` rows whose execution deadline elapsed to terminal `expired`; expiry wins over retry and emits the lifecycle event. `retention_delete_after` is fixed to `created_at + 90 days`. `PruneReportSubscriptionDeliveriesJob` deletes only already-terminal `notified|failed|expired` delivery metadata after that deadline and never changes business status or emits a second expiry transition. Plan 1b alone owns run/export artifact retention and deletion.

Events are allowlisted `reports.subscription.created|updated|paused|resumed|disabled|deleted` and `reports.subscription.delivery_scheduled|run_attached|export_attached|ready|notified|retry_scheduled|failed|expired`. Stable source IDs follow `reports:{subject_type}:{subject_ulid}:{event_code}:{transition_version}`. Evidence includes only organization ID, actor ID, status, attempt, definition-hash prefix, run/export IDs and safe code; never filters, rows, raw exception, recipient payload or private URL.

- [ ] **RED:** add exact DTO/arity reflection tests; state/DST/pinned-retry/manual `ON CONFLICT` interleaving/current-authorization/poll/24-hour-expiry/90-day-prune tests; prove notification is called once after a ready export and never before it.

```php
public function test_ready_export_is_notified_exactly_once(): void
{
    $delivery = $this->delivery(ReportSubscriptionDeliveryStatus::READY);
    $notifier = new RecordingIdempotentNotifier();
    $processor = $this->processor(delivery: $delivery, notifier: $notifier);

    $processor->process($delivery->id);
    $processor->process($delivery->id);

    self::assertCount(1, $notifier->receipts());
    self::assertSame(
        ReportSubscriptionDeliveryStatus::NOTIFIED,
        $this->deliveries->get($delivery->id)->status,
    );
}

public function test_retry_uses_delivery_input_pinned_before_subscription_update(): void
{
    $delivery = $this->scheduleWithInput($this->input(reportCode: 'before_update'));
    $this->subscriptions->replaceExecutionInput(
        $delivery->subscriptionId,
        $this->input(reportCode: 'after_update'),
    );

    $this->processor->process($delivery->id);
    $this->deliveries->rescheduleAfterRetryableFailure($delivery->id);
    $this->processor->process($delivery->id);

    self::assertSame(
        ['before_update', 'before_update'],
        $this->createRuns->reportCodes(),
    );
}

public function test_retention_cleanup_never_changes_business_status(): void
{
    $delivery = $this->notifiedDelivery(retentionDue: true);

    $this->pruner->handle();

    self::assertSame(
        [ReportSubscriptionDeliveryStatus::NOTIFIED],
        $this->audit->businessStatuses($delivery->id),
    );
    self::assertFalse($this->deliveries->exists($delivery->id));
}
```

`ReportSubscriptionPostgresTest` runs the manual race through two independent PostgreSQL connections and explicit barriers. It covers both possible winners for equal pinned input, then both possible winners when the same trigger key races with different pinned input hash/version. The winner receives the inserted ID; the loser receives no `RETURNING` row, successfully executes the locked reread plus `SELECT 1` in the same transaction, and either returns that exact ID for equal hashes or `REPORT_IDEMPOTENCY_CONFLICT` for different hashes. All four interleavings assert one persisted row, no `23505`, no `25P02`, no duplicate dispatch, and a usable loser connection after the decision.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Subscriptions/ReportSubscriptionScheduleCalculatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionCoordinatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionDeliveryProcessorTest.php tests/Unit/Reporting/Subscriptions/DeliverReportSubscriptionJobTest.php tests/Architecture/Reporting/ReportSubscriptionActionPortContractTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionDeliveryProcessor" not found`.

- [ ] **GREEN:** implement migration/config, exact DTOs/ports, optimistic stores, current-context factory, coordinator, scheduler, processor, queue jobs, idempotent notifier, audit adapter and provider bindings.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Subscriptions/ReportSubscriptionScheduleCalculatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionCoordinatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionDeliveryProcessorTest.php tests/Unit/Reporting/Subscriptions/DeliverReportSubscriptionJobTest.php tests/Architecture/Reporting/ReportSubscriptionActionPortContractTest.php`

Expected GREEN: `OK (37 tests, 184 assertions)`.

CI-only Run: `vendor/bin/phpunit tests/Integration/Reporting/ReportSubscriptionPostgresTest.php`

Expected CI GREEN: `OK (23 tests, 136 assertions)` including concurrent `SKIP LOCKED`, calendar uniqueness, four two-connection manual `ON CONFLICT` interleavings with replay/conflict outcomes and a healthy loser transaction, immutable byte/hash/version pinning, after-commit dispatch, optimistic transitions, tenant predicates, execution expiry, retention prune and notification receipt race.

Run: `php -l database/migrations/2026_07_26_000007_create_report_subscriptions_tables.php`

Expected: no syntax errors; migration is not executed locally.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Subscriptions app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionDeliveryStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionFrequency.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionTrigger.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionExecutionInput.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscription.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionDelivery.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionNotificationReceipt.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionStore.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryStore.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryDispatcher.php app/BusinessModules/Core/Reporting/Domain/Contracts/InAppReportSubscriptionNotifier.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionEventRecorder.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportSubscriptionDeliveryDispatcher.php app/BusinessModules/Core/Reporting/Infrastructure/Notifications/PersistedInAppReportSubscriptionNotifier.php app/BusinessModules/Core/Reporting/Infrastructure/Audit/ImmutableAuditReportSubscriptionEventRecorder.php app/BusinessModules/Core/Reporting/Infrastructure/Jobs --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- database/migrations/2026_07_26_000007_create_report_subscriptions_tables.php config/reporting_subscriptions.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionDeliveryStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionFrequency.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportSubscriptionTrigger.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionExecutionInput.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscription.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionDelivery.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionNotificationReceipt.php app/BusinessModules/Core/Reporting/Domain/DTO/CreateReportSubscriptionData.php app/BusinessModules/Core/Reporting/Domain/DTO/UpdateReportSubscriptionData.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionStore.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryStore.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionDeliveryDispatcher.php app/BusinessModules/Core/Reporting/Domain/Contracts/InAppReportSubscriptionNotifier.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionEventRecorder.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportSubscriptionDeliveryDispatcher.php app/BusinessModules/Core/Reporting/Infrastructure/Notifications/PersistedInAppReportSubscriptionNotifier.php app/BusinessModules/Core/Reporting/Infrastructure/Audit/ImmutableAuditReportSubscriptionEventRecorder.php app/BusinessModules/Core/Reporting/Application/Subscriptions app/BusinessModules/Core/Reporting/Infrastructure/Jobs app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php tests/Unit/Reporting/Subscriptions tests/Architecture/Reporting/ReportSubscriptionActionPortContractTest.php tests/Integration/Reporting/ReportSubscriptionPostgresTest.php`

Run: `git commit -m "feat[reports]: завершён жизненный цикл подписок"`

### Task 10: Отдельный cursor contract и Admin API подписок

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionWindow.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionPage.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionCursor.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionCursorCodec.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSubscriptionCursorCodec.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ListReportSubscriptionsHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/CreateReportSubscriptionHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/UpdateReportSubscriptionHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/DeleteReportSubscriptionHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/PauseReportSubscriptionHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/ResumeReportSubscriptionHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Subscriptions/RunReportSubscriptionNowHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSubscriptionsRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSubscriptionRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSubscriptionRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSubscriptionRouteRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Requests/RunReportSubscriptionNowRequest.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionResource.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionDeliveryResource.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionPageResource.php`
- Create: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSubscriptionController.php`
- Create: `docs/reports/contracts/report-subscription-resources.v1.schema.json`
- Create: `tests/Fixtures/Reporting/Wire/report-subscription-resources.v1.json`
- Modify: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSubscriptionStore.php`
- Modify: `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php`
- Modify: `app/BusinessModules/Core/Reporting/routes.php`
- Modify: `lang/ru/reports.php`
- Test: `tests/Unit/Reporting/Cursors/ReportSubscriptionCursorTest.php`
- Test: `tests/Unit/Reporting/Cursors/SignedReportSubscriptionCursorCodecTest.php`
- Test: `tests/Unit/Reporting/Http/ReportSubscriptionResourceSchemaTest.php`
- Test: `tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php`
- Test: `tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php`
- Test: `tests/Feature/Api/V1/Admin/Reporting/ReportSubscriptionAuthorizationTest.php`

Subscription listing never imports or returns the Plan 1a rows `ReportPage`, `ReportRowsWindow`, totals, freshness, quality, provenance or rows sort. Its exact contracts are:

```php
final readonly class ReportSubscriptionWindow
{
    public function __construct(
        public ?string $cursor,
        public int $limit,
        public ?ReportSubscriptionStatus $status,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['limit']],
            );
        }
    }
}

final readonly class ReportSubscriptionPage
{
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $limit,
        public bool $hasMore,
    ) {
    }
}

final readonly class ReportSubscriptionCursor
{
    public const VERSION = 1;
    public const ORDER = 'next_run_at_asc_nulls_last__id_asc';

    public function __construct(
        public int $version,
        public int $organizationId,
        public int $ownerId,
        public ?ReportSubscriptionStatus $statusFilter,
        public string $order,
        public ?DateTimeImmutable $lastNextRunAt,
        public string $lastId,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException('subscription_cursor_version_invalid');
        }
        if ($organizationId < 1 || $ownerId < 1) {
            throw new InvalidArgumentException('subscription_cursor_scope_invalid');
        }
        if ($statusFilter === ReportSubscriptionStatus::DELETED) {
            throw new InvalidArgumentException('subscription_cursor_filter_invalid');
        }
        if ($order !== self::ORDER) {
            throw new InvalidArgumentException('subscription_cursor_order_invalid');
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $lastId) !== 1) {
            throw new InvalidArgumentException('subscription_cursor_last_id_invalid');
        }
        if (
            ($lastNextRunAt !== null && $lastNextRunAt->getOffset() !== 0)
            || $expiresAt->getOffset() !== 0
        ) {
            throw new InvalidArgumentException('subscription_cursor_timestamp_invalid');
        }
    }
}

interface ReportSubscriptionCursorCodec
{
    public function encode(
        ReportExecutionContext $context,
        ReportSubscriptionCursor $cursor,
    ): string;

    public function decode(
        ReportExecutionContext $context,
        ReportSubscriptionStatus|null $expectedStatusFilter,
        string $cursor,
    ): ReportSubscriptionCursor;
}
```

`ReportSubscriptionPage::$items` is runtime-guarded `list<ReportSubscription>`. The store fetches `limit + 1` rows and returns no exact total. Fixed SQL order is `next_run_at ASC NULLS LAST, id ASC`; clients cannot override it. `SignedReportSubscriptionCursorCodec` signs canonical JSON produced from all eight DTO fields. `encode()` verifies cursor organization/owner against context. `decode()` verifies signature, constructs the DTO, compares organization/owner/status/order to context and request, then rejects `expiresAt <= clock->now()`. Invalid base64/JSON/signature/version/scope/filter/order/ULID/timestamp or expired cursor maps to `REPORT_CURSOR_INVALID` with no decoded fields.

**Exact handler signatures:**

```php
final class ListReportSubscriptionsHandler
{
    public function handle(
        ReportExecutionContext $context,
        ReportSubscriptionWindow $window,
    ): ReportSubscriptionPage;
}

final class CreateReportSubscriptionHandler
{
    public function handle(
        ReportExecutionContext $context,
        CreateReportSubscriptionData $data,
    ): ReportSubscription;
}

final class UpdateReportSubscriptionHandler
{
    public function handle(
        ReportExecutionContext $context,
        string $id,
        UpdateReportSubscriptionData $data,
    ): ReportSubscription;
}

final class DeleteReportSubscriptionHandler
{
    public function handle(ReportExecutionContext $context, string $id): void;
}

final class PauseReportSubscriptionHandler
{
    public function handle(
        ReportExecutionContext $context,
        string $id,
    ): ReportSubscription;
}

final class ResumeReportSubscriptionHandler
{
    public function handle(
        ReportExecutionContext $context,
        string $id,
    ): ReportSubscription;
}

final class RunReportSubscriptionNowHandler
{
    public function handle(
        ReportExecutionContext $context,
        string $id,
        IdempotencyKey $key,
    ): ReportSubscriptionDelivery;
}
```

`ListReportSubscriptionsHandler` calls `ReportAccessService::assertOperation()` with `ReportOperation::MANAGE` for every returned published definition and omits an entry if it is no longer visible; it never leaks a foreign subscription. All mutation handlers delegate to one `ReportSubscriptionCoordinator` method, which repeats current definition/scope checks under lock. `RunReportSubscriptionNowHandler` requires the exact Plan 1a `IdempotencyKey`.

**FormRequest and controller matrix:**

| Method and URI | Request | One handler call | Response |
|---|---|---|---|
| GET `/api/v1/admin/reports/subscriptions` | `ListReportSubscriptionsRequest` | `ListReportSubscriptionsHandler::handle($context,$request->toWindow())` | `ReportSubscriptionPageResource`, 200 |
| POST `/api/v1/admin/reports/subscriptions` | `CreateReportSubscriptionRequest` | `CreateReportSubscriptionHandler::handle($context,$request->toData())` | `ReportSubscriptionResource`, 201 |
| PATCH `/api/v1/admin/reports/subscriptions/{subscriptionId}` | `UpdateReportSubscriptionRequest` | `UpdateReportSubscriptionHandler::handle($context,$request->routeId(),$request->toData())` | `ReportSubscriptionResource`, 200 |
| DELETE `/api/v1/admin/reports/subscriptions/{subscriptionId}` | `ReportSubscriptionRouteRequest` | `DeleteReportSubscriptionHandler::handle($context,$request->routeId())` | `AdminResponse`, 204 |
| POST `/api/v1/admin/reports/subscriptions/{subscriptionId}/pause` | `ReportSubscriptionRouteRequest` | `PauseReportSubscriptionHandler::handle($context,$request->routeId())` | `ReportSubscriptionResource`, 200 |
| POST `/api/v1/admin/reports/subscriptions/{subscriptionId}/resume` | `ReportSubscriptionRouteRequest` | `ResumeReportSubscriptionHandler::handle($context,$request->routeId())` | `ReportSubscriptionResource`, 200 |
| POST `/api/v1/admin/reports/subscriptions/{subscriptionId}/run-now` | `RunReportSubscriptionNowRequest` | `RunReportSubscriptionNowHandler::handle($context,$request->routeId(),$request->idempotencyKey())` | `ReportSubscriptionDeliveryResource`, 201 |

These are the only seven subscription route entries. Route snapshot and generated route lock assert zero URI whose path starts with `/api/v1/admin/reporting`. Every route has both `reports.view` and `reports.manage` middleware. `ReportSubscriptionRouteRequest` validates a ULID. `RunReportSubscriptionNowRequest` accepts an empty body and requires printable ASCII `Idempotency-Key`; it constructs `IdempotencyKey`, never a raw string. Create/update requests accept only saved view, frequency, weekday/day-of-month, local time, timezone, period policy and format. Central forbidden fields include `organization_id`, `owner_id`, `actor_id`, `report_code`, `channel`, `recipients`, `status`, `next_run_at`, `execution_input`, `definition_hash`, `contract_version`, `consecutive_failures`, `run_id`, `export_id` and `notification_receipt_id`.

Each controller method obtains `ReportExecutionContext` from the Plan 1a factory, invokes exactly one handler and serializes exactly one resource via `AdminResponse`. It contains no DB/query/transaction/access/schedule/state-machine logic.

**Strict wire contract:**

```json
{
  "subscription": {
    "required": [
      "id",
      "saved_view_id",
      "report_code",
      "frequency",
      "weekday",
      "day_of_month",
      "local_time",
      "timezone",
      "period_policy",
      "format",
      "channel",
      "status",
      "disabled_reason",
      "consecutive_failures",
      "next_run_at",
      "created_at",
      "updated_at"
    ]
  },
  "delivery": {
    "required": [
      "id",
      "subscription_id",
      "trigger",
      "scheduled_for",
      "run_id",
      "export_id",
      "attempt",
      "status",
      "safe_error_code",
      "created_at",
      "updated_at",
      "expires_at"
    ]
  },
  "page": {
    "required": ["items", "meta"],
    "meta_required": ["limit", "next_cursor", "has_more"]
  }
}
```

All objects in `report-subscription-resources.v1.schema.json` set `additionalProperties: false`; the page has `items: array<subscription>` and exact meta fields above. Delivery `expires_at` is the public projection of immutable `executionExpiresAt`; the 90-day cleanup deadline is not exposed. The resources exclude organization/owner IDs, execution input/hash/version, definition hash, manual/trigger/notification key hashes, receipt ID, private artifact path, filters and exact total. Tests call the Task 1 `Draft202012SchemaValidator` backed by `Opis\JsonSchema\CompliantValidator` 2.6.0 for the valid fixture and one negative fixture per missing/extra/wrong-type field.

Only the existing Plan 1a HTTP catalog is used:

| Condition | Stable code |
|---|---|
| foreign/missing/deleted/non-published subscription or saved view | `REPORT_NOT_FOUND` |
| current organization/actor/permission denied | `REPORT_SCOPE_FORBIDDEN` |
| malformed schedule, unsupported capability, non-reproducible definition or invalid transition | `REPORT_REQUEST_INVALID` |
| invalid list cursor | `REPORT_CURSOR_INVALID` |
| missing/malformed idempotency header | `REPORT_IDEMPOTENCY_KEY_INVALID` |
| same manual key with different input | `REPORT_IDEMPOTENCY_CONFLICT` |
| transient dependency | `REPORT_DEPENDENCY_FAILED` |

No subscription-specific or quality-gate HTTP code is introduced. Unsupported capability and invalid transition are 422, not an invented 409 branch.

- [ ] **RED:** add dedicated page/cursor reflection test, positive/negative Opis resource-schema tests, exact seven-route snapshot, malformed request matrix, tenant pagination and permission matrix.

```php
public function test_subscription_list_does_not_reuse_report_rows_page(): void
{
    $method = new ReflectionMethod(ListReportSubscriptionsHandler::class, 'handle');

    self::assertSame(
        ReportSubscriptionPage::class,
        $method->getReturnType()?->getName(),
    );
    self::assertStringNotContainsString(
        ReportPage::class,
        file_get_contents((new ReflectionClass($method->class))->getFileName()),
    );
}

public function test_cursor_constructor_and_codec_return_type_are_exact(): void
{
    $cursor = new ReportSubscriptionCursor(
        1,
        10,
        20,
        ReportSubscriptionStatus::ACTIVE,
        ReportSubscriptionCursor::ORDER,
        new DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        '01J3R5QZ6H7K8M9N0P1Q2R3S4T',
        new DateTimeImmutable('2026-07-26T09:15:00+00:00'),
    );
    $decode = new ReflectionMethod(ReportSubscriptionCursorCodec::class, 'decode');

    self::assertSame(ReportSubscriptionCursor::VERSION, $cursor->version);
    self::assertSame(
        ReportSubscriptionCursor::class,
        $decode->getReturnType()?->getName(),
    );
}

public function test_subscription_routes_use_only_canonical_reports_prefix(): void
{
    self::assertSame(
        $this->expectedSevenSubscriptionRoutes(),
        $this->actualSubscriptionRoutes(),
    );
    self::assertSame(
        [],
        $this->routesStartingWith('/api/v1/admin/reporting'),
    );
}
```

`ReportSubscriptionCursorTest` separately rejects version other than `1`, non-positive organization/owner, `deleted` filter, any order token other than the constant, malformed/lowercase ULID and non-UTC `lastNextRunAt`/expiry. Codec tests separately reject expired cursors and every context/filter/signature mismatch.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Cursors/ReportSubscriptionCursorTest.php tests/Unit/Reporting/Cursors/SignedReportSubscriptionCursorCodecTest.php tests/Unit/Reporting/Http/ReportSubscriptionResourceSchemaTest.php tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage" not found`.

- [ ] **GREEN:** implement dedicated cursor/page, store query, handlers, requests, strict resources/schema, controller, translations, routes and container bindings.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Cursors/ReportSubscriptionCursorTest.php tests/Unit/Reporting/Cursors/SignedReportSubscriptionCursorCodecTest.php tests/Unit/Reporting/Http/ReportSubscriptionResourceSchemaTest.php tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php`

Expected GREEN: `OK (24 tests, 137 assertions)`.

CI-only Run: `vendor/bin/phpunit tests/Feature/Api/V1/Admin/Reporting/ReportSubscriptionAuthorizationTest.php`

Expected CI GREEN: `OK (19 tests, 96 assertions)` including foreign tenant indistinguishability, permission revocation and idempotent run-now.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionWindow.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionPage.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionCursor.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionCursorCodec.php app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSubscriptionCursorCodec.php app/BusinessModules/Core/Reporting/Application/Subscriptions/ListReportSubscriptionsHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/CreateReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/UpdateReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/DeleteReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/PauseReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/ResumeReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/RunReportSubscriptionNowHandler.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSubscriptionsRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSubscriptionRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSubscriptionRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSubscriptionRouteRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/RunReportSubscriptionNowRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionResource.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionDeliveryResource.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionPageResource.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSubscriptionController.php --no-progress`

Expected: exit 0, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionWindow.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionPage.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportSubscriptionCursor.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionCursorCodec.php app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportSubscriptionCursorCodec.php app/BusinessModules/Core/Reporting/Application/Subscriptions/ListReportSubscriptionsHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/CreateReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/UpdateReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/DeleteReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/PauseReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/ResumeReportSubscriptionHandler.php app/BusinessModules/Core/Reporting/Application/Subscriptions/RunReportSubscriptionNowHandler.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ListReportSubscriptionsRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportSubscriptionRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/UpdateReportSubscriptionRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportSubscriptionRouteRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Requests/RunReportSubscriptionNowRequest.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionResource.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionDeliveryResource.php app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSubscriptionPageResource.php app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportSubscriptionController.php app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSubscriptionStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSubscriptionStore.php app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php app/BusinessModules/Core/Reporting/routes.php docs/reports/contracts/report-subscription-resources.v1.schema.json tests/Fixtures/Reporting/Wire/report-subscription-resources.v1.json lang/ru/reports.php tests/Unit/Reporting/Cursors/ReportSubscriptionCursorTest.php tests/Unit/Reporting/Cursors/SignedReportSubscriptionCursorCodecTest.php tests/Unit/Reporting/Http/ReportSubscriptionResourceSchemaTest.php tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php tests/Feature/Api/V1/Admin/Reporting/ReportSubscriptionAuthorizationTest.php`

Run: `git commit -m "feat[reports]: добавлен cursor API подписок"`

### Task 11: Offline quality gates, catalog activation и platform/release separation

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidencePhase.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidenceStatus.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityGateFailureCode.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityGateEvidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityEvidenceLedger.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivation.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputBundle.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputs.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportReleaseGateBundle.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportingArtifactTransfer.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportCleanupEvidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/JointQG14Evidence.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/JointQG14EvidenceSource.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Quality/ReportQualityGateException.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Quality/ReportPlatformGateCatalog.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Quality/ReportReleaseEvidenceBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Quality/ReportReleaseGateBundleBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleLoader.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/ReportingArtifactTransferService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/ReportCleanupEvidenceBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Quality/FixedRootJointQG14EvidenceSource.php`
- Create: `app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json`
- Modify on activation re-entry: `app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml`
- Modify on activation re-entry: `app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json`
- Create: `docs/reports/contracts/report-platform-gates.v1.json`
- Create: `docs/reports/contracts/report-quality-evidence.schema.json`
- Create: `docs/reports/contracts/report-catalog-activation.schema.json`
- Create: `docs/reports/contracts/report-catalog-activation-input-bundle.schema.json`
- Create: `docs/reports/contracts/report-release-gate-bundle.schema.json`
- Create: `docs/reports/contracts/reporting-artifact-transfer.schema.json`
- Create: `docs/reports/contracts/report-cleanup-evidence.schema.json`
- Create: `scripts/reporting/build-report-quality-evidence.php`
- Create: `scripts/reporting/activate-report-catalog.php`
- Create: `scripts/reporting/build-report-catalog-activation-inputs.php`
- Create: `scripts/reporting/build-report-release-gate-bundle.php`
- Create: `scripts/reporting/transfer-reporting-artifact.php`
- Create: `scripts/reporting/build-report-cleanup-evidence.php`
- Create: `tests/Fixtures/Reporting/Quality/platform-gates.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-platform-evidence.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-release-evidence.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-catalog-activation.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/current.yaml`
- Create: `tests/Fixtures/Reporting/Activation/candidate-28.yaml`
- Create: `tests/Fixtures/Reporting/Activation/validation-28.json`
- Create: `tests/Fixtures/Reporting/Activation/bindings-28.json`
- Create: `tests/Fixtures/Reporting/Activation/conformance-28.json`
- Create: `tests/Fixtures/Reporting/Activation/plan-2.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/plan-3.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/report-catalog-activation-input-bundle.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/plan-2-wave-1-evidence.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/waves-2-3-candidate-contribution.valid.json`
- Create: `tests/Fixtures/Reporting/Activation/plan-3-waves-2-3-evidence.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-release-gate-bundle.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/plan-4-admin-evidence.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/plan-4-admin-evidence-transfer.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/reporting-artifact-transfer.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-release-evidence-transfer.valid.json`
- Create: `tests/Fixtures/Reporting/Quality/report-cleanup-evidence.valid.json`
- Create: `tests/Support/Reporting/Quality/ReportPlatformGateFixtureBuilder.php`
- Test: `tests/Architecture/Reporting/ReportPlatformGateCatalogTest.php`
- Test: `tests/Architecture/Reporting/ReportQualityGateHttpIsolationTest.php`
- Test: `tests/Architecture/Reporting/ReportCatalogActivationInputBundleSchemaTest.php`
- Test: `tests/Architecture/Reporting/ReportReleaseGateBundleSchemaTest.php`
- Test: `tests/Architecture/Reporting/ReportingArtifactTransferSchemaTest.php`
- Test: `tests/Architecture/Reporting/ReportCleanupEvidenceSchemaTest.php`
- Test: `tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php`
- Test: `tests/Unit/Reporting/Quality/ReportPlatformGateFixtureBuilderTest.php`
- Test: `tests/Unit/Reporting/Quality/ReportReleaseGateBundleBuilderTest.php`
- Test: `tests/Unit/Reporting/Quality/FixedRootJointQG14EvidenceSourceTest.php`
- Test: `tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php`
- Test: `tests/Unit/Reporting/Publication/ReportCatalogActivationInputBundleBuilderTest.php`
- Test: `tests/Unit/Reporting/Evidence/ReportingArtifactTransferServiceTest.php`
- Test: `tests/Unit/Reporting/Evidence/ReportCleanupEvidenceBuilderTest.php`
- Test: `tests/Integration/Reporting/ReportCatalogWorkspaceSubscriptionIntegrationTest.php`
- Test: `tests/Integration/Reporting/ReportActivationReleaseReentryTest.php`
- Test: `tests/Integration/Reporting/ReportAdminEvidenceTransferReentryTest.php`
- Test: `tests/Integration/Reporting/ReportCleanupEvidenceReentryTest.php`
- Modify: `.gitignore`

**Closed offline contracts:**

```php
enum ReportQualityEvidencePhase: string
{
    case PLATFORM = 'platform';
    case RELEASE = 'release';
}

enum ReportQualityEvidenceStatus: string
{
    case PENDING = 'pending';
    case PASSED = 'passed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}

enum ReportQualityGateFailureCode: string
{
    case MISSING = 'missing';
    case INVALID = 'invalid';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case STALE = 'stale';
    case RELEASE_SHA_MISMATCH = 'release_sha_mismatch';
    case SCHEMA_HASH_MISMATCH = 'schema_hash_mismatch';
    case COMMAND_COUNT_MISMATCH = 'command_count_mismatch';
    case CATALOG_COUNT_MISMATCH = 'catalog_count_mismatch';
    case BINDING_SET_MISMATCH = 'binding_set_mismatch';
    case GROUP_COVERAGE_MISMATCH = 'group_coverage_mismatch';
    case PHASE_INCOMPLETE = 'phase_incomplete';
}

final class ReportQualityGateException extends RuntimeException
{
    public function __construct(
        public readonly ReportQualityGateFailureCode $failureCode,
    ) {
        parent::__construct("quality-gate:{$failureCode->value}", 2);
    }

    public function exitCode(): int
    {
        return 2;
    }
}
```

This exception is exclusively an offline command/artifact failure. It does not extend `ReportContractException`, does not contain a `ReportErrorCode`, is never caught by `RenderReportErrors`, is never serialized through `AdminResponse`, and never appears in routes, FormRequests, controllers or resources. Offline scripts catch it, print exactly its safe ASCII message to STDERR and exit `2`; unexpected script errors print `quality-gate:invalid` and also exit `2`. No gate-evidence HTTP member is added to the fixed Plan 1a catalog.

```php
final readonly class ReportQualityGateEvidence
{
    public function __construct(
        public string $gate,
        public string $ownerPlan,
        public ReportQualityEvidencePhase $phase,
        public ReportQualityEvidenceStatus $status,
        public string $command,
        public int $count,
        public Sha256Hash $schemaHash,
        public string $releaseSha,
        public string $commitSha,
        public DateTimeImmutable $executedAt,
        public ?Sha256Hash $artifactHash,
    ) {
    }
}

final readonly class ReportQualityEvidenceLedger
{
    public function __construct(
        public string $status,
        public string $releaseSha,
        public Sha256Hash $manifestHash,
        public int $managementIdentityCount,
        public int $publishedCount,
        public int $bindingCount,
        public array $catalogGroups,
        public array $gates,
        public array $prerequisiteEvidenceHashes,
        public DateTimeImmutable $generatedAt,
    ) {
    }
}
```

All commit/release SHAs, timestamps, gate IDs, owner plans, exact allowed commands/counts and artifact hashes are constructor-validated. Evidence contains no filters, comparison, report rows, personal data, exception message, notification contents, SQL, object path or private URL.

**All 14 gates and phase ownership:**

| Gate | Platform record | Release record and owner |
|---|---|---|
| QG-01 | Plan 1c: 28 unique manifest identities, exact 12/10/6 waves, M-29 separate, seven catalog groups | owner `backend` — Plan 1c activation: exactly 28 nominal published definitions and exact same 28 binding codes |
| QG-02 | `pending`, owner `plans-2-3` | owner `backend` — Plans 2–3: exactly 56 golden fixtures, two for every code |
| QG-03 | `pending`, owner `plans-2-3` | owner `backend` — Plans 2–3: at least 500 deterministic seeds per formula family |
| QG-04 | Plan 1c: schema/harness/source/formula/provenance lock mechanics pass | owner `backend` — Plans 2–3: 28 passed per-code conformance hashes plus Plan 1b snapshot/result/page/export equality |
| QG-05 | Plans 1a/1c: access matrix, tenant workspace/saved-view/subscription isolation | owner `backend` — Plans 2–3: 28 per-code action matrices; Plan 4 serialization-redaction output is supporting evidence and does not change ownership |
| QG-06 | Plans 1a/1c: backend routes/resources/errors plus 46 malformed page cases | owner `backend` — backend API schema/parser/translation/route contract; Plan 4 parser evidence is byte-locked supporting input, not gate ownership |
| QG-07 | Plan 1b foundation evidence reference, domain portion pending | owner `backend` — Plans 2–3: per-code PostgreSQL cursor/index/query budget/N+1/performance evidence; fresh ≤24h |
| QG-08 | Plan 1b export state/idempotency/S3 evidence reference | owner `backend` — Plans 2–3: 28 semantic export parity records |
| QG-09 | Plans 1a/1b/1c scoped syntax/tests/PHPStan with zero suppression | owner `backend` — Plans 2–3 backend scoped static evidence |
| QG-10 | `pending`, owner `plan-4` | owner `admin` — Plan 4: 28 typed admin definitions, strict parser and MSW lock |
| QG-11 | `pending`, owner `plan-4` | owner `admin` — Plan 4: ≥252 parametrized states plus export states |
| QG-12 | `pending`, owner `plan-4` | owner `admin` — Plan 4: a11y roles/names/keyboard/focus/live-region and breakpoint branches |
| QG-13 | `pending`, owner `plan-4` | owner `admin` — Plan 4: TypeScript typecheck, scoped lint and exact formatter |
| QG-14 | `pending`, owner `plan-4`; no claim of cutover absence | owner `both` — one fixed two-root command, separate admin/backend/combined zero counts and three recomputed section/output hashes |

The release-phase owner map is closed and identical to Plan 4: `QG-01..QG-09=backend`, `QG-10..QG-13=admin`, `QG-14=both`. Aggregate ownership counts are exactly `backend=9`, `admin=4`, `joint=1`; the sole joint-count bucket member is gate `QG-14`, whose serialized owner remains `both`. QG-06 remains the backend API contract even when an admin parser/serialization artifact contributes a byte hash. No evidence source may promote QG-06 to `both` or move it into the joint-count bucket.

`docs/reports/contracts/report-platform-gates.v1.json` is the tracked catalog with exactly these 14 IDs, phase owners, allowed commands, minimum counts, schema hashes and fixed platform source-path catalogs. It is configuration, not proof. Passed platform gates QG-01/QG-04/QG-05/QG-06/QG-09 have non-empty closed source-path lists pointing only to the exact tracked manifest/schema/test/command-contract fixtures owned by Plans 1a/1b and Tasks 1–11; pending gates have an empty source list and their exact downstream owner. Unknown, duplicate, missing or reordered gates and alternate source paths are invalid.

`tests/Fixtures/Reporting/Quality/platform-gates.valid.json` is owned by Task 11 and is never handwritten gate truth. `ReportPlatformGateFixtureBuilder` accepts only repository root, fixed fixture release SHA and fixed fixture timestamp; it rereads the tracked catalog and every catalog-named source byte, computes all catalog/source/schema hashes, derives status/owner/command/count from the catalog, and serializes through `CanonicalJson`. The canonical root is exactly:

```text
artifact_id, schema_version, status, catalog, release_sha, generated_at, gates
```

Constants are `report_platform_gate_inputs`, `1.0.0`, `platform_gate_inputs_passed`. `catalog` has exactly fixed path `docs/reports/contracts/report-platform-gates.v1.json` and its reread lowercase SHA-256. `gates` preserves QG-01..QG-14 order; each record is the strict serialized `ReportQualityGateEvidence` plus an ordered `source_artifacts` array of exact path/raw-byte SHA pairs. Passed records require all catalog-named real source refs; pending records require none. The `$defs.platform_gate_inputs` branch of `report-quality-evidence.schema.json` is recursively closed and the fixture test regenerates the complete JSON, then requires byte equality with the tracked file.

`build-report-quality-evidence.php` requires exactly one `--gates` option in both phases. In Task 11 fixture mode its only accepted path is `tests/Fixtures/Reporting/Quality/platform-gates.valid.json`; release mode accepts only `build/reports/report-release-gate-bundle.json`. It rereads the gate bytes and tracked catalog, strict-schema validates, checks canonical bytes, catalog/source hashes, exact IDs/order/status/owner/command/count/release/timestamp, and only then supplies typed evidence to `ReportReleaseEvidenceBuilder`. Omitted/duplicate `--gates`, an alternate/symlinked path, caller-invented record, stale catalog hash or mismatched source byte fails before output mutation.

**Two non-interchangeable build methods:**

```php
final class ReportReleaseEvidenceBuilder
{
    public function buildPlatform(
        LoadedReportManifest $managementManifest,
        LoadedReportManifest $officialManifest,
        array $gateEvidence,
        array $prerequisiteEvidence,
        string $releaseSha,
        DateTimeImmutable $generatedAt,
    ): ReportQualityEvidenceLedger;

    public function buildRelease(
        ReportDefinitionRegistry $publishedRegistry,
        ReportDefinitionBindingMap $bindingMap,
        ReportCatalogActivation $activation,
        array $gateEvidence,
        array $prerequisiteEvidence,
        string $releaseSha,
        DateTimeImmutable $generatedAt,
    ): ReportQualityEvidenceLedger;
}
```

`buildPlatform()` requires:

1. valid commit-bound Plan 1a hermetic HTTP completion and Plan 1b post-CI completion, both with status `passed` and matching lock/schema/commit/artifact hashes;
2. exactly 28 management identities, no duplicates, exact waves 12/10/6, exactly seven non-empty groups and only separate official M-29;
3. exactly 14 gate records;
4. platform `passed` for QG-01, QG-04, QG-05, QG-06 and QG-09;
5. explicit `pending` owner records for QG-02, QG-03, QG-07 domain portion, QG-08 per-code parity, QG-10–QG-14;
6. no failed/skipped record and matching schema/release/command/count fields for every passed record.

It returns status exactly `platform_passed`. Published registry size may be `0..28` during foundation, but the ledger records the actual number and cannot call it release-ready. If a binding map is supplied to platform validation, its code set must equal the published code set, including the valid empty/empty case.

`buildRelease()` is unavailable until after all Plans 2–3 evidence, controlled activation and Plan 4 evidence. It requires:

1. exactly 28 distinct `ReportDefinitionRegistry::publishedCodes()`;
2. exact equality with 28 `ReportDefinitionBindingMap` codes;
3. each nominal wrapper payload has source/formula `ready`, delivery `verified`, publication `published`;
4. 28 byte locks, 28 conformance hashes, 28 definition hashes, exact seven groups and manifest/generated/resource hash equality;
5. Plans 1a/1b/1c platform artifacts and Plans 2/3/4 artifacts match the same release SHA;
6. QG-01–QG-14 all have release-phase status `passed`; none is pending/skipped/failed;
7. QG-02 count exactly 56, QG-03 family seed counts ≥500, QG-05 action count exactly 28, QG-06 malformed count exactly 46, QG-11 state count ≥252; QG-14 has `admin_forbidden_symbol_matches=0`, `backend_forbidden_symbol_matches=0`, `combined_forbidden_symbol_matches=0`, proves the combined count is the exact admin-plus-backend sum and carries three independently recomputed section/output hashes;
8. every QG-07 performance artifact belongs to the release SHA and is no older than 24 hours.

It returns status exactly `release_passed`. Neither method mutates registries or performs publication.

**Controlled 28-definition activation between Plans 2–3 and Plan 4:**

```php
final readonly class ReportCatalogActivation
{
    public function __construct(
        public string $status,
        public string $releaseSha,
        public Sha256Hash $previousManifestHash,
        public Sha256Hash $publishedManifestHash,
        public array $publishedCodes,
        public array $bindingCodes,
        public array $publicationLockHashes,
        public array $conformanceHashes,
        public DateTimeImmutable $activatedAt,
    ) {
    }
}
```

`ReportCatalogActivationService` consumes only Plan 1c candidate registry/validation/promotion contracts and tracked Plans 2–3 evidence:

```php
public function activate(
    LoadedReportManifest $current,
    LoadedReportManifest $candidate,
    ReportCandidateValidationResult $validation,
    iterable $candidateBindings,
    iterable $conformanceEvidence,
    array $planEvidenceDocuments,
    string $releaseSha,
    DateTimeImmutable $activatedAt,
): ReportCatalogActivation;
```

Before writing, it proves exact set equality among the 28 manifest codes, 28 candidate wrapper codes, 28 unique seven-field binding codes, 28 passed conformance codes and the union of Plan 2/3 evidence codes. Plans 2–3 documents may contain only candidate inputs, bindings and evidence; they cannot contain a published wrapper, publication lock, active manifest or publication operation. The service stages all 28 promotions through Task 5 into one temporary management YAML and temporary ledger, rereads with Opis + semantic loader after each step, then performs a single atomic replacement of the active management manifest and ledger only after final 28/28 validation. Any error removes only the bounded staging directory and leaves the previous active bytes unchanged.

Activation output status is exactly `catalog_activated`, not `platform_passed` or `release_passed`. Plan 4 starts only after its schema validates and it proves 28 published codes, 28 binding codes and 28 lock hashes. Activation itself does not claim QG-10–QG-14.

Both quality and activation JSON Schemas use Draft 2020-12, `additionalProperties:false` at every object, and the Task 1 `Draft202012SchemaValidator` (`Opis\JsonSchema\CompliantValidator` 2.6.0). No dependency changes occur in this task.

**Mandatory combined activation-input producer:**

The fixture-only granular activation command below remains a Task 11 contract test. It is not a production handoff. Production activation has exactly one input bundle:

| Field | Exact value |
|---|---|
| `artifact_id` | `report_catalog_activation_inputs` |
| path | `build/reports/report-catalog-activation-inputs.json` |
| schema | `docs/reports/contracts/report-catalog-activation-input-bundle.schema.json` |
| producer | `scripts/reporting/build-report-catalog-activation-inputs.php` |
| status | `activation_inputs_passed` |
| persistence | ignored/untracked, canonical JSON, temporary file + atomic rename |

The producer consumes these three real, materialized child artifacts and no fixture substitute:

| Owner | Exact artifact contract |
|---|---|
| Plan 2 Task 9 re-entry | `artifact_id=plan-2-wave-1-candidate-conformance`, `build/reports/plan-2-wave-1-evidence.json`, `docs/reports/contracts/plan-2-wave-1-evidence.schema.json`, status `candidate_evidence_passed`, exact `12` candidates/bindings/conformance records and exact `12` property families/`6000` seeds |
| Plan 3 Task 16 re-entry | `artifact_id=plan3_waves23_candidate_contribution`, `build/reports/waves-2-3-candidate-contribution.json`, `docs/reports/contracts/waves-2-3-candidate-contribution.schema.json`, status `candidate_contribution_passed`, exact ordered `10+6` candidate contribution |
| Plan 3 Task 17 re-entry | `artifact_id=plan3_waves23_evidence`, `build/reports/plan-3-waves-2-3-evidence.json`, `docs/reports/contracts/plan-3-waves-2-3-evidence.schema.json`, status `candidate_evidence_passed`, exact `16` conformance records, exact `8` property families/`4000` seeds and a digest link to the Task 16 artifact |

The exact interfaces are:

```php
final readonly class ReportCatalogActivationInputBundle
{
    public function __construct(
        public string $artifactId,
        public string $status,
        public string $releaseSha,
        public array $sourceArtifacts,
        public array $candidateManifest,
        public array $candidatePayloads,
        public array $validationItems,
        public array $bindings,
        public array $conformanceRecords,
        public array $planEvidenceDocuments,
        public array $counts,
        public array $sectionHashes,
        public DateTimeImmutable $generatedAt,
    ) {
    }
}

final class ReportCatalogActivationInputBundleBuilder
{
    public function build(
        string $releaseSha,
        DateTimeImmutable $generatedAt,
    ): ReportCatalogActivationInputBundle;
}

final class ReportCatalogActivationInputBundleLoader
{
    public function load(string $path): ReportCatalogActivationInputs;
}

final readonly class ReportCatalogActivationInputs
{
    public function __construct(
        public LoadedReportManifest $candidateManifest,
        public ReportCandidateValidationResult $validation,
        public array $candidateBindings,
        public array $conformanceEvidence,
        public array $planEvidenceDocuments,
    ) {
    }
}
```

The script supplies a fixed artifact catalog to the builder. Caller paths must equal the three exact paths in the table after repository-root canonicalization; symlinks, `..`, alternate schemas, alternate conformance roots and fixture paths fail closed. At controlled re-entry the builder:

1. rereads all three artifact bytes and all three tracked schema bytes, validates Draft 2020-12, recomputes their SHA-256 values and requires the exact IDs/statuses;
2. requires Plan 2 `counts={candidate_definitions:12,bindings:12,conformance_records:12,property_families:12,property_seeds:6000}`, verifies its candidate/binding/conformance plus `property_families_sha256` section hashes over canonical arrays, requires its `plan_commit_sha` to be the exact Task 9 owner commit/ancestor and its root `release_sha` plus every conformance/property `record.commitSha|commit_sha` to equal the freeze commit;
3. requires Plan 3 contribution/evidence to share the same explicit `release_sha` and producer freeze commit, and requires the evidence's candidate-artifact digest to equal the reread contribution digest;
4. reads the exact Plan 2 `candidate_manifest.path` bytes and the exact Plan 3 candidate source bytes named by the contribution, verifies their embedded hashes against committed blobs at the freeze commit, parses both with `ReportDefinitionFactory`, and canonicalizes one ordered manifest with Wave 1 `12`, Wave 2 `10`, Wave 3 `6`;
5. instantiates the committed `WaveOneCandidateBindingSet` and `Waves23CandidateBindingSet` classes through their exact class/method catalog, rejects a missing/wrong class or method, and compares the resulting real `ReportDefinitionBinding` objects to the serialized child descriptors;
6. loads all `12+16` content-addressed conformance files recorded by the child artifacts through `FilesystemReportConformanceEvidenceRepository`, validates each full `ReportDefinitionConformanceEvidence`, and compares its digest to the child record;
7. constructs one isolated `CandidateReportDefinitionRegistry`, calls `StrictReportDefinitionCandidateValidator` once over all `28` candidates and all `28` real bindings, and rejects any failed item;
8. atomically emits one canonical bundle, rereads it, revalidates its schema and semantics, requires the reread SHA-256 to equal the pre-write digest, and only then prints success.

The two downstream binding-set FQCN/method pairs are closed string metadata resolved only by the re-entry CLI, so the Task 11 foundation commit has no unresolved compile-time dependency on not-yet-created Plan 2/3 classes. The invoker requires the resolved object, exact allowed public method and every returned value to satisfy the final Plan 1a `ReportDefinitionBinding` contract; Task 11 unit fixtures exercise the same invoker, while production rejects a test double or alternate class.

| Contribution | Exact production invocation |
|---|---|
| Plan 2 | `App\BusinessModules\Core\Reporting\Application\Candidates\WaveOneCandidateBindingSet::bindings(): array<string,ReportDefinitionBinding>` |
| Plan 3 | `App\BusinessModules\Core\Reporting\Application\Candidates\Waves23CandidateBindingSet::build(CandidateReportDefinitionRegistry $candidateRegistry): array<string,ReportDefinitionBinding>` |

The strict bundle schema is Draft 2020-12 and uses `additionalProperties:false` on every object plus `unevaluatedProperties:false` around `$ref`/`oneOf`. Required root members are exactly:

```text
artifact_id, schema_version, status, release_sha, generated_at,
source_artifacts, candidate_manifest, candidate_payloads, validation_items,
bindings, conformance_records, plan_evidence_documents, counts, section_hashes
```

All Git identities use `^[0-9a-f]{40}$`, all content/schema/section hashes use `^[0-9a-f]{64}$`, and timestamps are canonical UTC RFC3339 seconds. The builder rejects caller time before any child evidence time or a future child timestamp.

`counts` is exactly:

```json
{
  "plan_2_candidates": 12,
  "plan_3_candidates": 16,
  "candidate_payloads": 28,
  "validation_items": 28,
  "passed_validations": 28,
  "bindings": 28,
  "conformance_records": 28
}
```

The exact ordered code union is Wave 1 from Plan 2, then Wave 2 `10`, then Wave 3 `6`; the schema and semantic validator require `uniqueItems`, while PHP additionally proves code-set equality across all five sections. `candidate_manifest` contains strict UTF-8/LF bytes plus their SHA-256. Every `candidate_payloads` item contains the full canonical Plan 1a payload and its definition hash. Every validation item contains only `code`, `definition_hash`, `passed:true`, `failure_codes:[]`. Every binding serializes the exact seven constructor fields `code`, `definitionHash`, `contractVersion`, `dataProvider`, `rowQuery`, `drillDownProvider`, `readinessProbe`; each object component is `{class,file_sha256}` and the nullable readiness probe is either that exact object or `null`. Every conformance item contains `code`, `definitionHash`, `fixtureHash`, bounded repository-relative `path`, reread `sha256`, full strict `record` and `conformance_digest`. `section_hashes` contains lowercase SHA-256 for the canonical manifest, candidate payload, validation, binding, conformance and plan-evidence sections.

This bundle is candidate-only. Both schema and semantic walk reject root/member names or values representing a `PublishedReportDefinition`, published registry/map, publication lock/ledger, active-manifest path, publication operation, `publication:published`, `catalog_activated` or `release_passed`. The only allowed publication readiness in all `28` payloads is `candidate`; source/formula/delivery are exactly `ready/ready/verified`. Thus the producer proves the exact `12+16=28` union, `28` payloads, `28/28` passed validations, `28` unique seven-field bindings and `28` actual conformance records before activation can run.

**Freeze SHA and no-self-reference rule:**

`release_sha` has one meaning throughout activation, admin-evidence transfer and release: the exact pre-activation backend freeze commit already present at `HEAD` after every tracked Plan 2 Task 9 and Plan 3 Task 17 implementation commit. It is never an arbitrary environment value. Before materializing child evidence, the orchestrator requires a clean tracked worktree, captures `BACKEND_RELEASE_SHA="$(git rev-parse HEAD)"`, and every producer verifies `--release-sha` equals current `HEAD`, resolves to a commit and includes all of its tracked producer/schema/input paths. The authoritative child/root SDD ledgers must each contain one identical `Plan 3 tracked-code freeze commit: <40hex>` line equal to that HEAD, whose exact subject is `test[reports]: закрыта готовность candidate-отчётов Wave 2 и Wave 3`. Plan 2's separate `plan_commit_sha` is the single identical child/root `Plan 2 Task 9 owner commit: <40hex>` value, has subject `test[reports]: доказана готовность первой волны`, and must be an ancestor of the freeze commit. Both Plan 3 artifacts carry the same freeze `release_sha` and producer commit. Plan 2 Task 9 is re-entered on this freeze HEAD with `--plan-commit-sha` and `--release-sha` to rematerialize its ignored artifact and its conformance records use `commitSha=$BACKEND_RELEASE_SHA`; then Plan 3 Tasks 16 and 17 are re-entered on the same HEAD. Evidence generated before the final Plan 3 tracked commit is rejected.

The fixed ledger authorities are `.superpowers/sdd/2026-07-26-reports-plan-2-wave-1/progress.md`, `.superpowers/sdd/2026-07-26-reports-plan-3-waves-2-3/progress.md` and `.superpowers/sdd/2026-07-26-reports-canonical/progress.md`. The builder accepts no ledger path flag and rejects absent, duplicate or disagreeing owner/freeze labels.

Activation writes a later, different `ACTIVATION_COMMIT_SHA`. The activation artifact and tracked publication ledger contain `release_sha=$BACKEND_RELEASE_SHA` but never try to contain their own future commit SHA. Only an ignored outer `ReportingArtifactTransfer` created after the activation commit may record `activation_commit_sha`. Likewise the tracked Plan 4 admin evidence must not embed its own containing commit SHA; its ignored outer transfer descriptor records that SHA after the admin-evidence commit. The existing `ReportQualityGateEvidence::commitSha` always means a pre-existing producer/execution commit verified before evidence serialization, never the future commit that first contains a tracked evidence file. Release evidence is ignored/untracked and may reference both pre-existing commits. No committed evidence file embeds the SHA of the commit that first contains that same file, so no fixed-point/self-reference is required.

**Exact controlled activation re-entry, only after Plan 3:**

Task 11 implementation is committed during Plan 1c foundation, but this subsection is resumed only after all tracked Plan 3 tasks are committed and the real three artifacts above have been rematerialized on the freeze HEAD. Every executable fence below is one self-contained atomic block: it canonicalizes every repository root it consumes, derives every lifecycle SHA again from the named validated artifact/ledger or current clean `HEAD`, captures each UTC RFC3339-seconds timestamp exactly once, and reuses that byte-identical value for its `--check` and normal invocation. No variable or shell state is inherited across Markdown fences or tool invocations. The exact shell precondition is:

```bash
test -z "$(git status --porcelain --untracked-files=no)"
BACKEND_RELEASE_SHA="$(git rev-parse HEAD)"
test "$(git rev-parse --verify HEAD^{commit})" = "$BACKEND_RELEASE_SHA"
PLAN2_OWNER_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["plan_commit_sha"];' build/reports/plan-2-wave-1-evidence.json)"
test "$(git rev-parse --verify "${PLAN2_OWNER_COMMIT_SHA}^{commit}")" = "$PLAN2_OWNER_COMMIT_SHA"
git merge-base --is-ancestor "$PLAN2_OWNER_COMMIT_SHA" "$BACKEND_RELEASE_SHA"
test "$BACKEND_RELEASE_SHA" != "$PLAN2_OWNER_COMMIT_SHA"
```

`PLAN2_OWNER_COMMIT_SHA` is read from the Plan 2 artifact only for the shell assertion; it is not caller-selected. `build-report-catalog-activation-inputs.php` independently schema-validates that artifact and cross-checks both commit values against the unique child/root ledger authority, exact subjects/ancestry, `HEAD==$BACKEND_RELEASE_SHA` and all three child `release_sha` bindings. The following single block captures `ACTIVATION_INPUTS_GENERATED_AT` once, rejects a non-canonical/future timestamp or one earlier than any child evidence timestamp, runs non-writing mode and then runs the byte-identical argument vector with only `--check` removed:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
test -z "$(git -C "$BACKEND_REPOSITORY_ROOT" status --porcelain --untracked-files=no)"
BACKEND_RELEASE_SHA="$(git -C "$BACKEND_REPOSITORY_ROOT" rev-parse HEAD)"
ACTIVATION_INPUTS_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/build-report-catalog-activation-inputs.php \
  --plan-2=build/reports/plan-2-wave-1-evidence.json \
  --plan-3-candidate=build/reports/waves-2-3-candidate-contribution.json \
  --plan-3-evidence=build/reports/plan-3-waves-2-3-evidence.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --generated-at="$ACTIVATION_INPUTS_GENERATED_AT" \
  --output=build/reports/report-catalog-activation-inputs.json \
  --check

php scripts/reporting/build-report-catalog-activation-inputs.php \
  --plan-2=build/reports/plan-2-wave-1-evidence.json \
  --plan-3-candidate=build/reports/waves-2-3-candidate-contribution.json \
  --plan-3-evidence=build/reports/plan-3-waves-2-3-evidence.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --generated-at="$ACTIVATION_INPUTS_GENERATED_AT" \
  --output=build/reports/report-catalog-activation-inputs.json
```

Expected: both invocations exit `0` and stdout is exactly `report-catalog-activation-inputs: activation_inputs_passed 12+16=28 sha256=<64 lowercase hex>` with the same digest; `--check` neither creates nor replaces the output and leaves the active manifest/ledger byte-identical. The normal invocation has the exact same serialized arguments and timestamp except for the absent `--check`; the exact output exists, the producer has reread it, validated the strict schema/status/counts/set equality, recomputed its SHA-256 and printed the same digest. Verify persistence:

```bash
test -f build/reports/report-catalog-activation-inputs.json
git check-ignore -q build/reports/report-catalog-activation-inputs.json
! git ls-files --error-unmatch build/reports/report-catalog-activation-inputs.json
```

`.gitignore` includes the exact activation-input, activation-output, release-gate and release-output paths. The production activation mode accepts `--inputs` and rejects every granular `--candidate|--validation|--bindings|--conformance|--plan-2|--plan-3` option. The old granular fixture mode is accepted only with `--check` and only when every input/output resolves under `tests/Fixtures/Reporting/Activation` or `tests/Fixtures/Reporting/Quality`.

```gitignore
build/reports/report-catalog-activation-inputs.json
build/reports/report-catalog-activation.json
build/reports/report-release-gate-bundle.json
build/reports/report-release-evidence.json
build/reports/report-cleanup-evidence.json
```

Snapshot the tracked active bytes, then use one atomic block. It rederives `F0`, rereads the canonical activation-input timestamp, captures `CATALOG_ACTIVATED_AT` once, and requires canonical/nonfuture `CATALOG_ACTIVATED_AT>=activation_inputs.generated_at`; the service independently enforces the same ordering. The check and normal argument bytes differ only by `--check`:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(git -C "$BACKEND_REPOSITORY_ROOT" rev-parse HEAD)"
ACTIVATION_INPUTS_GENERATED_AT="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["generated_at"];' build/reports/report-catalog-activation-inputs.json)"
CATALOG_ACTIVATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/activate-report-catalog.php \
  --current=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --inputs=build/reports/report-catalog-activation-inputs.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activated-at="$CATALOG_ACTIVATED_AT" \
  --output=build/reports/report-catalog-activation.json \
  --check

php scripts/reporting/activate-report-catalog.php \
  --current=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --inputs=build/reports/report-catalog-activation-inputs.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activated-at="$CATALOG_ACTIVATED_AT" \
  --output=build/reports/report-catalog-activation.json
```

Expected: both invocations exit `0` and stdout is exactly `report-catalog-activation: catalog_activated 28/28 sha256=<64 lowercase hex>` with the same digest; check mode leaves output, manifest and ledger byte-identical to their pre-check state, and normal mode is the exact same argument vector with only `--check` removed.

Normal mode acquires the reporting publication lock, stages both tracked files in one bounded sibling transaction directory, validates all `28` intermediate promotions, rereads the final staged manifest through schema + semantic loader, rereads the staged ledger through `report-publication-ledger.schema.json`, and proves all `28` lock/conformance/definition hashes. It then replaces the active manifest and ledger within the same lock/recovery transaction. Every reader of these resources takes the matching shared lock; an injected failure before, between or after replacement restores both previous byte sequences before releasing the lock. The activation artifact is written atomically only after both final active files have been reread successfully. Its status is exactly `catalog_activated`; no partial `1..27` ledger or manifest is observable through the reporting loader.

The exact tracked active ledger path is `app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json`. Its foundation version is committed by Task 11. Activation rewrites it to exactly `28` canonical publication events/locks keyed by the ordered code set and `release_sha=$BACKEND_RELEASE_SHA`; no separate untracked lock file is authoritative. The exact ignored output is `build/reports/report-catalog-activation.json`. Verify:

```bash
test -f build/reports/report-catalog-activation.json
git check-ignore -q build/reports/report-catalog-activation.json
! git ls-files --error-unmatch build/reports/report-catalog-activation.json
```

The normal command itself rereads and validates the activation schema/status, compares all manifest/ledger-derived hashes and prints the SHA-256 of the reread output. At this boundary `git diff --name-only` must contain exactly the following two lines and no other tracked path:

```text
app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml
app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json
```

Commit only that atomic active pair:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-catalog-activation.json")"
git add -- \
  app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json
git diff --cached --name-only --diff-filter=ACMR
git commit -m "feat[reports]: активирован каталог из 28 отчётов"
ACTIVATION_COMMIT_SHA="$(git rev-parse HEAD)"
test "$ACTIVATION_COMMIT_SHA" != "$BACKEND_RELEASE_SHA"
git diff --quiet "$ACTIVATION_COMMIT_SHA" -- \
  app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json
! git grep -F "$ACTIVATION_COMMIT_SHA" "$ACTIVATION_COMMIT_SHA" -- \
  app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json
```

Expected staged-name output before commit is exactly the two paths above; after commit, the working bytes equal `ACTIVATION_COMMIT_SHA:path`, both pass their tracked schemas/loaders, `publishedManifestHash` matches the manifest blob, all 28 activation lock hashes match the ledger events, the outer transfer records the reread ledger-blob SHA-256, and neither committed file embeds `ACTIVATION_COMMIT_SHA`.

**Exact activation handoff to Plan 4:**

`ReportingArtifactTransferService` has three closed modes, `activation`, `admin-evidence` and `release`; source/destination roots vary only by the mode-specific verified worktrees, while every source and destination relative path is fixed and caller-provided alternate filenames are rejected. For activation the fixed Plan 4 destinations are:

```text
build/reports/intake/report-catalog-activation.json
build/reports/intake/contracts/report-catalog-activation.schema.json
build/reports/intake/contracts/reporting-artifact-transfer.schema.json
build/reports/intake/report-catalog-activation.transfer.json
```

```php
final class ReportingArtifactTransferService
{
    public function transfer(
        string $kind,
        string $sourceRoot,
        string $sourcePath,
        string $schemaPath,
        string $sourceCommitSha,
        string $releaseSha,
        string $activationCommitSha,
        ?ReportingArtifactTransfer $adminTransfer,
        string $destinationRoot,
        DateTimeImmutable $generatedAt,
        bool $check,
    ): ReportingArtifactTransfer;
}
```

The constructor of `ReportingArtifactTransfer` contains only the closed schema members: artifact ID, `activation|admin-evidence|release` kind, `artifact_transferred` status, fixed source/destination/artifact-schema/transfer-schema paths, their reread hashes, release/source/activation/admin commit refs, committed-file hashes and generation time. Artifact ID is not caller-selected: the DTO/factory closes the mode map to `activation→report_catalog_activation_transfer`, `admin-evidence→plan4_admin_evidence_transfer`, `release→report_release_evidence_transfer`. It rejects an admin-transfer input in activation/admin-evidence modes and requires the already validated `plan4_admin_evidence_transfer` in release mode.

Every transfer CLI requires one explicit `--generated-at` captured once by its containing atomic shell block. The service requires canonical UTC RFC3339 seconds, rejects future time, and requires it to be no earlier than the transferred artifact, schema-bound evidence and prerequisite transfer timestamps. The service exposes the normalized command-argument record to tests; for each mode the check/normal records must be byte-identical after removing the sole `--check` token.

The transfer descriptor validates against `reporting-artifact-transfer.schema.json`, has `artifact_id=report_catalog_activation_transfer`, status `artifact_transferred`, source/destination byte SHA-256, artifact-schema SHA-256, transfer-schema SHA-256, `release_sha=$BACKEND_RELEASE_SHA`, external `activation_commit_sha=$ACTIVATION_COMMIT_SHA`, and commit-blob hashes for the active manifest and ledger. It is ignored/untracked. Run `--check` first and then the identical normal command:

`reporting-artifact-transfer.schema.json` is also strict Draft 2020-12 with recursive `additionalProperties:false`/`unevaluatedProperties:false`, closed `kind=activation|admin-evidence|release` branches, exact branch-local `artifact_id` constants, fixed relative sources/destinations and lowercase Git/SHA patterns. The activation branch requires `artifact_id=report_catalog_activation_transfer`, `source_commit_sha=activation_commit_sha` and forbids `admin_evidence_commit_sha`; the admin-evidence branch requires `artifact_id=plan4_admin_evidence_transfer`, `source_commit_sha=admin_evidence_commit_sha`, requires SHA inequality with `activation_commit_sha`, and forbids an embedded descriptor hash; the release branch requires `artifact_id=report_release_evidence_transfer`, `source_commit_sha=activation_commit_sha` plus the verified admin-transfer hash/commit. Cross-repository ancestry is not a schema invariant and is never inferred from hash fields.

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-catalog-activation.json")"
ACTIVATION_COMMIT_SHA="$(git -C "$BACKEND_REPOSITORY_ROOT" rev-parse HEAD)"
ACTIVATION_TRANSFER_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=activation \
  --source-root="$BACKEND_REPOSITORY_ROOT" \
  --source=build/reports/report-catalog-activation.json \
  --schema=docs/reports/contracts/report-catalog-activation.schema.json \
  --source-commit="$ACTIVATION_COMMIT_SHA" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --destination-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$ACTIVATION_TRANSFER_GENERATED_AT" \
  --check

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=activation \
  --source-root="$BACKEND_REPOSITORY_ROOT" \
  --source=build/reports/report-catalog-activation.json \
  --schema=docs/reports/contracts/report-catalog-activation.schema.json \
  --source-commit="$ACTIVATION_COMMIT_SHA" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --destination-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$ACTIVATION_TRANSFER_GENERATED_AT"
```

The script canonicalizes `PLAN4_REPOSITORY_ROOT`, requires it to be the root of the expected Plan 4 Git worktree, compares source working bytes to their reread digest, validates the source schema/status, verifies the active manifest and ledger against `ACTIVATION_COMMIT_SHA:path`, copies artifact plus both schemas byte-for-byte with atomic renames, rereads destination bytes and writes the outer descriptor last. Expected stdout is exactly `reporting-artifact-transfer: report_catalog_activation artifact_transferred sha256=<64 lowercase hex>`. Plan 4 must require byte equality for all copies, validate the transfer schema, require the two distinct SHAs, and prove all four intake files are ignored/untracked before Tasks 1–17 start.

```bash
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
cmp -s build/reports/report-catalog-activation.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/report-catalog-activation.json"
cmp -s docs/reports/contracts/report-catalog-activation.schema.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/contracts/report-catalog-activation.schema.json"
cmp -s docs/reports/contracts/reporting-artifact-transfer.schema.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/contracts/reporting-artifact-transfer.schema.json"
for path in \
  build/reports/intake/report-catalog-activation.json \
  build/reports/intake/contracts/report-catalog-activation.schema.json \
  build/reports/intake/contracts/reporting-artifact-transfer.schema.json \
  build/reports/intake/report-catalog-activation.transfer.json; do
  git -C "$PLAN4_REPOSITORY_ROOT" check-ignore -q -- "$path"
  ! git -C "$PLAN4_REPOSITORY_ROOT" ls-files --error-unmatch -- "$path"
done
```

Expected: all three `cmp` commands and every `check-ignore` succeed; `ls-files --error-unmatch` fails because all four handoff files remain untracked.

**Unified 14-gate release bundle and Plan 4 admin-evidence contract:**

Plan 4 Task 18 has an admin-evidence phase before release consumption. It must produce and commit:

| Field | Exact value |
|---|---|
| `artifact_id` | `plan4_admin_qg10_qg14_evidence` |
| tracked path in Plan 4 | `docs/reports/admin-evidence.json` |
| tracked schema in Plan 4 | `docs/reports/contracts/report-admin-evidence.schema.json` |
| producer in Plan 4 | `scripts/build-reporting-admin-evidence.mjs` |
| status | `admin_evidence_passed` |
| owned gates | exact `QG-10..QG-14`, all `passed` |

That tracked JSON contains `release_sha=$BACKEND_RELEASE_SHA` and activation-artifact/hash refs, but no field for its own containing commit SHA. After the admin-evidence commit, Plan 4 transfers its reread bytes and schema to the backend as:

The Plan 4 schema and `plan-4-admin-evidence.valid.json` fixture require one recursively closed `activation_artifact_ref` object with exactly these members, in this canonical order:

```text
artifact_path, artifact_sha256, artifact_schema_path, artifact_schema_sha256,
transfer_schema_path, transfer_schema_sha256, transfer_descriptor_path,
transfer_descriptor_sha256, artifact_status, transfer_status, release_sha,
activation_commit_sha, ordered_report_codes, published_code_count,
binding_code_count, publication_lock_hash_count, conformance_hash_count
```

The four paths are constants `build/reports/intake/report-catalog-activation.json`, `build/reports/intake/contracts/report-catalog-activation.schema.json`, `build/reports/intake/contracts/reporting-artifact-transfer.schema.json`, and `build/reports/intake/report-catalog-activation.transfer.json`. Their four hashes are lowercase SHA-256 over the exact reread raw bytes in the Plan 4 worktree. Status constants are `catalog_activated` and `artifact_transferred`; `release_sha=F0`, `activation_commit_sha=A1`; `ordered_report_codes` is the exact canonical 28-code order; all four counts are exactly `28`. Plan 4's producer derives the object from those intake bytes and accepts no caller-supplied reference, hash, status, SHA, code list or count.

The QG-14 contract is one indivisible two-repository command, shared byte-for-byte by Plan 4, the backend transfer verifier and the 14-gate release builder. Plan 4 records exactly seven command records overall. QG-14 owns exactly one command ID `qg14_forbidden_symbols` and its one exact argv array, in this order, with no `--section` option and no caller-derived root:

```json
["node","scripts/verify-reporting-cutover.mjs","--admin-root=C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin","--backend-root=C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/backend"]
```

That single invocation executes two fixed scan sections in exact order `admin`, then `backend`, against the two canonical roots above. Its strict result, Plan 4 admin evidence, `plan-4-admin-evidence.valid.json`, `plan-4-admin-evidence-transfer.valid.json` and the backend release-gate fixture carry these six exact semantic fields:

```text
admin_forbidden_symbol_matches
backend_forbidden_symbol_matches
combined_forbidden_symbol_matches
qg14_admin_sha256
qg14_backend_sha256
qg14_combined_sha256
```

All three counts are exactly `0`, and `combined_forbidden_symbol_matches === admin_forbidden_symbol_matches + backend_forbidden_symbol_matches`. Each canonical side section contains exactly its root kind, the fixed Task 17 inventory hash, sorted root-relative matches and count; the tracked Plan 4 Task 17 scanner/inventory contract is the single token-set authority and Plan 1c does not maintain a divergent duplicate list. `qg14_admin_sha256` is recomputed from the canonical raw admin scan section, `qg14_backend_sha256` from the canonical raw backend scan section, and `qg14_combined_sha256` from the canonical ordered two-section output containing both exact sections and all three counts; none may be copied from a caller, a prior run or one side's digest. The two section hashes and combined hash are pairwise role-bound even if raw scan content happens to be equal. An aggregate-only count, one-root invocation, swapped root order, two QG-14 command IDs, an eighth command record, two separately executed scans or a combined value/hash not recomputed from the same one-command output is invalid.

In the Plan 4 gate record, `command_ids` is exactly `["qg14_forbidden_symbols"]`; the gate-results/admin-evidence schemas close the three named counts and three named hashes. In the Plan 1c `report_release_gate_bundle`, QG-14 `actual_count` is the closed three-count object above, `required_count` is the same three keys with zero values, and `evidence_hashes` is the exact ordered tuple `[qg14_admin_sha256,qg14_backend_sha256,qg14_combined_sha256]` obtained from the reread Plan 4 bytes. The release CLI reruns the fixed two-root scan through the typed source, byte-compares both canonical sections, recomputes all three hashes and the sum, and passes the verified DTO to the pure release builder, which requires exact equality with the transferred Plan 4 gate-results/admin-evidence records before `release_gates_passed`.

The process boundary is typed and cannot be replaced with an aggregate integer or free-form command:

```php
final readonly class JointQG14Evidence
{
    public function __construct(
        public int $adminForbiddenSymbolMatches,
        public int $backendForbiddenSymbolMatches,
        public int $combinedForbiddenSymbolMatches,
        public Sha256Hash $qg14AdminSha256,
        public Sha256Hash $qg14BackendSha256,
        public Sha256Hash $qg14CombinedSha256,
        public array $argv,
        public string $commandId,
    ) {
    }
}

interface JointQG14EvidenceSource
{
    public function execute(): JointQG14Evidence;
}
```

`FixedRootJointQG14EvidenceSource` owns the fixed admin working directory, exact argv and strict one-line two-section output parser. It rejects any exit error, extra stdout/stderr, reordered/unknown member, noncanonical root, non-zero count, sum mismatch or section/output digest mismatch before returning `JointQG14Evidence`. `ReportingArtifactTransferService` invokes it before accepting the admin-evidence transfer; `build-report-release-gate-bundle.php` invokes it again and supplies the resulting typed value to `ReportReleaseGateBundleBuilder`. The builder never launches a process and never accepts six scalar caller arguments.

```text
build/reports/intake/plan-4-admin-evidence.json
build/reports/intake/contracts/report-admin-evidence.schema.json
build/reports/intake/plan-4-admin-evidence.transfer.json
```

The ignored transfer descriptor has `artifact_id=plan4_admin_evidence_transfer`, status `artifact_transferred`, exact source/destination/schema hashes, `release_sha`, `activation_commit_sha`, and the external `admin_evidence_commit_sha`. The backend verifier reads the Plan 4 commit blob named by that descriptor and rejects a dirty, rewritten, schema-mismatched or self-referential admin artifact.

**Exact Plan 4 admin-evidence handoff to backend:**

This handoff is the third `ReportingArtifactTransferService` mode, not a manual copy and not an inferred master action. Its source root is the canonical Plan 4 Git worktree; its destination root is the canonical backend Git worktree. It has exactly these tracked source paths:

```text
docs/reports/admin-evidence.json
docs/reports/contracts/report-admin-evidence.schema.json
```

and exactly these ignored/untracked backend destinations:

```text
build/reports/intake/plan-4-admin-evidence.json
build/reports/intake/contracts/report-admin-evidence.schema.json
build/reports/intake/plan-4-admin-evidence.transfer.json
```

The mode accepts no source/destination filename override and rejects equal, reversed or non-Git roots. It requires the Plan 4 root to contain the tracked source/schema and fixed ledger `.superpowers/sdd/2026-07-26-reports-plan-4-admin-cutover/progress.md`; it requires the backend root to contain the activated manifest/ledger and canonical ledger `.superpowers/sdd/2026-07-26-reports-canonical/progress.md`. Both ledgers must contain exactly one identical line `Plan 4 admin evidence commit: <40 lowercase hex>`. `admin-evidence` mode rejects a caller-supplied `--source-commit`; the CLI derives `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA` only from those two reread ledger lines and passes that typed value to the service.

Before writing, the service proves:

1. both tracked worktrees are clean; Plan 4 `HEAD` equals the ledger-derived admin-evidence commit and backend `HEAD` equals `ACTIVATION_COMMIT_SHA`;
2. `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA` resolves to a commit only in the Plan 4 repository and its exact two blobs are byte-identical to the source working files; the JSON/schema paths are not symlinks and cannot escape the source root;
3. the admin evidence validates against its committed strict schema, has `artifact_id=plan4_admin_qg10_qg14_evidence`, status `admin_evidence_passed`, exact `release_sha=$BACKEND_RELEASE_SHA`, the exact closed `activation_artifact_ref` contract above and exactly five passed records `QG-10..QG-14`; the transfer verifier independently rereads all four Plan 4 activation intake files, recomputes their raw hashes, validates both schemas/descriptors/statuses, release/A1 binding, exact code order and all `28` counts, then byte-compares the resulting reference to the tracked admin evidence;
4. QG-10 has `typed_definitions=28`, `strict_parser_locks=1`, `msw_contract_locks=1`; QG-11 preserves an exact actual state count `>=252`; QG-12 has `a11y_breakpoint_cases=25`; QG-13 has exactly the three allowed typecheck/lint/format commands; QG-14 has the exact singleton command ID/argv, exactly seven total Plan 4 command records, all three named match counts equal to zero with combined equal to their sum, and all three named hashes recomputed from the same fixed two-root scan output;
5. `ACTIVATION_COMMIT_SHA` and `$BACKEND_RELEASE_SHA` resolve to commits only in the backend repository, backend `HEAD` equals the activation commit, the release commit is its strict ancestor, and the activation commit contains the backend active manifest/ledger blobs referenced by the reread/schema/hash-validated Plan 4 activation intake and transfer descriptor;
6. the admin evidence's `producer_commit_sha` resolves only in the Plan 4 repository, is a strict ancestor of and differs from `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA`; the tracked evidence/schema contain neither a field nor any value equal to their own containing admin-evidence commit SHA;
7. all evidence/schema/gate hashes and timestamps are recomputed from bytes; QG-10..QG-14 evidence is not in the future and is at most `86400` seconds old at transfer validation time.

The repositories are separate Git object graphs. The service never resolves `ACTIVATION_COMMIT_SHA` or `$BACKEND_RELEASE_SHA` in the Plan 4 repository, never resolves `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA` or its producer in the backend repository, and never performs an ancestry comparison between `ACTIVATION_COMMIT_SHA` and `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA`. Their relationship is proven by byte-locked activation intake/descriptor references plus exact SHA inequality, not by impossible cross-repository `merge-base`.

Use one self-contained atomic transfer block. It derives the two already-established lifecycle identities from validated activation artifacts, keeps the third commit internal to the transfer CLI, captures one canonical/nonfuture transfer timestamp no earlier than all five admin gate times, and uses byte-identical arguments except for `--check`:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-catalog-activation.json")"
ACTIVATION_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["activation_commit_sha"];' "$PLAN4_REPOSITORY_ROOT/build/reports/intake/report-catalog-activation.transfer.json")"
ADMIN_TRANSFER_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=admin-evidence \
  --source-root="$PLAN4_REPOSITORY_ROOT" \
  --source=docs/reports/admin-evidence.json \
  --schema=docs/reports/contracts/report-admin-evidence.schema.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --destination-root="$BACKEND_REPOSITORY_ROOT" \
  --generated-at="$ADMIN_TRANSFER_GENERATED_AT" \
  --check

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=admin-evidence \
  --source-root="$PLAN4_REPOSITORY_ROOT" \
  --source=docs/reports/admin-evidence.json \
  --schema=docs/reports/contracts/report-admin-evidence.schema.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --destination-root="$BACKEND_REPOSITORY_ROOT" \
  --generated-at="$ADMIN_TRANSFER_GENERATED_AT"
```

Expected: both invocations exit `0`; stdout is exactly `reporting-artifact-transfer: plan4_admin_evidence artifact_transferred sha256=<64 lowercase hex>` with the same digest; check mode leaves all three destinations absent or byte-identical and neither worktree changes. The CLI/service accepts no activation-reference option: it derives and verifies `activation_artifact_ref` solely from the four fixed Plan 4 intake bytes. Normal mode stages only the destination JSON/schema in one bounded backend intake directory, rereads them, requires byte equality with both Plan 4 commit blobs, revalidates the copied schema/status/QG-10..QG-14 plus activation-reference contract and recomputes all hashes. Only after both data files are final does it atomically write and reread the descriptor. That descriptor has `artifact_id=plan4_admin_evidence_transfer`, `kind=admin-evidence`, status `artifact_transferred`, fixed source/destination paths, source/destination/artifact-schema/transfer-schema hashes, `release_sha`, `activation_commit_sha`, external `admin_evidence_commit_sha`, Plan 4 source-blob hashes and no hash of its own bytes.

Verify the materialized handoff:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
cmp -s "$PLAN4_REPOSITORY_ROOT/docs/reports/admin-evidence.json" \
  "$BACKEND_REPOSITORY_ROOT/build/reports/intake/plan-4-admin-evidence.json"
cmp -s "$PLAN4_REPOSITORY_ROOT/docs/reports/contracts/report-admin-evidence.schema.json" \
  "$BACKEND_REPOSITORY_ROOT/build/reports/intake/contracts/report-admin-evidence.schema.json"
for path in \
  build/reports/intake/plan-4-admin-evidence.json \
  build/reports/intake/contracts/report-admin-evidence.schema.json \
  build/reports/intake/plan-4-admin-evidence.transfer.json; do
  git -C "$BACKEND_REPOSITORY_ROOT" check-ignore -q -- "$path"
  ! git -C "$BACKEND_REPOSITORY_ROOT" ls-files --error-unmatch -- "$path"
done
test -z "$(git -C "$PLAN4_REPOSITORY_ROOT" status --porcelain --untracked-files=no)"
test -z "$(git -C "$BACKEND_REPOSITORY_ROOT" status --porcelain --untracked-files=no)"
```

Expected: both byte comparisons and every `check-ignore` succeed; each `ls-files --error-unmatch` fails, both tracked worktrees remain clean, and the descriptor is written last with the ledger-derived external admin commit. Only this verified output may be consumed by the release-gate builder.

Only after that transfer, `ReportReleaseGateBundleBuilder` may run:

```php
final class ReportReleaseGateBundleBuilder
{
    public function build(
        ReportQualityEvidenceLedger $platformEvidence,
        ReportCatalogActivationInputBundle $activationInputs,
        ReportCatalogActivation $activation,
        array $backendEvidenceDocuments,
        array $adminEvidenceDocument,
        ReportingArtifactTransfer $adminTransfer,
        JointQG14Evidence $qg14Evidence,
        string $releaseSha,
        string $activationCommitSha,
        DateTimeImmutable $generatedAt,
    ): ReportReleaseGateBundle;
}

final readonly class ReportReleaseGateBundle
{
    public function __construct(
        public string $artifactId,
        public string $status,
        public string $releaseSha,
        public string $activationCommitSha,
        public string $adminEvidenceCommitSha,
        public array $sourceArtifacts,
        public array $gates,
        public array $counts,
        public array $sectionHashes,
        public DateTimeImmutable $generatedAt,
    ) {
    }
}
```

Its fixed inputs are the active manifest/ledger and the following closed 13-row primary-path mapping. `P1A/P1B/P1C` mean the respective schema-validated completion producer commits, `P2O` is the unique Plan 2 Task 9 owner commit, `F0` is the backend Plan 3 freeze/release commit, `A1` is the backend activation commit, `AP` is the Plan 4 admin-evidence producer commit and `AE` is its Plan 4 containing admin-evidence commit:

| Primary path | Exact `artifact_id` | Exact `kind` | Git repository | Exact commit authority |
|---|---|---|---|---|
| `build/reports/plan-1a-completion.json` | `plan-1a-completion` | `ancestor_evidence` | backend | embedded schema-validated producer `P1A`; `P1A` is an ancestor of `F0` |
| `build/reports/plan-1b-completion.json` | `plan-1b-completion` | `ancestor_evidence` | backend | embedded schema-validated producer `P1B`; `P1B` is an ancestor of `F0` |
| `build/reports/plan-1c-platform-completion.json` | `plan-1c-platform-completion` | `ancestor_evidence` | backend | embedded schema-validated producer `P1C`; `P1C` is an ancestor of `F0` |
| `build/reports/plan-2-wave-1-evidence.json` | `plan-2-wave-1-candidate-conformance` | `release_evidence` | backend | `producer_commit_sha=P2O`, `release_sha=F0`; `P2O` is an ancestor of `F0` |
| `build/reports/waves-2-3-candidate-contribution.json` | `plan3_waves23_candidate_contribution` | `release_evidence` | backend | `producer_commit_sha=release_sha=F0` |
| `build/reports/plan-3-waves-2-3-evidence.json` | `plan3_waves23_evidence` | `release_evidence` | backend | `producer_commit_sha=release_sha=F0` |
| `build/reports/report-catalog-activation-inputs.json` | `report_catalog_activation_inputs` | `release_evidence` | backend | `producer_commit_sha=release_sha=F0` |
| `build/reports/report-catalog-activation.json` | `report_catalog_activation` | `release_evidence` | backend | `producer_commit_sha=A1`, bound by the validated activation transfer plus active manifest/ledger blobs; child `release_sha=F0` |
| `build/reports/intake/plan-4-admin-evidence.json` | `plan4_admin_qg10_qg14_evidence` | `release_evidence` | Plan 4 | root `producer_commit_sha=AP`; `AP` is a strict ancestor of container `AE`; the paired transfer proves the committed `AE:path` blob |
| `build/reports/intake/contracts/report-admin-evidence.schema.json` | `plan4_admin_evidence_schema` | `tracked_file` | Plan 4 | `commit_sha=AE`, committed container via the paired transfer's source blob |
| `build/reports/intake/plan-4-admin-evidence.transfer.json` | `plan4_admin_evidence_transfer` | `transfer` | both, repository-local checks | Plan 4 `admin_evidence_commit_sha=AE`; backend `activation_commit_sha=A1`; no cross-repository ancestry |
| `app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml` | `report_management_catalog_active` | `tracked_file` | backend | `commit_sha=A1` |
| `app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json` | `report_publication_ledger_active` | `tracked_file` | backend | `commit_sha=A1` |

The builder dispatches item parsing and authority validation only by this exact primary-path table; it never infers `artifact_id`, `kind` or commit authority from an absent root field. Every row's `artifact_id` is a schema `const` in the path-selected `oneOf` branch, and the schema's closed enum is exactly the ordered 13 values above; no caller, filename or builder branch may derive or invent another ID. The first eight backend evidence paths are resolved in the backend repository. `P1A/P1B/P1C/P2O` must be ancestors of `F0`, `P2O` has the unique child/root owner-ledger identity, every F0-owned path equals its `F0:path` producer/input bytes, and `F0` is a strict ancestor of `A1`. The admin evidence/schema/transfer source blobs plus `AP→AE` ancestry are resolved only in Plan 4; `A1`, `F0` and the active blobs are resolved only in backend. The builder never attempts `A1↔AE` ancestry.

The output contract is `artifact_id=report_release_gate_bundle`, exact path `build/reports/report-release-gate-bundle.json`, schema `docs/reports/contracts/report-release-gate-bundle.schema.json`, producer `scripts/reporting/build-report-release-gate-bundle.php`, status `release_gates_passed`. Its Draft 2020-12 schema is recursively closed. Root members are exactly `artifact_id,schema_version,status,release_sha,activation_commit_sha,admin_evidence_commit_sha,generated_at,source_artifacts,gates,counts,section_hashes`; all three Git identities match `^[0-9a-f]{40}$` and `activation_commit_sha!=release_sha`.

`source_artifacts` has four recursively closed `oneOf` item shapes. A release-bound evidence item has exactly `artifact_id,kind,path,schema_path,status,bytes_sha256,schema_sha256,release_sha,producer_commit_sha,generated_at`; an ancestor-bound Plan 1a/1b/1c completion item has the same fields except `release_sha`, which is forbidden rather than synthesized; a transfer item has exactly `artifact_id,kind,path,schema_path,status,bytes_sha256,schema_sha256,release_sha,activation_commit_sha,admin_evidence_commit_sha,generated_at`; a tracked-file item has exactly `artifact_id,kind,path,bytes_sha256,commit_sha`. `kind` is `release_evidence|ancestor_evidence|transfer|tracked_file`.

The semantic builder requires unique `artifact_id` and unique primary `path`; it does not reject equal SHA-256 values globally. Intentional digest reuse across semantic roles is valid and sometimes mandatory. Whenever an item's `schema_path` equals the primary path of a `tracked_file` item, that item's `schema_sha256` must equal the tracked file's `bytes_sha256`. In particular the admin evidence item's schema hash must exactly equal the separate admin-schema tracked-file byte hash at `AE`; unequal values fail, while equality must not be treated as a duplicate. Every digest is still recomputed from reread bytes and linked to its declared role/path.

For the pre-existing Plan 1a/1b/1c completions, “match the release SHA” means their schema-validated producer commits are exact ancestors of `$BACKEND_RELEASE_SHA`, their tracked input blobs at those commits equal the hashes carried by the completions, and their reread artifact hashes are locked into this release bundle. The builder never adds a nonexistent `release_sha` field to those child bytes. Plan 2, both Plan 3 artifacts, activation inputs, activation, admin evidence and transfer refs are release-bound and must carry exact `release_sha=$BACKEND_RELEASE_SHA`; their commit authorities remain exactly those in the 13-row table and are never collapsed to the release SHA.

`gates` has exactly `14` unique, sorted records `QG-01..QG-14`. Each record has exactly `gate,owner,phase,status,command_ids,actual_count,required_count,executed_at,age_seconds,evidence_hashes,schema_hashes`; every phase is `release`, every status is `passed`, every command is from the tracked gate catalog, and every evidence/schema/artifact hash is lowercase SHA-256 over reread bytes. Gate-specific `actual_count`/`required_count` are closed objects selected by gate ID, not a caller-provided scalar.

Gate-specific semantic records are exact:

| Gate | Required exact/minimum metrics |
|---|---|
| QG-01 | `published=28`, `bindings=28`, `publication_locks=28`, `conformance_hashes=28`, exact seven groups |
| QG-02 | `golden_fixtures=56`, exactly two per code |
| QG-03 | `formula_families=20`: exact `12` Wave 1 family IDs from Plan 2 plus exact `8` existing Waves 2–3 family IDs from Plan 3; a closed family→report-codes map covers the exact 28-code union with every code assigned exactly once, every family has `seed_count>=500`, exact actual counts are preserved and `total_seeds>=10000` |
| QG-04 | `conformance_records=28`, `pipeline_parity_records=28` |
| QG-05 | `action_matrices=28`, `admin_redaction_suites=1` |
| QG-06 | backend-owned `malformed_cases=46`, exact four cross-surface schema/parser/translation/route hashes; admin parser evidence is supporting input only |
| QG-07 | `performance_records=28`; each `age_seconds` is an exact non-negative integer `<=86400` at release `generated_at` |
| QG-08 | `export_parity_records=28` |
| QG-09 | exact unique backend static command-ID set declared by the Plan 1a/1b/1c/2/3 schemas; `actual_count=expected_count`, all passed, every record age `<=86400` |
| QG-10 | `typed_definitions=28`, `strict_parser_locks=1`, `msw_contract_locks=1` |
| QG-11 | exact actual parametrized state count preserved and `>=252` |
| QG-12 | `a11y_breakpoint_cases=25` |
| QG-13 | exactly `3` commands: TypeScript no-emit typecheck, scoped ESLint, exact Prettier check |
| QG-14 | `admin_forbidden_symbol_matches=0`, `backend_forbidden_symbol_matches=0`, `combined_forbidden_symbol_matches=0`; combined is the exact sum; singleton `command_ids=["qg14_forbidden_symbols"]`; exact fixed two-root argv; `qg14_admin_sha256`, `qg14_backend_sha256`, `qg14_combined_sha256` are independently recomputed from the same ordered scan output |

QG-03 uses this closed ownership lock; family IDs and code arrays are immutable schema values:

| Family ID | Exact report-code ownership |
|---|---|
| `wave1.project_portfolio_health` | `project_portfolio_health` |
| `wave1.portfolio_liquidity` | `portfolio_liquidity` |
| `wave1.baseline_schedule_variance` | `baseline_schedule_variance` |
| `wave1.project_margin` | `project_margin` |
| `wave1.budget_plan_fact` | `budget_plan_fact` |
| `wave1.wip_completion_forecast` | `wip_completion_forecast` |
| `wave1.contract_settlement_exposure` | `contract_settlement_exposure` |
| `wave1.management_pnl` | `management_pnl` |
| `wave1.workforce_capacity` | `workforce_capacity` |
| `wave1.attendance_execution` | `attendance_execution` |
| `wave1.project_labor_cost` | `project_labor_cost` |
| `wave1.payroll_readiness` | `payroll_readiness` |
| `waves23.allocation_finance` | `holding_performance`, `intercompany_contract_flows`, `change_claim_contingency` |
| `waves23.evm` | `project_evm_control` |
| `waves23.process_cohort_sla` | `lookahead_readiness`, `procurement_cycle`, `supplier_award_competitiveness`, `quality_defect_flow`, `safety_incident_actions`, `customer_sla` |
| `waves23.procurement_quantity` | `supply_reliability` |
| `waves23.inventory_recurrence` | `inventory_risk` |
| `waves23.readiness_compliance` | `workforce_admission`, `handover_readiness` |
| `waves23.accepted_production` | `accepted_production_progress` |
| `waves23.component_scorecard` | `contractor_scorecard` |

The twelve Wave 1 rows above bind, in that exact order, to golden IDs `G01,G04,G06,G09,G10,G11,G12,G13,G19,G20,G21,G22`; the builder rejects any reordered or substituted golden ID even when family/code counts still equal twelve.

Plan 2's ordered `property_families` source item is exactly `{family_id,golden_id,report_code,path,sha256,record}` and its nested record is exactly `{family_id,golden_id,report_code,seed_count,seed_set_sha256,assertion_count,assertion_set_sha256,status,commit_sha,generated_at}`. It requires `12` items, `seed_count=500`, total `6000`, `status=passed`, `commit_sha=$BACKEND_RELEASE_SHA`, unique paths/hashes/IDs/codes and the reread `property_families_sha256`. Plan 3 supplies the eight existing family records, `500` seeds each and total `4000`; its family-to-code arrays normalize to the eight rows above and carry the same freeze SHA/status/hash/time invariants.

The builder requires exact family-ID equality with this table, exact code-array equality per family, exact one-owner coverage of the activation bundle's 28 codes, `seed_count>=500` for every row and the recomputed sum `>=10000` (`6000+4000` for the canonical artifacts). A report cannot satisfy QG-03 through two families, and an unowned report or empty family fails closed.

For QG-10..QG-14 every record age is also an exact non-negative integer `<=86400`. QG-01..QG-06 and QG-08 use immutable content hashes rather than an invented freshness window. All source `executed_at` values must be `<=generated_at`; future timestamps fail. `counts` repeats exact `source_artifacts=13` for the eleven listed evidence paths plus the active manifest and ledger, `gates=14`, `passed_gates=14`, and ownership `backend=9`, `admin=4`, `joint=1`; the sole joint-count gate is QG-14 with serialized owner `both`, while QG-06 remains backend-owned. `section_hashes` locks the canonical source-artifact and gate arrays. The builder requires every source status, code set, release SHA, activation SHA, admin commit ref, artifact hash and schema hash to agree; no caller-supplied count/hash can override a value recomputed from bytes.

**Exact release re-entry, only after Plan 4 Task 18 admin-evidence phase:**

Release re-entry reloads identities from already materialized, subsequently schema-validated artifacts; it never accepts a free-form SHA:

```bash
test -z "$(git status --porcelain --untracked-files=no)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' build/reports/report-catalog-activation.json)"
ACTIVATION_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["activation_commit_sha"];' build/reports/intake/plan-4-admin-evidence.transfer.json)"
PLAN4_ADMIN_EVIDENCE_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["admin_evidence_commit_sha"];' build/reports/intake/plan-4-admin-evidence.transfer.json)"
test "$(git rev-parse HEAD)" = "$ACTIVATION_COMMIT_SHA"
test "$BACKEND_RELEASE_SHA" != "$ACTIVATION_COMMIT_SHA"
git merge-base --is-ancestor "$BACKEND_RELEASE_SHA" "$ACTIVATION_COMMIT_SHA"
git diff --quiet "$ACTIVATION_COMMIT_SHA" -- \
  app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json
```

The release-gate CLI then strictly validates those files, compares all three extracted values to the artifact/transfer schemas, verifies the admin JSON/schema blobs at `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA:path` in the canonical Plan 4 worktree and rejects any mismatch. The shell extraction is not evidence and cannot override the reread values.

The release-gate producer runs in one self-contained atomic block. It canonicalizes both roots, derives `F0/A1` from the validated activation/admin-transfer bytes, captures `RELEASE_GENERATED_AT` exactly once, and rejects it unless it is canonical, nonfuture and not earlier than every source evidence/transfer/gate timestamp. Check and normal argument bytes differ only by `--check`:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-catalog-activation.json")"
ACTIVATION_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["activation_commit_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/intake/plan-4-admin-evidence.transfer.json")"
RELEASE_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/build-report-release-gate-bundle.php \
  --plan-1a=build/reports/plan-1a-completion.json \
  --plan-1b=build/reports/plan-1b-completion.json \
  --plan-1c=build/reports/plan-1c-platform-completion.json \
  --plan-2=build/reports/plan-2-wave-1-evidence.json \
  --plan-3-candidate=build/reports/waves-2-3-candidate-contribution.json \
  --plan-3=build/reports/plan-3-waves-2-3-evidence.json \
  --activation-inputs=build/reports/report-catalog-activation-inputs.json \
  --activation=build/reports/report-catalog-activation.json \
  --manifest=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --admin=build/reports/intake/plan-4-admin-evidence.json \
  --admin-schema=build/reports/intake/contracts/report-admin-evidence.schema.json \
  --admin-transfer=build/reports/intake/plan-4-admin-evidence.transfer.json \
  --plan-4-repository-root="$PLAN4_REPOSITORY_ROOT" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --generated-at="$RELEASE_GENERATED_AT" \
  --output=build/reports/report-release-gate-bundle.json \
  --check

php scripts/reporting/build-report-release-gate-bundle.php \
  --plan-1a=build/reports/plan-1a-completion.json \
  --plan-1b=build/reports/plan-1b-completion.json \
  --plan-1c=build/reports/plan-1c-platform-completion.json \
  --plan-2=build/reports/plan-2-wave-1-evidence.json \
  --plan-3-candidate=build/reports/waves-2-3-candidate-contribution.json \
  --plan-3=build/reports/plan-3-waves-2-3-evidence.json \
  --activation-inputs=build/reports/report-catalog-activation-inputs.json \
  --activation=build/reports/report-catalog-activation.json \
  --manifest=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --admin=build/reports/intake/plan-4-admin-evidence.json \
  --admin-schema=build/reports/intake/contracts/report-admin-evidence.schema.json \
  --admin-transfer=build/reports/intake/plan-4-admin-evidence.transfer.json \
  --plan-4-repository-root="$PLAN4_REPOSITORY_ROOT" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --generated-at="$RELEASE_GENERATED_AT" \
  --output=build/reports/report-release-gate-bundle.json
```

Expected: both invocations exit `0`; stdout is exactly `report-release-gate-bundle: release_gates_passed 14/14 sha256=<64 lowercase hex>` with the same digest. Check mode creates/replaces no output and changes no active/committed file. Normal mode has the identical argument bytes/timestamp except for absent `--check`; the exact ignored/untracked output exists, is reread and schema/status/count/age/hash-validated.

The release CLI then consumes that real bundle and reconstructs the published registry and immutable binding map only from the committed active manifest/ledger plus the already verified activation-input binding descriptors. It independently rereads the bundle, activation inputs/artifact and active commit blobs, repeats their schema/hash/SHA/set checks and does not trust shell variables or a bundle-declared count. Its single atomic block derives `F0/A1/generated_at` from the strict reread gate bundle and activation artifact; check and normal arguments differ only by `--check`:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-release-gate-bundle.json")"
ACTIVATION_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["activation_commit_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-release-gate-bundle.json")"
RELEASE_GENERATED_AT="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["generated_at"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-release-gate-bundle.json")"

php scripts/reporting/build-report-quality-evidence.php \
  --phase=release \
  --manifest=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --activation-inputs=build/reports/report-catalog-activation-inputs.json \
  --activation=build/reports/report-catalog-activation.json \
  --gates=build/reports/report-release-gate-bundle.json \
  --plan-1a=build/reports/plan-1a-completion.json \
  --plan-1b=build/reports/plan-1b-completion.json \
  --plan-1c=build/reports/plan-1c-platform-completion.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --generated-at="$RELEASE_GENERATED_AT" \
  --output=build/reports/report-release-evidence.json \
  --check

php scripts/reporting/build-report-quality-evidence.php \
  --phase=release \
  --manifest=app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml \
  --ledger=app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json \
  --activation-inputs=build/reports/report-catalog-activation-inputs.json \
  --activation=build/reports/report-catalog-activation.json \
  --gates=build/reports/report-release-gate-bundle.json \
  --plan-1a=build/reports/plan-1a-completion.json \
  --plan-1b=build/reports/plan-1b-completion.json \
  --plan-1c=build/reports/plan-1c-platform-completion.json \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --generated-at="$RELEASE_GENERATED_AT" \
  --output=build/reports/report-release-evidence.json
```

Expected: both invocations exit `0`; stdout is exactly `report-quality-evidence: release_passed sha256=<64 lowercase hex>` with the same digest; check mode creates/replaces no output and leaves active manifest/ledger hashes unchanged. Normal mode uses the same timestamp and identical arguments except for absent `--check`, atomically writes only `build/reports/report-release-evidence.json`, rereads it, validates `report-quality-evidence.schema.json`, requires status `release_passed`, exact `28/28`, exact `14/14`, recomputes SHA-256 and prints that reread digest. Its `prerequisiteEvidenceHashes` contains the exact reread hashes for `report_release_gate_bundle`, activation inputs, activation and the Plan 4 admin transfer, so the outer admin commit ref is transitively locked without changing the existing DTO constructor. It cannot call `ReportCatalogActivationService`, a publication service, a write-mode ledger API or mutate a tracked file.

Verify both release artifacts:

```bash
test -f build/reports/report-release-gate-bundle.json
test -f build/reports/report-release-evidence.json
git check-ignore -q build/reports/report-release-gate-bundle.json
git check-ignore -q build/reports/report-release-evidence.json
! git ls-files --error-unmatch build/reports/report-release-gate-bundle.json
! git ls-files --error-unmatch build/reports/report-release-evidence.json
test -z "$(git status --porcelain --untracked-files=no)"
```

Finally transfer the release bytes/schema back to the same Plan 4 worktree in one self-contained check/normal block. It canonicalizes both roots, derives `F0/A1` from strict reread release/admin-transfer artifacts, captures `RELEASE_TRANSFER_GENERATED_AT` once, and passes identical arguments except for `--check`:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
BACKEND_RELEASE_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["release_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/report-release-evidence.json")"
ACTIVATION_COMMIT_SHA="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d["activation_commit_sha"];' "$BACKEND_REPOSITORY_ROOT/build/reports/intake/plan-4-admin-evidence.transfer.json")"
RELEASE_TRANSFER_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=release \
  --source-root="$BACKEND_REPOSITORY_ROOT" \
  --source=build/reports/report-release-evidence.json \
  --schema=docs/reports/contracts/report-quality-evidence.schema.json \
  --source-commit="$ACTIVATION_COMMIT_SHA" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --admin-transfer=build/reports/intake/plan-4-admin-evidence.transfer.json \
  --destination-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$RELEASE_TRANSFER_GENERATED_AT" \
  --check

php scripts/reporting/transfer-reporting-artifact.php \
  --kind=release \
  --source-root="$BACKEND_REPOSITORY_ROOT" \
  --source=build/reports/report-release-evidence.json \
  --schema=docs/reports/contracts/report-quality-evidence.schema.json \
  --source-commit="$ACTIVATION_COMMIT_SHA" \
  --release-sha="$BACKEND_RELEASE_SHA" \
  --activation-commit="$ACTIVATION_COMMIT_SHA" \
  --admin-transfer=build/reports/intake/plan-4-admin-evidence.transfer.json \
  --destination-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$RELEASE_TRANSFER_GENERATED_AT"
```

Fixed Plan 4 destinations are `build/reports/intake/report-release-evidence.json`, `build/reports/intake/contracts/report-quality-evidence.schema.json`, `build/reports/intake/contracts/reporting-artifact-transfer.schema.json`, and `build/reports/intake/report-release-evidence.transfer.json`. The output descriptor's schema-locked ID is exactly `artifact_id=report_release_evidence_transfer`; this is intentionally distinct from the transferred source artifact ID used in stdout. Expected stdout is exactly `reporting-artifact-transfer: report_release_evidence artifact_transferred sha256=<64 lowercase hex>`. The service proves byte-identical artifact/schema copies, `release_passed`, both external commit refs and ignored/untracked destination state. Only then may Plan 4 resume the Task 18 consumer-finalization phase; release production performs no publication, no tracked commit and no cutover mutation.

```bash
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
cmp -s build/reports/report-release-evidence.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/report-release-evidence.json"
cmp -s docs/reports/contracts/report-quality-evidence.schema.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/contracts/report-quality-evidence.schema.json"
cmp -s docs/reports/contracts/reporting-artifact-transfer.schema.json \
  "$PLAN4_REPOSITORY_ROOT/build/reports/intake/contracts/reporting-artifact-transfer.schema.json"
for path in \
  build/reports/intake/report-release-evidence.json \
  build/reports/intake/contracts/report-quality-evidence.schema.json \
  build/reports/intake/contracts/reporting-artifact-transfer.schema.json \
  build/reports/intake/report-release-evidence.transfer.json; do
  git -C "$PLAN4_REPOSITORY_ROOT" check-ignore -q -- "$path"
  ! git -C "$PLAN4_REPOSITORY_ROOT" ls-files --error-unmatch -- "$path"
done
```

Expected: all three byte comparisons and every `check-ignore` succeed; all four release handoff files are untracked and Plan 4 consumes these exact bytes without rewriting status or hashes.

**Post-window cleanup evidence owned by backend Task 11:**

Task 11 foundation tracks `docs/reports/contracts/report-cleanup-evidence.schema.json`, `ReportCleanupEvidence`, `ReportCleanupEvidenceBuilder`, `scripts/reporting/build-report-cleanup-evidence.php`, the deterministic valid fixture and unit/integration tests before release. It does not materialize production cleanup evidence during Plans 1–4. The only production path is the exact ignored/untracked `build/reports/report-cleanup-evidence.json`, produced by backend alone only after Plan 4 Phase 18C has committed and both authoritative ledgers contain one identical `Plan 4 Task 18C cutover commit: <40 lowercase hex>` plus `Plan 4 Task 18 phase: 18C_cutover_reviewed`.

The strict Draft 2020-12 schema is recursively closed and has exact root members:

```text
artifact_id, schema_version, status, verification_mode, release_sha,
activation_commit_sha, cutover_commit_sha, producer_commit_sha, cutover_pair,
rollback_window_seconds, eligible_at, generated_at, checks, counts, section_hashes
```

Constants are `artifact_id=report_cleanup_evidence`, `schema_version=1.0.0`, `status=cleanup_verified`, `verification_mode=external_read_only`, `rollback_window_seconds=604800`. All commits are lowercase 40-hex; all raw/section hashes are lowercase 64-hex; timestamps are canonical UTC RFC3339 seconds. `cutover_pair` is recursively closed and contains exact Plan 4 repository/path `docs/reports/cutover-release-pair.json`, committed raw-byte SHA-256, validated status/`28/28`/`14/14`, static cleanup policy path/schema/window/mode, and its `release_sha`/`activation_commit_sha`. `cutover_commit_sha` comes only from the unique dual-ledger line and must be the Plan 4 commit whose blob at that path equals the reread working bytes. `producer_commit_sha` is the clean backend `HEAD` whose tracked legacy cleanup and scan inputs are reread as commit blobs; the ignored output never embeds a future containing commit.

The exact six ordered check IDs are `cleanup.cutover_pair`, `cleanup.rollback_window`, `cleanup.legacy_route_aliases`, `cleanup.legacy_direct_callers`, `cleanup.qg14_forbidden_symbols`, `cleanup.policy_lock`. Each strict item contains `check_id,status=passed,repository,command_id,paths,actual_count,required_count,executed_at,evidence_sha256`; the QG-14 item closes `actual_count`/`required_count` over the exact admin/backend/combined keys and also carries the exact three role-bound QG-14 hashes from the one fixed two-root command. The three scan checks require zero matches; the policy check proves exact backend owner/schema/evidence paths, `604800` and `external_read_only`; the cutover check proves exact `28/28` and `14/14`. `counts` is exactly `checks=6,passed_checks=6,legacy_route_matches=0,direct_caller_matches=0,admin_forbidden_symbol_matches=0,backend_forbidden_symbol_matches=0,combined_forbidden_symbol_matches=0`, with the combined count recomputed as the exact sum. `section_hashes` locks canonical cutover-ref/check arrays plus `qg14_admin_sha256`, `qg14_backend_sha256` and `qg14_combined_sha256` recomputed from the same ordered two-section output.

The builder performs only read-only filesystem/Git/schema/route-snapshot/repository scans. It opens no DB connection, browser, server, network or runtime route, runs no build/migration and mutates no product/tracked file. It canonicalizes the fixed backend/admin worktrees, validates the two exact ledger identities, resolves repository-local commits only, rereads Plan 4's committed cutover pair and static cleanup policy, and computes `eligible_at = cutover commit committer time in UTC + 604800 seconds`. It rejects until `generated_at>=eligible_at`, rejects future/noncanonical time, and reruns QG-14 plus the exact 16 legacy alias and 84-entry direct-caller/inventory static scans. A caller cannot supply a cutover pair/hash/ref, ledger path, count, result or commit override.

The post-window atomic block is self-contained; check/normal arguments differ only by `--check` and reuse one captured timestamp:

```bash
BACKEND_REPOSITORY_ROOT="$(git rev-parse --show-toplevel)"
PLAN4_REPOSITORY_ROOT="$(git -C 'C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin' rev-parse --show-toplevel)"
CLEANUP_GENERATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

php scripts/reporting/build-report-cleanup-evidence.php \
  --backend-root="$BACKEND_REPOSITORY_ROOT" \
  --plan-4-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$CLEANUP_GENERATED_AT" \
  --output=build/reports/report-cleanup-evidence.json \
  --check

php scripts/reporting/build-report-cleanup-evidence.php \
  --backend-root="$BACKEND_REPOSITORY_ROOT" \
  --plan-4-root="$PLAN4_REPOSITORY_ROOT" \
  --generated-at="$CLEANUP_GENERATED_AT" \
  --output=build/reports/report-cleanup-evidence.json

test -f build/reports/report-cleanup-evidence.json
git check-ignore -q build/reports/report-cleanup-evidence.json
! git ls-files --error-unmatch build/reports/report-cleanup-evidence.json
```

Expected after the window: check mode writes nothing; both invocations print `report-cleanup-evidence: cleanup_verified 6/6 sha256=<64 lowercase hex>` with the same digest, normal mode atomically writes/rereads/schema-validates only the ignored output, and the tracked tree remains clean. Before the window both modes fail with `REPORT_CLEANUP_WINDOW_NOT_ELAPSED` and write nothing.

After reread verification, the orchestrator appends or verifies exactly one line only in `.superpowers/sdd/2026-07-26-reports-canonical/progress.md`: `Report cleanup evidence: cutover=<40hex> producer=<40hex> generated_at=<UTC-RFC3339-seconds> status=cleanup_verified sha256=<64hex> path=build/reports/report-cleanup-evidence.json`. This future external ref never enters Plan 4 `release-ledger.json`, `traceability.json`, `cutover-release-pair.json`, any activation/release artifact or admin evidence. Plan 4's optional post-window consumer is read-only and compares this root-ledger ref to its already tracked static policy.

`ReportCleanupEvidenceBuilderTest` and `ReportCleanupEvidenceReentryTest` cover exact deterministic bytes; check/normal argument parity; `604799` seconds versus exactly `604800`; missing/duplicate/disagreeing cutover ledger lines or wrong phase; wrong Plan 4 commit/blob/pair hash; altered policy path/schema/window/mode; wrong release/A1/28/28/14/14; dirty roots; cross-repository ancestry; a caller ref/hash/count/commit; any of the 16 legacy route aliases; any direct caller/QG-14 match; 83/85 inventory rows; missing/extra/reordered check; early/future/noncanonical timestamp; `--check` mutation; tracked output; and hash/schema/reread mismatch. Every negative fails before output or ledger mutation.

Foundation RED/GREEN: `vendor/bin/phpunit tests/Architecture/Reporting/ReportCleanupEvidenceSchemaTest.php tests/Unit/Reporting/Evidence/ReportCleanupEvidenceBuilderTest.php tests/Integration/Reporting/ReportCleanupEvidenceReentryTest.php`. Run scoped static analysis: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/ReportCleanupEvidence.php app/BusinessModules/Core/Reporting/Application/Evidence/ReportCleanupEvidenceBuilder.php scripts/reporting/build-report-cleanup-evidence.php --no-progress`. Before the existing Task 11 foundation commit, stage the complete owned set with `git add -- docs/reports/contracts/report-cleanup-evidence.schema.json app/BusinessModules/Core/Reporting/Domain/DTO/ReportCleanupEvidence.php app/BusinessModules/Core/Reporting/Application/Evidence/ReportCleanupEvidenceBuilder.php scripts/reporting/build-report-cleanup-evidence.php tests/Fixtures/Reporting/Quality/report-cleanup-evidence.valid.json tests/Architecture/Reporting/ReportCleanupEvidenceSchemaTest.php tests/Unit/Reporting/Evidence/ReportCleanupEvidenceBuilderTest.php tests/Integration/Reporting/ReportCleanupEvidenceReentryTest.php .gitignore`; the future ignored JSON and root-ledger ref are never staged in this foundation commit.

- [ ] **RED:** add platform/release phase tests; missing/pending/skipped/stale/hash/count failures; offline HTTP isolation; activation refuses 27/28, extra binding, candidate/published contamination and partial writes.

```php
public function test_platform_evidence_cannot_be_misrepresented_as_release(): void
{
    $ledger = $this->builder->buildPlatform(...$this->validPlatformArguments());

    self::assertSame('platform_passed', $ledger->status);
    self::assertNotSame('release_passed', $ledger->status);
}

public function test_quality_exception_is_offline_only(): void
{
    self::assertFalse(is_subclass_of(
        ReportQualityGateException::class,
        ReportContractException::class,
    ));
    self::assertSame(
        2,
        (new ReportQualityGateException(
            ReportQualityGateFailureCode::MISSING,
        ))->exitCode(),
    );
}

public function test_platform_gate_fixture_is_derived_from_catalog_and_real_sources(): void
{
    $actual = file_get_contents(
        base_path('tests/Fixtures/Reporting/Quality/platform-gates.valid.json'),
    );
    $expected = $this->platformGateFixtureBuilder->build(
        releaseSha: str_repeat('1', 40),
        generatedAt: new DateTimeImmutable('2026-07-26T00:00:00Z'),
    );

    self::assertSame($expected, $actual);
}
```

The re-entry RED set is separate from the legacy phase subset and must include:

```php
public function test_real_child_artifacts_form_one_exact_candidate_only_28_bundle(): void
{
    $bundle = $this->builder->build(
        self::BACKEND_FREEZE_SHA,
        new DateTimeImmutable('2026-07-26T00:00:00Z'),
    );

    self::assertSame('activation_inputs_passed', $bundle->status);
    self::assertSame(12, $bundle->counts['plan_2_candidates']);
    self::assertSame(16, $bundle->counts['plan_3_candidates']);
    self::assertSame(28, $bundle->counts['candidate_payloads']);
    self::assertSame(28, $bundle->counts['passed_validations']);
    self::assertSame(28, $bundle->counts['bindings']);
    self::assertSame(28, $bundle->counts['conformance_records']);
}

public function test_admin_evidence_transfer_derives_source_commit_from_ledgers_and_writes_descriptor_last(): void
{
    $transfer = $this->runValidAdminEvidenceTransferWithoutSourceCommitArgument();

    self::assertSame('admin-evidence', $transfer->kind);
    self::assertSame(
        self::PLAN4_ADMIN_EVIDENCE_COMMIT_SHA,
        $transfer->sourceCommitSha,
    );
    self::assertSame(
        self::PLAN4_ADMIN_EVIDENCE_COMMIT_SHA,
        $transfer->adminEvidenceCommitSha,
    );
    self::assertSame(self::ACTIVATION_COMMIT_SHA, $transfer->activationCommitSha);
    self::assertSame([
        'build/reports/intake/plan-4-admin-evidence.json',
        'build/reports/intake/contracts/report-admin-evidence.schema.json',
        'build/reports/intake/plan-4-admin-evidence.transfer.json',
    ], $this->destinationWriteOrder());
}

public function test_admin_transfer_resolves_and_compares_commits_only_in_their_own_repositories(): void
{
    $this->runValidAdminEvidenceTransferWithoutSourceCommitArgument();

    self::assertSame([
        ['plan4', self::PLAN4_PRODUCER_COMMIT_SHA],
        ['plan4', self::PLAN4_ADMIN_EVIDENCE_COMMIT_SHA],
        ['backend', self::BACKEND_RELEASE_SHA],
        ['backend', self::ACTIVATION_COMMIT_SHA],
    ], $this->gitCommitResolverCalls());
    self::assertSame([
        ['plan4', self::PLAN4_PRODUCER_COMMIT_SHA, self::PLAN4_ADMIN_EVIDENCE_COMMIT_SHA],
        ['backend', self::BACKEND_RELEASE_SHA, self::ACTIVATION_COMMIT_SHA],
    ], $this->gitAncestryCalls());
}

public function test_release_sources_have_the_exact_closed_path_kind_and_commit_authorities(): void
{
    $bundle = $this->releaseGateBuilder->build(...$this->validReleaseGateArguments());

    self::assertSame([
        'build/reports/plan-1a-completion.json' => ['plan-1a-completion', 'ancestor_evidence', 'backend', 'P1A'],
        'build/reports/plan-1b-completion.json' => ['plan-1b-completion', 'ancestor_evidence', 'backend', 'P1B'],
        'build/reports/plan-1c-platform-completion.json' => ['plan-1c-platform-completion', 'ancestor_evidence', 'backend', 'P1C'],
        'build/reports/plan-2-wave-1-evidence.json' => ['plan-2-wave-1-candidate-conformance', 'release_evidence', 'backend', 'P2O/F0'],
        'build/reports/waves-2-3-candidate-contribution.json' => ['plan3_waves23_candidate_contribution', 'release_evidence', 'backend', 'F0'],
        'build/reports/plan-3-waves-2-3-evidence.json' => ['plan3_waves23_evidence', 'release_evidence', 'backend', 'F0'],
        'build/reports/report-catalog-activation-inputs.json' => ['report_catalog_activation_inputs', 'release_evidence', 'backend', 'F0'],
        'build/reports/report-catalog-activation.json' => ['report_catalog_activation', 'release_evidence', 'backend', 'A1'],
        'build/reports/intake/plan-4-admin-evidence.json' => ['plan4_admin_qg10_qg14_evidence', 'release_evidence', 'plan4', 'AP/AE'],
        'build/reports/intake/contracts/report-admin-evidence.schema.json' => ['plan4_admin_evidence_schema', 'tracked_file', 'plan4', 'AE'],
        'build/reports/intake/plan-4-admin-evidence.transfer.json' => ['plan4_admin_evidence_transfer', 'transfer', 'plan4+backend', 'AE/A1'],
        'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml' => ['report_management_catalog_active', 'tracked_file', 'backend', 'A1'],
        'app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json' => ['report_publication_ledger_active', 'tracked_file', 'backend', 'A1'],
    ], $this->sourceAuthorityMap($bundle));
}

public function test_admin_schema_digest_is_intentionally_reused_by_its_tracked_file_role(): void
{
    $sources = $this->sourcesByPath(
        $this->releaseGateBuilder->build(...$this->validReleaseGateArguments()),
    );

    self::assertSame(
        $sources['build/reports/intake/plan-4-admin-evidence.json']->schemaSha256,
        $sources['build/reports/intake/contracts/report-admin-evidence.schema.json']->bytesSha256,
    );
}

public function test_lifecycle_timestamps_are_canonical_nonfuture_and_monotonic(): void
{
    $this->assertActivationInputsAtOrAfterAllChildEvidence();
    $this->assertActivationAtOrAfterActivationInputs();
    $this->assertReleaseAtOrAfterEveryEvidenceAndTransfer();
    $this->assertEveryLifecycleTimestampIsCanonicalUtcSecondsAndNotFuture();
}

public function test_each_check_and_normal_pair_has_identical_argument_bytes_except_check(): void
{
    foreach ($this->allLifecycleCommandPairs() as [$check, $normal]) {
        self::assertSame(
            $normal->serializedArgumentBytes(),
            $check->withoutSoleCheckFlag()->serializedArgumentBytes(),
        );
    }
}

public function test_admin_activation_reference_is_derived_from_exact_plan4_intake_bytes(): void
{
    $reference = $this->transferVerifier->deriveActivationArtifactReference();

    self::assertSame($this->exactActivationArtifactReference(), $reference);
    self::assertCount(28, $reference->orderedReportCodes);
    self::assertSame([28, 28, 28, 28], [
        $reference->publishedCodeCount,
        $reference->bindingCodeCount,
        $reference->publicationLockHashCount,
        $reference->conformanceHashCount,
    ]);
}

public function test_release_reentry_is_read_only_and_requires_exact_fourteen_passed_gates(): void
{
    $before = $this->activeFileHashes();
    $release = $this->releaseBuilder->buildRelease(
        ...$this->validReleaseArguments(),
    );

    self::assertSame('release_passed', $release->status);
    self::assertCount(14, $release->gates);
    self::assertSame($before, $this->activeFileHashes());
}
```

`ReportingArtifactTransferSchemaTest` and `ReportingArtifactTransferServiceTest` use a closed data provider whose complete mode set is exactly `activation,admin-evidence,release`: one valid descriptor per branch, including `plan-4-admin-evidence-transfer.valid.json` and `report-release-evidence-transfer.valid.json`, must pass; it asserts the exact ordered branch ID map `report_catalog_activation_transfer,plan4_admin_evidence_transfer,report_release_evidence_transfer`. An ID from another branch, caller-supplied ID, cross-branch field leak, missing mode-specific commit ref, fourth kind or alternate path must fail. The release service test also asserts that stdout names source artifact `report_release_evidence` while the reread descriptor contains `report_release_evidence_transfer`. The admin-evidence positive method above belongs to `ReportAdminEvidenceTransferReentryTest` and proves that no caller source commit is needed.

Negative providers cover `11+16`, `12+15`, duplicate/extra code, wrong order, `27` payloads, one failed validation, duplicate/non-seven-field binding, child descriptor versus constructed binding mismatch, missing/full-record versus digest-only conformance, mutated content-addressed bytes, wrong candidate artifact link, mismatched child/freeze release SHA, non-ancestor Plan 2 owner commit, dirty freeze tree, source path escape/symlink, every candidate-only contamination token, recursive unknown schema fields, a partial activation write at both replacement failpoints, omitted/duplicate/alternate `--gates`, changed platform catalog path/hash, missing/extra/reordered gate, wrong owner/status/command/count/schema hash, the obsolete eight-backend/five-admin ownership split, QG-06 serialized as `both` or assigned to the joint bucket, QG-14 serialized as anything except `both`, any ownership total other than exact `backend=9/admin=4/joint=1` with sole joint gate QG-14, changed catalog-named source path/hash/bytes, passed record without every real source ref, pending record with an invented ref, missing/extra/duplicate primary source path or artifact ID, each wrong/invented value in the closed 13-item artifact-ID order, any of the 13 paths assigned the wrong `kind`, repository or commit authority, commit kind inferred from an absent child field, global rejection of the intentional admin schema digest reuse, unequal admin evidence `schema_sha256` versus admin-schema tracked-file `bytes_sha256`, `13/14` or `15/14` gates, each gate count mismatch, QG-03 `19/21` families, duplicate/missing report-code ownership, wrong Wave 1 golden ID, family/seed/assertion hash mismatch, family below `500` or total below `10000`, QG-07/QG-09/QG-10..QG-14 age `86401`, missing/unassigned lifecycle timestamp, non-UTC/fractional/noncanonical timestamp, activation-input time before a child, activation time before inputs, release time before any evidence/transfer, future timestamps, check/normal timestamp or argument drift beyond the sole `--check`, wrong source/schema/artifact hash and attempted release publication/mutation. A no-self-reference test proves neither tracked active file contains `ACTIVATION_COMMIT_SHA` while the outer activation transfer does.

The third-mode negative matrix additionally covers missing, duplicate or disagreeing `Plan 4 admin evidence commit` ledger lines; a caller-supplied `--source-commit` or activation reference; wrong, reversed or equal source/destination roots; Plan 4 `HEAD` or source bytes differing from the ledger-named commit blobs; Plan 4 admin/producer commit missing or non-ancestor in the Plan 4 repository; backend activation/release commit missing or non-ancestor in the backend repository; an implementation attempting to resolve backend commits in Plan 4, Plan 4 commits in backend, or to run cross-repository ancestry between activation and admin evidence; self-referential evidence/schema bytes; rewritten admin bytes; missing/extra/reordered `activation_artifact_ref` member, any alternate fixed activation path, mutated artifact/schema/transfer raw hash, wrong artifact/transfer status, release/A1, reordered/duplicate/missing code or any `27/29` count; wrong QG-10..QG-14 membership/counts/command set/age/hash, release SHA, activation commit or admin commit; an aggregate-only QG-14 record, missing/renamed admin/backend/combined count or hash, non-zero side, combined count not equal to the two-side sum, section or combined digest mismatch, one-root/swapped-root/alternate-root argv, `--section`, two QG-14 command IDs, two scan invocations, or an eighth Plan 4 command record; equal activation/admin SHA; a `--check` destination mutation; and failpoints that attempt to write the descriptor before both destination data files or leave partial output. Every case fails before destination mutation, while the positive re-entry test proves ledger-derived commit identity, repository-local commit/ancestry resolution, exact activation-reference recomputation, exact three-path write order, destination reread/hash/schema validation and descriptor-last publication.

Run RED:

`vendor/bin/phpunit tests/Architecture/Reporting/ReportCatalogActivationInputBundleSchemaTest.php tests/Architecture/Reporting/ReportReleaseGateBundleSchemaTest.php tests/Architecture/Reporting/ReportingArtifactTransferSchemaTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationInputBundleBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php tests/Unit/Reporting/Quality/FixedRootJointQG14EvidenceSourceTest.php tests/Unit/Reporting/Quality/ReportReleaseGateBundleBuilderTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Evidence/ReportingArtifactTransferServiceTest.php tests/Integration/Reporting/ReportActivationReleaseReentryTest.php tests/Integration/Reporting/ReportAdminEvidenceTransferReentryTest.php`

Expected RED: missing `ReportCatalogActivationInputBundleBuilder`, `FixedRootJointQG14EvidenceSource`, `ReportReleaseGateBundleBuilder` and the three-mode transfer service; all fixture/source/destination bytes remain unchanged.

Run: `vendor/bin/phpunit tests/Architecture/Reporting/ReportPlatformGateCatalogTest.php tests/Architecture/Reporting/ReportQualityGateHttpIsolationTest.php tests/Unit/Reporting/Quality/ReportPlatformGateFixtureBuilderTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php`

Expected RED: `Class "App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseEvidenceBuilder" not found`.

- [ ] **GREEN:** implement closed gate catalog, offline-only failure model, strict Opis schemas, phase-specific builder, atomic 28-definition activation and scripts.

Implement the strict activation-input loader/builder, logical two-file activation transaction, three-branch transfer descriptor/verifier, closed 14-gate bundle and activation/admin-evidence/release CLI. Run GREEN:

`vendor/bin/phpunit tests/Architecture/Reporting/ReportCatalogActivationInputBundleSchemaTest.php tests/Architecture/Reporting/ReportReleaseGateBundleSchemaTest.php tests/Architecture/Reporting/ReportingArtifactTransferSchemaTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationInputBundleBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php tests/Unit/Reporting/Quality/FixedRootJointQG14EvidenceSourceTest.php tests/Unit/Reporting/Quality/ReportReleaseGateBundleBuilderTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Evidence/ReportingArtifactTransferServiceTest.php tests/Integration/Reporting/ReportActivationReleaseReentryTest.php tests/Integration/Reporting/ReportAdminEvidenceTransferReentryTest.php`

Expected GREEN: exit `0`; no failure/error/skipped/incomplete/risky test; positive fixtures prove exact `12+16=28`, `28/28` activation, all three closed transfers including the ledger-derived admin-evidence handoff, and `14/14` release, while every negative provider fails closed before mutation.

Run: `vendor/bin/phpunit tests/Architecture/Reporting/ReportPlatformGateCatalogTest.php tests/Architecture/Reporting/ReportQualityGateHttpIsolationTest.php tests/Unit/Reporting/Quality/ReportPlatformGateFixtureBuilderTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php`

Expected GREEN: all closed-catalog, deterministic fixture provenance, phase separation and offline-isolation cases pass.

Run: `php scripts/reporting/build-report-quality-evidence.php --phase=platform --manifest=tests/Fixtures/Reporting/Manifest/management.valid.yaml --official=tests/Fixtures/Reporting/Manifest/official.valid.yaml --gates=tests/Fixtures/Reporting/Quality/platform-gates.valid.json --plan-1a=tests/Fixtures/Reporting/Prerequisites/plan-1a-completion.valid.json --plan-1b=tests/Fixtures/Reporting/Prerequisites/plan-1b-completion.valid.json --release-sha=1111111111111111111111111111111111111111 --generated-at=2026-07-26T00:00:00Z --output=tests/Fixtures/Reporting/Quality/report-platform-evidence.valid.json --check`

Expected: `report-quality-evidence: platform_passed`; fixture unchanged.

Run: `php scripts/reporting/activate-report-catalog.php --current=tests/Fixtures/Reporting/Activation/current.yaml --candidate=tests/Fixtures/Reporting/Activation/candidate-28.yaml --validation=tests/Fixtures/Reporting/Activation/validation-28.json --bindings=tests/Fixtures/Reporting/Activation/bindings-28.json --conformance=tests/Fixtures/Reporting/Activation/conformance-28.json --plan-2=tests/Fixtures/Reporting/Activation/plan-2.valid.json --plan-3=tests/Fixtures/Reporting/Activation/plan-3.valid.json --release-sha=1111111111111111111111111111111111111111 --activated-at=2026-07-26T00:00:00Z --output=tests/Fixtures/Reporting/Quality/report-catalog-activation.valid.json --check`

Expected: `report-catalog-activation: 28/28 PASS`; fixture and active manifest unchanged.

CI-only Run: `vendor/bin/phpunit tests/Integration/Reporting/ReportCatalogWorkspaceSubscriptionIntegrationTest.php`

Expected CI GREEN: `OK (9 tests, 58 assertions)`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Quality app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationService.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidencePhase.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidenceStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityGateFailureCode.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityGateEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityEvidenceLedger.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivation.php --no-progress`

Expected: exit 0, `[OK] No errors`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Quality/ReportReleaseGateBundleBuilder.php app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleBuilder.php app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleLoader.php app/BusinessModules/Core/Reporting/Application/Evidence/ReportingArtifactTransferService.php app/BusinessModules/Core/Reporting/Domain/Contracts/JointQG14EvidenceSource.php app/BusinessModules/Core/Reporting/Domain/DTO/JointQG14Evidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputBundle.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputs.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportReleaseGateBundle.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportingArtifactTransfer.php app/BusinessModules/Core/Reporting/Infrastructure/Quality/FixedRootJointQG14EvidenceSource.php scripts/reporting/build-report-catalog-activation-inputs.php scripts/reporting/build-report-release-gate-bundle.php scripts/reporting/transfer-reporting-artifact.php --no-progress`

Expected: exit `0`, `[OK] No errors`.

- [ ] **Commit:**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidencePhase.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityEvidenceStatus.php app/BusinessModules/Core/Reporting/Domain/Enums/ReportQualityGateFailureCode.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityGateEvidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportQualityEvidenceLedger.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivation.php app/BusinessModules/Core/Reporting/Application/Quality app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationService.php docs/reports/contracts/report-platform-gates.v1.json docs/reports/contracts/report-quality-evidence.schema.json docs/reports/contracts/report-catalog-activation.schema.json scripts/reporting/build-report-quality-evidence.php scripts/reporting/activate-report-catalog.php tests/Fixtures/Reporting/Quality/platform-gates.valid.json tests/Fixtures/Reporting/Quality/report-platform-evidence.valid.json tests/Fixtures/Reporting/Quality/report-release-evidence.valid.json tests/Fixtures/Reporting/Quality/report-catalog-activation.valid.json tests/Support/Reporting/Quality/ReportPlatformGateFixtureBuilder.php tests/Architecture/Reporting/ReportPlatformGateCatalogTest.php tests/Architecture/Reporting/ReportQualityGateHttpIsolationTest.php tests/Unit/Reporting/Quality/ReportPlatformGateFixtureBuilderTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php tests/Integration/Reporting/ReportCatalogWorkspaceSubscriptionIntegrationTest.php`

Run: `git add -- .gitignore app/BusinessModules/Core/Reporting/Domain/Contracts/JointQG14EvidenceSource.php app/BusinessModules/Core/Reporting/Domain/DTO/JointQG14Evidence.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputBundle.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogActivationInputs.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportReleaseGateBundle.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportingArtifactTransfer.php app/BusinessModules/Core/Reporting/Application/Quality/ReportReleaseGateBundleBuilder.php app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleBuilder.php app/BusinessModules/Core/Reporting/Application/Publication/ReportCatalogActivationInputBundleLoader.php app/BusinessModules/Core/Reporting/Application/Evidence/ReportingArtifactTransferService.php app/BusinessModules/Core/Reporting/Infrastructure/Quality/FixedRootJointQG14EvidenceSource.php app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json docs/reports/contracts/report-catalog-activation-input-bundle.schema.json docs/reports/contracts/report-release-gate-bundle.schema.json docs/reports/contracts/reporting-artifact-transfer.schema.json scripts/reporting/build-report-catalog-activation-inputs.php scripts/reporting/build-report-release-gate-bundle.php scripts/reporting/transfer-reporting-artifact.php tests/Fixtures/Reporting/Activation tests/Fixtures/Reporting/Quality/report-release-gate-bundle.valid.json tests/Fixtures/Reporting/Quality/plan-4-admin-evidence.valid.json tests/Fixtures/Reporting/Quality/plan-4-admin-evidence-transfer.valid.json tests/Fixtures/Reporting/Quality/reporting-artifact-transfer.valid.json tests/Fixtures/Reporting/Quality/report-release-evidence-transfer.valid.json tests/Architecture/Reporting/ReportCatalogActivationInputBundleSchemaTest.php tests/Architecture/Reporting/ReportReleaseGateBundleSchemaTest.php tests/Architecture/Reporting/ReportingArtifactTransferSchemaTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationInputBundleBuilderTest.php tests/Unit/Reporting/Quality/FixedRootJointQG14EvidenceSourceTest.php tests/Unit/Reporting/Quality/ReportReleaseGateBundleBuilderTest.php tests/Unit/Reporting/Evidence/ReportingArtifactTransferServiceTest.php tests/Integration/Reporting/ReportActivationReleaseReentryTest.php tests/Integration/Reporting/ReportAdminEvidenceTransferReentryTest.php`

Expected before the foundation commit: staged files contain implementation/schemas/fixtures/tests plus the initial platform ledger, but do not contain a 28-definition active manifest, `catalog_activated` output or `release_passed` output.

Run: `git commit -m "test[reports]: разделены platform и release gates"`

### Task 12: Exact prerequisite lock и `platform_passed` completion evidence

**Files:**

- Create: `docs/reports/contracts/plan-1c-contract-lock.json`
- Create: `docs/reports/contracts/plan-1c-contract-lock.sha256`
- Create: `docs/reports/contracts/plan-1c-platform-completion.schema.json`
- Create: `docs/reports/contracts/report-prerequisite-artifact-bundle.schema.json`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportEvidenceArtifactDescriptor.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportPrerequisiteEvidenceBundle.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/TrackedPlanDocument.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/TrackedRepositoryFileReader.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneCPrerequisiteEvidenceValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneCPlatformEvidenceBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Evidence/GitTrackedRepositoryFileReader.php`
- Create: `scripts/reporting/build-plan-1c-platform-evidence.php`
- Create: `tests/Fixtures/Reporting/Prerequisites/plan-1a-completion.valid.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/plan-1b-completion.valid.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifact-bundle.valid.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-contract-lock.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-resource-schema.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-route-snapshot.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-ci-authorization.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-ci-malformed.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-plan1a-handoff.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-ownership-boundary.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-run-state-machine.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-run-idempotency.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-snapshot-identity.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-rows-cursor-drill-parity.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-row-stream-shape.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-export-state-machine.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-export-idempotency.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-renderer-parity.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-pdf-renderer-budget.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-streaming-budget.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-file-service-call-graph.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-s3-version-race.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-audit-fail-closed.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-retention-exact-version.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-action-bindings.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-error-retryability.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-run-export-observability.json`
- Create: `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1b-static-analysis.json`
- Create: `tests/Fixtures/Reporting/plan-1c-platform-completion.valid.json`
- Test: `tests/Unit/Reporting/Evidence/ReportPrerequisiteEvidenceBundleTest.php`
- Test: `tests/Unit/Reporting/Evidence/PlanOneCPrerequisiteEvidenceValidatorTest.php`
- Test: `tests/Unit/Reporting/Evidence/PlanOneCPlatformEvidenceBuilderTest.php`
- Test: `tests/Feature/Reporting/PlanOneCFakeSequenceTest.php`
- Test: `tests/Architecture/Reporting/PlanOneCPrerequisiteContractTest.php`
- Test: `tests/Architecture/Reporting/PlanOneCCrossPlanSymbolLockTest.php`
- Test: `tests/Architecture/Reporting/PlanOneCScopeBoundaryTest.php`
- Modify: `.gitignore`
- Track unchanged with force-add: `docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md`
- Track with force-add: `docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

This planning amendment updates only the Task 12 fixture contract stated below; it does not create or edit product fixture bytes now. When Task 12 is implemented, the following six prerequisite fixtures are regenerated from the amended Plan 1a contract:

| Fixture path | Exact updated expectation |
|---|---|
| `tests/Fixtures/Reporting/Prerequisites/plan-1a-completion.valid.json` | commit-bound Plan 1a completion with `status=passed`; both matrix summaries use `verification_mode=hermetic_http`; authorization is exact `22/22`, malformed requests are exact `20/20`, and the existing five digest-bearing fields remain the only digest leaves |
| `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-contract-lock.json` | updated trailer-derived Task 7 baseline/reviewed commit provenance, raw Task 7 artifact hash and exact locked fields, with no local-ref dependency |
| `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-route-snapshot.json` | `verification_mode=production_topology_snapshot`; exact closed ordered `topology={global_middleware,api_middleware}` from the unbooted production topology snapshot; exactly 12 closed, name-sorted minimal-slice routes with exact URI/raw middleware and raw methods `["GET","HEAD"]` for all four GET routes and `["POST"]` for all eight POST routes; exact ordered 19 legacy absences and exact six raw-Git source hashes |
| `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-ci-authorization.json` | `verification_mode=hermetic_http`, `status=passed`, exact `cases=22`, `passed=22`, `http_requests=28`, `assertions=132` and the closed 22-case execution records |
| `tests/Fixtures/Reporting/Prerequisites/artifacts/plan-1a-ci-malformed.json` | `verification_mode=hermetic_http`, `status=passed`, exact `cases=20`, `passed=20`, `http_requests=38`, `assertions=120` and the closed 20-case execution records |
| `tests/Fixtures/Reporting/Prerequisites/artifact-bundle.valid.json` | regenerated raw-byte SHA-256 descriptors for the amended Plan 1a completion/lock/route/authorization/malformed bytes; descriptor IDs, paths, the literal five-field completion mapping and exact descriptor count `27` remain unchanged; both matrix modes remain `hermetic_http`, and registry/assembler arity plus the nominal-wrapper protocol are unchanged |

The tracked lock contains exact current symbols, not aliases:

```json
{
  "plan": "1c",
  "contract_version": "1.0.0",
  "canonical_namespace": "App\\BusinessModules\\Core\\Reporting",
  "opis_json_schema_version": "2.6.0",
  "registries": {
    "runtime": "ReportDefinitionRegistry",
    "runtime_method": "published(string): PublishedReportDefinition",
    "candidate": "CandidateReportDefinitionRegistry",
    "candidate_method": "candidate(string): CandidateReportDefinition",
    "shared_payload_method": "payload(): ReportDefinition",
    "candidate_returns_binding_map": false
  },
  "binding": {
    "constructor_fields": 7,
    "register": "register(ReportDefinitionBinding): void",
    "assemble": "assemble(ReportDefinitionRegistry): ReportDefinitionBindingMap",
    "set_rule": "order_independent_published_codes_equals_registered_codes",
    "map_order": "published_manifest_order",
    "manifest_ordinal": "ReportCatalogMetadata.manifestOrdinal"
  },
  "execution_actions": {
    "create_run_arity": 3,
    "get_run_arity": 2,
    "create_export_arity": 4,
    "get_export_arity": 2
  },
  "catalog": {
    "management_identity_count": 28,
    "official_codes": ["official_material_usage_m29"],
    "groups": [
      "portfolio",
      "projects",
      "finance",
      "procurement_warehouse",
      "team",
      "quality_safety",
      "partners_customers"
    ]
  },
  "workspace": {
    "dto": "ReportWorkspacePreferences",
    "resource": "ReportWorkspacePreferencesResource",
    "recent_limit": 10,
    "favourites_server_owned": true
  },
  "saved_views": {
    "window": "ReportSavedViewWindow",
    "page": "ReportSavedViewPage",
    "resource": "ReportSavedViewPageResource",
    "exact_total": false
  },
  "subscriptions": {
    "window": "ReportSubscriptionWindow",
    "page": "ReportSubscriptionPage",
    "cursor": "ReportSubscriptionCursor",
    "page_resource": "ReportSubscriptionPageResource",
    "route_prefix": "/api/v1/admin/reports/subscriptions",
    "channel": "in_app",
    "delivery_states": [
      "scheduled",
      "building_run",
      "building_export",
      "ready",
      "notified",
      "failed",
      "expired"
    ],
    "delivery_input": "pinned_bytes_hash_and_subscription_version",
    "manual_idempotency_scope": "subscription_id_trigger_key_hash",
    "manual_insert_protocol": "on_conflict_do_nothing_returning",
    "execution_ttl_seconds": 86400,
    "retention_days": 90,
    "notification_exactly_once": true,
    "exact_total": false
  },
  "quality": {
    "platform_status": "platform_passed",
    "activation_status": "catalog_activated",
    "release_status": "release_passed",
    "release_published_count": 28,
    "release_binding_count": 28
  },
  "required_prerequisites": {
    "artifact_bundle_schema": "report-prerequisite-artifact-bundle.schema.json",
    "bundle_descriptor_count": 27,
    "plan_1a_artifact_id": "plan-1a-completion",
    "plan_1a_status": "passed",
    "plan_1a_nested_artifact_ids": [
      "plan-1a-contract-lock",
      "plan-1a-resource-schema",
      "plan-1a-route-snapshot",
      "plan-1a-ci-authorization",
      "plan-1a-ci-malformed"
    ],
    "plan_1b_artifact_id": "plan-1b-completion",
    "plan_1b_status": "passed",
    "plan_1b_gate_artifact_count": 20,
    "plan_1b_required_gate_ids": [
      "plan1a_handoff",
      "ownership_boundary",
      "run_state_machine",
      "run_idempotency",
      "snapshot_identity",
      "rows_cursor_drill_parity",
      "row_stream_shape",
      "export_state_machine",
      "export_idempotency",
      "renderer_parity",
      "pdf_renderer_budget",
      "streaming_budget",
      "file_service_call_graph",
      "s3_version_race",
      "audit_fail_closed",
      "retention_exact_version",
      "action_bindings",
      "error_retryability",
      "run_export_observability",
      "static_analysis"
    ],
    "plan_1b_path": "docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md",
    "plan_1c_path": "docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md",
    "plan_1b_plan_sha256": "58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626"
  }
}
```

`PlanOneCCrossPlanSymbolLockTest` reflection-checks the real Plan 1a wrappers, registries, seven-field binding, assembler/validator, owner ports and four action interfaces. It then boots the actual current Plan 1b round 3 provider with Plan 1c provider and proves the real run/rows/drill/export handlers receive the same singleton `ReportDefinitionRegistry` and `ReportDefinitionBindingMap`; a throwing candidate-registry spy records zero calls. It asserts that neither plan contains nor resolves an additional execution resolver: current Plan 1b round 3 deliberately consumes registry/map directly.

**Tracked plan and prerequisite bundle contracts:**

```php
final readonly class ReportEvidenceArtifactDescriptor
{
    public function __construct(
        public string $id,
        public string $plan,
        public string $kind,
        public string $relativePath,
        public Sha256Hash $sha256,
    ) {
    }
}

final readonly class ReportPrerequisiteEvidenceBundle
{
    public function __construct(
        public Sha256Hash $manifestHash,
        public array $artifacts,
        public array $planOneACompletion,
        public array $planOneBCompletion,
    ) {
    }
}

final readonly class TrackedPlanDocument
{
    public function __construct(
        public string $relativePath,
        public string $commitSha,
        public Sha256Hash $bytesHash,
        public string $bytes,
    ) {
    }
}

interface TrackedRepositoryFileReader
{
    public function read(
        string $repositoryRoot,
        string $relativePath,
        string $commitSha,
    ): TrackedPlanDocument;
}
```

`report-prerequisite-artifact-bundle.schema.json` requires `schema_version:1.0.0` and an `artifacts` list whose objects contain only `id`, `plan`, `kind`, `relative_path`, `sha256`. IDs and paths are unique. Paths are UTF-8 relative paths under the bundle directory; absolute paths, `..`, drive prefixes, NUL and symlinks escaping the bundle root are rejected.

The bundle has exactly 27 content-addressed entries:

- Plan 1a completion: `plan-1a-completion`;
- five Plan 1a nested bytes: `plan-1a-contract-lock`, `plan-1a-resource-schema`, `plan-1a-route-snapshot`, `plan-1a-ci-authorization`, `plan-1a-ci-malformed`;
- Plan 1b completion: `plan-1b-completion`;
- exactly 20 `plan-1b:<gate-id>` entries: `plan1a_handoff`, `ownership_boundary`, `run_state_machine`, `run_idempotency`, `snapshot_identity`, `rows_cursor_drill_parity`, `row_stream_shape`, `export_state_machine`, `export_idempotency`, `renderer_parity`, `pdf_renderer_budget`, `streaming_budget`, `file_service_call_graph`, `s3_version_race`, `audit_fail_closed`, `retention_exact_version`, `action_bindings`, `error_retryability`, `run_export_observability`, `static_analysis`.

The Plan 1a completion-to-descriptor mapping is closed and literal:

| Plan 1a completion field | Descriptor ID | Reread fixture path |
|---|---|---|
| `contract_lock_sha256` | `plan-1a-contract-lock` | `artifacts/plan-1a-contract-lock.json` |
| `resource_schema_sha256` | `plan-1a-resource-schema` | `artifacts/plan-1a-resource-schema.json` |
| `route_snapshot_sha256` | `plan-1a-route-snapshot` | `artifacts/plan-1a-route-snapshot.json` |
| `ci_http_matrices.authorization.artifact_sha256` | `plan-1a-ci-authorization` | `artifacts/plan-1a-ci-authorization.json` |
| `ci_http_matrices.malformed_requests.artifact_sha256` | `plan-1a-ci-malformed` | `artifacts/plan-1a-ci-malformed.json` |

`PlanOneCPrerequisiteEvidenceValidator::validateBundle(string $manifestPath): ReportPrerequisiteEvidenceBundle` rereads raw manifest bytes, validates them with Opis and computes `manifestHash` over those exact bytes. Because every descriptor contains its exact ID, plan, kind, relative path and SHA-256, this hash binds the complete 27-entry set. The validator resolves every path, rereads every artifact exactly once and compares its computed SHA-256 with the descriptor using `hash_equals()`.

It then validates both completion documents and distinguishes their evidence semantics: Plan 1a must be a commit-bound hermetic HTTP completion with `status=passed`, both matrix `verification_mode` values exactly `hermetic_http`, authorization exactly `cases=22` and `passed=22`, and malformed requests exactly `cases=20` and `passed=20`; Plan 1b retains its own post-CI completion semantics and every required gate must be `passed`. The validator constructs the exhaustive expected ID set from the five literal Plan 1a digest fields above and the exact 20 Plan 1b required gates, and requires set equality with all 27 descriptors including the two completion documents. Every Plan 1a embedded digest and every Plan 1b gate digest must map bijectively to one descriptor and its reread bytes; every descriptor must be referenced exactly once. Missing, extra, duplicate, wrong-plan, wrong-kind, wrong-path, wrong-digest, wrong Plan 1a matrix mode/count or unreferenced entries fail. A 64-hex digest without matching bytes never counts as evidence.

`GitTrackedRepositoryFileReader` accepts only the two exact plan paths in the lock. It requires both to be known by Git, reads their working-tree bytes, reads the blob at `commitSha:path`, requires byte equality, and computes the SHA-256 itself after each read. It rejects dirty/missing/untracked files, a commit that does not contain the path and any caller-provided alternate path. `PlanOneCPlatformEvidenceBuilder` receives the two `TrackedPlanDocument` objects; it does not accept plan digests as scalar arguments. It requires both `commitSha` fields to equal the completion repository commit, requires Plan 1b hash `58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626`, and records both computed plan hashes plus prerequisite bundle-manifest hash in completion.

The prerequisite validator:

1. validates Plan 1a completion bytes with `plan-1a-completion.schema.json`, requires commit-bound status `passed`, both matrix modes exactly `hermetic_http`, authorization exactly `22/22` and malformed requests exactly `20/20`;
2. validates Plan 1b completion bytes with `plan-1b-evidence.schema.json` and requires every required gate `passed`;
3. verifies the exact five Plan 1a lock/schema/route/hermetic HTTP matrix digests and all 20 Plan 1b gate digests against their mapped reread bytes from the content-addressed bundle;
4. verifies Plan 1b references the exact accepted Plan 1a evidence digest and commit;
5. verifies the tracked Plan 1b document SHA-256 computed from both working and commit bytes is exactly `58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626`;
6. rejects missing/skipped/failed/stale/unknown fields and never treats file existence as completion.

Tracked prerequisite fixtures are deterministic schema examples only. In CI, the builder receives one downloaded bundle manifest plus all content-addressed files under its bounded directory.

**Product-data-free fake sequence:**

1. Load one complete candidate fixture through Opis and semantic validation.
2. Resolve it only as `CandidateReportDefinition`; `published()` for the code returns `REPORT_NOT_FOUND`.
3. Register one exact seven-field fake binding and create passed source/formula conformance evidence.
4. Validate candidate exact sets and prove result contains no `ReportDefinitionBindingMap`.
5. Promote into an isolated temporary manifest; reread bytes as `PublishedReportDefinition`.
6. Assemble the exact one/one frozen map and call the real Plan 1b run/rows/drill/export handlers using Plan 1a fake owner ports.
7. Prove materialize/result/page/cursor/drill exact arities and snapshot/query/source identity.
8. Prove catalog handler, resource and generator share the same manifest hash and group.
9. Prove two organizations have isolated workspace preferences and saved-view pages.
10. Execute subscription `scheduled→building_run→building_export→ready→notified`, including queued polls, and prove notifier receipt count is one across duplicate jobs.
11. Update the subscription after scheduling; prove calendar/manual retries use pinned delivery bytes/hash/version, scoped manual replay returns the same delivery, conflict returns `REPORT_IDEMPOTENCY_CONFLICT`, 24-hour expiry is terminal and 90-day prune creates no second business transition.
12. Verify the exact 27-entry content-addressed prerequisite bundle, including all five Plan 1a nested digests and all 20 Plan 1b gates, plus tracked Plan 1b/1c commit bytes; then build platform evidence with status exactly `platform_passed`, with no assertion that production has 28 published definitions.

Temporary promotion bytes exist only inside the test directory and are removed by the test harness; the sequence does not alter production registry resources or domain/product data.

**Completion schema and builder:**

`plan-1c-platform-completion.schema.json` is Draft 2020-12 with `additionalProperties:false` at every object and status `const: platform_passed`. It requires:

- plan/schema version, repository commit, generation timestamp;
- Plan 1c lock bytes hash; computed tracked Plan 1b and Plan 1c document hashes; the repository commit containing the exact same bytes;
- content-addressed prerequisite bundle-manifest hash plus exact Plan 1a completion/five nested artifact hashes and Plan 1b completion/20 gate hashes verified from reread bytes;
- commit-bound Plan 1a hermetic HTTP completion and Plan 1b post-CI completion commit/lock/schema hashes and passed statuses;
- the fixed accepted Plan 1b round 3 plan SHA-256 above, computed rather than accepted from CLI;
- management/official raw manifest hashes and management identity/wave/group counts;
- generated catalog/resource/permission/translation/route/schema hashes;
- candidate validation, conformance-framework, publication-framework and platform quality-ledger hashes;
- local command records with command, status `passed`, count/duration and output artifact hash;
- required CI artifact hashes for PostgreSQL workspace/saved-view/subscription, integration and fake-sequence jobs;
- `published_count` and `binding_count` as actual integers `0..28`, with equality required;
- empty unresolved-risk list for Plan 1c-owned platform scope.

The schema prohibits `passed`, `release_passed`, `catalog_activated`, `production_ready` and any statement that all 28 definitions are published. `PlanOneCPlatformEvidenceBuilder` performs the same semantic checks in PHP after Opis validation.

`build-plan-1c-platform-evidence.php` requires:

```text
--repository-root
--commit-sha
--plan-1b-path=docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md
--plan-1c-path=docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md
--prerequisite-bundle
--platform-quality-artifact
--ci-workspace-artifact
--ci-saved-views-artifact
--ci-subscriptions-artifact
--ci-integration-artifact
--ci-fake-sequence-artifact
--executed-at
```

The script accepts no Plan 1a/1b completion hash, Plan 1a nested hermetic HTTP matrix hash, Plan 1b plan hash, Plan 1c plan hash or platform-quality hash from the caller. It computes them from reread bytes, binds both plan documents to `--commit-sha`, verifies every schema and required passed check, canonicalizes JSON, atomically writes only `build/reports/plan-1c-platform-completion.json`, rereads, revalidates and prints its SHA-256. It cannot invoke the release builder or activation service.

Task 12 appends these exact `.gitignore` rules after the existing `*.md` rule and keeps the generated completion ignored:

```gitignore
!docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md
!docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md
build/reports/plan-1c-platform-completion.json
```

Both plan documents are also force-added in the Task 12 commit. This is intentional: the accepted Plan 1b bytes become a tracked prerequisite without editing that plan, and Plan 1c completion can bind its own tracked bytes to the repository commit.

- [ ] **RED:** add exact 27-ID bundle-set/bijection tests; missing/wrong-digest/mutated-byte cases for the three new Plan 1a nested artifacts and `plan-1b:pdf_renderer_budget`; within the already-counted negative mutation methods, replace stale Plan 1a CI-semantic variants with rejection of authorization/malformed mode other than `hermetic_http`, counts other than exact `22/22` and `20/20`, a GET route missing raw `HEAD`, a POST route with an extra method, and any stale descriptor digest after regenerating the amended completion/lock/route/matrix bytes; add tracked-plan/commit failures, exact Plan 1b SHA lock, reflection bridge, fake-sequence and ignored-artifact tests. These substitutions preserve the existing expected GREEN aggregate `33 tests, 200 assertions`.

```php
public function test_bundle_rejects_nested_artifact_bytes_with_wrong_digest(): void
{
    $bundle = $this->bundleFixture();
    file_put_contents(
        $bundle->path('artifacts/plan-1b-static-analysis.json'),
        '{"status":"changed"}',
    );

    $this->expectExceptionMessage('prerequisite_artifact_hash_mismatch');
    $this->validator->validateBundle($bundle->manifestPath());
}

public static function newlyRequiredNestedArtifactPaths(): iterable
{
    yield 'plan-1a contract lock' => [
        'artifacts/plan-1a-contract-lock.json',
    ];
    yield 'plan-1a resource schema' => [
        'artifacts/plan-1a-resource-schema.json',
    ];
    yield 'plan-1a route snapshot' => [
        'artifacts/plan-1a-route-snapshot.json',
    ];
    yield 'plan-1b pdf renderer budget' => [
        'artifacts/plan-1b-pdf-renderer-budget.json',
    ];
}

#[\PHPUnit\Framework\Attributes\DataProvider('newlyRequiredNestedArtifactPaths')]
public function test_bundle_rejects_missing_required_nested_bytes(
    string $relativePath,
): void {
    $bundle = $this->bundleFixture();
    unlink($bundle->path($relativePath));

    $this->expectExceptionMessage('prerequisite_artifact_missing');
    $this->validator->validateBundle($bundle->manifestPath());
}

#[\PHPUnit\Framework\Attributes\DataProvider('newlyRequiredNestedArtifactPaths')]
public function test_bundle_rejects_wrong_required_nested_digest(
    string $relativePath,
): void {
    $bundle = $this->bundleFixture();
    $bundle->replaceDescriptorSha256($relativePath, str_repeat('0', 64));

    $this->expectExceptionMessage('prerequisite_artifact_hash_mismatch');
    $this->validator->validateBundle($bundle->manifestPath());
}

#[\PHPUnit\Framework\Attributes\DataProvider('newlyRequiredNestedArtifactPaths')]
public function test_bundle_rejects_mutated_required_nested_bytes(
    string $relativePath,
): void {
    $bundle = $this->bundleFixture();
    file_put_contents($bundle->path($relativePath), '{"mutated":true}');

    $this->expectExceptionMessage('prerequisite_artifact_hash_mismatch');
    $this->validator->validateBundle($bundle->manifestPath());
}

public function test_builder_computes_plan_hashes_from_tracked_commit_bytes(): void
{
    $evidence = $this->builder->build(...$this->validArguments());

    self::assertSame(
        hash('sha256', $this->trackedReader->bytesAtCommit(
            $evidence->repositoryCommit,
            $evidence->planOneCPath,
        )),
        $evidence->planOneCSha256,
    );
    self::assertSame(
        $this->bundle->manifestHash->value,
        $evidence->prerequisiteBundleSha256,
    );
}
```

Run: `vendor/bin/phpunit tests/Unit/Reporting/Evidence/ReportPrerequisiteEvidenceBundleTest.php tests/Unit/Reporting/Evidence/PlanOneCPrerequisiteEvidenceValidatorTest.php tests/Unit/Reporting/Evidence/PlanOneCPlatformEvidenceBuilderTest.php tests/Architecture/Reporting/PlanOneCPrerequisiteContractTest.php tests/Architecture/Reporting/PlanOneCCrossPlanSymbolLockTest.php tests/Architecture/Reporting/PlanOneCScopeBoundaryTest.php`

Expected RED: `Failed asserting that plan-1c-contract-lock.json exists`.

- [ ] **GREEN:** create lock/digest/schema/validator/builder/script/fixtures, complete exact reflection and fake sequence, add exact ignored artifact.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Evidence/ReportPrerequisiteEvidenceBundleTest.php tests/Unit/Reporting/Evidence/PlanOneCPrerequisiteEvidenceValidatorTest.php tests/Unit/Reporting/Evidence/PlanOneCPlatformEvidenceBuilderTest.php tests/Architecture/Reporting/PlanOneCPrerequisiteContractTest.php tests/Architecture/Reporting/PlanOneCCrossPlanSymbolLockTest.php tests/Architecture/Reporting/PlanOneCScopeBoundaryTest.php`

Expected GREEN: `OK (33 tests, 200 assertions)`.

CI-only Run: `vendor/bin/phpunit tests/Feature/Reporting/PlanOneCFakeSequenceTest.php`

Expected CI GREEN: `OK (8 tests, 103 assertions)`.

Run: `vendor/bin/phpunit tests/Architecture/Reporting/ReportManifestIdentityContractTest.php tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php tests/Unit/Reporting/Catalog/ReportCodeSetComparatorTest.php tests/Unit/Reporting/Catalog/ReportDefinitionCandidateValidatorTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php tests/Architecture/Reporting/CandidatePublishedBoundaryTest.php tests/Contract/Reporting/PlanOneBPublishedBindingConsumptionTest.php tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php tests/Unit/Reporting/SavedViews/ReportSavedViewServiceTest.php tests/Architecture/Reporting/ReportSavedViewRouteContractTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionScheduleCalculatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionCoordinatorTest.php tests/Unit/Reporting/Subscriptions/ReportSubscriptionDeliveryProcessorTest.php tests/Unit/Reporting/Subscriptions/DeliverReportSubscriptionJobTest.php tests/Unit/Reporting/Cursors/ReportSubscriptionCursorTest.php tests/Unit/Reporting/Cursors/SignedReportSubscriptionCursorCodecTest.php tests/Unit/Reporting/Http/ReportSubscriptionResourceSchemaTest.php tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php tests/Architecture/Reporting/ReportPlatformGateCatalogTest.php tests/Architecture/Reporting/ReportQualityGateHttpIsolationTest.php tests/Unit/Reporting/Quality/ReportReleaseEvidenceBuilderTest.php tests/Unit/Reporting/Publication/ReportCatalogActivationServiceTest.php tests/Unit/Reporting/Evidence/ReportPrerequisiteEvidenceBundleTest.php tests/Unit/Reporting/Evidence/PlanOneCPrerequisiteEvidenceValidatorTest.php tests/Unit/Reporting/Evidence/PlanOneCPlatformEvidenceBuilderTest.php tests/Architecture/Reporting/PlanOneCPrerequisiteContractTest.php tests/Architecture/Reporting/PlanOneCCrossPlanSymbolLockTest.php tests/Architecture/Reporting/PlanOneCScopeBoundaryTest.php`

Expected local aggregate: exit 0, zero skipped tests.

Run: `php scripts/reporting/generate-reporting-contracts.php --check`

Expected: `reporting-contracts: clean`.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting tests/Support/Reporting --no-progress`

Expected: exit 0, `[OK] No errors`.

Run: `git check-ignore -q build/reports/plan-1c-platform-completion.json`

Expected: exit 0.

Run: `git ls-files --error-unmatch build/reports/plan-1c-platform-completion.json`

Expected: non-zero; generated evidence is untracked.

- [ ] **Commit:**

Run: `git add -- docs/reports/contracts/plan-1c-contract-lock.json docs/reports/contracts/plan-1c-contract-lock.sha256 docs/reports/contracts/plan-1c-platform-completion.schema.json docs/reports/contracts/report-prerequisite-artifact-bundle.schema.json app/BusinessModules/Core/Reporting/Domain/DTO/ReportEvidenceArtifactDescriptor.php app/BusinessModules/Core/Reporting/Domain/DTO/ReportPrerequisiteEvidenceBundle.php app/BusinessModules/Core/Reporting/Domain/DTO/TrackedPlanDocument.php app/BusinessModules/Core/Reporting/Domain/Contracts/TrackedRepositoryFileReader.php app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneCPrerequisiteEvidenceValidator.php app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneCPlatformEvidenceBuilder.php app/BusinessModules/Core/Reporting/Infrastructure/Evidence/GitTrackedRepositoryFileReader.php scripts/reporting/build-plan-1c-platform-evidence.php tests/Fixtures/Reporting/Prerequisites tests/Fixtures/Reporting/plan-1c-platform-completion.valid.json tests/Unit/Reporting/Evidence/ReportPrerequisiteEvidenceBundleTest.php tests/Unit/Reporting/Evidence/PlanOneCPrerequisiteEvidenceValidatorTest.php tests/Unit/Reporting/Evidence/PlanOneCPlatformEvidenceBuilderTest.php tests/Feature/Reporting/PlanOneCFakeSequenceTest.php tests/Architecture/Reporting/PlanOneCPrerequisiteContractTest.php tests/Architecture/Reporting/PlanOneCCrossPlanSymbolLockTest.php tests/Architecture/Reporting/PlanOneCScopeBoundaryTest.php .gitignore`

Run: `git add -f -- docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Run: `git commit -m "test[reports]: добавлен platform handoff Plan 1c"`

Run after commit: `git ls-files --error-unmatch docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: both exact paths, exit 0.

---

## Completion evidence и release sequence

Plan 1c platform foundation считается завершённым, когда:

1. Tasks 1–12 имеют отдельные русские Conventional Commits.
2. Local aggregate, generator check, PHPStan и scope scans проходят без skips.
3. CI PostgreSQL workspace/saved-view/subscription tests, integration suite и fake sequence имеют status `passed`.
4. Manifest содержит ровно 28 management identities, exact waves 12/10/6 и семь непустых catalog groups; M-29 находится только в official manifest.
5. Candidate validation не создаёт map; order-independent published/registered set equality сохраняет manifest order через typed ordinal и требует promotion byte lock.
6. Subscription route snapshot содержит только семь `/api/v1/admin/reports/subscriptions...` routes; delivery retries используют pinned bytes/hash/version; manual race использует non-throwing `ON CONFLICT` и healthy-transaction reread; 24-hour expiry и 90-day cleanup доказаны отдельно.
7. Plan 1a hermetic HTTP / Plan 1b post-CI completion artifacts имеют status `passed`; Plan 1a привязан к exact repository commit, обе matrix modes равны `hermetic_http`, authorization равно `22/22`, malformed равно `20/20`; exact five Plan 1a lock/schema/route/hermetic HTTP matrix digests и exact 20/20 Plan 1b gate digests bijectively совпадают с reread bytes 27-entry content-addressed prerequisite bundle.
8. Plan 1b и Plan 1c documents tracked в одном repository commit; builder сам вычисляет оба hashes из working/commit bytes. Принятый Plan 1b round 3 имеет exact SHA-256 `58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626`.
9. `build/reports/plan-1c-platform-completion.json` валиден, включает repository commit и prerequisite bundle-manifest hash, имеет status только `platform_passed`, игнорируется Git и передан master вместе с SHA-256.

`platform_passed` завершает только foundation Plan 1. Он не означает production readiness и не требует фиктивной публикации пустого каталога. Дальнейший обязательный порядок:

```text
Plan 1c platform_passed
  -> Plans 2–3: 28 candidate bindings + per-code evidence, без publication ownership
  -> Plan 1c: atomic catalog_activated, exactly 28 published definitions and 28 bindings
  -> Plan 4: generated admin contract, state/a11y/static/cutover evidence
  -> Plan 1c release builder: QG-01–QG-14 passed -> release_passed
```

Plan 4 потребляет generated JSON/TypeScript/resource locks, `ReportWorkspacePreferences`, `ReportSavedViewPage` and `ReportSubscriptionPage`; он не создаёт client-owned recent/favourites and cannot accept `platform_passed` in place of `catalog_activated`.

## Resolution table: Plan 1c review

| Finding | Resolution in this plan | Status |
|---|---|---|
| C-01 | Canonical namespace is `App\BusinessModules\Core\Reporting`; current Plan 1b round 3 is present and directly consumes the authoritative singleton published registry/map. Task 4 adds provider wiring and a real Plan 1b run/rows/drill/export bridge test. The obsolete resolver suggested from an earlier Plan 1b snapshot is deliberately not introduced. Task 12 locks Plan 1b SHA-256. | addressed |
| C-02 | Tasks 9–10 define exact Plan 1a DTO/value-object arities, pin immutable execution-input bytes/hash/subscription version per delivery, implement manual replay with non-throwing PostgreSQL `ON CONFLICT ... DO NOTHING RETURNING` plus a locked reread in the same healthy transaction, reach exactly-once `notified`, and separate 24-hour execution expiry from 90-day cleanup. | addressed |
| I-01 | Task 4 compares published and registered binding sets in both directions before freeze, independently rejects duplicate/wrong-type/unsafe codes, preserves nominal wrappers and manifest order, and tests a combined-interface object. | addressed |
| I-02 | Tasks 1–2 consume pinned `opis/json-schema` 2.6.0 through `Opis\JsonSchema\CompliantValidator`, with exact positive/negative Draft 2020-12 tests and no new dependency change. | addressed |
| I-03 | Tasks 6, 11 and 12 separate `platform_passed`, `catalog_activated` and `release_passed`; final release requires exact 28/28 and all 14 gates. | addressed |
| I-04 | Tasks 1, 2 and 6 add the closed seven-group enum, exact mapping for all 28 codes, explicit `manifestOrdinal` and byte-locked ordering/resource generation. | addressed |
| I-05 | Task 10 adds dedicated `ReportSubscriptionWindow`, `ReportSubscriptionPage`, exact eight-field `ReportSubscriptionCursor`, signed codec and `ReportSubscriptionPageResource`, with constructor/reflection tests and no `ReportPage` reuse or exact total. | addressed |
| M-01 | Task 11 makes `ReportQualityGateException` offline-only with fixed exit 2/safe STDERR and architecture-proves that it cannot enter the HTTP error/response path. | addressed |

**Review result represented by the plan:** addressed `8/8`; open `0`.

## Resolution table: Plan 1c round 2

| Finding | Resolution in this plan | Status |
|---|---|---|
| R2-C-02 | Task 9 copies canonical execution-input bytes/hash and subscription transition version into each delivery, uses only pinned input on retry, aligns the `(subscription_id, trigger_key_hash)` lookup/lock/partial unique/`ON CONFLICT` scope without failed-transaction recovery, and gives 24-hour expiry and 90-day prune different jobs/semantics. | addressed |
| R2-I-05 | Task 10 creates `Domain/DTO/ReportSubscriptionCursor.php` with exact version/scope/filter/order/position/expiry fields, constructor invariants, codec return type, unit/reflection/PHPStan/commit coverage. | addressed |
| N-C-01 | Task 10 fixes all seven full URIs to `/api/v1/admin/reports/subscriptions...` and route snapshot asserts no `/api/v1/admin/reporting` entry. | addressed |
| N-C-02 | Tasks 2, 4 and 6 make set equality order-independent, validate types/duplicates separately, preserve original registry order in the binding map, and sort catalog by explicit metadata ordinal rather than lexicographic code. | addressed |
| XC-C-01 | Task 12 unignores and force-adds exact Plan 1b/1c documents; tracked reader computes hashes from reread working/commit bytes and completion binds both to repository commit without caller-supplied plan digest. | addressed |
| XC-I-02 | Task 12 validates an exact 27-entry content-addressed Plan 1a/1b artifact bundle, bijectively maps all five Plan 1a nested digests and all 20 Plan 1b gate digests to reread bytes, and includes the bundle-manifest hash in Plan 1c completion. | addressed |

**Round-2 result represented by the plan:** addressed `6/6`; open `0`.

## Resolution table: Plan 1c round 3

| Finding | Resolution in this plan | Status |
|---|---|---|
| R3-C-02 | Task 9 specifies atomic `INSERT ... ON CONFLICT (subscription_id, trigger_key_hash) WHERE trigger_key_hash IS NOT NULL DO NOTHING RETURNING id`; a no-row result performs the locked hash/version comparison in the same healthy transaction, and four two-connection interleavings prove replay/conflict without `23505` or `25P02`. | addressed |
| R3-P1C-C-01 | Task 12 adds descriptor ID `plan-1b:pdf_renderer_budget`, exact fixture path, reread digest comparison and missing/wrong-digest/mutated-byte tests, making the Plan 1b gate set exact `20/20`. | addressed |
| R3-XC-I-02 | Task 12 adds Plan 1a contract-lock, resource-schema and route-snapshot bytes; the literal five-field Plan 1a mapping and exact 27-ID bijection tie every embedded digest to reread bytes and to the computed bundle-manifest hash. | addressed |

**Round-3 result represented by the plan:** addressed `3/3`; open `0`.

## Resolution table: activation/admin-evidence/release re-entry audit

| Finding | Resolution in this plan | Status |
|---|---|---|
| A-01 | Task 11 owns `report_catalog_activation_inputs`: a strict Draft 2020-12 builder/loader/schema/CLI/fixture/test set consumes the real Plan 2 artifact and both real Plan 3 artifacts on the post-Plan-3 freeze commit, reconstructs the two committed binding sets and all content-addressed conformance records, runs the combined validator and proves exact `12+16=28`, `28` payloads, `28/28` validations, `28` unique seven-field bindings and `28` conformance digests. Production `--check` and normal commands, output path, reread/schema/status/SHA and ignored/untracked checks are exact; candidate-only contamination fails closed. | addressed |
| A-03 | Task 11 re-enters only after Plan 3, runs exact bundle and production activation commands, atomically replaces only the tracked active manifest/ledger pair, writes ignored `catalog_activated`, commits exactly the two active paths, verifies working bytes against the activation commit, and transfers artifact plus both schemas byte-identically with an outer descriptor to the four fixed Plan 4 intake paths. The pre-activation release SHA and later activation commit SHA are distinct; the latter exists only in that ignored outer transfer descriptor, eliminating self-reference. | addressed |
| R-01 | Task 11 re-enters again only after Plan 4 Task 18 commits its strict QG-10..QG-14 admin evidence. The third closed `admin-evidence` transfer mode derives the containing commit from the two canonical ledgers, runs exact `--check` and normal commands against fixed Plan 4 source and backend destination paths, verifies commit blobs, strict schema/status/QG-10..QG-14 counts, release/activation SHAs, hashes, age, no-self-reference and ignored/untracked destination state before release gates may run. A Plan 1c-owned strict `report_release_gate_bundle` producer then consumes the named Plan 1a/1b/1c/2/3/admin/activation paths and verifies exact 14-gate statuses/counts/ages/hashes/commit refs. QG-03 mirrors the final Plan 3 closed map: `lookahead_readiness` and `supplier_award_competitiveness` belong to `waves23.process_cohort_sla`, `waves23.procurement_quantity` owns only `supply_reliability`, and `waves23.readiness_compliance` owns only `workforce_admission,handover_readiness`; the remaining five Waves 2–3 rows stay exact. The result is exact `12+8=20` family IDs, exact one-owner coverage of all 28 codes, exact Plan 3 `8` families/`4000` seeds, `>=500` seeds per family and `>=10000` total seeds. The exact release `--check` and normal commands write only ignored `release_passed`, prove no publication/mutation and transfer byte-identical release/schema/outer-ref files back to fixed Plan 4 intake paths. | addressed |

**Activation/admin-evidence/release re-entry result represented by the plan:** addressed `3/3`; open `0`.

## Exact downstream handoff requirements

- Plan 1b round 3 needs no symbol correction: its direct `ReportDefinitionRegistry` + `ReportDefinitionBindingMap` contract is authoritative; accepted plan SHA-256 is locked above.
- Plans 2–3 may create only their assigned 16/12 domain providers, candidate definitions/bindings and conformance/golden/property/performance evidence. They do not construct `PublishedReportDefinition`, write publication locks, mutate active registry bytes or activate routes.
- Plan 1c alone validates exact 28-code union and performs controlled publication/activation after Plans 2–3 evidence.
- Plan 4 must not start from a generic Plans 1–3 ledger. Its exact prerequisite is the schema-validated Plan 1c `ReportCatalogActivation` artifact plus computed SHA-256: status `catalog_activated`, matching release SHA, previous/published manifest hashes, exact 28 published codes, exact same 28 binding codes, 28 publication-lock hashes and 28 conformance hashes.
- The freeze order is exact: all tracked Plan 3 tasks → capture current clean backend `HEAD` as `BACKEND_RELEASE_SHA` → rematerialize Plan 2 Task 9 and both Plan 3 artifacts on that same freeze → build combined activation inputs → activate and commit the active pair as distinct `ACTIVATION_COMMIT_SHA` → transfer activation to Plan 4.
- The release order is exact: Plan 4 Tasks 1–17 → Task 18 admin-evidence producer/checks → commit tracked `docs/reports/admin-evidence.json` without its own commit SHA → Plan 1c runs the `admin-evidence` transfer `--check` and then the identical normal command with the ledger-derived outer commit ref → validates the three fixed backend intake files → builds the 14-gate bundle and `release_passed` → transfers release back → only then Plan 4 Task 18 consumer-finalization/cutover may resume.
- Post-window cleanup re-entry order: only after the reviewed Plan 4 Task 18C cutover commit and exact `604800`-second window may backend Task 11 materialize `report_cleanup_evidence`; the external ref is appended only to the root ledger and never changes Plan 4 cutover outputs.
- Plan 4 must consume separate `ReportSavedViewPage` and `ReportSubscriptionPage` wire parsers/resources, not rows `ReportPage`; it must consume `ReportWorkspacePreferencesResource` and typed GET workspace, record recent, set favourites and update preferences operations. These downstream changes belong only to Plan 4.
- Plan 4 provides QG-10–QG-14 evidence before Plan 1c can build `release_passed`.

## Self-review

Run this static plan audit before execution:

```powershell
$p='docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md'
$bytes=[IO.File]::ReadAllBytes($p)
$text=[Text.UTF8Encoding]::new($false,$true).GetString($bytes)
$required=@(
  'tests/Fixtures/Reporting/Publication/candidate.valid.sha256',
  'tests/Fixtures/Reporting/Publication/candidate-validation.valid.json',
  'report-candidate-validation.schema.json',
  'ReportCandidateValidationFixtureBuilder',
  '^[0-9a-f]{64}\n$',
  'including that YAML''s one terminal LF',
  'tests/Fixtures/Reporting/Quality/platform-gates.valid.json',
  'ReportPlatformGateFixtureBuilder',
  'report_platform_gate_inputs',
  'requires exactly one `--gates` option',
  'report_catalog_activation_inputs',
  'build/reports/report-catalog-activation-inputs.json',
  'plan-2-wave-1-candidate-conformance',
  'plan3_waves23_candidate_contribution',
  'plan3_waves23_evidence',
  '"plan_2_candidates": 12',
  '"plan_3_candidates": 16',
  '"passed_validations": 28',
  '"bindings": 28',
  '"conformance_records": 28',
  'formula_families=20',
  'total_seeds>=10000',
  'property_families:12',
  'property_seeds:6000',
  'property_families_sha256',
  'exact `8` property families/`4000` seeds',
  '6000+4000',
  'Freeze SHA and no-self-reference rule',
  'feat[reports]: активирован каталог из 28 отчётов',
  'report_release_gate_bundle',
  'build/reports/report-release-gate-bundle.json',
  'source_artifacts=13',
  'closed 13-row primary-path mapping',
  'plan-1c-platform-completion',
  'plan4_admin_evidence_schema',
  'report_management_catalog_active',
  'report_publication_ledger_active',
  'P2O/F0',
  'AP/AE',
  'unique `artifact_id` and unique primary `path`',
  'does not reject equal SHA-256 values globally',
  'schema_sha256` must equal the tracked file''s `bytes_sha256',
  'gates=14',
  'plan4_admin_qg10_qg14_evidence',
  'three closed modes',
  'kind=activation|admin-evidence|release',
  '--kind=admin-evidence',
  'reporting-artifact-transfer: plan4_admin_evidence artifact_transferred sha256=<64 lowercase hex>',
  'report_release_evidence_transfer',
  'report_cleanup_evidence',
  'docs/reports/contracts/report-cleanup-evidence.schema.json',
  'build/reports/report-cleanup-evidence.json',
  'REPORT_CLEANUP_WINDOW_NOT_ELAPSED',
  'rollback_window_seconds=604800',
  'Report cleanup evidence: cutover=<40hex>',
  'activation_artifact_ref',
  'artifact_path, artifact_sha256, artifact_schema_path, artifact_schema_sha256',
  'No variable or shell state is inherited across Markdown fences or tool invocations',
  'identical argument bytes except for `--check`',
  'Plan 4 admin evidence commit: <40 lowercase hex>',
  'never performs an ancestry comparison between `ACTIVATION_COMMIT_SHA` and `PLAN4_ADMIN_EVIDENCE_COMMIT_SHA`',
  'gitCommitResolverCalls',
  'build/reports/intake/plan-4-admin-evidence.json',
  'build/reports/intake/contracts/report-admin-evidence.schema.json',
  'build/reports/intake/plan-4-admin-evidence.transfer.json',
  'tests/Fixtures/Reporting/Quality/report-release-evidence-transfer.valid.json',
  'qg14_forbidden_symbols',
  'exactly seven command records overall',
  'admin_forbidden_symbol_matches',
  'backend_forbidden_symbol_matches',
  'combined_forbidden_symbol_matches',
  'qg14_admin_sha256',
  'qg14_backend_sha256',
  'qg14_combined_sha256',
  'JointQG14EvidenceSource',
  'FixedRootJointQG14EvidenceSource',
  'QG-01..QG-09=backend',
  'QG-10..QG-13=admin',
  'QG-14=both',
  'backend=9',
  'admin=4',
  'joint=1',
  'sole joint-count bucket member is gate `QG-14`',
  'QG-06 remains the backend API contract',
  'descriptor is written last',
  'report-quality-evidence: release_passed sha256=<64 lowercase hex>',
  'report-release-evidence.transfer.json',
  'Activation/admin-evidence/release re-entry result represented by the plan'
)
$missing=$required | Where-Object { -not $text.Contains($_) }
if ($missing) { throw "Missing re-entry markers: $($missing -join ', ')" }
$selfReviewStart=$text.IndexOf('## Self-review',[StringComparison]::Ordinal)
$contractText=$text.Substring(0,$selfReviewStart)
foreach ($ownershipToken in @(
  'QG-01..QG-09=backend',
  'QG-10..QG-13=admin',
  'QG-14=both',
  'ownership `backend=9`, `admin=4`, `joint=1`',
  'sole joint-count gate is QG-14 with serialized owner `both`',
  'QG-06 remains backend-owned'
)) {
  if (-not $contractText.Contains($ownershipToken)) {
    throw "Missing canonical release-owner contract token: $ownershipToken"
  }
}
foreach ($downstreamToken in @(
  'valid commit-bound Plan 1a hermetic HTTP completion and Plan 1b post-CI completion',
  'Plan 1a hermetic HTTP / Plan 1b post-CI completion artifacts',
  'both matrix `verification_mode` values exactly `hermetic_http`',
  'authorization exactly `22/22`',
  'malformed requests exactly `20/20`',
  '`verification_mode=production_topology_snapshot`',
  'exact closed ordered `topology={global_middleware,api_middleware}`',
  'raw methods `["GET","HEAD"]` for all four GET routes',
  'exact descriptor count `27` remain unchanged',
  '"bundle_descriptor_count": 27'
)) {
  if (-not $contractText.Contains($downstreamToken)) {
    throw "Missing honest downstream contract token: $downstreamToken"
  }
}
$staleDownstreamTokens=@(
  ('Plan 1a and Plan 1b '+'post-CI completion'),
  ('Plan 1a/1b '+'post-CI completion'),
  ('Plan 1a lock/schema/route/'+'CI digests'),
  ('nested '+'CI hash'),
  ('bootstrapped_'+'router'),
  ('bootstrapped '+'router')
)
foreach ($staleDownstreamToken in $staleDownstreamTokens) {
  if ($contractText.IndexOf($staleDownstreamToken,[StringComparison]::OrdinalIgnoreCase) -ge 0) {
    throw "Stale Plan 1a downstream terminology: $staleDownstreamToken"
  }
}
$mappingStart=$contractText.IndexOf('The Plan 1a completion-to-descriptor mapping is closed and literal:',[StringComparison]::Ordinal)
$mappingEnd=$contractText.IndexOf('`PlanOneCPrerequisiteEvidenceValidator::validateBundle',$mappingStart,[StringComparison]::Ordinal)
$mappingText=$contractText.Substring($mappingStart,$mappingEnd-$mappingStart)
$expectedPlanOneAMappings=@(
  '| `contract_lock_sha256` | `plan-1a-contract-lock` | `artifacts/plan-1a-contract-lock.json` |',
  '| `resource_schema_sha256` | `plan-1a-resource-schema` | `artifacts/plan-1a-resource-schema.json` |',
  '| `route_snapshot_sha256` | `plan-1a-route-snapshot` | `artifacts/plan-1a-route-snapshot.json` |',
  '| `ci_http_matrices.authorization.artifact_sha256` | `plan-1a-ci-authorization` | `artifacts/plan-1a-ci-authorization.json` |',
  '| `ci_http_matrices.malformed_requests.artifact_sha256` | `plan-1a-ci-malformed` | `artifacts/plan-1a-ci-malformed.json` |'
)
$actualPlanOneAMappings=[regex]::Matches($mappingText,'(?m)^\| `[^`\r\n]+` \| `[^`\r\n]+` \| `[^`\r\n]+` \|$')
if ($actualPlanOneAMappings.Count -ne 5) {
  throw "Expected exact five Plan 1a literal mappings, got $($actualPlanOneAMappings.Count)"
}
foreach ($mapping in $expectedPlanOneAMappings) {
  if ([regex]::Matches($mappingText,[regex]::Escape($mapping)).Count -ne 1) {
    throw "Missing or duplicate Plan 1a literal mapping: $mapping"
  }
}
$staleTransferTokens=@(
  ('two closed '+'modes'),
  ('two '+'modes'),
  ('kind=activation'+'|release'),
  ('formula_families='+'28')
)
$staleTransferPattern=($staleTransferTokens | ForEach-Object { [regex]::Escape($_) }) -join '|'
$staleTransfer=[regex]::Matches($text,$staleTransferPattern)
if ($staleTransfer.Count -ne 0) { throw "Stale two-mode marker count: $($staleTransfer.Count)" }
$tasks=[regex]::Matches($text,'(?m)^### Task \d+:')
if ($tasks.Count -ne 12) { throw "Expected 12 tasks, got $($tasks.Count)" }
$ownershipRows=[regex]::Matches(
  $text,
  '(?m)^\| QG-(?<number>\d{2}) \|[^\r\n]*\| owner `(?<owner>backend|admin|both)` — [^\r\n]+\|$'
)
if ($ownershipRows.Count -ne 14) { throw "Expected exact 14 release-owner rows, got $($ownershipRows.Count)" }
$expectedOwnership=@{}
1..9 | ForEach-Object { $expectedOwnership['{0:D2}' -f $_]='backend' }
10..13 | ForEach-Object { $expectedOwnership['{0:D2}' -f $_]='admin' }
$expectedOwnership['14']='both'
foreach ($row in $ownershipRows) {
  $number=$row.Groups['number'].Value
  $owner=$row.Groups['owner'].Value
  if (-not $expectedOwnership.ContainsKey($number) -or $owner -ne $expectedOwnership[$number]) {
    throw "Wrong release owner for QG-$number`: $owner"
  }
}
$ownerCounts=@($ownershipRows | Group-Object { $_.Groups['owner'].Value })
$backendCount=@($ownershipRows | Where-Object { $_.Groups['owner'].Value -eq 'backend' }).Count
$adminCount=@($ownershipRows | Where-Object { $_.Groups['owner'].Value -eq 'admin' }).Count
$bothCount=@($ownershipRows | Where-Object { $_.Groups['owner'].Value -eq 'both' }).Count
if ($backendCount -ne 9 -or $adminCount -ne 4 -or $bothCount -ne 1 -or $ownerCounts.Count -ne 3) {
  throw "Wrong release-owner counts: backend=$backendCount admin=$adminCount both=$bothCount"
}
$qg06Row=@($ownershipRows | Where-Object { $_.Groups['number'].Value -eq '06' })
if ($qg06Row.Count -ne 1 -or
    $qg06Row[0].Groups['owner'].Value -ne 'backend' -or
    $qg06Row[0].Value -match '(?i)\bjoint\b') {
  throw 'QG-06 must be backend-owned and must not carry joint-owner wording'
}
$qg14Row=@($ownershipRows | Where-Object { $_.Groups['number'].Value -eq '14' })
if ($qg14Row.Count -ne 1 -or $qg14Row[0].Groups['owner'].Value -ne 'both') {
  throw 'QG-14 must be the sole serialized both-owner gate'
}
$staleOwnershipTokens=@(
  ('backend='+'8'),
  ('admin='+'5'),
  ('QG-06='+'joint'),
  ('QG06='+'joint'),
  ('joint=1'+'` for QG-'+'06'),
  ('joint-count bucket member is gate `QG-'+'06`')
)
$staleOwnershipPattern=($staleOwnershipTokens | ForEach-Object { [regex]::Escape($_) }) -join '|'
$staleOwnership=[regex]::Matches($text,$staleOwnershipPattern,[Text.RegularExpressions.RegexOptions]::IgnoreCase)
if ($staleOwnership.Count -ne 0) { throw "Stale release-owner marker count: $($staleOwnership.Count)" }
$familyRows=[regex]::Matches($text,'(?m)^\| `(?<family>(?:wave1|waves23)\.[^`]+)` \| (?<codes>.+) \|$')
if ($familyRows.Count -ne 20) { throw "Expected 20 QG-03 families, got $($familyRows.Count)" }
if (@($familyRows | Where-Object { $_.Groups['family'].Value.StartsWith('wave1.') }).Count -ne 12) { throw 'Expected 12 Wave 1 families' }
if (@($familyRows | Where-Object { $_.Groups['family'].Value.StartsWith('waves23.') }).Count -ne 8) { throw 'Expected 8 Waves 2-3 families' }
$expectedWaves23=[ordered]@{
  'waves23.allocation_finance'=@('holding_performance','intercompany_contract_flows','change_claim_contingency')
  'waves23.evm'=@('project_evm_control')
  'waves23.process_cohort_sla'=@('lookahead_readiness','procurement_cycle','supplier_award_competitiveness','quality_defect_flow','safety_incident_actions','customer_sla')
  'waves23.procurement_quantity'=@('supply_reliability')
  'waves23.inventory_recurrence'=@('inventory_risk')
  'waves23.readiness_compliance'=@('workforce_admission','handover_readiness')
  'waves23.accepted_production'=@('accepted_production_progress')
  'waves23.component_scorecard'=@('contractor_scorecard')
}
foreach ($entry in $expectedWaves23.GetEnumerator()) {
  $row=@($familyRows | Where-Object { $_.Groups['family'].Value -eq $entry.Key })
  if ($row.Count -ne 1) { throw "Expected one QG-03 row for $($entry.Key), got $($row.Count)" }
  $actualCodes=@([regex]::Matches($row[0].Groups['codes'].Value,'`(?<code>[a-z0-9_]+)`') | ForEach-Object { $_.Groups['code'].Value })
  if (($actualCodes -join ',') -ne ($entry.Value -join ',')) { throw "Wrong ordered QG-03 ownership for $($entry.Key)" }
}
$ownedCodes=@($familyRows | ForEach-Object {
  [regex]::Matches($_.Groups['codes'].Value,'`(?<code>[a-z0-9_]+)`') | ForEach-Object { $_.Groups['code'].Value }
})
if ($ownedCodes.Count -ne 28 -or @($ownedCodes | Sort-Object -Unique).Count -ne 28) { throw 'QG-03 must own each of 28 report codes exactly once' }
$expectedCodes=@(
  'project_portfolio_health','portfolio_liquidity','baseline_schedule_variance',
  'project_margin','budget_plan_fact','wip_completion_forecast',
  'contract_settlement_exposure','management_pnl','workforce_capacity',
  'attendance_execution','project_labor_cost','payroll_readiness',
  'holding_performance','intercompany_contract_flows','project_evm_control',
  'lookahead_readiness','procurement_cycle','supplier_award_competitiveness',
  'quality_defect_flow','safety_incident_actions','workforce_admission',
  'contractor_scorecard','accepted_production_progress','change_claim_contingency',
  'supply_reliability','inventory_risk','handover_readiness','customer_sla'
)
if (Compare-Object ($expectedCodes | Sort-Object) ($ownedCodes | Sort-Object)) { throw 'QG-03 code union differs from canonical 28' }
$expectedFamilies=@($expectedCodes[0..11] | ForEach-Object { "wave1.$_" }) + @(
  'waves23.allocation_finance','waves23.evm','waves23.process_cohort_sla',
  'waves23.procurement_quantity','waves23.inventory_recurrence',
  'waves23.readiness_compliance','waves23.accepted_production',
  'waves23.component_scorecard'
)
$actualFamilies=@($familyRows | ForEach-Object { $_.Groups['family'].Value })
if (Compare-Object ($expectedFamilies | Sort-Object) ($actualFamilies | Sort-Object)) { throw 'QG-03 family IDs differ from closed 12+8 contract' }
$ordered=@(
  'Mandatory combined activation-input producer',
  'Exact controlled activation re-entry, only after Plan 3',
  'Exact activation handoff to Plan 4',
  'Exact Plan 4 admin-evidence handoff to backend',
  'Exact release re-entry, only after Plan 4 Task 18 admin-evidence phase',
  'Plan 4 Task 18 consumer-finalization',
  'Post-window cleanup re-entry order'
)
$cursor=-1
foreach ($marker in $ordered) {
  $next=$text.IndexOf($marker,$cursor+1,[StringComparison]::Ordinal)
  if ($next -lt 0 -or $next -le $cursor) { throw "Wrong re-entry order at: $marker" }
  $cursor=$next
}
$fences=[regex]::Matches($text,'(?m)^```').Count
if (($fences % 2) -ne 0) { throw "Unbalanced fences: $fences" }
$bashFences=[regex]::Matches($text,'(?ms)^```bash\r?\n(?<body>.*?)^```\s*$')
foreach ($fence in $bashFences) {
  $body=$fence.Groups['body'].Value
  $consumed=@([regex]::Matches($body,'\$(?<name>[A-Z][A-Z0-9_]*)') | ForEach-Object { $_.Groups['name'].Value } | Sort-Object -Unique)
  foreach ($name in $consumed) {
    if (-not [regex]::IsMatch($body,"(?m)^\s*$([regex]::Escape($name))=")) {
      throw "Bash fence consumes $name without same-fence assignment"
    }
  }
}
$releaseRows=[regex]::Matches($text,'(?m)^\| `(?<path>(?:build/reports|app/BusinessModules/Core/Reporting/resources)/[^`]+)` \| `(?<id>[a-z0-9_-]+)` \| `(?<kind>ancestor_evidence|release_evidence|transfer|tracked_file)` \|')
$expectedReleaseIds=@(
  'plan-1a-completion','plan-1b-completion','plan-1c-platform-completion',
  'plan-2-wave-1-candidate-conformance','plan3_waves23_candidate_contribution',
  'plan3_waves23_evidence','report_catalog_activation_inputs','report_catalog_activation',
  'plan4_admin_qg10_qg14_evidence','plan4_admin_evidence_schema',
  'plan4_admin_evidence_transfer','report_management_catalog_active',
  'report_publication_ledger_active'
)
if ($releaseRows.Count -ne 13) { throw "Expected exact 13 release-source rows, got $($releaseRows.Count)" }
$actualReleaseIds=@($releaseRows | ForEach-Object { $_.Groups['id'].Value })
if (($actualReleaseIds -join ',') -ne ($expectedReleaseIds -join ',')) { throw 'Wrong ordered release-source artifact IDs' }
if (@($actualReleaseIds | Sort-Object -Unique).Count -ne 13) { throw 'Release-source artifact IDs must be unique' }

$task11Start=$text.IndexOf('### Task 11:',[StringComparison]::Ordinal)
$task12Start=$text.IndexOf('### Task 12:',$task11Start,[StringComparison]::Ordinal)
$task11Text=$text.Substring($task11Start,$task12Start-$task11Start)
$releaseTransferFixture='tests/Fixtures/Reporting/Quality/report-release-evidence-transfer.valid.json'
if (-not $task11Text.Contains("- Create: ``$releaseTransferFixture``")) {
  throw 'Task 11 does not own the release-transfer fixture as Create'
}
$fixtureStageLines=[regex]::Matches(
  $task11Text,
  "(?m)^Run: ``git add -- [^\r\n]*$([regex]::Escape($releaseTransferFixture))[^\r\n]*``$"
)
if ($fixtureStageLines.Count -ne 1) {
  throw "Expected the exact release-transfer fixture in one Task 11 foundation stage command, got $($fixtureStageLines.Count)"
}
if ([regex]::IsMatch(
  $task11Text,
  '(?m)^Run: `git add -- [^\r\n]*(?<![A-Za-z0-9_./-])tests/Fixtures/Reporting/Quality(?=(?:\s|`|$))'
)) {
  throw 'Broad Quality fixture-directory staging makes Task 11 ownership ambiguous'
}

$qg14Argv='["node","scripts/verify-reporting-cutover.mjs",' +
  '"--admin-root=C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/admin",' +
  '"--backend-root=C:/Users/kamilgaraev/Desktop/prohelper_full/.worktrees/reports-canonical/backend"]'
if ([regex]::Matches($text,[regex]::Escape($qg14Argv)).Count -ne 1) {
  throw 'Expected one exact joint QG-14 argv contract'
}
$aggregateOnlyToken='forbidden_symbol_matches'+'=0'
$standaloneAggregate=[regex]::Matches(
  $text,
  "(?<![a-z_])$([regex]::Escape($aggregateOnlyToken))(?![a-z_])"
)
if ($standaloneAggregate.Count -ne 0) {
  throw 'Aggregate-only QG-14 count remains in the Plan 1c contract'
}
$qg14SectionStart=$text.IndexOf('The QG-14 contract is one indivisible two-repository command',[StringComparison]::Ordinal)
$qg14SectionEnd=$text.IndexOf('**Exact Plan 4 admin-evidence handoff to backend:**',$qg14SectionStart,[StringComparison]::Ordinal)
$qg14Text=$text.Substring($qg14SectionStart,$qg14SectionEnd-$qg14SectionStart)
foreach ($token in @(
  'command_ids` is exactly `["qg14_forbidden_symbols"]',
  'combined_forbidden_symbol_matches === admin_forbidden_symbol_matches + backend_forbidden_symbol_matches',
  'qg14_admin_sha256` is recomputed from the canonical raw admin scan section',
  'qg14_backend_sha256` from the canonical raw backend scan section',
  'qg14_combined_sha256` from the canonical ordered two-section output',
  'an eighth command record'
)) {
  if (-not $qg14Text.Contains($token)) { throw "Missing exact joint QG-14 invariant: $token" }
}
```

Expected: no exception; UTF-8 decoding is strict, task count remains exactly `12`, every activation/admin-evidence/release marker exists and the re-entry sequence is ordered.

Run: `rg -n "report_catalog_activation_inputs|activation_inputs_passed|12\\+16=28|catalog_activated|report_release_gate_bundle|release_gates_passed|release_passed|ACTIVATION_COMMIT_SHA|admin_evidence_commit_sha" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: all lifecycle tokens appear only in Task 11, its resolution/downstream handoff, completion sequence or this static check; no task outside Task 11 owns a producer or mutation.

Run: `rg -n "report-catalog-activation-input-bundle.schema.json|report-release-gate-bundle.schema.json|reporting-artifact-transfer.schema.json|additionalProperties:false|unevaluatedProperties:false" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: all three new strict schemas and recursive closure rules are present.

Run: `rg -n "candidate.valid.sha256|candidate-validation.valid.json|report-candidate-validation.schema.json|ReportCandidateValidationFixtureBuilder|platform-gates.valid.json|ReportPlatformGateFixtureBuilder|report_platform_gate_inputs|exactly one .*--gates" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: Task 5 owns both candidate provenance files plus their strict validator-derived contract, and Task 11 owns the deterministic catalog/source-derived platform gate fixture with mandatory `--gates`.

Run: `rg -n "formula_families=20|12\\+8=20|total_seeds>=10000|every code assigned exactly once" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: QG-03 has the closed Plan 2/Plan 3 `12+8` family ownership map, exact one-owner union of 28 report codes, per-family minimum `500` and total minimum `10000`; no obsolete 28-family marker exists.

Run: `rg -n "BACKEND_RELEASE_SHA.*git rev-parse HEAD|ACTIVATION_COMMIT_SHA.*git rev-parse HEAD|never try to contain their own future commit SHA|no field for its own containing commit SHA" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: freeze identity is derived from the clean pre-activation `HEAD`, activation/admin containing commits are external refs, and no committed evidence requires its own commit SHA.

Run: `rg -n "build-report-catalog-activation-inputs.php|activate-report-catalog.php|build-report-release-gate-bundle.php|build-report-quality-evidence.php|transfer-reporting-artifact.php" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: each production producer has an explicit `--check` and normal-mode invocation in Task 11; all three transfer modes are executable, with activation ordered before Plan 4, admin-evidence ordered after its Plan 4 commit and release ordered after the verified admin-evidence intake.

Run: `rg -n "three closed modes|--kind=admin-evidence|plan4_admin_evidence artifact_transferred|Plan 4 admin evidence commit|plan-4-admin-evidence.transfer.json" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: the third closed mode has exact ledger provenance, `--check` and normal commands, deterministic stdout, fixed ignored destinations and a descriptor-last handoff before the release producer.

Run: `rg -n "repositories are separate Git object graphs|never performs an ancestry comparison|gitCommitResolverCalls|PLAN4_PRODUCER_COMMIT_SHA.*PLAN4_ADMIN_EVIDENCE_COMMIT_SHA|BACKEND_RELEASE_SHA.*ACTIVATION_COMMIT_SHA" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: admin/producer ancestry is verified only in Plan 4, release/activation ancestry only in backend, and no activation↔admin cross-repository ancestry check exists.

Run: `rg -n "closed 13-row primary-path mapping|build/reports/plan-2-wave-1-evidence.json.*P2O|report-catalog-activation.json.*A1|plan-4-admin-evidence.json.*AP/AE|unique .*artifact_id.*primary .*path|does not reject equal SHA-256 values globally|schema_sha256.*bytes_sha256" docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`

Expected: every release source path has one explicit kind/repository/commit authority, classification is not inferred, and the intentional admin-schema digest link is required instead of globally rejected.

Run: `$markers=@(('TO'+'DO'),('FIX'+'ME'),('T'+'BD'),('place'+'holder'),('temporary'+' stub'),('compatibility'+' fallback')); Get-ChildItem app/BusinessModules/Core/Reporting,docs/reports,scripts/reporting,tests/Unit/Reporting,tests/Architecture/Reporting -Recurse -File | Select-String -Pattern $markers`

Expected: empty output.

Run: `$stale=@(('PublishedReport'+'ExecutionResolver'),('REPORT_GATE_'+'EVIDENCE_MISSING'),('SavedViewWindow' + ' $window): ReportPage'),('plan-1c-'+'completion.json')); Select-String -LiteralPath docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md -Pattern $stale`

Expected: empty output.

Run: `rg -n "ReportDefinitionBindingMap" app/BusinessModules/Core/Reporting/Application/Catalog/StrictReportDefinitionCandidateValidator.php`

Expected: empty output.

Run: `rg -n "organization_id|owner_id" app/BusinessModules/Core/Reporting/Http/Admin/Requests`

Expected: only prohibited rules.

Run: `rg -n "DB::|Model::|FileService|dispatch\\(" app/BusinessModules/Core/Reporting/Http/Admin/Controllers`

Expected: empty output.

Run: `rg -n "official_material_usage_m29" app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml`

Expected: empty output.

Run: `git diff --check`

Expected: empty output.

Run: `(Get-FileHash -Algorithm SHA256 docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md).Hash.ToLowerInvariant()`

Expected: one new `64`-character lowercase SHA-256 recorded in the master audit after the final saved bytes; the plan never embeds that digest into itself.

Run: `git status --short`

Expected after Task 12 commit: clean; generated `build/reports` evidence is ignored and untracked.

Expected after activation, admin-evidence and release re-entry: tracked trees are clean after the two-file activation commit; all activation-input, activation, admin-evidence intake, release-gate, release and transfer artifacts remain ignored/untracked.

## Handoff

Plan saved at `docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md`. Execute Tasks 1–12 sequentially. Any change to canonical Plan 1a signatures, Plan 1b direct registry/map consumption, the locked Plan 1b SHA-256, 28 identities, seven groups or phase statuses requires stopping for master review rather than creating an adaptive local protocol.
