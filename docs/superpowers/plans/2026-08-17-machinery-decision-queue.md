# Machinery Decision Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать очередь решений по технике наполняемой из admin UI, согласовать её backend-семантику и обеспечить корректные permissions, состояния и cache invalidation.

**Architecture:** Backend определяет actionable-состояние единым Eloquent scope и использует его для API и overview. Admin добавляет изолированный dialog создания заявки, permission-gated dispatcher и централизованное отображение кодов, сохраняя существующий canonical assignment flow.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, PHPStan/Larastan; React 18, TypeScript, MUI, TanStack Query, Vitest/Testing Library.

## Global Constraints

- Не изменять legacy storage/write path, rollback storage, workflow-файлы или инфраструктуру.
- Не выполнять ручные smoke-тесты и локальные операции с БД вне тестового harness.
- Backend и admin выпускать отдельными branch/commit/push/PR/merge.
- Назначать только точные `asset_request_id` и canonical `organization_asset_id`.
- Mobile не изменять: существующий сценарий и решение по scope зафиксированы в design evidence.

---

### Task 1: Backend actionable contract

**Files:**
- Modify: `app/BusinessModules/Features/MachineryOperations/Models/AssetRequest.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Services/AssetDispatchService.php`
- Test: `tests/Feature/Api/V1/Admin/MachineryOperationsWorkflowTest.php`

**Interfaces:**
- Produces: `AssetRequest::scopeRequiresDecision(Builder $query): Builder`
- Consumes: query filter `requires_decision=1`

- [ ] Add a feature test with `pending`, `approved`, and `assigned` requests; assert list with `requires_decision=1` returns only the first two and overview reports `2`.
- [ ] Run the exact PHPUnit test and confirm RED because the filter is ignored.
- [ ] Add `scopeRequiresDecision()` and use it in both pagination and overview.
- [ ] Run the exact PHPUnit test and existing machinery workflow tests; confirm GREEN.
- [ ] Run targeted PHPStan/Larastan and commit the backend behavior.

### Task 2: Admin request client and dialog

**Files:**
- Modify: `src/types/machineryOperations.ts`
- Modify: `src/services/machineryOperationsService.ts`
- Modify: `src/services/machineryOperationsService.test.ts`
- Create: `src/pages/MachineryOperations/components/CreateMachineryRequestDialog.tsx`
- Create: `src/pages/MachineryOperations/components/CreateMachineryRequestDialog.test.tsx`

**Interfaces:**
- Produces: `CreateMachineryAssetRequestPayload`, `machineryOperationsService.createAssetRequest(payload)`, `CreateMachineryRequestDialog`
- Consumes: existing asset-request endpoint and active project collection

- [ ] Add failing service and component tests for the POST payload and business-only fields.
- [ ] Confirm RED for missing service/dialog behavior.
- [ ] Implement the typed client and dialog; serialize only checked profile capabilities as `true`.
- [ ] Run the exact Vitest files and confirm GREEN.

### Task 3: Permissions, actionable queue, and invalidation

**Files:**
- Modify: `src/pages/MachineryOperations/MachineryOperationsPage.tsx`
- Modify: `src/pages/MachineryOperations/MachineryOperationsPage.test.tsx`
- Modify: `src/types/machineryOperations.ts`

**Interfaces:**
- Consumes: `requires_decision: true`, request-create and request-approve permissions
- Produces: immediate queue refresh after create; overview/queue/assets/workspace/candidates refresh after assignment

- [ ] Add failing page tests for create permission, approve permission, exact request/candidate selection, and query refresh after mutations.
- [ ] Confirm RED against the current page.
- [ ] Wire the dialog, permission gates, actionable query, and full invalidation set.
- [ ] Run the page tests and confirm GREEN.

### Task 4: Russian labels and robust states

**Files:**
- Modify: `src/pages/MachineryOperations/machineryLabels.ts`
- Modify: `src/pages/MachineryOperations/components/DecisionQueue.tsx`
- Modify: `src/pages/MachineryOperations/components/DecisionQueue.test.tsx`
- Modify: `src/pages/MachineryOperations/components/DispatchPlanner.tsx`
- Modify: `src/pages/MachineryOperations/components/DispatchPlanner.test.tsx`

**Interfaces:**
- Produces: `machineryExclusionReasonLabel(code: string): string`

- [ ] Add failing tests proving no raw exclusion code is visible, unknown codes use a safe fallback, and empty/all-excluded states explain the outcome.
- [ ] Confirm RED.
- [ ] Implement centralized Russian labels and differentiated loading/error/empty content.
- [ ] Run component tests and confirm GREEN.

### Task 5: Verification and releases

**Files:**
- No production files; verify diffs and repository state.

- [ ] Run fresh backend target tests and PHPStan/Larastan.
- [ ] Commit/push backend, create PR, inspect checks, merge, then run only `.github/workflows/deploy-backend.yml` and wait for `conclusion=success`.
- [ ] Run fresh admin target Vitest, `npx tsc --noEmit`, and ESLint for touched files.
- [ ] Commit/push admin, create PR, inspect checks, merge, then run the existing admin deployment workflow and wait for `conclusion=success`.
- [ ] Collect requirement-by-requirement evidence: files, test commands/results, PRs, merge SHAs, workflow runs, deployment conclusions, and exact user scenario.
