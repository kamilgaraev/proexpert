# AI-сметчик МОСТ: Adaptive Document Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` inline. Subagents are forbidden by the user. Steps use checkbox syntax for tracking.

**Goal:** Replace the v3 all-or-nothing page runtime with an adaptive, durable, bounded document pipeline and a resilient admin contract.

**Architecture:** The first literal observer durably produces facts plus a depth decision. Server-owned routing selects one, two, or three independent observers and optional arbitration; dense pages receive bounded semantic crops. Physical provider responses remain recoverable before local parsing/projection, while page outcomes and admin states preserve partial/context results.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, Redis/Horizon, S3/FileService, Intervention Image/GD, PHPUnit; React/Vite, TypeScript, Vitest, MSW.

## Global Constraints

- Work only on `feat/ai-estimator-document-pipeline-v4` in backend/admin, both based on fresh `origin/main`.
- No production AI calls, no rerun of session 70/document 172, no local frontend build.
- No Actions/infra/secrets/new servers/MLOps/fallback model/new billing contour.
- Do not edit historical migrations; any schema change is forward-only and executed only by the standard deploy.
- Preserve tenant/source/version/ABAC/exact arithmetic/norm/price/publication gates.

---

### Task 1: Production-shaped RED contracts

**Files:**
- Create/modify: `tests/Fixtures/EstimateGeneration/Vision/*`
- Modify: `tests/Unit/EstimateGeneration/Analysis/DocumentArbitrationTest.php`
- Create: `tests/Unit/EstimateGeneration/Analysis/AdaptivePageAnalysisTest.php`
- Modify: `tests/Unit/EstimateGeneration/ProductionDocumentUnitProcessorTest.php`
- Modify: `tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php`

- [ ] Add anonymized page 1/3 arbiter fixtures with useful decisions and one invalid item.
- [ ] Add title, specification/table, dense drawing and unknown/low-readability fixtures.
- [ ] Run the exact new tests and record expected RED: fixed 3-observer route, no crop contract, post-response duration invalidation.

### Task 2: Adaptive routing and independent observer depth

**Files:**
- Create: `Analysis/Routing/PageAnalysisRoute.php`, `PageAnalysisRoutingDecision.php`, `PageAnalysisRouter.php`
- Modify: `Analysis/Observers/ObserverInputBuilder.php`, `RunIndependentObservers.php`, observer prompt/contracts
- Modify: `Analysis/Arbitration/ArbitrationInputBuilder.php`, `RunDocumentArbitration.php`
- Modify: `Application/Documents/ProductionDocumentUnitProcessor.php`, `DocumentUnitOutput.php`

- [ ] Persist literal observer result before route selection.
- [ ] Implement `simple_context`, `structured_textual`, `dense_ambiguous` with fail-open escalation.
- [ ] Keep later observers independent from prior semantic output.
- [ ] Make arbitration conditional for structured pages and mandatory for dense/ambiguous pages.
- [ ] Verify exact provider call counts and page outcomes.

### Task 3: Bounded semantic regions and multimodal crops

**Files:**
- Create: `Vision/Regions/SemanticRegion.php`, `SemanticRegionIngestor.php`, `SemanticRegionCropper.php`, `SemanticRegionSet.php`
- Modify: `Vision/DTO/VisionDocumentInput.php`, `Vision/Providers/TimewebVisionProvider.php`
- Modify: `Analysis/Observers/ObserverInputBuilder.php`, `Analysis/Arbitration/ArbitrationInputBuilder.php`
- Modify: `config/estimate-generation.php`

- [ ] RED invalid coordinate/count/pixel/byte/overlap tests.
- [ ] Validate AI regions against trusted full-page coordinate space.
- [ ] Render adaptive crops from canonical page bytes with GD/Intervention and stable server locators.
- [ ] Send full page plus crops as multiple image content parts.
- [ ] Preserve macro facts when microtext remains unreadable.

### Task 4: Durable post-HTTP recovery and exactly-one cost

