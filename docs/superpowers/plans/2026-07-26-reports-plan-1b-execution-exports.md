# Reports Plan 1b: Execution, Exports, Storage and Audit Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the canonical report run, immutable snapshot, row access, drill-down, export, storage, retention, audit, and evidence pipeline while consuming Plan 1a contracts without redefining them.
**Architecture:** Plan 1b is an implementation layer below the locked Plan 1a application and domain contracts. Prerequisite forward-only Plan 1a amendments add typed snapshot sealing and output classification, fix the sensitive/audit decision bug, and replace ambiguous integer-only report resources with typed scoped resources plus one atomic current-authorization result before any materializer exists. PostgreSQL aggregate stores atomically create one durable dispatch intent with every new run/export. `ReportDispatchIntentPublisher` is the sole run/export aggregate-ID Redis publication path; `ReportAuditOutboxScheduler` through `ReportAuditDispatcher` is the sole audit-intent-ID Redis publication path. Their purposes, payloads, claims, attempts, and acknowledgement semantics are disjoint, and neither may publish or acknowledge the other's intent. Idempotent jobs always rebuild actor, scope, and authorization from one current server-fact snapshot. Stores own atomic state transitions; a narrow row-stream adapter validates the untyped iterable returned by the existing `ReportRowQuery` port and groups validated rows into bounded internal chunks.
**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, Redis queues, S3-compatible storage through `App\Services\Storage\FileService`, installed `barryvdh/laravel-dompdf` constraint `^3.1` locked at `v3.1.1` with `dompdf/dompdf v3.1.4`, PHPUnit, Larastan/PHPStan, JSON Schema Draft 2020-12 as a deterministic evidence artifact.

---

## Scope and non-negotiable boundaries

- This plan owns execution and export implementations only.
- Plan 1a remains the sole owner of report domain DTOs, enums, errors, canonical provider ports, input DTOs, normalizers, action ports, access contracts, requests, resources, and test builders. Historical Tasks 4a and 4a2 and forward-only Task 4e are the only sanctioned amendments to those owned contracts; their committed descendants become the new immutable Plan 1a handoff.
- Plan 1b imports those symbols directly. It does not add aliases, compatibility shims, parallel DTOs, replacement enums, or second copies under another namespace.
- Plans 2 and 3 provide domain report definitions and providers through the Plan 1a ports.
- Plan 1c owns catalog publication, published activation, binding assembler/registry, saved views, schedules, subscriptions, and their telemetry; Plan 4 owns only evidence verification and deployment rollout.
- Controllers remain thin and use the standardized response classes already established by Plan 1a.
- Every queued job reloads the actor, organization scope, publication binding, and access decision. Serialized authorization decisions are forbidden.
- Untyped `resourceIds`, `resource_ids`, `scope_resource_ids`, and `allowed_resource_ids` are removed by Task 4e. There is no compatibility alias, coercion, dual read/write, generic resource kind, or numeric-ID fallback. Every non-empty resource scope consists only of typed `ReportScopedResource` values and is authorized item-by-item by an exact-kind server adapter; an unknown or unbound kind fails closed.
- Redis publication is never treated as part of a PostgreSQL transaction. Every new run/export and its unique dispatch intent commit atomically in PostgreSQL; publication is leased, retryable, observable, and at-least-once, while consumers are idempotent.
- `afterCommit()`/`after_commit` may be used only as a latency optimization to wake the durable publisher. It is never evidence of atomic PostgreSQL commit plus Redis publication and never replaces the outbox reconciler.
- Sensitive/audit access comes only from the typed Plan 1a output-classification contract. Column names, values, labels, permission-list emptiness, source names, snapshot kind, and other heuristics are forbidden.
- Official status comes only from `ReportSnapshotClassification::OFFICIAL` plus a trusted verified `ReportSnapshotSeal`; booleans, `kind`, ID prefixes, source names, and watermarks are never interpreted as officiality.
- A ready run and a ready export are impossible unless the unique immutable audit intent is appended in the same PostgreSQL transaction as the ready transition; Core audit delivery occurs afterward through the separate leased audit-intent consumer. Run/export Redis dispatch uses its own transport-intent publisher and cannot acknowledge audit delivery.
- CSV/XLSX use the streaming multipart pipeline and never buffer a complete export. PDF uses only validated bounded row chunks and one hard-capped render document; it may hold only that capped HTML/PDF representation, never an arbitrary or production-sized result set.
- Files use the organization prefix `org-{organization_id}/reports/`.
- Migrations are written and statically checked but are not executed as part of this plan.
- Local database commands, dev servers, frontend builds, browser smoke tests, and production mutations are outside this plan.
- Every task's `Files` list is its complete commit manifest. Before every commit, the sorted staged path set must equal that manifest exactly; after commit, `git diff-tree --no-commit-id --name-only -r HEAD` must equal it and task-scoped `git status --short` must be empty. Directory-form `git add` lines are only shorthand and may not stage an unlisted path.

## File map before implementation

### Existing Plan 1a files consumed directly

Task 4e is the third sanctioned forward-only Plan 1a descendant. Task 4f is its evidence-only forward descendant that makes postcommit replay phase-aware before Task 5 without rewriting Task 4e; all earlier task blocks and commit identities remain historical immutable facts.

Tasks 1–3 consume the initial Plan 1a lock unchanged. Historical Task 4a is the first sanctioned Plan 1a descendant and remains immutable. After historical Task 4b, forward-only Task 4a2 performs the second sanctioned Plan 1a lock/evidence repin for typed snapshot-identity violations before Task 4c and later consumers. Plan 1b never forks, rewrites, or aliases these contracts.

| Concern | Exact Plan 1a files |
|---|---|
| Definition and query | `app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinition.php`, `PublishedReportDefinition.php`, `CandidateReportDefinition.php`, `ReportQuery.php`, `ReportFilterSet.php`, `ReportScope.php`, `ReportExecutionContext.php`, `ReportProgress.php`, `ReportRowsWindow.php`, `ReportWindowSort.php` |
| Result identity | `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php`, `ReportSourceRef.php`, `ReportResult.php`, `ReportResultMetadata.php`, `ReportCursor.php`, `ReportPage.php`, `ReportDrillDownRequest.php`, `ReportDrillDownResult.php` |
| Run and export | `app/BusinessModules/Core/Reporting/Domain/DTO/ReportRun.php`, `ReportExport.php`, `ReportDownloadLink.php` |
| Value objects | `app/BusinessModules/Core/Reporting/Domain/ValueObjects/Sha256Hash.php`, `IdempotencyKey.php`; canonical encoding: `app/BusinessModules/Core/Reporting/Support/CanonicalJson.php` |
| Enums | `app/BusinessModules/Core/Reporting/Domain/Enums/ReportRunStatus.php`, `ReportExportStatus.php`, `ReportOperation.php`, `ReportSortDirection.php`, `ReportFreshnessStatus.php`, `ReportQualityStatus.php` |
| Errors | `app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorCode.php`, `ReportErrorDescriptor.php`, `ReportErrorCatalog.php`, `ReportContractException.php` |
| Provider and binding contracts | `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportDataProvider.php`, `ReportRowQuery.php`, `ReportDrillDownProvider.php`, `ReportDefinitionReadinessProbe.php`, `ReportDefinitionRegistry.php`, `CandidateReportDefinitionRegistry.php`, `ReportDefinitionBindingAssembler.php`, `ReportDefinitionCandidateValidator.php`; binding DTOs under `Domain/DTO/` |
| Inputs and normalizers | `app/BusinessModules/Core/Reporting/Application/Input/CreateReportRunData.php`, `CreateReportExportData.php`, `CreateReportDownloadLinkData.php`, and every normalizer created by Plan 1a |
| Action ports | all files under `app/BusinessModules/Core/Reporting/Application/Contracts/` created by Plan 1a |
| Access | `app/BusinessModules/Core/Reporting/Application/Access/ReportActorLoader.php`, `OrganizationReportScopeResolver.php`, `ReportExecutionContextFactory.php`, `ReportAccessService.php` |
| Contract evidence | `docs/reports/contracts/plan-1a-contract-lock.json`, `docs/reports/contracts/plan-1a-completion.schema.json`, `docs/reports/contracts/plan-1a-gate-evidence.schema.json`, generated `build/reports/plan-1a-completion.json`, fixed raw input `build/reports/plan-1a-ci-authorization.json` |

### New Plan 1b implementation areas

| Area | New files |
|---|---|
| Handoff verification | `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneACompletionVerifier.php`, `PlanOneACompletionRef.php` |
| Execution support | `app/BusinessModules/Core/Reporting/Application/Execution/`, `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/` |
| Action implementations | `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/` |
| Persistence | `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/`, two report migrations |
| Durable dispatch | `app/BusinessModules/Core/Reporting/Application/Dispatch/`, `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportDispatchIntentStore.php`, `app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/`, one dispatch-intent migration |
| Jobs | `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/` |
| Row streaming | `app/BusinessModules/Core/Reporting/Application/Rows/`, `app/BusinessModules/Core/Reporting/Infrastructure/Cursors/` |
| Export | `app/BusinessModules/Core/Reporting/Application/Exports/`, `app/BusinessModules/Core/Reporting/Infrastructure/Exports/` |
| Storage | additions under `app/Services/Storage/DTO/` and exact changes to `app/Services/Storage/FileService.php` |
| Audit | `app/BusinessModules/Core/Reporting/Application/Audit/`, `app/BusinessModules/Core/Reporting/Infrastructure/Audit/` |
| Telemetry | `app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/` |
| Wiring | `app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php` |
| Evidence | tracked schema and deterministic test fixture; ignored CI artifact `build/reports/plan-1b-completion.json` |

## Locked Plan 1a handoff

Implementation starts only after `PlanOneACompletionVerifier` confirms:

1. The lock, both tracked schemas, generated completion evidence and fixed raw authorization artifact exist, and the SHA-256 of the lock bytes equals evidence field `contract_lock_sha256`.
2. Completion evidence validates against `plan-1a-completion.schema.json`, has status `passed`, and contains a successful `hermetic_http` authorization summary with exact `22/22` plus `ci_http_matrices.authorization.artifact_sha256`.
3. The separately supplied `build/reports/plan-1a-ci-authorization.json` is a strict closed-shape `plan-1a-gate-evidence.schema.json` authorization artifact with exact ordered `22/22` execution records, and the SHA-256 recomputed from its raw reread bytes matches the completion digest through `hash_equals()`.
4. Reflection sees the exact constructor and method signatures listed below.
5. The ownership-boundary test finds no Plan 1b redefinition of a Plan 1a-owned symbol.

### Exact imported constructor contracts

`ReportQuery` has six input arguments:
```php
new ReportQuery(
    $definition,
    $scope,
    $filters,
    $comparison,
    $asOf,
    $locale,
);
```
It derives `canonicalJson` and a `Sha256Hash queryHash`. Callers never supply either derived value.
`ReportPage` is consumed with these fields and no local wrapper:
```text
rows
totals
freshness
quality
nextCursor
limit
hasMore
sort
```
`ReportProgress` is constructed from one integer percent. Its `advance(int): bool` method reports only whether progress grew by at least one percentage point. Plan 1b does not attach an observer or subclass it.
`ReportRun`, `ReportExport`, `ReportSnapshotRef`, `ReportCursor`, and `ReportDownloadLink` are the Plan 1a DTOs. Stores hydrate those DTOs directly.
`ReportDefinitionRegistry::published(string): PublishedReportDefinition`; execution uses its `payload()` and the same-code `ReportDefinitionBindingMap::get()` entry, never a Plan 1b wrapper.

### Exact imported provider methods

```php
ReportDataProvider::materialize(
    ReportExecutionContext $context,
    ReportQuery $query,
    ReportProgress $progress,
): ReportSnapshotRef;
ReportDataProvider::result(
    ReportExecutionContext $context,
    ReportSnapshotRef $snapshot,
): ReportResult;
ReportRowQuery::page(
    ReportExecutionContext $context,
    ReportSnapshotRef $snapshot,
    ReportWindowSort $sort,
    ?ReportCursor $cursor,
    int $limit,
): ReportPage;
ReportRowQuery::cursor(
    ReportExecutionContext $context,
    ReportSnapshotRef $snapshot,
    ReportWindowSort $sort,
    int $chunkSize,
): iterable;
ReportDrillDownProvider::drillDown(
    ReportExecutionContext $context,
    ReportSnapshotRef $snapshot,
    ReportDrillDownRequest $request,
): ReportDrillDownResult;
```

### Exact imported action methods

| Port | Exact `handle()` signature after the method name |
|---|---|
| `CreateReportRunAction` | `(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $idempotencyKey): ReportRun` |
| `GetReportRowsAction` | `(ReportExecutionContext $context, string $runId, ReportRowsWindow $window): ReportPage` |
| `CreateReportExportAction` | `(ReportExecutionContext $context, string $runId, CreateReportExportData $data, IdempotencyKey $idempotencyKey): ReportExport` |
| `CreateReportDownloadLinkAction` | `(ReportExecutionContext $context, CreateReportDownloadLinkData $data): ReportDownloadLink` |

The remaining get, retry, cancel, and drill-down handlers implement their corresponding Plan 1a action ports without signature changes.

### Exact authorization operation mapping

| Flow | `ReportOperation` |
|---|---|
| Read run, result, rows, or export status | `VIEW` |
| Create, retry, or cancel a run | `RUN` |
| Create, retry, or cancel an export | `EXPORT` |
| Create a temporary download link | `DOWNLOAD` |
| Read sensitive data | `VIEW_SENSITIVE` in addition to the base operation |
| Read audit data | `VIEW_AUDIT` |
| Drill down | `DRILL_DOWN` |

### Exact error and retryability ownership

All expected failures use `ReportContractException::fromCode()` with a Plan 1a `ReportErrorCode`. HTTP status, safe translated message key, retryability, and logging policy come only from `ReportErrorCatalog`.

| Error | HTTP | Retryable |
|---|---:|---:|
| report or resource not found | 404 | false |
| scope forbidden | 403 | false |
| filter, sort, cursor, or idempotency key invalid | 422 | false |
| idempotency conflict | 409 | false |
| snapshot not ready | 409 | true |
| export not ready | 409 | true |
| official snapshot unsealed | 409 | false |
| snapshot or export expired | 410 | false |
| export limit exceeded | 413 | false |
| rate limited | 429 | true |
| source unavailable or dependency failed | 503 | true |
| internal error | 500 | true |

## Plan 1b-owned implementation contracts

These contracts describe execution mechanics and do not overlap Plan 1a domain or application contracts.

| Contract | Exact responsibility |
|---|---|
| `ReportRunStore` | Create/reuse from `ReportQuery` plus `IdempotencyKey` with organization-wide key ownership and atomically create the unique run dispatch intent; actor is audit/requester metadata, not part of idempotency identity. Load in context; expose a typed retry source; start; persist Plan 1a progress; fail terminally only for non-retryable/final-attempt failures; seal the Plan 1a snapshot/result through `ReportTransitionAudit`; return Plan 1a `ReportRun`. |
| `ReportExportStore` | Exact seven methods: create/reuse from complete run-export source/input/key while atomically creating the unique export dispatch intent; get; fenced start-rendering/start-uploading; audited-outbox `sealReady`; terminal fail; cancel. Actor is audit/requester metadata, not idempotency identity. Completed-but-unsealed S3 versions are reconciled through the separate exact-version inventory, not an eighth store method. |
| `ReportDispatchIntentStore` | Own the PostgreSQL outbox: unique intent creation inside the aggregate transaction, lease claims, publication acknowledgement, bounded deterministic backoff, expired-lease recovery, dead-letter transition, and atomic aggregate failure on exhausted publication attempts. |
| `ReportTransitionAudit` | `append(string $eventId, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void`. |
| Dispatchers | Dispatch only run/export IDs. |
| Context rehydrator | Rebuild current Plan 1a execution context from a run or export ID. |
| Plan 1a binding consumption | Resolve a definition through `ReportDefinitionRegistry` and use its exact provider binding from `ReportDefinitionBindingMap`; add no Plan 1b resolver. |

Both store `sealReady()` methods start a transaction, lock the aggregate row, append the immutable Core event, update ready state, and commit. Audit failure rolls back the complete transition. Tasks 3 and 7 pin their typed method signatures with reflection tests.

## State and identity invariants

### Run state machine

| Current | Allowed next states |
|---|---|
| `QUEUED` | `MATERIALIZING`, `CANCELLED` |
| `MATERIALIZING` | `QUEUED` only for fenced retry-attempt release/watchdog recovery; otherwise `READY`, `FAILED`, `CANCELLED` |
| `READY` | `EXPIRED` |
| `FAILED` | no in-place retry; retry creates a new run |
| `CANCELLED` | no transition |
| `EXPIRED` | no transition |

### Export state machine

| Current | Allowed next states |
|---|---|
| `QUEUED` | `RUNNING`, `CANCELLED` |
| `RUNNING` | `QUEUED` only for fenced watchdog recovery; otherwise `UPLOADING`, `FAILED`, `CANCELLED` |
| `UPLOADING` | `QUEUED` only for fenced watchdog recovery; otherwise `READY`, `FAILED`, `CANCELLED` |
| `READY` | `EXPIRED` |
| `FAILED` | no in-place retry; retry creates a new export |
| `CANCELLED` | no transition |
| `EXPIRED` | no transition |

One immutable identity flows through every representation:
```text
definition hash
query hash
source hash
result hash
snapshot id
snapshot classification and complete verified seal identity
data classification and exact sensitive/audit column sets
formula version
contract version
source schema version
renderer version
```
Rows, totals, drill-down, CSV, XLSX, PDF, metadata, audit, and temporary links must refer to that identity. A mismatch fails closed before any ready transition.

## Required implementation order

| Order | Task | Gate opened |
|---:|---|---|
| 1 | Verify Plan 1a lock, evidence, signatures, and ownership | Plan 1b may compile |
| 2 | Add queue/context/clock ports, deterministic fakes, and audit contract | jobs and stores may depend on stable execution mechanics |
| 3 | Add the complete run-store port, persistence, and atomic audited transitions | run coordinators and readers may depend on durable run state |
| 4a | Amend Plan 1a typed seal/classification contracts and fix access decisions | coordinator may consume unambiguous official/sensitive/audit facts |
| 4b | Add durable PostgreSQL dispatch intents, publisher, and reconciler | aggregate creation can safely precede Redis publication |
| 4a2 | Forward-only Plan 1a repin for typed snapshot-identity violations | provider failures can be mapped without masking unrelated invalid identity |
| 4c | Implement run actions, retry identity, closed source hash, and seal validation | HTTP run actions are real without direct queue dispatch |
| 4d | Lock run lease renewal, correlation lineage, and the reports queue runtime | background execution has one race-safe timing model |
| 5 | Implement materialization job, current authorization rehydration, and stage-based progress | snapshots can become ready under current access |
| 6 | Implement row adapter, cursors, rows, and drill-down | online result access is real and classification-aware |
| 7 | Add the fully locked export store and atomic dispatch/audit transitions | export state and retry/reconciliation inputs are durable |
| 8 | Add bounded CSV/XLSX/PDF renderers | bytes can be streamed or rendered inside a hard PDF budget |
| 9 | Complete FileService multipart API and sink | bytes can be stored immutably |
| 10 | Implement export and download action handlers | HTTP export actions are real |
| 11 | Implement export job and retry-safe cleanup | exports can become ready |
| 12 | Add retention and the Core audit-intent consumer | expiry and audit delivery are complete |
| 13 | Add run/export telemetry and error mapping | operations are observable |
| 14 | Lock evidence, quality gates, and cross-plan handoff | Plan 1b may close |

---

### Task 1: Verify the exact Plan 1a handoff before implementation

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneACompletionRef.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneACompletionVerifier.php`
- Create: `tests/Unit/Reporting/Evidence/PlanOneACompletionVerifierTest.php`
- Create: `tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php`
- Create: `tests/Architecture/Reporting/PlanOneBOwnershipBoundaryTest.php`
- Read only: `docs/reports/contracts/plan-1a-contract-lock.json`
- Read only: `docs/reports/contracts/plan-1a-completion.schema.json`
- Read only: `docs/reports/contracts/plan-1a-gate-evidence.schema.json`
- Read only: `build/reports/plan-1a-completion.json`
- Read only: `build/reports/plan-1a-ci-authorization.json`

**Interfaces consumed or produced:**

- Consume every Plan 1a type and method listed in the locked handoff section.
- Produce `PlanOneACompletionVerifier::assertReady(string $lock, string $completionSchema, string $completionArtifact, string $authorizationSchema, string $authorizationArtifact): PlanOneACompletionRef`.
- `PlanOneACompletionRef` contains the verified lock SHA-256, evidence SHA-256, generated timestamp, and Plan 1a status.

The Task 1 production handoff call supplies all five explicit fixed paths; the verifier never derives either schema or the authorization artifact from another input:

```php
$planOneA = $verifier->assertReady(
    'docs/reports/contracts/plan-1a-contract-lock.json',
    'docs/reports/contracts/plan-1a-completion.schema.json',
    'build/reports/plan-1a-completion.json',
    'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
    'build/reports/plan-1a-ci-authorization.json',
);
```

**Step 1: Write failing tests**
Test missing lock/completion schema/gate schema/completion/raw authorization files, malformed SHA-256, mismatched lock bytes, failed evidence, evidence pointing at another lock, omitted fourth or fifth argument, any gate-schema path other than exact `docs/reports/contracts/plan-1a-gate-evidence.schema.json`, missing/alternate/mutated gate-schema bytes, any authorization path other than exact `build/reports/plan-1a-ci-authorization.json`, path alias/symlink escape for either explicit path, mutated raw authorization bytes, mode other than `hermetic_http`, root artifact status other than `passed`, missing/duplicate/extra/reordered execution record, any record status other than its exact locked integer HTTP status, any exact-count mismatch, and a raw artifact digest different from `ci_http_matrices.authorization.artifact_sha256`. Also test reflection drift for the exact five-argument verifier and every imported constructor and port, plus a forbidden Plan 1b-owned duplicate.
```php
public function test_rejects_evidence_bound_to_another_plan_one_a_lock(): void
{
    $this->expectException(ReportContractException::class);
    $this->verifier->assertReady(
        $this->fixture('plan-1a-contract-lock.json'),
        $this->fixture('plan-1a-completion.schema.json'),
        $this->fixture('completion-with-other-lock.json'),
        $this->fixture('plan-1a-gate-evidence.schema.json'),
        $this->fixture('build/reports/plan-1a-ci-authorization.json'),
    );
}
```
**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Evidence/PlanOneACompletionVerifierTest.php tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php tests/Architecture/Reporting/PlanOneBOwnershipBoundaryTest.php
```
Expected: failure because the verifier and architecture assertions do not exist.
**Step 3: Implement the minimum**
Hash raw lock bytes, strictly validate the generated evidence against the explicit Plan 1a completion-schema bytes, and compare the calculated digest with `contract_lock_sha256` using constant-time comparison. Resolve the repository root only from the exact canonical lock path, then form the two expected bounded paths `<repository-root>/docs/reports/contracts/plan-1a-gate-evidence.schema.json` and `<repository-root>/build/reports/plan-1a-ci-authorization.json`. Canonicalize the explicit fourth `$authorizationSchema` and fifth `$authorizationArtifact` arguments and require exact path equality before opening either file. The formed paths are allowlist comparators only; all schema and artifact bytes are read exclusively through the explicit fourth and fifth arguments. Reject missing files, alternate paths, path aliases, traversal and symlinks; never derive the gate schema or authorization artifact from the completion path or from each other.

Reread the explicit gate schema as raw bytes, require the exact path to be tracked at the completion's `commit_sha`, reread that commit blob and require byte equality with the working bytes, then strictly decode and compile those exact explicit bytes as the recursively closed Draft 2020-12 four-branch gate schema. Missing, alternate, symlinked, malformed, untracked or working-tree-mutated schema bytes fail before artifact acceptance. Reread the explicit authorization artifact as raw bytes, strictly decode it and validate those same bytes against the authorization branch of that exact compiled schema. Require artifact ID `plan_1a_ci_authorization`, `verification_mode=hermetic_http`, `status=passed`, root counts exactly `cases=22`, `passed=22`, `allowed_cases=7`, `denied_cases=15`, `http_requests=28`, `assertions=132`, and exactly 22 execution records in this order:

```text
unauthenticated_catalog_denied
non_admin_catalog_denied
module_disabled_catalog_denied
missing_global_permission_catalog_denied
view_actor_catalog_allowed
view_actor_run_status_allowed
view_actor_rows_allowed
view_actor_run_create_denied
view_actor_export_create_denied
view_actor_download_denied
runner_run_create_allowed
runner_run_retry_allowed
runner_run_cancel_allowed
runner_export_denied
exporter_export_allowed
exporter_download_denied
downloader_revoked_definition_denied
manage_does_not_expand_operational_permissions
foreign_and_nonexistent_filter_indistinguishable
foreign_and_nonexistent_source_indistinguishable
blocked_actor_denied_after_context_reload
deleted_actor_denied_after_context_reload
```

Each execution record contains only `case_id`, `status`, `request_count`, `response_statuses`, `response_codes`, `action_calls`, `actor_loads`, `assertions`; the root artifact status is exactly `passed`, each record status is its exact locked integer HTTP status from the ordered Plan 1a authorization matrix, and the record-folded case, allowed/denied, request and assertion counts equal the exact root counts `22`, `7/15`, `28` and `132`. Compute lowercase `hash('sha256', $authorizationRawBytes)` without re-encoding JSON and require `hash_equals($completion['ci_http_matrices']['authorization']['artifact_sha256'], $authorizationSha256)`. Only then return the immutable reference. Reflection assertions pin the five-argument verifier signature and exact parameter order `lock, completionSchema, completionArtifact, authorizationSchema, authorizationArtifact`, plus exact types, nullability, return type, enum cases and Plan 1a file ownership.
The ownership test rejects Plan 1b declarations named `ReportDataProvider`, `ReportRowQuery`, `ReportDrillDownProvider`, `ReportDefinition`, `PublishedReportDefinition`, `CandidateReportDefinition`, `ReportDefinitionBinding`, `ReportDefinitionBindingMap`, `ReportQuery`, `ReportPage`, `ReportRun`, `ReportExport`, `ReportCursor`, `ReportDownloadLink`, `CreateReportRunData`, or `CreateReportExportData`.
**Step 4: Prove GREEN**
Run the same targeted PHPUnit command.
Expected: all five-input fixed-path, explicit gate-schema bytes, raw authorization digest, closed schema, exact ordered `22/22` execution-record, completion, reflection, and ownership checks pass.
**Step 5: Static analysis**
Run:
```bash
vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Evidence tests/Unit/Reporting/Evidence tests/Architecture/Reporting --no-progress
```
Expected: no errors.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Evidence tests/Unit/Reporting/Evidence tests/Architecture/Reporting
git commit -m "test[reports]: зафиксировать передачу контрактов plan 1a"
```

### Task 2: Add execution-only contracts, deterministic fakes, and the audit gate

**Dependency:** Task 1 handoff verification is green. This task does not depend on a run store or coordinator.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExecutionClock.php`, `app/BusinessModules/Core/Reporting/Infrastructure/Clock/SystemReportExecutionClock.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportMaterializationDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunExecutionContextRehydrator.php`, `ReportExportExecutionContextRehydrator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Audit/ReportTransitionAudit.php`
- Create: `tests/Support/Reporting/FakeReportExecutionClock.php`
- Create: `tests/Support/Reporting/FakeReportTransitionAudit.php`
- Create: `tests/Support/Reporting/FakeReportMaterializationDispatcher.php`
- Create: `tests/Support/Reporting/FakeReportExportDispatcher.php`
- Create: `tests/Unit/Reporting/Execution/ExecutionContractsTest.php`

**Task file count:** 12 exact files.

**Interfaces consumed or produced:**

- Consume Plan 1a `ReportExecutionContext`, `ReportRun`, and `ReportExport`.
- Produce only the clock, dispatcher, context-rehydrator, and audit contracts declared in this task. Task 3 owns and locks `ReportRunStore`.
- The run/export rehydrator ports expose `forRun(string): ReportExecutionContext` and `forExport(string): ReportExecutionContext`; both reload current actor, scope, and access.

**Step 1: Write failing contract tests**
Assert no Task 2 production or fake namespace declares a second report-definition registry, binding composite, or provider abstraction; provider resolution remains exclusively behind the Plan 1a ports. Assert clock fakes are deterministic, both dispatcher fakes implement their exact ports and retain only IDs, and the audit fake can be configured to fail before recording. Coordinator wiring is deferred to Task 4, where the coordinator exists.
```php
public function test_audit_failure_is_observable_to_the_store_boundary(): void
{
    $audit = FakeReportTransitionAudit::failing();
    $this->expectException(ReportContractException::class);
    $audit->append(
        'evt-run-ready-1',
        'report.run.ready',
        $this->context,
        ['run_id' => 'run-1'],
        $this->clock->now(),
    );
}
```
**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Execution/ExecutionContractsTest.php
```
Expected: failure because the execution-only ports and fakes do not exist.
**Step 3: Implement the minimum**
Use deterministic fakes for Plan 1b mechanics. Do not create a second registry, binding composite, or provider abstraction. Task 4 will prove that its coordinator resolves the published definition from `ReportDefinitionRegistry` and its providers from the same Plan 1a `ReportDefinitionBindingMap`; Plans 2 and 3 later supply binding records through the Plan 1a lifecycle.
Audit events contain a caller-generated unique source event ID, event type, organization and actor context, immutable subject identity, and occurrence time. The interface is defined now so every later ready transition is fail-closed from its first implementation.
**Step 4: Prove GREEN**
Run the task test and expect all assertions to pass.
**Step 5: Static analysis**
Run PHPStan for the files created in this task and expect no errors.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Contracts/Execution app/BusinessModules/Core/Reporting/Application/Audit app/BusinessModules/Core/Reporting/Infrastructure/Clock tests/Support/Reporting tests/Unit/Reporting/Execution
git commit -m "feat[reports]: добавить контракты исполнения и аудит переходов"
```

### Task 3: Persist runs with idempotency and atomic audited transitions

**Dependency:** Task 2 audit and clock contracts are green. This task is the first and only owner of the run-store interface.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php`
- Create: `database/migrations/2026_07_26_000001_create_report_runs_table.php`, `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Create: `tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php`
- Modify: `tests/Unit/Reporting/Execution/ExecutionContractsTest.php`

**Task file count:** 8 exact files.

**Interfaces consumed or produced:**

