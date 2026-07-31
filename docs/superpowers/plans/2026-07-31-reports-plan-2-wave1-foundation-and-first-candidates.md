# Reports Plan 2 Wave 1 Foundation And First Candidates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an isolated Wave 1 candidate boundary and source-backed implementations for the first four reports without activating or publishing any report.

**Architecture:** A separate candidate manifest fixes the immutable identities of 12 Wave 1 candidates and is excluded from `management-catalog.v1.yaml`, `ReportDefinitionRegistry`, and runtime execution. `WaveOneCandidateBindingSet` is the verifiable ordering and status contract: four bindings receive real provider triples, while the remaining eight have explicit source-contract status only. Providers use the existing Budgeting services through narrow adapters and prove cursors, scope, redaction, fixtures, and property evidence through the existing `ReportSourceConformanceHarness`.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, Larastan/PHPStan, Symfony YAML, existing `App\BusinessModules\Core\Reporting` contracts, deterministic PHP fixtures.

## Global Constraints

- Work only in a separate branch, never in `main`; do not modify `management-catalog.v1.yaml`, the publication ledger, activation services, or the published registry.
- New PHP files use `declare(strict_types=1);`, PSR-12, and namespace `App\BusinessModules\Core\Reporting`.
- Do not run migrations, DB CLI commands, tinker, seeders, a development server, or a build; Wave 1 tests require no local database.
- Do not create synthetic source data, fake providers, or calculations in production code for codes without an approved source contract.
- `ReportDefinitionBindingAssembler::register()` and `assemble()` remain the only published runtime binding protocol; the Wave 1 set is not registered there.
- Every provider uses actor, organization, and project scope from `ReportExecutionContext`; a request cannot set `organization_id` or `owner_id`.
- Results containing financial or counterparty fields must be redacted before serialization and before the conformance-fixture digest.
- The candidate manifest has only `candidate` publication; this work creates no active or published activation, HTTP route, or UI.
- Every property family supplies 500 deterministic seeds; the four implemented families supply at least 2,000 verified seeds.

## Closed Wave 1 Identity Contract

| Ordinal | ID | Code | Family | Source status |
| ---: | --- | --- | --- | --- |
| 1 | G01 | `project_portfolio_health` | `wave1.project_portfolio_health` | implemented |
| 2 | G04 | `portfolio_liquidity` | `wave1.portfolio_liquidity` | implemented |
| 3 | G06 | `baseline_schedule_variance` | `wave1.baseline_schedule_variance` | source contract required |
| 4 | G09 | `project_margin` | `wave1.project_margin` | implemented |
| 5 | G10 | `budget_plan_fact` | `wave1.budget_plan_fact` | implemented |
| 6 | G11 | `wip_completion_forecast` | `wave1.wip_completion_forecast` | source contract required |
| 7 | G12 | `contract_settlement_exposure` | `wave1.contract_settlement_exposure` | source/formula contract required |
| 8 | G13 | `management_pnl` | `wave1.management_pnl` | source/formula contract required |
| 9 | G21 | `workforce_capacity` | `wave1.workforce_capacity` | source contract required |
| 10 | G22 | `attendance_execution` | `wave1.attendance_execution` | source contract required |
| 11 | G23 | `project_labor_cost` | `wave1.project_labor_cost` | source contract required |
| 12 | G24 | `payroll_readiness` | `wave1.payroll_readiness` | source contract required |

The literal order in this table is the only valid order for the manifest, `WaveOneCandidateBindingSet::ordered()`, fixture records and evidence output.

### Task 1: Candidate-only manifest and exact identity set

**Files:**

- Create: `app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.yaml`
- Create: `app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.schema.json`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidate.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateManifest.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/YamlWaveOneCandidateManifestLoader.php`
- Test: `tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php`

**Interfaces:**

- Consumes: `Draft202012SchemaValidator::assertValid(object $document, object $schema, string $schemaId): void` and `Sha256Hash`.
- Produces: `WaveOneCandidateManifest::ordered(): array`, where every item is `WaveOneCandidate`; `WaveOneCandidate` has readonly `ordinal`, `groupId`, `code`, `family`, `sourceStatus` and `publication` fields.

- [ ] **Step 1: Write the failing manifest test**

```php
$manifest = $this->loader->load($path, $schemaPath);

self::assertSame(
    ['G01','G04','G06','G09','G10','G11','G12','G13','G21','G22','G23','G24'],
    array_map(static fn (WaveOneCandidate $item): string => $item->groupId, $manifest->ordered()),
);
self::assertSame('candidate', $manifest->ordered()[0]->publication);
```

- [ ] **Step 2: Run the focused test and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php`

Expected: FAIL because the candidate manifest loader and DTOs do not exist.

- [ ] **Step 3: Add the closed YAML/schema and loader**

