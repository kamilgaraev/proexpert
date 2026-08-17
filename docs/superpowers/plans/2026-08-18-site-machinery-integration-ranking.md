# Site Machinery Integration and Explainable Ranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Subagents are forbidden by the user. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Связать заявки с объекта с очередью техники, нормализовать рейтинг, раскрыть источник назначений и корректно показывать время.

**Architecture:** `SiteRequestAssetProjectionService` атомарно проецирует site request в уникально связанную asset request и синхронизирует terminal lifecycle. Чистый `CandidateScoreService` выдаёт bounded score/breakdown только eligible-кандидатам, а resources передают стабильные связи admin-клиенту.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, PHPUnit, React 18, TypeScript, MUI, Vitest.

## Global Constraints

- Не создавать workflow, deploy-контуры, сервисы инфраструктуры, очереди или окружения.
- Не менять canonical asset architecture, legacy operational projection или rollback storage.
- Сначала RED-тест каждого поведения, затем минимальная реализация.
- Backend и admin выпускаются отдельными PR; mobile меняется только при доказанной необходимости.

---

### Task 1: Backend schema and transactional projection

**Files:**
- Create: `database/migrations/2026_08_18_000001_link_site_requests_to_asset_dispatch.php`
- Create: `app/BusinessModules/Features/MachineryOperations/Services/SiteRequestAssetProjectionService.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Models/SiteRequest.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Services/SiteRequestService.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Models/AssetRequest.php`
- Test: `tests/Unit/MachineryOperations/SiteRequestAssetProjectionServiceTest.php`

**Interfaces:**
- Produces: `project(SiteRequest $siteRequest, int $actorId): AssetRequest`, `closeFromSiteRequest(...)`, `markSiteRequestInProgress(...)`, `completeFromAssignment(...)`.

- [ ] Write isolated SQLite schema and failing tests proving create mapping, empty `required_profile`, unique retry, update, cross-organization rejection and terminal synchronization.
- [ ] Run `APP_ENV=testing php artisan test tests/Unit/MachineryOperations/SiteRequestAssetProjectionServiceTest.php` and verify failures are missing schema/service behavior rather than setup errors.
- [ ] Add nullable timestamp/source/link columns and model relationships.
- [ ] Implement scope-aware `updateOrCreate` projection plus immutable audit event writes inside caller transactions.
- [ ] Inject the projector into `SiteRequestService` create/update/changeStatus/delete transactions and make the tests pass.

### Task 2: Backend assignment provenance and lifecycle

**Files:**
- Modify: `app/BusinessModules/Features/MachineryOperations/Services/AssetDispatchService.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Services/MachineryOperationsService.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Models/MachineryAssignment.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Http/Resources/MachineryOperationRecordResource.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Http/Resources/MachineryAssetResource.php`
- Test: `tests/Unit/MachineryOperations/SiteRequestAssetProjectionServiceTest.php`
- Test: `tests/Feature/Api/V1/Admin/AssetDispatchWorkflowTest.php`

**Interfaces:**
- Consumes: nullable `asset_request_id` and `origin_type` from Task 1.
- Produces: assignment payload with nested request provenance and stable `request_number`.

- [ ] Add failing tests for request-backed assignment, direct assignment, legacy nullable fallback and close-on-return.
- [ ] Persist `asset_request_id`, set `origin_type=direct` for direct requests and call lifecycle synchronization in the existing transactions.
- [ ] Eager-load provenance and expose nullable stable fields from resources.
- [ ] Run the focused tests and verify green.

### Task 3: Backend bounded candidate score

**Files:**
- Create: `app/BusinessModules/Features/MachineryOperations/Services/CandidateScoreService.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Services/AssetDispatchService.php`
- Modify: `app/BusinessModules/Features/MachineryOperations/Http/Controllers/MachineryOperationsController.php`
- Test: `tests/Unit/MachineryOperations/CandidateScoreServiceTest.php`

**Interfaces:**
- Produces: `scoreEligible(array $candidates): array` with nullable `score`, suitability fields and three breakdown components.

- [ ] Write table-driven RED tests for same project, known distance boundaries, neutral missing coordinates, equal/different costs, 0–100 bounds and excluded null score.
- [ ] Implement the 40/30/30 formula and labels with no external dependencies.
- [ ] Integrate after hard exclusions and return the breakdown from API.
- [ ] Run focused score and dispatch tests.

### Task 4: Admin RED tests and presentation

**Files:**
- Modify: `src/types/machineryOperations.ts`
- Modify: `src/pages/MachineryOperations/components/DecisionQueue.tsx`
- Modify: `src/pages/MachineryOperations/components/DispatchPlanner.tsx`
- Modify: `src/pages/MachineryOperations/components/AssetWorkspaceDrawer.tsx`
- Modify: `src/pages/MachineryOperations/machineryLabels.ts`
- Create: `src/pages/MachineryOperations/machineryDateTime.ts`
- Modify corresponding `*.test.tsx`; create `machineryDateTime.test.ts`.

**Interfaces:**
- Consumes: nullable score/breakdown and assignment provenance from backend.
- Produces: localized period, user-facing assignment heading and exclusion copy.

- [ ] Write RED tests for date+time/open end, request/direct/legacy headings, Russian period/project/status, hidden excluded score, breakdown and full production-tracking explanation.
- [ ] Implement a single `Intl.DateTimeFormat('ru-RU')` helper using browser timezone without manual offset arithmetic.
- [ ] Update components/types minimally and run focused Vitest.

### Task 5: Equipment request time input

**Files:**
- Modify: `src/types/siteRequest.ts`
- Modify: `src/components/siteRequests/EquipmentForm.tsx`
- Modify: `src/components/siteRequests/SiteRequestForm.tsx`
- Test: `src/components/siteRequests/EquipmentForm.test.tsx`
- Test: `src/components/siteRequests/SiteRequestForm.test.tsx`

**Interfaces:**
- Produces: optional `equipment_start_at`/`equipment_end_at` plus compatible rental dates only for `equipment_request`.

- [ ] Write RED tests that select date and time for equipment while non-equipment payloads remain unchanged.
- [ ] Replace equipment date-only controls with datetime-local inputs, keep local values verbatim, derive `yyyy-MM-dd` compatibility fields without timezone conversion.
- [ ] Run focused tests.

### Task 6: Verification, PRs and deployments

- [ ] Backend: run fresh focused tests, PHP syntax, Pint and scoped PHPStan; record the pre-existing SQLite full-migration limitation if still present.
- [ ] Admin: run focused Vitest, `tsc --noEmit` and ESLint on changed files.
- [ ] Review diffs against all five requirements and verify mobile consumes only preserved fields, so no mobile change is needed.
- [ ] Commit/push backend branch, create/merge backend PR, run only `.github/workflows/deploy-backend.yml`, and wait for `conclusion=success` plus workflow health/cutover checks.
- [ ] Commit/push admin branch, create/merge admin PR, wait for the existing admin production workflow to finish with `conclusion=success`.
- [ ] Collect PR URLs, merge SHAs, workflow run IDs and requirement-by-requirement test evidence before completing the active goal.
