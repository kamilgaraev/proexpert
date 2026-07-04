# Safety Management Full Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** превратить текущий модуль «Охрана труда» из набора реестров в полноценную систему допуска людей к работам, связанную с объектами, видами работ, нарядами, СИЗ, медосмотрами, обучением, проверками, инцидентами, документами, мобильным приложением и AI/RAG.

**Execution status, 2026-07-04:** реализован основной контур refactor: backend compliance service, карточка ОТ сотрудника, участники нарядов-допусков, проверки и замечания, документные черновики, production-labor safety gate, admin contracts/UI, mobile read-модели и field UI. Локально подтверждены `php -l`, targeted PHPStan, targeted admin Vitest и `git diff --check`. Полные Laravel feature tests не запускались из-за проектного запрета на локальные DB-сценарии; `flutter analyze/test` не запускались, потому что Dart/Flutter/FVM отсутствуют в PATH; `npx tsc --noEmit` остановлен существующей ошибкой `src/components/layout/Logo.tsx`, не связанной с модулем ОТ.

**Architecture:** существующие сущности `SafetyWorkPermit`, `SafetyBriefing`, `SafetyIncident`, `SafetyViolation`, `SafetyCorrectiveAction` остаются совместимыми. Новый доменный центр - `SafetyComplianceService`, который по контексту `organization + employee/user + project + work_type/work_category + date + optional work_order_line/permit` возвращает статус допуска, причины блокировки, предупреждения и требуемые действия. Admin и mobile не пересчитывают бизнес-правила, а отображают backend-контракт.

**Tech Stack:** Laravel 11, PostgreSQL JSONB, AdminResponse/MobileResponse, React 18 + Vite + TypeScript + MUI + React Query, Flutter/Dart + Riverpod, Vitest/MSW, Laravel feature tests, Flutter tests.

---

## Project Constraints

- Работать в `main` внутри репозиториев `prohelper`, `prohelper_admin`, `prohelpers_mobile`.
- Не трогать существующие чужие изменения:
  - `prohelper/app/Http/Resources/Api/V1/UserResource.php`
  - `prohelper/app/Http/Responses/Auth/ProfileResponse.php`
  - `prohelper/tests/Feature/Api/V1/Landing/Auth/LandingProfileResponseTest.php`
  - `prohelper_admin/src/pages/TimeTracking/TimeEntries.tsx`
  - `prohelper_admin/src/pages/TimeTracking/TimeEntriesPage.test.tsx`
- Не запускать миграции, seeders, tinker, DB-команды и dev-серверы.
- Не запускать `npm run build` в `prohelper_admin`.
- Все пользовательские PHP-сообщения - через `trans_message('safety_management...')`.
- Новые PHP-файлы - `declare(strict_types=1);`.
- Все API-ответы - через `AdminResponse` или `MobileResponse`.

## Target Capability Map

1. Дашборд ОТ: допущены, не допущены, частично допущены, истекают документы, открытые нарушения, инциденты, просроченные мероприятия, рисковые объекты.
2. Реестр сотрудников и допусков: карточка ОТ сотрудника, виды работ, требования, фактические записи, статус допуска.
3. Матрица требований: должность/вид работ/проект/риск -> инструктажи, обучение, первая помощь, СИЗ, медосмотр, удостоверения, наряд-допуск, целевой инструктаж.
4. Инструктажи: вводный, первичный, повторный, внеплановый, целевой; участники; подпись; срок следующего инструктажа.
5. Обучение и проверка знаний: программы, протоколы, удостоверения, сроки, комиссии, назначение по должности.
6. СИЗ: нормы по должности/работам, выдача, срок носки, просрочка, блокировка допуска.
7. Медосмотры: прохождение, срок действия, результат, ограничения, скан/файл, предупреждения.
8. Наряды-допуски: исполнители, ответственные, зона, вид работ, контрольные меры, целевой инструктаж, проверка допуска всех исполнителей, открытие/приостановка/закрытие.
9. Проверки и чек-листы: шаблоны, обходы объекта, фотофиксация, нарушения, ответственные, сроки устранения.
10. Нарушения, инциденты, несчастные случаи: расследование, корректирующие действия, аналитика.
11. Документы: автоматическая подготовка журналов, карточек СИЗ, протоколов, актов, отчетов.
12. AI/RAG: помощник по ОТ, генерация чек-листов по виду работ, черновик акта нарушения, поиск по базе знаний. AI не принимает финальное решение о допуске.

