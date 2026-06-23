# PHERP-130: Data stewardship и change request process для мастер-данных

## Статус и цель

Документ фиксирует техническую спецификацию PHERP-130 для будущей реализации. В рамках этой задачи production-код не меняется: не добавляются миграции, модели, API, сервисы, UI-компоненты, фоновые задачи и не запускаются команды, работающие с БД.

Цель будущей реализации по этой спецификации - запретить неконтролируемое изменение критичных мастер-данных ProHelper ERP и ввести управляемый процесс: владелец данных, заявка на изменение, согласование, проверка влияния, блокировки для 1С-синхронизируемых данных, audit trail, timeline событий и стратегия отката.

Проблема enterprise-уровня: после закрытия цепочки `CRM / Tenders / CommercialProposals / Projects / Contracts / Budgeting` мастер-данные стали источником влияния на проекты, договоры, управленческий бюджет, платежи, склад, закупки, документы и 1С-обмен. Прямое редактирование юридических реквизитов, кодов, единиц измерения, бюджетных аналитик, складских идентификаторов и внешних соответствий может изменить смысл уже созданных документов или сломать интеграции.

## Что изучено в текущем коде

### MDM

- MDM routes: `prohelper/routes/api/v1/admin/mdm.php`.
- MDM module: `prohelper/app/BusinessModules/Core/Mdm`.
- `MdmEntityRegistry` сейчас регистрирует `contractor`, `supplier`, `material`, `measurement_unit`, `work_type`, `cost_category`, `estimate_position`, `estimate_position_category`.
- `MdmRecord` уже хранит `owner_user_id`, `version`, `quality_score`, `quality_issues`, `normalized_values`, `status`, `last_synced_at`.
- `MdmChangeRequest` уже есть, но покрывает только базовые `pending / approved / rejected`, `current_values`, `proposed_values`, `requested_by_user_id`, `reviewed_by_user_id`.
- `MdmChangeLog` уже хранит `before_values`, `after_values`, action, пользователя и metadata.
- `MdmRelationshipService` синхронизирует связи для `work_type_materials`, `estimate_position_catalog`, `warehouse_identifiers`, `warehouse_balances`, `purchase_request_lines`, `estimate_item_resources`.
- `MdmMergePlanner` уже умеет планировать влияние merge для подрядчиков, материалов, единиц измерения и видов работ.
- `MdmCatalogObserver` автоматически синхронизирует MDM-записи при сохранении зарегистрированных справочников.

Вывод: реализация должна расширять существующий MDM-модуль, а не создавать параллельный механизм.

### RBAC и AuthorizationService

- Права MDM уже заведены в `config/RoleDefinitions/admin/web_admin.json` и `config/RoleDefinitions/lk/organization_admin.json`.
- Существующие права: `mdm.view`, `mdm.manage`, `mdm.duplicates.resolve`, `mdm.import.preview`, `mdm.import.apply`, `mdm.change_requests.review`, `mdm.archive`, `mdm.owners.assign`, `mdm.merge.apply`.
- Переводы групп MDM есть в `prohelper/lang/ru/permissions.php`.
- Проверка прав идет через `authorize:*` middleware и `App\Domain\Authorization\Services\AuthorizationService`.
- `AuthorizationService` работает с системными и кастомными ролями, контекстами организации/проекта и ABAC-условиями через назначение ролей.

Вывод: новые права должны быть слагами permissions, а не проверками конкретных ролей. Владелец, согласующий и исполнитель определяются данными заявки и permissions, а не `role_slug` в PHP.

### Audit, logging и timeline

- `LoggingService::audit()` пишет audit log и через `ActivityAuditBridge` может создавать `activity_events`.
- `ActivityEventRecorder` и `ActivityEventData` уже поддерживают actor, module, event_type, action, severity, subject, changes, context, IP, user agent, correlation id.
- В платежах есть отдельный `PaymentAuditLog`.
- В procurement есть `ProcurementAuditEvent`.
- В MDM есть `MdmChangeLog`, но нет отдельной timeline-таблицы заявки.

Вывод: MDM change request должен писать и прикладной immutable timeline, и общий audit/activity слой.

### Связанные домены

