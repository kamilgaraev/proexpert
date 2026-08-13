# Explicit Document Retry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Безопасно повторно обрабатывать сохранённый document-wide `system_failure` одной новой lineage и одним post-commit dispatch.

**Architecture:** Существующий document retry endpoint сужается до explicit system-failure recovery. Application service под session/document row locks проверяет ABAC и fences, сохраняет idempotency/audit lineage в document JSONB, архивирует unit failures в metadata и сбрасывает только current-source units/pages; UI действует только по backend capability/disposition и хранит idempotency key через remount.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, PHPUnit/Pest, React 18, TypeScript, MUI, Vitest, MSW 2.

## Global Constraints

- Не запускать migrations, local DB-команды вне штатного PostgreSQL wrapper, dev servers или admin build.
- Не выполнять provider/S3 network calls в транзакции и не вызывать платный AI в production.
- Не удалять старые units/pages/failures и не менять исходный S3 artifact.
- Все пользовательские backend-тексты идут через `trans_message`.
- Реализация выполняется inline; субагент используется один раз только для read-only review завершённого блока.

---

### Task 1: RED backend policy and API contract

**Files:**
- Create: `tests/Unit/EstimateGeneration/Documents/ExplicitDocumentRetryEligibilityTest.php`
- Create: `tests/Unit/EstimateGeneration/Workflow/RetryEstimateGenerationDocumentTest.php`
- Modify: `tests/Unit/EstimateGeneration/Http/EstimateGenerationDocumentPresentationTest.php`
- Modify: `tests/Feature/EstimateGeneration/EstimateGenerationDocumentApiTest.php`

**Interfaces:**
- Consumes: current document resource/action contract and retry endpoint.
- Produces: expected request fields `state_version`, `source_version`, `idempotency_key`; disposition `explicit_system_failure`; typed accepted/replayed/in-progress/stale behavior.

- [ ] Write focused failing tests for allowed legacy systemic failure and forbidden active/ready/user/integrity/security/corrupt/hard-limit/stale cases.
- [ ] Run the exact test files and confirm failures are caused by the missing explicit contract.
- [ ] Write failing service tests for same key, different active key, ABAC denial, source/state fences, retained failure history and single after-commit dispatch.
- [ ] Run the exact tests and preserve RED evidence.

