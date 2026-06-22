# PHERP-132: SoD-матрица для финансов, закупок и склада

## 1. Цель

Документ описывает матрицу Segregation of Duties для финансов, закупок, склада, бюджетирования и MDM. Спецификация нужна как вход для PHERP-133, где должны быть реализованы online enforcement, exceptions, аудит и отчеты по нарушениям.

PHERP-132 не меняет production-код, роли, permissions, миграции, API или UI. Все ниже - проектное описание целевого поведения.

## 2. Изученная база

### 2.1. Роли и permissions

Изучены:

- `config/RoleDefinitions/**`;
- `lang/ru/permissions.php`;
- `App\Domain\Authorization\Services\AuthorizationService`;
- workflow платежей, закупок, склада, бюджетирования и MDM Change Requests.

Ключевые роли, которые уже совмещают критичные полномочия:

| Роль | Файл / фактический context / interface | Наблюдение для SoD |
| --- | --- | --- |
| `web_admin` | `admin/web_admin.json`; `context=organization`, `interface_access=admin` | Получает широкий набор явных permissions из доменов MDM, закупок, платежей, склада и бюджетирования, например `mdm.change_requests.apply`, `procurement.approvals.resolve`, `payments.transaction.approve`, `warehouse.manage_stock`, `budgeting.budgets.approve`; может технически пройти почти весь цикл операции. |
| `organization_admin` | `lk/organization_admin.json`; `context=organization`, `interface_access=lk,admin,mobile` | Имеет широкий набор прав по MDM, закупкам, платежам, складу, бюджетированию и управлению пользователями. |
| `organization_owner` | `lk/organization_owner.json`; `context=organization`, `interface_access=lk,admin,mobile` | Имеет `admin.*` и module wildcard `*` в доменах `finance`, `catalog-management`, `budgeting`, `payments`, `procurement`, `basic-warehouse` и других модулях. |
| `finance_admin` | `admin/finance_admin.json`; `context=organization`, `interface_access=admin` | Совмещает платежи, регистрацию транзакций, финансовую аналитику, управление бюджетами, лимитами, закрытием и переоткрытием периодов. |
| `accountant` | `lk/accountant.json`; `context=organization`, `interface_access=admin,lk` | Совмещает создание финансовых документов, регистрацию платежей, часть бюджетного workflow, авансы и просмотры закупок/склада. |
| `project_manager` | `project/project_manager.json`; `context=project`, `interface_access=admin,mobile` | Может создавать/редактировать бюджет, отправлять его на согласование, создавать платежные документы и выполнять складские операции в проектном контексте. |
| `foreman` | `mobile/foreman.json`; `context=project`, `interface_access=mobile,admin` | Имеет складские операции и `procurement.purchase_orders.receive`, а также `procurement.approvals.resolve`; это риск приемки и согласования в одном контуре. |
| `supplier` | `lk/supplier.json`; `context=organization`, `interface_access=lk` | Имеет `suppliers.create`, `suppliers.edit`, `warehouse.manage_stock`, `warehouse.receipts`, `warehouse.write_offs`, `warehouse.transfers`, `warehouse.inventory`. |

В `lang/ru/permissions.php` уже есть русские подписи для групп, subjects/actions и многих точечных permissions в доменах:

- `payments`;
- `procurement`;
- `warehouse`;
- `budgeting`;
- `mdm`;
- `suppliers`.

Здесь доменные имена не означают literal slug-и вида `payments.*` или `suppliers.*`. Для части точечных permissions, например `suppliers.view`, `suppliers.create`, `suppliers.edit`, `suppliers.delete`, человекочитаемые подписи сейчас строятся через `subjects` + `actions`, а не через отдельную запись в `values`.

Для будущих SoD permissions потребуется добавить отдельные русские подписи, чтобы API ролей и UI не показывали технические slug-и.

### 2.2. AuthorizationService

`AuthorizationService::can()` проверяет наличие permission с учетом контекста организации/проекта, активных назначений ролей, условий и fallback из project context в organization context. Сервис не знает:

- кто создал конкретный документ;
- кто его согласовал;
- кто выбрал поставщика;
- кто принял материалы;
- кто редактировал реквизиты;
- есть ли SoD exception для конкретного объекта.

Итог: SoD нельзя реализовать только через текущий RBAC. Нужен отдельный доменный слой проверки действия относительно конкретного объекта и истории действий.

### 2.3. Платежи

Изучены `Core/Payments` routes, models и services:

- `PaymentDocument`;
- `PaymentTransaction`;
- `PaymentApproval`;
- `PaymentDocumentService`;
- `ApprovalWorkflowService`;
- `PaymentBudgetLimitService`;
- `PaymentConfirmationService`;
- `PaymentAuditLog`;
- `PaymentAuditService`;
- `PaymentAccessControl`.

В платежах уже есть важные поля для SoD:

- `payment_documents.created_by_user_id`;
- `payment_documents.approved_by_user_id`;
- `payment_documents.recipient_confirmed_by_user_id`;
- `payment_documents.budget_limit_overridden_by_user_id`;
- `payment_transactions.created_by_user_id`;
- `payment_transactions.approved_by_user_id`;
- `payment_approvals.approver_id`, `approved_at`, `status`.

Проблема: `ApprovalWorkflowService` допускает admin bypass для `organization_owner`, legacy `admin`, `finance_admin` и пользователей с `payments.transaction.approve`, но не фиксирует SoD exception как отдельный бизнес-объект. PHERP-133 должен заменить молчаливый bypass на явное исключение с аудитом.

### 2.4. Закупки

Изучены `Features/Procurement` routes, models и services:

- `PurchaseRequest`;
- `PurchaseOrder`;
- `PurchaseReceipt`;
- `SupplierProposalDecision`;
- `ProcurementApproval`;
- `ProcurementApprovalService`;
- `ProcurementDutySeparationService`;
- `ProcurementApprovalPolicyService`;
- `PurchaseRequestService`;
- `PurchaseOrderService`;
- `SupplierPartyService`;
- `ProcurementAuditService`.

В закупках уже есть частичный SoD:

- `ProcurementDutySeparationService` блокирует согласование, если actor является requester, selector или intake author;
- `ProcurementApprovalPolicyService` включает `prevent_requester_approval`, `prevent_selector_approval`, `prevent_intake_author_approval`;
- `ProcurementApproval` хранит `requested_by`, `approved_by`, `rejected_by`;
- `SupplierProposalDecision` хранит `selected_by`;
- `PurchaseReceipt` хранит `received_by_user_id`;
- `PurchaseOrderService::receiveMaterials()` фиксирует приемку и audit event.

Пробелы:

- `PurchaseRequest` не хранит явный `created_by_user_id`;
- `PurchaseOrder` хранит `sent_by_user_id` в metadata, но не как нормализованное поле;
- подтверждение заказа не фиксирует `confirmed_by_user_id` как отдельное поле;
- `organization_owner` сейчас может обходить часть duty separation без SoD exception.

### 2.5. Поставщики и MDM

Изучены:

- `SupplierController`;
- `SupplierService`;
- `StoreSupplierRequest`;
- `UpdateSupplierRequest`;
- `Core/Mdm` workflow PHERP-131.

Текущий CRUD поставщиков работает через:

- `suppliers.view`;
- `suppliers.create`;
- `suppliers.edit`;
- `suppliers.delete`.

Пробел: создание поставщика и изменение реквизитов не разделены на разные permissions. Для SoD нужно отдельно выделить изменение юридических/налоговых/платежных реквизитов поставщика.

MDM Change Requests уже дают лучшую базу:

- `MdmChangeRequest.requested_by_user_id`;
- `owner_user_id`;
- `approver_user_id`;
- `executor_user_id`;
- `reviewed_by_user_id`;
- `MdmChangeRequestEvent.actor_user_id`;
- impact, validation snapshot, 1C lock summary, payload hash.

`mdm.records.direct_edit` уже есть в `lang/ru/permissions.php`, но в текущих `RoleDefinitions` и admin MDM routes не назначен и не используется как действующая проверка доступа. Ниже он рассматривается как кандидат для PHERP-133, если команда решит явно включить прямое редактирование безопасных MDM-полей.

Для PHERP-133 MDM должен стать основным способом high-risk изменений поставщиков, контрагентов, материалов, статей бюджета, ЦФО и договоров.

### 2.6. Склад

Изучены `Features/BasicWarehouse` routes, controllers, models и services:

- `WarehouseMovement`;
- `WarehouseService`;
- `WarehouseOperationsController`;
- `InventoryAct`;
- `InventoryActItem`;
- `InventoryController`;
- `ProjectMaterialDelivery`;
- `ProjectMaterialDeliveryEvent`;
- `ProjectMaterialDeliveryService`;
- `WarehouseCustodyService`;
- `ProjectWarehouseService`.

Складские операции:

- приход - `warehouse.receipts`;
- списание - `warehouse.write_offs`;
- перемещение - `warehouse.transfers`;
- инвентаризация - `warehouse.inventory`;
- общее управление остатками - `warehouse.manage_stock`;
- выдача/возврат ответственному - `warehouse.manage_stock`.

Данные для SoD:

- `WarehouseMovement.user_id` - actor движения;
- `WarehouseMovement.related_user_id` - связанный пользователь, например ответственный;
- `WarehouseMovement.operation_category`;
- `WarehouseMovement.project_material_delivery_id`;
- `ProjectMaterialDelivery.responsible_user_id`;
- `ProjectMaterialDelivery.receiver_user_id`;
- `ProjectMaterialDelivery.outbound_movement_id`;
- `ProjectMaterialDelivery.inbound_movement_id`;
- `ProjectMaterialDeliveryEvent.user_id`;
- `InventoryAct.created_by`;
- `InventoryAct.approved_by`.

Пробелы:

- нет отдельного `completed_by` для инвентаризации;
- `InventoryActItem` не хранит пользователя, который внес фактическое количество;
- утверждение инвентаризации изменяет остатки без отдельной SoD-проверки автора/утверждающего;
- операции receipt/write-off/transfer завязаны на `user_id`, но нет SoD-проверки на связанные закупки, платежи и приемки.

### 2.7. Бюджетирование

Изучены `Features/Budgeting` routes, models и services:

- `BudgetVersion`;
- `BudgetWorkflowService`;
- `BudgetPeriod`;
- `BudgetPeriodClosure`;
- `BudgetPeriodClosureService`;
- `BudgetPeriodReopenService`;
- `BudgetLimitCheckService`;
- `PaymentBudgetLimitService`.

Данные для SoD:

- `BudgetVersion.created_by`;
- `submitted_by`;
- `approved_by`;
- `activated_by`;
- `workflow_history`;
- `BudgetPeriod.created_by`, `updated_by`;
- `BudgetPeriodClosure.closed_by`, `closed_at`, `reopened_until`, `metadata`;
- `PaymentBudgetLimitService` уже фиксирует limit override через `budget_limit_overridden_by_user_id` и `budget_limit_override_reason`.

Бюджетные permissions уже переведены, включая:

- `budgeting.budgets.create`, `budgeting.budgets.edit`, `budgeting.budgets.submit`, `budgeting.budgets.approve`, `budgeting.budgets.activate`, `budgeting.budgets.edit_approved`, `budgeting.budgets.archive`;
- `budgeting.periods.close`, `budgeting.periods.reopen`;
- `budgeting.limits.manage`, `budgeting.limits.override`;
- `budgeting.audit.view`.

## 3. Термины и режимы контроля

| Термин | Значение |
| --- | --- |
| SoD rule | Правило несовместимости действий или permissions в рамках одного объекта, цепочки, периода или роли. |
| Actor | Пользователь, который выполняет текущее действие. |
| Subject | Документ, заявка, поставщик, платеж, период, MDM change request или складское движение. |
| Conflict actor | Пользователь, который ранее выполнил несовместимое действие по этому subject или связанной цепочке. |
| Online check | Проверка в момент workflow-действия до изменения состояния. |
| Periodic check | Плановая проверка ролей, исторических данных, косвенных связей и накопленных исключений. |
| Exception | Временное разрешение выполнить действие при срабатывании SoD rule. |

Режимы контроля:

| Режим | Поведение |
| --- | --- |
| `warning` | Действие разрешено, пользователь видит предупреждение, событие попадает в audit trail. |
| `hard_block` | Действие запрещено без возможности продолжить из текущего workflow. |
| `exception_approval` | Действие остановлено до выдачи независимого exception; после выдачи разрешается только в заданной области и сроке. |
| `report_only` | Online workflow не блокируется, нарушение попадает в периодический отчет. |