- `Project` хранит `external_code`, `cost_category_id`, `accounting_data`, `use_in_accounting_reports`, customer fields, contract number/date, плановую стоимость проекта.
- `Contract` связан с проектом, подрядчиком, поставщиком, актами, платежами, сметами, спецификациями, распределениями и event sourcing состоянием.
- `Contractor` хранит юридические реквизиты, `contractor_type`, `source_organization_id`, `sync_settings`, `last_sync_at`, умеет синхронизироваться из source organization.
- `Supplier`, `SupplierParty`, `ExternalSupplierContact` участвуют в закупочной цепочке и снапшотах поставщика.
- `Material` хранит `external_code`, SBIS-коды, `accounting_data`, `accounting_account`, нормы расхода, связь с единицей измерения и складом.
- `MeasurementUnit` используется материалами, видами работ и сметными позициями.
- `WorkType` используется выполненными работами и нормами материалов.
- `CostCategory` связана с проектами и бюджетными статьями.
- `BudgetArticle`, `ResponsibilityCenter`, `BudgetArticleMapping`, `BudgetLine`, `BudgetAmount` формируют управленческий бюджет. `BudgetLineService` требует draft-версию, активную leaf-статью, активный ЦФО, org-scoped project/contract/counterparty и открытый период.
- `PaymentDocument` связан с проектом, договором, контрагентами, бюджетной статьей, ЦФО и бюджетными лимитами.
- `CounterpartyAccount` хранит финансовое состояние контрагента: задолженность, кредитный лимит, блокировку, сроки оплаты.
- `OrganizationWarehouse`, `WarehouseZone`, `WarehouseStorageCell`, `WarehouseIdentifier`, `WarehouseBalance`, `WarehouseMovement` формируют складской контур и мобильное сканирование.
- Procurement использует `PurchaseRequestLine.material_id`, `PurchaseOrder.supplier_id / supplier_party_id / external_supplier_contact_id`, `PurchaseOrderItem.material_id`, `PurchaseReceipt.warehouse_id`.
- 1С-контур содержит `OneCExchangeMapping`, `OneCExchangeOperation`, `OneCExchangeConflict`, `OneCExchangeScope`. Scope уже покрывает `counterparties`, `organizations`, `projects`, `contracts`, `materials`, `nomenclature`, `cost_categories`, `cost_centers`, `warehouses`, `payment_documents`, `advance_transactions`, `procurement_documents`, `warehouse_documents`.

## Целевой охват мастер-данных и владельцы

Владелец данных - пользователь или группа пользователей, отвечающие за качество и смысл справочника. Технически владелец назначается через `mdm_records.owner_user_id` для конкретной записи и через entity policy для справочника. В коде нельзя привязываться к названиям ролей.

| Мастер-данные | Текущий источник | Data owner по умолчанию | Основной риск |
| --- | --- | --- | --- |
| Контрагенты и подрядчики | `contractors`, CRM linked contractor | Финансы / договорной контур | Юридические реквизиты, договоры, платежи, акты, 1С |
| Поставщики | `suppliers`, `supplier_parties`, `external_supplier_contacts` | Закупки / финансы | Заказы, предложения, приходки, платежи, снапшоты поставщика |
| CRM-компании и контакты | `crm_companies`, `crm_contacts` | CRM owner | Конвертация в проект/договор, дубли, персональные данные |
| Проекты | `projects` | PMO / руководитель проекта | Бюджет, договоры, склады объектов, графики, журналы, 1С |
| Договоры | `contracts` | Договорной контур / финансы | Акты, платежи, закупки, сметы, распределения, 1С |
| Материалы и номенклатура | `materials` | Снабжение / склад | Остатки, движения, закупки, сметы, нормы расхода, 1С |
| Единицы измерения | `measurement_units` | Справочники / склад | Пересчет количеств, сметы, материалы, работы |
| Виды работ | `work_types` | ПТО / сметный контур | Выполненные работы, нормы материалов, сметы, графики |
| Сметные позиции и категории | `estimate_position_catalog`, `estimate_position_catalog_categories` | ПТО / сметный контур | Импорт смет, проектные сметы, соответствия работам и единицам |
| Категории затрат | `cost_categories` | Финансы / PMO | Проекты, бюджетные статьи, управленческая аналитика, 1С |
| Бюджетные статьи | `budget_articles` | Финансы | Budget lines, платежи, лимиты, план-факт, 1С mapping |
| ЦФО | `responsibility_centers` | Финансы / руководитель ЦФО | Бюджеты, платежи, dashboard CFO, закрытые периоды |
| 1С-соответствия | `one_c_exchange_mappings` | Интеграции / бухгалтерия | Неверная синхронизация, дубли, conflicts, dead-letter |
| Склады | `organization_warehouses` | Склад / снабжение | Остатки, движения, объектовые склады, приходки, мобильные операции |
| Зоны и ячейки хранения | `warehouse_zones`, `warehouse_storage_cells` | Склад | Адресное хранение, сканирование, перемещения |
| Складские идентификаторы | `warehouse_identifiers` | Склад | QR/barcode/RFID, мобильное сканирование, traceability |
| Финансовые настройки контрагента | `counterparty_accounts`, contractor bank fields, payment settings | Финансы | Кредитный лимит, блокировки, реквизиты платежей, сверка |

## Политика полей

Реализация должна ввести entity policy на уровне MDM, например `MdmEntityGovernanceRegistry`. Policy не является RBAC-ролью. Она описывает поля, владельца по умолчанию, scope 1С, impact analyzer и правила прямого редактирования.

