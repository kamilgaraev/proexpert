# Permission-Based Procurement Payment Route

## Workflow tracker

YouTrack: [PHERP-152](https://prohelper.youtrack.cloud/issue/PHERP-152) фиксирует продуктовый маршрут материальной заявки, закупки, счета, согласования и оплаты по правам.

## Цель

Маршрут материальной заявки должен читаться как один процесс: заявка с площадки, выбор источника обеспечения, закупка, заказ поставщику, счет на оплату, согласование, регистрация оплаты и приемка материалов.

Backend является источником истины для текущего шага, блокеров и доступных действий. Admin UI не вычисляет следующий платежный шаг по статусу локально, а использует `action_summary` и `procurement_chain_summary`.

## Роли и права

Действия оплаты больше не завязаны на slug-и ролей `chief_accountant`, `financial_director`, `general_director`.

Используются существующие права:

- `payments.invoice.create` — создать или открыть счет на оплату по заказу поставщику.
- `payments.invoice.issue` — передать платежный документ на согласование.
- `payments.transaction.approve` — согласовать платежный документ.
- `payments.transaction.register` — зарегистрировать факт оплаты.
- `procurement.purchase_orders.receive` — принять материалы после выполнения платежного gate.
- `warehouse.manage_stock` — складская ветка обеспечения.
- `procurement.purchase_requests.create` — закупочная ветка обеспечения.

Если пользователь имеет нужное право, действие доступно независимо от названия роли. Если права нет, backend возвращает отключенное действие и человекочитаемый blocker.

## Payment Approval

Новые строки `payment_approvals` создаются с:

- `approval_permission = payments.transaction.approve`;
- `approval_role = null`;
- `approver_user_id = null`, если шаг доступен всем пользователям организации с нужным правом.

`approval_role` остается только для старых строк и обратной совместимости. Новая логика approval queue и approve/reject проверяет `approval_permission`.

Уровни согласования остаются amount-based:

- до 50 000 — один уровень;
- от 50 000 до 500 000 — два уровня;
- выше 500 000 — три уровня.

## Procurement Chain Stages

Платежная часть цепочки отображается явными route-stage ключами:

- `payment_document_missing` — счет еще не создан;
- `payment_document_draft` — счет создан, но не передан на согласование;
- `payment_approval_required` — счет ожидает согласования;
- `payment_approved` — счет согласован, оплату нужно зарегистрировать;
- `payment_partially_registered` — оплата зарегистрирована частично;
- `payment_registered` — оплата достаточна для перехода к приемке.

Persisted domain status документов не меняется. Эти ключи используются только для отображения маршрута и CTA.

## Unified Action Contract

Все основные кнопки используют один shape:

```ts
type WorkflowAction = {
  key: string;
  label: string;
  href: string | null;
  method: 'GET' | 'POST' | string;
  required_permission: string | null;
  is_enabled: boolean;
  disabled_reason: string | null;
  scope?: string | null;
  priority?: number | null;
};
```

`action_summary`:

```ts
type ActionSummary = {
  primary_action: WorkflowAction | null;
  secondary_actions: WorkflowAction[];
  menu_actions: WorkflowAction[];
  blockers: Array<{ key: string; message: string; severity: string }>;
};
```

Admin normalizes missing summaries to:

```ts
{
  primary_action: null,
  secondary_actions: [],
  menu_actions: [],
  blockers: []
}
```

## API Changes

### PaymentDocumentResource

Возвращает `action_summary`:

- `submit_payment_document` для draft, право `payments.invoice.issue`;
- `approve_payment_document` для submitted/pending approval, право `payments.transaction.approve`;
- `register_payment` для approved/scheduled/partially paid с остатком к оплате, право `payments.transaction.register`;
- `primary_action = null`, если документ полностью оплачен или действие не требуется.

Связанный с закупкой документ также содержит компактный `procurement_chain_summary`.

### Procurement chain endpoints

Для заказа поставщику chain возвращает:

- нет счета: `create_or_open_payment_document`;
- draft-счет: `submit_payment_document`;
- счет на согласовании: `approve_payment_document`;
- согласованный неоплаченный счет: `register_payment`;
- оплаченный заказ: `receive_materials`.

### Create or open payment document

`POST /api/v1/admin/procurement/purchase-orders/{id}/payment-document`

Поддерживает:

- `budget_article_id`;
- `responsibility_center_id`;
- `budget_override_reason`;
- `submit_after_create`;
- банковские поля получателя: `bank_account`, `bank_bik`, `bank_correspondent_account`, `bank_name`.

Ответ содержит:

- `payment_document`;
- `payment_action_summary`;
- `procurement_chain_summary`;
- `submitted`.

Если `submit_after_create = true`, backend проверяет `payments.invoice.issue` и после создания счета отправляет его на согласование. Если права нет, создается или открывается черновик, а маршрут показывает следующий доступный шаг.

## Admin UI

### Site request detail

Шапка заявки использует `request.action_summary.primary_action`. Если backend вернул действие закупочной или платежной цепочки, верхняя кнопка совпадает с нижним маршрутом.

Fallback-кнопки по статусу заявки остаются только для старого ответа без `action_summary`.

### Procurement chain panel

Панель цепочки выполняет действия через общий executor:

- `determine_fulfillment_source`;
- `approve_purchase_request`;
- `send_supplier_request`;
- `accept_proposal`;
- `create_or_open_payment_document`;
- `submit_payment_document`;
- `approve_payment_document`;
- `register_payment`;
- `receive_materials`.

### Purchase order detail

Действие оплаты называется по бизнес-смыслу: `Счет на оплату`.

Диалог создания счета показывает:

- номер и сумму заказа;
- поставщика;
- проект;
- требуемую дату;
- бюджетную статью и ЦФО;
- опцию `Сразу передать на согласование`, если есть `payments.invoice.issue`;
- пояснение про черновик, если права передачи на согласование нет.

### Financial command center

Карточка платежного документа использует `document.action_summary.primary_action` для действий:

- передать на согласование;
- согласовать оплату;
- зарегистрировать оплату.

Для документов из закупки показывается контекст:

- этап маршрута закупки;
- следующий шаг;
- заказ поставщику;
- поставщик;
- проект;
- исходные заявки с площадки.

## Material Fulfillment Branch

Материальная заявка после approval не должна автоматически превращаться в закупку, если включен режим решения источника обеспечения. Снабженец выбирает:

- склад;
- закупка;
- смешанный сценарий.

Складская ветка требует `warehouse.manage_stock`, закупочная — `procurement.purchase_requests.create`, смешанная — оба права.

## Тестовое покрытие

Backend:

- `PaymentApprovalPermissionWorkflowTest`;
- `ProcurementPaymentRouteTest`;
- `PaymentApprovalControllerWorkflowTest`;
- `ProcurementChainControllerTest`;
- `ProcurementChainServiceTest`.

Admin:

- `SiteRequestHeader.test.tsx`;
- `ProcurementChainPanel.test.tsx`;
- `PurchaseOrderPaymentDocumentDialog.test.tsx`;
- `PaymentPages.test.tsx`;
- `npx tsc --noEmit`.

## Ограничения текущего релиза

- Mobile UI не меняется.
- Старые role-based approval rows продолжают работать как legacy compatibility.
- Route-stage ключи не являются новыми persisted статусами.
- Dev-сервер и production миграции не запускаются из локального Codex окружения.
