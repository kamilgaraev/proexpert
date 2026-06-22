# PHERP-133: SoD enforcement, exceptions и отчетность

## 1. Статус и цель

Документ фиксирует техническую спецификацию PHERP-133 по автоматическому контролю Segregation of Duties для финансов, закупок, склада, MDM, бюджетирования и RBAC. Это продолжение PHERP-132 и вход для последующей production-реализации.

Фактический scope задачи PHERP-133 в YouTrack: спроектировать enforcement и отчетность по SoD-нарушениям. В рамках этой задачи production-код, миграции, API, UI, `RoleDefinitions` и `lang/ru/permissions.php` не меняются. Новые permissions ниже считаются целевым контрактом будущей реализации, а не включенными runtime-правами.

Критерий готовности PHERP-133: для каждого SoD-нарушения понятно, где оно ловится, кто может разрешить исключение и как нарушение попадает в отчет.

## 2. Фактическая кодовая база

### 2.1. RBAC и переводы

- Базовая проверка прав находится в `App\Domain\Authorization\Services\AuthorizationService`.
- Назначения ролей пишутся через `AuthorizationService::assignRole()` с audit-событием `auth.role.assigned`.
- Системные роли описаны в `config/RoleDefinitions/{admin,lk,mobile,project,system}`.
- Пользовательские названия permissions задаются в `lang/ru/permissions.php`.
- В текущем RBAC нет информации об actor history конкретного документа: кто создал платеж, кто выбрал поставщика, кто принял материалы, кто применил MDM CR, кто закрывал период.

Вывод: SoD должен быть отдельным доменным слоем поверх RBAC/ABAC. `AuthorizationService` остается источником ответа "может ли пользователь выполнить действие в принципе", а SoD отвечает на вопрос "может ли этот пользователь выполнить это действие над этим объектом с учетом его истории".

### 2.2. Платежи

Ключевые маршруты находятся в `app/BusinessModules/Core/Payments/routes.php` под `/api/v1/admin/payments`.

Точки workflow:

- `PaymentDocumentController::store` -> `PaymentDocumentService::create`;
- `PaymentDocumentController::submit` -> `PaymentDocumentService::submit`;
- `PaymentApprovalController::approve` -> `ApprovalWorkflowService::approveByUser`;
- `PaymentDocumentController::registerPayment` -> регистрация факта оплаты;
- `PaymentRecipientController::confirmReceipt` -> `PaymentConfirmationService::confirmReceipt`;
- `PaymentBudgetLimitService::assertAllowed` и `syncReservation` для лимитного контроля.

Actor-поля уже есть:

- `PaymentDocument.created_by_user_id`;
- `PaymentDocument.approved_by_user_id`;
- `PaymentDocument.recipient_confirmed_by_user_id`;
- `PaymentDocument.budget_limit_overridden_by_user_id`;
- `PaymentTransaction.created_by_user_id`;
- `PaymentTransaction.approved_by_user_id`;
- `PaymentApproval.approver_user_id`, `decided_at`, `status`.

Текущий риск: `ApprovalWorkflowService::approveByUser()` разрешает admin bypass для `organization_owner`, системного администратора, legacy-ролей `admin`/`finance_admin` и пользователей с `payments.transaction.approve`. Это должно стать явным SoD-result `exception_required`, а не молчаливым проходом.

### 2.3. Закупки

Ключевые маршруты находятся в `app/BusinessModules/Features/Procurement/routes.php` под `/api/v1/admin/procurement`.

Точки workflow:

- `PurchaseRequestController::approve` / `reject`;
- `SupplierProposalDecisionController::select` и `selectForPurchaseRequest`;
- `ProcurementApprovalController::approve` / `reject` -> `ProcurementApprovalService`;
- `PurchaseOrderController::send`, `confirm`, `receiveMaterials`;
- `ProcurementChainController::createOrOpenPaymentDocument`.

Частичный SoD уже есть:

- `ProcurementDutySeparationService::ensureCanResolve()` блокирует requester, selector и intake author;
- `ProcurementApprovalPolicyService` хранит настройки `prevent_requester_approval`, `prevent_selector_approval`, `prevent_intake_author_approval`;
- `ProcurementApproval` хранит `requested_by`, `approved_by`, `rejected_by`;
- `SupplierProposalDecision` хранит `selected_by`;
- `PurchaseReceipt` хранит `received_by_user_id`.

Текущий риск: `ProcurementDutySeparationService` возвращает пустой список blockers для `organization_owner`. В PHERP-133 это должно стать `exception_required` для high-risk цепочек, если owner участвует в конфликте.

### 2.4. Склад

Ключевые маршруты находятся в `app/BusinessModules/Features/BasicWarehouse/routes.php` под `/api/v1/admin`.

Точки workflow:

- `WarehouseOperationsController` для receipt, write-off, transfer и custody;
- `WarehouseService::receiveAsset`, `writeOffAsset`, `transferAsset`;
- `ProjectMaterialDeliveryService` для выдачи/возврата материалов по объекту;
- `InventoryController::store`, `start`, `updateItem`, `complete`, `approve`.

Actor-поля уже есть:

- `WarehouseMovement.user_id`;
- `WarehouseMovement.related_user_id`;
- `WarehouseMovement.project_material_delivery_id`;
- `WarehouseMovement.operation_category`;
- `ProjectMaterialDelivery.responsible_user_id`;
- `ProjectMaterialDelivery.receiver_user_id`;
- `InventoryAct.created_by`;
- `InventoryAct.approved_by`.

Недостающие данные:

- `InventoryAct.completed_by`;
- `InventoryActItem.updated_by_user_id`;
- надежная связка write-off -> receipt/batch для всех списаний.

До появления недостающих полей часть правил должна работать как `report_only` или `warning`, а не как silent allow.

### 2.5. Бюджетирование и закрытие периода

Ключевые сервисы:

- `BudgetWorkflowService::transitionVersion`;
- `BudgetPeriodClosureService::close`;
- `BudgetPeriodReopenService::reopen`;
- `BudgetLimitCheckService`;
- `PaymentBudgetLimitService`.

Actor-поля уже есть:

- `BudgetVersion.created_by`, `submitted_by`, `approved_by`, `activated_by`;
- `BudgetVersion.workflow_history`;
- `BudgetPeriod.created_by`, `updated_by`;
- `BudgetPeriodClosure.closed_by`, `closed_at`, `reopened_until`, `metadata`;
- `BudgetLimitReservation.created_by_user_id`;
- `PaymentDocument.budget_limit_overridden_by_user_id`.

Текущий риск: закрытие и переоткрытие периода имеют бизнес-обоснование и TTL окна, но не проверяют SoD-конфликт actor'а закрытия/переоткрытия с активными корректировками и последующими действиями в reopened window.

### 2.6. MDM

Ключевые маршруты находятся в `routes/api/v1/admin/mdm.php` под `/api/v1/admin/mdm`.

Точки workflow:

- `MdmController::previewChangeRequest`;
- `submitChangeRequest`, `submitDraftChangeRequest`;
- `startReviewChangeRequest`;
- `approveChangeRequest`;
- `applyChangeRequest`;
- `reviewChangeRequest`;
- `importApply`, `fileImportApply`, `mergeApply`.

MDM уже содержит сильную базу для PHERP-133:

- `MdmChangeRequest.requested_by_user_id`;
- `owner_user_id`;
- `approver_user_id`;
- `executor_user_id`;
- `reviewed_by_user_id`;
- `MdmChangeRequestEvent.actor_user_id`;
- `impact_snapshot`;
- `one_c_lock_summary`;
- `payload_hash`;
- `expected_record_version`.

Текущий риск: `MdmChangeRequestService::approve()` и `applyApproved()` пока не запрещают requester=approver и approver=executor для high-risk изменений.

## 3. Целевая архитектура

Новый доменный модуль целесообразно разместить в `app/BusinessModules/Core/Sod`.

Компоненты:

| Компонент | Ответственность |
| --- | --- |
| `SodRuleCatalog` | Возвращает системный каталог правил `SOD-*`, домен, severity, mode, required permissions и supported actions. |
| `SodCheckService` | Принимает `SodCheckContext`, строит actor chain, проверяет правила, ищет active exception и возвращает `SodCheckResult`. |
| `SodExceptionService` | Создает, согласует, отклоняет, отзывает и атомарно использует one-time exceptions. |
| `SodAuditService` | Пишет check event, violation и usage event в отдельный SoD-журнал. |
| `SodViolationReportService` | Формирует реестр нарушений, агрегаты по доменам и periodic report. |
| `SodRoleHeatmapService` | Анализирует `RoleDefinitions`, custom roles и назначения пользователей на role-level конфликты. |
| `SodWorkflowResponseFactory` | Формирует единый API payload для `AdminResponse`, включая `data.sod_result`. |
| `SodDomainContextResolver` | Строит цепочки payment/procurement/warehouse/budget/mdm/rbac по текущим моделям. |

DTO:

- `SodCheckContext`: actor, organization, optional project, action key, permission key, subject type/id, amount, source endpoint, request metadata.
- `SodCheckResult`: status, mode, rule id, severity, user message, conflicts, can request exception, exception id, audit event id.
- `SodConflict`: conflict actor, action, subject, happened at, source module.
- `SodExceptionDecision`: decision, approver, reason, validity scope.

Сервис должен вызываться после RBAC/organization scope checks, но до изменения статуса, создания финансового факта, записи складского движения или применения MDM diff.

## 4. Данные