### Task 2: GREEN backend application service

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ExplicitDocumentRetryEligibility.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ExplicitDocumentRetryCommand.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/RetryEstimateGenerationDocument.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentActionResult.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Requests/RetryEstimateGenerationDocumentRequest.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationDocumentController.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Presentation/EstimateGenerationDocumentActionBuilder.php`
- Modify: `lang/ru/estimate_generation.php`

**Interfaces:**
- Consumes: `AuthorizationService::can`, `DocumentSystemFailureDetector`, current source units, `ProcessEstimateGenerationDocumentJob`.
- Produces: locked explicit retry result with `disposition`, current document summary and one after-commit job.

- [ ] Implement eligibility as a pure policy with explicit deny reasons.
- [ ] Implement command DTO carrying actor, scope, state/source fences, idempotency key and optional reason.
- [ ] Replace destructive retry logic with locked tenant-scoped reset, append-only history and hashed idempotency audit.
- [ ] Register exactly one recovery-queue dispatch after commit only for the winning lineage.
- [ ] Keep the controller thin and map stale/forbidden results to safe translated responses.
- [ ] Expose backend-only action capability/disposition/source fence.
- [ ] Run Task 1 tests to GREEN.

### Task 3: PostgreSQL concurrency and history contracts

**Files:**
- Create: `tests/Feature/EstimateGeneration/ExplicitDocumentRetryPostgresTest.php`
- Create: `tests/Support/EstimateGeneration/ExplicitDocumentRetryRaceWorker.php`
- Modify: `tests/Unit/EstimateGeneration/DocumentProcessingUnitContractTest.php`

**Interfaces:**
- Consumes: production PostgreSQL transaction/lock implementation.
- Produces: proof of one winner/dispatch, replay identity, source fence, tenant isolation, audit history and renewed breaker behavior.

- [ ] Add failing real-PostgreSQL same-key replay and concurrent different-key tests using the existing process race pattern.
- [ ] Add failing tests for cross-tenant/ABAC, stale source, current-only reset and retained failure history.
- [ ] Add breaker regression showing the new lineage can run and three identical new failures stop remaining units again.
- [ ] Run `tests/Runtime/run-postgres-tests.ps1 -TestPath tests/Feature/EstimateGeneration/ExplicitDocumentRetryPostgresTest.php`; require 0 skipped new scenarios.
- [ ] Fix only contract gaps revealed by these tests and rerun the minimal set.

### Task 4: RED/GREEN admin action flow

**Files:**
- Create: `src/features/estimate-generation/documents/DocumentRetryDialog.tsx`
- Create: `src/features/estimate-generation/documents/documentRetryIdempotency.ts`
- Create: `src/features/estimate-generation/documents/__tests__/ExplicitDocumentRetry.test.tsx`
- Modify: `src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationDocumentNormalizers.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationApi.ts`
- Modify: `src/features/estimate-generation/documents/DocumentDetailsPanel.tsx`
- Modify: `src/features/estimate-generation/steps/DocumentsStep.tsx`

**Interfaces:**
- Consumes: backend action capability/disposition, source/state fences and response disposition.
- Produces: accessible confirmation, stable sessionStorage UUID and refresh-driven UI.

- [ ] Write MSW/Vitest RED tests for capability visibility, forbidden/no button, confirmation copy and accessible dialog.
- [ ] Add RED tests for double click, unresolved request/remount same key, success snapshot/detail refresh and 409 refresh/message.
- [ ] Implement strict DTO normalization and request body with stable idempotency key.
- [ ] Implement responsive MUI dialog and disabled/loading behavior without optimistic counts.
- [ ] Keep key after accepted/unknown processing; clear only on terminal/stale refresh.
- [ ] Run the focused Vitest file to GREEN.

### Task 5: Regression and static verification

**Files:**
- Verify changed backend/admin files and existing incident tests.

**Interfaces:**
- Consumes: completed backend/admin block.
- Produces: fresh verification evidence before review/commit.

- [ ] Run incident regressions for deterministic terminal processing, breaker, canonical counts/outcome and full-page Vision fallback.
- [ ] Run `php -l` on changed PHP, Pint on changed PHP and minimal Larastan; record genuine `storage_configuration_invalid` without fake S3 values.
- [ ] Run focused admin Vitest/MSW, `npx tsc --noEmit`, ESLint and Prettier checks for changed files; do not build.
- [ ] Verify UTF-8 and `git diff --check` in both repositories.

### Task 6: One independent review and fixes

**Files:**
- Review both repository diffs read-only.

**Interfaces:**
- Consumes: final implementation and this spec/plan.
- Produces: one reviewer report and locally verified fixes for confirmed findings.

- [ ] Commit the completed large block in both branches with Russian Conventional Commit messages.
- [ ] Spawn exactly one read-only reviewer subagent with backend/admin base and head SHAs.
- [ ] Validate findings, fix Critical/Important confirmed issues locally and rerun only affected tests/checks.
- [ ] Commit verified fixes; do not repeat full review for documentation/SHA-only changes.

### Task 7: PR, merge, deploy and read-only canary

**Files:**
- No CI/infrastructure changes.

**Interfaces:**
- Consumes: reviewed green feature branches.
- Produces: merged/deployed backend/admin releases while leaving production document 168 untouched.

- [ ] Push both branches and create PRs against `main` using existing templates.
- [ ] Merge through the standard repository workflow and wait for existing deploy workflows.
- [ ] Read-only verify backend/admin `release.json`, backend `/ready`, protected retry endpoint without auth returning 401 and fresh production logs.
- [ ] Confirm no retry/provider call was made for document `168` and stop before production smoke retry.
- [ ] Search/update the nearest YouTrack Knowledge Base workflow/UX article with released behavior.