## 4. Критичные бизнес-операции

| Операция | Текущие permissions | Объекты и поля | Целевой SoD-контроль |
| --- | --- | --- | --- |
| Создание платежа | `payments.invoice.create` | `PaymentDocument.created_by_user_id`, сумма, контрагент, бюджетная статья, проект | Запретить последующее self-approval и self-registration. |
| Отправка платежа на согласование | `payments.invoice.issue` | `PaymentDocument.status`, budget limit check | Проверять автора документа и лимитный override. |
| Согласование платежа | `payments.transaction.approve` | `PaymentApproval`, `PaymentDocument.approved_by_user_id` | Запретить согласование собственного платежа и платежа по поставщику, чьи реквизиты менял actor. |
| Подтверждение оплаты/регистрация платежа | `payments.transaction.register` | `PaymentTransaction.created_by_user_id`, `PaymentDocument.paid_amount` | Запретить регистрацию оплаты автором или согласующим платежа. |
| Подтверждение входящей оплаты | `payments.transaction.approve` | `PaymentConfirmationService`, `recipient_confirmed_by_user_id` | Запретить подтверждение со стороны не той организации и конфликт с payer-side actor. |
| Создание поставщика | `suppliers.create`, MDM `mdm.change_requests.create` | `Supplier`, `MdmChangeRequest` | Запретить создателю поставщика выбирать/согласовывать/оплачивать этого поставщика без exception. |
| Изменение реквизитов поставщика | Сейчас `suppliers.edit`; целевое `suppliers.requisites.edit` и MDM | `Supplier`, `Contractor`, MDM diff | High-risk изменения только через MDM CR, с запретом requester=approver=executor. |
| Создание закупочной заявки | `procurement.purchase_requests.create` | `PurchaseRequest`, будущий `created_by_user_id` | Автор заявки не может ее согласовать и выбирать победителя без независимого шага. |
| Согласование закупки | `procurement.purchase_requests.approve`, `procurement.approvals.resolve` | `ProcurementApproval`, `SupplierProposalDecision` | Сохранить и расширить `ProcurementDutySeparationService`. |
| Выбор поставщика | `procurement.proposal_decisions.select` | `SupplierProposalDecision.selected_by` | Selector не может approve procurement approval и платеж по этой цепочке. |
| Подтверждение заказа поставщику | `procurement.purchase_orders.confirm` | `PurchaseOrder`, будущий `confirmed_by_user_id` | Confirm actor не должен принимать материалы и регистрировать платеж. |
| Приемка | `procurement.purchase_orders.receive`, `warehouse.receipts` | `PurchaseReceipt.received_by_user_id`, `WarehouseMovement.user_id` | Receiver не может approve/register payment и списывать эти же материалы. |
| Складское списание | `warehouse.write_offs`, `warehouse.manage_stock` | `WarehouseMovement.user_id`, `operation_category`, партии | Запретить списание пользователем, который принял эти материалы или утвердил инвентаризацию. |
| Инвентаризация | `warehouse.inventory` | `InventoryAct.created_by`, `approved_by`, `InventoryActItem` | Создатель/заполняющий не должен утверждать акт; утверждение с расхождениями требует независимого approval. |
| Изменение лимитов | `budgeting.limits.manage`, `budgeting.limits.override` | Budget limit check, payment/procurement context | Лимитный override нельзя делать автору/согласующему исходного документа. |
| Закрытие бюджетного периода | `budgeting.periods.close` | `BudgetPeriodClosure.closed_by` | Закрывающий не должен быть автором/согласующим активных корректировок периода. |
| Переоткрытие периода | `budgeting.periods.reopen` | `BudgetPeriodClosure.reopened_until`, `metadata.allowed_operations` | Требует exception-like окна, независимого approving actor и короткого TTL. |
| Изменение мастер-данных | Текущие `mdm.change_requests.create`, `mdm.change_requests.submit`, `mdm.change_requests.review`, `mdm.change_requests.approve`, `mdm.change_requests.reject`, `mdm.change_requests.apply`, `mdm.change_requests.cancel`, `mdm.one_c.override`; кандидат PHERP-133 `mdm.records.direct_edit` | MDM CR requester/approver/executor/events | Requester не может approve/apply; high-risk apply должен быть отдельным actor. |

## 5. SoD-матрица несовместимых действий

### 5.1. Финансы и платежи

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-PAY-001 | Автор платежа согласует тот же платеж | `payments.invoice.create`, `payments.invoice.issue` + `payments.transaction.approve` | Фиктивный или ошибочный платеж без второго контроля | `hard_block` | `sod.exceptions.approve.finance` | Не применяется для hard block; emergency - до финализации документа, максимум 24 часа | Online |
| SOD-PAY-002 | Автор платежа регистрирует оплату по своему документу | `payments.invoice.create` + `payments.transaction.register` | Обход согласования и фиктивное закрытие задолженности | `exception_approval` | Финансовый директор или `organization_owner`, не участвовавший в документе | До финализации документа, максимум 7 дней | Online |
| SOD-PAY-003 | Согласующий платеж регистрирует оплату по тому же платежу | `payments.transaction.approve` + `payments.transaction.register` | Один пользователь подтверждает и факт оплаты, и разрешение на нее | `exception_approval` | `sod.exceptions.approve.finance` | До финализации документа, максимум 7 дней | Online |
| SOD-PAY-004 | Пользователь с admin bypass согласует свой платеж без отдельного exception | `organization_owner`, legacy `admin`, `finance_admin` + `payments.transaction.approve` | Молчаливый обход контрольной цепочки | `exception_approval` | Другой пользователь с finance exception permission; emergency admin только с audit reason | 24 часа | Online |
| SOD-PAY-005 | Пользователь менял реквизиты поставщика и согласует/регистрирует платеж этому поставщику | Целевое `suppliers.requisites.edit` или MDM CR apply + `payments.transaction.approve` / `payments.transaction.register` | Подмена реквизитов и вывод средств | `exception_approval` для обычных реквизитов, `hard_block` для банковских реквизитов без MDM | Финансовый директор + MDM steward, оба независимые | 7 дней или один payment document | Online при связке supplier/contractor; иначе periodic |
| SOD-PAY-006 | Пользователь создал поставщика и создает/согласует платеж ему в cooling-off период | `suppliers.create` + `payments.invoice.create` / `payments.transaction.approve` / `payments.transaction.register` | Создание фиктивного поставщика и платеж | `warning` для создания платежа, `exception_approval` для approve/register | Финансовый директор или procurement controller | 30 дней с момента создания поставщика; exception на один платеж | Online при supplier id; periodic для исторических связок |
| SOD-PAY-007 | Пользователь делает бюджетный override по платежу, который сам создал или согласовал | `budgeting.limits.override` + платежные действия | Обход лимитов через self-approval | `exception_approval` | Финансовый директор, не участвовавший в документе | Один limit check, максимум 7 дней | Online |
| SOD-PAY-008 | Подтверждение оплаты выполняет пользователь с конфликтом payer-side/recipient-side в одной организации | `payments.transaction.approve`, recipient confirmation | Самоподтверждение взаиморасчетов | `hard_block` при одном actor, `report_only` для косвенных связей | Не применяется, кроме emergency | 24 часа | Online + periodic |