- Create and implement `ReportRunStore` in this task. Its complete typed contract is:
```php
interface ReportRunStore
{
    public function createOrReuse(
        ReportExecutionContext $context,
        ReportQuery $query,
        ?string $savedViewId,
        IdempotencyKey $idempotencyKey,
    ): ReportRun;

    public function get(
        ReportExecutionContext $context,
        string $runId,
    ): ReportRun;

    public function queryForRun(
        ReportExecutionContext $context,
        string $runId,
    ): ReportQuery;

    public function startMaterialization(
        ReportExecutionContext $context,
        string $runId,
        DateTimeImmutable $occurredAt,
    ): ReportRun;

    public function persistProgress(
        ReportExecutionContext $context,
        string $runId,
        ReportProgress $progress,
        DateTimeImmutable $occurredAt,
    ): ReportRun;

    public function sealReady(
        ReportExecutionContext $context,
        string $runId,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        Sha256Hash $sourceHash,
        DateTimeImmutable $occurredAt,
    ): ReportRun;

    public function fail(
        ReportExecutionContext $context,
        string $runId,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): ReportRun;

    public function cancel(
        ReportExecutionContext $context,
        string $runId,
        DateTimeImmutable $occurredAt,
    ): ReportRun;
}
```
- Hydrate the Plan 1a `ReportRun` directly.
- `ReportRunStore::queryForRun(ReportExecutionContext $context, string $runId): ReportQuery` rebuilds the exact Plan 1a query from persisted normalized components without rerunning HTTP normalizers.
- Consume `IdempotencyKey::value` for safe diagnostic context and `IdempotencyKey::hash` for persistence.
- Consume Plan 1a `ReportRunStatus`, `Sha256Hash`, `ReportSnapshotRef`, and `ReportResult`.
- The exact constructors and hydrator surface are:
```php
public function __construct(
    ReportExecutionClock $clock,
    ReportTransitionAudit $audit,
    ReportRunHydrator $hydrator,
    int $runTtlSeconds,
    int $pollAfterMs,
);

public function hydrate(
    ReportRunRecord $record,
    string $httpDisposition,
    int $pollAfterMs,
): ReportRun;

public function query(ReportRunRecord $record): ReportQuery;
```
`ReportRunHydrator` declares no constructor and has no container/configuration dependency.
`runTtlSeconds` is `3600..2592000`; `pollAfterMs` is `250..30000`. Task 3 tests pass both scalar values explicitly. Task 13 is the sole future owner of `config/reporting_execution.php` and wires `runs.ttl_seconds` and `runs.poll_after_ms` into this constructor; Task 3 does not create configuration or a provider.
`createOrReuse()` hydrates a successful insert with `created` and a replay with `reused`; every load/transition method hydrates with `reused`. The hydrator passes the configured positive poll value only for `QUEUED` or `MATERIALIZING` and passes `null` for every other status, exactly matching the locked `ReportRun` constructor.

**Step 1: Write failing tests**
Cover:

- reflection initially locks the exact eight Task 3 methods above; Task 4b deliberately replaces this pre-coordinator surface with the final ten-method lease/retry/export-source contract and updates the same reflection authority;
- same organization and key with the same canonical body returns the same run even when the current actor differs from the requester;
- same organization and key with a changed canonical body raises `REPORT_IDEMPOTENCY_CONFLICT` regardless of actor;
- another organization cannot observe or collide with the key;
- every state transition uses a row lock and compare-and-set status predicate; row-lock serialization is proved separately from a deterministic stale-status zero-row compare-and-set scenario;
- the `ReportTransitionAudit` append and `READY` update share one database transaction; after Task 4b this append is concretely the local audit-outbox insert, never remote I/O;
- audit-intent insertion failure leaves the run in `MATERIALIZING`;
- a repeated source event ID does not create a second ready transition;
- terminal states reject illegal transitions;
- hydrator round-trips every Plan 1a run field, including active-state polling, created/reused disposition, immutable expiry, and the locked non-ready sealed-identity projection;
- the reconstructed `ReportDefinition`, `ReportScope`, `ReportFilterSet`, and `ReportQuery` reproduce the stored definition/query hashes and canonical query bytes exactly;
- the store-derived `definition_snapshot_hash` and `result_hash` reproduce the canonical persisted definition and complete seven-member `ReportResult` payload; neither digest is accepted from the caller;
- `row_schema` and `capabilities` round-trip exactly and any mutation of either fails closed before hydration or ready replay;
- every `definition_snapshot` member, scope component, comparison, filter, timezone, locale, or `as_of` mutation fails closed instead of returning a different query;
- the PostgreSQL create race uses a non-throwing conflict path and never queries inside an aborted transaction; the first worker reaches and holds its uncommitted insert behind an explicit post-insert barrier before the second worker is released, and every barrier, process wait, and database lock wait has a bounded timeout with child termination/reaping and database/temp-file cleanup in `finally`;
- the amended Task 2 ownership test remains green before Task 3 exists and after the one canonical Task 3 `ReportRunStore` path exists; it rejects a premature Task 2 declaration and any duplicate declaration elsewhere.

**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php
```
This is the complete DB-free local RED gate. Run `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php` only in CI configured with an isolated PostgreSQL database; SQLite is not a substitute for the `ON CONFLICT`, partial-index, row-lock, JSONB, and transaction assertions.
Expected: the local unit gate fails because the store/hydrator contract does not exist; the PostgreSQL CI feature gate remains red until implementation.
**Step 3: Implement schema and store**
Create the provisional Task 3 interface with the exact eight signatures above and implement it in `EloquentReportRunStore`. Task 4b is the single scheduled amendment point: before any coordinator exists it replaces this surface with the final ten-method contract, and all later tasks consume that final surface without ad hoc extension.

`report_runs` has this closed persistence shape and no UUID/run-shadow identity:

| Column | PostgreSQL type and nullability | Meaning |
|---|---|---|
| `id` | `char(26)` PK | uppercase canonical Plan 1a ULID |
| `organization_id`, `requester_actor_id` | `bigint`, not null | tenant identity and immutable requesting actor metadata |
| `report_code` | `text`, not null | definition code |
| `status` | `text`, not null | exact `ReportRunStatus` value |
| `definition_hash`, `definition_snapshot_hash`, `query_hash`, `source_hash`, `result_hash`, `idempotency_key_hash`, `input_fingerprint` | `char(64)`; only `source_hash` and `result_hash` nullable | lowercase SHA-256 values; the two persistence digests are store-derived and never caller-supplied |
| `contract_version`, `formula_version`, `source_schema_version`, `renderer_version` | `text`, not null | immutable version tuple |
| `definition_snapshot` | `jsonb`, not null | closed canonical definition object below |
| `canonical_query_json` | `text`, not null | exact `ReportQuery::canonicalJson` bytes |
| `scope_holding_organization_ids`, `scope_project_ids`, pre-Task-4e `scope_resource_ids` | `jsonb`, not null | normalized integer arrays; Task 4e performs the mandatory fail-closed cutover from the untyped resource column to typed `scope_resources` before Task 5 |
| `scope_timezone` | `text`, not null | IANA timezone name |
| `filters`, `comparison` | `jsonb`, not null | normalized query arrays |
| `as_of` | `timestamptz`, not null | exact query instant |
| `locale` | `text`, not null | normalized query locale |
| `saved_view_id`, `saved_view_hash` | `char(26)`, `char(64)`, nullable together | optional saved-view ULID plus immutable reference hash |
| `saved_view_revision` | `bigint`, nullable with saved-view identity | positive immutable saved-view revision |
| `snapshot_classification`, `data_classification` | `text`, nullable until ready | exact typed `operational|official` and `standard|sensitive` values |
| `sensitive_column_ids`, `audit_column_ids` | `jsonb`, not null | exact sorted typed classification IDs |
| `snapshot_seal_key_id`, `snapshot_seal_algorithm`, `snapshot_sealed_payload_hash`, `snapshot_seal_signature`, `snapshot_sealed_at` | typed nullable all-or-none seal fields | complete trusted official seal identity |
| `execution_lease_token`, `execution_lease_expires_at`, `execution_heartbeat_at` | typed nullable all-or-none lease fields | fenced materialization attempt ownership |
| `progress` | `smallint`, not null | Plan 1a progress percent |
| `row_count` | `bigint`, nullable | sealed result count |
| `result_metadata`, `totals`, `freshness`, `quality`, `provenance`, `row_schema`, `capabilities` | `jsonb`, nullable except `totals` is not null | complete sealed Plan 1a result payload; both new columns are non-null for `READY`/`EXPIRED` |
| `snapshot_kind`, `snapshot_id` | `text`, nullable | sealed snapshot kind and identifier |
| `snapshot_generated_at`, `snapshot_stale_at` | `timestamptz`, nullable | sealed snapshot freshness instants |
| `snapshot_watermarks` | `jsonb`, nullable | sealed canonical watermarks |
| `error_code` | `text`, nullable | exact safe `ReportErrorCode` value |
| `queued_at`, `created_at`, `updated_at`, `expires_at` | `timestamptz`, not null | lifecycle and immutable retention deadline |
| `started_at`, `ready_at`, `failed_at`, `cancel_requested_at`, `cancelled_at`, `expired_at` | `timestamptz`, nullable | transition instants |

`definition_snapshot` is the canonical JSON object with exactly: `code`, `definition_hash`, four versions, `filters`, `columns`, `sorts`, `formats`, `permission_policy`, `snapshot_classification`, `output_classification`, `publication_readiness`, `supports_subscriptions`. `output_classification` contains exact default data classification, sensitive/audit column IDs and three audit/summary flags. The store encodes through `CanonicalJson`; the hydrator rejects unknown/missing members and reconstructs the typed Plan 1a definition without defaults.

`definition_snapshot_hash` is exactly lowercase SHA-256 of the `CanonicalJson::encode()` bytes of that closed snapshot. `createOrReuse()` computes it from the actual `ReportDefinition` members while building the insert and never accepts it, `definition_hash`, or `query_hash` as a substitute proof. `query()` re-encodes the JSONB value through `CanonicalJson`, verifies `definition_snapshot_hash` with `hash_equals()`, and only then reconstructs the definition and verifies the independent Plan 1a definition/query identities. This is a contained Plan 1b persistence invariant and does not change any Plan 1a constructor or hash.

The sealed JSON columns are also closed constructor inputs, not arbitrary Eloquent casts: `result_metadata` has exactly `row_count`, `generated_at`, `stale_at`; `freshness` is the exact `ReportFreshnessStatus` scalar; `quality` and `provenance` have exactly their Plan 1a constructor fields; `totals`, `row_schema`, and `capabilities` are canonical JSON arrays/objects accepted by `ReportResult`; and snapshot identity comes only from the dedicated snapshot columns. Hydration reconstructs `ReportScope`, `ReportSnapshotRef`, `ReportResultMetadata`, `ReportQuality`, and `ReportProvenance`, then constructs `ReportResult` with the persisted `row_schema` and `capabilities`, never with `$definition->columns` or an invented empty capability list. The duplicated row count/freshness instants must match across the reconstructed DTOs and dedicated columns.

`result_hash` is exactly lowercase SHA-256 of `CanonicalJson::encode()` over the closed projection with keys in this order: `metadata`, `totals`, `freshness`, `quality`, `provenance`, `row_schema`, `capabilities`. `metadata` contains the complete dedicated `ReportSnapshotRef` projection plus `row_count`, `generated_at`, and `stale_at`; all instants use UTC `Y-m-d\TH:i:s.u\Z`, and nested quality/provenance/source/scope objects use their exact closed persisted shapes. `sealReady()` derives this digest from the supplied validated `ReportResult`, stores it with the complete payload, includes it in the immutable audit subject, and uses `reports:run:{runId}:ready:{resultHash}` as the ready event ID. Hydration and ready replay rederive the digest from reread stored values and require `hash_equals()` before returning. A source hash match alone therefore cannot authorize replay of changed totals, metadata, row schema, capabilities, quality, or provenance.

`ReportRunHydrator::query()` reconstructs `ReportScope` from the row organization, the three scope arrays, and `DateTimeZone(scope_timezone)`, then constructs `ReportFilterSet(filters)` and `ReportQuery(definition, scope, filters, comparison, as_of, locale)`. It requires the resulting `queryHash` and `canonicalJson` to equal the dedicated stored hash and exact `canonical_query_json`; no HTTP normalizer, current registry lookup, or mutable current definition participates.

`input_fingerprint` is lowercase SHA-256 over canonical `definition_snapshot_hash`, decoded exact query, and saved-view reference `null|{id,revision,hash}`. The store derives all identities; the idempotency key is not part of the body fingerprint and its separate hash selects the replay slot. Saved-view members are all null or all valid and are reauthorized on retry.

The migration creates exactly these named constraints and indexes:

- `report_runs_status_check`, restricting status to all six Plan 1a run states;
- `report_runs_progress_check`, enforcing `progress BETWEEN 0 AND 100`;
- `report_runs_error_code_check`, with exact predicate `(status = 'failed' AND error_code IN ('REPORT_NOT_FOUND','REPORT_SCOPE_FORBIDDEN','REPORT_REQUEST_INVALID','REPORT_FILTER_UNSUPPORTED','REPORT_FILTER_VALUE_NOT_FOUND','REPORT_FILTER_RANGE_INVALID','REPORT_SORT_UNSUPPORTED','REPORT_CURSOR_INVALID','REPORT_IDEMPOTENCY_KEY_INVALID','REPORT_IDEMPOTENCY_CONFLICT','REPORT_SNAPSHOT_NOT_READY','REPORT_EXPORT_NOT_READY','REPORT_OFFICIAL_SNAPSHOT_UNSEALED','REPORT_SNAPSHOT_EXPIRED','REPORT_EXPORT_EXPIRED','REPORT_EXPORT_LIMIT_EXCEEDED','REPORT_RATE_LIMITED','REPORT_SOURCE_UNAVAILABLE','REPORT_DEPENDENCY_FAILED','REPORT_INTERNAL_ERROR')) OR (status <> 'failed' AND error_code IS NULL)`;
- `report_runs_definition_hash_check`, `report_runs_definition_snapshot_hash_check`, `report_runs_query_hash_check`, `report_runs_source_hash_check`, `report_runs_result_hash_check`, `report_runs_idempotency_hash_check`, and `report_runs_input_fingerprint_check`, enforcing lowercase 64-hex when present;
- `report_runs_expiry_order_check`, enforcing `expires_at > created_at`;
- `report_runs_ready_identity_check`, requiring for `READY` non-null `source_hash`, `result_hash`, `snapshot_kind`, `snapshot_id`, `snapshot_generated_at`, `snapshot_watermarks`, `row_count`, `result_metadata`, `freshness`, `quality`, `provenance`, `row_schema`, `capabilities`, `ready_at`, `progress=100`, and null `error_code`, plus `snapshot_stale_at IS NULL OR snapshot_stale_at >= snapshot_generated_at`;
- `report_runs_terminal_timestamps_check`, requiring only the matching `failed_at`, `cancelled_at`, or `expired_at` for `FAILED`, `CANCELLED`, or `EXPIRED`, and forbidding those three terminal timestamps otherwise;
- `report_runs_expired_seal_check`, requiring an `EXPIRED` row to retain the complete formerly-ready sealed identity including `result_hash`, `row_schema`, and `capabilities`, plus `ready_at`, with `expired_at >= expires_at`;
- unique `report_runs_org_idempotency_unique` on `(organization_id, idempotency_key_hash)`;
- `report_runs_org_id_lookup` on `(organization_id, id)`;
- partial `report_runs_queued_idx` on `(queued_at, id)` where `status='QUEUED'`;
- partial `report_runs_retention_idx` on `(expires_at, id)` where `status IN ('READY','EXPIRED')`.

`expires_at` is computed once as `clock.now() + runTtlSeconds` during successful insert and is never extended by reuse, polling, progress, failure, cancellation, or expiry. `expired_at` records only the `READY -> EXPIRED` transition. Persistent sealed identity is never erased. Because the locked Plan 1a `ReportRun` constructor requires sealed fields only for `READY`, hydration of `EXPIRED` deliberately projects null/empty sealed DTO fields while retaining and validating the sealed columns in persistence; Task 12 reads the retained record identity for audit/cleanup and status alone makes downloads unavailable.

At PostgreSQL `READ COMMITTED`, `createOrReuse()` starts one transaction and executes its fully enumerated insert with `ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id`. A returned ID yields disposition `created`. With no returned row, the same healthy transaction locks by the exact unique scope, compares `input_fingerprint` through `hash_equals()`, and either hydrates disposition `reused` or throws `ReportContractException::fromCode(REPORT_IDEMPOTENCY_CONFLICT)`. It never catches SQLSTATE `23505`, never predicates identity on actor, and another organization neither observes nor collides.

Every transition transaction locks `(organization_id,id)`, validates current status and immutable identity, and performs an update with both `id` and the expected current `status` in the predicate. Zero updated rows is a defensive concurrency conflict. The PostgreSQL gate distinguishes ordinary `FOR UPDATE` serialization, where the later transaction rereads the new status and fails its state precondition, from the exact zero-row CAS branch. The latter deterministically commits a status change through an independently coordinated transaction, then invokes the same production status-qualified update primitive with the now-stale expected status and proves that it affects zero rows and maps to `REPORT_SNAPSHOT_NOT_READY`. It does not claim that a correctly retained PostgreSQL row lock permits another transaction to change the row between the production lock and update. A serialized precondition failure is not claimed as zero-row CAS evidence. Missing or cross-tenant rows map to `REPORT_NOT_FOUND`; expired rows to `REPORT_SNAPSHOT_EXPIRED`; an active/not-yet-valid state or lost compare-and-set maps to `REPORT_SNAPSHOT_NOT_READY`; malformed persisted identity maps to `REPORT_INTERNAL_ERROR`; idempotency mismatch maps only to `REPORT_IDEMPOTENCY_CONFLICT`. No new error enum is introduced. `fail()` persists only a supplied Plan 1a `ReportErrorCode`.

`sealReady()` validates identity before mutation, derives the complete closed result projection and `result_hash`, then in one transaction locks the row, requires `MATERIALIZING`, and derives deterministic event key `reports:run:{runId}:ready:{resultHash}`. It appends the closed `report.run.ready` audit outbox intent with source/result hashes before compare-and-set; intent failure rolls back everything. Core audit I/O is never executed in this transaction. A replay against `READY` returns only when every sealed identity field and rederived result hash match, without another intent/update; drift fails closed.

Task 3 also changes the already tracked `ExecutionContractsTest` rather than dropping it from historical gates. Its Task 2 ownership assertion scans the exact Task 2-owned files and proves that none declares `ReportRunStore`. Separately, it scans reporting production declarations: before the canonical Task 3 file exists, zero declarations are allowed; after it exists, exactly one canonical path is required. A duplicate fails. Task 4b updates this same reflection authority from the provisional eight methods to the final ten; there is never a second interface.
**Step 4: Prove GREEN**
Run locally, without opening a database connection:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php
```
Run only in isolated PostgreSQL CI:
```bash
vendor/bin/phpunit tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php
```
The local Task 3 gate proves its provisional eight-method surface, constructor/hydrator signatures, closed snapshot/query reconstruction, DTO projection, and corruption rejection. Task 4b reruns and supersedes the reflection gate with the final ten-method surface before coordinators. The PostgreSQL gate proves constraints, concurrency, tenant isolation, row locking, audit-intent ordering, rollback, replay, and idempotency.
**Step 5: Static checks**
Run only DB-free static checks:
```bash
php -l database/migrations/2026_07_26_000001_create_report_runs_table.php
vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence tests/Unit/Reporting/Persistence --no-progress
```
Do not run the migration locally.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php database/migrations/2026_07_26_000001_create_report_runs_table.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence tests/Feature/Reporting/Persistence tests/Unit/Reporting/Persistence tests/Unit/Reporting/Execution/ExecutionContractsTest.php
git commit -m "feat[reports]: сохранить запуски и атомарные переходы"
```

### Task 4a: Amend Plan 1a typed seal/classification contracts and fix access decisions

**Dependency:** Task 3 is committed. This prerequisite is a separate canonical Plan 1a ownership commit and must pass a fresh Plan 1a evidence/lock review before any Plan 1b coordinator, job, row handler, or export store is implemented.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewReferenceResolver.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportOutputClassification.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewRef.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotSeal.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportDataClassification.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSnapshotClassification.php`
- Create: `tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php`
- Create: `tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Access/ReportAccessService.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportExportAction.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportRunAction.php`
- Modify: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinition.php`
- Modify: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportCatalogResource.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php`
- Modify: `database/migrations/2026_07_26_000001_create_report_runs_table.php`
- Modify: `docs/reports/contracts/plan-1a-completion.schema.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.sha256`
- Modify: `docs/reports/contracts/plan-1a-gate-evidence.schema.json`
- Modify: `docs/reports/contracts/reporting-admin-resources.v1.schema.json`
- Modify: `scripts/reporting/build-plan-1a-evidence.php`
- Modify: `scripts/reporting/run-plan-1a-gates.php`
- Modify: `tests/Architecture/Reporting/PlanOneAHandoffContractTest.php`
- Modify: `tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php`
- Modify: `tests/Architecture/Reporting/ThinReportControllerTest.php`
- Modify: `tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php`
- Modify: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-ci-authorization.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-ci-malformed.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json`
- Modify: `tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json`
- Modify: `tests/Support/Reporting/FakeReportingActions.php`
- Modify: `tests/Support/Reporting/HermeticReportingHttpHarness.php`
- Modify: `tests/Support/Reporting/ReportDefinitionBuilder.php`
- Modify: `tests/Support/Reporting/ReportRunBuilder.php`
- Modify: `tests/Unit/Reporting/Access/ReportAccessServiceTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php`
- Modify: `tests/Unit/Reporting/Execution/ExecutionContractsTest.php`
- Modify: `tests/Unit/Reporting/Http/ReportControllerContractTest.php`
- Modify: `tests/Unit/Reporting/Http/ReportResourceSchemaTest.php`
- Modify: `tests/Unit/Reporting/Input/ReportInputNormalizerTest.php`
- Modify: `tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php`
- Modify: `tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php`
- Modify: `tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php`

**Task file count:** 53 exact tracked files: 8 `Create` and 45 `Modify`. Generated `build/reports/plan-1a-*.json` files remain ignored and untracked.

**Interfaces produced:**

```php
enum ReportSnapshotClassification: string
{
    case OPERATIONAL = 'operational';
    case OFFICIAL = 'official';
}

enum ReportDataClassification: string
{
    case STANDARD = 'standard';
    case SENSITIVE = 'sensitive';
}

final readonly class ReportSnapshotSeal
{
    public function __construct(
        public string $keyId,
        public string $algorithm,
        public Sha256Hash $sealedPayloadHash,
        public string $signature,
        public DateTimeImmutable $sealedAt,
    );
}

final readonly class ReportOutputClassification
{
    public function __construct(
        public ReportDataClassification $defaultClassification,
        public array $sensitiveColumnIds,
        public array $auditColumnIds,
        public bool $totalsSensitive,
        public bool $totalsAudit,
        public bool $provenanceAudit,
    );

    public function requiresSensitiveForRows(): bool;
    public function requiresAuditForRows(): bool;
    public function requiresSensitiveForColumns(array $columnIds): bool;
    public function requiresAuditForColumns(array $columnIds): bool;
    public function requiresSensitiveForSummary(): bool;
    public function requiresAuditForSummary(): bool;
}

final readonly class ReportSavedViewRef
{
    public function __construct(
        public string $id,
        public int $revision,
        public Sha256Hash $hash,
    );
}

interface ReportSavedViewReferenceResolver
{
    public function resolve(ReportExecutionContext $context, string $savedViewId): ReportSavedViewRef;
    public function assertCurrent(ReportExecutionContext $context, ReportSavedViewRef $reference): void;
}
```

`ReportDefinition` adds, after `ReportPermissionPolicy` and before publication readiness, exact constructor parameters `ReportSnapshotClassification $snapshotClassification` and `ReportOutputClassification $outputClassification`. `ReportSnapshotRef` adds exact final parameters `ReportSnapshotClassification $classification` and `?ReportSnapshotSeal $seal`.

`ReportOutputClassification` is canonical, not advisory. Both column arrays must be lists of IDs matching `^[a-z][a-z0-9_]{0,63}$`; construction sorts them lexicographically, rejects duplicates, and `ReportDefinition` rejects every classified ID absent from its normalized `columns`. Empty arrays and false flags are explicit. Its six methods have this exact truth table:

```text
requiresSensitiveForRows()
  = defaultClassification === SENSITIVE
    OR sensitiveColumnIds is non-empty
    OR totalsSensitive
requiresAuditForRows()
  = auditColumnIds is non-empty
    OR totalsAudit
requiresSensitiveForColumns(selected)
  = defaultClassification === SENSITIVE
    OR intersection(selected, sensitiveColumnIds) is non-empty
requiresAuditForColumns(selected)
  = intersection(selected, auditColumnIds) is non-empty
requiresSensitiveForSummary()
  = defaultClassification === SENSITIVE
    OR totalsSensitive
requiresAuditForSummary()
  = totalsAudit
    OR provenanceAudit
```

Every `selected` list is validated with the same ID grammar, sorted, duplicate-free, and a subset of definition columns before these methods are called. Default `SENSITIVE` protects the entire output. Rows cover selected columns plus totals; summary covers totals plus provenance. Sensitive and audit decisions are independent: neither permission implies the other.

`ReportSnapshotRef` requires snapshot kind `^[a-z][a-z0-9_.:-]{0,63}$` and snapshot ID `^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$`; trimming or normalization is forbidden. `OFFICIAL` requires a non-null seal, `OPERATIONAL` forbids one, and `seal.sealedAt >= generatedAt` is checked here because the seal itself has no generation time. The same grammar and time ordering are repeated by Task 4c verification input.

`ReportSnapshotSeal` requires key ID `^[a-z][a-z0-9_.:-]{2,127}$`, algorithm exactly `ed25519-sha256`, and a canonical unpadded base64url signature: exactly 86 characters matching `^[A-Za-z0-9_-]{86}$`, no `=`, decodes strictly to exactly 64 bytes, and re-encoding those bytes without padding must equal the original string.

Both retry action ports add exact final `IdempotencyKey $idempotencyKey` parameters. Both retry controllers require the same `Idempotency-Key` header contract as create and construct the Plan 1a value object before calling the action. No server-generated or random retry key exists.

Saved views are never represented by ID alone after resolution. Plan 1c implements the sole `ReportSavedViewReferenceResolver`; Plan 1b resolves an HTTP create ID to `ReportSavedViewRef`, persists all three fields, includes them in the input fingerprint, and calls `assertCurrent()` on retry. Revision/hash drift or revoked access fails closed. Null remains the explicit no-saved-view case.

Task 4a absorbs the persistence compatibility slice required by these constructor changes. `ReportRunStore::createOrReuse()` accepts `?ReportSavedViewRef`; the definition snapshot and fingerprint persist exact snapshot/data/output classification, sensitive/audit IDs and flags, snapshot classification/seal, and saved-view ID/revision/hash. The Task 3 migration, record casts/fillable, store, hydrator, builders, persistence tests, and corruption matrix change atomically in this commit. Missing members are never defaulted during hydration. Task 4b subsequently modifies the same eight persistence paths from this typed Task 4a baseline to add the final ten-method store, result identity, transport/audit intents, and execution leases; it must not reintroduce an untyped compatibility state.

**Step 1: Write failing contract and regression tests**

Lock exact constructors/methods, all six truth-table formulas, persistence signatures, resource shapes, and retry signatures. Prove invalid/unknown/unsorted/duplicate classified columns, selected-column non-membership, malformed kind/ID/key/signature, padded/noncanonical/wrong-length base64url, unsupported algorithm, seal before generation, `OFFICIAL` without a seal, and `OPERATIONAL` with a seal are rejected. Add independent access regressions proving `VIEW_SENSITIVE` and `VIEW_AUDIT` require their exact visibility. Prove default-sensitive applies globally, rows include columns/totals, summary includes totals/provenance, sensitive permission does not grant audit, audit does not grant sensitive, and global/definition halves cannot authorize independently. Prove four HTTP cases reject before action invocation: missing/invalid run retry key and missing/invalid export retry key. Saved-view references reject invalid ULID/revision/hash. The hermetic malformed matrix is exact ordered `20/20` with no missing, duplicate, reordered, or extra case, replacing the former `16/16`.

**Step 2: Prove RED**

Run the amended reporting contract/access tests and Plan 1a tooling/architecture suites. Expected: typed contracts are absent and current `ReportAccessService` incorrectly maps both operations to `canView`.

**Step 3: Implement and repin Plan 1a evidence**

Change the access match exactly to:

```php
ReportOperation::VIEW_SENSITIVE => $visibility->canViewSensitive,
ReportOperation::VIEW_AUDIT => $visibility->canViewAudit,
```

Keep `DRILL_DOWN` on base view plus source access; Task 6 applies typed classification before it calls the base operation. Add snapshot/data classifications, typed seal, saved-view reference/resolver, explicit retry keys, the Task 3 persistence compatibility slice, resources, all transitive production/test callsites, and exact `20/20` malformed records to canonical projections, signatures, HTTP matrices, and ownership evidence.

`build-plan-1a-evidence.php` and `run-plan-1a-gates.php` add a canonical `TASK_FOUR_A_SUBJECT`, the exact ordered 53-path manifest, sanctioned descendant ownership after Plan 1a Tasks 7 and 11, the new signature/field/resource contracts, and actual PHPUnit execution counts. The tracked lock and sidecar are generated before commit and bind all 53 paths, never the old 21-path assumption. Precommit validation accepts the parent Plan 1a lock plus the exact staged Task 4a manifest but never predicts a commit SHA. After the Task 4a commit, ignored completion/evidence is regenerated with the actual Task 4a commit SHA; normal gates and `--verify-existing` reread it byte-for-byte. A filesystem hash/mtime fence proves `--verify-existing` performs no write. No Plan 1b alias is permitted.

**Step 4: Prove GREEN**

Before commit, run the complete DB-free Plan 1a reporting suite, targeted contract/access/resource/persistence/tooling tests, syntax-check the amended migration without executing it, and run scoped PHPStan over Reporting plus every changed PHP test. Stage exactly the 53-path manifest, assert sorted staged-set equality, verify the generated tracked lock/sidecar, and obtain an independent precommit review.

Commit once. Then generate ignored evidence with the actual Task 4a completion SHA, run normal gates and `--verify-existing`, and prove the no-write fence. PostgreSQL persistence tests remain isolated-CI-only; SQLite, local DB, browser, authorization smoke, and migrations are forbidden. Expected: all permitted gates pass, generated artifacts remain ignored, and the lock binds all 53 paths and typed signatures.

**Step 5: Commit**

```bash
git add <the 53 exact tracked paths listed above>
# Require sorted staged-set equality with the exact 53-path manifest and an independent precommit lock/evidence review.
git commit -m "fix[reports]: зафиксировать классификацию и печать снимков"
```

After commit, regenerate ignored completion evidence with the actual commit SHA, run normal and `--verify-existing` gates, require no-write hash/mtime equality, exact 53-path `diff-tree` equality, and a clean Task 4a scoped status.

### Task 4b: Add durable PostgreSQL dispatch intents, publisher, and reconciler

**Dependency:** Historical Task 4a is immutable: exact 53-path commit `0b581469a3ad39d4ce5eff5c41072f5ef3f745f7` with parent `786e5f3433d04baf35c81789178e1e83012e0916`. Its canonical Plan 1a evidence is green. This task is completed before `ReportRunCoordinator`.

Task 4b remains an exact 39-path commit. Its eight deliberate overlaps with Task 4a are `ReportRunStore.php`, `EloquentReportRunStore.php`, `ReportRunRecord.php`, `ReportRunHydrator.php`, the Task 3 run migration, `EloquentReportRunStoreTest.php`, `ReportRunHydratorTest.php`, and `ExecutionContractsTest.php`. They must start from the typed Task 4a persistence baseline. Task 4b may add final store methods, result/export identity, dispatch/audit intents, and execution leases, but may not delete, default, loosen, or reinterpret Task 4a classification, seal, saved-view, fingerprint, hydration, resource, or corruption invariants.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportDispatchIntentStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchAggregate.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchTopic.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntent.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchLease.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchPublishSummary.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchBackoffPolicy.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntentPublisher.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntentReconciler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditIntentStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntent.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntentLease.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportDispatchIntentRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportAuditIntentRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuditIntentStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/LaravelReportDispatchIntentPublisher.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/OutboxReportTransitionAudit.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/PublishReportDispatchIntentsCommand.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportDispatchIntentsCommand.php`
- Create: `database/migrations/2026_07_26_000002_create_report_dispatch_intents_table.php`
- Create: `database/migrations/2026_07_26_000003_create_report_audit_intents_table.php`
- Create: `tests/Unit/Reporting/Dispatch/ReportDispatchBackoffPolicyTest.php`
- Create: `tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php`
- Create: `tests/Unit/Reporting/Dispatch/ReportDispatchIntentReconcilerTest.php`
- Create: `tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php`
- Create: `tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php`
- Create: `tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php`
- Modify: `database/migrations/2026_07_26_000001_create_report_runs_table.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportRunRetrySource.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportRunExportSource.php`
- Modify: `tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php`
- Modify: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Modify: `tests/Unit/Reporting/Execution/ExecutionContractsTest.php`