```yaml
catalog: wave-1-candidates.v1
contract_version: 1.0.0
candidates:
  - {ordinal: 1, group_id: G01, code: project_portfolio_health, family: wave1.project_portfolio_health, source_status: implemented, publication: candidate}
```

The schema requires exactly 12 unique rows, `ordinal` from 1 through 12 in literal order, the 12 literal `group_id`/`code`/`family` triples in the table, and `publication: candidate`. It forbids `published`, `active`, `readiness`, provider class names and unknown fields.

- [ ] **Step 4: Run the focused test and static check**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php; vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidate.php app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateManifest.php app/BusinessModules/Core/Reporting/Infrastructure/Catalog/YamlWaveOneCandidateManifestLoader.php`

Expected: PASS; the manifest contains exactly the closed 12 identities and PHPStan reports no errors.

- [ ] **Step 5: Commit the candidate identity boundary**

Run: `git add -- app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.yaml app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.schema.json app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidate.php app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateManifest.php app/BusinessModules/Core/Reporting/Infrastructure/Catalog/YamlWaveOneCandidateManifestLoader.php tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php; git commit -m "feat[reports]: add Wave 1 candidate manifest"`

### Task 2: Binding-set contract with explicit unavailable sources

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateBinding.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php`
- Create: `docs/reports/wave-1-source-contracts.md`
- Test: `tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

**Interfaces:**

- Consumes: `WaveOneCandidateManifest::ordered(): array`.
- Produces: `WaveOneCandidateBindingSet::__construct(WaveOneCandidateManifest $manifest, iterable $bindings)`, `ordered(): array`, `implemented(): array`; statuses are `implemented` and `blocked_by_source_contract`.

- [ ] **Step 1: Write failing exact-order and no-fake-provider tests**

```php
$set = new WaveOneCandidateBindingSet($manifest, $bindings);

self::assertSame($expectedCodes, array_map(static fn (WaveOneCandidateBinding $binding): string => $binding->code, $set->ordered()));
self::assertCount(4, $set->implemented());
self::assertSame('blocked_by_source_contract', $set->ordered()[6]->status->value);
self::assertNull($set->ordered()[6]->provider);
```

- [ ] **Step 2: Run the test and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

Expected: FAIL because `WaveOneCandidateBindingSet` is absent.

- [ ] **Step 3: Implement set validation and source-contract document**

```php
public function ordered(): array
{
    return $this->bindings;
}

public function implemented(): array
{
    return array_values(array_filter($this->bindings, static fn (WaveOneCandidateBinding $binding): bool => $binding->status === WaveOneCandidateBindingStatus::IMPLEMENTED));
}
```

Reject duplicate, missing, reordered, or extra codes. Require a non-null `ReportDataProvider` only for the four implemented rows, and require a `null` provider for every blocked row. In `docs/reports/wave-1-source-contracts.md`, record for G06, G11, and G21-G24 the upstream owner, required source fields, grain, scope key, freshness rule, and acceptance test; record G12/G13 separately with formula inputs, sign convention, period-close rule, and the same acceptance fields. The document calls these rows blocked by an explicit source contract, not executable reports.

- [ ] **Step 4: Run tests and confirm the candidate set cannot enter runtime binding**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php; rg -n "WaveOneCandidateBindingSet|register\(" app/BusinessModules/Core/Reporting`

Expected: PASS; search finds no `register(` call on `WaveOneCandidateBindingSet` and no candidate-to-published conversion.

- [ ] **Step 5: Commit the candidate binding contract**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateBinding.php app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php docs/reports/wave-1-source-contracts.md tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php; git commit -m "feat[reports]: add Wave 1 binding contract"`

### Task 3: Source adapters for G01 and G04

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/ProjectPortfolioHealthReportDataProvider.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/PortfolioLiquidityReportDataProvider.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Conformance/WaveOneReportProviderTriple.php`
- Test: `tests/Unit/Reporting/Providers/WaveOne/ProjectPortfolioHealthReportDataProviderTest.php`
- Test: `tests/Unit/Reporting/Providers/WaveOne/PortfolioLiquidityReportDataProviderTest.php`

**Interfaces:**

- Consumes: `ReportDataProvider`, `ReportExecutionContext`, `CfoProjectPortfolioAggregator`, `CashGapForecastReadService`.
- Produces: providers implementing the existing `ReportDataProvider::materialize()` and `result()` methods, plus `WaveOneReportProviderTriple` with `code`, `provider`, `rowQuery` and `drillDownProvider`.

- [ ] **Step 1: Write failing adapter tests using existing service return fixtures**

```php
$result = $provider->result($context, $snapshot);

self::assertSame('project_portfolio_health', $result->code);
self::assertSame([17, 18], $result->projectIds());
self::assertNotContains('counterparty_bank_account', array_keys($result->rows()[0]));
```