Пример структуры policy:

```php
[
    'entity_type' => 'material',
    'model' => Material::class,
    'one_c_scope' => 'materials',
    'owner_permission' => 'mdm.materials.own',
    'direct_fields' => ['description', 'notes', 'photo_gallery', 'additional_properties.ui_notes'],
    'change_request_fields' => ['name', 'code', 'measurement_unit_id', 'external_code', 'accounting_account', 'is_active'],
    'locked_fields' => ['organization_id'],
    'critical_fields' => ['code', 'measurement_unit_id', 'external_code'],
    'impact_analyzer' => MaterialImpactAnalyzer::class,
]
```

### Можно менять напрямую

Прямое изменение допустимо только для полей, которые не меняют учетный смысл записи и не влияют на документы:

- описания, внутренние заметки, теги, UI-метаданные;
- контактные поля CRM, если компания не связана с contractor/organization и изменение не касается юридических реквизитов;
- фото и вложения материала, если они не являются обязательным документом учета;
- неключевые display-поля складской зоны, если зона не участвует в открытых движениях;
- настройки уведомлений или dashboard, если они не меняют справочник.

Даже прямое изменение должно писать `MdmChangeLog` через observer/sync и общий audit при наличии пользователя.

### Только через change request

Через заявку должны идти поля, которые являются идентификаторами, внешними соответствиями, учетной аналитикой, иерархией или влияют на документы:

- юридические реквизиты: `name`, `legal_name`, `inn`, `kpp`, `ogrn`, адреса, банковские реквизиты;
- `external_code`, `accounting_data`, `accounting_account`, SBIS/1С-коды;
- material `code`, `measurement_unit_id`, `consumption_rates`, `is_active`;
- measurement unit `name`, `short_name`, `type`, `is_system`, `is_default`;
- work type `code`, `measurement_unit_id`, нормы материалов;
- cost category `code`, `external_code`, `parent_id`, `is_active`;
- budget article `code`, `name`, `parent_id`, `budget_kind`, `flow_direction`, `is_leaf`, `cost_category_id`, `is_active`;
- responsibility center `code`, `name`, `parent_id`, `center_type`, `owner_user_id`, `approver_user_id`, `linked_entity_type`, `linked_entity_id`, active period;
- project `external_code`, customer fields, `cost_category_id`, `use_in_accounting_reports`, `accounting_data`, contract number/date;
- contract `number`, `date`, `contractor_id`, `supplier_id`, side/category, subject, payment terms, amounts, status-affecting fields;
- warehouse `code`, `warehouse_type`, `project_id`, `responsible_user_id`, `is_main`, `is_active`;
- warehouse cell/zone `code`, address parts, status, capacity constraints;
- warehouse identifier `code`, `identifier_type`, `entity_type`, `entity_id`, `is_primary`, `status`;
- `one_c_exchange_mappings` active local/external links;
- counterparty credit limit, block status/reason, payment terms and bank реквизиты.

### Нельзя менять обычной заявкой

Некоторые поля должны быть либо неизменяемыми, либо требовать отдельного специализированного workflow:

- `organization_id` у всех org-scoped сущностей;
- первичные ключи и UUID;
- ссылку на уже оплаченный/проведенный платежный документ как способ "перекинуть" историю на другого контрагента;
- поля закрытых бюджетных периодов без reopen flow;
- данные posted складских движений и приходок без корректирующего документа;
- 1С mapping с активным unresolved conflict без решения конфликта;
- system measurement unit, если она используется в документах и нет approved replacement.

## Модель заявки на изменение

Существующую таблицу `mdm_change_requests` нужно расширить, а не заменять.

### Поля заявки

Рекомендуемые поля:

- `id`, `uuid`.
- `organization_id`.
- `mdm_record_id` nullable для create-заявок.
- `entity_type`, `entity_id`.
- `action`: `create`, `update`, `archive`, `merge`, `split`, `replace_reference`, `rollback`.
- `status`: `draft`, `submitted`, `under_review`, `approved`, `rejected`, `applied`, `failed`, `cancelled`.
- `priority`: `low`, `normal`, `high`, `urgent`.
- `title`, `reason`, `business_justification`.
- `current_values`: snapshot текущих значений на момент draft/submit.
- `proposed_values`: целевые значения.
- `diff`: нормализованный список изменений по полям.
- `field_policy_version`: версия policy, по которой рассчитана заявка.
- `impact_snapshot`: результат before/after impact analysis.
- `validation_snapshot`: результат доменных проверок.
- `one_c_lock_summary`: есть ли активный mapping/conflict/operation и какая стратегия нужна.
- `rollback_snapshot`: данные, необходимые для обратной заявки.
- `apply_result`: результат применения и ссылки на MDM/1С операции.
- `failure_reason`: человекочитаемая причина сбоя.
- `requested_by_user_id`.
- `owner_user_id`: data owner записи или справочника.
- `approver_user_id`: пользователь, принявший решение.
- `executor_user_id`: пользователь или системный actor, применивший изменение.
- `cancelled_by_user_id`.
- `submitted_at`, `under_review_at`, `approved_at`, `rejected_at`, `applied_at`, `failed_at`, `cancelled_at`.
- `review_note`, `apply_note`, `cancel_reason`.
- `expected_record_version`: optimistic lock версии `mdm_records.version`.
- `idempotency_key`, `payload_hash`.
- timestamps.