---

## File Structure

### Backend `prohelper`

Create:
- `app/BusinessModules/Features/SafetyManagement/DTOs/SafetyComplianceContext.php`
- `app/BusinessModules/Features/SafetyManagement/DTOs/SafetyComplianceResult.php`
- `app/BusinessModules/Features/SafetyManagement/DTOs/SafetyComplianceRequirementResult.php`
- `app/BusinessModules/Features/SafetyManagement/Services/SafetyComplianceService.php`
- `app/BusinessModules/Features/SafetyManagement/Services/SafetyDashboardService.php`
- `app/BusinessModules/Features/SafetyManagement/Services/SafetyDocumentTemplateService.php`
- `app/BusinessModules/Features/SafetyManagement/Services/SafetyChecklistService.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyRequirementMatrix.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyEmployeeRequirement.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyTrainingRecord.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyMedicalExam.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyPpeNorm.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyPpeIssue.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyWorkPermitParticipant.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyInspectionTemplate.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyInspection.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyInspectionFinding.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyComplianceResultResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyEmployeeRequirementResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyTrainingRecordResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyMedicalExamResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyPpeIssueResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyInspectionResource.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyDashboardResource.php`
- `app/BusinessModules/Features/SafetyManagement/migrations/2026_07_04_000001_create_safety_compliance_tables.php`
- `app/BusinessModules/Features/SafetyManagement/migrations/2026_07_04_000002_extend_safety_work_permits_for_participants.php`
- `tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php`
- `tests/Feature/Api/V1/Admin/SafetyInspectionWorkflowTest.php`
- `tests/Feature/Api/V1/Admin/SafetyProductionLaborGateTest.php`
- `tests/Feature/Api/V1/Mobile/SafetyMobileWorkflowTest.php`

Modify:
- `app/BusinessModules/Features/SafetyManagement/Services/SafetyManagementService.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Controllers/SafetyManagementController.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Controllers/Mobile/SafetyManagementController.php`
- `app/BusinessModules/Features/SafetyManagement/Http/Resources/SafetyWorkPermitResource.php`
- `app/BusinessModules/Features/SafetyManagement/Models/SafetyWorkPermit.php`
- `app/BusinessModules/Features/SafetyManagement/routes.php`
- `app/BusinessModules/Features/ProductionLabor/Services/ProductionLaborService.php`
- `app/BusinessModules/Features/ProductionLabor/Models/ProductionLaborWorkOrderLine.php`
- `lang/ru/safety_management.php`
- `lang/ru/production_labor.php`
- `config/ModuleList/features/safety-management.json`
- `config/RoleDefinitions/**/*.json` only if missing permissions are required by the current project pattern.

### Admin `prohelper_admin`

Create:
- `src/pages/Safety/SafetyDashboard.tsx`
- `src/pages/Safety/SafetyEmployeesSection.tsx`
- `src/pages/Safety/SafetyRequirementsSection.tsx`
- `src/pages/Safety/SafetyPermitsSection.tsx`
- `src/pages/Safety/SafetyBriefingsSection.tsx`
- `src/pages/Safety/SafetyTrainingSection.tsx`
- `src/pages/Safety/SafetyPpeSection.tsx`
- `src/pages/Safety/SafetyMedicalSection.tsx`
- `src/pages/Safety/SafetyInspectionsSection.tsx`
- `src/pages/Safety/SafetyIncidentsSection.tsx`
- `src/pages/Safety/SafetyDocumentsSection.tsx`
- `src/pages/Safety/SafetyAiAssistantPanel.tsx`
- `src/pages/Safety/components/SafetyStatusChip.tsx`
- `src/pages/Safety/components/SafetyBlockerList.tsx`
- `src/pages/Safety/components/SafetyActionDialog.tsx`
- `src/pages/Safety/components/SafetyRequirementChecklist.tsx`
- `src/pages/Safety/SafetyManagementPage.test.tsx`
- `src/services/safetyManagementService.test.ts`

Modify:
- `src/pages/Safety/SafetyManagementPage.tsx`
- `src/services/safetyManagementService.ts`
- `src/services/apiConstants.ts`
- `src/types/safetyManagement.ts`
- `src/constants/permissions.ts`
- `src/components/layout/SidebarMenu.tsx` only if navigation labels or access checks need alignment.