### 4.1. `sod_rules`

Хранит настраиваемые параметры правил поверх системного каталога.

Обязательные поля:

- `id`;
- `organization_id` nullable для global defaults;
- `rule_id` unique within organization;
- `domain`: `payments`, `procurement`, `warehouse`, `budgeting`, `mdm`, `rbac`;
- `severity`: `low`, `medium`, `high`, `critical`;
- `mode`: `warning`, `hard_block`, `exception_approval`, `report_only`;
- `is_online_enabled`;
- `is_periodic_enabled`;
- `exception_ttl_hours`;
- `thresholds` jsonb;
- `created_at`, `updated_at`.

### 4.2. `sod_check_events`

Пишется для каждого online и periodic check.

Обязательные поля:

- `organization_id`;
- `project_id` nullable;
- `actor_user_id`;
- `action_key`;
- `permission_key`;
- `subject_type`;
- `subject_id`;
- `rule_id`;
- `severity`;
- `mode`;
- `result`: `allowed`, `warned`, `blocked`, `exception_required`, `exception_used`, `report_only`;
- `conflict_user_ids` jsonb;
- `conflict_actions` jsonb;
- `conflict_subjects` jsonb;
- `exception_id` nullable;
- `reason_code`;
- `message_key`;
- `snapshot` jsonb;
- `request_id`, `ip`, `user_agent`;
- `created_at`.

### 4.3. `sod_violations`

Отдельная карточка нарушения для реестра и отчетов.

Обязательные поля:

- `organization_id`;
- `project_id` nullable;
- `check_event_id`;
- `rule_id`;
- `domain`;
- `severity`;
- `mode`;
- `status`: `open`, `resolved`, `accepted_with_exception`, `expired`, `false_positive`;
- `actor_user_id`;
- `conflict_user_ids` jsonb;
- `subject_type`, `subject_id`;
- `source_action_key`;
- `source_permission_key`;
- `occurred_at`;
- `resolved_at`;
- `resolution_reason`;
- `metadata` jsonb.

### 4.4. `sod_exceptions`

Exception является бизнес-объектом, а не побочным флагом роли.

Обязательные поля:

- `organization_id`;
- `project_id` nullable;
- `rule_id`;
- `domain`;
- `scope_type`;
- `scope_id`;
- `requested_action`;
- `requested_permission`;
- `requested_by_user_id`;
- `approved_by_user_id`;
- `rejected_by_user_id`;
- `revoked_by_user_id`;
- `used_by_user_id`;
- `status`: `requested`, `approved`, `rejected`, `revoked`, `expired`, `used`;
- `reason`;
- `business_justification`;
- `valid_from`;
- `valid_until`;
- `used_at`;
- `metadata` jsonb;
- `created_at`, `updated_at`.

Индексы:

- active lookup: `(organization_id, rule_id, scope_type, scope_id, requested_action, status, valid_until)`;
- queue: `(organization_id, domain, status, valid_until)`;
- audit: `(organization_id, requested_by_user_id, approved_by_user_id, created_at)`.

Для one-time использования нужен row lock на exception перед переводом в `used`, чтобы два параллельных запроса не смогли использовать один exception.

## 5. Permissions

### 5.1. Core permissions

Целевые permissions PHERP-133:

| Permission | Назначение |
| --- | --- |
| `sod.rules.view` | Просмотр правил SoD. |
| `sod.rules.manage` | Управление режимами и порогами правил SoD. |
| `sod.checks.view` | Просмотр журнала проверок SoD. |
| `sod.violations.view` | Просмотр нарушений SoD. |
| `sod.violations.export` | Экспорт нарушений SoD. |
| `sod.exceptions.request` | Запрос исключения по заблокированному действию. |
| `sod.exceptions.approve.finance` | Согласование финансовых исключений. |
| `sod.exceptions.approve.procurement` | Согласование закупочных исключений. |
| `sod.exceptions.approve.warehouse` | Согласование складских исключений. |
| `sod.exceptions.approve.mdm` | Согласование MDM-исключений. |
| `sod.exceptions.approve.period_close` | Согласование исключений по закрытию и переоткрытию периода. |
| `sod.exceptions.revoke` | Отзыв ранее выданных исключений. |
| `sod.exceptions.admin` | Emergency-исключения с усиленным аудитом. |
| `sod.reports.view` | Просмотр SoD-отчетов. |
| `sod.reports.export` | Экспорт SoD-отчетов. |

При production-реализации эти permissions должны быть добавлены в `lang/ru/permissions.php`, `config/RoleDefinitions/**` и тесты `PermissionTranslator`/контракта ролей. Русские подписи должны отдаваться API ролей и SoD reports без технических slug'ов в пользовательском UI.

### 5.2. Доменные permissions

PHERP-133 не должен переименовывать существующие permissions без миграционного пути. Если потребуется разделение действий, целевые permissions такие:

- `payments.transaction.confirm_payment`;
- `payments.approvals.override_admin`;
- `suppliers.requisites.edit`;
- `suppliers.requisites.approve`;
- `suppliers.bank_accounts.manage`;
- `procurement.supplier_parties.link`;
- `warehouse.write_offs.approve`;
- `warehouse.inventory.approve`;
- `budgeting.limits.exception_approve`;
- `budgeting.periods.close_override`;
- `mdm.change_requests.approve_high_risk`;
- `mdm.change_requests.apply_high_risk`;
- `mdm.records.direct_edit`;
- `mdm.source_of_truth.override`, если команда решит мигрировать с текущего `mdm.one_c.override`.

`mdm.records.direct_edit` уже переведен в `lang/ru/permissions.php`, но сейчас не должен считаться включенным в runtime-поток без отдельной реализации маршрутов и аудита.

## 6. Единый результат online check

Для workflow endpoints SoD check возвращает один из статусов:

- `allowed` - действие разрешено;
- `warned` - действие разрешено, но warning записан в audit и report;
- `blocked` - действие запрещено;
- `exception_required` - действие остановлено до независимого согласования;
- `exception_used` - действие разрешено через действующий exception, usage записан;
- `report_only` - workflow не остановлен, violation попадет в periodic report.

Для `hard_block` и `exception_required` доменный endpoint возвращает HTTP `409` через `AdminResponse::error()`, где `data.sod_result` содержит бизнес-причину и поля для UI.

Пример:

```json
{
  "success": false,
  "message": "Для этого действия требуется независимое подтверждение.",
  "data": {
    "sod_result": {
      "status": "exception_required",
      "mode": "exception_approval",
      "rule_id": "SOD-PAY-003",
      "severity": "high",
      "message": "Регистрацию оплаты должен выполнить другой пользователь.",
      "can_request_exception": true,
      "approver_permissions": ["sod.exceptions.approve.finance"],
      "blocking_conflicts": [
        {
          "action": "payment_approved",
          "user_id": 123,
          "subject_type": "payment_document",
          "subject_id": 456
        }
      ]
    }
  }
}
```

Пользовательский `message` не должен содержать технические слова вроде `payload`, `fallback`, `constraint`, class names, SQL-детали или permission slug как основной текст.

## 7. Матрица enforcement

