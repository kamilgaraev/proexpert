# AI Estimator Production Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans inline. The user permits exactly one subagent, only for the final read-only review. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recover and publish durable useful AI results without new provider calls, correct canonical scope/projection, and make stop/finalization truthful and terminal.

**Architecture:** Existing durable physical attempts and role runs remain the replay source. Local validation sanitizes only isolatable warning contradictions; a shared visual-object scope/identity policy separates evidence union, canonical object and estimate scope; existing atomic publication and aggregate reconciliation remain the only write paths.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, PostgreSQL contract harness, deterministic recorded provider spies; React/Vite/Vitest only if the backend DTO requires an admin change.

**Spec:** `docs/specs/2026-08-19-ai-estimator-production-reconciliation.md`

## Global Constraints

- Preserve exact tenant/project/session/document/page/source-version/model/evidence fences.
- No production AI calls, retry/resume, writes, migrations, restarts or cache clears.
- No new infrastructure, workflow, secret, feature flag or temporary fallback contour.
- Do not modify historical migrations.
- No local admin build.
- TDD RED must be observed before production code for every finding.

---

### Task 1: Production-shaped RED fixtures

**Files:**
- Create: `tests/Fixtures/EstimateGeneration/Vision/session-77-pages-5-9-11.json`
- Modify: `tests/Unit/EstimateGeneration/Vision/VisionScaleInvariantTest.php`
- Modify: `tests/Unit/EstimateGeneration/Documents/VisualInventoryProjectorTest.php`
- Modify: `tests/Unit/EstimateGeneration/BuildingModel/ProjectModelEvidenceWriterTest.php`
- Modify: `tests/Unit/EstimateGeneration/Documents/DocumentProcessingOutcomeResolverTest.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/AtomicDocumentUnitPublicationPostgresTest.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingControlPostgresTest.php`

**Interfaces:**
- Consumes: sanitized production contracts for pages 5/9/11/13.
- Produces: failing assertions for warning sanitation, object dedup/scope, collision-free atomic publication and terminal stop.

- [ ] Add the bounded fixture with synthetic scope IDs and hand-checked expected counts.
- [ ] Add page 11 test that expects `scale_missing` removed/quarantined while all useful sections survive.
- [ ] Run the single scale test and record expected RED `scale_missing_warning_mismatch`.
- [ ] Add page 5 visual inventory tests for semantic dedup, lineage union and excluded/contextual scope.
- [ ] Run them and record RED duplicate/contextual assertions.
- [ ] Add page 9 PostgreSQL publication test reproducing exact entity collision and 0-call replay expectation.
- [ ] Run it and record RED collision.
- [ ] Add stop/outcome PostgreSQL test with 10 completed + 12 superseded and post-wire completion.
- [ ] Run it and record RED processing/non-cancelled outcome.

### Task 2: Isolatable provider warning sanitation and replay

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VisionAnalysisData.php`
- Modify: `tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php`
- Modify: `tests/Feature/EstimateGeneration/Vision/VisionPhysicalAttemptRecoveryPostgresTest.php`

**Interfaces:**
- Consumes: provider schema v4 array and existing durable physical response.
- Produces: valid `VisionAnalysisData` with bounded quarantine; no provider call or second usage journal on replay.

- [ ] Normalize `scale_missing` from the accepted scale set before constructing the strict DTO.
- [ ] Preserve warning quarantine reason and every valid evidence/element/fact/routing field.
- [ ] Keep constructor fail-closed for direct invalid DTO construction and all identity/evidence boundaries.
- [ ] Run page 11 unit and durable PostgreSQL replay tests to GREEN.

### Task 3: Canonical visual object and collision-free project model publication

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/VisualObjectIdentity.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/CanonicalFactReducer.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/VisualInventoryProjector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelEvidenceWriter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/AtomicDocumentUnitPublicationWriter.php`

**Interfaces:**
- Produces: normalized visual object identity `(room/location, family, object type)` and scope policy.
- Consumes: canonical decisions and claims with exact lineage.
- Produces: one immutable Entity plus fact-specific Facts/DocumentFacts, with contextual objects excluded from estimate assertions.