**Task file count:** 39 exact files.

**Locked interfaces:**

Task 4b replaces the pre-coordinator Task 3 surface with this exact ten-method run-store contract: `createOrReuse`, `get`, `queryForRun`, `retrySource`, `exportSource`, `claimMaterialization`, `persistProgress`, `sealReady`, `fail`, and `cancel`. The lease-bearing signatures are:

```php
public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?ReportSavedViewRef $savedView, IdempotencyKey $idempotencyKey): ReportRun;
public function retrySource(ReportExecutionContext $context, string $runId): ReportRunRetrySource;
public function exportSource(ReportExecutionContext $context, string $runId): ReportRunExportSource;
public function claimMaterialization(ReportExecutionContext $context, string $runId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun;
public function persistProgress(ReportExecutionContext $context, string $runId, string $leaseToken, ReportProgress $progress, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun;
public function sealReady(ReportExecutionContext $context, string $runId, string $leaseToken, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun;
public function fail(ReportExecutionContext $context, string $runId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun;
```

`ReportRunRetrySource` contains exact `ReportRun $run`, `ReportQuery $query`, `?ReportSavedViewRef $savedView`, and `?ReportErrorCode $errorCode`. It is returned only for `FAILED`, `CANCELLED`, or `EXPIRED`; retry never reads sealed result rows/artifacts from an expired source. `READY` is not retryable and requires the separate future refresh operation. The retry handler reauthorizes the definition, query scope, and saved-view reference before creating a child.

`ReportRunExportSource` has this exact constructor and no additional public fields:

```php
public function __construct(
    public ReportRun $run,
    public ReportQuery $query,
    public ReportResult $result,
    public Sha256Hash $resultHash,
    public ReportSnapshotRef $snapshot,
    public ReportDataClassification $dataClassification,
    public ReportOutputClassification $outputClassification,
    public string $contractVersion,
    public string $formulaVersion,
    public string $sourceSchemaVersion,
    public string $rendererVersion,
);
```

It contains the ready unexpired run, complete persisted result, exact snapshot kind/ID/classification/seal, typed output classification, and all four versions. Its constructor rederives and compares every duplicated identity. `exportSource()` rederives `resultHash`; `EXPIRED` maps to `REPORT_SNAPSHOT_EXPIRED`. This is the sole export-identity input; Task 7 never reconstructs identity from a partial run DTO.

Extend the Task 4a typed persistence baseline in the eight declared overlap files. Preserve and revalidate its `snapshot_classification`, definition `data_classification`, exact `sensitive_column_ids`, `audit_column_ids`, three audit-summary flags, nullable seal tuple, and nullable all-or-none saved-view tuple. Task 4b adds the final result identity and nullable all-or-none `execution_lease_token`, `execution_lease_expires_at`, `execution_heartbeat_at`, plus dispatch/audit integration. The preserved and new fields participate in `definition_snapshot_hash`, `input_fingerprint`, complete `result_hash`, ready/expired/lease constraints, audit subject, replay validation, and corruption tests. A run may not hydrate/replay by silently defaulting an absent classification, seal member, saved-view revision/hash, row schema, capability, or sensitive-column set.

```php
interface ReportDispatchIntentStore
{
    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void;
    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void;
    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array;
    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void;
    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void;
    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int;
}

interface ReportAuditIntentStore
{
    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void;
    public function dueIds(int $limit, DateTimeImmutable $now): array;
    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease;
    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent;
    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void;
    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void;
    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int;
}
```

The remaining created production symbols are reflection-locked to these exact shapes:

```php
enum ReportDispatchAggregate: string { case RUN = 'run'; case EXPORT = 'export'; }
enum ReportDispatchTopic: string { case MATERIALIZE_RUN = 'materialize_run'; case GENERATE_EXPORT = 'generate_export'; }

final readonly class ReportDispatchIntent
{
    public function __construct(
        public string $id,
        public string $eventKey,
        public int $organizationId,
        public ReportDispatchAggregate $aggregate,
        public string $aggregateId,
        public ReportDispatchTopic $topic,
        public int $attemptCount,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $availableAt,
    );
}

final readonly class ReportDispatchLease
{
    public function __construct(
        public ReportDispatchIntent $intent,
        public string $leaseToken,
        public DateTimeImmutable $leaseExpiresAt,
    );
}

final readonly class ReportDispatchPublishSummary
{
    public function __construct(
        public int $scanned,
        public int $claimed,
        public int $published,
        public int $retryScheduled,
        public int $deadLettered,
        public int $skipped,
    );
}

final readonly class ReportAuditIntent
{
    public function __construct(
        public string $id,
        public string $eventKey,
        public string $eventType,
        public int $organizationId,
        public int $actorId,
        public array $subject,
        public int $attemptCount,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $availableAt,
    );
}

final readonly class ReportAuditIntentLease
{
    public function __construct(
        public string $intentId,
        public string $leaseToken,
        public DateTimeImmutable $leaseExpiresAt,
        public int $attemptCount,
    );
}

ReportDispatchBackoffPolicy::nextAttemptAt(int $attempt, DateTimeImmutable $occurredAt): DateTimeImmutable;
ReportDispatchIntentPublisher::publishBatch(int $limit, DateTimeImmutable $occurredAt): ReportDispatchPublishSummary;
ReportDispatchIntentReconciler::reconcile(int $limit, DateTimeImmutable $occurredAt): ReportDispatchPublishSummary;
LaravelReportDispatchIntentPublisher::publish(ReportDispatchIntent $intent): void;
ReportAuditDispatcher::dispatch(string $intentId): void;
OutboxReportTransitionAudit::append(string $eventId, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void;
PublishReportDispatchIntentsCommand::handle(): int;
ReconcileReportDispatchIntentsCommand::handle(): int;
```

`EloquentReportRunStore` has the exact constructor order
`(ReportExecutionClock $clock, ReportTransitionAudit $audit, ReportRunHydrator $hydrator, ReportDispatchIntentStore $dispatchIntents, int $runTtlSeconds, int $pollAfterMs)`.
`ReportDispatchIntentPublisher` has constructor `(ReportDispatchIntentStore $store, LaravelReportDispatchIntentPublisher $transport, ReportDispatchBackoffPolicy $backoff, int $leaseSeconds)`. `ReportDispatchIntentReconciler` has `(ReportDispatchIntentStore $store, ReportDispatchIntentPublisher $publisher)`. `LaravelReportDispatchIntentPublisher` has `(ReportMaterializationDispatcher $runs, ReportExportDispatcher $exports)`. `OutboxReportTransitionAudit` has `(ReportAuditIntentStore $store)`. `PublishReportDispatchIntentsCommand` has `(ReportDispatchIntentPublisher $publisher, int $batchSize)` and `ReconcileReportDispatchIntentsCommand` has `(ReportDispatchIntentReconciler $reconciler, int $batchSize)`.

The two Eloquent intent stores implement only their locked interfaces; both record models expose only casts/table metadata and no business workflow. Batch size is `1..500`, lease seconds `15..300`, and invalid constructor configuration fails before any query. Architecture/reflection tests lock every created production basename, enum case, constructor parameter order/type/nullability, public property, and public method above; no undeclared public workflow method is allowed.

The single backoff contract is:

```text
attempt must be 1..12
base_seconds = 15
delay_seconds = min(3600, 15 * 2^(attempt - 1))
base_time = occurredAt converted to UTC and truncated toward the past to whole seconds
next_attempt_at = base_time + delay_seconds seconds
```

Thus attempts 1..9 yield `15,30,60,120,240,480,960,1920,3600`; attempts 10..12 remain capped at `3600`. Integer arithmetic only is allowed. Tests mutate every attempt 1..12, attempts 0/13, exponent offset, base, cap, UTC conversion, microsecond truncation, and boundary timestamps.

Both transport `add*Intent()` calls and audit `add()` are transaction-required. Run/export creation inserts transport intents in the aggregate transaction. `OutboxReportTransitionAudit` implements Task 2 solely through `ReportAuditIntentStore::add()` in the aggregate transition transaction. No store transaction performs Redis or Core-audit I/O.

Initial execution event keys are exactly `reports:run:{runId}:materialize:initial` and `reports:export:{exportId}:generate:initial`. Watchdog recovery keys are `reports:run:{runId}:materialize:recovery:{expiredLeaseToken}` and `reports:export:{exportId}:generate:recovery:{expiredLeaseToken}`. Thus recovery is durable and unique without reopening a published intent.

Migration `000002` creates `report_dispatch_intents` with this exact PostgreSQL schema: `id char(26) primary key`; `event_key text not null`; `organization_id bigint not null`; `aggregate_type text not null`; `aggregate_id char(26) not null`; `topic text not null`; `status text not null default 'pending'`; `attempt_count smallint not null default 0`; `occurred_at timestamptz(6) not null`; `available_at timestamptz(6) not null`; nullable `lease_token uuid`, `lease_expires_at timestamptz(6)`, `published_at timestamptz(6)`, `dead_lettered_at timestamptz(6)`, `last_error_code text`; and `created_at/updated_at timestamptz(6) not null`. It adds:

- FK `report_dispatch_intents_organization_fk` to `organizations(id)` with `ON DELETE RESTRICT`;
- unique `report_dispatch_intents_event_key_unique(event_key)`;
- checks `report_dispatch_intents_aggregate_check` (`run|export`), `report_dispatch_intents_topic_check` (exact run/materialize and export/generate mapping), `report_dispatch_intents_status_check`, `report_dispatch_intents_attempt_check` (`0..12`), `report_dispatch_intents_lease_shape_check` (token/expiry both non-null only for `leased`), and `report_dispatch_intents_terminal_shape_check` (only `published` has `published_at`; only `dead_letter` has `dead_lettered_at` and non-null safe error; pending/leased have neither terminal timestamp);
- partial B-tree `report_dispatch_intents_due_idx(available_at,id) WHERE status='pending'`;
- partial B-tree `report_dispatch_intents_lease_expiry_idx(lease_expires_at,id) WHERE status='leased'`;
- index `report_dispatch_intents_aggregate_idx(organization_id,aggregate_type,aggregate_id)`.

There is deliberately no polymorphic aggregate FK. Every update is status-and-lease-token fenced, terminal rows are immutable, and unique event insertion occurs in the aggregate transaction.

Migration `000003` creates `report_audit_intents`: `id char(26) primary key`; unique `event_key text not null`; `event_type text not null`; `organization_id bigint not null`; `actor_id bigint not null`; `subject jsonb not null`; `status text not null default 'pending'`; `attempt_count smallint not null default 0`; `occurred_at/available_at timestamptz(6) not null`; nullable `lease_token uuid`, `lease_expires_at/delivered_at/dead_lettered_at timestamptz(6)`, `last_error_code text`; `created_at/updated_at timestamptz(6) not null`. It adds FK `report_audit_intents_organization_fk` with restrict, unique `report_audit_intents_event_key_unique`, checks `report_audit_intents_event_type_check`, `report_audit_intents_status_check`, `report_audit_intents_attempt_check`, `report_audit_intents_subject_object_check` (`jsonb_typeof(subject)='object'`), `report_audit_intents_lease_shape_check`, and `report_audit_intents_terminal_shape_check`; partial indexes `report_audit_intents_due_idx(available_at,id) WHERE status='pending'` and `report_audit_intents_lease_expiry_idx(lease_expires_at,id) WHERE status='leased'`; and index `report_audit_intents_organization_event_idx(organization_id,event_type,occurred_at,id)`. The event-type check allows exactly the fourteen event names enumerated in the subject block below. `delivered` means Core append succeeded, never Redis acceptance.

Audit subjects are recursively closed and event-specific:

```text
report.run.queued:
  run_id, report_code, status, definition_hash, query_hash,
  contract_version, formula_version, source_schema_version, renderer_version,
  saved_view{id,revision,hash}|null
report.run.materializing:
  run_id, report_code, status, definition_hash, query_hash
report.run.ready:
  run_id, report_code, status, definition_hash, query_hash, source_hash,
  result_hash, snapshot{kind,id,classification,seal_digest|null},
  data_classification, row_count,
  contract_version, formula_version, source_schema_version, renderer_version
report.run.failed:
  run_id, report_code, status, definition_hash, query_hash, error_code
report.run.cancelled:
  run_id, report_code, status, definition_hash, query_hash
report.run.expired:
  run_id, report_code, status, definition_hash, query_hash, source_hash,
  result_hash, snapshot_id, expired_at
report.export.queued:
  export_id, run_id, report_code, status, definition_hash, query_hash,
  source_hash, result_hash, snapshot_id, snapshot_classification,
  data_classification, format, columns, locale, timezone, renderer_version
report.export.running | report.export.uploading:
  export_id, run_id, report_code, status, format
report.export.ready:
  export_id, run_id, report_code, status, definition_hash, query_hash,
  source_hash, result_hash, snapshot_id, format, renderer_version,
  row_count, artifact{version_id,etag,checksum,size,mime}
report.export.failed:
  export_id, run_id, report_code, status, format, error_code
report.export.cancelled:
  export_id, run_id, report_code, status, format
report.export.expired:
  export_id, run_id, report_code, status, format, version_id, occurred_at
report.export.artifact_deleted:
  export_id, run_id, report_code, status, format, version_id, occurred_at
```

Every shape has exactly the listed keys. IDs/hashes/versions use their canonical grammars, `columns` is a sorted unique column-ID list, nested objects reject extra keys, and `seal_digest` is the SHA-256 of canonical public seal identity rather than signature/key ID. Status equals the event suffix for state-transition events. `report.export.artifact_deleted` is not a new aggregate status: its subject requires `status='expired'`, physical deletion leaves the historical export `EXPIRED`, and stored seal/result/artifact identity remains intact. Positive contract tests accept both `report.export.expired` and subsequent `report.export.artifact_deleted(status=expired)`; negative tests reject `artifact_deleted(status=ready)`, unknown status, missing/extra keys, and identity clearing. Rows/cells, filters, query JSON, object path, URLs, credentials, tokens, signatures, key IDs, authorization facts, transport metadata, and exception text are recursively forbidden.

Transport `claimDue()` uses `FOR UPDATE SKIP LOCKED`; only `ReportDispatchIntentPublisher` publishes run/export IDs and marks only transport intents published. `ReportAuditOutboxScheduler` calls audit `dueIds()` and `ReportAuditDispatcher::dispatch($intentId)` without changing audit state. The ID-only audit job calls `claim()` with its queue-envelope UUID; `claim()` increments attempts and returns a lease, `loadLeased()` rereads the fenced closed payload, Core append uses `event_key` idempotently, and only then `acknowledge()` sets `delivered`. Crash after Redis acceptance leaves audit pending; crash after Core append before acknowledge is safely replayed after lease expiry.

Both stores use the exact deterministic backoff formula above. Before attempt 12, a transport failure returns the fenced intent to pending with the calculated `available_at`. At attempt 12 Task 4b atomically dead-letters the transport intent and CAS-fails only a still-queued `RUN`. `EloquentReportDispatchIntentStore::markPublicationFailed()` is the sole narrow cross-record transaction owner for this branch: it locks the leased intent and organization/run row, verifies `RUN|MATERIALIZE_RUN`, matching token and attempt 12, then updates only `ReportRunRecord(status=QUEUED)` through the same status-qualified failure primitive and safe error mapping used by `EloquentReportRunStore`; zero-row CAS leaves the aggregate unchanged while still terminally dead-lettering the exhausted intent. Architecture tests forbid every other dispatch component from referencing the run record and prove the two failure primitives remain behaviorally identical. Task 4b must not query, fake, or terminally mutate an export because export persistence does not exist yet. Task 7 completes the symmetric export branch by deliberately modifying the dispatch store and its PostgreSQL test after `ReportExportRecord` exists. Audit `failDelivery()` returns to pending before attempt 12 and atomically dead-letters on attempt 12; it never rewrites an aggregate, retains payload for operator replay, and triggers a critical fail-hard gate. Reclaim clears expired leases without incrementing attempts.

**Step 1: Write failing tests**

Cover both exact schemas, every named constraint/index/predicate/precision/default, separate transport/audit tables, unique keys, aggregate rollback, transaction-required audit writer, every closed event subject and unknown/forbidden member, disjoint claims, lease fencing, Redis-accept crash while audit remains pending, Core-append-before-ack crash, event-key replay, the full backoff mutation matrix, attempt-12 audit dead-letter, attempt-12 queued-run terminal CAS, explicit absence of an export-record/table branch, reclaim without attempt increment, topic separation, and forbidden direct dispatcher/remote-audit calls.

**Step 2: Prove RED**

Run dispatch unit tests and amended DB-free run tests locally. Run PostgreSQL concurrency/constraint suites only in isolated CI; SQLite is not a substitute.

**Step 3: Implement**

Implement the exact table, typed values, store, publisher, reconciler, commands, and run-store integration. Laravel `afterCommit()` may only wake the durable publisher; correctness tests suppress it and prove the reconciler alone publishes every committed intent. Queue payloads contain only aggregate IDs.

**Step 4: Prove GREEN, static checks, and commit**

```bash
php -l database/migrations/2026_07_26_000001_create_report_runs_table.php
php -l database/migrations/2026_07_26_000002_create_report_dispatch_intents_table.php
php -l database/migrations/2026_07_26_000003_create_report_audit_intents_table.php
vendor/bin/phpunit tests/Unit/Reporting/Dispatch tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php tests/Unit/Reporting/Execution/ExecutionContractsTest.php
vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportDispatchIntentStore.php app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditIntentStore.php app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php app/BusinessModules/Core/Reporting/Application/Dispatch app/BusinessModules/Core/Reporting/Application/Execution/ReportRunRetrySource.php app/BusinessModules/Core/Reporting/Application/Execution/ReportRunExportSource.php app/BusinessModules/Core/Reporting/Infrastructure/Audit/OutboxReportTransitionAudit.php app/BusinessModules/Core/Reporting/Infrastructure/Console/PublishReportDispatchIntentsCommand.php app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportDispatchIntentsCommand.php app/BusinessModules/Core/Reporting/Infrastructure/Dispatch app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuditIntentStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportDispatchIntentRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportAuditIntentRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php tests/Unit/Reporting/Dispatch tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php tests/Unit/Reporting/Execution/ExecutionContractsTest.php tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php --no-progress
# Isolated PostgreSQL CI only:
vendor/bin/phpunit tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php
git add <the 39 exact paths listed above>
# Require exact sorted 39-path staged-set equality and independent schema/signature review.
git commit -m "feat[reports]: добавить надежную доставку заданий отчетов"
```

After commit, require exact 39-path `diff-tree` equality and a clean Task 4b scoped status.

### Task 4a2: Sanction typed snapshot-identity violations in Plan 1a

**Dependency:** Historical Task 4a exact53 commit is immutable. Task 4b exact39 commit `973aabb17516c0ff9bc7d5a87b3ab6eb8732f333` is the exact parent. Task 4a2 is a new forward-only sanctioned Plan 1a descendant; it does not amend, squash, rebase, or relabel either historical commit.

**Files:**

- Modify: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Enums/ReportSnapshotIdentityViolationReason.php`
- Create: `app/BusinessModules/Core/Reporting/Domain/Exceptions/ReportSnapshotIdentityViolation.php`
- Modify: `docs/reports/contracts/plan-1a-completion.schema.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.sha256`
- Modify: `docs/reports/contracts/plan-1a-gate-evidence.schema.json`
- Modify: `scripts/reporting/build-plan-1a-evidence.php`
- Modify: `scripts/reporting/run-plan-1a-gates.php`
- Modify: `tests/Architecture/Reporting/PlanOneAHandoffContractTest.php`
- Modify: `tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json`
- Modify: `tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php`
- Modify: `tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php`
- Modify: `tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php`

**Task file count:** 16 exact tracked files: two `Create` and fourteen `Modify`.

**Locked domain contract:**

```php
enum ReportSnapshotIdentityViolationReason: string
{
    case INVALID_KIND = 'invalid_kind';
    case INVALID_ID = 'invalid_id';
    case OFFICIAL_SEAL_REQUIRED = 'official_seal_required';
    case OPERATIONAL_SEAL_FORBIDDEN = 'operational_seal_forbidden';
    case SEAL_TIME_INVALID = 'seal_time_invalid';
}

final class ReportSnapshotIdentityViolation extends InvalidArgumentException
{
    public function __construct(
        public readonly ReportSnapshotIdentityViolationReason $reason,
    );
}
```

The exception message is always `snapshot_identity_invalid`; no consumer branches on text. `ReportSnapshotRef` keeps its exact constructor and valid identity. It throws the corresponding typed reason for invalid kind, invalid ID, official-without-seal, operational-with-seal, and seal-before-generated-time. The existing stale-time violation remains a generic safe `InvalidArgumentException('snapshot_identity_invalid')` and cannot be interpreted as a seal reason.

**Evidence ownership:**

Builder/runner introduce a separate closed `task_4a2` block, `TASK_FOUR_A2_SUBJECT = 'fix[reports]: типизировать нарушения идентичности снимков'`, exact ordered 16-path manifest, exact parent `973aabb17516c0ff9bc7d5a87b3ab6eb8732f333`, and sanctioned lineage `Task 4a exact53 -> Task 4b exact39 -> Task 4a2 exact16`. The original Task 4a block/digests remain historical facts. Fresh test/assertion counts are captured from execution, never copied from prior fixtures.

Precommit generation binds the parent commit and exact staged 16-path manifest without predicting a child SHA. After commit, ignored completion evidence is rebuilt with the actual Task 4a2 SHA. Normal verification and `--verify-existing` reread the same bytes; hash/mtime fencing proves verify-existing performs no write. Closed schemas reject missing/extra/reordered paths, wrong subject/parent/commit, omitted reason cases, mutable message, and any attempt to rewrite historical Task 4a/4b ownership.

**RED/GREEN and checks:**

Tests reflection-lock five enum cases, final exception, readonly reason, constant message, unchanged `ReportSnapshotRef` constructor, each condition-to-reason mapping, stale-time non-mapping, and absence of message parsing. Run the Plan 1a contract/handoff/tooling suites and the full DB-free Plan 1a regression. Run PHPStan over the three domain files, both scripts, architecture/contract/tooling tests; syntax-check every changed PHP file. No DB, migration, browser, or authorization smoke command is allowed.

```bash
git add <the 16 exact paths listed above>
# Require exact sorted 16-path staged-set equality, parent-bound lock verification, and independent Plan 1a ownership review.
git commit -m "fix[reports]: типизировать нарушения идентичности снимков"
# Rebuild ignored evidence with actual Task 4a2 SHA; run normal + --verify-existing; require no-write fence, exact16 diff-tree, and clean scoped status.
```

### Task 4c: Implement run actions, retry identity, closed source hashing, and seal validation

**Dependency:** Historical Tasks 4a and 4b plus the new Task 4a2 are green. Task 4c consumes the Task 4a2 typed violation contract without owning Plan 1a domain/evidence files. `ReportRunCoordinator` has no dispatcher dependency. The single deliberate Task 4b overlap is `EloquentReportRunStore.php`, solely for expired-inclusive status/auth-context reads; its outbox, lease, identity, and ten-method contracts remain unchanged.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Execution/CanonicalReportSourceHashBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportSnapshotSealVerifier.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealVerificationInput.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Security/TrustedReportSnapshotSealVerifier.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportRunCoordinator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportRunHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRunHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/RetryReportRunHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CancelReportRunHandler.php`
- Create: `tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php`
- Create: `tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php`
- Create: `tests/Unit/Reporting/Actions/ReportRunHandlersTest.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`

**Task file count:** 15 exact files: 14 `Create` and one `Modify`.

**Interfaces consumed or produced:**

- Each handler implements its exact Plan 1a action interface.
- Consume Plan 1a `CreateReportRunData`, `IdempotencyKey`, `ReportQuery`, `ReportRun`, `ReportAccessService`, and `ReportOperation`.
- Consume the Task 4b `ReportRunStore` exact ten-method surface, `ReportRunRetrySource`, and `ReportRunExportSource`; this task adds no repository/provider abstraction.
- `ReportRunCoordinator::create(ReportExecutionContext, CreateReportRunData, IdempotencyKey): ReportRun`.
- `CanonicalReportSourceHashBuilder::build(ReportQuery $query, ReportSnapshotRef $snapshot, ReportResult $result): Sha256Hash`.
- `ReportSnapshotSealValidator::assertSealable(ReportQuery $query, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $calculatedSourceHash): void`.
- `ReportSnapshotSealVerifier::assertTrusted(ReportSnapshotSealVerificationInput $input): void`.
- `ReportRunCoordinator::__construct(ReportDefinitionRegistry $definitions, ReportSavedViewReferenceResolver $savedViews, ReportAccessService $access, ReportRunStore $runs, ReportExecutionClock $clock)`.
- `ReportRunCoordinator::get(ReportExecutionContext $context, string $runId): ReportRun`.
- `ReportRunCoordinator::retry(ReportExecutionContext $context, string $runId, IdempotencyKey $key): ReportRun`.
- `ReportRunCoordinator::cancel(ReportExecutionContext $context, string $runId): ReportRun`.
- Consume the exact Task 4a `ReportSnapshotIdentityViolationReason` and `ReportSnapshotIdentityViolation`; Task 4c may not redefine, modify, or message-parse them.

```php
final readonly class ReportSnapshotSealVerificationInput
{
    public function __construct(
        public ReportSnapshotSeal $seal,
        public string $snapshotId,
        public string $snapshotKind,
        public ReportSnapshotClassification $snapshotClassification,
        public DateTimeImmutable $generatedAt,
        public Sha256Hash $calculatedSourceHash,
    );

    public function signedBytes(): string;
}
```

The constructor validates the same snapshot ID/kind grammar as `ReportSnapshotRef`, requires `OFFICIAL`, requires `seal.sealedAt >= generatedAt`, and requires `seal.sealedPayloadHash` equal `calculatedSourceHash`. `signedBytes()` is the sole owner of the exact framing below; the verifier neither reaches for ambient snapshot data nor rebuilds a partial message.

The retry action receives the explicit Plan 1a `IdempotencyKey` supplied by HTTP. Repeating the same retry key and canonical source body reuses one child; a changed source body conflicts. Run retry preserves exact `ReportSavedViewRef` identity and accepts only `FAILED`, `CANCELLED`, or `EXPIRED`. `READY` is not retryable; refresh is a distinct future action.

Task 4c deliberately modifies only `EloquentReportRunStore`. Its private `findIncludingExpired(ReportExecutionContext $context, string $runId): ReportRunRecord` preserves organization scoping and `REPORT_NOT_FOUND` concealment. Only `get()` and `queryForRun()` use it so the coordinator can return an `EXPIRED` status DTO and authorize against the original query. `retrySource()` retains its own explicit eligible-terminal semantics. `exportSource()`, result/artifact reads, Task 6 rows/drill-down, export creation, and downloads remain expired-fail-closed with exact 410 errors. No public store method is added and the ten-method interface is unchanged; Task 13 architecture tests lock this single Task 4b→4c overlap and reject any fallback from expired data reads.

The source hash is lowercase SHA-256 of a recursively closed canonical projection with no extra keys and UTC `Y-m-d\TH:i:s.u\Z` instants:

```text
query:
  definition_hash, query_hash, contract_version, formula_version,
  source_schema_version, renderer_version, canonical_query_json
snapshot:
  kind, id, scope{organization_id,holding_organization_ids,project_ids,resources,timezone},
  definition_hash, formula_version, generated_at, stale_at, watermarks
result:
  row_count
  provenance:
    source_of_truth, external_confirmation_role
    source_refs[] projected with exactly
      source,snapshot_kind,snapshot_id,schema_version,watermark,row_count,hash
    then sorted by all seven projected fields
```

Snapshot/provenance `sourceHash` values and seal payload hash are comparison outputs and excluded to avoid circular hashing. Duplicate identity is the exact five-field tuple `(source,snapshot_kind,snapshot_id,schema_version,watermark)`; `row_count` and `hash` are characteristics of that identity, not part of duplicate detection. Duplicate tuples fail even when their row count/hash differ. The input DTOs and arrays are never mutated.

Floats are forbidden anywhere before `CanonicalJson`. Decimal strings must match exactly `^-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$`, with an additional exact rejection of `-0`. Therefore plus signs, exponent form, leading zeros, `1.0`, `1.20`, `0.0`, `0.00`, `-0`, and `-0.00` fail; canonical integers and decimals such as `0`, `-1`, `1.25`, and `-0.5` pass.

The validator locks these exact equalities independently and mutation-tests each side:

- query definition hash equals snapshot definition hash;
- query scope canonical identity equals snapshot scope canonical identity;
- result metadata snapshot equals the complete supplied snapshot;
- metadata generated/stale instants equal snapshot generated/stale instants at UTC microsecond precision;
- result metadata row count is the sole result row count used by the projection;
- snapshot classification equals the published definition snapshot classification;
- snapshot formula version equals the published definition formula version;
- snapshot source hash equals the calculated source hash;
- provenance source hash equals the calculated source hash;
- an official seal payload hash equals the calculated source hash;
- query contract/formula/source-schema/renderer versions equal the published definition and persisted result identities.

For `ReportSnapshotClassification::OFFICIAL`, `ReportSnapshotSealValidator` constructs `ReportSnapshotSealVerificationInput` from the exact `ReportSnapshotRef`, its existing seal, and calculated source hash. `ReportSnapshotRef` remains the sole structural invariant owner but now throws `ReportSnapshotIdentityViolation` with the exact reason: invalid kind, invalid ID, official seal required, operational seal forbidden, or seal time invalid. The exception message is always the same safe `snapshot_identity_invalid`; callers branch only on the typed enum, never message text. The existing stale-time structural check remains a separate safe generic invalid-identity failure and is never interpreted as a seal failure.

When Task 5 receives a provider-side `ReportSnapshotIdentityViolation`, it maps only `OFFICIAL_SEAL_REQUIRED` to `REPORT_OFFICIAL_SNAPSHOT_UNSEALED`. `INVALID_KIND`, `INVALID_ID`, `OPERATIONAL_SEAL_FORBIDDEN`, and `SEAL_TIME_INVALID` map to `REPORT_INTERNAL_ERROR`; stale-time `InvalidArgumentException` and any unknown structural throwable also map to `REPORT_INTERNAL_ERROR`. The matrix is exhaustive and has no default-to-unsealed branch. Technical reason/message is logged only as safe structured classification and never returned.

`TrustedReportSnapshotSealVerifier` has exact constructor `__construct(array $trustedSealKeys)`. The root is a non-empty associative map keyed by canonical seal key ID. Every value is exactly `array{public_key:string,revoked:bool}` with no missing/extra member; `public_key` is canonical unpadded base64url, exactly 43 characters, strictly decodes to exactly 32 bytes, and round-trips without padding. Private-key fields/material and numeric/list roots are rejected during construction. The verifier selects exact `input.seal.keyId`, requires `revoked === false`, requires algorithm `ed25519-sha256`, strictly decodes the already canonical 86-character signature to 64 bytes, and calls `sodium_crypto_sign_verify_detached($signatureBytes, $input->signedBytes(), $publicKeyBytes)`.

The framed canonical object contains exactly `snapshot_id`, `snapshot_kind`, `snapshot_classification`, `generated_at`, `seal_key_id`, `seal_algorithm`, `sealed_payload_hash`, and `sealed_at`; both instants use UTC microseconds. The exact bytes are domain prefix `most-report-snapshot-seal-v1`, one NUL byte, raw 32-byte calculated hash, one NUL byte, then `CanonicalJson` bytes of that closed object. Unknown key, revoked key, malformed key-map entry, malformed public key, unavailable/failed sodium verification, malformed existing seal/signature, invalid signature, algorithm drift, hash drift, or classification drift maps only to `ReportContractException(REPORT_OFFICIAL_SNAPSHOT_UNSEALED)` without exposing the cause. `OPERATIONAL` snapshots must have no seal and never call the verifier.