- [ ] **Step 2: Run focused provider tests and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Providers/WaveOne/ProjectPortfolioHealthReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/PortfolioLiquidityReportDataProviderTest.php`

Expected: FAIL because the two provider classes are absent.

- [ ] **Step 3: Implement adapters without new calculations**

```php
public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
{
    return $this->redactor->redact(
        $this->resultFactory->fromPortfolio($this->aggregator->aggregate($context->scope)),
        $context,
    );
}
```

Map only values supplied by `CfoProjectPortfolioAggregator` for G01 and `CashGapForecastReadService` for G04. The source mapping document specifies service method, source fields, as-of timestamp, cursor tuple `(as_of, project_id)`, allowed project IDs, and removed sensitive fields. Each triple uses the existing row and drill-down ports; no controller or direct model query is introduced.

- [ ] **Step 4: Run adapter and conformance tests**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Providers/WaveOne/ProjectPortfolioHealthReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/PortfolioLiquidityReportDataProviderTest.php tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php`

Expected: PASS; row ordering is stable, cursor continuation has no duplicates, tenant-project filtering is preserved and sensitive fields are absent.

- [ ] **Step 5: Commit the first two source-backed candidates**

Run: `git add -- app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/ProjectPortfolioHealthReportDataProvider.php app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/PortfolioLiquidityReportDataProvider.php app/BusinessModules/Core/Reporting/Application/Conformance/WaveOneReportProviderTriple.php tests/Unit/Reporting/Providers/WaveOne/ProjectPortfolioHealthReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/PortfolioLiquidityReportDataProviderTest.php docs/reports/wave-1-source-contracts.md; git commit -m "feat[reports]: add G01 and G04 sources"`

### Task 4: Source adapters for G09 and G10

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/ProjectMarginReportDataProvider.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/BudgetPlanFactReportDataProvider.php`
- Test: `tests/Unit/Reporting/Providers/WaveOne/ProjectMarginReportDataProviderTest.php`
- Test: `tests/Unit/Reporting/Providers/WaveOne/BudgetPlanFactReportDataProviderTest.php`
- Modify: `docs/reports/wave-1-source-contracts.md`

**Interfaces:**

- Consumes: `ReportDataProvider`, `ProjectMarginCalculator`, `PlanFactCalculator`, the triple factory from Task 3.
- Produces: real G09/G10 `ReportDataProvider` implementations and four total `implemented` bindings in `WaveOneCandidateBindingSet`.

- [ ] **Step 1: Write failing formula-preservation tests**

```php
self::assertSame('project_margin', $provider->code());
self::assertSame($calculatorResult->marginPercent, $result->rows()[0]['margin_percent']);
self::assertSame($calculatorResult->variance, $result->rows()[0]['variance']);
self::assertArrayNotHasKey('internal_cost_breakdown', $result->rows()[0]);
```

- [ ] **Step 2: Run focused tests and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Providers/WaveOne/ProjectMarginReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/BudgetPlanFactReportDataProviderTest.php`

Expected: FAIL because G09/G10 providers are absent.

- [ ] **Step 3: Implement projection-only adapters and exact formula references**

The G09 adapter invokes only `ProjectMarginCalculator`; the G10 adapter invokes only `PlanFactCalculator`. Document input field names, calculator result fields, null/zero handling inherited from each calculator, period selection, deterministic `(period_end, project_id)` cursor and redacted columns. The adapters must not recompute percentage, variance, plan or fact values.

- [ ] **Step 4: Run tests and validate all four real bindings**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Providers/WaveOne/ProjectMarginReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/BudgetPlanFactReportDataProviderTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

Expected: PASS; G01, G04, G09 and G10 are the only bindings with a provider.

- [ ] **Step 5: Commit the second two source-backed candidates**

Run: `git add -- app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/ProjectMarginReportDataProvider.php app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne/BudgetPlanFactReportDataProvider.php tests/Unit/Reporting/Providers/WaveOne/ProjectMarginReportDataProviderTest.php tests/Unit/Reporting/Providers/WaveOne/BudgetPlanFactReportDataProviderTest.php docs/reports/wave-1-source-contracts.md; git commit -m "feat[reports]: add G09 and G10 sources"`

### Task 5: Conformance fixtures, property families and evidence

**Files:**

- Create: `tests/Support/Reporting/WaveOneDeterministicSeedGenerator.php`
- Create: `tests/Fixtures/Reporting/WaveOne/project_portfolio_health.v1.json`
- Create: `tests/Fixtures/Reporting/WaveOne/portfolio_liquidity.v1.json`
- Create: `tests/Fixtures/Reporting/WaveOne/project_margin.v1.json`
- Create: `tests/Fixtures/Reporting/WaveOne/budget_plan_fact.v1.json`
- Create: `tests/Fixtures/Reporting/WaveOne/wave-1-conformance-evidence.v1.json`
- Test: `tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php`

