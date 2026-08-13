# AI Estimate Second PDF Production Incident Implementation Plan
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Доказать и исправить второй pre-provider PDF failure, затем атомарно завершать повторяющуюся системную ошибку на уровне документа и explicit retry.

**Architecture:** Реальный PDF adapter/representation/raster/processor path тестируется с fixture-backed storage и deterministic Vision spy. Safe throwable diagnostics образуют отдельную page-independent identity для breaker, а aggregate reconciler завершает document/session/retry operation одной транзакционно согласованной terminal projection.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL JSONB/row locks, PHPUnit, PyMuPDF runtime, Pint, Larastan; React/Vite/TypeScript, Vitest/MSW только при доказанном admin gap.

## Global Constraints

- Работать только в feature worktree от свежего `origin/main`.
- Не запускать migrations, локальные произвольные DB-команды, dev servers, admin/landing build, production writes или provider HTTP.
- Не менять infrastructure, workflows, secrets, queues, servers или MLOps.
- Не исправлять root cause до точного совпадения fingerprint `c5c07e…`.
- Не ослаблять tenant/source/integrity/security/hard-limit checks.
- Production retry document 168 остаётся запрещённым.

---

### Task 1: Safe root-cause reproduction

**Files:**
- Modify: `tests/Unit/EstimateGeneration/ProductionDocumentUnitProcessorTest.php`
- Create if fixture extraction is required: `tests/Fixtures/EstimateGeneration/pdf/second-incident-page.json`
- Modify only after RED: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureNormalizer.php`

**Interfaces:**
- Consumes: real `PdfGeometryWorker`, `PdfDocumentAdapter::representation`, `RasterPreprocessor`, `ProductionDocumentUnitProcessor`.
- Produces: safe class chain and deterministic diagnostic fingerprint; Vision spy inputs.

- [ ] Add a diagnostic test whose production mutation is “drop the original throwable class chain”; use `MOST_PDF_INCIDENT_FIXTURE` only for the local 22-page proof and a committed extracted fixture for permanent CI.
- [ ] Run the exact test and record RED caused by the current pre-provider exception; assert provider call count remains zero.
- [ ] Compute `hash('sha256', implode('|', [...$classes, 'document_unit_processing_failed']))` and require exact `c5c07e…` before naming root cause.
- [ ] Trace the throwing expression to its caller and compare with the working raster/PDF path.
- [ ] Add a minimal safe diagnostic value object/normalizer only if the chain cannot be asserted without production persistence changes.
- [ ] Rerun the diagnostic test without changing root-cause behavior; preserve class-only evidence.

### Task 2: Minimal root-cause fix and full-page Vision proof

**Files:**
- Modify only the proven production class under `app/BusinessModules/Addons/EstimateGeneration/Application/Documents` or `Vision/Preprocessing`.
- Modify: `tests/Unit/EstimateGeneration/ProductionDocumentUnitProcessorTest.php`

**Interfaces:**
- Consumes: safe diagnosis from Task 1.
- Produces: complete `VisionDocumentInput` with one full-page PNG and optional auxiliary metadata.

- [ ] Write the permanent RED for the proven failing input; the assertion must fail on the identified branch, not on mock existence.
- [ ] Run the single RED and confirm the expected class/boundary.
- [ ] Implement the smallest source fix; auxiliary parser/vector failure maps to bounded raster fallback, while source-integrity failures still throw typed terminal errors.
- [ ] Run the RED to GREEN and assert image dimensions/content hash/source transform at the Vision spy.
- [ ] Run the 22-page harness and assert 22 complete full-page inputs with zero HTTP/provider billing calls.

### Task 3: Closed safe observability contract

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/SafeThrowableDiagnostic.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureNormalizer.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Observability/SensitiveDiagnosticSanitizer.php`
- Modify: `tests/Unit/EstimateGeneration/Observability/FailureRecorderTest.php`
- Modify: `tests/Unit/EstimateGeneration/Observability/SensitiveDiagnosticSanitizerTest.php`

**Interfaces:**
- Produces: `top_exception_class`, `previous_chain_fingerprint`, `execution_boundary`, `diagnostic_fingerprint` and allowlisted scalar context.

- [ ] Write RED: two sensitive messages of one class normalize identically; two root classes differ; none of the forbidden strings survives serialization.
- [ ] Write RED proving one recorder capture produces one canonical observer event.
- [ ] Implement bounded class slug allowlist and class-only previous-chain hash with a maximum depth.
- [ ] Add only the new keys to the sanitizer closed domains and keep the 2 KiB failure-data bound.
- [ ] Run observability tests to GREEN.