### 5.2. Закупки

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-PROC-001 | Автор закупочной заявки согласует ту же заявку | `procurement.purchase_requests.create` + `procurement.purchase_requests.approve` | Завышение потребности и обход контроля | `hard_block` | Не применяется, emergency через procurement exception | 24 часа | Online |
| SOD-PROC-002 | Requester/selector/intake author согласует supplier proposal decision | `procurement.approvals.resolve` против `requested_by`, `selected_by`, intake author | Выбор выгодного поставщика без независимого контроля | `hard_block` | `sod.exceptions.approve.procurement`; `organization_owner` не должен быть silent bypass | Один approval, максимум 7 дней | Online |
| SOD-PROC-003 | Пользователь создал или изменил поставщика и выбирает его победителем | `suppliers.create` / `suppliers.edit`, `procurement.proposal_decisions.select` | Конфликт интересов при выборе поставщика | `exception_approval` | Procurement controller + finance controller | 30 дней с момента изменения поставщика или один procurement chain | Online при supplier party link; periodic иначе |
| SOD-PROC-004 | Пользователь выбирает поставщика и согласует закупку в той же цепочке | `procurement.proposal_decisions.select` + `procurement.approvals.resolve` | Self-approval выбора | `hard_block` | `sod.exceptions.approve.procurement` | Один approval, максимум 7 дней | Online |
| SOD-PROC-005 | Пользователь подтверждает заказ поставщику и принимает материалы по нему | `procurement.purchase_orders.confirm` + `procurement.purchase_orders.receive` | Подтверждение поставки без независимой приемки | `exception_approval` | Procurement controller или warehouse manager, не confirm actor | Один purchase order, максимум 7 дней | Online после добавления `confirmed_by_user_id`; periodic до этого |
| SOD-PROC-006 | Пользователь выбирал/согласовывал поставщика и затем согласует платеж по этому PO | `procurement.proposal_decisions.select` / `procurement.approvals.resolve` + `payments.transaction.approve` | Полный цикл выбор-платеж одним actor | `exception_approval` | Финансовый директор, не участвовавший в закупке | Один payment document | Online при связке PO -> payment; periodic иначе |
| SOD-PROC-007 | Пользователь создал закупочную заявку и делает лимитный override для нее или платежа по ней | `procurement.purchase_requests.create` + `budgeting.limits.override` | Обход бюджетных лимитов автором потребности | `exception_approval` | Финансовый controller | Один limit check | Online |

### 5.3. Склад и приемка

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-WH-001 | Приемщик материалов согласует или регистрирует платеж за эти материалы | `procurement.purchase_orders.receive`, `warehouse.receipts` + `payments.transaction.approve` / `payments.transaction.register` | Оплата непроверенной или фиктивной поставки | `exception_approval` | Финансовый директор или warehouse controller, не receiver | Один payment document | Online при PO/receipt/payment link |
| SOD-WH-002 | Приемщик списывает те же материалы из той же поставки/проекта/склада в cooling-off период | `warehouse.receipts` + `warehouse.write_offs` | Сокрытие недостачи или фиктивное потребление | `hard_block` для той же партии/receipt, `exception_approval` для той же связки проект-материал | Warehouse manager + finance controller | 7 дней или один movement | Online при batch/receipt link; periodic иначе |
| SOD-WH-003 | Пользователь выполняет приход и списание по одному материалу/складу без независимой проверки | `warehouse.receipts` + `warehouse.write_offs` | Манипуляция остатками | `warning` до появления партийной связи, `exception_approval` для high-value | Warehouse manager | 7 дней | Online для high-value; periodic для общего паттерна |
| SOD-WH-004 | Создатель инвентаризации утверждает акт | `warehouse.inventory` create + approve | Самоподтверждение расхождений | `hard_block` | Не применяется; emergency exception только при нулевых расхождениях | 24 часа | Online |
| SOD-WH-005 | Пользователь, который вносил фактические количества, утверждает акт с расхождениями | `warehouse.inventory` update item + approve | Подмена остатков через инвентаризацию | `hard_block` после добавления actor на item; пока `report_only` | Warehouse controller + finance controller | Один inventory act | Online после доработки данных |
| SOD-WH-006 | Пользователь выдает материалы ответственному и сам принимает возврат/списывает их | `warehouse.manage_stock`, custody issue/return, `warehouse.write_offs` | Необоснованное закрытие материальной ответственности | `exception_approval` | Warehouse manager, не actor и не responsible user | Один custody chain | Online |
| SOD-WH-007 | Роль совмещает `warehouse.manage_stock`, `warehouse.receipts`, `warehouse.write_offs`, `warehouse.inventory` без второго контроля | RoleDefinitions/custom roles | Складской пользователь может управлять всем циклом движения остатков | `report_only` | Не exception, а отчет по ролям | 30 дней для временного role exception | Periodic |