### Mobile `prohelpers_mobile`

Create:
- `lib/features/safety/presentation/safety_employee_admission_screen.dart`
- `lib/features/safety/presentation/safety_inspection_screen.dart`
- `lib/features/safety/presentation/safety_requirement_widgets.dart`
- `test/features/safety/data/safety_model_test.dart`
- `test/features/safety/domain/safety_provider_test.dart`
- `test/features/safety/presentation/safety_screen_test.dart`

Modify:
- `lib/features/safety/data/safety_model.dart`
- `lib/features/safety/data/safety_repository.dart`
- `lib/features/safety/domain/safety_provider.dart`
- `lib/features/safety/presentation/safety_screen.dart`
- `lib/features/production_labor/data/production_labor_model.dart`
- `lib/features/production_labor/data/production_labor_repository.dart`
- `lib/features/production_labor/presentation/production_labor_screen.dart`
- `lib/core/navigation/mobile_navigation_registry.dart`

### Docs

Create:
- `docs/workflows/safety-management/workflow.md`
- `docs/workflows/safety-management/roles-and-access.md`
- `docs/workflows/safety-management/status-model.md`
- `docs/workflows/safety-management/admin-ux.md`
- `docs/workflows/safety-management/mobile-ux.md`
- `docs/workflows/safety-management/operations-guide.md`

---

## Task 1: Backend Compliance Schema And Domain Types

**Files:**
- Create migration `prohelper/app/BusinessModules/Features/SafetyManagement/migrations/2026_07_04_000001_create_safety_compliance_tables.php`
- Create DTOs and models listed in backend file structure
- Test: `prohelper/tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php`

- [ ] **Step 1: Write failing compliance matrix test**

Add `test_employee_is_not_admitted_when_required_medical_exam_and_ppe_are_missing`.

