# Workforce Production Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** довести контур "Персонал и трудозатраты" до production-ready состояния: убрать ощущение заготовки, закрыть жизненные циклы сотрудников, структуры, отсутствий, payroll source и выгрузок, проверить связи с production-labor, ролями и тарифами, добавить поведенческие тесты и исключить технические слова из пользовательского UI/API.

**Architecture:** backend остается в `prohelper/app/BusinessModules/Features/WorkforceManagement` как бизнес-фича пакета workforce-management. Production-labor не выносится отдельно: он продолжает быть источником фактической выработки и табельных данных для workforce. Admin UI остается в `prohelper_admin/src/pages/Workforce`, но разбивается на рабочие разделы и компоненты с понятными действиями, статусами, фильтрами и детальными панелями. API-ответы должны отдавать бизнес-понятные labels, workflow summary, blockers и next actions, чтобы UI не угадывал смысл статусов.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL, AdminResponse, React/Vite/TypeScript, Vitest, MSW-style сервисные моки. Миграции не запускать вручную. Dev servers и `npm run build` для admin не запускать.

---

## Task 1: Зафиксировать behavioral baseline по backend workflow

**Files:**
- Create: `prohelper/tests/Feature/Api/V1/Admin/WorkforceProductionReadinessTest.php`
- Modify if needed: `prohelper/tests/Feature/Api/V1/Admin/WorkforceProWorkflowTest.php`
- Modify if needed: `prohelper/tests/Feature/Api/V1/Admin/WorkforceCorporateWorkflowTest.php`

**Step 1: Add failing tests first**

Cover at least these scenarios:

- `viewer_cannot_mutate_workforce_employees`: user with read-only admin role can read allowed workforce data but cannot create/update/dismiss employees.
- `employee_lifecycle_closes_active_assignment_on_dismissal`: dismissal either blocks unsafe state or closes active assignment with correct end date.
- `employee_cannot_be_dismissed_before_hire_date`: service returns translated business error, not raw validation/exception text.
- `active_user_assignment_is_unique_per_organization`: cannot create two active employee cards linked to the same platform user in one organization.
- `payroll_periods_do_not_overlap`: overlapping payroll periods for the same organization are rejected with a business message.
- `locked_payroll_period_rejects_stale_source`: source changes after validation/lock are detected before export.
- `export_package_status_has_guarded_transitions`: export cannot jump from created directly to accepted.
- `workforce_payload_has_business_labels`: list/detail payloads include labels and workflow info, not only raw ids/statuses.

Expected command:

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper
php artisan test --filter=WorkforceProductionReadinessTest
```

Expected result before implementation: tests fail for real missing behavior, not because of factory/setup errors.

**Step 2: Keep tests focused on behavior**

Use existing factories and authenticated admin API helpers from nearby tests. Do not mock `AuthorizationService` for permission integration checks unless the test is explicitly unit-level.

---

## Task 2: Нормализовать API-контракт workforce

**Files:**
- Create: `prohelper/app/BusinessModules/Features/WorkforceManagement/Http/Resources/WorkforceEmployeeResource.php`
- Create: `prohelper/app/BusinessModules/Features/WorkforceManagement/Http/Resources/WorkforcePayrollPeriodResource.php`
- Create: `prohelper/app/BusinessModules/Features/WorkforceManagement/Http/Resources/WorkforceExportPackageResource.php`
- Create if needed: `prohelper/app/BusinessModules/Features/WorkforceManagement/Support/WorkforceStatusLabels.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceEmployeeService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceProService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceCorporateService.php`
- Modify: `prohelper/lang/ru/workforce.php`

**Implementation requirements:**

- Keep raw ids/statuses available for machines, but add user-facing fields:
  - `status_label`
  - `status_tone`
  - `workflow_summary`
  - `next_actions`
  - `blockers`
  - relation labels such as `employee_label`, `department_label`, `position_label`, `project_label`, `payroll_period_label`
- Do not expose technical words in messages or labels: `fallback`, `legacy`, `payload`, `dto`, `exception`, `sql`, `constraint`, raw status codes, raw issue codes.
- Replace generic user messages like `record_created` in workforce flows with business-specific translation keys.
- Keep response wrapper as `AdminResponse`; do not return `response()->json()` directly.

**Verification:**

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper
php artisan test --filter=WorkforceProductionReadinessTest
php artisan test --filter=WorkforceProWorkflowTest
php artisan test --filter=WorkforceCorporateWorkflowTest
```

---

## Task 3: Закрыть employee lifecycle

**Files:**
- Modify: `prohelper/app/Domain/HR/Models/WorkforceEmployee.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceEmployeeService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Http/Requests/WorkforceEmployeeRequest.php`
- Modify: `prohelper/lang/ru/workforce.php`

**Implementation requirements:**

