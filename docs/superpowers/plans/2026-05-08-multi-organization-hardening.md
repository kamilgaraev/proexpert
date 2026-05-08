# Multi-Organization Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** привести модуль мультиорганизации к проверяемому состоянию через полноценную нормализацию домена: закрыть найденные проблемы lifecycle/workflow, разграничения доступа, целостности данных, API-контрактов, фронтенд-потребителей, OpenAPI и тестов.

**Architecture:** Laravel LK API остается источником истины для холдингов, дочерних организаций, контекста организации, отчетов и пользователей дочерних организаций. Модуль должен иметь один канонический route-контур для LK workflow мультиорганизации; публичный сайт/лендинг холдинга остается отдельной подзоной того же домена, но без дублирования core workflow. `prohelper_land` потребляет только задокументированные канонические endpoints. Workflow фиксируется в коде, тестах и документации: `single -> parent holding`, `child active -> archived/deactivated`, без небезопасного hard delete и без переноса данных в чужую организацию.

**Compatibility Policy:** обратная совместимость не требуется. Не добавлять fallback-ручки, legacy aliases, временные адаптеры, параллельные response shapes и заглушки. При конфликте старого и нового поведения удалять старое поведение, обновлять клиентов, OpenAPI и тесты в одном изменении.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL, JWT auth, `LandingResponse`, project RBAC через `config/RoleDefinitions/lk/*.json`, React/Vite/TypeScript в `prohelper_land`, Vitest/MSW для фронтенд-контрактов.

---

## Audit Baseline

Найденные проблемы зафиксированы в `prohelper/docs/analysis/multi-organization-workflow-review.md`. Этот план основан на проверке:

- `php artisan route:list --path=multi-organization`: 36 routes основного LK-контура мультиорганизации.
- `php artisan route:list --path=holding`: 51 совпадение по строке `holding`; это не отдельный модуль, а пересекающийся набор route-контуров того же домена: публичный `holding-api/{slug}`, LK `landing/holding/*`, site builder/analytics и часть `multi-organization/*` routes с `holding` в URI/name/action.
- `rg --files prohelper/tests | rg "(Multi|multi|Holding|holding|Organization|organization)"`: специальных тестов multi-organization/holding нет.
- `prohelper/docs/openapi/lk/paths/multi_organization.yaml`: покрывает только часть фактических routes.
- `prohelper_land/src`: есть потребители несуществующих backend endpoints и разные проверки типа холдинга.

Критичные исходные файлы:

- `prohelper/routes/api/v1/landing/multi_organization.php`
- `prohelper/routes/api/v1/landing/holding.php`
- `prohelper/app/Http/Controllers/Api/V1/Landing/MultiOrganizationController.php`
- `prohelper/app/Services/Landing/MultiOrganizationService.php`
- `prohelper/app/Services/Landing/ChildOrganizationUserService.php`
- `prohelper/app/BusinessModules/Core/MultiOrganization/Http/Controllers/*`
- `prohelper/app/BusinessModules/Core/MultiOrganization/Services/*`
- `prohelper/app/Models/Organization.php`
- `prohelper/app/Models/OrganizationGroup.php`
- `prohelper/database/migrations/*organization*`
- `prohelper/database/migrations/*holding_sites*`
- `prohelper/config/RoleDefinitions/lk/*.json`
- `prohelper/lang/ru/holding.php`
- `prohelper_land/src/utils/api.ts`
- `prohelper_land/src/utils/multiOrganizationApiV2.ts`
- `prohelper_land/src/hooks/useOrganizationContext.ts`
- `prohelper_land/src/layouts/DashboardLayout.tsx`
- `prohelper_land/src/layouts/HoldingPanelLayout.tsx`
- `prohelper_land/src/components/multi-org/HoldingDashboard.tsx`
- `prohelper_land/src/pages/dashboard/MultiOrganizationPage.tsx`

---

## Task 0: Normalize Module Boundaries and Route Map

- [ ] Draw the canonical module map before changing controllers:
  - LK core workflow: `/api/v1/landing/multi-organization/*`;
  - public holding API: `/api/v1/holding-api/{slug}/*`;
  - holding site builder/public landing: `/api/v1/landing/holding/site/*` and `/api/v1/landing/holding/public/*`;
  - holding analytics: either move under canonical multi-organization reports or keep under `landing/holding/analytics/*` with explicit reason.