### 5.4. Бюджетирование и период

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-BUD-001 | Автор бюджетной версии согласует ее | `budgeting.budgets.create` / `budgeting.budgets.edit` / `budgeting.budgets.submit` + `budgeting.budgets.approve` | Завышение или скрытое перераспределение бюджета | `hard_block` | Finance exception для emergency only | 24 часа | Online |
| SOD-BUD-002 | Согласующий бюджетной версии активирует ее без второго контроля | `budgeting.budgets.approve` + `budgeting.budgets.activate` | Утверждение и ввод в действие одним actor | `exception_approval` | Финансовый директор или `organization_owner`, не approver | Один budget version | Online |
| SOD-BUD-003 | Пользователь редактирует лимиты и сам делает override по документу, использующему эти лимиты | `budgeting.limits.manage` + `budgeting.limits.override` | Управление правилом и обходом правила одним actor | `hard_block` для собственного документа, `exception_approval` для чужого документа | Finance controller | Один limit check | Online |
| SOD-BUD-004 | Пользователь закрывает период, где сам создавал/согласовывал активные корректировки или imports | `budgeting.periods.close` + budget/import actions | Закрытие периода с собственными изменениями без независимого контроля | `exception_approval` | Финансовый директор, не actor изменения | 48 часов | Online |
| SOD-BUD-005 | Пользователь переоткрывает период и сам вносит корректировки в разрешенном окне | `budgeting.periods.reopen` + allowed operations | Подмена данных после закрытия | `hard_block` для same actor, `exception_approval` для emergency | Финансовый директор + audit controller | Окно `reopened_until`, максимум 48 часов | Online |
| SOD-BUD-006 | Пользователь закрывает период и затем approve/register payment задним числом в этот период | `budgeting.periods.close` + payment approve/register | Изменение финансового факта после закрытия | `hard_block` без reopen window | Финансовый директор через period reopen | До конца reopen window | Online + periodic |

### 5.5. MDM и мастер-данные

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-MDM-001 | Requester MDM change request согласует свой CR | `mdm.change_requests.create` / `mdm.change_requests.submit` + `mdm.change_requests.approve` | Self-approval изменения мастер-данных | `hard_block` | Не применяется, кроме emergency | 24 часа | Online |
| SOD-MDM-002 | Requester или approver сам применяет high-risk MDM CR | `mdm.change_requests.apply` | Один actor меняет, согласует и применяет критичные данные | `exception_approval`; для банковских реквизитов `hard_block` без второго actor | MDM steward + finance controller | Один CR, максимум 7 дней | Online |
| SOD-MDM-003 | Пользователь с целевым `mdm.records.direct_edit` меняет данные, влияющие на платежи/закупки, и участвует в этих workflow | Кандидат PHERP-133 `mdm.records.direct_edit` + payments/procurement actions | Обход CR workflow и последующее использование изменения | `exception_approval` | MDM steward + `organization_owner` или finance controller | 7 дней | Online при audit link; periodic иначе |
| SOD-MDM-004 | Пользователь с `mdm.one_c.override` меняет source-of-truth поле и применяет связанный финансовый документ | `mdm.one_c.override` + payments/procurement/budget actions | Расхождение с 1C и финансовая операция на спорных данных | `hard_block` для active 1C conflict, иначе `exception_approval` | MDM steward + finance director | Один CR или 24 часа | Online |
| SOD-MDM-005 | Пользователь меняет статью бюджета/ЦФО и затем делает limit override или закрывает период | MDM budget article/CFO change + `budgeting.limits.override`, `budgeting.periods.close` | Манипуляция управленческой отчетностью | `exception_approval` | Finance controller, не requester/approver CR | Один период | Online + periodic |

### 5.6. Роли и системные полномочия

| ID | Несовместимость | Permissions/действия | Риск | Режим | Кто может выдать exception | TTL exception | Проверка |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SOD-RBAC-001 | Пользователь назначает себе роль или permission, которые закрывают SoD-конфликт | role management + domain permissions | Самоназначение обходных прав | `hard_block` | Security/system admin, не subject user | 24 часа для emergency role assignment | Online |
| SOD-RBAC-002 | RoleDefinitions/custom role совмещает создание, согласование и исполнение в одном домене | Домены payments, procurement, warehouse, budgeting, MDM; примеры `payments.invoice.create`, `payments.transaction.approve`, `procurement.proposal_decisions.select`, `warehouse.manage_stock`, `budgeting.budgets.approve`, `mdm.change_requests.apply` | Роль позволяет полный цикл без независимого actor | `report_only` + role heatmap | Не exception, а governance review | 30 дней для временного role exception | Periodic |
| SOD-RBAC-003 | Exception approver является участником конфликтующей цепочки | Новые PHERP-133 permissions `sod.exceptions.approve.finance`, `sod.exceptions.approve.procurement`, `sod.exceptions.approve.warehouse`, `sod.exceptions.approve.mdm`, `sod.exceptions.approve.period_close` + conflicting action | Исключение утверждает заинтересованный пользователь | `hard_block` | Другой approver той же области или emergency system approver | До 24 часов | Online |

## 6. Правила выдачи exceptions

### 6.1. Общие правила

Exception должен быть отдельным бизнес-объектом, а не неявным пропуском через широкую роль.

Обязательные ограничения:

- exception approver не может быть тем же пользователем, что actor текущего действия;
- exception approver не может быть conflict actor по этой SoD rule;
- exception approver не может быть автором, согласующим, применяющим, приемщиком или регистратором в той же цепочке;
- exception всегда имеет область: rule, subject type/id, action, organization, optional project, optional amount threshold;
- exception всегда имеет срок действия;
- exception всегда имеет бизнес-обоснование;
- использование exception фиксируется отдельно от выдачи exception;
- просроченный, отозванный или уже использованный one-time exception не должен разрешать действие.

### 6.2. Предлагаемые approver permissions

| Permission | Назначение |
| --- | --- |
| `sod.exceptions.request` | Запросить исключение по заблокированному действию. |
| `sod.exceptions.approve.finance` | Утверждать исключения по платежам, лимитам и финансовым документам. |
| `sod.exceptions.approve.procurement` | Утверждать исключения по закупкам и выбору поставщика. |
| `sod.exceptions.approve.warehouse` | Утверждать исключения по приемке, списаниям, инвентаризации и материальной ответственности. |
| `sod.exceptions.approve.mdm` | Утверждать исключения по MDM и реквизитам. |
| `sod.exceptions.approve.period_close` | Утверждать исключения по закрытию/переоткрытию периода. |
| `sod.exceptions.revoke` | Отзывать ранее выданные исключения. |
| `sod.exceptions.admin` | Emergency-режим, доступен только системному администратору или `organization_owner` с полным аудитом. |

### 6.3. TTL по умолчанию