- Enforce unique active employee card per linked `user_id` inside organization.
- Validate hire/dismissal dates as a business lifecycle, not only field validation.
- On dismissal:
  - block dismissal if payroll period is locked/open in a way that would corrupt source data;
  - close active staff assignment with the dismissal date when safe;
  - return blockers when dismissal is not allowed.
- Add safe rehire behavior only if current schema supports it without ambiguous historical records. Otherwise reject rehire into an existing dismissed card and require a new effective card with explicit business message.
- Ensure employees from another organization cannot be referenced from pro/corporate endpoints.

**Tests:**

Run the baseline test plus the existing workforce tests.

---

## Task 4: Закрыть структуру и назначения

**Files:**
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceProService.php`
- Create if useful: `prohelper/app/BusinessModules/Features/WorkforceManagement/Support/WorkforceAssignmentGuard.php`
- Modify: `prohelper/tests/Feature/Api/V1/Admin/WorkforceProductionReadinessTest.php`

**Implementation requirements:**

- Guard assignments against:
  - dismissed/inactive employees;
  - inactive departments, positions, staff units and schedules;
  - overlapping assignments for one employee;
  - staff unit capacity overflow by headcount/rate;
  - dates outside the staff unit lifecycle.
- Deactivation/closure of department, position or staff unit must be blocked if active assignments depend on it.
- Return readable blockers that name the affected employee/position/unit.
- Include relation labels in list payloads so admin UI never renders `#id` as the primary text.

**Tests:**

- Add assignment overlap test.
- Add headcount capacity test.
- Add structure deactivation with active assignment test.
- Add cross-organization reference test.

---

## Task 5: Довести отсутствия, командировки и кадровые приказы

**Files:**
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceProService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Controllers/WorkforceProController.php`
- Modify: `prohelper/lang/ru/workforce.php`

**Implementation requirements:**

- Replace generic `setStatus` behavior with explicit guarded workflow transitions.
- Absences:
  - draft -> approved -> cancelled;
  - reject approval for dismissed employee;
  - reject overlap with another approved absence;
  - show conflict as business blocker.
- Business trips:
  - draft -> approved -> completed/cancelled;
  - reject overlap with approved absence unless business rules explicitly allow it.
- HR orders:
  - draft -> approved -> applied/cancelled;
  - applying an order must either update linked workforce data or be blocked until the target operation is implemented.
- UI/API labels must use Russian business words: "Черновик", "Согласовано", "Отменено", "Применено", not raw codes.

**Tests:**

- Add lifecycle transition tests for absences and orders.
- Add conflict tests for absence/trip overlap.

---

## Task 6: Сделать payroll source надежным источником для зарплатной системы

**Files:**
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceProService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceCorporateService.php`
- Modify: `prohelper/tests/Feature/Api/V1/Admin/WorkforceProWorkflowTest.php`
- Modify: `prohelper/tests/Feature/Api/V1/Admin/WorkforceCorporateWorkflowTest.php`

**Implementation requirements:**

- Prevent overlapping payroll periods per organization.
- Rebuild source only while period is draft or validated with explicit invalidation of previous validation result.
- Store and compare source hash before lock/export.
- Lock only after:
  - source rows exist;
  - no blocking validation issues;
  - required accounting mappings exist for involved projects/cost objects;
  - all referenced employees still have valid assignments for the source dates.
- Add issue labels and remediation actions:
  - "Не найдено назначение сотрудника"
  - "Не назначен график работы"
  - "День попадает на отсутствие"
  - "Не настроена статья затрат"
- Ensure source rows are tied back to production-labor entries with stable links and labels.

**Tests:**

- Add stale source lock/export test.
- Add blocking validation issue test.
- Add accounting mapping requirement test.
- Add source relation label test.

---

## Task 7: Закрыть export lifecycle и бухгалтерские связи

