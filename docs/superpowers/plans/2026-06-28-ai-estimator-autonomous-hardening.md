# AI Estimator Autonomous Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Усилить AI-сметчика без участия пользователя: добавить единый статус готовности, заблокировать небезопасное применение черновика, улучшить рабочее место проверки и подготовить handoff-документ для команды.

**Architecture:** Backend становится источником правды по готовности: отдельный сервис считает документы, извлеченные объемы, нормы, цены и блокеры применения. Admin UI только отображает этот контракт и ведет пользователя по следующему действию. Документация фиксирует, что уже сделано автономно и что требует реальных чертежей/эталонных смет.

**Tech Stack:** Laravel 11, PHP 8.2/8.3, Larastan/PHPStan, React/Vite/TypeScript, Vitest.

---

### Task 1: Backend Readiness Contract

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimatorReadinessService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationSessionResource.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Modify: `lang/ru/estimate_generation.php`
- Test: `tests/Unit/EstimateGeneration/EstimatorReadinessServiceTest.php`

- [x] **Step 1: Add pure readiness service**

Create a service that accepts `EstimateGenerationSession`, loaded documents and draft payload, then returns:
- `status`
- `can_generate`
- `can_apply`
- `next_action`
- `blockers`
- `warnings`
- `metrics`

- [x] **Step 2: Add API exposure**

Add `estimator_readiness` to session resource and status endpoint. Use backend-provided metrics instead of frontend recomputation.

- [x] **Step 3: Enforce apply guard**

Before applying a generated estimate, reject drafts where readiness says `can_apply = false`.

- [x] **Step 4: Add unit tests**

Cover ready-to-generate, document-review-required, ready-to-apply, and blocked-by-unresolved-norm cases.

### Task 2: Admin Readiness UX

**Files:**
- Modify: `prohelper_admin/src/types/estimateGeneration.ts`
- Modify: `prohelper_admin/src/pages/Estimates/EstimateGenerationWorkspacePage.tsx`
- Modify: `prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.ts`
- Test: `prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.test.ts`

- [x] **Step 1: Type backend readiness**

Add `EstimateGenerationEstimatorReadiness` and include it in session/status types.

- [x] **Step 2: Render readiness cockpit**

Add a compact panel with current status, blockers, warnings and metrics. User must see whether the system needs documents, review, norms, or can apply the result.

- [x] **Step 3: Align apply button**

Admin applies only when both existing UI rules and backend readiness allow it.

- [x] **Step 4: Add helper tests**

Test readiness-aware apply logic in presentation helpers.

### Task 3: Handoff Document

**Files:**
- Create: `docs/ai-estimator/handoff-after-autonomous-hardening.md`

- [x] **Step 1: Document completed autonomous work**

List concrete product/backend/UI improvements completed without user input.

- [x] **Step 2: Document team-owned next steps**

Separate what requires real data, production configuration, domain validation, etalon Grand-Smeta comparisons, and manual QA.

### Task 4: Verification

**Commands:**
- `php -l` on changed PHP files.
- `vendor\bin\phpstan.bat analyse app\BusinessModules\Addons\EstimateGeneration tests\Unit\EstimateGeneration --memory-limit=1G`
- Safe unit tests that do not depend on Laravel SQLite migrations.
- `npx vitest run src/pages/Estimates/estimateGenerationPresentation.test.ts src/pages/Estimates/EstimatesListPageLegacyAi.test.ts`
- `npx tsc --noEmit`
- `git diff --check`

**Do not run:** migrations, dev servers, frontend builds, browser smoke tests.