### Task 4: Attempt-lineage circuit breaker

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentProcessingUnitStore.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentProcessingUnitStore.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/DocumentRepresentationJsonbRoundTripPostgresTest.php`
- Modify/create runtime child under `tests/Runtime/` only if required by the existing wrapper.

**Interfaces:**
- Consumes: page-independent `diagnostic_fingerprint` and persisted `processing_attempt_id`.
- Produces: exactly three physical executions and terminalized pending units in the same scope.

- [ ] Add PostgreSQL RED with concurrent workers: 3 processor executions, remaining pending units receive systemic terminal outcome, 0 provider calls for stopped units, 0 skips.
- [ ] Add RED for a pre-existing running unit: it stays running and is reconciled by its own owner.
- [ ] Add cross-tenant/document/source/attempt tests and a new-lineage reset test.
- [ ] Extend `fail()` with a typed breaker identity and lock the document/scope before counting committed matching physical failures.
- [ ] Exclude page/unit identity from breaker grouping but preserve every tenant/source/lineage fence.
- [ ] Run the exact PostgreSQL file to GREEN through `tests/Runtime/run-postgres-tests.ps1`.

### Task 5: Explicit retry terminal lifecycle and honest counts

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/RetryEstimateGenerationDocument.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ExplicitDocumentRetryEligibility.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/ExplicitDocumentRetryPostgresContractTest.php`
- Modify: `tests/Unit/EstimateGeneration/Http/EstimateGenerationDocumentPresentationTest.php`

**Interfaces:**
- Produces: canonical terminal retry audit entry with completion timestamp, execution/system-failure counts and safe diagnostic fingerprint; exact replay is inert.

- [ ] Write PostgreSQL RED for `processing → failed` (or the already established terminal enum), `completed_at`, honest counts and terminal reason.
- [ ] Add exact same-key replay RED proving no new lineage and no dispatch.
- [ ] Add capability RED for a new-key retry only after terminal snapshot and ABAC approval.
- [ ] Reconcile document, session and active retry audit entry atomically after the last non-running unit.
- [ ] Preserve internal `MAX_ATTEMPTS` exhaustion invariant but expose separate `execution_count` and `system_failed_count`.
- [ ] Run the lifecycle/replay/cross-scope PostgreSQL tests to GREEN with 0 skips.

### Task 6: Canonical API and conditional admin correction

**Files:**
- Inspect/modify backend presentation and resource classes under `Application/Documents` and `Http/Resources`.
- Modify `lang/ru/estimate_generation.php` for any new user-facing key.
- Modify `prohelper_admin` only if backend payload cannot already drive one document-level system failure.

**Interfaces:**
- Produces: one document-level message, counts for 0/N system failure, no page retry, backend-controlled document retry capability.

- [ ] Add backend RED for all-system failure: no action-required page cards, no “требуется решение”, no page retry.
- [ ] Add RED for mixed non-systemic page outcomes to prevent over-collapsing legitimate user-action cases.
- [ ] Implement the canonical payload and Russian translation through `trans_message`.
- [ ] If admin is defective, create an admin worktree, write Vitest/MSW RED for 0/N breaker and terminal retry lifecycle, implement minimal normalization/UI correction, and run focused Vitest plus `tsc --noEmit`.

### Task 7: Regression, static verification and one independent review

**Files:**
- All changed backend files; admin files only if Task 6 proved the need.

- [ ] Run DB-free processor/render/Vision tests, safe diagnostics, prior JSONB/fallback/terminal/idempotency/ABAC regressions.
- [ ] Run the exact PostgreSQL wrapper tests from Tasks 4–5 and require 0 new skips.
- [ ] Run `php -l` for changed PHP, Pint on changed PHP and minimal Larastan/PHPStan; report genuine `storage_configuration_invalid` without fake S3.
- [ ] Run UTF-8 checks and `git diff --check`; for admin run focused Vitest/MSW, `tsc --noEmit`, ESLint/Prettier changed files, never build.
- [ ] Commit the completed large block with a Russian Conventional Commit subject.
- [ ] Dispatch exactly one read-only reviewer subagent with base/head SHA and this spec; fix confirmed Critical/Important findings and rerun only affected checks.

### Task 8: PR, merge, deploy, documentation and canary

**Files:**
- No infrastructure/workflow changes.

- [ ] Push feature branch, create PR against `main`, wait for required checks and squash merge through the standard GitHub flow.
- [ ] Wait for the existing deploy workflow; do not invoke migrations or manual server operations.
- [ ] Verify `/ready`, backend/admin release metadata, and protected retry endpoint returning 401 without auth.
- [ ] Check fresh GlitchTip and read-only production logs, then read-only snapshot document 168; assert no new AI usage/physical attempt and no new retry audit entry.
- [ ] Update the nearest YouTrack Knowledge Base AI-сметчик workflow/status/UX article with the released terminal retry behavior.
- [ ] Stop before production retry document 168 and request separate authorization for it.