**Files:**
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Services/WorkforceCorporateService.php`
- Modify: `prohelper/app/BusinessModules/Features/WorkforceManagement/Controllers/WorkforceCorporateController.php`
- Modify: `prohelper/lang/ru/workforce.php`

**Implementation requirements:**

- Export package transitions:
  - created -> sent -> accepted;
  - created -> rejected is allowed only for internal cancellation/replacement if business label is clear;
  - accepted/rejected are terminal unless a new replacement export is created.
- Reject export creation when payroll period is not locked or source hash is stale.
- Include generated file metadata with business labels, not internal storage wording.
- Accounting mappings must be unique by organization/scope/cost account and must not point to inactive cost accounts if the project has that concept.
- Return blockers that make it clear which mapping or period prevents export.

**Tests:**

- Add invalid transition test.
- Add terminal status test.
- Add stale period export test.

---

## Task 8: Пересобрать Admin UI как рабочий production-раздел

**Files:**
- Modify: `prohelper_admin/src/pages/Workforce/WorkforcePage.tsx`
- Create: `prohelper_admin/src/pages/Workforce/components/WorkforceRegistry.tsx`
- Create: `prohelper_admin/src/pages/Workforce/components/WorkforceDetailPanel.tsx`
- Create: `prohelper_admin/src/pages/Workforce/components/WorkforceWorkflowBanner.tsx`
- Create: `prohelper_admin/src/pages/Workforce/components/WorkforceActionBar.tsx`
- Modify: `prohelper_admin/src/services/workforceService.ts`
- Modify: `prohelper_admin/src/types/workforce.ts`
- Modify: `prohelper_admin/src/pages/Workforce/WorkforcePage.test.tsx`
- Create if useful: `prohelper_admin/src/services/workforceService.test.ts`

**Implementation requirements:**

- Keep one top-level admin section "Персонал", but split page content into work areas:
  - "Сотрудники"
  - "Структура"
  - "Графики и отсутствия"
  - "Начисления"
  - "Выгрузки"
  - "Настройки учета"
- Remove decorative tariff chips and repeated pricing content from the operational page.
- Render human labels from API; never show raw `draft`, `validated`, `scope_type`, `issue_code`, `#id` as primary UI text.
- Add filters/search for employees, assignments, payroll periods and exports.
- Add detail panel for selected record with:
  - lifecycle status;
  - related department/position/project;
  - blockers;
  - next allowed actions;
  - linked source rows for payroll/export.
- Hide unavailable actions by permission and show only business-readable disabled reasons when action is visible but blocked.
- Do not request corporate endpoints unless user has corporate/settings permissions.
- All empty/error states must be business-readable.

**Behavioral UI tests:**

- `renders workforce without technical status words`
- `shows employee lifecycle and dismissal blockers`
- `hides corporate controls without corporate permission`
- `does not fetch accounting mappings without settings permission`
- `shows payroll validation blockers and next actions`
- `renders export lifecycle labels`

**Verification:**

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper_admin
npx vitest run src/pages/Workforce/WorkforcePage.test.tsx src/services/workforceService.test.ts src/components/layout/SidebarMenu.test.tsx
npx tsc --noEmit --pretty false
```

---

## Task 9: Проверить тарифные уровни и права

**Files:**
- Modify: `prohelper/config/ModuleList/features/workforce-management.json`
- Modify: `prohelper/config/RoleDefinitions/admin/web_admin.json`
- Modify: `prohelper/config/RoleDefinitions/admin/admin_viewer.json`
- Modify if needed: `prohelper/app/Domain/Authorization/Services/PermissionResolver.php`
- Modify: `prohelper/tests/Feature/Api/V1/Admin/AdminAccessGuardTest.php`

**Implementation requirements:**

- Tiers must upgrade each other without conflict:
  - Start: employee cards, work orders/shift tasks, actual production output.
  - Pro: Start + departments, positions, staff units, schedules, absences, payroll source.
  - Corporate: Pro + period locks, accounting mappings, export packages/files.
- Split read/create/manage permissions where current `workforce.employees.basic` allows mutations for read-only roles.
- Viewer role must not mutate workforce data.
- Admin role must retain required management permissions.
- Route middleware must match permission semantics.

**Tests:**

- Add viewer mutation denial test.
- Add tier permission visibility test if existing module availability tests support it.

---

## Task 10: Финальная проверка связей и качества

**Backend verification:**

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper
php -l app/BusinessModules/Features/WorkforceManagement/Services/WorkforceEmployeeService.php
php -l app/BusinessModules/Features/WorkforceManagement/Services/WorkforceProService.php
php -l app/BusinessModules/Features/WorkforceManagement/Services/WorkforceCorporateService.php
php artisan test --filter=Workforce
php artisan test --filter=ProductionLaborWorkflowTest
php artisan test --filter=AdminAccessGuardTest
vendor/bin/phpstan analyse app/BusinessModules/Features/WorkforceManagement app/Domain/HR app/Domain/Authorization/Services/PermissionResolver.php --memory-limit=1G --no-progress
```

**Admin verification:**

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper_admin
npx vitest run src/pages/Workforce/WorkforcePage.test.tsx src/services/workforceService.test.ts src/components/layout/SidebarMenu.test.tsx
npx tsc --noEmit --pretty false
```

**Manual review checklist:**

- No user-facing technical words in workforce UI.
- No raw status codes as primary labels.
- No `#id` as primary record title when relation label exists.
- No unguarded lifecycle jump.
- No cross-organization references.
- No payroll lock/export on stale source.
- No corporate endpoint calls without corporate/settings permissions.
- Production-labor payroll source links remain intact.
- Start/Pro/Corporate increase capability without conflicting behavior.

---

## Execution Order

1. Implement Tasks 1-3 first: backend tests, contract labels and employee lifecycle.
2. Implement Tasks 4-7 next: pro/corporate lifecycle, payroll and export guards.
3. Implement Tasks 8-9 after backend contract is stable: admin UI and permissions.
4. Run Task 10 verification before any commit or push.