Test setup:
- create organization context with admin auth
- create project
- create `WorkforceEmployee`
- create `SafetyRequirementMatrix` for `work_category = height_work`
- require: `briefing:target`, `training:occupational_safety`, `training:first_aid`, `training:ppe`, `medical_exam`, `ppe:harness`, `ppe:helmet`
- call `POST /api/v1/admin/safety-management/admission/check`
- assert status `not_admitted`
- assert blocker codes include `medical_exam_missing`, `ppe_missing`
- assert no production table write is needed for the check

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php --filter=test_employee_is_not_admitted_when_required_medical_exam_and_ppe_are_missing
```

Expected: FAIL because endpoint/service/table does not exist.

- [ ] **Step 2: Create compliance tables**

Migration must create:
- `safety_requirement_matrices`
- `safety_employee_requirements`
- `safety_training_records`
- `safety_medical_exams`
- `safety_ppe_norms`
- `safety_ppe_issues`

Required columns:
- all tables: `id`, `organization_id`, timestamps with timezone, soft deletes where records are lifecycle records
- matrices: `project_id nullable`, `position_name nullable`, `work_type_id nullable`, `work_category`, `risk_level`, `requirements jsonb`, `is_active`, `effective_from`, `effective_until nullable`
- employee requirements: `employee_id`, `user_id nullable`, `project_id nullable`, `work_type_id nullable`, `work_category`, `requirement_code`, `requirement_type`, `source_type`, `source_id nullable`, `valid_from nullable`, `valid_until nullable`, `status`, `metadata jsonb`
- training: `employee_id`, `user_id nullable`, `program_code`, `program_name`, `training_type`, `completed_at`, `valid_until`, `result`, `document_number nullable`, `protocol_number nullable`, `metadata jsonb`
- medical exams: `employee_id`, `exam_type`, `completed_at`, `valid_until`, `result`, `restrictions nullable`, `file_id nullable`, `metadata jsonb`
- PPE norms: `position_name nullable`, `work_category nullable`, `ppe_code`, `ppe_name`, `wear_period_days nullable`, `is_required`
- PPE issues: `employee_id`, `ppe_code`, `ppe_name`, `issued_at`, `valid_until nullable`, `quantity`, `status`, `warehouse_operation_id nullable`, `metadata jsonb`

Indexes:
- organization + employee + status
- organization + work_category + is_active
- organization + employee + valid_until
- organization + project + work_category

- [ ] **Step 3: Add models and relationships**

Models must use module namespace, fillables, casts, `scopeForOrganization`, and relations to `Organization`, `Project`, `WorkforceEmployee`, `User` where applicable.

- [ ] **Step 4: Implement DTO skeletons**

`SafetyComplianceContext` fields:
- `organizationId`, `employeeId`, `userId`, `projectId`, `workTypeId`, `workCategory`, `date`, `positionName`, `permitId`, `workOrderLineId`

`SafetyComplianceResult` fields:
- `employeeId`, `status`, `statusLabel`, `blocked`, `expiresSoon`, `requirements`, `blockers`, `warnings`, `checkedAt`

`SafetyComplianceRequirementResult` fields:
- `code`, `type`, `label`, `status`, `severity`, `sourceType`, `sourceId`, `validUntil`, `message`

- [ ] **Step 5: Run RED test again**

Expected: still FAIL because service/endpoint logic is not implemented, but migration/model class errors are gone.

---

## Task 2: Backend SafetyComplianceService

**Files:**
- Create `SafetyComplianceService.php`
- Create `SafetyComplianceResultResource.php`
- Modify controller/routes
- Test: `SafetyComplianceWorkflowTest.php`

- [ ] **Step 1: Extend failing tests**

Add cases:
- admitted when all required records are valid
- partially admitted when only warning records expire inside 14 days
- matrix can be scoped by project and falls back to organization-wide default
- inactive matrix is ignored
- dismissed/inactive employee is never admitted

Run each filter and verify failure before implementation.

- [ ] **Step 2: Implement requirement resolution**

`SafetyComplianceService::check(SafetyComplianceContext $context): SafetyComplianceResult` must:
- load active matrices for organization/project/work category/position/work type
- merge requirements without duplicates
- check employee employment status
- check training records by `program_code`
- check briefing requirements using `SafetyBriefingParticipant` by user or employee mapping
- check medical exams
- check PPE issues
- check active safety permit when requirement code is `work_permit`
- return deterministic sorted requirement results

Status rules:
- `admitted` if no critical blockers and no expired required records
- `partial` if no blockers but at least one required record expires in 14 days or optional requirement missing
- `not_admitted` if any required record missing/expired/negative result or employee inactive

- [ ] **Step 3: Add admin endpoint**

Routes:
- `GET /api/v1/admin/safety-management/admissions`
- `POST /api/v1/admin/safety-management/admission/check`
- `GET /api/v1/admin/safety-management/employees/{employee}/admission-card`

Permissions:
- read endpoints: `safety-management.view`
- check endpoint: `safety-management.view`

- [ ] **Step 4: Add mobile read endpoint**

Routes:
- `GET /api/v1/mobile/safety-management/my-admission`
- `POST /api/v1/mobile/safety-management/admission/check`

Mobile endpoint must restrict records to current user or current project context.

- [ ] **Step 5: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php
php -l app/BusinessModules/Features/SafetyManagement/Services/SafetyComplianceService.php
```

Expected: PASS for compliance tests, no syntax errors.

---

## Task 3: Employee Safety Records API

**Files:**
- Modify `SafetyManagementService.php`, controllers, routes
- Create resources for training, medical, PPE, employee requirements
- Test: `SafetyComplianceWorkflowTest.php`

- [ ] **Step 1: Write failing CRUD/workflow tests**

Cases:
- create training record updates admission status
- expired training blocks admission
- medical exam result `not_fit` blocks admission
- medical exam result `fit_with_restrictions` returns partial with warning
- PPE issue with expired `valid_until` blocks admission
- organization scoping rejects foreign employee/project IDs

- [ ] **Step 2: Add endpoints**

Admin:
- `GET /safety-management/employees`
- `GET /safety-management/employees/{employee}/admission-card`
- `POST /safety-management/training-records`
- `POST /safety-management/medical-exams`
- `POST /safety-management/ppe-issues`
- `POST /safety-management/requirement-matrices`
- `PATCH /safety-management/requirement-matrices/{id}`

Mobile:
- read admission card
- create violation/inspection records only, no matrix management

- [ ] **Step 3: Add translations**