### Timeline событий

Нужна отдельная таблица `mdm_change_request_events`:

- `id`, `organization_id`, `change_request_id`.
- `event_type`: `draft_created`, `field_changed`, `submitted`, `impact_recalculated`, `review_started`, `approved`, `rejected`, `apply_started`, `applied`, `failed`, `cancelled`, `rollback_requested`.
- `actor_user_id`.
- `from_status`, `to_status`.
- `comment`.
- `changes`.
- `metadata`: correlation id, IP, user agent, related 1С operation/conflict ids.
- `created_at`.

Эта timeline нужна для карточки заявки. `MdmChangeLog` остается журналом фактических изменений записи.

## Workflow

Целевой workflow:

```text
draft -> submitted -> under_review -> approved -> applied
                                 \-> rejected
draft/submitted/under_review -> cancelled
approved -> failed
failed -> under_review | cancelled
applied -> rollback request
```

### Draft

Draft создается из карточки MDM-записи, формы редактирования справочника или отдельного реестра заявок.

Backend:

- загружает запись в рамках текущей организации;
- проверяет field policy;
- строит `current_values`, `proposed_values`, `diff`;
- считает первичный impact;
- назначает `owner_user_id` из `mdm_records.owner_user_id`, entity policy или linked owner, например `responsibility_centers.owner_user_id`;
- сохраняет заявку в `draft`.

Черновик можно редактировать только инициатору, владельцу данных или пользователю с правом управления заявками.

### Submitted

Submit фиксирует payload:

- пересчитывает diff и impact;
- проверяет, что нет конфликтующей активной заявки на те же critical fields той же записи;
- сохраняет `expected_record_version`;
- переводит статус в `submitted`;
- пишет timeline и audit.

После submit нельзя менять `proposed_values`. Нужно создать новую версию заявки или отменить и создать новую.

### Under review

Владелец данных или пользователь с правом review переводит заявку в `under_review`.

Backend должен вернуть карточку со всеми блокерами:

- какие документы затронуты;
- какие поля требуют согласования;
- есть ли активные 1С mapping/conflict/operation;
- есть ли закрытые периоды, posted движения, paid платежи;
- можно ли применить автоматически или нужен manual executor.

### Approved / rejected

Approve:

- доступен только пользователю с правом approve и, для critical changes, не должен быть self-approval инициатором без отдельного permission override;
- фиксирует `approver_user_id`, `approved_at`, `review_note`;
- не применяет изменение сразу, если policy требует отдельного executor или 1С-проверку.

Reject:

- требует `review_note`;
- сохраняет причину в timeline;
- не меняет target entity.

### Applied

Apply выполняет `MdmChangeRequestApplyService`:

1. Блокирует заявку и target record через transaction/row lock.
2. Проверяет статус `approved`.
3. Повторно читает target entity и `MdmRecord`.
4. Проверяет `expected_record_version`; при расхождении переводит в `failed` с причиной "Запись изменилась после подачи заявки".
5. Повторно запускает impact validation.
6. Проверяет 1С-lock policy.
7. Применяет изменение через доменный service, а не массовый `fill()` в обход правил.
8. Синхронизирует `MdmRecord`.
9. Пишет `MdmChangeLog`, `mdm_change_request_events`, `LoggingService::audit()` и `ActivityEvent`.
10. Если нужен обмен с 1С, создает outbox/operation после успешного commit или помечает `apply_result.one_c_required=true`.

Если локальное применение падает внутри transaction, данные откатываются и заявка становится `failed`. Если локальное применение успешно, а 1С-доставка упала, заявка остается `applied`, но `apply_result.one_c_status` указывает на problem operation/conflict. Откат в таком случае идет отдельной rollback-заявкой.

### Cancelled

Отмена доступна инициатору до `approved`, владельцу данных или пользователю с `mdm.change_requests.cancel`. Для `approved` отмена разрешена только до apply и требует причины.

## Проверка влияния before/after

Нужен `MdmImpactAnalysisService` с analyzer-классами по entity type. Результат должен быть сохраняемым snapshot, чтобы reviewer видел то же состояние, которое согласовывал. Перед apply impact пересчитывается, а различия добавляются в `validation_snapshot`.

