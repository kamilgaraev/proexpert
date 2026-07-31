# Reports Plan 2 Wave 1 Foundation And First Candidates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an isolated Wave 1 candidate boundary that truthfully blocks every candidate until its source contract and immutable snapshot/replay readiness are admitted, without activating or publishing any report.

**Architecture:** A separate candidate manifest fixes the immutable identities of 12 Wave 1 candidates and is excluded from `management-catalog.v1.yaml`, `ReportDefinitionRegistry`, and runtime execution. `WaveOneCandidateBindingSet` is the verifiable ordering and status contract: G01, G04, G09 and G10 are blocked by source readiness, while the remaining candidates are blocked by source contract. No binding has a provider until its immutable snapshot/replay contract and admission evidence are present.

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
- A candidate receives deterministic conformance seeds only after its source-readiness admission; no pre-admission candidate is represented as implemented.

## Closed Wave 1 Identity Contract

| Ordinal | ID | Code | Family | Source status |
| ---: | --- | --- | --- | --- |
| 1 | G01 | `project_portfolio_health` | `wave1.project_portfolio_health` | source readiness required |
| 2 | G04 | `portfolio_liquidity` | `wave1.portfolio_liquidity` | source readiness required |
| 3 | G06 | `baseline_schedule_variance` | `wave1.baseline_schedule_variance` | source contract required |
| 4 | G09 | `project_margin` | `wave1.project_margin` | source readiness required |
| 5 | G10 | `budget_plan_fact` | `wave1.budget_plan_fact` | source readiness required |
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
  - {ordinal: 1, group_id: G01, code: project_portfolio_health, family: wave1.project_portfolio_health, source_status: source readiness required, publication: candidate}
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
- Produces: `WaveOneCandidateBindingSet::__construct(WaveOneCandidateManifest $manifest, iterable $bindings)`, `ordered(): array`, `implemented(): array`; statuses are `implemented`, `blocked_by_source_readiness` and `blocked_by_source_contract`.

- [ ] **Step 1: Write failing exact-order and no-fake-provider tests**

```php
$set = new WaveOneCandidateBindingSet($manifest, $bindings);

self::assertSame($expectedCodes, array_map(static fn (WaveOneCandidateBinding $binding): string => $binding->code, $set->ordered()));
self::assertSame([], $set->implemented());
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

Reject duplicate, missing, reordered, or extra codes. Require a `null` provider for every blocked row. In `docs/reports/wave-1-source-contracts.md`, record G01/G04/G09/G10 source-readiness contracts separately from the existing G06, G11, G12, G13 and G21-G24 source contracts. The document calls every pre-admission row a blocked, non-executable report candidate.

- [ ] **Step 4: Run tests and confirm the candidate set cannot enter runtime binding**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php tests/Unit/Reporting/Catalog/ImmutableBindingAssemblerTest.php; rg -n "WaveOneCandidateBindingSet|register\(" app/BusinessModules/Core/Reporting`

Expected: PASS; search finds no `register(` call on `WaveOneCandidateBindingSet` and no candidate-to-published conversion.

- [ ] **Step 5: Commit the candidate binding contract**

Run: `git add -- app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidateBinding.php app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php docs/reports/wave-1-source-contracts.md tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php; git commit -m "feat[reports]: add Wave 1 binding contract"`

### Task 3R: Restore truthful candidate state

Update the Wave 1 candidate manifest, schema and `WaveOneCandidateBindingSet` so G01, G04, G09 and G10 are `blocked_by_source_readiness` with a `null` provider. Keep G06, G11, G12, G13 and G21-G24 `blocked_by_source_contract`. The binding set rejects a provider for every blocked candidate and `implemented()` returns an empty array. Update the focused manifest and binding-set tests with the complete literal twelve-row state.

Run: `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

Expected: PASS; no Wave 1 candidate is implemented, registered or published.

### Task 3S: Specify immutable source snapshot and replay contracts

For G01, G04, G09 and G10, record the owned source snapshot, source-version identity, tenant scope, cursor, retention, redaction and replay acceptance test. The contract must show that a historical result can be reproduced without reading mutable current state. Do not create a provider, binding registration, route or UI while this evidence is absent.

### Task 3A: Admit only proven source-ready candidates

After Task 3S acceptance evidence exists, add a provider and conformance fixture for one candidate at a time. Admission requires a source snapshot replay test, deterministic cursor/scope/redaction evidence, and a binding-set test that changes only the admitted candidate from its blocked status. Until all gates pass, the candidate remains `blocked_by_source_readiness` with `provider: null`.

### Task 4: Post-admission conformance fixtures and evidence

**Files:**

- Create: `tests/Support/Reporting/WaveOneDeterministicSeedGenerator.php`
- Create: one `tests/Fixtures/Reporting/WaveOne/<admitted-candidate>.v1.json` fixture for each candidate admitted in Task 3A
- Create: `tests/Fixtures/Reporting/WaveOne/wave-1-conformance-evidence.v1.json`
- Test: `tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php`

**Interfaces:**

- Consumes: admitted `WaveOneReportProviderTriple` records, `ReportSourceConformanceHarness`, `ReportSourceConformanceEvidence`.
- Produces: evidence only for candidates that passed Task 3A admission, with fixture SHA-256 and cursor/scope/redaction assertions.

- [ ] **Step 1: Write failing deterministic evidence test**

```php
$evidence = $this->loadEvidence($path);
$admittedFamilies = $this->admittedFamilies();