### 7.1. Платежи

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-PAY-001` автор согласует свой платеж | `ApprovalWorkflowService::approveByUser()` до поиска/закрытия `PaymentApproval`; сравнить `actor_user_id` с `PaymentDocument.created_by_user_id` | `hard_block` | штатно никто; emergency только `sod.exceptions.admin` и отдельный audit reason | `sod_check_events.result=blocked`, `sod_violations.domain=payments`, linked `payment_document_id` |
| `SOD-PAY-002` автор регистрирует оплату своего документа | `PaymentDocumentService::registerPayment` / controller action до создания `PaymentTransaction`; сравнить actor с `created_by_user_id` | `exception_approval` | `sod.exceptions.approve.finance`, approver не creator и не текущий actor | `exception_required` или `exception_used`, report group `payments.self_registration` |
| `SOD-PAY-003` согласующий регистрирует оплату | регистрация оплаты; сравнить actor с `PaymentDocument.approved_by_user_id` и approved `PaymentApproval.approver_user_id` | `exception_approval` | `sod.exceptions.approve.finance` | violation связывается с payment document и transaction draft context |
| `SOD-PAY-004` admin bypass согласует свой платеж | `ApprovalWorkflowService::approveByUser()` в ветках `isOrganizationOwner`, `isSystemAdmin`, `hasRole(admin/finance_admin)`, `can(payments.transaction.approve)` | `exception_approval` | другой пользователь с `sod.exceptions.approve.finance`; emergency `sod.exceptions.admin` | отдельный reason `admin_bypass_requires_exception` |
| `SOD-PAY-005` actor менял реквизиты поставщика и согласует/регистрирует платеж | payment approve/register + MDM link supplier/contractor -> `MdmChangeRequest.executor_user_id` или future supplier audit | `exception_approval`, банковские реквизиты без MDM - `hard_block` | `sod.exceptions.approve.finance` + `sod.exceptions.approve.mdm`, оба независимые | domain pair `payments+mdm`, linked MDM CR and payment document |
| `SOD-PAY-006` actor создал поставщика и создает/согласует/регистрирует платеж ему в cooling-off | payment create/approve/register; source supplier actor from future `Supplier.created_by_user_id` or MDM/supplier audit | create - `warning`, approve/register - `exception_approval` | `sod.exceptions.approve.finance` или `sod.exceptions.approve.procurement` | report field `cooling_off_until` |
| `SOD-PAY-007` actor делает budget override по своему платежу | `PaymentBudgetLimitService::assertCalculationAllowed()` перед override; сравнить actor с `PaymentDocument.created_by_user_id`/approved actor | `exception_approval` | `sod.exceptions.approve.finance` | linked budget limit check and payment document |
| `SOD-PAY-008` payer-side/recipient-side self confirmation | `PaymentConfirmationService::confirmReceipt()` после проверки recipient organization, до `confirmByRecipient` | same actor - `hard_block`, косвенная связь - `report_only` | emergency `sod.exceptions.admin` только для hard block | report group `recipient_confirmation_conflict` |

### 7.2. Закупки

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-PROC-001` автор закупочной заявки согласует ее | `PurchaseRequestController::approve` / service до смены статуса; после добавления `PurchaseRequest.created_by_user_id` | `hard_block` | штатно никто; emergency `sod.exceptions.approve.procurement` на 24 часа | violation linked `purchase_request_id` |
| `SOD-PROC-002` requester/selector/intake author resolve approval | расширить `ProcurementDutySeparationService::resolutionBlockers()` | `hard_block`; owner bypass -> `exception_required` | `sod.exceptions.approve.procurement`, approver не requester/selector/intake author | сохранять current blocker code в `sod_check_events.snapshot` |
| `SOD-PROC-003` создатель/редактор поставщика выбирает его победителем | `SupplierProposalDecisionController::select*` до записи decision; actor source from MDM/supplier audit | `exception_approval` | `sod.exceptions.approve.procurement` + `sod.exceptions.approve.finance` | linked supplier party, proposal decision, cooling-off period |
| `SOD-PROC-004` selector согласует закупку | `ProcurementApprovalService::approve()` до `update status=approved`; сравнить actor с `SupplierProposalDecision.selected_by` | `hard_block` | `sod.exceptions.approve.procurement` только emergency | `blocked` violation with `selected_by` conflict |
| `SOD-PROC-005` confirm actor принимает материалы | `PurchaseOrderService::confirm()` и `receiveMaterials()` после добавления `confirmed_by_user_id`; до этого periodic по metadata `sent_by_user_id`/audit | `exception_approval` | `sod.exceptions.approve.procurement` или `sod.exceptions.approve.warehouse` | linked purchase order and receipt |
| `SOD-PROC-006` selector/approver закупки согласует платеж по PO | `ProcurementChainController::createOrOpenPaymentDocument` и payment approve через PO/payment link | `exception_approval` | `sod.exceptions.approve.finance` | report chain `proposal -> PO -> payment_document` |
| `SOD-PROC-007` автор закупочной заявки делает budget override | `BudgetLimitCheckService` / `PaymentBudgetLimitService`, если source chain указывает на purchase request | `exception_approval` | `sod.exceptions.approve.finance` | linked purchase request and limit check |

### 7.3. Склад

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-WH-001` receiver согласует/регистрирует платеж | payment approve/register через PO -> receipt; сравнить actor с `PurchaseReceipt.received_by_user_id` | `exception_approval` | `sod.exceptions.approve.finance` или `sod.exceptions.approve.warehouse` | linked receipt, PO and payment |
| `SOD-WH-002` receiver списывает те же материалы | `WarehouseService::writeOffAsset()` до движения; искать receipt/batch/project/material link | same receipt/batch - `hard_block`, same project/material - `exception_approval` | `sod.exceptions.approve.warehouse` + finance для high-value | report fields `receipt_id`, `movement_id`, `material_id` |
| `SOD-WH-003` receipt и write-off одним actor без batch link | `WarehouseService::receiveAsset/writeOffAsset` и periodic scan по `WarehouseMovement` | `warning` или high-value `exception_approval` | `sod.exceptions.approve.warehouse` | `warned` online + periodic trend |
| `SOD-WH-004` создатель инвентаризации утверждает акт | `InventoryController::approve()` до `applyApprovedItemQuantity`; сравнить `created_by` с actor | `hard_block` | штатно никто; emergency только при нулевых расхождениях | linked `inventory_act_id` |
| `SOD-WH-005` заполняющий фактические количества утверждает акт с расхождениями | `InventoryController::updateItem()` должен писать future `updated_by_user_id`; `approve()` проверяет items | сейчас `report_only`, после поля - `hard_block` | `sod.exceptions.approve.warehouse` + finance для расхождений | data gap report до появления поля |
| `SOD-WH-006` actor выдал материалы ответственному и сам закрывает возврат/списание | custody issue/return/write-off через `WarehouseCustodyService`, `ProjectMaterialDeliveryService`, `WarehouseMovement.related_user_id` | `exception_approval` | `sod.exceptions.approve.warehouse` | linked custody chain and movement ids |
| `SOD-WH-007` роль совмещает полный складской цикл | `SodRoleHeatmapService` по RoleDefinitions/custom roles | `report_only` | не exception; governance review | role heatmap: role, users count, conflicting permissions |

### 7.4. Бюджетирование и период

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-BUD-001` автор budget version согласует ее | `BudgetWorkflowService::transitionVersion()` при action `approve`; сравнить actor с `created_by`/`submitted_by` | `hard_block` | emergency `sod.exceptions.approve.finance` на 24 часа | linked budget version |
| `SOD-BUD-002` approver активирует ту же версию | `BudgetWorkflowService::transitionVersion()` при action `activate`; сравнить actor с `approved_by` | `exception_approval` | `sod.exceptions.approve.finance` или `sod.exceptions.approve.period_close` | report `budget_version_approve_activate` |
| `SOD-BUD-003` actor управляет лимитом и сам делает override | `BudgetLimitCheckService`/`PaymentBudgetLimitService`; actor history будущего limit audit | own document - `hard_block`, чужой документ - `exception_approval` | `sod.exceptions.approve.finance` | linked limit check |
| `SOD-BUD-004` actor закрывает период со своими активными корректировками | `BudgetPeriodClosureService::close()` после blockers, до status `closing` | `exception_approval` | `sod.exceptions.approve.period_close` | report per period and actor |
| `SOD-BUD-005` actor переоткрывает период и сам вносит изменения | `BudgetPeriodReopenService::reopen()` and all `assertActiveReopenAllows()` consumers | same actor - `hard_block`, emergency - `exception_approval` | `sod.exceptions.approve.period_close` | linked closure uuid and allowed operations |
| `SOD-BUD-006` actor закрывает период и затем approve/register payment задним числом | payment approve/register checks against closed period and `BudgetPeriodClosure.closed_by` | `hard_block` без reopen window | через period reopen, не прямой exception | report group `closed_period_financial_action` |