### Формат impact

```json
{
  "summary": {
    "risk_level": "high",
    "affected_records_total": 184,
    "blocking": true,
    "requires_manual_executor": true,
    "requires_one_c_review": true
  },
  "before": {
    "display_name": "ООО СтройСнаб",
    "quality_score": 82,
    "one_c_mapping_status": "active"
  },
  "after": {
    "display_name": "ООО СтройСнаб-Проект",
    "quality_score": 85,
    "one_c_mapping_status": "requires_export"
  },
  "impacts": [
    {
      "domain": "payments",
      "label": "Платежи",
      "count": 42,
      "severity": "critical",
      "blocker": true,
      "reason": "Есть оплаченные документы, юридические реквизиты меняются только новой версией контрагента"
    }
  ],
  "recommended_action": "create_new_version_or_counterparty"
}
```

### Analyzer matrix

| Entity type | Проверяемые связи |
| --- | --- |
| `contractor` | `contracts.contractor_id`, `payment_documents.contractor_id/payer/payee`, `payment_transactions`, `counterparty_accounts`, CRM linked company, contractor invitations/verifications, 1С mapping `counterparties` |
| `supplier` | `contracts.supplier_id`, `purchase_orders.supplier_id`, `supplier_parties.registered_supplier_id`, supplier requests/proposals, 1С mapping `counterparties` |
| `supplier_party` / external supplier | supplier requests, proposals, decisions, purchase orders, snapshots, procurement audit |
| `crm_company` | deals, tenders, commercial proposals, linked contractor/organization, merge history |
| `project` | contracts, estimates, schedules, construction journals, completed works, budget lines, payment documents, warehouses, material deliveries, 1С mapping `projects` |
| `contract` | acts, payment documents/schedules/transactions, estimates, contract allocations, procurement orders, budget lines, 1С mapping `contracts` |
| `material` | warehouse balances/movements, identifiers/assets, procurement lines/orders/receipts, estimate item resources, journal materials, work type material norms, 1С mapping `materials/nomenclature` |
| `measurement_unit` | materials, work types, estimate positions, procurement/order snapshots |
| `work_type` | completed works, estimate positions, schedule tasks/resources, work type materials |
| `cost_category` | projects, budget articles, 1С cost categories |
| `budget_article` | budget lines, budget amounts, budget limit reservations/checks, payment documents, mappings to 1С |
| `responsibility_center` | budget lines, payment documents, CFO dashboards, linked entity, active period |
| `warehouse` | balances, movements, zones, cells, tasks, receipts, material deliveries, 1С warehouses |
| `warehouse_zone/cell` | balances/movements/tasks if адресное хранение используется |
| `warehouse_identifier` | scan events, logistic units, bound material/asset/warehouse entity |
| `one_c_mapping` | operations, conflicts, duplicate warnings, active profile/base |
| `counterparty_account` | payment documents, overdue invoices, credit limit, blocked status, reconciliation |

## Блокировки для 1С-синхронизируемых данных

Поле считается 1С-синхронизируемым, если выполняется хотя бы одно условие:

- у entity есть active `OneCExchangeMapping` по `local_type/local_id`;
- entity содержит `external_code`, `accounting_data`, `accounting_account`, SBIS/1С-коды;
- entity type входит в `OneCExchangeScope`;
- есть незавершенная `OneCExchangeOperation` по этой сущности;
- есть открытый `OneCExchangeConflict`.

Правила:

- Нельзя напрямую менять `external_code`, active mapping и accounting fields.
- Если есть открытый conflict, change request нельзя применить до решения конфликта или явного override-права.
- Если есть processing/retry operation, заявка остается `approved`, но apply блокируется до финального статуса операции.
- Если изменение меняет поле, экспортируемое в 1С, apply создает 1С operation с idempotency key, связанным с `mdm_change_request.uuid`.
- Если 1С delivery disabled, заявка может быть approved, но apply должен либо блокироваться, либо требовать `mdm.change_requests.apply_without_one_c` с явным audit.
- UI показывает бизнес-текст: "Данные связаны с 1С. Перед применением нужно проверить обмен", без сырых payload, SQL, exception и token details.

## Rollback strategy

Откат applied-заявки не должен молча менять данные назад. Для enterprise-аудита откат - это новая заявка `action=rollback`, где `proposed_values` строятся из `rollback_snapshot`.

Правила:

- Если apply упал до commit, данные откатываются transaction-ом, rollback-заявка не нужна.
- Если apply завершился локально, откат создается как новая change request с обратным diff.
- Если после apply была создана 1С operation, rollback запрещен до финального статуса операции или создает compensating operation после approval.
- Если затронуты paid платежи, posted складские движения, закрытые бюджетные периоды или подписанные договорные документы, rollback заменяется корректирующим workflow: новая версия контрагента, корректирующая статья/ЦФО, корректировочный документ склада или дополнительное соглашение.
- Откат merge должен использовать сохраненный merge plan и не восстанавливать soft-deleted дубли без отдельной проверки ссылок.
- Любой rollback пишет timeline, `MdmChangeLog` и audit.

## RBAC без хардкода ролей

Новые permissions:

- `mdm.change_requests.view`
- `mdm.change_requests.create`
- `mdm.change_requests.submit`
- `mdm.change_requests.review`
- `mdm.change_requests.approve`
- `mdm.change_requests.reject`
- `mdm.change_requests.apply`
- `mdm.change_requests.cancel`
- `mdm.change_requests.rollback`
- `mdm.change_requests.impact.view`
- `mdm.change_requests.one_c.override`
- `mdm.records.direct_edit`
- `mdm.records.field_policy.manage`
- `mdm.owners.assign`

Совместимость:

- На первом этапе `mdm.view` может давать read-only доступ к реестру и карточке.
- `mdm.manage` может временно покрывать create/submit для backward compatibility.
- `mdm.change_requests.review` остается alias для approve/reject до миграции RoleDefinitions.

Правила проверки:

- Все routes защищаются `authorize:*`.
- В service-слое дополнительно проверяется организация, owner и запрет self-approval для critical changes.
- Owner может смотреть и комментировать свои записи, если у него есть `mdm.change_requests.view` или entity-specific owner permission.
- Approver не определяется по role slug. Он определяется через `AuthorizationService::can($user, 'mdm.change_requests.approve', ['organization_id' => ...])`.
- Для 1С override используется отдельное право, а не `mdm.manage`.
- Новые permissions добавляются в `lang/ru/permissions.php`, RoleDefinitions и тест PermissionTranslator, чтобы UI не получал технические ключи вместо русских названий.

## Backend API contract

Все ответы идут через `AdminResponse`.

### `GET /api/v1/admin/mdm/entities`

Расширить существующий ответ:

```json
{
  "data": {
    "material": {
      "title": "Материалы и складская номенклатура",
      "display_field": "name",
      "owner_policy": {
        "default_owner_source": "entity_policy",
        "assignable": true
      },
      "field_policy": {
        "direct_fields": ["description", "additional_properties"],
        "change_request_fields": ["name", "code", "measurement_unit_id", "external_code"],
        "locked_fields": ["organization_id"],
        "critical_fields": ["code", "measurement_unit_id", "external_code"]
      },
      "one_c_scope": "materials"
    }
  }
}
```

### `POST /api/v1/admin/mdm/change-requests/preview`

Право: `mdm.change_requests.create`.

Request:

```json
{
  "entity_type": "material",
  "entity_id": 15,
  "action": "update",
  "proposed_values": {
    "name": "Цемент М500",
    "measurement_unit_id": 3
  }
}
```

Response:

```json
{
  "data": {
    "current_values": {},
    "proposed_values": {},
    "diff": [
      {
        "field": "measurement_unit_id",
        "label": "Единица измерения",
        "before": 2,
        "after": 3,
        "policy": "change_request",
        "critical": true
      }
    ],
    "impact": {},
    "blockers": [],
    "warnings": []
  }
}
```

### `POST /api/v1/admin/mdm/change-requests`

Право: `mdm.change_requests.create`.

Создает draft. Для backward compatibility существующий `submitChangeRequest` можно оставить, но новый контракт должен разделять draft и submit.

### `GET /api/v1/admin/mdm/change-requests`

Право: `mdm.change_requests.view`.

Фильтры:

- `status`
- `entity_type`
- `priority`
- `owner_user_id`
- `requested_by_user_id`
- `approver_user_id`
- `q`
- `has_blockers`
- `requires_one_c_review`
- `created_from`, `created_to`
- `page`, `per_page`

Response должен быть paginated и содержать `summary`:

```json
{
  "data": [{ "id": 10, "status": "submitted" }],
  "meta": { "current_page": 1, "per_page": 25, "total": 1 },
  "summary": {
    "by_status": { "submitted": 1 },
    "urgent_count": 0,
    "blocked_count": 0,
    "requires_one_c_review_count": 0
  }
}
```

### `GET /api/v1/admin/mdm/change-requests/{id}`

Право: `mdm.change_requests.view`.

Возвращает:

- заявку;
- entity display payload;
- diff with labels;
- impact;
- one_c_lock_summary;
- timeline;
- available_actions с permission slugs и enabled/disabled reasons.

### Mutations