Add user-facing labels and errors for all blocker codes:
- `employee_inactive`
- `matrix_not_configured`
- `training_missing`
- `training_expired`
- `briefing_missing`
- `medical_exam_missing`
- `medical_exam_expired`
- `medical_exam_not_fit`
- `medical_exam_restricted`
- `ppe_missing`
- `ppe_expired`
- `work_permit_missing`
- `target_briefing_missing`

- [ ] **Step 4: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php
php -l app/BusinessModules/Features/SafetyManagement/Http/Controllers/SafetyManagementController.php
```

---

## Task 4: Work Permit Participants And Open/Close Controls

**Files:**
- Migration `2026_07_04_000002_extend_safety_work_permits_for_participants.php`
- Model `SafetyWorkPermitParticipant.php`
- Modify `SafetyWorkPermit`, resource, service
- Test: `SafetyComplianceWorkflowTest.php`

- [ ] **Step 1: Write failing permit participant tests**

Cases:
- permit cannot activate if participant has blockers
- permit can activate after blockers are resolved
- permit resource returns participants with admission status and blockers
- target briefing can satisfy permit participant requirement

- [ ] **Step 2: Add participant table**

`safety_work_permit_participants`:
- `permit_id`, `organization_id`, `employee_id nullable`, `user_id nullable`, `external_name nullable`, `role_name nullable`, `admission_status`, `admission_checked_at`, `admission_blockers jsonb`, `signed_at nullable`, `metadata jsonb`

- [ ] **Step 3: Update create permit contract**

Allow `participants` array in admin create/update payload:
- internal employee participant
- user participant
- external participant

External participants are allowed only if marked `external_controlled = true` in metadata and do not count as automatically admitted.

- [ ] **Step 4: Enforce activation**

Before `activatePermit`, run `SafetyComplianceService` for every internal participant. If any blocker exists, return 422 with readable message and `problem_flags` in resource.

- [ ] **Step 5: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php --filter=permit
```

---

## Task 5: ProductionLabor Safety Gate

**Files:**
- Modify `ProductionLaborService.php`
- Modify production labor resources/types only where contract changes
- Test: `SafetyProductionLaborGateTest.php`

- [ ] **Step 1: Write failing production gate tests**

Cases:
- timesheet creation fails when line requires safety and employee is not admitted
- timesheet creation succeeds when employee is admitted and active permit is present
- output cannot be recorded for hazardous line without active permit
- error message is business-readable and does not mention internal DTO/payload names

- [ ] **Step 2: Replace reference-only gate**

Current logic checks only `safety_permit_reference`. Keep backward compatibility, but add:
- work category from line metadata or `work_type_id`
- employee admission check for every payroll employee entry
- active permit check by id or number

- [ ] **Step 3: Add resource fields**

Work order line resource should expose:
- `requires_safety_permit`
- `work_category`
- `safety_requirements_summary`
- `safety_blockers_count`

- [ ] **Step 4: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyProductionLaborGateTest.php
php artisan test tests/Feature/Api/V1/Admin/ProductionLaborWorkflowTest.php
```

---

## Task 6: Inspections, Checklists, Findings

**Files:**
- Create inspection models/resources/service
- Modify routes/controllers
- Test: `SafetyInspectionWorkflowTest.php`

- [ ] **Step 1: Write failing inspection tests**

Cases:
- template with checklist items can be created
- inspection can be started and completed
- failed checklist item creates safety violation
- finding can create corrective action with due date and assignee
- mobile can create inspection with findings

- [ ] **Step 2: Create inspection tables**

Tables:
- `safety_inspection_templates`: project nullable, title, inspection_type, checklist_items jsonb, is_active
- `safety_inspections`: project, conducted_by_user, template, status, started_at, completed_at, location_name, score, metadata
- `safety_inspection_findings`: inspection, violation nullable, severity, title, description, checklist_item_code nullable, status, due_date, assigned_to_user_id, evidence_files jsonb, metadata

- [ ] **Step 3: Implement service**

`SafetyChecklistService` must:
- create/update templates
- start inspection
- complete inspection
- create findings
- optionally create `SafetyViolation` from finding

- [ ] **Step 4: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyInspectionWorkflowTest.php
php artisan test tests/Feature/Api/V1/Mobile/SafetyMobileWorkflowTest.php --filter=inspection
```

---

## Task 7: Dashboard, Documents, AI/RAG Hooks