| Область | TTL |
| --- | --- |
| Payment document | До финализации документа, максимум 7 календарных дней. |
| Procurement approval / PO | Один approval или один purchase order, максимум 7 календарных дней. |
| Warehouse movement / inventory act | Один movement или один inventory act, максимум 7 календарных дней. |
| Supplier requisites / MDM CR | Один CR или один связанный документ, максимум 7 календарных дней. |
| Supplier creation cooling-off | До 30 календарных дней с момента создания или изменения поставщика. |
| Budget period reopen | До `reopened_until`, максимум 48 часов. |
| Emergency admin | Максимум 24 часа. |
| Role-level exception | Максимум 30 календарных дней, только для report-only governance. |

## 7. Audit trail

PHERP-133 должен писать аудит в отдельный SoD-журнал и связывать его с существующими аудитами модулей.

### 7.1. SoD check event

Каждая online-проверка должна сохранять:

- `organization_id`;
- `project_id`, если применимо;
- `actor_user_id`;
- `action_key`;
- `permission_key`;
- `subject_type`;
- `subject_id`;
- `rule_id`;
- `severity`;
- `mode`;
- `result`: `allowed`, `warned`, `blocked`, `exception_required`, `exception_used`;
- `conflict_user_ids`;
- `conflict_actions`;
- `conflict_subjects`;
- `exception_id`, если использован;
- `reason_code`;
- `message_key`;
- snapshot проверяемых полей;
- request id, ip, user agent;
- `created_at`.

### 7.2. SoD exception

Exception должен хранить:

- `organization_id`;
- `rule_id`;
- `scope_type`;
- `scope_id`;
- `requested_action`;
- `requested_by_user_id`;
- `approved_by_user_id`;
- `rejected_by_user_id`;
- `revoked_by_user_id`;
- `status`;
- `reason`;
- `business_justification`;
- `valid_from`;
- `valid_until`;
- `used_at`;
- `used_by_user_id`;
- `metadata`.

### 7.3. Связь с существующим аудитом

SoD-события должны быть связаны с:

- `PaymentAuditLog` для платежей;
- `ProcurementAuditEvent`/Activity events для закупок;
- `WarehouseMovement`, `ProjectMaterialDeliveryEvent`, `InventoryAct` для склада;
- `MdmChangeRequestEvent` и MDM change log для MDM;
- `BudgetVersion.workflow_history`, `BudgetPeriodClosure` и бюджетный audit для бюджетирования.

Пользовательские сообщения в API/UI должны быть человекочитаемыми и переведенными. Нельзя показывать пользователю `fallback`, `payload`, `exception`, `sql`, `constraint`, технические slug-и permissions или внутренние class names как основной текст.

## 8. Online vs periodic/report-only

### 8.1. Online-проверки в workflow

Online должны выполняться до изменения состояния:

- платеж: submit, approve, reject, schedule, register payment, recipient confirmation;
- закупки: approve purchase request, select supplier proposal, resolve procurement approval, confirm PO, receive materials, create payment from PO;
- склад: receipt, write-off, transfer, custody issue/return, inventory approve;
- бюджет: submit/approve/activate budget version, limit override, close period, reopen period;
- MDM: submit, start review, approve, apply, direct edit, 1C override;
- RBAC: назначение роли самому себе или выдача permission, создающего SoD-конфликт.

Online check должен возвращать workflow-friendly результат:

```json
{
  "sod_result": {
    "status": "blocked",
    "mode": "exception_approval",
    "rule_id": "SOD-PAY-003",
    "severity": "high",
    "message": "Для этого действия требуется независимое подтверждение.",
    "can_request_exception": true,
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
```

### 8.2. Periodic/report-only проверки

Periodic проверки нужны для:

- role heatmap по `RoleDefinitions` и custom roles;
- пользователей с широкими wildcard permissions;
- старых документов без actor fields;
- косвенных связок supplier -> contractor -> payment, где нет прямого FK;
- повторяющихся exceptions одним пользователем;
- операций после закрытия периода;
- складских приходов/списаний без партийной связки;
- данных, где actor хранится только в metadata;
- контроля, что новые SoD permissions переведены в `lang/ru/permissions.php`.

Периодический отчет должен показывать:

- количество конфликтов по доменам;
- критичность;
- пользователей и роли;
- правила;
- связанные документы;
- долю warning/hard block/exception/report-only;
- просроченные или часто используемые exceptions;
- рекомендации по разнесению ролей.

## 9. Новые permissions

### 9.1. SoD core permissions

| Permission | Назначение |
| --- | --- |
| `sod.rules.view` | Просмотр правил SoD. |
| `sod.rules.manage` | Управление настройками правил SoD. |
| `sod.checks.view` | Просмотр журнала проверок SoD. |
| `sod.violations.view` | Просмотр нарушений SoD. |
| `sod.violations.export` | Экспорт нарушений SoD. |
| `sod.exceptions.request` | Запрос исключения. |
| `sod.exceptions.approve.finance` | Согласование финансовых исключений. |
| `sod.exceptions.approve.procurement` | Согласование закупочных исключений. |
| `sod.exceptions.approve.warehouse` | Согласование складских исключений. |
| `sod.exceptions.approve.mdm` | Согласование MDM-исключений. |
| `sod.exceptions.approve.period_close` | Согласование исключений по закрытию периода. |
| `sod.exceptions.revoke` | Отзыв исключений. |
| `sod.exceptions.admin` | Emergency-исключения с усиленным аудитом. |
| `sod.reports.view` | Просмотр SoD-отчетов. |
| `sod.reports.export` | Экспорт SoD-отчетов. |

### 9.2. Желательные доменные permissions