- `PATCH /api/v1/admin/mdm/change-requests/{id}` - редактировать draft.
- `POST /api/v1/admin/mdm/change-requests/{id}/submit` - `draft -> submitted`.
- `POST /api/v1/admin/mdm/change-requests/{id}/start-review` - `submitted -> under_review`.
- `POST /api/v1/admin/mdm/change-requests/{id}/approve` - approve.
- `POST /api/v1/admin/mdm/change-requests/{id}/reject` - reject with note.
- `POST /api/v1/admin/mdm/change-requests/{id}/apply` - apply approved request.
- `POST /api/v1/admin/mdm/change-requests/{id}/cancel` - cancel with reason.
- `POST /api/v1/admin/mdm/change-requests/{id}/rollback-preview` - построить обратный diff.
- `POST /api/v1/admin/mdm/change-requests/{id}/rollback` - создать rollback-заявку.
- `GET /api/v1/admin/mdm/change-requests/{id}/timeline` - timeline событий.

Mutation response всегда возвращает обновленную карточку заявки, чтобы UI не собирал состояние по частям.

### `GET /api/v1/admin/mdm/records/{record}/impact`

Право: `mdm.change_requests.impact.view`.

Нужен для карточки записи и предупреждений перед редактированием. Поддерживает optional `proposed_values` для before/after анализа.

## Admin UI

Текущий admin UI уже имеет страницы:

- `/catalogs/mdm`
- `/catalogs/mdm/records`
- `/catalogs/mdm/records/:recordId`
- `/catalogs/mdm/duplicates`
- `/catalogs/mdm/import`
- `/catalogs/mdm/change-requests`

Реализация должна развить существующий `MdmChangeRequestsPage`, а не создавать отдельный раздел вне MDM.

### Реестр заявок

Экран должен быть операционным:

- PageHeader с названием `Заявки на изменение мастер-данных`.
- Summary strip: всего, на рассмотрении, срочные, заблокированные, требуют 1С-проверки, просроченные.
- Tabs/status segmented control: `Все`, `Черновики`, `На согласовании`, `На проверке`, `Согласованы`, `Применены`, `Отклонены`, `С ошибкой`.
- Фильтры: справочник, владелец, инициатор, приоритет, риск, 1С, период.
- DataGrid с колонками: номер, справочник, запись, действие, приоритет, статус, владелец, инициатор, риск, влияние, 1С, дата подачи, срок.
- Quick actions только если backend `available_actions` разрешает действие.
- Empty state без технического текста: "Заявок на изменение нет".

### Карточка заявки

Карточка должна быть cockpit-экраном:

- Header: номер, статус, приоритет, справочник, запись, owner, текущий следующий шаг.
- Блок `Изменения`: diff before/after с человекочитаемыми labels, группировкой critical/non-critical и подсветкой поля.
- Блок `Влияние`: доменные карточки `Проекты`, `Договоры`, `Платежи`, `Бюджет`, `Склад`, `Закупки`, `1С`, с count/severity/blocker.
- Блок `Проверки`: blockers и warnings.
- Блок `1С`: mapping status, active operation/conflict, next action, без raw payload.
- Timeline: draft, submit, review, approve/reject, apply/failed/cancel, comments.
- Actions: submit, start review, approve, reject, apply, cancel, rollback. Важные действия через dialog с причиной/комментарием.

### Карточка MDM-записи

Существующий `MdmRecordDetailPage` нужно расширить:

- показать владельца данных и кнопку назначения owner по `mdm.owners.assign`;
- показать impact summary;
- показать последние change requests и MdmChangeLog;
- для critical fields вместо inline edit показывать действие `Создать заявку на изменение`;
- для direct fields оставить обычное редактирование при наличии права.

### Встраивание в формы справочников

При попытке изменить critical field в существующих формах справочников:

- frontend получает field policy из `/mdm/entities`;
- direct fields отправляются в текущий endpoint справочника;
- critical fields формируют draft/preview change request;
- пользователь видит бизнес-текст: "Это изменение затрагивает связанные документы. Создайте заявку на согласование."

UI не должен показывать пользователю внутренние слова `payload`, `fallback`, `legacy`, `sql`, `constraint`, `exception`.

## Доменное применение изменений

Apply не должен делать универсальный `Model::fill($proposed_values)->save()` для всех сущностей. Нужно маршрутизировать изменения через доменные сервисы:

- contractors/suppliers - service со scoped validation и проверкой дублей ИНН;
- materials/measurement units/work types - catalog services и warehouse impact;
- budget articles/CFO - BudgetCatalogService и `BudgetPeriodClosureService`;
- projects - `ProjectService` и правила PHERP-151 для плановой стоимости;
- contracts - `ContractService`, `ContractSideMutationService`, supplementary agreement logic при необходимости;
- warehouses - BasicWarehouse services, без прямой правки posted movement history;
- one_c mappings - `OneCMappingService` и conflict service.

Если доменный service отсутствует, сначала нужно добавить его в реализации, а не применять изменения напрямую из MDM.