- [ ] Remove duplicate or overlapping core workflow routes instead of keeping aliases.
- [ ] Decide final route names, controller ownership and response class for every route in the map.
- [ ] Ensure every route belongs to exactly one purpose:
  - context and hierarchy;
  - child organization lifecycle;
  - child users and roles;
  - dashboard and aggregates;
  - projects/contracts;
  - reports;
  - public holding site/API.
- [ ] Delete unused frontend client methods for routes that are removed.
- [ ] Delete backend routes/controllers/methods that are no longer canonical.
- [ ] Update route names so service scope logic never depends on ambiguous strings such as `holding` appearing in route names.
- [ ] Add a route inventory test that asserts the final canonical route list and fails when an undocumented multi-organization route appears.

Verification:

```powershell
php artisan route:list --path=multi-organization
php artisan route:list --path=holding
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationRouteInventoryTest.php
```

Expected after fixes: route-list shows one normalized LK core contour, public/site routes are clearly separated, and no removed route is still reachable.

---

## Task 1: Add Backend Regression Tests First

- [ ] Create `prohelper/tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationWorkflowTest.php`.
- [ ] Cover `create-holding`: organization becomes `organization_type=parent`, `is_holding=true`, has group, module context remains readable.
- [ ] Cover `add-child`: child belongs to selected group, gets `organization_type=child`, `parent_organization_id`, correct `hierarchy_path`, owner pivot, and RBAC assignment.
- [ ] Cover `delete child`: rejects `transfer_data_to` outside current holding and rejects transfer to the child itself.
- [ ] Cover child user removal: pivot is detached/deactivated and active `UserRoleAssignment` rows for that organization context are deactivated.
- [ ] Cover custom role assignment: custom role produces `role_type=custom`, system role produces `role_type=system`.
- [ ] Create `prohelper/tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationPermissionsTest.php`.
- [ ] Cover reports route access for owner, accountant, viewer and a user without `multi-organization.reports.*`.
- [ ] Cover `switch-context`: user cannot switch to an unrelated organization.
- [ ] Cover `projects`, `contracts-v2`, `dashboard-v2`, `filter-options`: LK routes return LK response shape, not admin response shape.
- [ ] Add route-level coverage for every canonical route:
  - unauthenticated request returns 401;
  - authenticated without module/permission returns 403;
  - invalid request returns 422 with LK response shape;
  - cross-organization access returns 403;
  - missing entity returns 404 where applicable;
  - successful request returns documented LK response shape.
- [ ] Add destructive-workflow coverage for every mutation route:
  - database state changes only inside the allowed organization scope;
  - no orphaned pivot/RBAC records remain;
  - cache invalidation happens when scope-relevant data changes.
- [ ] Add response contract assertions for all endpoints consumed by `prohelper_land`.

Verification:

```powershell
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationWorkflowTest.php
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationPermissionsTest.php
```

Expected before fixes: at least the transfer target, custom role type, child removal and report permission tests fail.

---

## Task 2: Fix Migration and Schema Consistency

- [ ] Fix fresh-install ordering between `holding_sites` and `organization_groups`.
- [ ] Edit `prohelper/database/migrations/2025_09_15_120000_create_holding_sites_table.php` so it guarantees `organization_groups` exists before creating `holding_sites`.
- [ ] Edit `prohelper/database/migrations/2025_12_25_130001_create_organization_groups_table.php` so it is idempotent when `organization_groups` already exists.
- [ ] Keep this compatible with existing environments where both migrations have already run.
- [ ] Fix `prohelper/app/Models/OrganizationGroup.php::getActiveChildOrganizations()` to filter organizations by the real organization activity field, not non-existent `organizations.status`.
- [ ] Add a unit/feature assertion that `getActiveChildOrganizations()` returns active children and excludes inactive children.

Verification:

```powershell
php -l database/migrations/2025_09_15_120000_create_holding_sites_table.php
php -l database/migrations/2025_12_25_130001_create_organization_groups_table.php
php -l app/Models/OrganizationGroup.php
php artisan test --filter=getActiveChildOrganizations
```

Do not run `artisan migrate` or `artisan migrate:fresh` during implementation unless the user explicitly asks for a database migration run.

---

## Task 3: Make Organization Lifecycle Explicit and Safe

- [ ] In `prohelper/app/Services/Landing/MultiOrganizationService.php`, make `createOrganizationGroup()` the only transition from single organization to holding parent.
- [ ] Ensure the transition sets `organization_type=parent`, `is_holding=true`, `parent_organization_id=null`, `hierarchy_path=<parent_id>`.
- [ ] In `addChildOrganization()`, create the child first, then set `hierarchy_path` to `<parent_path>.<child_id>`.
- [ ] Move external S3 bucket creation out of the database transaction or defer it until after the database write succeeds.
- [ ] Validate that `group_id` belongs to the authenticated holding organization resolved from request context, not only `user->current_organization_id`.
- [ ] Replace unsafe child hard delete with a lifecycle-safe operation:
  - transfer projects and contracts only to the parent holding or a sibling child inside the same holding;
  - reject unrelated target organizations;
  - reject target equal to deleted child;
  - deactivate/archive the child when dependent records remain;
  - hard delete only when there are no dependent business records and no active users.
- [ ] Keep the existing `DELETE /child-organizations/{childOrgId}` endpoint, but make the service behavior safe and response message business-readable.
- [ ] Add `trans_message('holding.*')` keys for every user-facing lifecycle error/success message.

Verification:

```powershell
php -l app/Services/Landing/MultiOrganizationService.php
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationWorkflowTest.php
```

Expected after fixes: transfer outside holding is forbidden, hierarchy path includes child id, delete/archive behavior is deterministic.

---

## Task 4: Unify Child User Workflow and RBAC Assignments

- [ ] In `prohelper/app/Services/Landing/ChildOrganizationUserService.php`, align `role_data.template`, `role_data.slug`, custom role creation and assignment into one explicit path.
- [ ] Store pivot `settings.primary_role_slug` consistently as JSON-compatible array and update it when a role changes.
- [ ] Set `UserRoleAssignment.role_type=custom` for custom roles and `system` for RoleDefinitions roles.
- [ ] Replace `Auth::id()` in assignment metadata with the explicit `$createdBy` or `$updatedBy` argument.
- [ ] Use the existing role deactivation logic when removing a user from a child organization.
- [ ] In `MultiOrganizationService::removeUserFromChildOrganization()`, deactivate active RBAC assignments for the child organization context before detaching/deactivating the pivot.
- [ ] In bulk creation, return a clear per-row result and avoid silent half-created RBAC state.

Verification:

```powershell
php -l app/Services/Landing/ChildOrganizationUserService.php
php -l app/Services/Landing/MultiOrganizationService.php
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationWorkflowTest.php --filter=user
```

Expected after fixes: removing a child user removes effective access; custom/system role type matches the assigned role source.

---

## Task 5: Harden Permissions, Scope and Response Contracts

- [ ] In `prohelper/routes/api/v1/landing/multi_organization.php`, add granular route middleware:
  - read pages: `authorize:multi-organization.view`;
  - dashboard: `authorize:multi-organization.dashboard`;
  - reports summary/dashboard: `authorize:multi-organization.reports.view` plus specific report permissions where roles define them;
  - management operations remain under `authorize:multi-organization.manage`;
  - child user operations also require user-management capability, not only generic manage.
- [ ] Keep `check-availability` available without module access, but make all returned flags non-sensitive.
- [ ] In `MultiOrganizationService::hasAccessToOrganization()`, either honor the `$permission` argument or remove it from callers. Prefer honoring it for `read`, `manage`, `reports`.
- [ ] In `ContextAwareOrganizationScope`, replace route-name detection with request/module context that does not depend on `multiOrganization.*` route names.
- [ ] Add cache invalidation after holding creation, child creation, child archive/delete, context switch and access permission changes.
- [ ] Replace service-level `abort(403, ...)` with typed exceptions handled by controllers and translated via `trans_message`.
- [ ] Replace `AdminResponse` with `LandingResponse` in `HoldingProjectsController` and `HoldingContractsController` because these controllers are exposed under `api/v1/landing/multi-organization`.
- [ ] Cap `per_page` in project/contract controllers to a safe maximum and return 404 for missing project/contract instead of generic load errors.
- [ ] Remove duplicate response formats instead of supporting both old and new payloads.
- [ ] Replace any controller/service fallback behavior with explicit validation and explicit errors.
- [ ] Ensure every user-facing error/success text is translated via `trans_message`.

