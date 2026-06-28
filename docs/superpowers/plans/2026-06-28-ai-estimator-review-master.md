# AI Estimator Review Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Block saving AI-generated estimates with unresolved zero-price positions and add a review master where the user confirms or replaces norms/prices before applying the draft.

**Architecture:** Backend remains the source of truth for apply readiness and learning events. Frontend builds a review queue from draft work items and uses the existing candidate-selection endpoint to recalculate rows, refresh packages, and progressively clear blockers.

**Tech Stack:** Laravel 11, PHP 8.2/8.3, React/Vite/TypeScript, MUI, Vitest, PHPUnit, PHPStan.

---

### Task 1: Backend Apply Guard

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimatorReadinessService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateDraftPersistenceService.php`
- Test: `tests/Unit/EstimateGeneration/EstimatorReadinessServiceTest.php`

- [ ] **Step 1: Write a failing test**

Add a test that creates an `EstimateGenerationSession` with `draft_payload.quality_summary.not_calculated_work_items = 1`, `priced_work_items = 10`, and no unresolved normative count. Expected: readiness `can_apply` is false and blocker code is `prices_require_review`.

- [ ] **Step 2: Run the test and verify failure**

Run: `vendor\bin\phpunit.bat --bootstrap vendor\autoload.php tests\Unit\EstimateGeneration\EstimatorReadinessServiceTest.php`

- [ ] **Step 3: Implement guard**

Add `prices_require_review` blocker when `not_calculated_work_items > 0` or `safe_norm_required_work_items > 0`. Add the same explicit check in `EstimateDraftPersistenceService::assertDraftCanBeApplied()` so API apply cannot bypass readiness.

- [ ] **Step 4: Run backend checks**

Run syntax checks, targeted PHPUnit, and targeted PHPStan for changed backend files.

### Task 2: Frontend Review Queue

**Files:**
- Modify: `prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.ts`
- Test: `prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.test.ts`

- [ ] **Step 1: Write failing presentation tests**

Add tests for:
- apply is disabled when `notCalculatedWorkItemsCount > 0`;
- review queue includes not-calculated items, candidate items, low-confidence matched items, and priced matched items that still have nearby candidates.

- [ ] **Step 2: Implement pure helpers**

Add helpers to collect reviewable work items from draft local estimates and summarize review counts without touching React state.

- [ ] **Step 3: Run Vitest for presentation helpers**

Run: `npx vitest run src/pages/Estimates/estimateGenerationPresentation.test.ts`

### Task 3: Frontend Review Master UI

**Files:**
- Modify: `prohelper_admin/src/pages/Estimates/EstimateGenerationWorkspacePage.tsx`
- Modify: `prohelper_admin/src/services/estimateGenerationService.ts`

- [ ] **Step 1: Add review state**

Track selected review item key and expose current item from the review queue.

- [ ] **Step 2: Add master controls**

Add a compact panel above package/detail area: counts, “Проверить позиции”, “Следующая”, “Предыдущая”, “Подтвердить текущую норму”, candidate buttons.

- [ ] **Step 3: Use existing selection endpoint**

Selecting a candidate calls `selectNormativeCandidate`, refreshes session/draft/packages/package detail, and advances to next unresolved row.

- [ ] **Step 4: Keep apply button blocked**

Show explicit alert when apply is blocked by not-calculated positions or backend readiness.

- [ ] **Step 5: Run frontend checks**

Run `npx tsc --noEmit` and targeted Vitest. Do not run build or dev server.

### Task 4: Commit And Push

**Files:** all changed files from Tasks 1-3.

- [ ] **Step 1: Verify staged diff excludes unrelated KnowledgeHub changes**

Run `git diff --cached --name-only` before commit.

- [ ] **Step 2: Commit**

Commit message: `feat[lk]: добавлен мастер проверки AI-сметы`

- [ ] **Step 3: Push**

Run `git push origin main`.