## Тестовые сценарии

### Backend unit

- Field policy классифицирует direct/change_request/locked поля.
- Draft строит корректный diff и назначает owner.
- Submit запрещает дублирующую активную заявку на тот же critical field.
- Workflow запрещает недопустимые переходы.
- Approve запрещает self-approval для critical changes без override.
- Apply проверяет `expected_record_version`.
- Apply пишет `MdmChangeLog`, timeline и audit.
- Failed apply не оставляет частично измененную target entity.
- Rollback preview строит обратный diff.
- 1С lock service блокирует open conflict/processing operation.
- Impact analyzers считают ссылки по каждому поддержанному entity type.

### Backend feature/API

- `GET /mdm/entities` возвращает field policy без технических классов в UI payload.
- `POST /change-requests/preview` возвращает diff, impact, blockers.
- `POST /change-requests/{id}/submit` переводит `draft -> submitted`.
- `approve/reject/apply/cancel` проверяют permissions через `AuthorizationService`.
- Paginated registry возвращает `data`, `meta`, `summary`.
- Org-scoping запрещает доступ к заявке другой организации.
- PermissionTranslator тест подтверждает русские названия новых permissions.

### Domain integration

- Contractor legal name/INN change with paid payment documents блокируется или требует new version strategy.
- Material measurement unit change with warehouse balances блокируется.
- BudgetArticle `flow_direction` change with existing budget lines блокируется.
- ResponsibilityCenter inactive period change with budget lines in closed period блокируется.
- Project `external_code` change with active 1С mapping требует one_c review.
- Warehouse code change with active identifiers требует заявку и impact warning.

### Admin UI

- Реестр показывает loading/error/empty/populated states.
- Filters сохраняют состояние и не ломают пагинацию.
- Detail page показывает diff before/after и impact groups.
- Approve/reject/apply dialogs требуют комментарий там, где это нужно.
- UI скрывает действия без permission.
- UI безопасно отображает отсутствующие optional поля.
- Service layer нормализует paginated `AdminResponse`.

### Edge cases

- Target record удалена или архивирована после submit.
- Target record изменилась между submit и apply.
- Две заявки меняют одно поле одной записи.
- Заявка создает запись с дублем normalized_key.
- Owner снят или пользователь заблокирован до review.
- 1С operation ушла в dead-letter после локального apply.
- 1С delivery disabled.
- Связанный budget period закрыт после approve, но до apply.
- Контрагент входит в holding/invited organization sync и поле приходит из source organization.
- Supplier snapshot уже попал в sent supplier request/proposal.
- Material участвует в posted purchase receipt и warehouse movement.
- У measurement unit `is_system=true`.
- Mobile user сканирует warehouse identifier во время изменения статуса.
- Rollback пытается вернуть значение, которое уже занято другой записью.
- Импорт MDM создает batch с critical updates и должен создавать заявки, а не применять их напрямую.

## Implementation plan

1. Расширить MDM schema: поля `mdm_change_requests`, новая `mdm_change_request_events`, индексы по status/priority/owner/entity, payload hash/idempotency.
2. Добавить `MdmEntityGovernanceRegistry`, field policies и owner resolver.
3. Добавить `MdmDiffService`, `MdmImpactAnalysisService`, analyzers и `MdmOneCLockService`.
4. Переписать `MdmChangeRequestService` под draft/submit/review/approve/apply/cancel/rollback.
5. Добавить domain appliers по entity type.
6. Расширить routes и request/resource классы.
7. Добавить permissions, RoleDefinitions, переводы и PermissionTranslator tests.
8. Обновить admin types/services/pages MDM.
9. Добавить backend и frontend tests.
10. Прогнать `php -l`, targeted `phpstan analyse`, `npx tsc --noEmit`, targeted Vitest, UTF-8/mojibake scan и `git diff --check`.

## Acceptance criteria

- Critical master data fields cannot be changed directly through admin endpoints or UI.
- Every critical change has owner, initiator, approver, executor, status, priority, diff, impact snapshot and timeline.
- Apply is transactional locally, idempotent and protected by optimistic version checks.
- 1С-synced records are blocked or explicitly routed through one_c review/export flow.
- Impact analysis covers projects, contracts, counterparties, budget articles, CFO, payments, procurement, warehouse and documents.
- Applied changes write `MdmChangeLog`, timeline, audit log and activity event.
- Rollback is handled as a new auditable change request.
- RBAC uses permission slugs and `AuthorizationService`; no PHP role slug hardcode.
- Admin UI has registry, detail card, diff, impact, timeline and approval actions.
- User-facing texts are business-readable and do not expose internal technical terms.
- Markdown spec remains UTF-8 and, because specs are ignored by `.gitignore`, future commit uses `git add -f docs/specs/PHERP-130-master-data-ownership-change-requests.md`.
