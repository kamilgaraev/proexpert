# AI Estimator Canonical Result Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans inline. Subagents are prohibited by the user for this task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce unique, evidence-preserving canonical page facts and a typed, human-readable Russian admin presentation, then deploy backend and admin safely.

**Architecture:** A backend canonical reducer owns semantic identity, lineage and confidence before persistence. The backend resource builds a bounded presentation DTO and also reduces historical payloads; admin strictly normalizes and renders that DTO without inventing semantics.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, PostgreSQL contract harness, React, TypeScript, Vitest, MSW, MUI.

**Spec:** `docs/specs/2026-08-18-ai-estimator-canonical-result-quality.md`

## Global Constraints

- Preserve tenant/project/session/source-version/evidence fences and atomic publication.
- Do not change prices, norms, estimate arithmetic, historical migrations, infrastructure, workflows, secrets or feature flags.
- Do not run paid AI, retry, resume or production mutations.
- All PHP user text uses `trans_message(...)`; UI text is Russian.
- No frontend build; run targeted tests, typecheck, lint and formatting only.

---

### Task 1: Production-shaped contract fixture and failing backend tests

**Files:**
- Create: `tests/Fixtures/EstimateGeneration/Vision/session-75-page-5-canonicalization.json`
- Create: `tests/Unit/EstimateGeneration/Analysis/CanonicalFactReducerTest.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/AtomicDocumentUnitPublicationPostgresTest.php`
- Modify: `tests/Unit/EstimateGeneration/Ocr/EstimateGenerationDocumentSemanticProjectionTest.php`

**Interfaces:**
- Consumes: observer claims and arbitration decisions shaped like the production page payload.
- Produces: executable expectations for `CanonicalFactReducer::reduce()`, persisted lineage/confidence and resource DTO.

- [ ] Write the fixture with synthetic scope IDs and literal expected Russian labels.
- [ ] Write tests for semantic dedup, distinct axes, level/floor count, confidence, quarantine, room-area binding and labels.
- [ ] Run targeted PHPUnit tests and capture expected RED assertions against duplicate rows, `floor_count`, `1.0` confidence and raw labels.

### Task 2: Backend canonical reducer and persistence

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/CanonicalFactReducer.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/CanonicalFactConfidence.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ObservationClaim.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ClaimSemanticMatcher.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentUnitPublicationFactory.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/AtomicDocumentUnitPublicationWriter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelEvidenceWriter.php`

**Interfaces:**
- Consumes: `list<ObservationClaim>` and `list<ArbitrationDecision>`.
- Produces: `CanonicalFactReducer::reduce(array $claims, array $decisions): array` with one decision per semantic identity, merged supporting IDs/evidence refs and deterministic order.

- [ ] Carry validated observer confidence into `ObservationClaim`.
- [ ] Implement semantic identity including entity, fact type, typed value and unit.
- [ ] Merge accepted equivalent decisions while retaining all safe lineage.
- [ ] Replace claim-ID persistence identity with canonical semantic identity.
- [ ] Persist conservative aggregated confidence and lineage; map `level` to elevation and validate explicit `floor_count`.
- [ ] Run targeted PHPUnit and PostgreSQL tests to GREEN.

### Task 3: Backend AdminResponse presentation DTO

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/CanonicalDocumentFactPresenter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationDocumentDetailResource.php`
- Modify: `lang/ru/estimate_generation.php`
- Modify: `tests/Unit/EstimateGeneration/Ocr/EstimateGenerationDocumentSemanticProjectionTest.php`

**Interfaces:**
- Consumes: historical/current arbitration decisions plus independent claims.
- Produces: bounded `semantic_analysis.facts` entries with canonical ID, Russian label, normalized unit, confidence, lineage and source page.

- [ ] Implement room-area joining and semantic dimension labels.
- [ ] Normalize units and remove duplicate unit suffixes.
- [ ] Expose source metadata separately from label.
- [ ] Run resource tests to GREEN.

### Task 4: Typed admin normalizer and UI

**Files:**
- Modify: `src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationDocumentNormalizers.ts`
- Modify: `src/features/estimate-generation/documents/DocumentDetailsPanel.tsx`
- Modify: `src/features/estimate-generation/documents/DocumentDetailsPanel.semantic.test.tsx`

**Interfaces:**
- Consumes: backend `DocumentSemanticFactDto`.
- Produces: typed, historically deduplicated facts rendered with backend labels and separate source links.

- [ ] Write Vitest/MSW tests for the fixture and verify RED.
- [ ] Add strict DTO parsing and defensive semantic identity deduplication.
- [ ] Render Russian labels, single units, room-area rows and `#page=N` source links.
- [ ] Run targeted Vitest/MSW tests to GREEN.

### Task 5: Verification and sequential review

**Files:**
- Review: all changed backend/admin files.

**Interfaces:**
- Consumes: completed implementation and test evidence.
- Produces: release-ready diffs without unrelated changes.

- [ ] Run backend syntax checks, targeted PHPUnit/PostgreSQL, PHPStan and Pint.
- [ ] Run admin targeted Vitest/MSW, `tsc --noEmit`, ESLint and Prettier for changed files.
- [ ] Review correctness, tenant/evidence security, architecture, production-size behavior and UX sequentially.
- [ ] Fix only findings in scope and rerun the smallest affected verification.

### Task 6: PR, merge, deploy and read-only canary

**Files:**
- No product-file changes expected.

**Interfaces:**
- Consumes: verified branch commits.
- Produces: merged and deployed backend/admin releases with exact SHA evidence.

- [ ] Commit with Russian Conventional Commit messages and push both branches.
- [ ] Create backend/admin PRs against `main`, wait for required checks and merge.
- [ ] Monitor standard deploy workflows to successful completion.
- [ ] Verify `/ready`, exact backend/admin release SHA, protected `401`, logs/GlitchTip and unchanged production session 75/document 177/page 1066/fact count/AI cost.
- [ ] Do not invoke retry, resume, document processing or any paid AI endpoint.

### Task 7: Workflow documentation sync

**Files:**
- Update the nearest existing YouTrack Knowledge Base article for AI-сметчик result review UX.

**Interfaces:**
- Consumes: released behavior.
- Produces: business-readable operational guidance for reviewing canonical facts and opening source evidence.

- [ ] Search existing МОСТ AI-сметчик workflow/UX articles.
- [ ] Update the nearest article with result labels, evidence navigation, partial quarantine and historical display behavior.
- [ ] Verify the article describes only deployed behavior.