**Step 1: Write failing tests**
Mutation-test every closed-projection, exact validator equality, and signed-input leaf. Reflection-lock the five reason cases, typed exception constructor/readonly reason/constant message, all coordinator/verification constructors and methods, the closed trusted-key shape, and unchanged ten-method store. Prove changes to snapshot ID, kind, classification, generation time, calculated hash, key, algorithm, signature, or seal time fail. Cover unknown/revoked/malformed/extra/private key entries, malformed 32-byte public keys, detached 64-byte signature verification, safe error mapping, operational verifier no-call, and Task 5 provider mapping. Positive mapping covers only `OFFICIAL_SEAL_REQUIRED`; negative tests prove invalid kind, invalid ID, operational+seal, invalid seal time, stale time, and unknown structural failure produce `REPORT_INTERNAL_ERROR`, never `REPORT_OFFICIAL_SNAPSHOT_UNSEALED`.

Also prove seven-field source-ref sorting without input mutation, duplicate five-field identity rejection even when row count/hash differ, float rejection, every decimal boundary above, explicit create/retry-key forwarding, saved-view current tuple, eligible retry statuses, `get/queryForRun` expired status visibility, `exportSource` expired rejection, summary sensitive→audit authorization ordering, idempotency conflict, cancel races, absence of dispatcher dependency, and absence of direct record access outside the single store modification.
```php
public function test_create_handler_forwards_the_plan_one_a_idempotency_value_object(): void
{
    $key = new IdempotencyKey('run-key-1');
    $run = $this->handler->handle($this->context, $this->data, $key);
    self::assertSame('run-key-1', $this->store->lastIdempotencyKey()->value);
    self::assertSame($key->hash, $this->store->lastIdempotencyKey()->hash);
    self::assertSame($this->existingRun, $run);
}
```
**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php tests/Unit/Reporting/Actions/ReportRunHandlersTest.php
```
Expected: failures because handlers, coordinator, builder, validator, verifier, and expired-status store behavior do not exist.
**Step 3: Implement the minimum**
The coordinator resolves only a published definition, builds the six-argument Plan 1a query, resolves a supplied saved-view ID to its typed current reference, authorizes `RUN`, and calls `createOrReuse()`; the store-created dispatch intent is the only delivery source. Retry first consumes `retrySource()`, authorizes `RUN` against `source.query->definition` rather than a current same-code definition, revalidates the saved-view tuple, forwards the explicit retry key, and creates/reuses a child without resetting the source row. Read obtains run plus original query, authorizes `VIEW`, then summary sensitive and audit operations in that order, and permits status for `EXPIRED` without reading sealed result data. Cancel authorizes `RUN` against the original query and delegates the locked compare-and-set with `clock.now()`; it performs no local optimistic status check.
**Step 4: Prove GREEN**
Run the same four tests, then the DB-free Task 4a+4b reporting regression aggregate. Expect canonicalization, exact equalities, trust failures, expired status/data separation, access, idempotency, and transition cases to pass. Do not run PostgreSQL, browser, authorization smoke, or migrations.
**Step 5: Static analysis**
Run:
```bash
vendor/bin/phpstan analyse --memory-limit=1G app/BusinessModules/Core/Reporting/Application/Execution app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportSnapshotSealVerifier.php app/BusinessModules/Core/Reporting/Infrastructure/Security/TrustedReportSnapshotSealVerifier.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php tests/Unit/Reporting/Execution tests/Unit/Reporting/Actions/ReportRunHandlersTest.php
```
Expect no errors.
**Step 6: Commit**
```bash
git add <the 15 exact paths listed above>
# Require exact sorted 15-path staged-set equality and independent source/seal/access review.
git commit -m "feat[reports]: реализовать операции и идентичность запуска"
# Require exact 15-path diff-tree equality and clean Task 4c scoped status.
```

### Task 4d: Lock run lease renewal and the asynchronous queue runtime

**Dependency:** Task 4c is committed at exact parent `8fb79f5c24697f5bc39e32ccf13287d528e94886`. This is a forward-only prerequisite; Tasks 4a, 4b, 4a2, and 4c remain immutable.

**Files:**

- Create: `database/migrations/2026_07_26_000004_add_report_run_execution_lineage.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`
- Modify: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Modify: `config/queue.php`
- Modify: `config/horizon.php`
- Create: `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`

**Task file count:** 6 exact files.

**Locked behavior:**

- The additive migration adds nullable grammar/length-checked `correlation_lineage_id`, plus a partial unique index on non-null `execution_lease_token`; its down path removes the exact named objects. It is migration `000004`; the export-table migration in Task 7 moves forward to `000005`.
- `EloquentReportRunStore::claimMaterialization()` may renew `MATERIALIZING` only for the same non-empty canonical UUID lease token. It locks the organization/run row, requires the stored token to equal the supplied token, requires the stored lease to satisfy exact `expiry > occurredAt`, and atomically advances only expiry/heartbeat without resetting `started_at`, progress, or emitting a second audit event. A different token, equality-at-expiry, an already expired lease, or a terminal state is fenced. Every progress/ready/fail write also requires the matching token and a live `expiry > occurredAt`.
- Expired leases are never renewed by the worker. They remain reserved for the future Task 5 watchdog path. Task 4d proves the locked renewal half and common row-lock semantics; Task 5, after creating the recovery store, owns the actual two-boundary renewal-versus-watchdog PostgreSQL race and proves that both cannot win.
- The queue envelope carries only the aggregate ID. `correlation_lineage_id` is durable diagnostic lineage, never authority; each delivery still creates a fresh correlation ID during current-fact context rehydration.
- Reporting jobs use the dedicated Redis connection `redis_reports` and queue `reports`. The materialization and export jobs lock `timeout=900`; execution leases lock `960`; Horizon supervisor timeout is exactly `960` and therefore greater than the job timeout; Redis `retry_after` is exactly `1200` and therefore greater than the lease/Horizon timeout. The architecture test resolves the effective queue and Horizon configuration and fails if these inequalities, names, or exact values drift.

**Step 1: Write failing tests**

Add PostgreSQL cases for same-token live renewal, expired same-token rejection, different-token rejection, correlation-lineage hydration, and renewal row-lock serialization. Do not reference the not-yet-created recovery store here. Add a runtime architecture test for `redis_reports`, job timeout `900`, lease `960`, Horizon timeout `960`, Redis `retry_after=1200`, and `900 < 960 < 1200`.

**Step 2: Prove RED**

Run the targeted store and architecture tests. The PostgreSQL race runs only in isolated CI.

**Step 3: Implement the additive schema, fenced renewal, and queue runtime**

Keep queue timing configuration centralized in `config/queue.php` and `config/horizon.php`; production code must not invent per-call alternatives. Do not add a fallback queue or connection.

**Step 4: Prove GREEN and analyze**

Run `vendor/bin/phpunit tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php` locally. Run `vendor/bin/phpunit tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php` only in isolated PostgreSQL CI. Run PHP syntax for the migration/configuration and PHPStan for the amended store and both tests.

**Step 5: Commit**

```bash
git add <the 6 exact paths listed above>
# Require the sorted staged path set to equal the six-path manifest and HEAD to equal the exact parent.
git commit -m "fix[reports]: закрепить аренду и контекст фонового запуска"
```

After commit, record the actual SHA and prove exact lineage `8fb79f5c24697f5bc39e32ccf13287d528e94886 -> Task 4d (6)`. Require the committed path set to equal the manifest and all six paths to be clean.

### Task 4e: Cut over to typed resource scope and atomic current authorization

**Dependency:** Task 4d is committed at exact parent `1934f947a44aa5221b5aa4cbd8c03963f5f1c005`. This is a forward-only prerequisite to Task 5. Tasks 4a, 4b, 4a2, 4c, and 4d and their commits remain immutable; Task 4e creates one new descendant and does not amend, squash, reset, or rewrite their history.

**Reason for the prerequisite:** the pre-Task-4e `LaravelCurrentReportScopeAuthorizer` prototype was invalid because it returned the persisted requested holding/project/resource IDs after only an actor check. Production HTTP establishes only `current_organization_id`; it has no producer for `holding_organization_ids`, `allowed_project_ids`, `allowed_resource_ids`, or `organization_timezone`. `AuthorizationService` resolves organization and singular `project_id` contexts but does not interpret plural or untyped `resource_ids`. Numeric resource ID alone cannot identify ownership because the same integer can name rows in unrelated domain tables. Task 5 may not materialize data until these ambiguities are removed.

**Files (exact 78-path commit manifest):**

- Create: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportScopedResource.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/CurrentReportAuthorization.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/CurrentReportAuthorizationTarget.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/ReportCatalogAuthorization.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/ReportAuthorizationSubject.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/ReportHttpAuthorizationOrchestrator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/CurrentReportAuthorizationFacts.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/CurrentReportPermissionDecision.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Access/ReportScopedResourceAccessDecision.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/CurrentReportScopeAuthorizer.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportScopedResourceAuthorizer.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Access/CurrentReportAbacEvaluator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportAuthorizationSubjectReader.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportHttpAuthorizationTargetResolver.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelReportScopedResourceAuthorizerRegistry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelCurrentReportAbacEvaluator.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelReportHttpAuthorizationTargetResolver.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelCurrentReportScopeAuthorizer.php`
- Create: `database/migrations/2026_07_29_000004_cut_over_report_scope_resources.php`
- Create: `tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php`
- Create: `tests/Unit/Reporting/Execution/CurrentReportAuthorizationTargetTest.php`
- Create: `tests/Unit/Reporting/Access/ReportHttpAuthorizationOrchestratorTest.php`
- Create: `tests/Unit/Reporting/Access/ReportAuthorizationSubjectTest.php`
- Create: `tests/Unit/Reporting/Access/LaravelReportHttpAuthorizationTargetResolverTest.php`
- Create: `tests/Unit/Reporting/Access/ReportScopedResourceAuthorizerContractTest.php`
- Create: `tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php`
- Create: `tests/Unit/Reporting/Access/CurrentReportPermissionDecisionTest.php`
- Create: `tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php`
- Create: `tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorTest.php`
- Create: `tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorBehaviorTest.php`
- Create: `tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorRaceTest.php`
- Create: `tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizerTest.php`
- Create: `tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizationRaceTest.php`
- Create: `tests/Feature/Reporting/Persistence/ReportTypedResourceScopeCutoverMigrationTest.php`
- Create: `tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportCatalogController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRowsController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportDrillDownController.php`
- Modify: `app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Contracts/GetReportCatalogAction.php`
- Modify: `app/BusinessModules/Core/Reporting/Domain/DTO/ReportScope.php`
- Modify: `app/BusinessModules/Core/Reporting/Domain/DTO/AuthorizationDecisionContext.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Access/OrganizationReportScopeResolver.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Access/ReportExecutionContextFactory.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php`
- Modify: `tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php`
- Modify: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Modify: `tests/Support/Reporting/ReportExecutionContextBuilder.php`
- Modify: `tests/Support/Reporting/ReportRunBuilder.php`
- Modify: `tests/Support/Reporting/FakeReportingActions.php`
- Modify: `tests/Unit/Reporting/Access/OrganizationReportScopeResolverTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php`
- Modify: `tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php`
- Modify: `tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php`
- Modify: `tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php`
- Modify: `tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php`
- Modify: `tests/Architecture/Reporting/PlanOneAHandoffContractTest.php`
- Modify: `tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php`
- Modify: `tests/Architecture/Reporting/ThinReportControllerTest.php`
- Modify: `tests/Architecture/Reporting/ReportPortSignatureTest.php`
- Modify: `tests/Unit/Reporting/Http/ReportControllerContractTest.php`
- Modify: `tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php`
- Modify: `docs/reports/contracts/plan-1a-completion.schema.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.sha256`
- Modify: `docs/reports/contracts/plan-1a-gate-evidence.schema.json`
- Modify: `scripts/reporting/build-plan-1a-evidence.php`
- Modify: `scripts/reporting/run-plan-1a-gates.php`
- Modify: `tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json`
- Modify: `tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php`
- Modify: `tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php`

**Task file count:** 78 exact tracked files.

The two final fallout paths are mandatory Task 4e ownership, not incidental changes: `LaravelCurrentReportAbacEvaluatorBehaviorTest` is the executable behavior proof for the Task 4e uncached current-ABAC invariants, and `FakeReportingActions` must adopt the amended `GetReportCatalogAction` per-definition authorization signature used by the Task 4e orchestrator/catalog tests. Neither path belongs to Task 5, and neither may be omitted, deferred, or staged in a separate compatibility commit.

**Exact target contracts:**

```php
final readonly class ReportScopedResource
{
    public function __construct(
        public string $kind,
        public int $id,
        public ?int $projectId,
    );

    /** @return array{kind:string,id:int,project_id:?int} */
    public function canonicalIdentity(): array;
}
```

`kind` matches `^[a-z][a-z0-9_]{0,63}$`; `id >= 1`; `projectId` is null or `>= 1`. The tuple `(kind,id,project_id)` is the complete identity. `ReportScope` receives `array $resources` in place of `array $resourceIds`, requires every member to be a `ReportScopedResource`, rejects duplicate tuples, sorts them by exact binary `kind`, numeric `id`, then null-first numeric `projectId`, and exposes only `public array $resources`. Its canonical identity key is exactly `resources`, containing each resource canonical identity in that order. The symbols/property/keys `resourceIds`, `resource_ids`, `scope_resource_ids`, and `allowed_resource_ids` cease to exist in reporting production code and contracts.

```php
final readonly class CurrentReportAuthorization
{
    public function __construct(
        public ReportActor $actor,
        public AuthorizationDecisionContext $decision,
        public ReportVisibility $visibility,
        public CurrentReportAuthorizationTarget $target,
    );
}

final readonly class CurrentReportAuthorizationTarget
{
    public function __construct(
        public ReportDefinition $definition,
        public ReportOperation $operation,
        public ?ReportSnapshotRef $snapshot,
    );

    public function canonicalFingerprint(): string;
}

interface CurrentReportScopeAuthorizer
{
    public function authorizeForOrganization(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization;

    public function authorizeExact(
        int $actorId,
        ReportScope $requestedScope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization;
}

interface ReportScopedResourceAuthorizer
{
    public function kind(): string;

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision;
}

interface CurrentReportAbacEvaluator
{
    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision;
}

interface ReportHttpAuthorizationTargetResolver
{
    public function createRun(string $reportCode): CurrentReportAuthorizationTarget;
    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget;
    public function createExport(string $runId): CurrentReportAuthorizationTarget;
    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget;

    /** @return list<CurrentReportAuthorizationTarget> */
    public function catalog(): array;
}

interface ReportAuthorizationSubjectReader
{
    public function run(string $runId): ReportAuthorizationSubject;
    public function export(string $exportId): ReportAuthorizationSubject;
}
```

`ReportAuthorizationSubject` is a closed immutable value with exact fields `{aggregateKind:RUN|EXPORT, aggregateId, definition, scope:ReportScope, snapshot:?ReportSnapshotRef, parentRunId:?string, artifactIdentityHash:?Sha256Hash}` and cross-field invariants for run versus export. `ReportAuthorizationSubjectReader` is the sole narrow server-owned lookup boundary for HTTP authorization subjects. A run subject always contains the persisted canonical requested scope, including while the run is `QUEUED` and has no snapshot; once a snapshot exists, its scope must exact-equal that persisted requested scope. An export subject contains its own persisted canonical scope, parent run identity, immutable definition, and exact sealed snapshot/artifact identity; export scope, parent-run requested scope, and snapshot scope must all exact-equal. Missing/malformed scope or any mismatch fails closed before authorization. The reader returns no actor permissions and accepts no definition, operation, snapshot, organization, or scope from request input.

`ReportHttpAuthorizationOrchestrator` has one named method per HTTP operation: `createRun`, `showRun`, `retryRun`, `cancelRun`, `rows`, `drillDown`, `createExport`, `showExport`, `retryExport`, `cancelExport`, `download`, and `catalog`. Each method accepts only authenticated request facts plus the minimal route/business identifier and delegates target construction to `ReportHttpAuthorizationTargetResolver`; callers cannot pass a `ReportOperation`, `ReportDefinition`, `ReportSnapshotRef`, target, visibility, or permission list. Its result contains the `ReportExecutionContext` and exact authorization target/result required by the action. `catalog` returns `ReportCatalogAuthorization`, an ordered map of published definition hash to that definition's independently computed full visibility; it never fabricates one catalog-wide target or copies visibility between definitions.

`GetReportCatalogAction` is amended to consume `(ReportExecutionContext $context, ReportCatalogAuthorization $authorization): ReportCatalogView`. The catalog controller passes both values returned by `orchestrator->catalog($request)`; Plan 1c filters/projects catalog entries only through this closed per-definition map and cannot recompute permissions or accept a client visibility map.

`CurrentReportAuthorizationTarget` is the closed server-owned identity of the exact authorization attempt. Its fingerprint is built from the complete canonical published-definition identity (code, version/hash, policy and required permission sets), exact `ReportOperation`, and the complete canonical snapshot reference when present. `RUN` requires `snapshot=null`; every operation addressing an existing snapshot requires that exact snapshot reference. A caller cannot supply only a report code, reuse a target for another definition revision, or replace/omit a snapshot after authorization.

`CurrentReportAuthorization` requires positive matching actor identity, a decision whose complete scope identity equals the successfully authorized requested scope, the complete seven-bit `ReportVisibility`, and the exact target/fingerprint authorized in the same invocation. Consumers exact-match actor, scope, definition, operation, and snapshot before use; the result is not reusable for another report revision, operation, snapshot, actor, or scope. It contains neither the Eloquent `User`, role assignments, credentials, tokens, request metadata, nor a persisted authorization snapshot.

`CurrentReportAuthorizationFacts` is a closed immutable queue-safe value with exact fields `{channel='queue', actorId, organizationId, projectId:?int, resource:?ReportScopedResource, occurredAt:DateTimeImmutable}`. It rejects non-positive IDs, a resource whose non-null project differs from `projectId`, any channel other than `queue`, and extra/ambient request data. It contains no IP, user agent, geolocation, request amount, callback, token, role, permission, or serialized decision.

`CurrentReportPermissionDecision` is a closed immutable value with exact fields `{actorId, permission, organizationId, projectId:?int, resource:?ReportScopedResource, granted:bool}`. `LaravelCurrentReportScopeAuthorizer` accepts `granted=true` only when every identity field equals the exact `CurrentReportAuthorizationFacts` and requested permission; any mismatch is denial.

`ReportScopedResourceAccessDecision` is a closed immutable value with exact fields `{actorId, organizationId, projectId:?int, kind, id, granted:bool}`. The registry accepts it only when `granted=true` and all five identity fields equal the requested actor, anchor organization, and exact `(projectId,kind,id)` resource tuple. A decision produced for another actor is denied even when organization/project/resource are identical; it is not replayable across actors. A handler cannot prove access by returning `void`, a boolean, another resource, a parent resource, or a broader organization/project.

`LaravelReportScopedResourceAuthorizerRegistry` receives `iterable<ReportScopedResourceAuthorizer>`. An empty registry is valid and deployable while the authorized scope has an empty resource list. Each present handler must expose one valid exact non-wildcard kind; invalid, empty-string, `*`, `all`, `generic`, or duplicate kinds fail registry construction. The registry is not called for an empty resource list. For any non-empty resource list, each item must have one exact-kind handler; missing/unknown kind, handler exception, `granted=false`, or decision identity mismatch is `REPORT_SCOPE_FORBIDDEN`. The registry never guesses a model/table from an ID, interprets an ID as a project, tries other handlers, or accepts a generic/wildcard adapter. Plans 2 and 3 must register a domain adapter before publishing any definition that can produce that kind; binding architecture tests reject a published resource-scoped report without the exact-kind adapter. Empty typed resources are a first-class complete scope, not a compatibility path.

`LaravelCurrentReportAbacEvaluator` is the only queue current-permission boundary. It executes inside the caller's existing Task 4e `REPEATABLE READ` transaction; it must not open a second transaction. It directly rereads current active `UserRoleAssignment`, current system/custom role permission definition, and every active `RoleCondition` required for the exact organization/project/resource fact tuple. It never calls `AuthorizationService::can()`, `User::can()`, `request()`, `auth()`, `Request`, `Cache`, or the five-minute array permission cache. It returns a typed `CurrentReportPermissionDecision`; it never returns a cached role/permission list.

For each assignment that currently grants the exact permission, all its active conditions must pass from explicit `CurrentReportAuthorizationFacts`. `TIME` evaluates against `occurredAt`; `PROJECT_COUNT` is recomputed from current active project assignments inside the same transaction. `LOCATION`, `BUDGET`, and `CUSTOM`, and any unknown condition type, are request-only/unsupported for queue facts and make that assignment fail closed. Missing or malformed condition data also fails that assignment. Authorization succeeds only if at least one current assignment grants the exact permission with every active condition proven. Another assignment's failure does not mask an independently valid assignment; no condition is ignored because a fact is absent.

`AuthorizationDecisionContext` replaces `resourceIds` with typed `resources`; `toAuthorizationArray()` emits exact key `resources` with the canonical typed projection. No scalar/list coercion is permitted.

`ReportExecutionContextFactory::fromHttp()` is no longer a public controller entry point and never manufactures a target. Only `ReportHttpAuthorizationOrchestrator` may call its narrow fact-extraction method after the resolver has produced a server-owned target. It ignores client fields and request attributes named `operation`, `definition`, `definition_hash`, `snapshot`, `snapshot_id`, `holding_organization_ids`, `allowed_project_ids`, `allowed_resource_ids`, `resources`, or `organization_timezone`. After obtaining only the authenticated actor ID and middleware-proven `current_organization_id`, the orchestrator calls `authorizeForOrganization(actorId, organizationId, UTC, target)` and constructs the HTTP execution context from the returned actor/decision/visibility/target.

The resolver owns this exact operation/source table: create-run resolves the requested report code through the published `ReportDefinitionRegistry` and fixes `RUN` with no snapshot; show-run fixes `VIEW`, retry/cancel-run fix `RUN`, rows fixes `VIEW`, and drill-down fixes `DRILL_DOWN`, all from the persisted run's immutable definition, persisted requested `ReportScope`, and exact sealed snapshot where required; create-export fixes `EXPORT` from the persisted ready parent run; show-export fixes `VIEW`, retry/cancel-export fix `EXPORT`, and download fixes `DOWNLOAD`, all from the persisted export plus parent-run definition/scope/snapshot/artifact identity. Route IDs select only the persisted subject. Request bodies, query parameters, headers, and request attributes can never choose or override operation, definition revision/hash, scope, snapshot, parent identity, or classification.

Create-run and per-definition catalog authorization are the only HTTP flows allowed to call `authorizeForOrganization()`, because no aggregate scope exists yet. Every persisted run/export flow must call `authorizeExact(actorId, subject.scope, target)` inside the shared transaction. A queued run still authorizes its sealed persisted requested scope even with `target.snapshot=null`; it never widens back to the actor's newly derived complete organization/project contour. Any current revocation within that exact scope denies before the action. For snapshot-bearing run operations, `snapshot.scope === subject.scope` is mandatory. For exports, `export.scope === parentRun.scope === snapshot.scope` is mandatory before `authorizeExact`; no partial intersection, current-scope substitution, or same-code/current-definition recovery is allowed.

Catalog is a distinct multi-definition flow: the resolver enumerates the exact published registry snapshot, constructs one `VIEW` target per published definition, and the orchestrator computes a separate full visibility vector for each definition in deterministic definition-code/hash order. A denied view omits that definition; other capability bits remain definition-specific. Catalog never invokes the single-target execution-context path with a fake/sentinel definition.

Controllers remain transport-only: validate/normalize the request, call the one named orchestrator method, pass its context to the existing action, and format `AdminResponse`. They do not resolve registries/stores, choose enum operations, load run/export rows, or inspect visibility. The orchestrator depends by constructor on the factory and typed resolver; the resolver depends by constructor on the published registry and `ReportAuthorizationSubjectReader`. No container lookup, facade, static registry, optional dependency, catch-and-broaden branch, current-definition-by-code fallback for persisted subjects, or client-derived fallback is permitted. `authorizeForOrganization()` derives the complete current active holding/project contour in the same transaction and sets typed resources to the exact empty list; server-owned non-empty typed selection must pass through `authorizeExact()`. Canonical `UTC` remains the only HTTP query timezone until a server organization-timezone source exists.

**Atomic current-fact algorithm:**

For HTTP, `ReportHttpAuthorizationOrchestrator` opens one PostgreSQL read-only `REPEATABLE READ` transaction before target resolution; `LaravelReportHttpAuthorizationTargetResolver`, `ReportAuthorizationSubjectReader`, and `LaravelCurrentReportScopeAuthorizer` must join that exact transaction and may not open/nest a second snapshot. For queue callers, `LaravelCurrentReportScopeAuthorizer::authorizeExact()` opens the same transaction itself. Thus persisted definition/snapshot/artifact resolution and every authorization read below are one atomic observation and return only after all checks pass:

1. Validate positive actor ID and the already-constructed closed `ReportScope`.
2. Load one non-deleted `User` with `is_active=true`.
3. Load the requested anchor `Organization` as non-deleted and `is_active=true`; require the actor's exact `organization_user` pivot to exist with `is_active=true`.
4. Derive the current organization contour from server topology. If the anchor is an active holding parent, the allowed set is the anchor plus its active, non-deleted direct children. Otherwise it is only the anchor. `authorizeForOrganization()` uses the complete derived set; `authorizeExact()` requires every requested `holdingOrganizationId` to be in it and the anchor to be present. A user need not have a separate child membership when access is inherited from the active holding parent. Cross-holding, inactive, deleted, grandchild, expired sharing-grant, detached-parent, and caller-supplied extra organization IDs fail closed.
5. Read the current `UserProjectAccessService::queryAccessibleProjects($user, $anchorId)` set. `authorizeForOrganization()` uses its complete sorted active, non-archived ID set. `authorizeExact()` checks every requested project individually against it. This preserves `organization_user.project_access_mode`, active `project_user`, owner/participant organization access, and tenant isolation. New unrelated accessible projects do not change an exact selected requested scope; every requested project is checked individually.
6. From the exact target definition, derive the complete unique permission set required to compute all seven visibility capabilities, not merely the requested operation. Evaluate every slug through `CurrentReportAbacEvaluator` against exact organization facts, then each requested project and resource through its separate exact fact value. Require a typed decision whose actor/permission/fact tuple matches the call. Build the full vector atomically as: `view = reports.view + definition.permissionPolicy.viewPermissions`; `run = view + reports.run`; `export = view + reports.export + definition.permissionPolicy.exportPermissions`; `download = export + reports.download`; `manage = view + reports.manage`; `sensitive = view + reports.sensitive + definition.permissionPolicy.sensitivePermissions`; `audit = view + reports.audit + definition.permissionPolicy.auditPermissions`. Each `+` means every permission in the union must be granted for the exact facts. A denied capability remains `false` without suppressing evaluation of the other six; all seven bits are computed from the same database snapshot. No `AuthorizationService`, request global, role cache, plural project context, stored permission list, or post-return permission check participates.
7. For each requested typed resource, require `resource.projectId` to be null or already present in requested project IDs, invoke the exact-kind registry once with a closed resource fact value, and require a matching typed granted decision that proves current organization ownership/participation, current project containment when present, domain visibility, and resource-specific ABAC. There is no attempt to enumerate a global current resource allowlist.
8. Build `ReportActor` only from permissions proven inside this same snapshot and attach the complete `ReportVisibility(view, run, export, download, manage, sensitive, audit)`. Require the bit corresponding to the exact requested operation to be true; `DRILL_DOWN` requires `canView=true` plus an exact snapshot target. Otherwise deny only after the complete vector has been computed. Build one queue `AuthorizationDecisionContext` from the now-proven exact requested contour, typed resources, the requested timezone as a query semantic rather than an authority source, a fresh UUID correlation ID, and null transport metadata.
9. Return actor, decision, full visibility, and the exact target together as `CurrentReportAuthorization`. The Task 5 rehydrator exact-matches all four and performs no second actor/scope/definition/snapshot/permission load that could mix snapshots.

Any missing row, changed membership/topology/project assignment/role/condition/resource ownership, database/read failure, malformed handler result, or mismatch maps to `REPORT_SCOPE_FORBIDDEN` with no raw technical message. No partial intersection is allowed because it would execute a different canonical query. A retry opens a new transaction and must observe then-current facts.

**Typed persistence cutover:**

- The new migration is the only transition from `report_runs.scope_resource_ids` to `report_runs.scope_resources`. It declares transactional execution and supports PostgreSQL only; any other driver fails before DDL.
- `up()` executes one explicit PostgreSQL transaction. Its first statement obtains `LOCK TABLE report_runs IN ACCESS EXCLUSIVE MODE`, preventing a legacy writer from racing the preflight. Before any `ALTER TABLE`, it proves every `scope_resource_ids` value is a JSON array equal to exact `[]`; non-empty, null, non-array, or malformed legacy data raises a deterministic exception while the schema still has only the old column. It then performs add `scope_resources jsonb`, exact empty backfill, closed JSON-array check creation/validation, non-null enforcement, and old-column drop inside that same transaction. Any error rolls back every DDL/DML statement, so an observer can see only the old schema or the final new schema, never a durable dual-column state. It does not infer a kind, wrap integers in `legacy`, preserve opaque IDs, dual-write, or retain the old column.
- `down()` uses the symmetric single PostgreSQL transaction and `ACCESS EXCLUSIVE` lock. It preflights exact empty typed arrays before any DDL, then recreates/backfills/validates the old empty-array column and drops `scope_resources` within the same transaction. A non-empty/malformed typed value aborts while the schema still has only `scope_resources`. This is an explicit target cutover, not backward-compatible runtime behavior.
- `EloquentReportRunStore`, `ReportRunHydrator`, `ReportRunRecord`, and `EloquentReportDispatchIntentStore` read/write only canonical typed `scope_resources`. The closed projection rejects missing/extra keys, non-list roots, duplicate resources, noncanonical order, strings in numeric fields, and unknown fields.
- Migrations are created and statically inspected only. They are not run locally.

**Forward-only Plan 1a evidence ownership:**

Before Task 4e may commit, the stale Plan 1a scope-boundary gate is amended forward-only. `PlanOneAScopeBoundaryTest` replaces only its historical global dispatch prohibition with this exact sorted allowlist:

```text
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportDispatcher.php
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportMaterializationDispatcher.php
app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/LaravelReportDispatchIntentPublisher.php
app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php
```

The gate hard-fails if a listed path is missing, reordered in governance evidence, renamed, duplicated, outside its exact namespace/layer, or exposes an undeclared dispatch boundary. It still proves there is no production `Jobs` directory at the reporting module root and no dispatch interface, publisher, queue adapter, `dispatch()` call, queue facade, job construction, or transport leakage anywhere else in the module. Skip, xFail, incomplete markers, wildcard/glob allowlists, basename matching, directory-wide exclusions, substring exemptions, and environment-conditional bypasses are forbidden.

The builder/runner add one separate closed `task_4e` block with exact subject `feat[reports]: типизировать ресурсы и текущую авторизацию`, exact ordered 78-path manifest, exact parent `1934f947a44aa5221b5aa4cbd8c03963f5f1c005`, and lineage `Task 4a exact53 -> Task 4b exact39 -> Task 4a2 exact16 -> Task 4c exact15 -> Task 4d exact6 -> Task 4e exact78`. Historical blocks/digests remain byte-for-byte historical facts. The new block records the typed-resource constructor/projection, typed resource and permission decisions, queue-safe uncached ABAC boundary, absence of untyped symbols/request globals/authority cache, exact current-authorization signature/result, atomic empty-only migration cutover, empty-registry deployability plus non-empty fail-closed dispatch, organization/project/ABAC/resource matrices, and repeatable-read race matrix.