Verification:

```powershell
php -l routes/api/v1/landing/multi_organization.php
php -l app/BusinessModules/Core/MultiOrganization/Services/ContextAwareOrganizationScope.php
php -l app/BusinessModules/Core/MultiOrganization/Http/Controllers/HoldingProjectsController.php
php -l app/BusinessModules/Core/MultiOrganization/Http/Controllers/HoldingContractsController.php
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationPermissionsTest.php
```

Expected after fixes: LK clients receive LK response shape, reports are not exposed by module access alone, and scope does not change behavior only because a route was renamed.

---

## Task 6: Fix Reports, Aggregates and Query Semantics

- [ ] In `MultiOrganizationService::getHoldingDashboard()`, fix `total_balance` precedence so parent balance and child balances are summed correctly.
- [ ] Replace N+1 counts in child organization lists/dashboard with `withCount`, aggregate queries or preloaded relationships.
- [ ] Normalize report date filters across project, contract, act and movement reports:
  - document exact date field used by each report;
  - expose field names in OpenAPI;
  - keep frontend query names stable.
- [ ] In report services/controllers, validate organization filters against accessible organization ids.
- [ ] Ensure `organization_ids` accepts the format actually sent by `prohelper_land` and rejects inaccessible ids.
- [ ] Add tests for report filters by accessible child ids and inaccessible child ids.

Verification:

```powershell
php -l app/Http/Controllers/Api/V1/Landing/MultiOrganizationController.php
php -l app/BusinessModules/Core/MultiOrganization/Services/HoldingReportService.php
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization/MultiOrganizationPermissionsTest.php --filter=report
```

Expected after fixes: reports use a consistent access filter and do not leak unrelated organization data.

---

## Task 7: Align Frontend Consumers With Real API

- [ ] In `prohelper_land/src/hooks/useOrganizationContext.ts`, detect holding by `organization_type === 'parent' || is_holding === true`, not by `organization_type === 'holding'`.
- [ ] Apply the same holding detection fix in:
  - `prohelper_land/src/layouts/DashboardLayout.tsx`;
  - `prohelper_land/src/layouts/HoldingPanelLayout.tsx`;
  - `prohelper_land/src/components/multi-org/HoldingDashboard.tsx`;
  - `prohelper_land/src/pages/holding/HoldingConsolidatedReportPage.tsx`.
- [ ] In `prohelper_land/src/utils/api.ts`, remove or replace calls to endpoints that are not present in route-list:
  - `PATCH /multi-organization/child-organizations/bulk-update`;
  - `GET /multi-organization/child-organizations/export`;
  - `GET /multi-organization/analytics/summary`.
- [ ] If a screen actively uses one of those actions, implement the matching backend route with tests and OpenAPI in the same task; otherwise delete the unused client method.
- [ ] Replace the hardcoded production URL in child organization deletion with the configured API client/base URL.
- [ ] In `prohelper_land/src/pages/dashboard/MultiOrganizationPage.tsx`, replace the incomplete modal placeholder with working create-holding and add-child dialogs or remove the dead UI branch.
- [ ] Add Vitest/MSW coverage for holding detection and API paths used by multi-organization screens.
- [ ] Remove client-side fallback parsing for legacy response shapes.
- [ ] Make frontend types match the final OpenAPI schemas exactly.
- [ ] Add tests for each screen state backed by multi-organization API:
  - loading;
  - empty;
  - permission denied;
  - validation error;
  - successful mutation;
  - stale context after organization switch.

Verification:

```powershell
npx tsc --noEmit
npx vitest run src/**/__tests__/*multi* src/**/__tests__/*holding*
```