**Files:**
- Modify: `Vision/PhysicalAttempt/VisionPhysicalAttemptStore.php`, `EloquentVisionPhysicalAttemptStore.php`, snapshot DTO
- Modify: `Vision/Providers/TimewebVisionProvider.php`
- Modify: `Analysis/EloquentAiRoleRunRepository.php`, role-run DTOs
- Add forward-only migration only if persisted parsed/projected state cannot fit existing bounded columns safely.
- Modify PostgreSQL physical-attempt/usage/role-run tests.

- [ ] RED crash/timeout after HTTP 200 and deterministic parser/projection failure.
- [ ] Persist raw body before parsing; replay locally without provider call.
- [ ] Persist bounded parsed envelope/projection progress and finish role/page publication after restart.
- [ ] Prove one physical attempt maps to exactly one usage/cost record.

### Task 5: Workflow budgets, bounded dispatch and breaker

**Files:**
- Modify: `DocumentRepresentationResourceLimits.php`, `ProductionDocumentUnitProcessor.php`
- Modify: `DispatchDocumentProcessingUnits.php`, `EloquentDocumentUnitDispatchStore.php`, `EloquentDocumentProcessingUnitStore.php`
- Modify: jobs/config/runtime tests and `DocumentProcessingOutcomeResolver.php`.

- [ ] RED page 2 successful arbiter crossing old 60-second representation threshold.
- [ ] Separate representation resource limits, transport timeout, page workflow soft budget and job hard timeout.
- [ ] Replace 500-job burst with bounded free-window dispatch across documents/pages.
- [ ] Limit breaker to repeated transport/system/safety failures.
- [ ] Verify `ready_calculation`, `ready_context`, `partial_review`, `system_failure`, queued/processing semantics.

### Task 6: Inter-document escalation and 200-page scale

**Files:**
- Modify: `Understanding/CrossDocumentFactLinker.php`, coordinator/services as discovered by graph trace.
- Add unit/PostgreSQL tests for escalation identity, replay and mixed 200-page workload.

- [ ] Escalate stored page on cross-document reference while reusing first observer result.
- [ ] Process 3–4 documents/200 pages with bounded in-flight units, bytes, regions, tokens and calls.
- [ ] Assert calls are materially below unconditional 800 baseline while dense pages retain full analysis.

### Task 7: Remove superseded runtime

**Files:** discovered by `search_code`/`trace_path` for old canonicalizers, repair regexes, targeted semantic recheck, compatibility normalizers and dead adapters.

- [ ] Prove every candidate has no required reader/writer.
- [ ] Delete all-or-nothing/repair/timeout/breaker compatibility paths.
- [ ] Keep historical migrations unchanged; add forward-only cleanup only for proven unused schema.

### Task 8: Admin RED→GREEN

**Files (admin repository):**
- Modify contracts/normalizers/API/hook/DocumentsStep/DocumentDetailsPanel/session summary and their Vitest/MSW tests.

- [ ] Add anonymized snapshot/list/detail fixtures matching session 70/document 172.
- [ ] RED optional field, 200/304, refresh failure, partial/context/system/breaker/progress/cost scenarios.
- [ ] Isolate snapshot/list/detail/analysis errors and retain last useful state.
- [ ] Render honest outcomes, questions, sources and regions; never show 100% as failed success.
- [ ] Confirm geometry/model tabs remain absent.

### Task 9: Verification and sequential review

- [ ] Backend targeted unit and PostgreSQL contract tests without skip.
- [ ] `php -l`, Pint, changed-module Larastan, `git diff --check`.
- [ ] Admin Vitest/MSW, `tsc --noEmit`, changed-file ESLint/Prettier; no build.
- [ ] Separate read-only review of complete diffs for correctness, security, architecture, UX and production scale.
- [ ] Fix confirmed findings and rerun only affected checks.

### Task 10: Release and canary

- [ ] Russian Conventional Commits, push backend/admin branches, PRs, CI, standard merge/deploy.
- [ ] Verify `/ready`, exact backend/admin SHA, protected endpoint 401, read-only logs and GlitchTip.
- [ ] Update/create YouTrack workflow/status/admin UX/operator articles after deployed behavior is true.
- [ ] Mark goal complete only after both production deploys and canary.

## Self-review

Every requested outcome, routing case, recovery boundary, scale budget, cost invariant, admin state and release gate maps to a task. No deferred or fallback path remains. Types flow from physical attempt → role run → page corpus → document/session projection → admin.
