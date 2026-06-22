# PHERP-134: Immutable audit layer ключевых ERP-операций

## 1. Статус и цель

Документ фиксирует проектную спецификацию PHERP-134: единый неизменяемый аудит критичных операций в платежах, бюджетировании, MDM, RBAC, интеграциях 1С, складе, CRM и закрытии периодов.

Фактический scope задачи PHERP-134 в YouTrack: спроектировать immutable audit layer, который сохраняет actor, timestamp, before/after, reason, correlation id, source, organization/project context, retention, защиту от изменения и поиск. В рамках PHERP-134 production-код, миграции, API, UI, роли, `RoleDefinitions` и `lang/ru/permissions.php` не меняются. Все новые классы, таблицы, права и маршруты ниже являются целевым контрактом для PHERP-135/следующей реализации.

Критерий готовности PHERP-134: для каждой критичной операции понятно, какой неизменяемый след должен быть записан, какие поля обязательны, где находится источник фактических данных, как событие защищается от изменения, кто может его искать и какие проверки должны доказать целостность.

## 2. Фактическая кодовая база

### 2.1. Общий аудит и бизнес-логи

В проекте уже есть несколько журналов, но они не образуют единый immutable layer:

- `activity_events` создается миграцией `database/migrations/2026_05_08_000001_create_activity_events_table.php`.
- `ActivityEventData`, `ActivityEventRecorder`, `ActivityAuditBridge`, `ActivityEventQueryService` находятся в `app/DTOs/Activity` и `app/Services/Activity`.
- `ActivityEventRedactor` и `SensitiveDataRedactor` уже маскируют пароли, токены, телефоны, email, паспортные данные, банковские реквизиты и другие чувствительные поля.
- `LoggingService::audit()` пишет audit log и через `ActivityAuditBridge` может создать `activity_events`.
- `config/logging.php` задает daily JSON-каналы `audit`, `business`, `security`, `technical`, `access` с retention на уровне файлов.
- API журнала действий расположен в `routes/api/v1/admin/activity.php` и контроллере `ActivityEventController`.
- Для просмотра уже используются права `system-logs.activity-events.view` и `system-logs.activity-events.export`.

Пробелы для PHERP-134:

- `activity_events` можно обновлять и удалять обычными средствами Eloquent/SQL, отдельной DB-level защиты от `UPDATE`/`DELETE` нет.
- Нет hash-chain, seal/anchor событий, отдельной проверки целостности и публичного integrity status.
- Не все домены передают обязательные `reason`, `before_state`, `after_state`, `source` и `correlation_id`.
- File-based audit log не дает удобного доменного поиска по subject, actor, project, before/after и не является единым ERP-контрактом.

### 2.2. Платежи

Текущий аудит платежей:

- `PaymentAuditLog` и `PaymentAuditService` находятся в `app/BusinessModules/Core/Payments`.
- Таблица `payment_audit_logs` есть в миграциях `app/BusinessModules/Core/Payments/migrations/2025_11_20_000009_create_payment_audit_logs_table.php` и `database/migrations/2025_11_21_000006_create_payment_audit_logs_table.php`.
- Поля журнала: `organization_id`, `payment_document_id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `changed_fields`, `description`, `ip_address`, `user_agent`, `metadata`.
- `PaymentDocumentObserver` пишет события create/update/delete.
- `PaymentDocumentService`, `ApprovalWorkflowService`, `PaymentConfirmationService`, `PaymentBudgetLimitService` меняют workflow и лимитные состояния.

Actor-поля уже есть:

- `PaymentDocument.created_by_user_id`;
- `PaymentDocument.approved_by_user_id`;
- `PaymentDocument.recipient_confirmed_by_user_id`;
- `PaymentDocument.budget_limit_overridden_by_user_id`;
- `PaymentDocument.budget_limit_override_reason`;
- `PaymentTransaction.created_by_user_id`;
- `PaymentTransaction.approved_by_user_id`;
- `PaymentApproval.approver_user_id`, `decided_at`, `status`.

Пробелы для PHERP-134:

- `payment_audit_logs` не является immutable storage.
- `correlation_id`, `source`, chain hash и integrity status не являются обязательными.
- Не все high-risk действия имеют обязательное бизнес-обоснование: approve/reject, override лимита, register payment, cancel, reschedule, refund, reconciliation, export to 1C.

### 2.3. Бюджетирование и закрытие периодов

Ключевые точки:

- `BudgetWorkflowService::transitionVersion`;
- `BudgetPeriodClosureService::close`;
- `BudgetPeriodReopenService::reopen`;
- `BudgetLimitCheckService`;
- `PaymentBudgetLimitService`;
- миграция `app/BusinessModules/Features/Budgeting/migrations/2026_06_08_000001_create_budgeting_tables.php`.

Actor-поля уже есть:

- `BudgetVersion.created_by`, `submitted_by`, `approved_by`, `activated_by`;
- `BudgetVersion.workflow_history`;
- `BudgetPeriod.created_by`, `updated_by`;
- `BudgetPeriodClosure.closed_by`, `closed_at`, `reopened_until`, `metadata`;
- `BudgetLimitReservation.created_by_user_id`;
- cash gap opening balance поля `created_by_user_id`, `approved_by_user_id`;
- `PaymentDocument.budget_limit_overridden_by_user_id`.

Текущие права:

- `budgeting.audit.view`;
- `budgeting.wip_forecast.view_audit`.

Пробелы для PHERP-134:

- Общий immutable audit endpoint для бюджетирования отсутствует.
- Закрытие/переоткрытие периода должно фиксировать before/after period state, affected operations, reason, TTL окна переоткрытия, actor и correlation id.
- В `workflow_history` уже есть история, но это поле бизнес-сущности, а не независимый неизменяемый журнал.

### 2.4. MDM

Ключевые файлы:

- `MdmChangeRequestService`;
- `MdmChangeRequest`;
- `MdmChangeRequestEvent`;
- `MdmChangeLog`;
- `MdmDiffService`;
- `MdmImpactAnalysisService`;
- `MdmOneCLockService`;
- `MdmDomainChangeApplier`;
- `routes/api/v1/admin/mdm.php`;
- миграции `2026_05_16_000000_create_mdm_core_tables.php` и `2026_06_21_000000_extend_mdm_change_requests_for_governance.php`.

Уже есть сильная база для аудита:

- `MdmChangeRequest.requested_by_user_id`, `owner_user_id`, `approver_user_id`, `executor_user_id`, `cancelled_by_user_id`;
- `MdmChangeRequest.reason`, `business_justification`, `failure_reason`, review/apply/cancel notes;
- `MdmChangeRequest.diff`, `impact_snapshot`, `validation_snapshot`, `one_c_lock_summary`, `rollback_snapshot`, `apply_result`;
- `MdmChangeRequest.payload_hash`, `idempotency_key`, `expected_record_version`;
- `MdmChangeRequestEvent.actor_user_id`, `event_type`, `before_status`, `after_status`, `comment`, `metadata`;
- `MdmChangeLog.before_values`, `after_values`, `changed_by_user_id`, `metadata`;
- MDM API истории: `GET /api/v1/admin/mdm/history`, `GET /api/v1/admin/mdm/change-requests/{changeRequest}/timeline`.

Фактические управляемые MDM-сущности в `MdmEntityGovernanceRegistry`: `contractor`, `supplier`, `budget_article`, `responsibility_center`, `project`, `contract`.

Пробелы для PHERP-134:

- `MdmChangeLog` отключает только `updated_at`, но это не запрещает изменение или удаление строки.
- `MdmChangeRequestEvent` остается обычной Eloquent-моделью.
- Нет unified immutable chain, отдельного correlation id и `source` для всех MDM timeline events.
- Нет отдельного права `mdm.audit.view`; текущая история завязана на MDM workflow permissions.

### 2.5. RBAC, роли и permissions

Фактическая база:

- RBAC проверяется через `App\Domain\Authorization\Services\AuthorizationService`.
- Системные роли описаны в `config/RoleDefinitions/{admin,lk,mobile,project,system}`.
- Русские подписи permissions находятся в `lang/ru/permissions.php`.
- `AuthorizationService::assignRole()` уже пишет audit-событие `auth.role.assigned`.
- В `ActivityAuditBridgeTest` покрыт сценарий `user.admin.role.revoked`.

Пробелы для PHERP-134:

- Нет отдельного immutable audit trail для назначения, отзыва, изменения срока, условий или context ролей.
- Изменения JSON-файлов `RoleDefinitions` и переводов permissions не имеют runtime immutable-событий; это должно фиксироваться через deployment metadata или отдельный release audit event в следующей реализации.
- SoD enforcement из PHERP-133 пока описан спецификацией; runtime-модуль `Core/Sod` в коде не найден. Интеграция SoD events с immutable audit является новым для PHERP-135/следующей реализации.

### 2.6. Интеграции 1С

Ключевые файлы:

- `routes/api/v1/admin/one_c_exchange.php`;
- `OneCExchangeController`;
- `OneCExchangeJournalService`;
- `OneCExchangeConflictService`;
- `OneCExchangeMonitoringService`;
- `OneCExchangePayloadSanitizer`;
- `OneCProfileAuditEvent`;
- миграции `2026_05_12_000002_create_one_c_exchange_mappings_table.php`, `2026_05_12_000003_create_one_c_exchange_runs_table.php`, `2026_06_07_100000_create_one_c_exchange_operations_table.php`, `2026_06_07_100001_create_one_c_exchange_messages_table.php`, `2026_06_07_100002_add_registry_fields_to_one_c_exchange_mappings_table.php`, `2026_06_07_100003_create_one_c_exchange_conflicts_table.php`, `2026_06_07_100004_create_one_c_exchange_conflict_events_table.php`, `2026_06_07_110000_create_one_c_profile_connection_tables.php`.

Уже есть:

- `one_c_exchange_operations.operation_key`, `correlation_id`, `idempotency_key`, `source_hash`, `payload_hash`, `safe_payload_preview`, `created_by`, retry/dead-letter поля;
- `one_c_exchange_messages.request_hash`, `response_hash`, safe request/response previews, attempt numbers;
- `one_c_exchange_conflicts.prohelper_values`, `one_c_values`, `resolution`, `summary`, `version`, `detected_at`, `resolved_by`;
- `one_c_exchange_conflict_events.user_id`, `action`, `from_status`, `to_status`, `comment`, `payload`;
- `one_c_profile_audit_events.actor_id`, `event_type`, `result_code`, `result_status`, `duration_ms`, `safe_context`;
- права `one_c_exchange.view`, `one_c_exchange.manage_tokens`, `one_c_exchange.manage_mappings`, `one_c_exchange.import`, `one_c_exchange.export`, `one_c_exchange.history.view`, `one_c_exchange.retry`, `one_c_exchange.dead_letter.manage`, `one_c_exchange.conflicts.view`, `one_c_exchange.conflicts.resolve`, `one_c_exchange.profiles.test_connection`.

Пробелы для PHERP-134:

- 1С-журнал уже хранит hashes и safe preview, но не включен в общий immutable chain.
- `one_c_exchange_messages` и `one_c_exchange_conflict_events` имеют cascade delete от родительских сущностей, что несовместимо с строгим immutable audit без отдельной копии события.
- Секреты профилей 1С шифруются, но аудит должен хранить только fingerprint/status/result и никогда не сохранять token/password в before/after.

### 2.7. Склад

Ключевые файлы:

- `app/BusinessModules/Features/BasicWarehouse/routes.php`;
- `WarehouseOperationsController`;
- `WarehouseService`;
- `WarehouseCustodyService`;
- `WarehouseReceiptFromPaymentService`;
- `ProjectMaterialDeliveryService`;
- `InventoryController`;
- `WarehouseMovement`;
- `ProjectMaterialDelivery`;
- `ProjectMaterialDeliveryEvent`;
- `InventoryAct`;
- `InventoryActItem`.

Actor-поля уже есть:

- `WarehouseMovement.user_id`;
- `WarehouseMovement.related_user_id`;
- `WarehouseMovement.operation_category`;
- `WarehouseMovement.project_material_delivery_id`;
- `ProjectMaterialDelivery.responsible_user_id`;
- `ProjectMaterialDelivery.receiver_user_id`;
- `ProjectMaterialDeliveryEvent.user_id`;
- `OrganizationWarehouse.responsible_user_id`;
- `InventoryAct.created_by`;
- `InventoryAct.approved_by`.

Пробелы для PHERP-134:

- Нет единого складского audit log, сопоставимого с `PaymentAuditLog`.
- `InventoryAct.completed_by` и `InventoryActItem.updated_by_user_id` в текущей базе не найдены; если нужны для полного actor chain, это новое для PHERP-135/следующей реализации.
- Списания, перемещения, приемка по платежу, custody-операции и инвентаризация должны писать immutable-события отдельно от изменяемых business tables.

### 2.8. CRM

Ключевые файлы:

- `app/BusinessModules/Features/Crm/routes.php`;
- `CrmRegistryService`;
- `CrmTimelineService`;
- `CrmDuplicateService`;
- `DealConversionWizardService`;
- `CrmImportService`;
- `CrmMergeEvent`;
- `CrmTimelineEvent`;
- `CrmConversionOperation`.

Уже есть:

- `created_by_user_id`, `updated_by_user_id`, `owner_user_id` на CRM-сущностях;
- `CrmMergeEvent` с actor, reason, before/after snapshots;
- `CrmTimelineEvent` с actor и payload;
- `CrmConversionOperation.created_by_user_id`;
- `CrmImportBatch.uploaded_by_user_id`, confirmation `confirmed_by_user_id`;
- route permission `crm.timeline.view`, merge permissions `crm.merge.execute` и `crm.merge.view`.

Пробелы для PHERP-134:

- CRM timeline и merge history являются доменной историей, но не имеют immutable chain и DB-level запрета на изменение.
- Конвертация лида/сделки в проект/договор должна фиксировать source subject, target subjects, before/after link state, reason и correlation id.

### 2.9. Закупки как связанный домен SoD

PHERP-134 scope в YouTrack не выделяет закупки отдельно, но PHERP-132/PHERP-133 связывают платежи, склад, бюджетирование и SoD с procurement chain. Поэтому immutable audit должен поддержать закупочные события как связанный источник:

- `ProcurementAuditService`;
- `ProcurementAuditEvent`;
- таблица `procurement_audit_events`;
- права `procurement.audit.view`;
- события `supplier_request_created`, `supplier_request_sent`, `supplier_proposal_selected`, `procurement_approval_approved`, `purchase_order_created`, `materials_received` и другие из `ProcurementAuditEventTypeEnum`.

Пробелы для PHERP-134:

- `procurement_audit_events.payload` произвольный и не содержит обязательный единый contract before/after/reason/correlation/source.
- Это доменный audit, но не immutable layer.

## 3. Целевая архитектура

Новый доменный модуль целесообразно разместить в `app/BusinessModules/Core/ImmutableAudit`.

Компоненты будущей реализации:

| Компонент | Ответственность |
| --- | --- |
| `ImmutableAuditRecorder` | Принимает нормализованный event DTO, маскирует payload, рассчитывает hashes и пишет append-only запись. |
| `ImmutableAuditEventData` | DTO обязательных полей audit event. |
| `ImmutableAuditSourceAdapter` | Интерфейс адаптера доменного источника: платежи, MDM, бюджетирование, 1С, склад, CRM, RBAC, SoD. |
| `ImmutableAuditRedactor` | Расширение текущих `ActivityEventRedactor` и `SensitiveDataRedactor` под before/after/diff. |
| `ImmutableAuditIntegrityService` | Рассчитывает `payload_hash`, `previous_hash`, `record_hash`, проверяет цепочку и формирует integrity status. |
| `ImmutableAuditSealService` | Закрывает дневные/часовые batches, записывает anchor и контрольные суммы. |
| `ImmutableAuditQueryService` | Поиск, пагинация, детализация, сводки и экспорт через `AdminResponse`. |
| `ImmutableAuditRetentionService` | Retention, перевод partitions в archive/cold storage, контроль запрета ручного удаления. |
| `ImmutableAuditDomainContextResolver` | Приводит subject, project, organization, actor и source к единой форме. |
| `ImmutableAuditExportService` | Готовит выгрузку только по праву, с masking policy и trace id выгрузки. |

Сервис должен вызываться после RBAC/organization scope checks, но до commit критичного business transition или внутри той же transaction. Для critical operations запись audit event является fail-closed: если immutable event не записан, операция откатывается. Для вспомогательных activity/business events допускается best-effort bridge, но не для финансового, складского, MDM, RBAC, 1С и period-close события.

## 4. Модель audit event

### 4.1. `immutable_audit_events`

Целевая таблица append-only. Название является проектным контрактом PHERP-134 и не существует в текущей базе.

| Поле | Тип | Обязательность | Назначение |
| --- | --- | --- | --- |
| `id` | UUID | required | Публичный идентификатор события. |
| `sequence_id` | BIGINT | required | Монотонный порядок записи внутри хранилища. |
| `organization_id` | FK/int | required | Tenant scope. |
| `project_id` | FK/int/null | optional | Project context, если операция проектная. |
| `domain` | string | required | `payments`, `budgeting`, `mdm`, `rbac`, `one_c_exchange`, `warehouse`, `crm`, `period_close`, `procurement`, `sod`. |
| `event_type` | string | required | Нормализованный тип события, например `payments.document.approved`. |
| `action` | string | required | Business action: `create`, `update`, `approve`, `reject`, `apply`, `close`, `reopen`, `retry`, `resolve`, `assign`, `revoke`. |
| `result` | string | required | `success`, `denied`, `failed`, `exception_used`, `scheduled`. |
| `severity` | string | required | `info`, `warning`, `high`, `critical`. |
| `occurred_at` | timestamptz | required | Business timestamp операции. |
| `recorded_at` | timestamptz | required | Timestamp записи в audit storage. |
| `actor_type` | string | required | `user`, `system`, `integration`, `scheduler`, `support`. |
| `actor_user_id` | FK/int/null | optional | User id, если actor является пользователем. |
| `actor_snapshot` | jsonb | required | Имя, email/phone в маске, role/context snapshot, interface. |
| `impersonator_user_id` | FK/int/null | optional | Для support/impersonation, если появится. |
| `source` | string | required | `admin_api`, `lk_api`, `mobile_api`, `queue`, `scheduler`, `one_c_connector`, `import`, `system`. |
| `source_route` | string/null | optional | Route name или command/job key без внутренних stack traces. |
| `source_model` | string/null | optional | Модель-источник, например `PaymentDocument`. |
| `source_table` | string/null | optional | Таблица-источник, например `payment_audit_logs`. |
| `source_event_id` | string/null | optional | ID события в доменном журнале для идемпотентности. |
| `correlation_id` | string | required | Request/job/exchange correlation id. |
| `idempotency_key` | string/null | optional | Ключ идемпотентности, если есть в домене. |
| `subject_type` | string | required | Тип бизнес-объекта. |
| `subject_id` | string | required | ID бизнес-объекта. |
| `subject_label` | string/null | optional | Человекочитаемый номер/название в snapshot. |
| `related_subjects` | jsonb | optional | Связанные платежи, бюджет, складские движения, 1С-operation, MDM CR. |
| `reason` | text/null | required for high-risk | Бизнес-обоснование или причина отказа/исключения. |
| `before_state` | jsonb/null | required for mutation | Маскированное состояние до операции. |
| `after_state` | jsonb/null | required for mutation | Маскированное состояние после операции. |
| `diff` | jsonb/null | optional | Нормализованный diff для быстрых UI и API. |
| `domain_context` | jsonb | required | Amount/currency, period, warehouse, 1С scope, MDM entity/action, CRM pipeline и другие безопасные атрибуты. |
| `sensitive_fields` | jsonb | required | Список маскированных путей без исходных значений. |
| `redaction_policy_version` | string | required | Версия политики маскирования. |
| `payload_hash` | string | required | Hash canonical JSON payload после маскирования. |
| `previous_hash` | string/null | required except first | Hash предыдущего события в chain scope. |
| `record_hash` | string | required | Hash записи с `previous_hash`. |
| `chain_scope` | string | required | Например `organization:{id}` или `organization:{id}:domain:{domain}`. |
| `chain_version` | smallint | required | Версия алгоритма цепочки. |
| `sealed_at` | timestamptz/null | optional | Когда event попал в sealed batch. |
| `seal_id` | UUID/null | optional | Ссылка на batch seal. |
| `integrity_status` | string | required | `pending`, `sealed`, `verified`, `broken`, `archived`. |
| `retention_until` | date | required | Дата окончания обязательного хранения. |
| `created_at` | timestamptz | required | Технический timestamp insert. |

Правила:

- `actor`, `timestamp`, `before/after`, `reason`, `correlation_id`, `source`, `organization/project context` являются обязательным contract для high-risk mutation events.
- Для read-only событий before/after не требуются, но `result`, `source`, `actor`, `subject` и `correlation_id` остаются обязательными.
- Для system/integration actor `actor_snapshot` должен содержать service name, connector version или scheduler key.
- Если пользователь удален или его роль изменилась, audit event остается читаемым через snapshot.
- Связи с business tables не должны иметь cascade delete; event должен переживать удаление/архивацию исходного объекта.

### 4.2. `immutable_audit_seals`

Целевая таблица sealing batches:

| Поле | Назначение |
| --- | --- |
| `id` | UUID seal batch. |
| `organization_id` | Tenant scope. |
| `chain_scope` | Scope цепочки. |
| `from_sequence_id`, `to_sequence_id` | Диапазон событий. |
| `events_count` | Количество событий. |
| `root_hash` | Итоговый hash batch. |
| `previous_seal_hash` | Связь batches. |
| `seal_hash` | Hash seal-записи. |
| `sealed_at` | Timestamp sealing. |
| `sealed_by` | `scheduler` или service user. |
| `storage_anchor` | Ссылка на внешний anchor, если будет WORM/S3/object storage. |
| `integrity_status` | `sealed`, `verified`, `broken`, `archived`. |

Внешний anchor является новым для PHERP-135/следующей реализации. Для первого этапа достаточно DB chain + seal table + периодическая verify-команда без запуска в PHERP-134.

## 5. Storage strategy и защита от изменения

### 5.1. Append-only storage

Целевое поведение:

- `immutable_audit_events` и `immutable_audit_seals` пишутся только через `ImmutableAuditRecorder`.
- На уровне БД нужен trigger, запрещающий `UPDATE` и `DELETE` для audit events и seals.
- У application DB role должны быть права `INSERT` и `SELECT`; `UPDATE`/`DELETE` на audit tables не выдаются.
- Любая коррекция записывается только новым compensating event, например `payments.document.audit_correction_requested`, без изменения старой строки.
- Для retention допускается только partition-level archival через отдельную привилегированную процедуру с записью retention event и seal before/after archive.

### 5.2. Partitioning

Рекомендуемая схема:

- partition by `occurred_at` по месяцам для `immutable_audit_events`;
- hot partitions: текущий месяц и предыдущие 24 месяца;
- warm partitions: до 7 лет в основной БД или read replica;
- cold archive: старше 7 лет в объектном хранилище с manifest/hash anchor, если объем станет production-sized проблемой.

Retention срок должен быть конфигурируемым по домену:

| Домен | Минимальный целевой retention |
| --- | --- |
| `payments`, `budgeting`, `period_close`, `one_c_exchange` | 7 лет |
| `mdm`, `rbac`, `sod` | 7 лет |
| `warehouse`, `crm`, `procurement` | 5 лет |
| технические integrity/seal events | не меньше максимального срока домена в chain scope |

Если правовые требования организации требуют больший срок, `retention_until` рассчитывается по organization policy. Удаление до `retention_until` запрещено.

### 5.3. Idempotency и transactional behavior

- Для каждого доменного источника задается `source_event_id` и unique index `(organization_id, domain, source, source_event_id)`, если источник имеет собственный ID.
- Для операций без собственного события используется `idempotency_key` или hash `(domain, event_type, subject_type, subject_id, action, occurred_at, actor_user_id, correlation_id)`.
- Critical mutation event пишется в той же DB transaction, что и business transition.
- 1С retry/dead-letter и queue events должны передавать исходный `correlation_id` из `one_c_exchange_operations`.
- При повторной доставке recorder возвращает уже существующий audit event, а не создает дубль.

## 6. Masking sensitive payload

Целевой `ImmutableAuditRedactor` должен использовать текущие наработки:

- `ActivityEventRedactor`;
- `SensitiveDataRedactor`;
- `OneCExchangePayloadSanitizer`.

Правила:

- Before/after/diff сохраняются только после маскирования.
- Запрещено сохранять в audit event: пароли, bearer/session tokens, refresh tokens, API keys, private keys, cookies, CVV, полные номера карт, plaintext secrets 1С, authorization headers.
- Для персональных и финансовых данных хранить безопасные snapshots: маска email/phone, последние 4 символа счета/карты, fingerprint, hash или label.
- `sensitive_fields` хранит пути маскированных полей, например `after_state.bank_account.number`.
- `redaction_policy_version` обязателен, чтобы будущие проверки понимали, какой политикой событие было сохранено.
- Если домену нужен forensic payload, это новое для PHERP-135/следующей реализации: хранить encrypted blob отдельно, с отдельным правом, audit-on-read и retention policy. В PHERP-134 базовый контракт предполагает masked payload only.

## 7. API contract

Все маршруты целевого immutable audit API должны возвращать `AdminResponse` и быть доступны только в organization scope.

### 7.1. Routes

Целевые маршруты:

- `GET /api/v1/admin/immutable-audit/events`
- `GET /api/v1/admin/immutable-audit/events/{event}`
- `GET /api/v1/admin/immutable-audit/events/{event}/integrity`
- `GET /api/v1/admin/immutable-audit/summary`
- `GET /api/v1/admin/immutable-audit/actors`
- `GET /api/v1/admin/immutable-audit/subjects/{subjectType}/{subjectId}`
- `GET /api/v1/admin/immutable-audit/correlations/{correlationId}`
- `POST /api/v1/admin/immutable-audit/exports`
- `GET /api/v1/admin/immutable-audit/exports/{export}`
- `POST /api/v1/admin/immutable-audit/integrity/verify`

### 7.2. Filters

`GET /events` должен поддержать:

- `domain`;
- `event_type`;
- `action`;
- `result`;
- `severity`;
- `actor_user_id`;
- `actor_type`;
- `subject_type`;
- `subject_id`;
- `project_id`;
- `source`;
- `correlation_id`;
- `idempotency_key`;
- `date_from`;
- `date_to`;
- `integrity_status`;
- `retention_until_from`;
- `retention_until_to`;
- `search`;
- `page`;
- `per_page`.

`per_page` ограничить 100. Default sort: `occurred_at desc, sequence_id desc`.

### 7.3. List response

Пример формы ответа:

```json
{
  "success": true,
  "message": "События аудита получены",
  "data": [
    {
      "id": "018f7d21-7b31-7b9e-9d6b-3c5f4d74c201",
      "occurred_at": "2026-06-22T10:15:00+03:00",
      "domain": "payments",
      "event_type": "payments.document.approved",
      "action": "approve",
      "result": "success",
      "severity": "critical",
      "actor": {
        "type": "user",
        "id": 42,
        "name": "Иван Петров",
        "role_label": "Финансовый менеджер"
      },
      "subject": {
        "type": "PaymentDocument",
        "id": "185",
        "label": "Платеж #185"
      },
      "organization_id": 7,
      "project_id": 12,
      "source": "admin_api",
      "correlation_id": "req-01J...",
      "integrity_status": "sealed"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1
  },
  "summary": {
    "total": 1,
    "critical": 1,
    "integrity_gaps": 0
  }
}
```

### 7.4. Detail response

Detail должен добавлять:

- `reason`;
- `before_state`;
- `after_state`;
- `diff`;
- `domain_context`;
- `related_subjects`;
- `source_event`;
- `chain`;
- `seal`;
- `retention`;
- `sensitive_fields`.

Если у пользователя нет права на sensitive view, API возвращает только masked state. На первом этапе PHERP-135 можно не добавлять unmasked mode вообще.

### 7.5. Integrity response

`GET /events/{event}/integrity` возвращает:

- `payload_hash`;
- `previous_hash`;
- `record_hash`;
- `chain_scope`;
- `seal_id`;
- `seal_hash`;
- `integrity_status`;
- `verified_at`;
- `broken_reason`, если status `broken`.

Пользовательский текст не должен показывать технические stack traces, SQL или внутренние exception names.

## 8. Permissions и русские подписи

PHERP-134 не добавляет права в `lang/ru/permissions.php`. Для следующей реализации нужны целевые permissions:

| Permission | Русская подпись | Назначение |
| --- | --- | --- |
| `immutable_audit.events.view` | Просмотр неизменяемого аудита | Видеть список и карточку audit events в рамках организации. |
| `immutable_audit.events.export` | Экспорт неизменяемого аудита | Создавать и скачивать выгрузки audit events. |
| `immutable_audit.events.view_sensitive` | Просмотр расширенных данных аудита | Видеть расширенный masked before/after и sensitive field paths. |
| `immutable_audit.integrity.verify` | Проверка целостности аудита | Запускать проверку hash-chain и просматривать integrity details. |
| `immutable_audit.retention.manage` | Управление сроками хранения аудита | Управлять retention policy и archive jobs. |

Группа permissions:

- slug: `immutable_audit`;
- label: `Неизменяемый аудит`;
- description: `Доступ к защищенному журналу критичных ERP-операций`.

Рекомендация по ролям:

- `web_admin` и `organization_admin`: `immutable_audit.events.view`, `immutable_audit.events.export`, `immutable_audit.integrity.verify`;
- `security_auditor` или будущая ERP control role: все view/export/verify права;
- `immutable_audit.retention.manage`: только platform/security admin, не обычная организация.

Нужно добавить тест на `PermissionTranslator`, чтобы API ролей не отдавал в UI технические slug-и вместо русских подписей.

## 9. Индексы и производительность

Рекомендуемые индексы для `immutable_audit_events`:

- primary key `id`;
- unique `sequence_id`;
- unique `(organization_id, domain, source, source_event_id)` where `source_event_id is not null`;
- index `(organization_id, occurred_at desc, sequence_id desc)`;
- index `(organization_id, domain, occurred_at desc)`;
- index `(organization_id, event_type, occurred_at desc)`;
- index `(organization_id, actor_user_id, occurred_at desc)`;
- index `(organization_id, subject_type, subject_id, occurred_at desc)`;
- index `(organization_id, project_id, occurred_at desc)`;
- index `(organization_id, correlation_id)`;
- index `(organization_id, integrity_status, occurred_at desc)`;
- GIN `domain_context jsonb_path_ops`;
- GIN `diff jsonb_path_ops`;
- BRIN по `occurred_at` для cold partitions, если объем станет большим.

Ограничения:

- list API не должен подгружать тяжелые before/after; detail endpoint загружает их отдельно.
- Экспорт всегда async job с audit event `immutable_audit.export.created`.
- Поиск по `search` ограничить безопасными полями: `subject_label`, `event_type`, `correlation_id`, masked actor snapshot.
- Для больших организаций нужен keyset pagination по `occurred_at + sequence_id` как следующий шаг, если offset pagination станет узким местом.
- Integrity verification не запускается синхронно из list/detail API.

## 10. Интеграционные точки по доменам

### 10.1. Payments

Обязательные события:

- `payments.document.created`;
- `payments.document.updated`;
- `payments.document.deleted`;
- `payments.document.submitted`;
- `payments.document.approved`;
- `payments.document.rejected`;
- `payments.document.payment_registered`;
- `payments.document.receipt_confirmed`;
- `payments.document.cancelled`;
- `payments.document.rescheduled`;
- `payments.budget_limit.override_used`;
- `payments.transaction.created`;
- `payments.transaction.approved`;
- `payments.export.one_c_requested`;
- `payments.reconciliation.applied`.

Hook points:

- `PaymentAuditService`;
- `PaymentDocumentObserver`;
- `PaymentDocumentService::submit`;
- `ApprovalWorkflowService::approveByUser`;
- `PaymentConfirmationService::confirmReceipt`;
- `PaymentBudgetLimitService`;
- controllers registering payment/cancel/reschedule/export.

High-risk requirements:

- approve/reject/register/cancel/reschedule/override require `reason` or derived reason from request/business rule.
- before/after must include status, amount, budget article, recipient, due date, payment method, approval chain and limit state in masked form.

### 10.2. Budgeting и period close

Обязательные события:

- `budgeting.version.created`;
- `budgeting.version.submitted`;
- `budgeting.version.approved`;
- `budgeting.version.rejected`;
- `budgeting.version.activated`;
- `budgeting.version.archived`;
- `budgeting.lines.replaced`;
- `budgeting.import.previewed`;
- `budgeting.import.committed`;
- `budgeting.limit.reserved`;
- `budgeting.limit.override_used`;
- `period_close.closed`;
- `period_close.reopened`;
- `period_close.reopen_window_expired`;
- `period_close.adjustment_applied`.

Hook points:

- `BudgetWorkflowService::transitionVersion`;
- `BudgetPeriodClosureService::close`;
- `BudgetPeriodReopenService::reopen`;
- `BudgetLimitCheckService`;
- budget import commit services;
- cash gap opening balance approval services.

High-risk requirements:

- close/reopen require reason, affected period, operation list snapshot, user, timestamp, reopened_until and SoD result once PHERP-133 runtime exists.
- before/after must include period status, active budget version, locked operations and relevant amounts in aggregated/masked form.

### 10.3. MDM

Обязательные события:

- `mdm.change_request.created`;
- `mdm.change_request.submitted`;
- `mdm.change_request.review_started`;
- `mdm.change_request.approved`;
- `mdm.change_request.rejected`;
- `mdm.change_request.applied`;
- `mdm.change_request.failed`;
- `mdm.change_request.cancelled`;
- `mdm.record.direct_change_detected`;
- `mdm.record.synced`;
- `mdm.record.archived`;
- `mdm.merge.applied`;
- `mdm.one_c_lock.blocked`;

Hook points:

- `MdmChangeRequestService::createDraft`;
- `submitDraft`, `startReview`, `approve`, `reject`, `applyApproved`, `cancel`;
- `MdmDomainChangeApplier`;
- `MdmChangeLogService`;
- import/file import/merge apply flows;
- `MdmOneCLockService`.

High-risk requirements:

- before/after comes from `current_values`, `proposed_values`, `diff` and `MdmChangeLog`.
- reason comes from `reason`, `business_justification`, review/apply/cancel notes or failure reason.
- domain context includes entity, action, `payload_hash`, `idempotency_key`, `expected_record_version`, `one_c_lock_summary`, impact summary.

### 10.4. RBAC и permissions

Обязательные события:

- `rbac.role.assigned`;
- `rbac.role.revoked`;
- `rbac.role_assignment.updated`;
- `rbac.custom_role.created`;
- `rbac.custom_role.updated`;
- `rbac.custom_role.deleted`;
- `rbac.permission_catalog.changed`;
- `rbac.role_definition.changed`;
- `rbac.access_denied.high_risk`.

Hook points:

- `AuthorizationService::assignRole`;
- role revoke/update flows;
- custom role services, если используются;
- deployment/release audit hook для changes in `config/RoleDefinitions/**` и `lang/ru/permissions.php`.

High-risk requirements:

- before/after must include role slug, context, organization/project, active dates, conditions and permission diff.
- reason is mandatory for manual grant/revoke of finance, MDM, warehouse, 1С, immutable audit and SoD rights.
- RoleDefinitions file changes are new for PHERP-135/следующей реализации because current runtime does not audit repository file diffs.

### 10.5. 1С exchange

Обязательные события:

- `one_c.profile.created`;
- `one_c.profile.updated`;
- `one_c.profile.connection_tested`;
- `one_c.profile.secret_rotated`;
- `one_c.mapping.created`;
- `one_c.mapping.approved`;
- `one_c.mapping.verified`;
- `one_c.mapping.archived`;
- `one_c.operation.created`;
- `one_c.operation.attempt_recorded`;
- `one_c.operation.retry_scheduled`;
- `one_c.operation.dead_lettered`;
- `one_c.conflict.detected`;
- `one_c.conflict.assigned`;
- `one_c.conflict.resolved`;
- `one_c.conflict.closed`;
- `one_c.import.committed`;
- `one_c.export.committed`.

Hook points:

- `OneCExchangeJournalService::createOperation`;
- `recordAttempt`, `retry`, `moveToDeadLetter`;
- `OneCExchangeConflictService`;
- profile create/update/test/revoke flows;
- mapping store/reference mapping flows.

High-risk requirements:

- before/after never stores secrets, only fingerprint/status/safe preview/hash.
- domain context includes `operation_key`, `correlation_id`, `idempotency_key`, direction, scope, entity type/id, external id, payload hashes, retry count, failure type.
- conflict resolution includes `prohelper_values`, `one_c_values`, resolution and actor in masked form.

### 10.6. Warehouse

Обязательные события:

- `warehouse.receipt.created`;
- `warehouse.write_off.created`;
- `warehouse.transfer.created`;
- `warehouse.custody.issued`;
- `warehouse.custody.returned`;
- `warehouse.inventory.created`;
- `warehouse.inventory.started`;
- `warehouse.inventory.item_updated`;
- `warehouse.inventory.completed`;
- `warehouse.inventory.approved`;
- `warehouse.project_delivery.created`;
- `warehouse.project_delivery.issued`;
- `warehouse.project_delivery.received`;
- `warehouse.project_delivery.returned`;
- `warehouse.photo.uploaded`;
- `warehouse.photo.deleted`.

Hook points:

- `WarehouseService::receiveAsset`;
- `WarehouseService::writeOffAsset`;
- `WarehouseService::transferAsset`;
- `WarehouseCustodyService`;
- `WarehouseReceiptFromPaymentService`;
- `ProjectMaterialDeliveryService`;
- `InventoryController`.

High-risk requirements:

- before/after includes stock quantity, warehouse/location, material/resource, responsible user, project delivery link and document references.
- `InventoryAct.completed_by` and `InventoryActItem.updated_by_user_id` are new for PHERP-135/следующей реализации if full actor chain is required.

### 10.7. CRM

Обязательные события:

- `crm.company.created`;
- `crm.company.updated`;
- `crm.contact.created`;
- `crm.contact.updated`;
- `crm.lead.created`;
- `crm.lead.updated`;
- `crm.lead.qualified`;
- `crm.deal.created`;
- `crm.deal.updated`;
- `crm.deal.stage_changed`;
- `crm.deal_conversion.completed`;
- `crm.merge.completed`;
- `crm.import.previewed`;
- `crm.import.confirmed`;
- `crm.import.cancelled`;
- `crm.activity.created`;
- `crm.activity.completed`.

Hook points:

- `CrmRegistryService`;
- `CrmTimelineService`;
- `CrmDuplicateService::merge`;
- `DealConversionWizardService`;
- `CrmImportService`.

High-risk requirements:

- merge and conversion must always include reason, actor, source subjects, target subjects, before/after link state and correlation id.
- contact data is masked according to redaction policy.

### 10.8. Procurement and SoD linkage

Обязательные события для связки с PHERP-132/PHERP-133:

- `procurement.supplier_request.created`;
- `procurement.supplier_proposal.selected`;
- `procurement.approval.approved`;
- `procurement.approval.rejected`;
- `procurement.purchase_order.created`;
- `procurement.materials_received`;
- `sod.check.performed`;
- `sod.violation.detected`;
- `sod.exception.requested`;
- `sod.exception.approved`;
- `sod.exception.used`.

Hook points:

- `ProcurementAuditService`;
- `ProcurementDutySeparationService`;
- будущий `SodAuditService` из PHERP-133.

SoD runtime является новым для PHERP-135/следующей реализации, потому что в текущем коде отдельный модуль `Core/Sod` не найден.

## 11. Admin UI contract

PHERP-134 не меняет UI, но целевой интерфейс должен быть спроектирован как рабочий реестр, а не как технический лог:

- список событий с фильтрами по домену, периоду, actor, subject, project, result, severity, integrity status и correlation id;
- карточка события с человекочитаемым описанием, masked before/after, diff, reason, source, related subjects и integrity block;
- отдельная вкладка correlation timeline, которая показывает цепочку request/job/exchange событий;
- предупреждение при `integrity_status=broken` без технических stack traces;
- экспорт с явным указанием периода, фильтров, автора и audit event самой выгрузки;
- все пользовательские строки должны быть русскими и бизнес-понятными.

Технические термины `payload`, `hash`, `constraint`, `sql`, `exception` не должны отображаться обычному пользователю. Для аудитора допускаются подписи `Контрольная сумма`, `Цепочка событий`, `Пакет проверки`.

## 12. План реализации для PHERP-135/следующей задачи

1. Создать модуль `Core/ImmutableAudit` с DTO, recorder, redactor, integrity service и query service.
2. Добавить миграции `immutable_audit_events` и `immutable_audit_seals` с partitioning-ready схемой, indexes, append-only trigger и без cascade delete.
3. Добавить permissions в `lang/ru/permissions.php` и `RoleDefinitions`, покрыть `PermissionTranslator`.
4. Реализовать API `/api/v1/admin/immutable-audit/*` на `AdminResponse`.
5. Подключить source adapters сначала к `PaymentAuditService`, `MdmChangeRequestService`, `OneCExchangeJournalService`, `BudgetWorkflowService`, `BudgetPeriodClosureService`.
6. Затем подключить warehouse, CRM, procurement и RBAC flows.
7. Добавить seal job и verify command. Локально не запускать DB-команды без отдельного разрешения.
8. Добавить async export с audit-on-export.
9. Добавить admin UI registry/detail/correlation timeline, если задача будет затрагивать frontend.
10. Провести production-sized проверку: большие partitions, массовый экспорт, retry/idempotency, slow queries, masked payload, integrity verification.

## 13. Тестовые сценарии будущей реализации

Unit:

- `ImmutableAuditRedactorTest`: маскирует токены, пароли, карты, email, телефон, паспортные/банковские поля во вложенных before/after.
- `ImmutableAuditHashServiceTest`: canonical JSON дает стабильный `payload_hash`; изменение payload ломает hash.
- `ImmutableAuditRecorderTest`: повторный `source_event_id` не создает дубль.
- `ImmutableAuditRetentionPolicyTest`: retention рассчитывается по домену и organization policy.
- `ImmutableAuditPermissionTranslatorTest`: новые права возвращают русские подписи.

Integration/API:

- пользователь с `immutable_audit.events.view` видит события своей организации и не видит чужие.
- пользователь без права получает понятный отказ.
- filters by domain/event_type/actor/subject/project/correlation/date работают и возвращают `AdminResponse` с `meta` и `summary`.
- detail endpoint возвращает before/after только в masked форме.
- export endpoint создает export job и пишет audit event `immutable_audit.export.created`.
- integrity endpoint показывает `sealed/verified/broken` без технических stack traces.

Domain:

- approve платежа пишет event с actor, before/after status, reason, amount, project, correlation id.
- budget period close/reopen пишет period state, reason, reopened_until и affected operations summary.
- MDM apply пишет diff, payload_hash, expected_record_version, impact summary и one_c_lock_summary.
- role assignment/revoke пишет before/after role context и reason.
- 1С retry/dead-letter пишет operation_key, correlation_id, retry_count, failure type и safe payload hashes.
- warehouse write-off/transfer пишет stock before/after и responsible users.
- CRM merge/conversion пишет reason, source/target subjects и before/after link state.

Data integrity:

- прямой `UPDATE`/`DELETE` audit event должен завершаться ошибкой на уровне БД.
- verify job обнаруживает изменение `before_state`, `after_state`, `record_hash` или разрыв `previous_hash`.
- archive partition сохраняет seal manifest и не меняет hash chain.

Запреты для локальной проверки остаются: не запускать миграции, локальный tinker/DB-команды, feature tests с `RefreshDatabase`, dev-server и build для admin/land без отдельной команды.

## 14. Acceptance criteria

PHERP-134 считается готовой как проектная спецификация, если:

- описана фактическая база текущих audit/logging mechanisms без выдуманных классов и полей;
- явно указано, что `immutable_audit_events`, `immutable_audit_seals`, permissions и API являются новым контрактом для PHERP-135/следующей реализации;
- модель audit event содержит actor, timestamp, before/after, reason, correlation id, source, organization/project context;
- описана storage strategy: append-only, DB-level запрет update/delete, hash-chain, seal batches, partitioning, retention;
- описана masking policy для sensitive payload;
- определены API поиска, просмотра, integrity details, actors, summary, export и permissions;
- указаны русские подписи новых прав;
- перечислены индексы и требования к производительности;
- покрыты интеграционные точки платежей, бюджетирования, MDM, RBAC, 1С, склада, CRM, period close, procurement/SoD linkage;
- указаны тестовые сценарии и acceptance criteria будущей реализации;
- все пробелы текущей реализации явно помечены как новые для PHERP-135/следующей реализации.

## 15. Ограничения PHERP-134

- Production-код не менялся.
- Миграции не создавались и не запускались.
- `RoleDefinitions` и `lang/ru/permissions.php` не менялись.
- API и UI не реализовывались.
- Локальные DB/tinker/seed/rollback/reset/delete/verify/dry-run команды не требуются для этой спецификации.
- Context7 не использовался, потому что задача не затрагивала внешние библиотеки, SDK, CLI или актуальный синтаксис сторонних API.