Do not run `npm run build` for `prohelper_land`.

---

## Task 8: Update OpenAPI and Local Workflow Documentation

- [ ] Update `prohelper/docs/openapi/lk/paths/multi_organization.yaml` to cover every canonical `multi-organization` route that remains after normalization.
- [ ] Update `prohelper/docs/openapi/lk/paths/index.yaml` and `prohelper/docs/openapi/lk/index.yaml` refs for every added or removed path.
- [ ] Add schemas for:
  - child organization lifecycle state;
  - safe delete/archive response;
  - child user role assignment;
  - report filter request/query shape;
  - LK pagination response shape for holding projects/contracts.
- [ ] Update `prohelper/docs/analysis/multi-organization-workflow-review.md`:
  - mark resolved issues with implementation commit scope;
  - keep unresolved business decisions as explicit risks, not invented workflow.
- [ ] Create `prohelper/docs/backend/multi-organization-workflow.md` with the final factual workflow:
  - create holding;
  - add child;
  - switch context;
  - manage child users;
  - archive/delete child;
  - reports and scope rules;
  - public holding API boundaries.
- [ ] If Notion documentation is required after local stabilization, create or update the workspace workflow page from this local document.
- [ ] Add a route-to-OpenAPI comparison check or test so future routing changes cannot bypass documentation.

Verification:

```powershell
rg -n "multi-organization/(analytics/summary|child-organizations/export|child-organizations/bulk-update)" docs/openapi prohelper_land/src
rg -n "organization_type.*holding" prohelper_land/src
```

Expected after fixes: no stale frontend/OpenAPI references to removed endpoints and no `organization_type === 'holding'` checks.

---

## Task 9: Final Quality Gates

- [ ] Run syntax checks on every changed PHP file:

```powershell
php -l app/Http/Controllers/Api/V1/Landing/MultiOrganizationController.php
php -l app/Services/Landing/MultiOrganizationService.php
php -l app/Services/Landing/ChildOrganizationUserService.php
php -l routes/api/v1/landing/multi_organization.php
```

- [ ] Run targeted backend tests:

```powershell
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization
```

- [ ] Run static analysis on touched backend areas:

```powershell
vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/Landing/MultiOrganizationController.php app/Services/Landing/MultiOrganizationService.php app/Services/Landing/ChildOrganizationUserService.php app/BusinessModules/Core/MultiOrganization --memory-limit=1G
```

- [ ] Run frontend typecheck and targeted tests:

```powershell
npx tsc --noEmit
npx vitest run src/**/__tests__/*multi* src/**/__tests__/*holding*
```

- [ ] Re-run route-list and compare with OpenAPI:

```powershell
php artisan route:list --path=multi-organization
php artisan route:list --path=holding
```

- [ ] Confirm no project rules were violated:
  - no `artisan migrate`;
  - no dev server unless requested;
  - no `npm run build` for `prohelper_land` or `prohelper_admin`;
  - no production write commands.
- [ ] Confirm no compatibility leftovers remain:

```powershell
rg -n "fallback|legacy|deprecated|alias|TODO|TBD|заглуш|временно|omitted for brevity" app routes docs/openapi ../prohelper_land/src
```

- [ ] Confirm every final route has feature coverage:

```powershell
php artisan test tests/Feature/Api/V1/Landing/MultiOrganization
```

Expected final state: backend tests pass, frontend typecheck/tests pass for touched multi-organization areas, OpenAPI matches real routes, and the problem registry has resolved/unresolved statuses.

---

## Recommended Execution Order

- [ ] Task 0: route/domain normalization.
- [ ] Task 1: tests first.
- [ ] Task 2: schema/model consistency.
- [ ] Task 3: lifecycle safety.
- [ ] Task 4: child user/RBAC consistency.
- [ ] Task 5: permissions/scope/response contracts.
- [ ] Task 6: reports/aggregates.
- [ ] Task 7: frontend consumers.
- [ ] Task 8: documentation/OpenAPI.
- [ ] Task 9: quality gates.

This order makes the breaking route/API decision explicit first, then puts the risky backend behavior under tests before touching frontend consumers and docs.