**Files:**
- Create `SafetyDashboardService.php`
- Create `SafetyDocumentTemplateService.php`
- Modify AI assistant source mapping if existing pattern supports entity sources
- Test: `SafetyComplianceWorkflowTest.php`, existing AI assistant tests where touched

- [ ] **Step 1: Write failing dashboard test**

Assert dashboard returns:
- `employees_total`
- `employees_admitted`
- `employees_not_admitted`
- `expiring_training_count`
- `expiring_medical_count`
- `expired_ppe_count`
- `open_permits`
- `open_violations`
- `open_incidents`
- `overdue_corrective_actions`
- `high_risk_projects`

- [ ] **Step 2: Implement dashboard endpoint**

Admin route:
- `GET /api/v1/admin/safety-management/dashboard`

Mobile route:
- `GET /api/v1/mobile/safety-management/dashboard`

- [ ] **Step 3: Implement document draft endpoints**

Admin routes:
- `POST /safety-management/documents/briefing-journal/draft`
- `POST /safety-management/documents/ppe-card/draft`
- `POST /safety-management/documents/violation-act/draft`

The endpoint returns structured draft data, not a final PDF. File generation can later use existing document export services.

- [ ] **Step 4: Implement AI helper boundaries**

Expose AI-ready context:
- safety entity type
- safety work category
- requirement result list
- source document references

AI may generate checklist or act draft, but final status remains manual/system-rule based.