Precommit generation binds the exact parent and staged 78-path manifest without predicting the child SHA. After the single Task 4e commit, ignored completion evidence is rebuilt with its actual SHA. Normal verification and `--verify-existing` reread identical bytes; hash/mtime fencing proves verify-existing performs no write. Closed schemas reject missing/extra/reordered paths, wrong subject/parent/commit, any rewritten Task 4a/4b/4a2/4c/4d fact, omitted authorization matrix, a stale global dispatch prohibition, a missing/extra/unsorted dispatch-boundary allowlist entry, any dispatch leakage outside the five sanctioned paths, any root `Jobs` directory, any skip/xFail/broad exclusion, untyped compatibility key, boolean/void resource proof, request-global/cached queue authorization, wildcard resource handler, rejection of an empty unused registry, non-atomic schema transition, or missing migration cutover result.

The schemas, valid fixtures, builder, runner, staged-set gate, and post-commit diff-tree gate all encode Task 4e as the exact ordered 78-path list including the ABAC behavior proof and catalog fake compatibility path. The final ownership audit requires `Task4e exact78 ∪ Task5 exact30` to equal the complete sorted 108-path changed set, with intersection size zero and unowned/extra counts both zero. Any `107/108`, `109/108`, duplicate ownership, missing fallout path, or order drift is a hard failure.

**Step 1: Write failing tests**

Cover exact reflection contracts and removal of every untyped symbol. Mutation-test invalid kind/ID/project, duplicate and order normalization, closed typed projection, context/scope mismatch, registry duplicate/unknown/wildcard behavior, and exact handler dispatch.

The organization matrix covers inactive/deleted actor, inactive pivot, inactive/deleted anchor, holding parent plus active direct child, detached child, foreign holding, grandchild, inactive/deleted child, and child access inherited only through the current holding parent. The project matrix covers all-project mode, assigned-project mode, inactive assignment, archived project, owner/participant organization, foreign project, selected subset, and newly accessible unrelated project.

The permission/ABAC matrix covers current organization role, singular project role, revoked/expired/inactive assignment, system and custom role permission removal, inactive condition, valid/expired time condition at exact injected `occurredAt`, freshly recomputed project-count condition, request-only location/budget/custom condition, unknown/malformed condition, missing required fact, resource-specific facts, two assignments where only one proves access, and proof that plural IDs never select an authorization context. Reflection and mutation tests lock the exact target input and the complete atomic `ReportVisibility` vector for `view/run/export/download/manage/sensitive/audit`: every base and definition-specific permission independently flips only its dependent bits, `download` implies the export chain, all seven are evaluated before operation admission, and definition revision, operation, or snapshot mismatch/replay is denied without any later permission check. Architecture tests reject imports/calls to `AuthorizationService::can`, `User::can`, `request`, `auth`, `Request`, and `Cache`; reflection locks the queue-safe facts and typed permission decision. A same-process test changes a role/condition between two evaluations inside the nominal 300-second window and proves the second evaluation sees the change rather than cached authority.

The resource matrix covers an empty registry plus empty resources as valid/deployable, an empty registry plus any non-empty resource as denied, two kinds sharing the same numeric ID, exact organization ownership, exact project containment, foreign organization, foreign project, deleted/inactive resource, unknown kind, missing adapter, adapter exception, explicit denied decision, duplicate/invalid/wildcard adapter, and decisions mismatching actor, organization, project, kind, or ID. It explicitly replays an otherwise identical granted decision under a second actor and requires denial.

The HTTP matrix covers every named controller callsite and exact operation/source pair: create, show, retry, cancel, rows, drill-down, create/show/retry/cancel export, download, and catalog. It proves only authenticated actor, middleware-proven organization, route ID, and create-run report code reach the orchestrator; operation/definition/hash/snapshot/classification/parent/visibility injections through body, query, header, route extras, or request attributes are ignored or rejected. Cross-operation tests replay a valid show target into retry/download, a run target into export, an export target into rows, and a current same-code definition over a persisted revision; every case denies before action/store/provider/renderer/S3 invocation.

Scope data-flow tests prove create-run/catalog alone use `authorizeForOrganization`, while every persisted run/export callsite invokes `authorizeExact` once with the subject's canonical scope. They cover a queued run with no snapshot, selected project/resource subsets, later expansion of actor access without scope widening, project/resource/holding revocation, malformed persisted scope, snapshot-versus-run scope mismatch, export-versus-parent scope mismatch, and export/parent-versus-snapshot mismatch. Every mismatch or revocation fails before action/store/provider/renderer/S3; no test fake may return a scope different from the persisted subject.

**Exhaustive persisted-subject state/operation/evidence matrix:**

Every matrix cell follows one ordering: the reader loads and validates the complete persisted subject in the shared snapshot; the orchestrator performs `authorizeExact(actorId, subject.scope, target)`; only then may the action inspect/transition/read the allowed state. Missing/malformed/mismatched evidence fails before authorization result publication. Authorization revocation and invalid operation/state both fail before provider, row query, renderer, queue dispatch, audit transition, artifact inventory, temporary-link, or S3 call.

| RUN state | Persisted authority/evidence source | Allowed HTTP callsites and fixed operation | Required evidence | All other operations/states |
|---|---|---|---|---|
| `QUEUED` | Run row immutable definition + canonical requested scope | `showRun:VIEW`, `cancelRun:RUN` | Snapshot/result/artifact absent; requested scope remains mandatory and is authorized exactly | retry, rows, drill-down, create-export deny |
| `MATERIALIZING` | Same run definition + requested scope | `showRun:VIEW`, `cancelRun:RUN` | No readable snapshot/result; active execution evidence may exist but is not authority | retry, rows, drill-down, create-export deny |
| `READY` | Run definition + requested scope + sealed snapshot/result | `showRun:VIEW`, `rows:VIEW`, `drillDown:DRILL_DOWN`, `createExport:EXPORT` | Snapshot present, trusted sealed identity valid, `snapshot.scope == run.scope`, definition/query/source/result identities exact, unexpired | retry and cancel deny; any missing/mismatched seal/scope/hash denies |
| `FAILED` | Run definition + requested scope + safe terminal evidence | `showRun:VIEW`, `retryRun:RUN` | No readable partial snapshot/result/artifact; persisted error code valid; retry uses original definition/scope/query | cancel, rows, drill-down, create-export deny |
| `CANCELLED` | Run definition + requested scope + terminal evidence | `showRun:VIEW`, `retryRun:RUN` | No readable partial snapshot/result/artifact; retry uses original definition/scope/query | cancel, rows, drill-down, create-export deny |
| `EXPIRED` | Retained run definition + requested scope + retained sealed identity | `showRun:VIEW`, `retryRun:RUN` only under the already locked retry policy | Retained snapshot identity must still exact-match run scope/definition; expired rows/result are never read | cancel, rows, drill-down, create-export deny |

| EXPORT state | Persisted authority/evidence source | Allowed HTTP callsites and fixed operation | Required evidence | All other operations/states |
|---|---|---|---|---|
| `QUEUED` | Export row + parent run immutable definition/scope/sealed snapshot | `showExport:VIEW`, `cancelExport:EXPORT` | `export.scope == parent.scope == snapshot.scope`; artifact absent; parent identity exact | retry and download deny |
| `RUNNING` | Same export/parent/snapshot chain | `showExport:VIEW`, `cancelExport:EXPORT` | Exact chain plus active execution evidence; no readable artifact | retry and download deny |
| `UPLOADING` | Same export/parent/snapshot chain | `showExport:VIEW`, `cancelExport:EXPORT` | Exact chain plus active upload evidence; incomplete object is not downloadable authority | retry and download deny |
| `READY` | Export + parent definition/scope/snapshot + sealed artifact identity | `showExport:VIEW`, `download:DOWNLOAD` | Exact scope/definition/hash/version chain, complete artifact path/version/ETag/checksum/size/MIME, unexpired | retry and cancel deny; omission/mismatch prevents temporary-link/S3 |
| `FAILED` | Export + parent definition/scope/snapshot + safe terminal evidence | `showExport:VIEW`, `retryExport:EXPORT` | Exact parent/scope/snapshot chain; no partial artifact may be treated as ready; safe error valid | cancel and download deny |
| `CANCELLED` | Export + parent definition/scope/snapshot + terminal evidence | `showExport:VIEW`, `retryExport:EXPORT` | Exact parent/scope/snapshot chain; incomplete artifact is non-authoritative | cancel and download deny |
| `EXPIRED` | Retained export + parent definition/scope/snapshot/artifact identity | `showExport:VIEW`, `retryExport:EXPORT` only while the locked parent-ready/unexpired rule passes | Retained identities exact; expired artifact is never downloadable; retry never extends parent TTL | cancel and download deny |

Create-run remains the only single-definition callsite without a persisted subject (`published definition + requested report code -> RUN, snapshot=null, authorizeForOrganization`). Catalog remains the only multi-definition callsite (`published registry snapshot -> independent VIEW target/vector per definition`). Neither participates in the persisted-state tables.

Exact named tests are required in `LaravelReportHttpAuthorizationTargetResolverTest`, `ReportHttpAuthorizationOrchestratorTest`, `ReportingAuthorizationMatrixTest`, and `EloquentReportAuthorizationSubjectReaderTest`: one positive case for every allowed table cell; one denial case for every explicitly disallowed operation/state pair; and per state, mutations removing or changing scope, definition hash, snapshot, parent ID, seal, result hash, or artifact evidence where that field is required. Each denial asserts zero calls to action mutation, provider/row query, renderer, dispatcher, audit writer, artifact inventory, temporary-link service, and S3.

Two-connection PostgreSQL TOCTOU tests pause after persisted subject resolution but before permission evaluation, then concurrently revoke membership/permission, replace publication, expire/replace snapshot, or change export-parent/artifact identity. The in-flight call observes one old snapshot or fails closed, never a mixed target; the next call observes the committed change. Catalog tests publish two definitions with different permission policies, assert independent seven-bit vectors and deterministic ordering, and reject a single fake target, first-definition reuse, N+1 ambient lookups, or visibility copied between definitions. Architecture tests prove controllers contain no operation enum, registry/store/model lookup, target construction, service locator, or fallback branch, and prove the resolver/factory/authorizer share the explicit transaction boundary. A separate server-owned exact-selection fixture proves a typed Plan 2/3 scope can enter only through `authorizeExact()`.

Two-connection PostgreSQL tests cover:

- membership revocation during the repeatable-read transaction cannot produce mixed old/new facts; the next invocation denies;
- holding detach and project assignment revocation have the same snapshot/next-invocation behavior;
- resource ownership transfer during authorization cannot be combined with pre-transfer project/permission facts;
- role/ABAC revocation committed before transaction start denies, while a commit after snapshot acquisition is visible only to the next invocation;
- role, permission-definition, and active-condition changes are reread inside the same repeatable-read snapshot without the nominal 300-second authorization cache;
- the returned actor and decision originate from one invocation, and the Task 5 consumer has no second actor load.

Migration tests prove PostgreSQL-only transactional declaration, `ACCESS EXCLUSIVE` lock before preflight, empty-only forward and reverse cutover, non-empty/malformed abort before the first DDL, rollback of an injected failure after add/backfill/constraint but before drop, typed JSON root constraint, exactly one old-or-new column after commit/abort, concurrent writer fencing, and no legacy/generic/dual-read code. Do not run PostgreSQL tests locally.

**Step 2: Prove RED**

Run the DB-free unit and architecture subset:

```bash
vendor/bin/phpunit \
  tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php \
  tests/Unit/Reporting/Access/ReportScopedResourceAuthorizerContractTest.php \
  tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php \
  tests/Unit/Reporting/Access/CurrentReportPermissionDecisionTest.php \
  tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php \
  tests/Unit/Reporting/Access/ReportAuthorizationSubjectTest.php \
  tests/Unit/Reporting/Access/ReportHttpAuthorizationOrchestratorTest.php \
  tests/Unit/Reporting/Access/LaravelReportHttpAuthorizationTargetResolverTest.php \
  tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorBehaviorTest.php \
  tests/Unit/Reporting/Http/ReportControllerContractTest.php \
  tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php \
  tests/Unit/Reporting/Access/OrganizationReportScopeResolverTest.php \
  tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php \
  tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php \
  tests/Architecture/Reporting/ThinReportControllerTest.php \
  tests/Architecture/Reporting/ReportPortSignatureTest.php
```

Expected: failures because typed resources/decisions, queue-safe ABAC facts/evaluator, server-owned HTTP subject/target orchestration, per-definition catalog authorization, the atomic result, registry, authorizer, thin callsite cutover, and untyped/client-target prohibition do not exist.

**Step 3: Implement the exact cutover**

Implement only the exact contracts, transaction, typed decisions, queue-safe current ABAC evaluator, registry, projections, and atomic migration above. Do not add a compatibility constructor, deprecated property, array-of-int branch, request-attribute fallback, request-global/cache authorization path, default/wildcard resource adapter, void/boolean resource proof, or second authorization read.

**Step 4: Prove GREEN**

Run the same DB-free subset plus every changed Task 4a–4d reporting unit/architecture test. Write the PostgreSQL feature/race tests but do not execute them locally under the project DB prohibition. Inspect them statically for two independent connections, exact synchronization barriers, microsecond timestamps, rollback cleanup, and fail-closed assertions.

**Step 5: Static and manifest gates**

Run `php -l` for every PHP path in the exact78 manifest, PHPStan over every Task 4e production/test PHP path, the migration prohibition/static test, Plan 1a handoff/tooling tests, and:

```bash
git diff --check
git grep -n -E 'resourceIds|resource_ids|scope_resource_ids|allowed_resource_ids' -- \
  app/BusinessModules/Core/Reporting tests/Unit/Reporting tests/Feature/Reporting tests/Architecture/Reporting
```

The final grep must be empty except the new migration's explicitly quoted source-column name and migration tests asserting its removal. Require independent architecture/security review before staging.

**Step 6: Commit once**

```bash
git add <the exact 78 paths listed above>
# Require exact sorted 78-path staged-set equality, parent-bound lock verification, and an independent staged review.
# Require the cross-task ownership audit: Task 4e exact78 + Task 5 exact30 = all 108 changed paths, zero overlap, zero unowned, zero extra.
git commit -m "feat[reports]: типизировать ресурсы и текущую авторизацию"
# Rebuild ignored evidence with the actual Task 4e SHA; require normal + --verify-existing no-write fence, exact78 diff-tree equality, and clean Task 4e scoped status.
```

Record the new commit SHA and plan SHA-256. Task 5 may start only after post-commit DB-free regression, PHPStan, syntax, manifest, and architecture gates are green. PostgreSQL and migration execution remain deferred to the authorized CI/staging environment.

### Task 4f: Make Plan 1a completion evidence phase-aware from committed trees

**Dependency:** Task 4e is already committed at exact SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`. This is a new forward-only descendant before Task 5. Do not amend, squash, reset, cherry-pick over, or otherwise rewrite Task 4e or any earlier task.

**Reason for the prerequisite:** the committed Task 4e completion builder still requires a globally clean worktree, but its committed handoff/scope-boundary tests incorrectly expect the pending Task 5 exact30 manifest and the final five-path dispatch allowlist. Task 5 is not an all-new tree: it contains 29 `Create` paths plus one existing-path `Modify`, `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`. At clean Task 4e HEAD that modified path is the regular blob `e366f714d24672b1ac457df93eba12275a456040`; the 29 `Create` paths are absent. A clean replay at Task 4e HEAD therefore fails because the Task 5 queue adapter/jobs are absent; restoring the pending Task 5 work makes the builder correctly reject a dirty tree. Task 4f removes this contradiction without weakening either rule: evidence authority comes only from the current commit tree and its exact parent diff, while dirty-worktree rejection remains global and unconditional.

The raw clean Task 4e commit is recorded truthfully as historically red under its committed stale gate; Task 4f does not relabel or regenerate it as green. The first supported PRE5 state is the clean Task 4f descendant: its production reporting tree is still Task 4e-only, all 29 Task 5 `Create` paths are absent, and the one Task 5 `Modify` path remains the regular baseline blob `e366f714d24672b1ac457df93eba12275a456040` from Task 4e. Only the exact13 evidence/governance repair differs.

**Files (exact 13-path commit manifest):**

- Modify: `tests/Architecture/Reporting/PlanOneAHandoffContractTest.php`
- Modify: `tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php`
- Modify: `docs/reports/contracts/plan-1a-completion.schema.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.json`
- Modify: `docs/reports/contracts/plan-1a-contract-lock.sha256`
- Modify: `docs/reports/contracts/plan-1a-gate-evidence.schema.json`
- Modify: `scripts/reporting/build-plan-1a-evidence.php`
- Modify: `scripts/reporting/run-plan-1a-gates.php`
- Modify: `tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json`
- Modify: `tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json`
- Modify: `tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php`
- Modify: `tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php`

**Task file count:** 13 exact tracked files.

**Exact subject and lineage:**

- Subject: `fix[reports]: разделить evidence по фазам исполнения`.
- Parent: exact Task 4e SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`.
- Lineage: `Task 4a exact53 -> Task 4b exact39 -> Task 4a2 exact16 -> Task 4c exact15 -> Task 4d exact6 -> Task 4e exact78@57b9e1b5eb3d646f5d24f78e00165ca9b272e93d -> Task 4f exact13 -> Task 5 exact30 pending`.
- Task 4f changes only evidence/governance code and fixtures. It owns no production reporting class, migration, queue adapter, or job.

**Phase-aware commit-tree authority:**

The builder and runner determine phase exclusively from `HEAD`, its verified ancestry, and exact `git diff-tree` path sets. They never inspect unstaged/staged content to satisfy an evidence assertion and never infer phase from file existence in the working directory.

The tracked completion schema, gate schema, lock, sidecar, command ledger, and valid fixtures declare only the closed phase names, exact subjects, exact ordered E/F/5 manifests, Task 5's fixed `29 Create + 1 Modify` classification, structural parent rules, four/five-path allowlists, and transition invariants. The sole Task 5 `Modify` path is `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`; its PRE5 baseline is the regular Task 4e blob `e366f714d24672b1ac457df93eba12275a456040`. They must not embed the eventual Task 4f commit SHA, Task 5 commit SHA, current `HEAD`, or any full-tree/projection digest: that would make the commit self-referential or require a later tracked governance mutation outside Task 5 exact30. Actual Task 4f/Task 5 commit SHA, derived phase/state, verified parent identities, and canonical committed-tree projection digests exist only in ignored generated evidence after the relevant commit and are recomputed from the verified graph/diff-tree on every normal/verify-existing run.

1. `POST_TASK_4E_PRE_TASK_5`: selected only when `HEAD` has the exact Task 4f subject, its parent is exact Task 4e SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`, and `git diff-tree` for `HEAD` is exact13. The parent is independently verified as Task 4e exact78 with its locked subject/parent. Task 5 is derived as pending only when all 29 declared `Create` paths are absent from the committed `HEAD` tree and `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php` is present as a regular `100644` blob with OID `e366f714d24672b1ac457df93eba12275a456040`. The same OID and bytes must be read from verified Task 4e (`57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`) and from Task 4f `HEAD`, proving the baseline is unchanged through Task 4f. `PlanOneAScopeBoundaryTest` uses the exact sorted committed four-path dispatch allowlist:

```text
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportDispatcher.php
app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportMaterializationDispatcher.php
app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/LaravelReportDispatchIntentPublisher.php
```

It separately requires the pending Task 5 path `app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php` and every Task 5 `Infrastructure/Jobs` path to be absent from the committed tree. The reporting module root `Jobs` prohibition and dispatch-leakage scan remain hard failures.

2. `POST_TASK_5`: selected only when `HEAD` has exact subject `feat[reports]: материализовать снимки с текущей авторизацией` and an exact30 `git diff-tree --name-status` containing exactly 29 `A` entries for the declared `Create` paths plus one `M` entry for `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`, while its parent has the exact Task 4f subject, exact13 diff-tree, and parent `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`. For that sole modification, raw diff-tree old blob must be the PRE5 baseline OID `e366f714d24672b1ac457df93eba12275a456040` and its new blob must differ. Task 5 is derived as present only after all 30 classified changes meet those conditions; a 29-add-only, unchanged-modification, wrong-old-blob, or any other partial Task 5 diff is rejected. The scope boundary then selects the final exact sorted five-path allowlist by adding only `Infrastructure/Queue/LaravelReportMaterializationDispatcher.php`. No tracked governance file changes at Task 5 and no unrelated committed path may satisfy either manifest.

Both phases reject an unknown HEAD/parent, wrong subject, rewritten ancestry, missing/extra/reordered manifest path, a pending `Create` path committed early, an altered/missing PRE5 `Modify` baseline, a present path absent later, a wrong old/new modification blob, a partial Task 5 diff, dispatch outside the phase allowlist, a root `Jobs` directory, or union/overlap drift. There is no compatibility/default phase, file-existence fallback, skip, xFail, broad exclusion, environment branch, or acceptance of an unstaged future file.

`PlanOneAHandoffContractTest` verifies the three commits independently: immutable E is Task 4e exact78 at `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`; F is the structurally discovered Task 4f exact13 child; optional 5 is either PRE5's 29-absent-Create plus exact-existing-Modify baseline or POST5's exact30 child classified as 29 adds plus one changed modification. It proves the abstract ownership equation `E ∪ 5 = 108` and `E ∩ 5 = ∅` from the two declared manifests even in PRE5, while F remains a separate evidence-only exact13 set and is never folded into the 108 product-change ownership set. It never compares a broad working-tree diff, `HEAD` full-tree path list, or unstaged content to 108.

`PlanOneAScopeBoundaryTest` enumerates production blobs only from the verified commit using exact `git ls-tree -r --name-only <verified-head>` and reads inspected bytes only with `git show <verified-head>:<path>`. PRE5 selects the exact four-path allowlist; POST5 selects the exact five-path allowlist. Files in the worktree, index, stash, ignored directories, alternate refs, or filesystem overlays cannot add an allowed boundary or select a phase.

**Dirty-worktree invariant:**

The first operation in both normal generation and `--verify-existing` is `git status --porcelain=v1 --untracked-files=all`, which must be exactly empty across the entire repository. This preflight precedes phase discovery, graph reads, schema reads, and evidence reads and is not scoped to reporting paths.

Only after PRE5 has been selected from the verified commit graph, a rejection-only pending probe treats the classified paths differently. For each of the 29 declared `Create` paths, it runs `git ls-files --others --ignored --exclude-standard -- <create pathspecs>` and a non-following `lstat`; any untracked/ignored occupant, symlink, directory substitution, or other filesystem occupant fails replay. For the sole `Modify` path, it requires a non-symlink regular worktree file, `git diff --quiet --cached HEAD -- tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`, `git diff --quiet HEAD -- tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`, and byte equality with the verified `HEAD` baseline blob `e366f714d24672b1ac457df93eba12275a456040`; any staged, unstaged, missing, symlinked, or content/OID deviation fails replay. These checks supplement rather than relax the first global-clean preflight. The probe can only reject: an empty result never proves PRE5, never marks Task 5 pending, never chooses an allowlist, and never contributes bytes/digests to evidence. POST5 authority still comes only from its exact committed diff-tree.

Evidence generation/verification records `HEAD` and global porcelain before reads, then rechecks both `HEAD` and `git status --porcelain=v1 --untracked-files=all` after every evidence/schema/commit-tree read and before success. `--verify-existing` retains the existing byte hash/mtime no-write fence. A dirty or hidden-pending tree always fails even if its bytes would complete the next phase.

**Required tests:**

- clean Task 4f tree passes `POST_TASK_4E_PRE_TASK_5` with Task 4e exact78 present, Task 4f exact13 present, all 29 Task 5 `Create` paths absent, `ReportQueueRuntimeContractTest.php` present as the exact regular baseline blob `e366f714d24672b1ac457df93eba12275a456040`, and the exact four-path allowlist;
- raw Task 4e HEAD remains a recognized historical-red unsupported replay, while only its clean Task 4f child is accepted as PRE5;
- placing any Task 5 `Create` path as tracked, staged, unstaged, untracked, symlinked, or ignored-but-explicit future input fails the global clean preflight or committed-tree phase check; an otherwise clean PRE5 tree also rejects a missing, symlinked, staged, unstaged, or byte-different `ReportQueueRuntimeContractTest.php` baseline;
- a clean synthetic Task 5 child with exact30 `29 A + 1 M` diff transitions to `POST_TASK_5`, requires all 29 declared additions plus the changed runtime-contract modification, verifies old blob `e366f714d24672b1ac457df93eba12275a456040` and a different new blob, and uses the exact five-path allowlist;
- synthetic PRE5 trees reject an existing runtime-contract path with a non-baseline blob, an early modification of that path, or a missing baseline; synthetic POST5 trees reject a wrong old blob and a 29-add-only child whose runtime-contract path remains unchanged;
- tracked lock/schema/fixtures contain no Task 4f/Task 5 actual commit SHA, `HEAD`, full-tree digest, or generated phase state; PRE5 and POST5 reuse identical tracked governance bytes;
- phase discovery rejects a Task 4f-lookalike HEAD with wrong parent/diff/subject and a Task 5-lookalike HEAD whose parent does not independently verify exact Task 4f→`57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`;
- handoff derives E=78, F=13, optional5=30 from independent commit diffs, proves abstract E∪5=108/disjoint, and rejects any attempt to use a broad working diff/full tree as that ownership set;
- scope inspection proves all enumerated paths/bytes come from `ls-tree`/`show` at the verified commit and that filesystem-only overlays cannot affect its four/five-path result;
- the pending-path probe rejects each hidden `Create` occupant and mutation-tests that its empty/nonempty result cannot select phase or produce evidence fields; it separately rejects every staged/unstaged/symlink/content deviation of the sole `Modify` path from the verified PRE5 `HEAD` baseline;
- missing/extra/reordered Task 4e/4f/5 paths, wrong 29-add/one-modify classification, overlap, wrong parent/subject/SHA, wrong modification old blob, unchanged modification new blob, early queue adapter, late missing queue adapter, root `Jobs`, and dispatch leakage all fail hard;
- normal generation and `--verify-existing` produce/re-read identical phase bytes; verify-existing changes neither content hash nor mtime;
- staging any Task 4f path still makes normal builder/runner fail their unchanged global-clean preflight; pure renderer tests alone validate precommit tracked lock/sidecar determinism;
- mutation during evidence reads is caught by the final repeated `HEAD` and global-porcelain checks;
- tests construct real temporary git commits/trees. Mocks of `git status`, filesystem-only presence checks, or copying pending files into a clean fixture are insufficient.

**Safe stash/restore choreography for the already prepared Task 5 exact30 work:**

1. Before Task 4f work, assert the observed current shape is exactly one tracked unstaged Task 5 `Modify` path, `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`, plus 29 untracked Task 5 `Create` paths, together equal to the sorted exact30 classified manifest, with zero overlap against Task 4f exact13. Require the tracked path's HEAD baseline to be regular blob `e366f714d24672b1ac457df93eba12275a456040` from Task 4e before its unstaged Task 5 edit. Record outside the repository a canonical fingerprint for all 30 paths containing relative path, classification, porcelain status, executable mode, byte length, and SHA-256 of exact bytes.
2. Stash only those exact30 paths with tracked index/worktree state and untracked files included. Record the resulting stash object ID; do not use an unnamed/latest-stash assumption.
3. Require global `git status --porcelain=v1 --untracked-files=all` to be empty before editing Task 4f. Implement, test, stage exactly the 13-path manifest, verify exact parent, and commit once with the exact Task 4f subject.
4. With Task 5 still stashed and the repository globally clean, run normal postcommit generation, normal verification, and `--verify-existing` for `POST_TASK_4E_PRE_TASK_5`. Any need to restore Task 5 for this gate is a design failure.
5. Apply the recorded stash object with index restoration but do not drop it. Recompute the exact30 `29 Create + 1 Modify` changed set and canonical fingerprint; require byte-for-byte/classification/status/mode equality with the saved fingerprint, the Modify path's pre-edit HEAD baseline OID to remain `e366f714d24672b1ac457df93eba12275a456040`, and zero Task 4f-path modification.
6. Only after equality succeeds, resolve the captured object ID to exactly one stash reference using `git stash list --format='%gd %H'`, require a unique matching `stash@{n}`, and drop that explicit reference. Never drop “latest” or pass a raw OID as an assumed stash selector. On zero/multiple matches, conflict, or fingerprint drift, preserve the stash, stop, and report failure; never reset, overwrite, or reconstruct the Task 5 work.

**Commit and gates:**

Task 4f precommit may not add a staged-manifest exception to the normal builder/runner clean preflight. While exact13 is staged, validate deterministic tracked lock/sidecar bytes only through the pure renderer/unit contract, plus the two tooling unit tests and three architecture tests, PHP syntax checks, scoped PHPStan, `git diff --check`, exact13 staged-set equality, and independent review. Normal evidence generation and `--verify-existing` are intentionally not run until after the commit when the repository is globally clean. Commit once:

```bash
git commit -m "fix[reports]: разделить evidence по фазам исполнения"
```

After commit, require exact13 diff-tree equality, clean postcommit replay in `POST_TASK_4E_PRE_TASK_5`, normal plus `--verify-existing` no-write proof, and only then restore the exact30 Task 5 fingerprint.

### Task 5: Materialize immutable snapshots with stage-based progress

**Dependency:** Task 4f is committed as the forward-only child of exact Task 4e SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`, and the clean `POST_TASK_4E_PRE_TASK_5` replay is green. Task 5 consumes the Task 4e contracts unchanged and may not recreate an actor loader, scope authorizer, untyped resource path, compatibility adapter, or second current-fact read.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportProgressWritePolicy.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportAsyncContextSeed.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportExpiredExecutionLease.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportExecutionWatchdogSummary.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunAsyncContextSeedReader.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunLeaseRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunAttemptLifecycleStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExecutionTelemetry.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelReportRunExecutionContextRehydrator.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunAsyncContextSeedReader.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunLeaseRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunAttemptLifecycleStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Listeners/FinalizeFailedReportRunAttempt.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportRunExecutionWatchdog.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Execution/ReportRunAttemptFinalizer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportRunExecutionLeasesCommand.php`
- Create: `tests/Unit/Reporting/Execution/ReportProgressWritePolicyTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportRunAsyncContextSeedReaderContractTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php`
- Create: `tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportRunAsyncContextSeedReaderTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php`
- Create: `tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php`
- Create: `tests/Support/Reporting/PostgresProcessRaceHarness.php`
- Modify: `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`

**Task file count:** 30 exact files: 29 `Create` and 1 `Modify`. The sole `Modify` path is `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`; its PRE5 regular baseline is Task 4e blob `e366f714d24672b1ac457df93eba12275a456040` and Task 5 must change it.

**Interfaces consumed or produced:**

The Task 4d private race helper remains byte-for-byte unchanged in Task 5; this task only creates the shared harness for new races. Task 12a owns the bounded deduplication.

- Implement `ReportMaterializationDispatcher`.
- Consume the exact ten-method Task 4b `ReportRunStore`; materialization uses `get()`, `queryForRun()`, `claimMaterialization()`, `persistProgress()`, `sealReady()`, and terminal `fail()`.
- Consume Plan 1a `ReportProgress`, `ReportDataProvider`, `ReportQuery`, `ReportResult`, and `ReportSnapshotRef`.
- `ReportProgressWritePolicy::shouldPersist(ReportProgress, ReportProgress, DateTimeImmutable, DateTimeImmutable): bool`.
- Consume exact Task 4e `CurrentReportScopeAuthorizer::authorizeExact(int $actorId, ReportScope $requestedScope, CurrentReportAuthorizationTarget $target): CurrentReportAuthorization`.
- `ReportRunAsyncContextSeedReader::forRun(string $runId): ReportAsyncContextSeed`.
- `ReportRunLeaseRecoveryStore::due(int $limit, DateTimeImmutable $occurredAt): array`, returning only `ReportExpiredExecutionLease` values.
- `ReportRunLeaseRecoveryStore::requeue(ReportExpiredExecutionLease $lease, DateTimeImmutable $occurredAt): bool`.
- `ReportRunAttemptLifecycleStore::claimOrRenew(string $runId, string $envelopeUuid, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): bool`.
- `ReportRunAttemptLifecycleStore::failLeased(string $runId, string $envelopeUuid, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): bool`.
- `ReportRunAttemptFinalizer::finalize(string $runId, string $leaseToken, ?Throwable $failure, DateTimeImmutable $occurredAt): bool`.
- `FinalizeFailedReportRunAttempt::__invoke(Illuminate\Queue\Events\JobFailed $event): void`.
- `ReportRunExecutionWatchdog::reclaim(int $limit, DateTimeImmutable $occurredAt): ReportExecutionWatchdogSummary`.
- `LaravelReportRunExecutionContextRehydrator::forRun(string $runId): ReportExecutionContext`.
- `ReportExecutionTelemetry::runTransition(string $reportCode, string $status): void`.
- `ReportExecutionTelemetry::runDuration(string $reportCode, string $status, float $seconds): void`.
- `ReportExecutionTelemetry::exportTransition(string $reportCode, string $format, string $status): void`.
- `ReportExecutionTelemetry::exportDuration(string $reportCode, string $format, string $status, float $seconds): void`.
- `ReportExecutionTelemetry::exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void`.
- `ReportExecutionTelemetry::multipartAbort(string $reportCode, string $format): void`.
- `ReportExecutionTelemetry::dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void`.
- `ReportExecutionTelemetry::executionAttempt(string $intentType, string $errorCode): void`.
- `ReportExecutionTelemetry::executionLeaseReclaimed(string $intentType): void`.
- `ReportExecutionTelemetry::auditDeliveryFailure(string $errorCode, string $outcome): void`.

`ReportAsyncContextSeed` is a closed immutable DTO containing only aggregate ID, organization ID, requester actor ID, requested `ReportScope`, the server-persisted published-definition identity needed to rebuild the exact `RUN` authorization target, and nullable `correlationLineageId`. It rejects mismatched aggregate kind/ID and contains no role, permission, authorization decision, allowed-ID list, visibility, transport metadata, query JSON, filter, credential, or token. `ReportExpiredExecutionLease` contains only aggregate kind/ID, organization ID, expected lease token, and exact lease expiry. `ReportExecutionWatchdogSummary` has exactly the non-negative integer fields `{scanned, requeued, skipped, failed}`.

`EloquentReportRunAsyncContextSeedReader` is the sole narrow run-record reader for async bootstrap. It reads only the Task 4e typed `scope_resources` projection plus the immutable published-definition identity and constructs the closed seed; any untyped, malformed, unknown-field, duplicate, noncanonical resource, or unresolved definition revision fails before authorization. `LaravelReportRunExecutionContextRehydrator::forRun(runId)` receives its closed seed instead of touching `ReportRunRecord` or a context-bound store. It rebuilds the exact server-owned `RUN` target with `snapshot=null`, invokes Task 4e `CurrentReportScopeAuthorizer::authorizeExact()` exactly once, and consumes the returned actor, decision, complete visibility, and target from the same repeatable-read snapshot. It requires exact actor identity, complete scope canonical equality, exact target fingerprint, and `visibility.run=true`, then replaces only transport facts with channel `queue`, a fresh server-generated correlation ID, the persisted correlation lineage ID, and exact metadata `{job: materialize_report_run, lineage_id: ?string}`. It performs no final actor reload, second permission lookup, resource re-resolution, or partial intersection. The job separately obtains and validates the Laravel envelope UUID for lease fencing. The rehydrator never reads/deserializes a previous authorization decision, stored permission list, visibility, transport metadata, role snapshot, or untyped allowed-ID list.

`EloquentReportRunLeaseRecoveryStore` is the sole run-lease recovery persistence boundary. `due()` keyset-scans expired `MATERIALIZING` leases without returning query/result payload. `requeue()` opens one transaction, locks the organization/run row, requires the same status, token, and `execution_lease_expires_at <= occurredAt`, resets it to `QUEUED`, clears lease fields, and inserts the deterministic run recovery transport intent before commit. A stale candidate returns `false`; intent failure rolls back the reset. The watchdog consumes only this port and never references `ReportRunRecord`, `ReportDispatchIntentRecord`, or `EloquentReportRunStore`.

`EloquentReportRunAttemptLifecycleStore` is the only authority-free bootstrap/exhausted-attempt boundary. `claimOrRenew(runId, envelopeUuid, leaseExpiresAt, at)` row-locks the stored aggregate and either transitions `QUEUED -> MATERIALIZING` with a canonical UUID/live expiry or renews only a live `MATERIALIZING` lease carrying the same token. Different token, expired/equal lease, or terminal state returns `false`. Only the first transition appends its deterministic start audit; same-token renewal preserves `started_at`, progress, and audit cardinality. `failLeased(runId, envelopeUuid, code, at)` requires `MATERIALIZING`, the exact live token, and derives tenant/organization, requester, subject, and audit lineage only from the persisted aggregate. It accepts only a safe catalogued code, atomically writes `FAILED`, clears lease fields, sets the terminal timestamp, and appends one deterministic secret-free audit intent. Neither method loads an actor, fabricates an execution context, consults current authorization, or calls a provider/data source. Audit-intent failure rolls back the state transition.

Laravel's `JobFailed` event, not a job `failed()` method, invokes `FinalizeFailedReportRunAttempt`. The listener accepts only connection `redis_reports`, queue `reports`, the exact resolved `MaterializeReportRunJob` name, a canonical event job UUID, and the ID-only run payload. It extracts only `runId`, never deserializes authority, and passes the UUID as lease token. The finalizer maps the safe code and calls only `failLeased(runId, envelopeUuid, errorCode, occurredAt)`; it never falls back to an unqualified queued transition. Malformed UUID/payload, foreign connection/queue/job, or absent exception are rejected and observed without aggregate mutation. Replayed or late events are idempotent; after watchdog requeue the old token cannot terminalize `QUEUED` or a later generation. This path deliberately performs no current-user authorization.

**Step 1: Write failing tests**
Cover:

- the job serializes only the run ID;
- execution context, published definition, and Plan 1a binding are reloaded in `handle()`;
- cancellation before start performs no provider call;
- duplicate delivery does no work for `READY`;
- materialization and result use the same context and returned snapshot;
- progress writes happen only after a stage, never more often than five seconds, and only after at least one percentage point;
- no observer is passed to or installed on Plan 1a `ReportProgress`;
- validation failure prevents `READY`;
- provider output raising typed `OFFICIAL_SEAL_REQUIRED` maps to `REPORT_OFFICIAL_SNAPSHOT_UNSEALED`; invalid kind/ID, operational+seal, invalid seal time, stale time, and unknown structural failures map to `REPORT_INTERNAL_ERROR` and no raw exception escapes;
- current actor/scope revocation is denied before provider resolution;
- no serialized authorization decision is read;
- only the holder of the active execution lease may write progress or ready state;
- an expired lease is reclaimed by the watchdog in a transaction that resets `MATERIALIZING -> QUEUED` and adds the unique recovery outbox intent;
- seed reader output is reflection-locked to the closed DTO and cannot carry request-time authority;
- lease recovery due-scan, token/status/expiry CAS, reset plus recovery-intent atomicity, stale-candidate no-op, and concurrent watchdog fencing pass PostgreSQL tests;
- the Task 4d live-renewal boundary races the now-existing watchdog recovery boundary in both row-lock orders: renewal-first makes recovery stale, watchdog-first clears/requeues and prevents the old token from renewing or writing;
- a non-retryable catalogued failure persists its safe code terminally on the current attempt;
- the ID-only job validates its envelope UUID and calls the authority-free `claimOrRenew()` before current-fact rehydration; a non-retryable catalogued authorization failure then calls only `failLeased()` with that token, while retryable or unexpected failure escapes under the lease for retry/watchdog handling;
- a retryable catalogued or unexpected failure records attempt telemetry, remains `MATERIALIZING` under the same queue-envelope lease, and is rethrown without changing the aggregate to `FAILED`;
- only the token-fenced Laravel `JobFailed` listener/finalizer persists an exhausted-attempt safe code (`REPORT_INTERNAL_ERROR` for an unclassified throwable), without current-user authorization;
- single-call consumption of Task 4e atomic current authorization, exact typed-scope equality, actor mismatch rejection, fresh correlation plus durable lineage, absence of a second actor/permission/resource read, and rehydrator rejection paths are independently tested;
- malformed/foreign failed events, UUID mismatch, stale/expired token, status races, already-terminal state, and audit rollback never create an invalid terminal mutation; finalizer/watchdog races and event replay create at most one terminal audit;
- expired lease -> watchdog requeue -> late old `JobFailed` is an explicit no-op, including after a later queue generation starts;
- reflection locks the lifecycle boundary to exactly `claimOrRenew` and `failLeased` with the declared parameter order/types and no generic, context-taking, or unqualified queued-failure method;
- audit failure rolls back ready state.

**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit \
  tests/Unit/Reporting/Execution/ReportProgressWritePolicyTest.php \
  tests/Unit/Reporting/Execution/ReportRunAsyncContextSeedReaderContractTest.php \
  tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php \
  tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php \
  tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php \
  tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php \
  tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php \
  tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php
```
Expected: failure because the policy, ports, finalizer/listener, rehydrator, dispatcher, and job do not exist. `ReportRunAttemptFinalizerTest` contains the full `JobFailed` listener routing/filtering matrix.
**Step 3: Implement the job**
The job:

1. reloads context, published definition, matching Plan 1a binding, and the persisted Plan 1a query;
2. uses the Laravel queue envelope UUID as a stable lease token, locks and transitions `QUEUED` to `MATERIALIZING` with expiry `now + 960 seconds`; retry of the same envelope may renew the same token, while another token is fenced;
3. constructs `new ReportProgress($run->progress)`;
4. calls the exact Plan 1a `materialize(context, query, progress)`;
5. persists the current percent and extends the lease to `now + 960 seconds` after materialization only when policy permits;
6. calls `result(context, snapshot)`;
7. maps only provider `OFFICIAL_SEAL_REQUIRED` to `REPORT_OFFICIAL_SNAPSHOT_UNSEALED`, maps every other structural reason to `REPORT_INTERNAL_ERROR`, then computes and validates immutable identity for a constructed snapshot;
8. advances progress to 100;
9. calls audited `sealReady()`.

The Plan 1a progress object is neither wrapped nor extended. This plan intentionally records stage checkpoints; provider-internal callbacks are not invented.
Configure exact `tries=5`, backoff `[30,120,300,900]`, `timeout=900`, `failOnTimeout=true`, and the locked Task 4d queue runtime. `handle()` validates the Laravel envelope UUID, authority-free claims `QUEUED -> MATERIALIZING` with that token and a 960-second lease, then rehydrates current facts. A non-retryable catalogued rehydration/provider error calls `failLeased()`; retryable or unexpected errors leave `MATERIALIZING`, emit attempt telemetry, and escape. Same-envelope retry renews only the still-live lease through the existing context-bound store after successful rehydration. The job has no `failed()` method. Exhaustion arrives through Laravel's `JobFailed` listener/finalizer, which calls only leased CAS and never constructs an authorization context. If the lease expires first, the watchdog atomically requeues and clears it; any late old event is fenced, and shared row locks ensure exactly one valid terminal or recovery result.
**Step 4: Prove GREEN**
Run the same exact DB-free command and expect all queue, progress, identity, audit, finalizer/listener, and retry cases to pass.

Run only in isolated PostgreSQL CI:
```bash
vendor/bin/phpunit \
  tests/Feature/Reporting/Persistence/EloquentReportRunAsyncContextSeedReaderTest.php \
  tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php \
  tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php
```
This gate includes authority-free first claim and same-token renewal before authorization, leased nonretryable/exhausted terminalization, status/token/expiry stale no-ops, deletion/revocation-independent cleanup, repeat-event idempotency, audit rollback, and the explicit ABA matrix: expired old lease -> watchdog requeue -> late old `JobFailed` leaves `QUEUED` unchanged; if a new envelope claims first, the new token/state survive unchanged. It also includes the actual two-connection renewal-first/watchdog-first race between Task 4d's run store and the now-existing lease-recovery store, exact microseconds, stale loser behavior, and atomic recovery-intent insertion. Task 4e exclusively owns organization/project/resource/current-authorization PostgreSQL matrices; Task 5 does not duplicate them. Do not run these database tests locally.
**Step 5: Static analysis**
Run PHP syntax checks for every PHP path in the exact30 manifest and PHPStan over all Task 5 production and test paths, including the modified runtime architecture test. No path may be silently omitted.
**Step 6: Commit**
```bash
git add <the 30 exact paths listed above>
# Before commit, require the sorted staged path set to equal the 30-path Files manifest exactly, classified as 29 new paths plus the one modified runtime-contract path; fail on every missing or extra path.
git commit -m "feat[reports]: материализовать снимки с текущей авторизацией"
```

After commit, require `git diff-tree --no-commit-id --name-only -r HEAD` to equal the same 30-path manifest, and `git diff-tree --no-commit-id --name-status -r HEAD` to classify it as 29 `A` plus one `M` for `tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php`. Require the raw old blob for that modification to equal PRE5 baseline `e366f714d24672b1ac457df93eba12275a456040`, its new blob to differ, no partial Task 5 diff, and `git status --short -- <those 30 paths>` to be empty.

### Task 6: Validate row streams and implement rows, signed cursors, and drill-down

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Rows/ReportCursorRow.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Rows/ReportRowChunk.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Rows/ReportRowChunkReader.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Cursors/SignedReportCursorCodec.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRowsHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportDrillDownHandler.php`
- Create: `tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php`
- Create: `tests/Unit/Reporting/Cursors/SignedReportCursorCodecTest.php`
- Create: `tests/Unit/Reporting/Actions/ReportReadHandlersTest.php`
- Create: `tests/Contract/Reporting/ReportRowsParityContractTest.php`

**Task file count:** 10 exact files.

**Interfaces consumed or produced:**

- Consume exact Plan 1a `ReportRowQuery`, `ReportDrillDownProvider`, `ReportCursor`, `ReportPage`, `ReportRowsWindow`, and `ReportWindowSort`.
- Consume final Task 4b `ReportRunStore::get()` and `queryForRun()` for authorized ready-run identity; do not introduce a read-side run repository.
- The ready `ReportRun::resultMetadata->snapshot` is the exact reconstructed `ReportSnapshotRef` passed to row and drill-down providers. Task 6 does not need or add `snapshotForRun()`: `get()` supplies the sealed snapshot through the locked Plan 1a result metadata, while `queryForRun()` supplies the exact original query.
- Implement exact Plan 1a `GetReportRowsAction` and `GetReportDrillDownAction`.
- `ReportCursorRow` is an internal execution record: row key, values, snapshot ID, query hash, and source hash.
- `ReportRowChunk` is an internal non-empty list of at most 5,000 validated `ReportCursorRow` objects with one shared identity.
- `ReportRowChunkReader::read(ReportExecutionContext, ReportSnapshotRef, Sha256Hash, ReportWindowSort, int, ReportRowQuery): iterable`.

**Step 1: Write failing tests**
Cover:

- a single-row iterator becomes one one-row chunk;
- an empty iterator produces no chunks;
- a yielded empty list is rejected instead of being treated as a chunk;
- a yielded list containing 5,001 rows is rejected instead of being treated as one provider chunk;
- an ordinary row lacking the internal identity envelope is rejected;
- snapshot, source, or query hash drift is rejected before rendering;
- generated chunks are non-empty and never exceed the requested bound;
- invalid signatures, key rotation misses, expired cursors, wrong organization, wrong report, wrong snapshot, wrong sort, and malformed payloads map through Plan 1a cursor errors;
- row pages, cursor stream, and drill-down preserve the same immutable identity;
- expired status remains observable through the run-status handler, but rows and drill-down reject `EXPIRED` with `REPORT_SNAPSHOT_EXPIRED` before any provider call;
- rows authorize `VIEW`, then `VIEW_SENSITIVE` when typed row classification requires it and `VIEW_AUDIT` when typed audit classification requires it; drill-down applies the same typed decisions before `DRILL_DOWN`. Column names/values and permission-list emptiness are not classification inputs.

```php
public function test_rejects_provider_output_that_looks_like_an_oversized_chunk(): void
{
    $rows = array_fill(0, 5001, $this->validRowEnvelope());
    $query = FakeReportRowQuery::yielding([$rows]);
    $this->expectException(ReportContractException::class);
    iterator_to_array($this->reader->read(
        $this->context,
        $this->snapshot,
        $this->queryHash,
        $this->sort,
        5000,
        $query,
    ));
}
```
**Step 2: Prove RED**
Run the four targeted test files. Expected: failures because the adapter, codec, handlers, and parity contract do not exist.
**Step 3: Implement the minimum**
`ReportRowChunkReader` invokes the existing `ReportRowQuery::cursor()` once. Each yielded item must be one row envelope with exactly:
```text
row_key
values
snapshot_id
query_hash
source_hash
```
It validates and converts one item at a time, groups rows internally, and yields only bounded `ReportRowChunk` values. It never assumes the Plan 1a iterable already contains chunks.
The signed cursor payload includes key ID, organization ID, report code, run ID, snapshot ID, definition/query/source hashes, sort field and direction, last sort value, last stable row key, issue time, and expiry. The codec returns the existing Plan 1a `ReportCursor`.
`GetReportRowsHandler` loads a ready unexpired run, reauthorizes current actor/scope and the exact typed output classification, decodes the cursor, and calls the exact Plan 1a `page()` method. Drill-down performs the same current reauthorization and immutable identity checks before `drillDown()`. Revocation or expiry prevents all provider calls.
**Step 4: Prove GREEN**
Run the same tests. Expect negative shape tests, signature tests, access tests, and online/export identity parity to pass.
**Step 5: Static analysis**
Run PHPStan for the row, cursor, and handler files.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Rows app/BusinessModules/Core/Reporting/Infrastructure/Cursors app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRowsHandler.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportDrillDownHandler.php tests/Unit/Reporting/Rows tests/Unit/Reporting/Cursors tests/Unit/Reporting/Actions/ReportReadHandlersTest.php tests/Contract/Reporting/ReportRowsParityContractTest.php
git commit -m "feat[reports]: добавить защищенные строки и детализацию"
```

### Task 7: Persist exports with complete immutable identity, leases, outbox, and audited transitions