**Interfaces:**

- Consumes: four `WaveOneReportProviderTriple` records, `ReportSourceConformanceHarness`, `ReportSourceConformanceEvidence`.
- Produces: one record for each implemented `wave1.*` family with `seed_count: 500`, fixture SHA-256, provider triple names, cursor/scope/redaction assertions and a total of 2 000 seeds.

- [ ] **Step 1: Write failing deterministic evidence test**

```php
$evidence = $this->loadEvidence($path);

self::assertSame(2000, $evidence['total_seed_count']);
self::assertSame(500, $evidence['families']['wave1.project_margin']['seed_count']);
self::assertSame(['cursor','scope','redaction'], $evidence['families']['wave1.project_margin']['assertions']);
```

- [ ] **Step 2: Run the test and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php`

Expected: FAIL because generator, fixtures and evidence do not exist.

- [ ] **Step 3: Add a deterministic generator and four source-derived fixtures**

```php
public function cases(string $family, int $count = 500): array
{
    return array_map(
        fn (int $seed): WaveOneConformanceCase => $this->caseFor($family, $seed),
        range(1, $count),
    );
}
```

Use each seed to vary only source-valid project IDs, dates, amounts and pagination boundaries. Each fixture records source service, provider triple, expected canonical rows, next cursor, visible project IDs and the exact removed sensitive keys. A hash mismatch, missing fixture, seed count other than 500 or a non-implemented family in evidence fails the test.

- [ ] **Step 4: Run the full Wave 1 evidence suite**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

Expected: PASS; all four source-backed families prove provider/row/drill triples, stable cursor pages, current scope, redaction and 500 seeds each.

- [ ] **Step 5: Commit conformance evidence**

Run: `git add -- tests/Support/Reporting/WaveOneDeterministicSeedGenerator.php tests/Fixtures/Reporting/WaveOne tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php; git commit -m "test[reports]: add Wave 1 conformance evidence"`

### Task 6: Final boundary checks and handoff evidence

**Files:**

- Modify: `docs/reports/wave-1-source-contracts.md`
- Create: `docs/reports/wave-1-candidate-handoff.md`
- Test: `tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php`

**Interfaces:**

- Consumes: all Task 1-5 artifacts.
- Produces: auditable candidate-only handoff declaring four implemented candidates, eight source-contract-blocked candidates and zero activation artifacts.

- [ ] **Step 1: Write failing isolation test**

```php
self::assertStringNotContainsString('wave-1-candidates.v1.yaml', file_get_contents($managementManifest));
self::assertSame([], $this->activationCallsFor('WaveOneCandidateBindingSet'));
self::assertSame(0, $this->publishedDefinitionsFor($waveOneCodes));
```

- [ ] **Step 2: Run it and confirm failure**

Run: `vendor/bin/phpunit tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php`

Expected: FAIL until the architecture scanner and handoff record are added.

- [ ] **Step 3: Add isolation scanner and handoff document**

The architecture test scans only reporting source and resource files. It rejects Wave 1 manifest references from `resources/management-catalog.v1.yaml`, `ReportCatalogActivation*`, `ReportManifestPromotionService`, `ReportDefinitionRegistry` bindings and routes. The handoff document repeats the closed 12-row mapping, links each of G01/G04/G09/G10 to its source-contract section and fixture, and states that G12/G13 have formula contracts but no provider triple.

- [ ] **Step 4: Run final proportional checks**

Run: `vendor/bin/phpunit tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php; vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidate.php app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne; git diff --check`

Expected: PASS, no PHPStan errors, and empty `git diff --check` output. No DB command is run.

- [ ] **Step 5: Commit the Wave 1 handoff**

Run: `git add -- docs/reports/wave-1-source-contracts.md docs/reports/wave-1-candidate-handoff.md tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php; git commit -m "docs[reports]: record Wave 1 candidate handoff"`

## Plan Self-Review

- [ ] Coverage: Tasks 1-2 establish candidate identity and binding order; Tasks 3-4 deliver only G01/G04/G09/G10 from real existing services; Task 2 documents G12/G13 formula/source contracts; Task 5 supplies triples, fixtures, cursor/scope/redaction, and 500 seeds per real family; Task 6 blocks activation and publication.
- [ ] Placeholder scan: each task above contains concrete file paths, signatures, checks, expected results and a commit command.
- [ ] Type consistency: all provider work uses the existing `ReportDataProvider`, and the candidate set never substitutes for `ReportDefinitionBindingAssembler`.

## Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates.md`. Execute Tasks 1-6 sequentially. Use Subagent-Driven execution for a fresh reviewer gate per task, or Inline Execution with task checkpoints.