- [ ] **Step 5: Verify**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php --filter=dashboard
```

---

## Task 8: Admin Contract And UI Refactor

**Files:**
- Admin files listed above
- Tests: `SafetyManagementPage.test.tsx`, `safetyManagementService.test.ts`

- [ ] **Step 1: Write failing service normalization tests**

Cases:
- dashboard response normalizes from AdminResponse
- admissions list handles paginated response
- admission card handles missing optional arrays
- inspection list handles empty data

Run:
```powershell
npx vitest run src/services/safetyManagementService.test.ts
```

Expected: FAIL before service extension.

- [ ] **Step 2: Extend TypeScript contracts**

Add types:
- `SafetyDashboard`
- `SafetyAdmissionCard`
- `SafetyRequirementResult`
- `SafetyEmployeeSafetyStatus`
- `SafetyTrainingRecord`
- `SafetyMedicalExam`
- `SafetyPpeIssue`
- `SafetyRequirementMatrix`
- `SafetyInspection`
- `SafetyInspectionFinding`
- `SafetyDocumentDraft`
- `SafetyAiContext`

- [ ] **Step 3: Extend service layer**

Add methods:
- `getDashboard`
- `listAdmissions`
- `checkAdmission`
- `getEmployeeAdmissionCard`
- `createTrainingRecord`
- `createMedicalExam`
- `createPpeIssue`
- `listRequirementMatrices`
- `saveRequirementMatrix`
- `listInspections`
- `createInspection`
- `completeInspection`
- `createDocumentDraft`

- [ ] **Step 4: Split page into operational sections**

`SafetyManagementPage.tsx` should become orchestration only:
- tab/workspace state
- shared filters
- data queries
- permission checks
- render section components

Sections:
- Dashboard
- Employees and admissions
- Requirement matrix
- Permits
- Briefings and training
- PPE
- Medical
- Inspections and findings
- Incidents and violations
- Documents and AI helper

- [ ] **Step 5: Add UI tests**

Tests:
- renders dashboard blockers
- opens employee admission card
- shows not admitted reasons
- creates inspection finding
- permit action disabled when backend blockers exist

- [ ] **Step 6: Verify**

Run:
```powershell
npx tsc --noEmit
npx vitest run src/services/safetyManagementService.test.ts src/pages/Safety/SafetyManagementPage.test.tsx
```

Do not run `npm run build`.

---

## Task 9: Mobile Safety Field Workflow

**Files:**
- Mobile files listed above
- Tests listed above

- [ ] **Step 1: Write failing model/provider tests**

Cases:
- parses admission card with blockers
- parses dashboard counters
- parses inspections and findings
- provider load returns permits, incidents, violations, admission and dashboard
- permission denied remains readable

Run:
```powershell
flutter test test/features/safety/data/safety_model_test.dart test/features/safety/domain/safety_provider_test.dart
```

- [ ] **Step 2: Extend repository and models**

Add repository methods:
- `fetchDashboard`
- `fetchMyAdmission`
- `checkAdmission`
- `fetchInspections`
- `createInspection`
- `completeInspection`
- `createViolationActDraft`

Models must tolerate missing optional fields and use business-readable errors.

- [ ] **Step 3: Update mobile screen**

Add field-first zones:
- «Сегодня» with blockers and urgent actions
- «Мой допуск»
- «Наряды-допуски»
- «Проверка объекта»
- «Нарушения»
- «Инциденты»

- [ ] **Step 4: Integrate ProductionLabor warning**

When a work order line requires safety:
- show current admission status
- show active permit requirement
- prevent submission if backend returns safety blocker

- [ ] **Step 5: Verify**

Run:
```powershell
flutter analyze
flutter test test/features/safety/data/safety_model_test.dart test/features/safety/domain/safety_provider_test.dart test/features/safety/presentation/safety_screen_test.dart
```

Do not run full app build.

---

## Task 10: Workflow Documentation

**Files:**
- Docs listed above

- [ ] **Step 1: Create workflow package**

Write:
- purpose
- roles
- entities
- status model
- admission decision rules
- permit lifecycle
- inspection lifecycle
- incident lifecycle
- mobile scenarios
- admin scenarios
- exceptions and overrides

- [ ] **Step 2: Document rights**

Include:
- specialist OT
- foreman
- warehouse keeper
- HR/office
- project manager
- worker/mobile user
- admin

- [ ] **Step 3: Document AI boundary**

AI can:
- answer from knowledge base
- propose checklist
- draft violation act
- summarize inspection

AI cannot:
- mark employee admitted
- override missing legal requirement
- silently close violations

- [ ] **Step 4: Verify readability**

Read docs once as an operations manager:
- no endpoint-first language
- every status explained
- daily actions visible
- blocked states explained

---

## Task 11: Final Verification

- [ ] **Backend syntax**

Run `php -l` for every changed PHP file.

- [ ] **Backend tests**

Run:
```powershell
php artisan test tests/Feature/Api/V1/Admin/SafetyManagementWorkflowTest.php
php artisan test tests/Feature/Api/V1/Admin/SafetyComplianceWorkflowTest.php
php artisan test tests/Feature/Api/V1/Admin/SafetyInspectionWorkflowTest.php
php artisan test tests/Feature/Api/V1/Admin/SafetyProductionLaborGateTest.php
php artisan test tests/Feature/Api/V1/Mobile/SafetyMobileWorkflowTest.php
```

- [ ] **Static analysis**

Run changed-module PHPStan/Larastan command if available in `composer.json`. If the project command is unavailable or needs DB, report that clearly.

- [ ] **Admin**

Run:
```powershell
npx tsc --noEmit
npx vitest run src/services/safetyManagementService.test.ts src/pages/Safety/SafetyManagementPage.test.tsx
```

- [ ] **Mobile**

Run:
```powershell
flutter analyze
flutter test test/features/safety/data/safety_model_test.dart test/features/safety/domain/safety_provider_test.dart test/features/safety/presentation/safety_screen_test.dart
```

- [ ] **Browser QA**

If a local/admin URL is already available without starting a forbidden dev server, run gstack/browser smoke for `/safety-management`:
- page opens
- no console errors
- dashboard renders
- admission card opens
- action dialogs do not overflow

If no URL is available without starting a dev server, report browser QA as not run due project restrictions.

---

## Execution Order

1. Backend schema and compliance service.
2. Backend employee records and permit participants.
3. ProductionLabor safety gate.
4. Inspections and dashboard/doc/AI hooks.
5. Admin contracts and UI split.
6. Mobile contracts and field workflow.
7. Workflow documentation.
8. Full verification.

## Definition Of Done

- Backend can answer why a worker is admitted or blocked for a specific project/work category/date.
- ProductionLabor cannot start or record hazardous work for blocked employees.
- Work permits display and enforce participant admission.
- Admin has operational screens for every major ОТ contour from the idea.
- Mobile supports field checks, admissions, permits, violations and incidents.
- Documents and AI support exist as controlled helper flows.
- Tests cover admission decisions, permit gate, production gate, inspections, admin contracts and mobile parsing.
- Workflow docs explain roles, statuses, exceptions and UI usage.