**Dependency:** Tasks 4a–6, including forward-only Tasks 4d and 4e, are green. The exact seven-method store surface is locked in this task before Tasks 8–13 consume it.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportStore.php`
- Create: `app/Services/Storage/DTO/StoredFile.php`
- Create: `database/migrations/2026_07_26_000005_create_report_exports_table.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportExportRecord.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportExportHydrator.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuthorizationSubjectReader.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportAuthorizationSubjectReaderTest.php`
- Create: `tests/Unit/Reporting/Persistence/ReportExportHydratorTest.php`
- Create: `tests/Architecture/Reporting/ReportAuthorizationSubjectReaderOwnershipTest.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php`
- Modify: `tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php`

**Task file count:** 13 exact files.

**Interfaces consumed or produced:**

- Create and implement `ReportExportStore` with exactly seven methods and no later extension:

```php
interface ReportExportStore
{
    public function createOrReuse(ReportExecutionContext $context, ReportRunExportSource $source, CreateReportExportData $data, IdempotencyKey $idempotencyKey): ReportExport;
    public function get(ReportExecutionContext $context, string $exportId): ReportExport;
    public function startRendering(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport;
    public function startUploading(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport;
    public function sealReady(ReportExecutionContext $context, string $exportId, string $leaseToken, StoredFile $artifact, int $rowCount, DateTimeImmutable $occurredAt): ReportExport;
    public function fail(ReportExecutionContext $context, string $exportId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportExport;
    public function cancel(ReportExecutionContext $context, string $exportId, DateTimeImmutable $occurredAt): ReportExport;
}
```

- `createOrReuse()` inserts the unique initial export dispatch intent in the same PostgreSQL transaction as a newly inserted export; replay creates no intent.
- Task 7 completes the predeclared export branch of `ReportDispatchIntentStore::markPublicationFailed()` without changing its six-method interface. On fenced attempt 12, one PostgreSQL transaction locks the leased export intent and organization/export row, requires matching `EXPORT|GENERATE_EXPORT`, `status='leased'`, lease token and `attempt_count=12`, dead-letters the intent, and CAS-transitions only a still-`QUEUED` export to `FAILED` with the safe error code. Missing export, non-queued export, stale token, wrong topic, or already-terminal intent rolls back/no-ops exactly as the locked contract specifies. This is the only reason Task 7 modifies the Task 4b dispatch store/test; Task 4b contains no impossible pre-table export branch.
- `StoredFile` contains exact path, non-empty version ID and ETag, positive size, lowercase SHA-256 checksum, and MIME; constructor rejects incomplete identity.
- Hydrate the Plan 1a `ReportExport` directly.
- Consume Plan 1a `ReportExportStatus`, `CreateReportExportData`, `IdempotencyKey`, `ReportRun`, and `Sha256Hash`.
- Implement the Task 4e `ReportAuthorizationSubjectReader` exactly once as `EloquentReportAuthorizationSubjectReader`. Task 7 owns it because this is the first point where both run and export schemas exist. It joins the caller's repeatable-read transaction, reads only `ReportRunRecord` and `ReportExportRecord`, and returns the closed Task 4e subject. It never invokes a context-requiring action/store, preventing a resolver-reader-store-authorization dependency cycle.

**Step 1: Write failing tests**
Reflection-lock the exact seven export-store methods, parameter order/types and absence of an eighth method. Cover organization-scoped cross-actor idempotency, other-organization isolation, normalized conflicts, atomic export+intent rollback, complete run/export identity equality, lease fencing/expiry, transition races, illegal transitions, outbox-audit rollback, safe error persistence, exact artifact version identity, typed classification/seal persistence, complete Plan 1a DTO hydration, and the dispatch-store attempt-12 export branch including atomic dead-letter+queued-export failure, stale-token/topic/status races, and no mutation of non-queued exports. Reader persistence tests instantiate every run state `QUEUED|MATERIALIZING|READY|FAILED|CANCELLED|EXPIRED` and every export state `QUEUED|RUNNING|UPLOADING|READY|FAILED|CANCELLED|EXPIRED`, asserting the exact Task 4e matrix evidence projection for each. They cover queued run scope without snapshot, ready run scope/snapshot equality, export/parent/snapshot exact equality, malformed typed scope, missing parent, cross-organization parent, definition mismatch, evidence omission/mutation by state, and every scope mismatch failing closed. The ownership test permits direct run/export record reads only in this reader and rejects writes, inline persistence in resolver/orchestrator/controllers, container lookup, store injection, and nested transactions.
**Step 2: Prove RED**
Run the hydrator unit and subject-reader ownership tests locally. Run the PostgreSQL store and subject-reader persistence tests only in isolated CI. Expected: red until schema, store, and the single concrete reader exist.
**Step 3: Implement schema and store**
The table includes:

- canonical ULID primary key, run ID, organization and actor IDs;
- nullable `correlation_lineage_id` copied from the parent run as diagnostic lineage only; export deliveries generate fresh correlation IDs and never treat lineage as authority;
- report code and Plan 1a export status;
- definition, query, source, result, and export hashes; the complete canonical typed `ReportScope` copied from the parent run; snapshot kind/ID/classification; optional complete seal identity; definition data classification; exact sensitive/audit column IDs and audit-summary flags;
- contract, formula, source-schema, and renderer versions;
- format, normalized columns, normalized sort, locale, timezone;
- idempotency hash and normalized input fingerprint;
- artifact path, exact version ID, ETag, MIME, checksum, byte size, row count;
- nullable all-or-none execution lease token, expiry, and heartbeat;
- safe error code and lifecycle timestamps.

Add:

- exact unique `(organization_id, idempotency_key_hash)` named `report_exports_org_idempotency_unique`;
- partial unique execution-token index where `execution_lease_token IS NOT NULL`;
- `(organization_id, id)` and `(run_id, status)`;
- partial queue and retention indexes;
- hash-length, positive-size, ready-artifact, and terminal-timestamp checks.

`export_hash` and `input_fingerprint` are derived from one closed canonical projection containing the parent run ID, complete canonical parent `ReportScope`, result hash, snapshot kind/ID/classification and complete seal identity, definition/query/source hashes, definition data classification and sensitive/audit IDs/flags, all four versions, format, selected columns, sort, locale, and timezone. Floats are forbidden; any decimal uses the canonical decimal-string grammar from Task 4c. `createOrReuse()` resolves only by `(organization_id,idempotency_key_hash)`, compares the complete fingerprint in constant time, returns the existing export when equal and raises `REPORT_IDEMPOTENCY_CONFLICT` when changed; actor is requester/audit metadata only.

`startRendering()` claims `QUEUED` with a fenced 960-second lease; a retry of the same Laravel queue-envelope UUID may reenter `RUNNING` and renew that exact live lease, while another or expired token is rejected. `startUploading()` requires the same live lease and extends it. `sealReady()` locks `UPLOADING`, verifies lease and complete parent identity, appends the deterministic secret-free audit outbox intent, pins artifact fields, clears lease, and commits. Audit-intent failure leaves `UPLOADING`. `fail()` is terminal only for a non-retryable current attempt; exhausted retries use Task 11's narrow UUID/token-fenced failure store. Retryable attempts remain nonterminal under the same envelope lease.
**Step 4: Prove GREEN**
Run unit hydration/reflection/ownership locally and PostgreSQL behavior only in isolated CI. Expect exact seven-method lock, identity and canonical scope round-trip, aggregate+dispatch atomicity, lease fencing, audit-intent rollback, queued-run subject support, and exact run/export/snapshot scope equality.
**Step 5: Static checks**
Run PHP syntax checks for the migration, reader, and tests and PHPStan for the persistence files. The architecture allowlist must contain exactly one cross-run/export read-only subject reader and no inline resolver persistence.
**Step 6: Commit**
```bash
git add <the 13 exact paths listed above>
git commit -m "feat[reports]: сохранить полную идентичность экспортов"
```

### Task 8: Render CSV, XLSX, and PDF through bounded validated chunks

**Files:**

- Read only: `composer.json`, `composer.lock`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportLimits.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportArtifactStream.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfRenderBudget.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocument.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocumentBuilder.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocumentRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/CsvReportExportRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/XlsxReportExportRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/DompdfReportPdfDocumentRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/PdfReportExportRenderer.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/ReportExportRendererRegistry.php`
- Create: `resources/views/reports/exports/canonical-report-pdf.blade.php`
- Create: `tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php`
- Create: `tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php`
- Create: `tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php`
- Create: `tests/Unit/Reporting/Exports/ReportExportRendererRegistryTest.php`
- Create: `tests/Contract/Reporting/ReportExportParityContractTest.php`
- Create: `tests/Performance/Reporting/ReportExportStreamingBudgetTest.php`

**Interfaces consumed or produced:**

- Consume `ReportRowChunkReader`, Plan 1a `PublishedReportDefinition`, complete `ReportRunExportSource`, `ReportSnapshotRef`, `ReportWindowSort`, and `CreateReportExportData`.
- `ReportExportRenderer::render(ReportRunExportSource, CreateReportExportData, iterable, ReportArtifactStream): int`.
- The iterable contains only Plan 1b `ReportRowChunk` values.
- The returned integer is the exact rendered data-row count.
- `ReportExportRendererRegistry::resolve(PublishedReportDefinition $definition, CreateReportExportData $data): ReportExportRenderer` has exact `csv`, `xlsx`, and `pdf` entries and no default renderer.
- `ReportPdfDocumentRenderer::render(ReportPdfDocument $document, ReportPdfRenderBudget $budget): string` returns one bounded PDF byte string or throws a Plan 1a catalogued error.

`ReportPdfRenderBudget` is immutable and requires `maxDetailRows`, `maxPages`, `maxHtmlBytes`, `maxPdfBytes`, and `maxMemoryDeltaBytes`. `maxDetailRows` is `1..5000`; every other field is positive. Registry wiring pins a budget to exact definition hash plus renderer version for every published definition that advertises `pdf`. Missing or stale budget fails before cursor consumption; there is no global permissive fallback.

The existing dependency is reused unchanged: `composer.json` already declares `barryvdh/laravel-dompdf: ^3.1`; `composer.lock` pins `barryvdh/laravel-dompdf v3.1.1` and `dompdf/dompdf v3.1.4`. This task does not run Composer and does not modify either dependency file. `DompdfReportPdfDocumentRenderer` uses `Barryvdh\DomPDF\Facade\Pdf` through an adapter boundary; existing unbounded PDF services are not reused or copied.

**Step 1: Write failing tests**
Cover UTF-8 CSV, delimiter and formula-injection protection, deterministic headers, locale formatting, XLSX shared-string pressure, date and decimal fidelity, sheet limits, export row/column/byte limits, cancellation between chunks, checksum stability, and row/totals identity parity with the online result.

PDF tests additionally cover:

- exact registry resolution for `pdf`, `application/pdf` output and actual artifact checksum/size identity;
- headers, selected columns, normalized cell values, totals and source identity equal to the online result;
- renderer metadata pins result hash, snapshot/data classifications, sensitive/audit column sets, and complete official seal identity without emitting the seal signature into visible cells;
- `5000` detail rows accepted when the definition budget allows them, while row `5001` raises `REPORT_EXPORT_LIMIT_EXCEEDED` before Dompdf is invoked;
- exact `maxPages`, `maxHtmlBytes`, and `maxPdfBytes` boundaries accepted; one page or byte above each hard cap raises `REPORT_EXPORT_LIMIT_EXCEEDED` and writes zero artifact bytes;
- peak-memory delta is measured against `maxMemoryDeltaBytes`; no test or production path passes an arbitrary row array or domain model into Dompdf;
- missing definition-hash/renderer-version budget, stale renderer version and unregistered format fail closed before source reads;
- a Dompdf render failure becomes `REPORT_DEPENDENCY_FAILED`; it never leaks a library message;
- semantic parity is asserted on `ReportPdfDocument`; cross-run byte equality is not claimed because PDF metadata may differ, while each produced artifact still pins its own checksum.

The performance fixture contains 50,000 rows and uses chunk size 500. Assert:

- provider cursor is consumed lazily;
- no chunk exceeds 500 rows for this fixture;
- no more than four source reads are attributed to one chunk;
- peak memory delta stays at or below 128 MiB;
- no full result array is retained.

The dedicated PDF performance fixture contains exactly 5,000 detail rows in validated 500-row chunks and a locked definition budget. It asserts the configured HTML/PDF/page/memory caps, releases each source chunk after copying only scalar render cells, and never retains provider rows or a dataset beyond the bounded `ReportPdfDocument`.

**Step 2: Prove RED**
Run the three unit renderer tests, registry test and parity contract test. Run both performance fixtures in the dedicated CI performance job. Expected: failures until the PDF renderer, budget and registry wiring exist.
**Step 3: Implement bounded renderers**
The renderer registry accepts only normalized Plan 1a format values. CSV and XLSX process one validated chunk at a time and stream bytes to `ReportArtifactStream`. Runtime chunk size is configurable from 2,000 to 5,000; locked performance fixtures deliberately use 500 for reproducibility.

`PdfReportExportRenderer` also receives only `ReportRowChunk` values. `ReportPdfDocumentBuilder` validates scalar cells, selected columns and cumulative limits before appending, discards each source chunk immediately and stops at the immutable definition budget. It keeps only a bounded `ReportPdfDocument`, never the original iterable or domain models. `DompdfReportPdfDocumentRenderer` loads the dedicated Blade view, renders with the installed facade, checks the Dompdf canvas page count before obtaining output bytes, then checks output byte count and measured memory delta before the first `ReportArtifactStream::write()`. The output string is permitted only because `maxPdfBytes`, `maxHtmlBytes`, `maxPages`, `maxDetailRows` and `maxMemoryDeltaBytes` are all hard bounds.

Before each chunk, enforce cancellation, row, column, worksheet, elapsed-time, projected-byte and format budget limits. Limit failures use `REPORT_EXPORT_LIMIT_EXCEEDED`; Dompdf/library failures use `REPORT_DEPENDENCY_FAILED`.
**Step 4: Prove GREEN**
Run renderer, registry and parity tests locally. Run both budget fixtures in CI. Expect deterministic CSV/XLSX behavior, bounded PDF materialization, all-format semantic parity and exact artifact identity.
**Step 5: Static analysis**
Run PHPStan for renderer source and targeted tests.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Exports app/BusinessModules/Core/Reporting/Infrastructure/Exports tests/Unit/Reporting/Exports tests/Contract/Reporting/ReportExportParityContractTest.php tests/Performance/Reporting/ReportExportStreamingBudgetTest.php
git add resources/views/reports/exports/canonical-report-pdf.blade.php
git commit -m "feat[reports]: добавить ограниченные csv xlsx и pdf экспорты"
```

### Task 9: Complete FileService multipart storage and immutable artifact publication

**Files:**

- Create: `app/Services/Storage/DTO/MultipartUpload.php`, `app/Services/Storage/DTO/MultipartPart.php`
- Create: `app/Services/Storage/DTO/TemporaryFileLink.php`
- Read only: `app/Services/Storage/DTO/StoredFile.php`
- Modify: `app/Services/Storage/FileService.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/S3ReportArtifactStream.php`
- Create: `tests/Unit/Services/Storage/FileServiceMultipartTest.php`
- Create: `tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php`
- Create: `tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php`

**Interfaces consumed or produced:**
`FileService` exposes exactly these seven report-capable operations:
```php
public function startMultipart(
    string $organizationPath,
    string $mime,
    int $partSizeBytes,
    array $metadata,
): MultipartUpload;
public function uploadPart(
    MultipartUpload $upload,
    int $partNumber,
    string $bytes,
    string $checksumSha256,
): MultipartPart;
public function completeMultipart(
    MultipartUpload $upload,
    array $orderedParts,
    array $conditions,
): StoredFile;
public function abortMultipart(MultipartUpload $upload): void;
public function headVersion(string $organizationPath, string $versionId): StoredFile;
public function createTemporaryLink(
    string $organizationPath,
    string $versionId,
    int $ttlSeconds,
): TemporaryFileLink;
public function deleteVersion(string $organizationPath, string $versionId): void;
```
**DTO invariants:**

- `MultipartUpload`: organization-prefixed path, upload ID, MIME, fixed part size, immutable metadata.
- `MultipartPart`: number `1..10000`, non-empty ETag, positive byte size, lowercase 64-character SHA-256.
- `StoredFile`: exact path, non-empty version ID, ETag, positive size, checksum, MIME.
- `TemporaryFileLink`: URL, exact version ID, expiry instant; TTL is `1..300` seconds.
- Multipart part size is fixed at start, between 5 MiB and 64 MiB; only the final part may be smaller.
- `completeMultipart()` requires strictly ascending unique part numbers and matching upload identity.

**Step 1: Write failing unit and integration tests**
Cover all seven methods and this complete call graph:
```text
construct stream
  -> startMultipart
write bytes
  -> buffer to fixed part size
  -> uploadPart in ascending order
finish
  -> upload final non-empty remainder
  -> completeMultipart with immutable-create conditions
  -> headVersion for exact version and checksum verification
failure or cancellation before completion
  -> abortMultipart
download
  -> createTemporaryLink for the pinned version
retention
  -> deleteVersion for the pinned version
```
Also cover zero-byte rejection, duplicate or skipped part number, out-of-order completion, checksum mismatch, wrong organization prefix, wrong version ID, abort after upload failure, abort after renderer failure, idempotent abort, and link TTL above 300.
Integration tests against disposable S3-compatible storage cover:

- concurrent immutable publication and conditional 409/412 responses;
- a race where another worker completes the same key;
- comparison of the winner's exact version, checksum, size, and ETag;
- cleanup of the losing multipart upload;
- temporary link pinned to the winner version;
- deletion of only the named version.

**Step 2: Prove RED**
Run unit tests locally:
```bash
vendor/bin/phpunit tests/Unit/Services/Storage/FileServiceMultipartTest.php tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php
```
Run S3 integration only in isolated CI. Expected: red because the DTOs, seven methods, and sink do not exist.
**Step 3: Implement FileService and sink**
`S3ReportArtifactStream` starts exactly one multipart upload, incrementally hashes bytes, uploads fixed-size parts in order, and retains only the current buffer and part receipts. Its closed secret-free metadata contains organization ID, export ID/hash, run ID, result hash, snapshot ID/classification, data classification, and all four versions; no filters, rows, signature, key ID, actor data, or authorization facts. `finish()` completes with immutable-create preconditions, heads the exact returned version, compares checksum, size, MIME, and every metadata member, then returns `StoredFile`.
For a 409/412 race, reload the export record. Reuse only when it already pins a ready artifact with the same export hash and exact storage identity. Otherwise abort the local upload and raise a catalogued dependency failure. Never silently accept an unversioned object.
Any exception before verified completion calls `abortMultipart()` once. An exception after completion never deletes the completed version; store reconciliation owns that case.
**Step 4: Prove GREEN**
Run unit tests locally and integration tests in isolated CI. Expect full call-graph coverage and exact-version behavior.
**Step 5: Static analysis**
Run PHPStan for the four DTOs, `FileService`, sink, and unit tests.
**Step 6: Commit**
```bash
git add app/Services/Storage/DTO app/Services/Storage/FileService.php app/BusinessModules/Core/Reporting/Infrastructure/Exports/S3ReportArtifactStream.php tests/Unit/Services/Storage/FileServiceMultipartTest.php tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php
git commit -m "feat[reports]: добавить версионное multipart хранение экспортов"
```

### Task 10: Implement export, retry, cancel, status, and download action handlers

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportCoordinator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportExportHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportExportHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/RetryReportExportHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CancelReportExportHandler.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportDownloadLinkHandler.php`
- Create: `tests/Unit/Reporting/Actions/ReportExportHandlersTest.php`
- Create: `tests/Feature/Reporting/Actions/ReportDownloadLinkHandlerTest.php`

**Task file count:** 8 exact files.

**Interfaces consumed or produced:**

- Implement the exact Plan 1a export action ports.
- Consume Plan 1a `CreateReportExportData`, `CreateReportDownloadLinkData`, `IdempotencyKey`, `ReportExport`, and `ReportDownloadLink`.
- `ReportExportCoordinator::create(ReportExecutionContext, ReportRunExportSource, CreateReportExportData, IdempotencyKey): ReportExport`.
- `CreateReportExportHandler::handle(ReportExecutionContext $context, string $runId, CreateReportExportData $data, IdempotencyKey $key): ReportExport`.
- `GetReportExportHandler::handle(ReportExecutionContext $context, string $exportId): ReportExport`.
- `RetryReportExportHandler::handle(ReportExecutionContext $context, string $exportId, IdempotencyKey $key): ReportExport`.
- `CancelReportExportHandler::handle(ReportExecutionContext $context, string $exportId): ReportExport`.
- `CreateReportDownloadLinkHandler::handle(ReportExecutionContext $context, CreateReportDownloadLinkData $data): ReportDownloadLink`.
- Return the Plan 1a `ReportDownloadLink` directly.
- The architecture test reflects these exact public method names, parameter order/types, and return types; handlers expose no second public workflow method.

**Step 1: Write failing tests**
Cover:

- export creation only from a ready unexpired run;
- export identity is derived from complete run/result/snapshot/classification/seal identity, all four versions, normalized format, columns, sort, locale, and timezone;
- same organization + different actor + same Plan 1a idempotency object and canonical input reuse one export;
- same organization + different actor + same key with changed normalized input raises `REPORT_IDEMPOTENCY_CONFLICT`;
- another organization may independently use the same key;
- `pdf` resolves the exact definition-hash/renderer-version budget, creates an export whose identity includes format `pdf`, and dispatches through the PDF registry entry;
- missing/stale PDF budget or registry entry fails before persistence/dispatch and cannot leave an unrenderable queued export;
- retry receives and forwards the explicit Plan 1a `IdempotencyKey`, reuses on equal canonical source body, and creates a child from `FAILED` or `CANCELLED` without resetting the source; an `EXPIRED` export is retryable only while its parent run remains `READY` and `clock.now() < run.expiresAt`; `READY` requires a separate refresh;
- retry of an `EXPIRED` export whose parent run is expired or no longer `READY` returns `REPORT_SNAPSHOT_EXPIRED` before renderer resolution, S3, persistence, or outbox insertion; the user must explicitly retry the parent run with its own idempotency key and then create a new export, and no handler automatically chains those two operations;
- PDF retry creates a new export with the same immutable run/definition/query/source/renderer identity, resolves PDF again and never falls through to CSV/XLSX;
- cancellation races are compare-and-set;
- get authorizes `VIEW`;
- create/retry/cancel authorize `EXPORT`;
- temporary link authorizes `DOWNLOAD`;
- selected sensitive columns additionally authorize `VIEW_SENSITIVE`; selected audit columns additionally authorize `VIEW_AUDIT`;
- link uses the exact stored version and TTL at most 300 seconds;
- status get may return `EXPIRED`, while export creation from an expired run and link creation for an expired export use exact 410 semantics before renderer, S3, or URL generation;
- actor/scope/definition/saved-view/classification access is reloaded immediately before persistence and immediately before URL generation;
- context scope is checked before existence is disclosed.

**Step 2: Prove RED**
Run the unit test locally. Run the feature test only in isolated CI. Expected: failures because handlers and coordinator do not exist.
**Step 3: Implement the minimum**
The coordinator obtains `ReportRunExportSource`, resolves the same published definition/binding, reauthorizes current actor/scope and exact typed selected-column classification, verifies every immutable hash/classification/seal/version, resolves renderer plus definition budget before persistence, and calls the exact seven-method store's `createOrReuse()` with the explicit Plan 1a key. The store-created outbox intent is the only dispatch source. The persisted requester actor does not participate in idempotency identity. Retry first loads both source export and parent run under the current organization, then enforces the parent-run rule above; it never extends the parent TTL or silently creates a replacement run.
The download handler loads current export and complete parent source, rejects expired/not-ready state, reauthorizes current actor/scope/definition plus sensitive/audit classification, then and only then calls `createTemporaryLink(path, versionId, ttl)`. Revocation never reaches S3 URL generation.
All expected errors use `ReportContractException::fromCode()`. No handler constructs HTTP responses.
**Step 4: Prove GREEN**
Run targeted unit tests and the CI feature test. Expect access, identity, idempotency, lifecycle, and link assertions to pass.
**Step 5: Static analysis**
Run PHPStan for coordinator and handler files.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/Application/Exports/ReportExportCoordinator.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportExportHandler.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportExportHandler.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/RetryReportExportHandler.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CancelReportExportHandler.php app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportDownloadLinkHandler.php tests/Unit/Reporting/Actions/ReportExportHandlersTest.php tests/Feature/Reporting/Actions/ReportDownloadLinkHandlerTest.php
git commit -m "feat[reports]: реализовать защищенные операции экспорта"
```
### Task 11: Execute exports with retry-safe multipart cleanup

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/GenerateReportExportJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportExportDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelReportExportExecutionContextRehydrator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportAsyncContextSeedReader.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportLeaseRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportAttemptLifecycleStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportAsyncContextSeedReader.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportLeaseRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportAttemptLifecycleStore.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionWatchdog.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportAttemptFinalizer.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReportArtifactVersionInventory.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportCompletedArtifactRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportCompletedArtifactRecoveryStore.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/S3ReportArtifactVersionInventory.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportExportExecutionLeasesCommand.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Listeners/FinalizeFailedReportExportAttempt.php`
- Create: `tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php`
- Create: `tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportCompletedArtifactRecoveryStoreContractTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportExportAsyncContextSeedReaderContractTest.php`
- Create: `tests/Unit/Reporting/Execution/ReportExportLeaseRecoveryStoreContractTest.php`
- Create: `tests/Unit/Reporting/Exports/ReportExportAttemptFinalizerTest.php`
- Create: `tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php`
- Create: `tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportCompletedArtifactRecoveryStoreTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportExportAsyncContextSeedReaderTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php`
- Create: `tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php`

**Task file count:** 31 exact files.

**Interfaces consumed or produced:**

- Implement `ReportExportDispatcher`.
- Consume `ReportExportStore`, `ReportExportExecutionContextRehydrator`, Plan 1a `ReportDefinitionRegistry` and `ReportDefinitionBindingMap`, `ReportRowChunkReader`, renderer registry, and `S3ReportArtifactStream`.
- Consume Plan 1a run, export, snapshot, query hash, and normalized export data.
- `ReportExportExecutionService::execute(string $exportId, string $leaseToken): void`.
- `LaravelReportExportDispatcher::dispatch(string $exportId): void`.
- `LaravelReportExportExecutionContextRehydrator::forExport(string $exportId): ReportExecutionContext`.
- `ReportExportAsyncContextSeedReader::forExport(string $exportId): ReportAsyncContextSeed`.
- `ReportExportLeaseRecoveryStore::due(int $limit, DateTimeImmutable $occurredAt): array`, returning only `ReportExpiredExecutionLease` values.
- `ReportExportLeaseRecoveryStore::requeue(ReportExpiredExecutionLease $lease, DateTimeImmutable $occurredAt): bool`.
- `ReportExportAttemptLifecycleStore::claimOrRenew(string $exportId, string $envelopeUuid, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): bool`.
- `ReportExportAttemptLifecycleStore::failLeased(string $exportId, string $envelopeUuid, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): bool`.
- `ReportExportAttemptFinalizer::finalize(string $exportId, string $leaseToken, ?Throwable $failure, DateTimeImmutable $occurredAt): bool`.
- `GenerateReportExportJob::__construct(public readonly string $exportId)`.
- `GenerateReportExportJob::handle(ReportExportExecutionService $service): void`.
- `FinalizeFailedReportExportAttempt::__invoke(Illuminate\Queue\Events\JobFailed $event): void`.
- `ReportExportExecutionWatchdog::reclaim(int $limit, DateTimeImmutable $occurredAt): ReportExecutionWatchdogSummary`.
- `ReportArtifactVersionInventory::forExport(int $organizationId, string $exportId): iterable`.
- `S3ReportArtifactVersionInventory::forExport(int $organizationId, string $exportId): iterable`.
- `ReconcileCompletedReportArtifacts::reconcile(ReportExecutionContext $context, string $exportId, DateTimeImmutable $occurredAt): ReportCompletedArtifactReconciliationResult`.
- `ReportCompletedArtifactRecoveryStore::claimExpiredUpload(ReportExecutionContext $context, string $exportId, string $newLeaseToken, DateTimeImmutable $newLeaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport`.
- `EloquentReportCompletedArtifactRecoveryStore::claimExpiredUpload(ReportExecutionContext $context, string $exportId, string $newLeaseToken, DateTimeImmutable $newLeaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport`.
- `ReconcileReportExportExecutionLeasesCommand::handle(): int`.

`EloquentReportCompletedArtifactRecoveryStore` is the sole narrow exception to the seven-method execution store. It directly owns only `ReportExportRecord` recovery CAS: lock the organization/export row, require `UPLOADING`, require non-null `execution_lease_expires_at <= occurredAt`, and update token/expiry/heartbeat with the same status predicate. It cannot create, read general execution input, fail, cancel, or seal. It is allowlisted by the Task 13 architecture test; any other direct `ReportExportRecord` writer is forbidden.

`EloquentReportExportAsyncContextSeedReader` is the sole narrow export-record reader for async bootstrap and returns the shared closed Task 5 seed only. The rehydrator consumes that port and never touches `ReportExportRecord` or calls the context-bound export store before current context exists. `EloquentReportExportLeaseRecoveryStore` is the sole lost-export-lease reset boundary: `due()` keyset-scans expired `RUNNING|UPLOADING` leases and returns closed lease candidates; `requeue()` transactionally locks and requires the same organization/export/status/token/expired timestamp, resets to `QUEUED`, clears lease fields, and inserts the deterministic export recovery transport intent. Stale candidates return `false`; intent failure rolls back. The watchdog consumes only this port. Completed-version recovery remains separate: the completed-artifact recovery store may renew an expired `UPLOADING` lease but may not reset or enqueue.

`EloquentReportExportAttemptLifecycleStore` is the symmetric authority-free export boundary. `claimOrRenew(exportId, envelopeUuid, leaseExpiresAt, at)` row-locks and transitions `QUEUED -> RUNNING` or renews the same live token in `RUNNING|UPLOADING`; a different/expired token or terminal state returns `false`, and only the first transition emits its deterministic start audit. `failLeased(exportId, envelopeUuid, code, at)` requires `RUNNING|UPLOADING`, the exact live token, derives organization/requester/subject/audit lineage only from the stored export, writes the safe code, atomically transitions to `FAILED`, clears the lease, and appends one deterministic audit intent. Audit failure rolls back; no current actor, authorization context, provider, renderer, or storage call is permitted. The exact `redis_reports`/`reports` `JobFailed` listener accepts only the resolved `GenerateReportExportJob` name, canonical event UUID, and ID-only export payload. Its finalizer calls only leased failure; replay and late old events are fenced. The job itself has no `failed()` method.

**Step 1: Write failing tests**
Cover:

- job payload contains only export ID;
- context, run, export, published definition, matching Plan 1a binding, and providers are reloaded;
- current actor/scope/classification access is reauthorized before provider cursor creation, before renderer invocation, and again before opening/completing S3 multipart;
- duplicate delivery is a no-op for `READY`;
- cancelled or expired inputs never open multipart upload;
- `QUEUED -> RUNNING -> UPLOADING -> READY` is compare-and-set;
- every execution write is fenced by a live lease, duplicate delivery loses the claim, and a dead worker's expired lease is reset/requeued through one watchdog transaction plus unique recovery outbox intent;
- async seed output is closed and contains no persisted authorization facts, while export lease recovery proves due-scan, status/token/expiry CAS, atomic reset plus intent, stale no-op, and concurrent watchdog fencing in PostgreSQL;
- renderer consumes only chunks from `ReportRowChunkReader`;
- `csv`, `xlsx`, and `pdf` select exactly their registered renderer; PDF uses the exact definition-hash/renderer-version budget;
- PDF pins format, renderer version, MIME `application/pdf`, rendered row count, checksum and size to the same run/snapshot identity;
- PDF row/page/HTML/output/memory budget failures persist `REPORT_EXPORT_LIMIT_EXCEEDED`, abort multipart once and never reach `READY`;
- Dompdf failure persists `REPORT_DEPENDENCY_FAILED`, aborts multipart once and exposes no library message;
- the job calls authority-free `claimOrRenew()` before current authorization, renderer, provider, or storage access; non-retryable current-authorization failure uses the exact leased CAS, while retryable/unexpected failure rethrows under that lease;
- a retryable attempt failure remains nonterminal, records attempt failure, and reaches another delivery without reusing an unverified artifact; only the exact leased failure boundary or `JobFailed` finalizer writes `FAILED`;
- malformed/foreign failed events, token/envelope mismatch, expired lease, watchdog race, audit failure, and already-terminal state cannot create an exhausted-attempt mutation;
- reflection locks the export lifecycle store to exact `claimOrRenew`/`failLeased`; PostgreSQL covers first claim, same-token renewal, pre-auth leased failure, safe persisted lineage, no current authority lookup, different/stale/status/token/expiry matrices, audit rollback, replay, and lifecycle/watchdog races;
- expired lease -> watchdog requeue -> late old `JobFailed` cannot fail `QUEUED` or a new delivery; the new token and state survive unchanged;
- snapshot/query/source/version drift aborts before ready;
- failure before completion aborts multipart;
- cancellation between chunks aborts multipart;
- completion followed by store failure keeps the exact version for reconciliation;
- audit failure rolls back ready and leaves a reconcilable completed version;
- row count, checksum, size, MIME, ETag, path, and version are pinned;
- queue retry does not create two ready artifacts.

**Step 2: Prove RED**
Run the job unit test locally and integration test in isolated CI. Expected: red until execution service and job exist.
**Step 3: Implement execution flow**
The service:

1. reloads current execution context;
2. loads export plus complete `ReportRunExportSource` in organization scope;
3. verifies ready, unexpired run identity and reauthorizes current actor/scope/definition plus selected-column sensitive/audit classification;
4. claims `QUEUED -> RUNNING` with the stable Laravel queue-envelope UUID and a 960-second lease; retry of the same envelope renews it;
5. opens the validated Plan 1a cursor iterable through `ReportRowChunkReader`;
6. resolves the renderer from the normalized format and exact published definition; CSV/XLSX stream chunks, while PDF builds only its hard-capped document and writes output only after row/page/HTML/PDF-byte/memory checks;
7. reauthorizes again, transitions to `UPLOADING` and extends the fenced lease before multipart completion;
8. reauthorizes again before completion, then verifies the exact stored version;
9. calls audited `sealReady()`.

Validate the envelope UUID and call authority-free `claimOrRenew(exportId, uuid, now+960, now)` before current authorization, provider, renderer, or storage access. A non-retryable catalogued authorization/renderer/limit error calls `failLeased()`; incomplete multipart is aborted when one exists. Retryable source/storage/library or unexpected failure aborts incomplete multipart, records attempt telemetry, leaves the aggregate nonterminal under its expiring lease, and rethrows. The job has no `failed()` method; the export-only Laravel `JobFailed` listener/finalizer calls only leased CAS without current-user authorization. Exact job policy is `tries=5`, backoff `[30,120,300,900]`, timeout `900`, `failOnTimeout=true`, on Task 4d's locked runtime. The watchdog obtains candidates only through `ReportExportLeaseRecoveryStore::due()` and invokes its atomic `requeue()`; row-lock/status/token/expiry fencing permits exactly one winner between watchdog recovery and leased terminalization, and late old events cannot affect a requeued/new generation.

After successful S3 completion but before ready commit, never delete the version. The inventory adapter lists only the exact export prefix and paginates closed metadata. `ReconcileCompletedReportArtifacts` reauthorizes and rereads complete identity, accepts exactly one matching version, generates a new UUID lease, calls `claimExpiredUpload()`, then calls the seven-method store's `sealReady()` with that new token. Recovery-store CAS races with watchdog/job: exactly one status-and-expired-lease predicate wins; loser performs no S3 mutation or ready transition. Claim rollback preserves the old lease. Multiple matches, metadata drift, truncation, live lease, authorization failure, or CAS loss is fail-hard/observable; unmatched versions are deleted only after grace.
**Step 4: Prove GREEN**
Run unit and CI integration tests. Expect retry, cancellation, cleanup, audit, and exact-version cases to pass.
**Step 5: Static analysis**
Run PHPStan for job, dispatcher, execution service, and unit tests.
**Step 6: Commit**
```bash
git add <the 31 exact paths listed above>
# Before commit, require the sorted staged path set to equal the 31-path Files manifest exactly; fail on every missing or extra path.
git commit -m "feat[reports]: выполнить экспорт с арендой и сверкой"
```
After commit, require `git diff-tree --no-commit-id --name-only -r HEAD` to equal the same 31-path manifest and `git status --short -- <those 31 paths>` to be empty.

### Task 12: Add retention and the immutable Core audit-intent consumer

**Dependency:** Task 4b owns the final exact ten-method run-store port and the `report_runs` record. Retention must not add an eleventh method or a second run repository.

**Files:**

- Create: `app/BusinessModules/Core/Reporting/Application/Retention/ExpireReportsService.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Retention/DeleteExpiredReportArtifactsService.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/AppendReportAuditEventJob.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/LaravelReportAuditDispatcher.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/CoreReportAuditIntentConsumer.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Audit/ReportAuditOutboxScheduler.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/DeliverReportAuditIntentsCommand.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/ExpireReportsCommand.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Console/DeleteExpiredReportArtifactsCommand.php`
- Modify: `routes/console.php`
- Create: `tests/Unit/Reporting/Retention/ExpireReportsServiceTest.php`
- Create: `tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php`
- Create: `tests/Integration/Reporting/Audit/CoreReportAuditIntentConsumerTest.php`
- Create: `tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php`
- Create: `tests/Unit/Reporting/Audit/ReportAuditOutboxSchedulerTest.php`

**Task file count:** 15 exact files.

**Interfaces consumed or produced:**

- Implement Task 4b `ReportAuditDispatcher` and consume its exact `ReportAuditIntentStore`; `OutboxReportTransitionAudit` already implements the Task 2 port. Do not add another audit table/store and do not call Core audit inside an aggregate transaction.
- Consume `FileService::deleteVersion()` and exact stored artifact identity.
- Services use keyset batches and return counts for scanned, transitioned, deleted, skipped, and failed records.
- `LaravelReportAuditDispatcher::dispatch(string $intentId): void`.
- `CoreReportAuditIntentConsumer::append(ReportAuditIntent $intent): void`.
- `ReportAuditOutboxScheduler::dispatchDue(int $limit, DateTimeImmutable $occurredAt): int`.
- `ExpireReportsService::expire(int $limit, DateTimeImmutable $occurredAt): array`, with the exact closed return shape `array{scanned:int,transitioned:int,deleted:int,skipped:int,failed:int}`.
- `DeleteExpiredReportArtifactsService::delete(int $limit, DateTimeImmutable $occurredAt): array`, with the same exact closed return shape.
- `AppendReportAuditEventJob::__construct(public readonly string $intentId)`.
- `AppendReportAuditEventJob::handle(CoreReportAuditIntentConsumer $consumer, ReportAuditIntentStore $store): void`.
- `AppendReportAuditEventJob::failed(?Throwable $throwable): void`.
- `DeliverReportAuditIntentsCommand::handle(): int`.
- `ExpireReportsCommand::handle(): int`.
- `DeleteExpiredReportArtifactsCommand::handle(): int`.
- `ReportAuditOutboxScheduler` calls `dueIds(int $limit, DateTimeImmutable $now): array` and `ReportAuditDispatcher::dispatch()` for each ID without mutating audit-intent state. `DeliverReportAuditIntentsCommand` is only its thin console adapter.
- `AppendReportAuditEventJob` serializes only `intentId`; it claims with the queue-envelope UUID, rereads through `loadLeased()`, calls `append()`, then `acknowledge()`. Failure calls lease-fenced `failDelivery()` with the shared exact backoff; duplicate/no-longer-due claims are no-ops.
- `ExpireReportsService` owns the retention persistence workflow directly over `ReportRunRecord` and the Task 7 export record; this bounded service is not exposed as an execution store or read repository. For each run it keyset-selects `(expires_at,id)`, captures one `$occurredAt = clock.now()`, opens a transaction, locks the exact row, requires `READY` and `expires_at <= $occurredAt`, validates the retained sealed identity, appends deterministic event `reports:run:{runId}:expired:{expiresAtUtcRfc3339Seconds}` through `ReportTransitionAudit`, then compare-and-set updates `status='EXPIRED'`, `expired_at=$occurredAt`, and `updated_at=$occurredAt` without changing `expires_at` or any sealed column. A replay observes `EXPIRED` and performs no append/update. This is how Task 12 expires runs without silently extending `ReportRunStore`.

**Step 1: Write failing tests**
Cover:

- only eligible ready runs and exports become `EXPIRED`;
- retention is organization-safe and keyset-paginated;
- download becomes unavailable immediately after expiry;
- physical deletion has a separate grace period;
- only the pinned object version is deleted;
- failed deletion remains retryable and does not erase storage identity;
- repeated expiry and deletion are idempotent;
- run expiry retains `source_hash`, snapshot/result identity, versions and `ready_at`, sets `expired_at` exactly once, and never changes `expires_at`;
- retention introduces no eleventh `ReportRunStore` method and no duplicate run-store/repository abstraction;
- audit intent creation is immutable and deduplicated by deterministic event key in the caller transaction;
- audit delivery job carries only intent ID, appends idempotently by event key, and acknowledges the audit intent only after Core success;
- Redis acceptance does not alter audit status; Core outage retries/dead-letters through `ReportAuditIntentStore` without losing payload;
- transport publisher rejects audit intents/topics, while the audit scheduler rejects run/export aggregate intents/topics; neither can mark the other lifecycle published, delivered, failed, or dead-lettered;
- crash after Redis acceptance and crash after Core append before ack both recover deterministically;
- audit records actor, organization, hashes, versions, row count, artifact identity, and occurrence time without sensitive row values.

**Step 2: Prove RED**
Run retention unit tests locally and audit integration in isolated CI. Expected: red until services and adapter exist.
**Step 3: Implement the minimum**
Expire state in bounded keyset batches. Delete physical versions only after grace expiry and only through `deleteVersion(path, versionId)`. Record successful deletion without blanking the historical version identity.
`AppendReportAuditEventJob` carries only intent ID. Its exact lease-fenced store lifecycle makes Core append precede `acknowledge()`. `CoreReportAuditIntentConsumer` uses `event_key` as immutable Core deduplication key and rejects unknown payload members. Core failures return the same audit intent to pending or dead-letter through `failDelivery()`; transport publisher state is unrelated. Crash after append before ack replays the idempotent event and then acknowledges.
Commands are thin adapters that call services, report counts, and return nonzero only for terminal batch failure. Register schedules in the existing console bootstrap without running them.
**Step 4: Prove GREEN**
Run targeted unit tests and CI audit integration. Expect retention, exact-version deletion, deduplication, and rollback assertions to pass.
**Step 5: Static analysis**
Run PHPStan for retention, audit, command, and unit-test files.
**Step 6: Commit**
```bash
git add <the 15 exact paths listed above>
# Before commit, require the sorted staged path set to equal the 15-path Files manifest exactly; fail on every missing or extra path.
git commit -m "feat[reports]: добавить хранение и доставку аудита"
```
After commit, require `git diff-tree --no-commit-id --name-only -r HEAD` to equal the same 15-path manifest and `git status --short -- <those 15 paths>` to be empty.

### Task 12a: Consolidate the bounded PostgreSQL race harness

**Dependency:** Tasks 5 and 12 are committed. The Task 4d private race helper remains byte-for-byte unchanged through Tasks 4e–12 so the authorization and execution cutovers do not rewrite historical concurrency evidence.

**Files (exact 2-path commit manifest):**

- Modify: `tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php`
- Modify: `tests/Support/Reporting/PostgresProcessRaceHarness.php`

**Task file count:** 2 exact files.

Move only the Task 4d private process/barrier orchestration from `EloquentReportRunStoreTest` into the Task 5 shared `PostgresProcessRaceHarness`, switch that test to the shared API, and delete the now-duplicate private methods. Preserve every worker command, barrier ordering, timeout, assertion, PostgreSQL connection, cleanup path, and race outcome at the fixture/assertion level. Do not change production code, database schema, runtime behavior, or add a second abstraction. A static contract proves the test has no remaining private process harness and the shared helper is the sole reporting process-race harness.

Run only the DB-free syntax/static contract locally; write but do not execute the PostgreSQL race under the local DB prohibition. Commit the exact two paths as `test[reports]: объединить PostgreSQL race harness`.

### Task 13: Wire handlers and add run/export-only observability

**Files:**

- Create: `app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php`
- Create: `app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php`
- Create: `config/reporting_execution.php`
- Modify: `bootstrap/providers.php`
- Modify: `lang/ru/reports.php`
- Create: `tests/Architecture/Reporting/ReportingExecutionBindingsTest.php`
- Create: `tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php`
- Create: `tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php`

**Task file count:** 8 exact files.

**Interfaces consumed or produced:**

- Bind the Plan 1a run, rows, drill-down, export, and download action ports to Plan 1b handlers; leave `GetReportCatalogAction` unbound for Plan 1c exactly as Plan 1a requires.
- Bind the Plan 1b run/export stores, both closed async-context seed readers, both narrow lease-recovery stores, transport-intent store/`ReportDispatchIntentPublisher`/reconciler, separate audit-intent store/`ReportAuditOutboxScheduler`/consumer, ID-only run/export/audit dispatchers, both current-fact context rehydrators, audit writer, execution watchdogs, completed-artifact recovery store, exact CSV/XLSX/PDF renderer registry, fail-closed PDF budget registry, clock, and telemetry. Transport publication and Core audit acknowledgement remain disjoint lifecycles; binding tests prove the transport publisher rejects audit purpose and the audit scheduler rejects run/export purpose.
- Bind both narrow attempt-lifecycle stores/finalizers and register the exact run/export `JobFailed` listeners once; listener routing is restricted to `redis_reports`/`reports` and the exact resolved job names.
- Bind `CurrentReportScopeAuthorizer` exactly to `LaravelCurrentReportScopeAuthorizer` and `CurrentReportAbacEvaluator` exactly to `LaravelCurrentReportAbacEvaluator`. Bind one `LaravelReportScopedResourceAuthorizerRegistry` from the tagged exact-kind adapters: an empty adapter set must resolve and authorize only an empty resource scope; every non-empty resource kind must resolve one and only one exact-kind adapter, with no wildcard/default/generic binding.
- Bind the Task 4e `ReportHttpAuthorizationTargetResolver`, its single narrow `ReportAuthorizationSubjectReader` bound exactly to `EloquentReportAuthorizationSubjectReader`, and `ReportHttpAuthorizationOrchestrator` explicitly. The reader implementation is assembled only after run and export persistence exist and is the only HTTP target source; no controller/action-specific alternate resolver, nullable binding, service locator, or current-definition fallback may resolve.
- Consume but do not bind Plan 1a `ReportDefinitionRegistry` or `ReportDefinitionBindingMap`; Plan 1c supplies them, owns catalog publication/published activation, and Plan 1b never performs that transition.
- Consume Plan 1a `ReportErrorCatalog` and `ReportContractException`.
- Telemetry covers run and export families only.
- `ReportingExecutionServiceProvider::register(): void`.
- `ReportingExecutionServiceProvider::boot(): void`.
- Implement the final exact ten-method Task 5-owned `ReportExecutionTelemetry` port; do not redefine or expand it here.
- The binding architecture test reflects the Task 5 telemetry port and all exact handler/store signatures from Tasks 4b and 10–12. Direct record access is permitted only inside these named persistence boundaries: `EloquentReportAuthorizationSubjectReader`, `EloquentReportRunStore`, `EloquentReportRunAsyncContextSeedReader`, `EloquentReportRunLeaseRecoveryStore`, `EloquentReportRunAttemptLifecycleStore`, `EloquentReportDispatchIntentStore`, `EloquentReportAuditIntentStore`, `EloquentReportExportStore`, `EloquentReportExportAsyncContextSeedReader`, `EloquentReportExportLeaseRecoveryStore`, `EloquentReportExportAttemptLifecycleStore`, and `EloquentReportCompletedArtifactRecoveryStore`, plus the two bounded retention services `ExpireReportsService` and `DeleteExpiredReportArtifactsService`. `EloquentReportDispatchIntentStore` may cross-reference `ReportRunRecord` only for the Task 4b attempt-12 `RUN|MATERIALIZE_RUN` queued-failure CAS and `ReportExportRecord` only for the Task 7 symmetric `EXPORT|GENERATE_EXPORT` branch; AST tests reject every other cross-record method, status, or write. AST rules lock each narrow reader to closed seed construction only, each lease-recovery implementation to `due/requeue` and atomic recovery-intent insertion only, each attempt-lifecycle store to authority-free same-envelope claim/renew and UUID/token-fenced terminalization plus audit only, and completed-artifact recovery to expired-`UPLOADING` lease renewal only. Rehydrators, jobs, listeners, watchdogs, coordinators, handlers, and consumers may not reference persistence records. Every other production reference to `ReportRunRecord`, `ReportExportRecord`, `ReportDispatchIntentRecord`, or `ReportAuditIntentRecord`, and every second generic store/repository abstraction for those records, fails the architecture suite.

**Step 1: Write failing tests**
Register Plan 1a registry/map fakes, then assert every Plan 1b-owned action port resolves to the expected handler, `GetReportCatalogAction` remains outside this provider, and every execution dependency resolves once. Assert the renderer registry has exactly `csv`, `xlsx`, and `pdf`, the PDF entry resolves only with a matching definition-hash/renderer-version budget, and no fallback renderer or budget exists. Assert safe translations exist through `trans_message('reports.errors.<key>')`.
Boot the provider in an isolated container and assert the concrete `CurrentReportScopeAuthorizer`, `CurrentReportAbacEvaluator`, and empty resource registry resolve exactly once without request/auth globals. Repeat with two tagged distinct-kind adapters and prove exact dispatch. Duplicate kind, wildcard/generic kind, missing adapter for a non-empty scope, or an alternate binding must fail provider boot or the static binding contract.
Resolve the HTTP orchestrator graph and assert exact concrete resolver/`EloquentReportAuthorizationSubjectReader`/factory/authorizer identities, one shared transaction coordinator, and all twelve named controller methods. Reflection locks the reader to the Task 4e interface and the run/export record read allowlist, while persisted operation bindings require `authorizeExact(subject.scope, target)`. Boot must fail for a missing/duplicate reader or resolver and architecture checks reject inline persistence, container/facade lookup, cyclic store injection, and fallback construction.
Assert `config/reporting_execution.php` has closed run settings `runs.ttl_seconds` and `runs.poll_after_ms` within Task 3 bounds, and the provider passes those exact scalars plus the Task 4b outbox dependency to the amended constructor locked by reflection. Assert both rehydrators depend on their seed-reader port rather than a record or context-bound store, and both watchdogs depend on their lease-recovery port; reflection and AST checks reject service-locator or direct-model bypasses.
Also lock `dispatch.batch_size=100`, `dispatch.lease_seconds=60`, `dispatch.max_attempts=12`, `execution.lease_seconds=960`, `execution.watchdog_batch_size=100`, and `artifacts.reconciliation_grace_seconds=3600`; missing, extra, non-integer, or out-of-range values fail provider boot. Assert these execution values remain linked to Task 4d's effective `redis_reports` queue/Horizon runtime and never override its `timeout=900`, Horizon timeout `960`, or Redis `retry_after=1200`. Trusted seal public keys live in `reporting_execution.trusted_seal_keys` and use the exact Task 4c closed map `{key_id: {public_key: canonical unpadded base64url 32-byte Ed25519 key, revoked: bool}}`; list roots, missing/extra keys, padded/noncanonical/wrong-length values, and private material fail provider boot before verifier construction.
Lock these low-cardinality telemetry families:
```text
reports_run_total
reports_run_duration_seconds
reports_run_failed_total
reports_export_total
reports_export_duration_seconds
reports_export_failed_total
reports_export_bytes
reports_export_rows
reports_export_multipart_abort_total
reports_audit_transition_failed_total
reports_dispatch_intent_total
reports_dispatch_publish_failed_total
reports_dispatch_lease_reclaimed_total
reports_dispatch_dead_letter_total
reports_execution_attempt_failed_total
reports_execution_lease_reclaimed_total
reports_dispatch_oldest_pending_seconds
```
Allowed labels are report code, status, format, queue class, error code, intent type/topic/outcome, and bounded duration/size/age buckets. Actor ID, organization ID, run ID, export ID, intent/event key, lease token, object path, filter value, cursor, signature/key ID, and raw exception text are forbidden metric labels.
Test the complete Plan 1a retryability table, including retryable snapshot-not-ready, export-not-ready, and internal-error cases. Assert technical exception messages never reach user responses.
**Step 2: Prove RED**
Run the three targeted test files. Expected: failures because bindings, telemetry, config, and translations do not exist.
**Step 3: Implement wiring and telemetry**
Register the execution provider after the Plan 1a contracts provider without binding, assembling, activating, or publishing the Plan 1c registry/map. Keep error classification entirely in `ReportErrorCatalog`; execution code supplies a code and safe structured context only.
`config/reporting_execution.php` is the only configuration owner for run/export TTL/poll, transport dispatch, audit delivery, execution lease/watchdog, reconciliation grace, and trusted seal-key scalars. Bind the amended run store with its exact Task 4b transport-intent dependency and audit writer, bind the separate audit-intent lifecycle, and reject missing, extra, non-integer, or out-of-range values during provider boot. No persistence file reads `config()` directly.
Log run/export lifecycle with hashes and IDs as structured log context, not metric labels. Emit queue age, intent publish outcomes, reclaimed leases, dead letters, execution attempts, duration, rows, bytes, multipart aborts, and audit delivery failures. Define fail-hard alert thresholds for oldest pending age, any audit dead letter, sustained dispatch failure, repeated lease recovery, execution error ratio, duration regression, and storage abort ratio.
Do not add schedule or subscription events, metrics, labels, dashboards, or alerts in this task.
**Step 4: Prove GREEN**
Run binding, telemetry, and error-mapping tests. Expect exact bindings, safe labels, correct retryability, and translated user messages.
**Step 5: Static analysis**
Run PHPStan for provider, telemetry, configuration consumers, and targeted tests.
**Step 6: Commit**
```bash
git add app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php app/BusinessModules/Core/Reporting/Infrastructure/Telemetry config/reporting_execution.php bootstrap/providers.php lang/ru/reports.php tests/Architecture/Reporting/ReportingExecutionBindingsTest.php tests/Unit/Reporting/Telemetry tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php
git commit -m "feat[reports]: подключить надежное исполнение отчетов"
```
### Task 14: Lock executable evidence and the Plans 1c, 2, 3, and 4 handoff

**Files:**

- Create: `docs/reports/contracts/plan-1b-evidence.schema.json`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php`
- Create: `app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php`
- Create: `tests/Unit/Reporting/Evidence/PlanOneBEvidenceValidatorTest.php`
- Create: `tests/Unit/Reporting/Evidence/PlanOneBEvidenceBuilderTest.php`
- Create: `tests/Fixtures/Reporting/plan-1b-completion.valid.json`
- Create: `tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php`
- Create: `tests/Contract/Reporting/PlanOneBEndToEndContractTest.php`
- Modify: `.gitignore`
- Generated after successful CI only; never stage: `build/reports/plan-1b-completion.json`
- Modify: `docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md`
**Interfaces consumed or produced:**

- `PlanOneBEvidenceValidator::validate(array $document): void`.
- `PlanOneBEvidenceBuilder::build(PlanOneACompletionRef, array $checks, DateTimeImmutable): array`.
- Plans 2 and 3 implement only Plan 1a provider ports and supply candidate bindings to Plan 1c through the Plan 1a lifecycle; they do not register or publish runtime bindings.
- Plan 1c supplies the published registry/map and owns every publication transition; Plan 1b only records that external dependency.
- Plans 1c and 4 consume the post-CI artifact location and SHA-256, never a committed `build/` file; Plan 4 owns evidence verification and deployment rollout only.
**Step 1: Write failing validator tests**
The validator test invokes PHP code directly; the JSON Schema is not treated as executable by itself.
Reject:

- missing or unknown root properties;
- wrong scalar types;
- non-ISO timestamps;
- non-lowercase or non-64-character digests;
- a Plan 1a lock digest different from the verified reference;
- missing required gate;
- duplicate gate IDs;
- a required gate with status other than `passed`;
- missing test command, result, duration, or artifact digest;
- forbidden runtime/browser/build evidence;
- evidence naming a Plan 1a-owned symbol as Plan 1b-owned;
- evidence containing subscription telemetry.
Accept the tracked deterministic fixture and assert canonical JSON produces a stable SHA-256 without treating fixture values as current CI evidence.
**Step 2: Prove RED**
Run:
```bash
vendor/bin/phpunit tests/Unit/Reporting/Evidence/PlanOneBEvidenceValidatorTest.php tests/Unit/Reporting/Evidence/PlanOneBEvidenceBuilderTest.php tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php tests/Contract/Reporting/PlanOneBEndToEndContractTest.php
```
Expected: failures because validator, builder, evidence, and final contracts do not exist.
**Step 3: Implement the executable evidence contract**
Write a Draft 2020-12 schema with:

- `additionalProperties: false` at every object level;
- required schema version, plan ID, status, generation time;
- Plan 1a lock and evidence digests;
- repository revision;
- gate records with ID, status, command, result, duration, and artifact digests;
- ownership lists;
- performance measurements;
- unresolved-risk array.
Implement the same locked rules explicitly in `PlanOneBEvidenceValidator` using strict PHP type checks, exact allowed-key sets, `DateTimeImmutable::createFromFormat()`, digest regular expressions, unique gate indexing, and exhaustive required gate IDs. No new package is introduced.
Required gates:
```text
plan1a_handoff
ownership_boundary
run_state_machine
run_idempotency
snapshot_identity
snapshot_seal_trust
typed_data_classification
rows_cursor_drill_parity
row_stream_shape
export_state_machine
export_idempotency
dispatch_outbox_atomicity
dispatch_lease_recovery
dispatch_dead_letter
audit_outbox_delivery
current_async_authorization
execution_attempt_leases
renderer_parity
pdf_renderer_budget
streaming_budget
file_service_call_graph
s3_version_race
audit_fail_closed
retention_exact_version
action_bindings
error_retryability
run_export_observability
static_analysis
```
`plan1a_handoff` additionally proves immutable historical Task 4a exact53, Task 4b exact39, Task 4a2 exact16, Task 4c exact15, and Task 4d exact6 commits, then the forward-only Task 4e subject, exact ordered 78-path ownership manifest, exact parent/lineage, then Task 4f exact13 phase-aware commit-tree authority, immutable Task 4e SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d`, Task 5 pending/present transition, four-to-five-path dispatch allowlist transition, strict global-clean preflight, fresh runner counts, and `--verify-existing` no-write hash/mtime fence. The existing exact `20/20` malformed HTTP matrix remains intact. `run_idempotency` and `export_idempotency` each contain named PostgreSQL matrix results for same organization + different actor + same canonical body reuse, changed-body conflict independent of actor, other-organization independence, and explicit retry-key replay. Export evidence additionally proves that retrying an expired export is allowed only while its parent run is still ready and unexpired, and otherwise fails before side effects. `snapshot_identity` locks every validator equality, the exact decimal grammar and negative-zero rejection, seven-field source projection/sort, five-field duplicate identity, no input mutation, status-only expired reads, and fail-closed expired data reads. `snapshot_seal_trust` covers the Task 4a2-owned five-case typed structural-reason contract, only-official-seal-required safe mapping and negative non-masking matrix, closed 32-byte public-key/revoked map, absence of private/extra members, sodium detached verification, trusted/revoked/unknown/malformed cases, mutation of every signed field, and payload binding. `typed_data_classification` covers sensitive/audit access without heuristics. Dispatch gates prove both exact named schemas, aggregate+transport-intent rollback, unique event keys, closed event-specific subject rejection, SKIP LOCKED claims, lease fencing/reclaim, the complete deterministic backoff mutation matrix, crash-after-publication redelivery, two ID-only transport topics, Task 4b attempt-12 atomic run dead-letter/failure, and Task 7 attempt-12 atomic export dead-letter/failure with no pre-table export branch. `audit_outbox_delivery` separately proves the ID-only audit mapping, transactional audit-intent creation, Redis-acceptance without acknowledgement, claim/load/Core-append/ack ordering, Core-append replay, lease-fenced failure/backoff, attempt-12 dead-letter, and critical alerting. `current_async_authorization` proves Task 4e typed scope persistence/cutover, exact-kind resource adapters, membership/holding/project/ABAC/resource matrices, one repeatable-read `CurrentReportAuthorization`, the Task 7 exact13 single Eloquent subject reader, persisted run/export `authorizeExact` scope, queued-run no-snapshot scope, and run/export/snapshot scope equality, the exhaustive six-run-state/seven-export-state operation/evidence matrix with zero-side-effect denials, both closed seed-reader shapes, exact current-scope equality, fresh correlation IDs with durable non-authoritative lineage, revocation before provider/renderer/S3/URL, no second current-fact read, and absence of serialized authority or direct record access by rehydrators. `execution_attempt_leases` proves the exact `redis_reports` runtime inequalities `900 < 960 < 1200`, authority-free first claim and same-token live renewal before authorization, different/expired token rejection, retryable attempts, exact `claimOrRenew`/`failLeased` lifecycle surfaces for runs and exports, leased nonretryable authorization failure, UUID/token-fenced exhausted failure, run/export-only `JobFailed` listeners with no job `failed()` mechanism, queued failure fallback, or fabricated current-user context, both typed `due/requeue` recovery ports, PostgreSQL status/token/expiry fencing, atomic terminal state plus audit intent, rollback, replay idempotency, and explicit ABA proof that watchdog requeue or a new delivery fences every late old `JobFailed` while preserving the new token/state. `renderer_parity` contains CSV/XLSX/PDF semantic identity results. `pdf_renderer_budget` records dependency lock versions, per-definition budget registry coverage, 5,000-row boundary, page/HTML/PDF-byte/memory limits, registry dispatch, safe failure mapping and retry cleanup.

The `current_async_authorization` block additionally requires empty-registry/empty-scope deployability, non-empty missing-kind denial, exact typed resource and permission decisions with all identity mismatch cases, queue ABAC reads that bypass request globals and the nominal 300-second authority cache, and an atomic PostgreSQL cutover whose injected mid-DDL failure leaves only the old schema.

The builder runs only after every required CI gate passes, validates before writing, canonicalizes JSON, atomically writes the ignored `build/` artifact, rereads and validates it, then publishes its bytes and SHA-256 as CI artifacts.
**Step 4: Run the complete non-runtime verification sequence**
Run targeted unit, architecture, and contract tests from Tasks 1 through 14. Run PostgreSQL, disposable-S3, queue, and performance suites only in their isolated CI jobs. Run PHP syntax checks for all Plan 1b migrations and PHPStan for all Plan 1b changed PHP files.
Do not execute migrations, local database commands, dev servers, frontend builds, browser checks, or production commands.
Run `git check-ignore -q build/reports/plan-1b-completion.json` and require success; run `git ls-files --error-unmatch build/reports/plan-1b-completion.json` and require failure.
**Step 5: Run cross-file symbol and placeholder scans**
Use `rg` to compare Plan 1a-owned class basenames with every `Create:` entry in this plan. The intersection must be empty.
Search Plan 1b for:

- forbidden duplicate Plan 1a paths;
- undefined class basenames;
- action handler signature drift;
- stale authorization operation cases;
- direct technical messages;
- subscription telemetry;
- raw string idempotency arguments;
- actor or operation inside a run/export idempotency unique key;
- direct run/export dispatcher calls from coordinators or aggregate stores;
- `afterCommit`/`after_commit` used as the only delivery proof;
- remote/Core audit I/O inside aggregate transactions;
- outbox payloads containing filters, rows, query JSON, authorization facts, credentials, URLs, signatures, key IDs, or exception text;
- outbox claims without `FOR UPDATE SKIP LOCKED`, lease-token fencing, unique event key, reclaim, bounded backoff, or dead-letter handling;
- boolean/string/`kind` heuristics for officiality or sensitive data;
- stored authorization decisions/allowed-ID arrays used as current queue authority;
- retry handlers without explicit Plan 1a `IdempotencyKey` or without saved-view revision/hash/current-access checks;
- source/export identity projections containing floats, circular `source_hash`, unsorted/duplicate source refs, or non-UTC-microsecond instants;
- a `ReportExportStore` surface other than the exact seven locked methods;
- a renderer registry that does not contain exact `csv`, `xlsx`, and `pdf` entries;
- a PDF path without `ReportPdfRenderBudget`, page-count enforcement, byte caps, bounded-memory evidence, or safe failure mapping;
- unbalanced code fences.
Run the marker scan with split literals so its command cannot match itself:
```powershell
$markers = @(('TO' + 'DO'), ('FIX' + 'ME'), ('T' + 'BD'), ('X' + 'XX'), ('.' + '.' + '.')); Select-String -LiteralPath 'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md' -Pattern $markers -SimpleMatch
```
Expected: empty output.
**Step 6: Perform a fresh-context self-review**
Review the plan from line 1 without relying on implementation memory. Confirm:

- Plan 1a ownership and exact signatures;
- audit contract precedes every ready transition;
- row iterable shape is adapted and negatively tested;
- every FileService method has a complete caller and failure path;
- only run/export observability appears;
- evidence validation is executable;
- the Plan 1a handoff accepts only five explicit read-only arguments in order: lock, completion schema, completion artifact, fixed `docs/reports/contracts/plan-1a-gate-evidence.schema.json`, fixed raw `build/reports/plan-1a-ci-authorization.json`; it never performs implicit schema/artifact lookup and accepts only successful `hermetic_http` authorization evidence with exact ordered `22/22` execution records and a matching raw artifact digest, while Plan 1b completion remains post-CI;
- all state, identity, access, error, storage, retention, and performance invariants are testable;
- run/export idempotency is keyed only by organization plus idempotency hash and is tested across actors;
- installed Dompdf versions are unchanged, every published PDF-capable definition has an exact renderer budget, and PDF cannot consume an arbitrary dataset;
- every task has files, interfaces, RED, implementation, GREEN, static checks, and commit boundary.
**Step 7: Prove GREEN and write evidence**
After all isolated CI jobs pass, build and validate `build/reports/plan-1b-completion.json`, verify its digest after reread, upload the file and digest as CI artifacts, and confirm it remains ignored and untracked.
Expected: every required gate is `passed`, unresolved risks are explicit, cross-file ownership intersection is empty, and no generated completion evidence is staged.
**Step 8: Commit**
```bash
git add docs/reports/contracts/plan-1b-evidence.schema.json app/BusinessModules/Core/Reporting/Application/Evidence tests/Unit/Reporting/Evidence tests/Fixtures/Reporting/plan-1b-completion.valid.json tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php tests/Contract/Reporting/PlanOneBEndToEndContractTest.php .gitignore docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md
git commit -m "test[reports]: зафиксировать доказательства исполнения и экспортов"
```

---
## Review finding resolution — rounds 1, 2, and 3

| Finding | Resolution in this revision | Status |
|---|---|---|
| C-01 | Replaced stale paths, constructors, DTO fields, operations, error model, retryability, and action signatures with the exact Plan 1a handoff; Task 1 reflects and hashes it. | Addressed |
| C-02 | Removed Plan 1b copies of all Plan 1a DTOs, enums, provider ports, normalizers, inputs, and action contracts; added an explicit ownership intersection test. | Addressed |
| I-01 | Moved `ReportTransitionAudit` and its failing fake to Task 2; run and export `sealReady()` are audited atomically from their first implementation in Tasks 3 and 7. | Addressed |
| I-02 | Added `ReportRowChunkReader`, which treats the Plan 1a iterable as rows, validates identity, groups internally, and rejects nested empty or oversized chunk-shaped values and identity drift. | Addressed |
| I-03 | Defined all seven FileService methods, DTO invariants, ordered part rules, complete sink-to-method call graph, 409/412 race behavior, abort paths, temporary links, and exact-version deletion. | Addressed |
| I-04 | Removed every subscription-specific telemetry item; Task 13 is explicitly limited to run and export observability, leaving subscriptions to Plan 1c. | Addressed |
| I-05 | Added a first-party `PlanOneBEvidenceValidator` with direct positive and negative tests; JSON Schema remains a deterministic documentation and digest artifact rather than an unexecuted claim. | Addressed |
| M-01 | Changed the final marker review to construct literals from fragments and use `-SimpleMatch`; the command scans the whole plan without matching itself. | Addressed |
| N-I-01 | Restored Plan 1c ownership of catalog publication, published activation, assembler, and registry; Plan 4 is limited to evidence verification and deployment rollout. | Addressed in round 2 |
| N-I-02 | Made completion evidence an ignored post-gate CI artifact; only schema, validator/builder, deterministic fixture, tests, and ignore rule are committed. | Addressed in round 2 |
| N-C-01 | Preserved public `csv|xlsx|pdf`; Task 8 adds the full `PdfReportExportRenderer`, definition-hash/renderer-version budget registry, installed Dompdf adapter, bounded document/page/HTML/PDF-byte/memory contracts and parity/limit/failure tests. Tasks 10–11 cover PDF identity, dispatch, retry, cleanup and safe errors; Task 14 requires executable PDF evidence. | Addressed in round 3 |
| N-I-01 (round 3) | Tasks 3 and 7 now require exact unique `(organization_id,idempotency_key_hash)` for runs and exports. Persistence, action and evidence matrices prove same-org cross-actor reuse for equal canonical bodies and `REPORT_IDEMPOTENCY_CONFLICT` for changed bodies regardless of actor. | Addressed in round 3 |

Open review findings after round 3: **0**.

## Plan 1b completion definition

Plan 1b is complete only when:

- the Plan 1a lock, completion evidence and explicit fixed raw authorization artifact are verified byte-for-byte, including closed schema, exact ordered `22/22` records and matching digest;
- the ownership scan has no duplicate Plan 1a symbols;
- every Plan 1a action port owned by this phase is bound to one Plan 1b handler;
- run and export idempotency is organization-scoped across actors, compare-and-set, and audit-fail-closed;
- every run/export creation commits a unique secret-free PostgreSQL transport intent and every immutable audit event commits a unique closed PostgreSQL audit intent; Redis transport publication and Core audit delivery each have their own leased at-least-once lifecycle, idempotent consumer behavior, recovery, bounded backoff and fail-hard dead-letter evidence;
- operational/official snapshot classification, standard/sensitive data classification, sensitive/audit column sets, saved-view revision/hash, result hash, and trusted seal identity are persisted and verified without heuristics;
- async execution and download rebuild current actor/scope/access facts before provider, renderer, S3, or URL calls; stored authorization decisions/allowlists are never trusted;
- retryable attempts remain nonterminal and execution leases/watchdogs recover dead workers; only non-retryable or exhausted attempts write terminal failure;
- summary, pages, drill-down, CSV, XLSX, and PDF preserve one immutable identity;
- the untyped row iterable is validated row by row and grouped only by Plan 1b;
- export memory, source-read, row, column, byte, worksheet, timeout, PDF detail-row, definition-page, HTML-byte and PDF-byte budgets pass;
- the existing `barryvdh/laravel-dompdf v3.1.1` / `dompdf v3.1.4` lock is reused without a new dependency, and no PDF renderer receives an arbitrary dataset;
- all seven FileService operations have unit coverage and S3 race integration coverage;
- temporary links and retention always use exact object version IDs;
- Plan 1a error catalog status and retryability are preserved;
- telemetry contains only bounded run/export families;
- the first-party evidence validator accepts the generated completion document and rejects malformed variants;
- Plans 1c and 4 can verify the post-CI artifact digest without reading implementation internals or a committed `build/` file.

## Explicit handoff to later plans

Plans 2 and 3 must:

- implement the existing Plan 1a `ReportDataProvider`, `ReportRowQuery`, and `ReportDrillDownProvider`;
- make each `ReportRowQuery::cursor()` yield one row envelope accepted by `ReportRowChunkReader`;
- supply candidate definitions/bindings to Plan 1c through Plan 1a contracts without activating or publishing them;
- preserve all immutable hashes and version fields;
- pass the Plan 1b rows, drill-down, renderer, and streaming contract suites.

Plan 1c owns catalog publication, candidate validation, published activation, binding assembly, and production registry/map; it may use ready run/export IDs but must not change their execution contracts.
Plan 4 verifies the CI artifact digest, isolated integration results, rollback proof, and deployment gates before rollout; it does not publish catalog definitions.

Plan 1c and the umbrella/wave plans are intentionally untouched by this Task 4–7 amendment. After Plan 1b's Task 14 canonical commit, a separate prerequisite repin must replace every stale Plan 1b raw-plan SHA and every `20/20` Plan 1b gate assumption with the canonical Task 14 SHA and exact `28/28` gate set. The repin scope is limited to `2026-07-26-reports-canonical.md`, `2026-07-26-reports-plan-1-platform.md`, `2026-07-26-reports-plan-1c-catalog-workspace-quality.md`, `2026-07-26-reports-plan-2-wave-1.md`, and `2026-07-26-reports-plan-3-waves-2-3.md`; it must update fixtures/descriptors/bijection tests atomically and may not predict the pre-commit Plan 1b SHA.

## Task 14 implementation evidence

Task 14 is implemented at its local canonical commit boundary. The Draft 2020-12 schema, executable validator, atomic canonical builder, deterministic fixture, unit tests, architecture test, end-to-end contract test, ignore rule, and this canonical plan are tracked together.

Local non-database evidence:

- the exact amended Task 14 PHPUnit gate passes with `38 tests, 495 assertions`;
- PHPStan reports no errors for the two changed production PHP files;
- Pint completed for all six changed PHP files;
- `build/reports/plan-1b-completion.json` remains ignored, absent, and untracked.

Plan 1b completion evidence remains post-CI. The generated artifact and its SHA-256 may be published only after every isolated required gate is `passed`; no local result in this amendment is current CI completion evidence.

### Task 14 review amendment — round 1

The evidence contract now has one exact ordered set of 28 gates in both Draft 2020-12 JSON Schema and the executable PHP validator. Gate commands, artifact identifiers and types, required result checks, and performance measurement identifiers and units are closed and deterministic. A single mutation corpus exercises both validators; cross-field invariants that JSON Schema cannot express are covered by the executable validator.

The builder accepts only paths and expected SHA-256 values for real gate artifacts. It rereads every artifact, verifies its digest and embedded repository revision, reconstructs the completion document from those bytes, validates the temporary canonical document before rename, and rereads the final path after rename. A final-byte mismatch removes the invalid final artifact and fails closed. The committed fixture is explicitly marked as deterministic synthetic evidence and cannot be confused with a CI completion artifact.

The ownership test scans every `Create` entry in the whole canonical plan, including entries containing several backtick paths. It locks the complete path count and proves that none of the exact Plan 1a symbols is recreated. All amended tests use the production Composer autoloader.

Local validation for this amendment is intentionally limited to the amended Task 14 non-database gate, changed production-file static analysis, formatting, and diff hygiene. PostgreSQL, S3, queue, authorization, performance, complete-module, and deployment gates remain isolated CI or deployment evidence and are not represented as locally executed results.

### Task 14 review amendment — round 2

The completion schema now fixes both minimum and maximum cardinality at exactly 28 ordered gates and exactly one artifact per gate. Every performance measurement has an exact identifier, unit and limit, and its value has the same schema-level maximum enforced by the PHP validator. The shared mutation corpus therefore gives the same verdict for every JSON-representable invariant.

Every accepted CI gate artifact now has a closed executable envelope with `evidence_scope=ci`, exact repository revision, fixed producer identity, an existing PHPUnit test path, a canonical `build/reports/gates/<gate>.json` producer path, and an exact ordered set of typed passed case records corresponding one-to-one with the canonical required checks. The builder validates those bytes before using their result. Missing, failed or reordered records, impossible commands, incorrect producer paths, and fixture documents used as CI artifacts fail closed.

The deterministic committed completion fixture uses `evidence_scope=fixture`; the builder can only produce `evidence_scope=ci`. Gate commands no longer reference undeclared suites: each exact command invokes an existing test file as `php vendor/bin/phpunit <path> --no-coverage`.

The isolated Windows worktree uses its own untracked Composer vendor directory generated from the lock file. This prevents concurrent worktrees from replacing its production autoload classmap. The amended Task 14 non-database gate passes with `38 tests, 495 assertions`; database-backed, S3, queue, authorization, performance and deployment commands remain CI boundaries and were not executed locally.