- [ ] Implement deterministic visual object identity without raw-description equality.
- [ ] Merge equivalent decisions and union supporting claims/evidence while preserving scope fences.
- [ ] Make accepted entity attributes fact-independent and merge repeated entity descriptors before repository save.
- [ ] Skip project model assertions for `contextual_only` and `excluded_by_document_note` furniture.
- [ ] Preserve `requires_confirmation` fixtures as candidate facts for `VisualInventoryQuestionBuilder`.
- [ ] Run page 5/page 9 unit and PostgreSQL tests to GREEN.

### Task 4: Document facts, Questions AI and truthful geometry boundary

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/AcceptedDocumentFactProjector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Understanding/VisualInventoryQuestionBuilder.php`
- Modify: `tests/Unit/EstimateGeneration/Ocr/EstimateGenerationDocumentSemanticProjectionTest.php`
- Modify: `tests/Unit/EstimateGeneration/Application/Understanding/VisualInventoryQuestionBuilderTest.php`

**Interfaces:**
- Consumes: accepted explicit room/dimension facts and requires-confirmation visual objects.
- Produces: sourced document facts and Questions AI without inferred quantity takeoffs.

- [ ] Project explicit room name/area and numeric dimension chains without promoting polygons to confirmed quantities.
- [ ] Ensure questions group canonical fixture objects by room and retain source pages/evidence.
- [ ] Assert contextual furniture creates no question/work/candidate.
- [ ] Run semantic projection and understanding tests to GREEN.

### Task 5: Stop/finalization reconciliation

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/StopEstimateGenerationDocumentProcessing.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentProcessingOutcomeResolver.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingControlPostgresTest.php`
- Modify: `tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php`

**Interfaces:**
- Consumes: exact document/source stop plus late bounded response completion.
- Produces: 0 new dispatch, terminal partial/cancelled outcome and reconciled session snapshot.

- [ ] Normalize all operator-stopped/superseded units as cancellation without erasing pre-stop diagnostics.
- [ ] Invalidate only the same-source reconciliation marker whenever terminal unit state changes after stop.
- [ ] Reconcile document/session after the final in-flight wire settles and do not dispatch successors.
- [ ] Preserve completed outputs and expose honest execution/usefulness counts.
- [ ] Run stop, late response, idempotency and snapshot tests to GREEN.

### Task 6: Offline full-PDF and quality gates

**Files:**
- Modify if required: `tests/Feature/EstimateGeneration/Pipeline/FullPdfAiEstimatorPostgresE2ETest.php`

**Interfaces:**
- Consumes: existing isolated PostgreSQL harness and deterministic recorded providers.
- Produces: full 22-page proof with zero network/provider calls on replay and unchanged cost/quota counters.

- [ ] Run minimal regressions for each finding once.
- [ ] Run the relevant EstimateGeneration unit module.
- [ ] Run PostgreSQL publication/control/replay contracts through the existing harness.
- [ ] Run the offline 22-page PDF gate and assert pages 5/9/11 publish, scopes/questions are correct, stop is terminal and provider calls/cost remain zero on replay.
- [ ] Run `php -l` on changed PHP, targeted Pint and targeted Larastan/PHPStan.
- [ ] If admin changed, run production-shaped Vitest/MSW, `tsc --noEmit`, changed-file ESLint and Prettier; never build.

### Task 7: Review, release and canary

**Files:**
- Review all changed backend/admin files.

**Interfaces:**
- Produces: one independently reviewed, merged and deployed release.

- [ ] Dispatch exactly one read-only subagent for final P0–P2 correctness/security/architecture/UX review.
- [ ] Fix confirmed findings and rerun only affected checks.
- [ ] Perform final self-review of diff, source/version/evidence fences and release scope.
- [ ] Commit in Russian Conventional Commit format, push, create PR and wait for required CI.
- [ ] Merge through the standard path and monitor standard backend/admin deploy only for changed repositories.
- [ ] Read-only canary exact release SHA, `/ready`, protected `401`, logs and GlitchTip without AI smoke.
- [ ] Search YouTrack Knowledge Base and update the nearest workflow only if deployed user workflow meaning changed.