### 7.5. MDM

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-MDM-001` requester согласует свой CR | `MdmChangeRequestService::approve()` до transition | `hard_block` | штатно никто; emergency `sod.exceptions.approve.mdm` на 24 часа | linked MDM CR |
| `SOD-MDM-002` requester/approver сам apply high-risk CR | `MdmChangeRequestService::applyApproved()` до `MdmDomainChangeApplier::apply()` | `exception_approval`, банковские реквизиты - `hard_block` без второго actor | `sod.exceptions.approve.mdm` + finance для financial fields | linked CR, diff severity and impact |
| `SOD-MDM-003` direct edit участвует в payments/procurement | future direct edit route and downstream payment/procurement checks | `exception_approval` | `sod.exceptions.approve.mdm` + domain approver | report links direct edit audit to later workflow |
| `SOD-MDM-004` 1C override и финансовый документ | `MdmOneCLockService` + payment/procurement/budget checks | active conflict - `hard_block`, иначе `exception_approval` | `sod.exceptions.approve.mdm` + finance | report `source_of_truth_override` |
| `SOD-MDM-005` MDM budget article/CFO change + limit override/period close | MDM apply and budgeting checks via impact snapshot | `exception_approval` | `sod.exceptions.approve.finance` или `sod.exceptions.approve.period_close` | linked CR and budget period/limit check |

### 7.6. RBAC

| Rule | Где ловится | Mode | Кто может согласовать exception | Как попадает в отчет |
| --- | --- | --- | --- | --- |
| `SOD-RBAC-001` self-assignment conflict role | `AuthorizationService::assignRole()` before `UserRoleAssignment::assignRole()` | `hard_block` | `sod.exceptions.admin`, approver не target user и не assigned_by | security report with target user/role |
| `SOD-RBAC-002` роль совмещает полный цикл | `SodRoleHeatmapService` periodic scan of RoleDefinitions and custom roles | `report_only` | не exception, governance action | role heatmap with conflicting permissions |
| `SOD-RBAC-003` exception approver участвует в цепочке | `SodExceptionService::approve()` / `reject()` before decision | `hard_block` | другой approver той же области или `sod.exceptions.admin` | violation linked exception request |

## 8. API contract

Все endpoints находятся под `/api/v1/admin/sod`, используют `AdminRouteStack::middleware()` и `AdminResponse`.

| Method | Path | Permission | Назначение |
| --- | --- | --- | --- |
| `GET` | `/rules` | `sod.rules.view` | Список правил, режимов, severity, online/report flags. |
| `PUT` | `/rules/{ruleId}` | `sod.rules.manage` | Изменить mode, thresholds, online/report flags. |
| `POST` | `/checks/preview` | `sod.checks.view` + доменное право действия | Preview без изменения состояния. |
| `GET` | `/checks` | `sod.checks.view` | Журнал проверок. |
| `GET` | `/violations` | `sod.violations.view` | Реестр нарушений. |
| `GET` | `/violations/{id}` | `sod.violations.view` | Деталь нарушения. |
| `POST` | `/exceptions` | `sod.exceptions.request` | Запрос исключения. |
| `GET` | `/exceptions` | any approve permission or `sod.exceptions.request` scoped to own | Очередь и история exceptions. |
| `POST` | `/exceptions/{id}/approve` | area approve permission | Согласование exception. |
| `POST` | `/exceptions/{id}/reject` | area approve permission | Отклонение exception. |
| `POST` | `/exceptions/{id}/revoke` | `sod.exceptions.revoke` | Отзыв exception. |
| `GET` | `/reports/summary` | `sod.reports.view` | Сводка, role heatmap, domain aggregates. |
| `GET` | `/reports/export` | `sod.reports.export` | Экспорт отчета. |

### 8.1. Preview request

```json
{
  "action_key": "payments.document.approve",
  "permission_key": "payments.transaction.approve",
  "subject_type": "payment_document",
  "subject_id": 456,
  "project_id": null,
  "amount": 125000.0,
  "metadata": {
    "source": "admin"
  }
}
```

### 8.2. Rule payload

```json
{
  "id": "SOD-PAY-003",
  "domain": "payments",
  "title": "Регистрация оплаты согласующим",
  "severity": "high",
  "mode": "exception_approval",
  "online_enabled": true,
  "periodic_enabled": true,
  "approver_permissions": ["sod.exceptions.approve.finance"],
  "supported_actions": ["payments.document.register_payment"]
}
```

### 8.3. Exception request

```json
{
  "rule_id": "SOD-PAY-003",
  "scope_type": "payment_document",
  "scope_id": 456,
  "requested_action": "payments.document.register_payment",
  "business_justification": "Оплата срочная, независимый финансовый контролер подтвердил документы.",
  "valid_until": "2026-06-23T18:00:00+03:00",
  "metadata": {
    "return_to": "/payments?tab=payments&document_id=456"
  }
}
```

### 8.4. List response shape

Paginated list endpoints return:

```json
{
  "data": [
    {
      "id": 91,
      "rule_id": "SOD-PAY-003",
      "domain": "payments",
      "severity": "high",
      "status": "open",
      "actor": {"id": 12, "name": "Иван Петров"},
      "subject": {"type": "payment_document", "id": 456, "label": "Платеж №PD-456"},
      "occurred_at": "2026-06-22T12:30:00+03:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  },
  "summary": {
    "open": 1,
    "critical": 0,
    "high": 1,
    "exception_required": 1
  }
}
```

## 9. Admin UI contract

Future admin implementation should add a SoD control center in `prohelper_admin`.

Suggested files:

- `src/types/sod.ts`;
- `src/services/sodService.ts`;
- `src/pages/Sod/SodControlCenterPage.tsx`;
- `src/pages/Sod/components/SodRulesTab.tsx`;
- `src/pages/Sod/components/SodViolationsTab.tsx`;
- `src/pages/Sod/components/SodExceptionsTab.tsx`;
- `src/pages/Sod/components/SodReportsTab.tsx`;
- `src/pages/Sod/components/SodAuditTab.tsx`;
- route `/sod` in `src/App.tsx`;
- menu item in `src/components/layout/SidebarMenu.tsx`.

UI requirements:

- operational dashboard, not landing page;
- tabs: rules, violations, exceptions, reports, audit;
- summary strip: open violations, blocked actions, pending exceptions, expired exceptions, high-risk roles;
- filters: domain, rule id, severity, status, actor, organization/project, date range;
- role heatmap: role, context, conflicting permissions, user count, recommendation;
- inline workflow banner for payments/procurement/warehouse/budgeting/MDM when `sod_result` is returned;
- request exception dialog only when `can_request_exception=true`;
- no technical slug as primary user-facing text.

Service layer must normalize paginated `AdminResponse` intentionally, following existing patterns in `src/services/responseUtils.ts` and module services.

## 10. Внедрение в workflow

Порядок проверки в доменных endpoints:

1. JWT/auth middleware.
2. Organization/project scoping.
3. `authorize:*` permission middleware или `AuthorizationService`.
4. Validation request.
5. `SodCheckService::checkOrFail()` внутри транзакции, до изменения состояния.
6. Domain mutation.
7. Domain audit.
8. SoD audit event finalization.

Для `warning` и `report_only` workflow продолжается, но SoD event пишется обязательно.

Для `exception_approval`:

1. Первый запрос получает `409` и `data.sod_result`.
2. Пользователь создает exception request.
3. Независимый approver согласует exception.
4. Пользователь повторяет исходное действие.
5. `SodExceptionService` атомарно помечает one-time exception как `used`.
6. Workflow выполняется и пишет связанный audit.

Exception approver не может быть:

- текущим actor;
- conflict actor по rule;
- requester/selector/receiver/approver/executor в той же цепочке;
- target user для RBAC self-assignment.

## 11. Periodic report и role heatmap

Periodic job должен работать read-only по бизнес-данным и создавать `sod_check_events` / `sod_violations` для:

- RoleDefinitions и custom roles с конфликтными permissions;
- пользователей с wildcard permissions;
- документов без actor fields;
- цепочек supplier -> proposal -> PO -> receipt -> payment;
- MDM changes -> downstream payments/procurement/budget;
- закрытых периодов и действий после closure/reopen;
- повторных exceptions одним пользователем;
- просроченных, отозванных или слишком широких exceptions.

Для role heatmap нужны минимум:

- `role_slug`;
- context;
- interface;
- conflicting permissions;
- affected domain;
- severity;
- users count;
- recommendation: split role, leave report-only, restrict permission, require exception workflow.

Первый релиз должен оставить role-level конфликты в `report_only`, чтобы не сломать текущие системные роли `web_admin`, `organization_admin`, `organization_owner`, `finance_admin`, `accountant`, `project_manager`, `foreman`, `supplier`.

## 12. План реализации

1. Добавить migrations/models для `sod_rules`, `sod_check_events`, `sod_violations`, `sod_exceptions`.
2. Добавить `App\BusinessModules\Core\Sod` с сервисами, DTO и ресурсами.
3. Добавить translations `lang/ru/sod.php` для сообщений и `lang/ru/permissions.php` для новых permissions.
4. Добавить `config/RoleDefinitions` для view/request/report permissions и узких approver permissions.
5. Добавить `/api/v1/admin/sod` routes/controllers/requests/resources.
6. Встроить read-only `checks/preview`, rules list и reports summary.
7. Встроить online checks в платежи с `SOD-PAY-001..004` и budget override `SOD-PAY-007`.
8. Расширить procurement checks поверх `ProcurementDutySeparationService` без silent owner bypass.
9. Встроить MDM checks `SOD-MDM-001..002` в `MdmChangeRequestService`.
10. Встроить budget version/period checks `SOD-BUD-001..006`.
11. Встроить warehouse checks `SOD-WH-004` сразу, а rules с недостающими actor fields оставить `report_only` до schema changes.
12. Добавить periodic role heatmap и report-only scan.
13. Добавить admin SoD control center и inline workflow UX.
14. Покрыть unit, contract и feature tests без локального запуска запрещенных DB-команд.

## 13. Тесты будущей реализации

### Unit

- `SodRuleCatalogTest`: все `SOD-*` правила имеют mode, severity, domain, message key.
- `SodCheckServiceTest`: allowed/warned/blocked/exception_required/exception_used.
- `SodExceptionServiceTest`: approver independence, TTL, revoke, expired, one-time lock.
- `SodRoleHeatmapServiceTest`: RoleDefinitions conflict detection.
- `PermissionTranslatorTest`: русские подписи всех `sod.*` permissions.

### Contract

- `/api/v1/admin/sod/rules` возвращает translated titles и modes.
- Paginated `/violations`, `/checks`, `/exceptions` возвращают `data`, `meta`, `summary`.
- Workflow `409` содержит `data.sod_result`, а `errors` используется только для field validation.

### Domain workflow

- Payment self-approval returns `SOD-PAY-001`.
- Payment admin bypass returns `SOD-PAY-004` exception required.
- Procurement requester/selector/intake author blocks via `SOD-PROC-002`.
- MDM requester cannot approve own CR.
- Budget version creator cannot approve own version.
- Inventory act creator cannot approve own act.
- One-time exception cannot be used twice in parallel.

Локально нельзя запускать миграции, DB/tinker/seed/rollback/reset/delete/verify/dry-run команды и feature tests с `RefreshDatabase` без отдельной команды пользователя.

## 14. Acceptance criteria

- Для каждого правила `SOD-PAY`, `SOD-PROC`, `SOD-WH`, `SOD-BUD`, `SOD-MDM`, `SOD-RBAC` определены hook point, mode, approver permission и отчетная запись.
- Silent bypass в платежах и закупках заменен в целевой архитектуре на recorded exception.
- SoD service не заменяет RBAC, а работает после `AuthorizationService`.
- Exceptions имеют scope, TTL, business justification, independent approver и one-time usage.
- SoD audit отделен от доменных audit logs, но связывается с payment/procurement/warehouse/MDM/budget records.
- API contract использует `AdminResponse` и `data.sod_result`.
- Admin UI имеет control center и inline UX для workflow-blocking.
- Role heatmap работает в report-only режиме до включения hard blocks для ролей.
- Новые permissions имеют русские подписи в целевой реализации.
- Документ сохранен в UTF-8; так как markdown игнорируется `.gitignore`, коммит должен добавлять файл через `git add -f docs/specs/PHERP-133-sod-enforcement-reporting.md`.