| Permission | Зачем нужен |
| --- | --- |
| `payments.transaction.confirm_payment` | Разделить согласование платежа и подтверждение факта оплаты, если `payments.transaction.approve` сейчас используется неоднозначно. |
| `payments.approvals.override_admin` | Явно отделить emergency/admin override от обычного согласования. |
| `suppliers.requisites.edit` | Отделить контактные данные поставщика от юридических/платежных реквизитов. |
| `suppliers.requisites.approve` | Согласование изменения реквизитов поставщика. |
| `suppliers.bank_accounts.manage` | Управление банковскими счетами поставщика, если они будут вынесены в отдельную сущность. |
| `procurement.supplier_parties.link` | Контроль связывания external supplier party с зарегистрированным поставщиком. |
| `warehouse.write_offs.approve` | Разнести создание списания и утверждение списания. |
| `warehouse.inventory.approve` | Разнести создание/заполнение и утверждение инвентаризации. |
| `budgeting.limits.exception_approve` | Отделить настройку лимитов от approval их превышения. |
| `budgeting.periods.close_override` | Emergency-контроль операций с закрытым периодом. |
| `mdm.change_requests.approve_high_risk` | Отдельный approval для high-risk MDM изменений. |
| `mdm.change_requests.apply_high_risk` | Отдельное применение high-risk MDM изменений. |
| `mdm.records.direct_edit` | Уже переведен, но сейчас не назначен системным ролям и не используется в admin MDM routes; в PHERP-133 нужно явно решить, включать ли прямое редактирование безопасных MDM-полей и как его аудитировать. |
| `mdm.source_of_truth.override` | Единое имя для override source-of-truth. В текущем коде уже есть `mdm.one_c.override`; в PHERP-133 нужно выбрать итоговое именование и миграционный путь. |

Все новые permissions должны быть добавлены в `config/RoleDefinitions` и `lang/ru/permissions.php` с русскими подписями.

## 10. Данные, нужные для PHERP-133 enforcement

### 10.1. Уже есть

| Домен | Данные |
| --- | --- |
| Платежи | `PaymentDocument.created_by_user_id`, `approved_by_user_id`, `recipient_confirmed_by_user_id`, `budget_limit_overridden_by_user_id`; `PaymentTransaction.created_by_user_id`, `approved_by_user_id`; `PaymentApproval`. |
| Закупки | `ProcurementApproval.requested_by/approved_by/rejected_by`, `SupplierProposalDecision.selected_by`, `PurchaseReceipt.received_by_user_id`, procurement audit events. |
| Склад | `WarehouseMovement.user_id`, `related_user_id`, `project_material_delivery_id`; `ProjectMaterialDelivery.receiver_user_id`, events; `InventoryAct.created_by`, `approved_by`. |
| Бюджетирование | `BudgetVersion.created_by/submitted_by/approved_by/activated_by`, workflow history; `BudgetPeriodClosure.closed_by/reopened_until/metadata`; budget limit check/reservation. |
| MDM | `MdmChangeRequest.requested_by_user_id/owner_user_id/approver_user_id/executor_user_id/reviewed_by_user_id`, events, diff, impact, validation, 1C lock. |

### 10.2. Не хватает или нужно нормализовать

| Домен | Недостающие данные |
| --- | --- |
| Закупки | `PurchaseRequest.created_by_user_id`, `approved_by_user_id`; `PurchaseOrder.created_by_user_id`, `sent_by_user_id`, `confirmed_by_user_id`; нормализованная связь PO -> payment document. |
| Поставщики | `Supplier.created_by_user_id`, `updated_by_user_id`; field-level audit; классификация sensitive fields; отдельные сущности банковских реквизитов, если они появятся. |
| Склад | `InventoryAct.completed_by`; `InventoryActItem.updated_by_user_id`; batch/receipt link для списаний; approval workflow для списаний с расхождениями/high-value. |
| Бюджетирование | Кто именно менял лимит/строку бюджета; audit event по изменению лимитов; связь limit check -> исходный procurement/payment actor chain. |
| MDM | Risk classification по diff fields; явная пометка high-risk CR; связь MDM entity -> supplier/contractor/material/budget article/period. |
| RBAC | История назначений ролей и actor назначения; текущие custom roles с permissions snapshot. |

### 10.3. Связи объектов

PHERP-133 должен уметь строить цепочки:

- supplier -> supplier party -> proposal -> proposal decision -> purchase request -> purchase order -> receipt -> payment document -> transaction;
- contractor/supplier MDM record -> платежи/договоры/закупки;
- material MDM record -> purchase receipt -> warehouse movement -> write-off -> project material delivery;
- budget article/CFO/period -> budget version -> limit check -> payment/procurement document;
- user -> role assignments -> permissions -> SoD rules -> action history.

## 11. API требования для PHERP-133

Все endpoints должны использовать `AdminResponse` и возвращать переводимые человекочитаемые сообщения.

Предлагаемый namespace:

```text
/api/v1/admin/sod
```

Endpoints:

| Method | Path | Permission | Назначение |
| --- | --- | --- | --- |
| `GET` | `/rules` | `sod.rules.view` | Список правил, режимов, severity, областей применения. |
| `PUT` | `/rules/{ruleId}` | `sod.rules.manage` | Изменение режима правила, threshold, report-only/online flags. |
| `POST` | `/checks/preview` | domain permission + `sod.checks.view` | Предпросмотр результата SoD для действия без изменения состояния. |
| `GET` | `/checks` | `sod.checks.view` | Журнал проверок. |
| `GET` | `/violations` | `sod.violations.view` | Реестр нарушений и блокировок. |
| `GET` | `/violations/{id}` | `sod.violations.view` | Детальная карточка нарушения. |
| `POST` | `/exceptions` | `sod.exceptions.request` | Запрос exception. |
| `GET` | `/exceptions` | approver permissions | Очередь и история exceptions. |
| `POST` | `/exceptions/{id}/approve` | Один из новых PHERP-133 permissions `sod.exceptions.approve.finance`, `sod.exceptions.approve.procurement`, `sod.exceptions.approve.warehouse`, `sod.exceptions.approve.mdm`, `sod.exceptions.approve.period_close` | Утверждение exception. |
| `POST` | `/exceptions/{id}/reject` | Один из новых PHERP-133 permissions `sod.exceptions.approve.finance`, `sod.exceptions.approve.procurement`, `sod.exceptions.approve.warehouse`, `sod.exceptions.approve.mdm`, `sod.exceptions.approve.period_close` | Отклонение exception. |
| `POST` | `/exceptions/{id}/revoke` | `sod.exceptions.revoke` | Отзыв exception. |
| `GET` | `/reports/summary` | `sod.reports.view` | Сводка нарушений и role heatmap. |
| `GET` | `/reports/export` | `sod.reports.export` | Экспорт отчета. |

### 11.1. Контракт ответа workflow endpoints

Если доменный endpoint блокируется SoD, он должен возвращать:

- HTTP `409` для hard block/exception required;
- `data.sod_result`;
- `message` с понятным текстом;
- `errors` только для field validation, не для бизнес-SoD.

Если действие разрешено с warning:

- основной workflow продолжает выполнение;
- `data.sod_result.status = "warned"`;
- audit event пишется обязательно.

## 12. Admin UI требования

### 12.1. SoD control center

В админке нужен раздел контроля SoD:

- вкладка правил;
- вкладка нарушений;
- вкладка exceptions;
- вкладка отчетов;
- вкладка аудита;
- role heatmap по пользователям и ролям.

### 12.2. Inline workflow UX

В платежах, закупках, складе, бюджетировании и MDM:

- показывать блокировку до выполнения действия;
- показывать бизнес-причину, а не технический slug;
- давать кнопку запроса исключения только если правило допускает `exception_approval`;
- в форме запроса exception требовать обоснование, срок, область действия;
- показывать, кто может согласовать exception;
- после согласования возвращать пользователя к исходному действию.

### 12.3. Экран exceptions

Нужны фильтры:

- область: финансы, закупки, склад, MDM, бюджет;
- статус;
- severity;
- rule id;
- пользователь;
- организация/проект;
- срок действия;
- просроченные/использованные/отозванные.

### 12.4. Role heatmap

Нужен отчет:

- роль;
- контекст;
- конфликтующие permissions;
- домен;
- уровень риска;
- количество пользователей с ролью;
- рекомендации: оставить report-only, разделить роль, добавить exception workflow, убрать permission.

## 13. Тестовые сценарии для PHERP-133

### 13.1. Платежи

1. Пользователь создает payment document и пытается согласовать его - получает `SOD-PAY-001`.
2. Пользователь создает payment document и пытается зарегистрировать оплату - получает `SOD-PAY-002`.
3. Пользователь согласовал payment document и пытается зарегистрировать оплату - получает `SOD-PAY-003`.
4. `finance_admin` или `organization_owner` с admin bypass пытается согласовать свой платеж - требуется recorded exception, молчаливого bypass нет.
5. Пользователь изменил реквизиты поставщика через MDM и пытается согласовать платеж этому поставщику - требуется finance+MDM exception.
6. Действительный exception позволяет выполнить только один payment action в рамках scope.
7. Просроченный exception не позволяет выполнить действие.

### 13.2. Закупки

1. Автор purchase request пытается согласовать свою заявку - hard block.
2. Selector supplier proposal пытается approve procurement approval - hard block.
3. Intake author supplier proposal пытается approve procurement approval - hard block.
4. `organization_owner` не получает silent bypass для high-risk supplier decision; нужен recorded exception.
5. Пользователь создал поставщика и пытается выбрать его победителем в течение cooling-off периода - требуется exception.
6. Пользователь выбрал поставщика и пытается согласовать платеж по этому PO - требуется finance exception.

### 13.3. Склад

1. Receiver по purchase order пытается approve/register payment по этому PO - требуется exception.
2. Receiver пытается списать тот же материал из той же поставки - hard block.
3. Пользователь создает inventory act и пытается его утвердить - hard block.
4. Пользователь вносит фактические количества и утверждает акт с расхождениями - hard block после добавления `updated_by_user_id`.
5. Пользователь выдал материалы ответственному и сам закрывает возврат/списание - требуется warehouse exception.

### 13.4. Бюджетирование

1. Автор budget version отправляет и затем пытается approve - hard block.
2. Approver budget version пытается activate ту же версию - требуется exception.
3. Пользователь делает limit override по платежу, который сам создал - требуется exception.
4. Пользователь закрывает период, где сам делал активные budget/import корректировки - требуется period close exception.
5. Пользователь переоткрыл период и сам вносит изменения в allowed window - hard block.

### 13.5. MDM

1. Requester MDM CR пытается approve свой CR - hard block.
2. Approver high-risk CR пытается сам apply - требуется независимый executor или exception.
3. Если PHERP-133 включает `mdm.records.direct_edit`, пользователь с этим правом меняет поставщика и затем согласует платеж этому поставщику - требуется exception.
4. Active 1C conflict блокирует MDM source-of-truth override и связанные платежи.

### 13.6. RBAC и отчеты

1. Пользователь пытается назначить себе роль с конфликтующими permissions - hard block.
2. Periodic report подсвечивает `web_admin`, `organization_admin`, `organization_owner`, `finance_admin`, `accountant`, `project_manager`, `foreman`, `supplier` как роли с повышенным SoD-риском.
3. Report-only проверка не ломает текущий workflow, но создает violation record.
4. API ролей и SoD reports возвращают русские подписи permissions.
5. SoD check пишет audit event для `allowed`, `warned`, `blocked`, `exception_required`, `exception_used`.
6. При отсутствии actor fields система не разрешает риск молча: online check возвращает controlled warning/report-only marker, а periodic report фиксирует data gap.
7. Два параллельных запроса на использование одного one-time exception не могут оба пройти.

## 14. Рекомендации по реализации PHERP-133

1. Добавить SoD domain service поверх `AuthorizationService`.
2. Оставить `AuthorizationService` ответственным за базовый RBAC/ABAC, а SoD service - за объектные конфликты и историю действий.
3. Встроить online checks в workflow services до изменения статусов и до записи финансовых/складских фактов.
4. Убрать silent bypass из критичных сценариев; широкий admin/`organization_owner` доступ должен превращаться в recorded exception.
5. Сначала покрыть online правила с уже доступными actor fields: платежи, procurement approvals, MDM CR, budget versions, period closure, warehouse movements.
6. Затем добавить недостающие actor fields в закупках, поставщиках, инвентаризации и складских связках.
7. Добавить таблицы/модели для SoD checks, violations и exceptions.
8. Добавить permissions и русские подписи в `lang/ru/permissions.php`.
9. Сделать periodic report и role heatmap до включения hard block для role-level конфликтов.
10. Добавить contract tests для API shape через `AdminResponse` и feature tests для ключевых workflow-нарушений.

## 15. Границы PHERP-132

PHERP-132 только фиксирует матрицу и требования. В рамках этой задачи не нужно:

- менять `RoleDefinitions`;
- добавлять permissions;
- менять `lang/ru/permissions.php`;
- создавать миграции;
- менять services/controllers/models;
- запускать миграции, DB-команды, dev-server или build.

Для будущего коммита файл нужно добавить принудительно, потому что markdown игнорируется:

```bash
git add -f docs/specs/PHERP-132-sod-matrix-finance-procurement-warehouse.md
```