self::assertSame(count($admittedFamilies) * 500, $evidence['total_seed_count']);
self::assertSame($admittedFamilies, array_keys($evidence['families']));
foreach ($admittedFamilies as $family) {
    self::assertSame(500, $evidence['families'][$family]['seed_count']);
    self::assertSame(['cursor', 'scope', 'redaction', 'snapshot_replay'], $evidence['families'][$family]['assertions']);
}
```

- [ ] **Step 2: Run the test and confirm failure**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php`

Expected: FAIL until Task 3A admits at least one candidate and its generator, fixture and evidence are added.

- [ ] **Step 3: Add a deterministic generator and source-derived fixtures only for admitted candidates**

```php
public function cases(string $family, int $count = 500): array
{
    return array_map(
        fn (int $seed): WaveOneConformanceCase => $this->caseFor($family, $seed),
        range(1, $count),
    );
}
```

Use each seed to vary only source-valid project IDs, dates, amounts and pagination boundaries. Each fixture records source snapshot, provider triple, expected canonical rows, next cursor, visible project IDs and the exact removed sensitive keys. A hash mismatch, missing fixture, missing admission evidence, or a non-admitted family in evidence fails the test.

- [ ] **Step 4: Run the full Wave 1 evidence suite**

Run: `vendor/bin/phpunit tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`

Expected: PASS; each admitted family proves provider/row/drill triples, immutable replay, stable cursor pages, current scope, redaction and 500 seeds.

- [ ] **Step 5: Commit conformance evidence**

Run: `git add -- tests/Support/Reporting/WaveOneDeterministicSeedGenerator.php tests/Fixtures/Reporting/WaveOne tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php; git commit -m "test[reports]: add evidence for admitted Wave 1 candidates"`

### Task 5: Final boundary checks and handoff evidence

**Files:**

- Modify: `docs/reports/wave-1-source-contracts.md`
- Create: `docs/reports/wave-1-candidate-handoff.md`
- Test: `tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php`

**Interfaces:**

- Consumes: all Task 1-5 artifacts.
- Produces: auditable candidate-only handoff declaring source-readiness and source-contract blocked candidates, admitted candidates only when evidence exists, and zero activation artifacts.

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

The architecture test scans only reporting source and resource files. It rejects Wave 1 manifest references from `resources/management-catalog.v1.yaml`, `ReportCatalogActivation*`, `ReportManifestPromotionService`, `ReportDefinitionRegistry` bindings and routes. The handoff document repeats the closed 12-row mapping, links G01/G04/G09/G10 to their source-readiness contracts, and states that every blocked candidate has no provider triple.

- [ ] **Step 4: Run final proportional checks**

Run: `vendor/bin/phpunit tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php tests/Unit/Reporting/Conformance/WaveOneCandidateConformanceTest.php; vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/WaveOneCandidate.php app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php app/BusinessModules/Core/Reporting/Infrastructure/Providers/WaveOne; git diff --check`

Expected: PASS, no PHPStan errors, and empty `git diff --check` output. No DB command is run.

- [ ] **Step 5: Commit the Wave 1 handoff**

Run: `git add -- docs/reports/wave-1-source-contracts.md docs/reports/wave-1-candidate-handoff.md tests/Architecture/Reporting/WaveOneCandidateIsolationTest.php; git commit -m "docs[reports]: record Wave 1 candidate handoff"`

## Plan Self-Review

- [ ] Coverage: Tasks 1-2 establish candidate identity and binding order; Task 3R restores truthful blocked state; Task 3S specifies immutable source readiness; Task 3A admits candidates only after proof; Task 4 supplies post-admission evidence; Task 5 blocks activation and publication.
- [ ] Placeholder scan: each task above contains concrete file paths, signatures, checks, expected results and a commit command.
- [ ] Type consistency: all provider work uses the existing `ReportDataProvider`, and the candidate set never substitutes for `ReportDefinitionBindingAssembler`.

## Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates.md`. Execute Tasks 1-6 sequentially. Use Subagent-Driven execution for a fresh reviewer gate per task, or Inline Execution with task checkpoints.
