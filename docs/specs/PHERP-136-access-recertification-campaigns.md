# PHERP-136: Кампании access recertification

## 1. Статус и цель

Документ фиксирует проектную спецификацию PHERP-136: регулярный пересмотр доступов пользователей, ролей и рискованных полномочий в ERP-контуре МОСТ. Это следующая ERP-06 способность после SoD и immutable audit.

Фактический scope PHERP-136 в YouTrack: спроектировать кампании access recertification, включая периодичность, список пользователей, проверяемые роли и права, владельцев проверки, статусы, решения `approve` / `revoke` / `exception`, evidence, дедлайны, разные контуры `admin`, `lk`, `mobile`, `customer`, связь с SoD и отчет по просроченным и опасным доступам.

В рамках PHERP-136 production-код, миграции, API, UI, `config/RoleDefinitions` и `lang/ru/permissions.php` не меняются. Все таблицы, сервисы, маршруты, UI-экраны и permissions ниже являются целевым контрактом и помечаются как новое для реализации.

Критерий готовности PHERP-136 как проектной спецификации: можно реализовать запуск кампании, назначение владельцев проверки, сбор решений, immutable evidence и отчеты по просрочкам, опасным доступам и исключениям без изменения смысла существующего RBAC.

## 2. Проверенная база PHERP-132/133/134/135

### 2.1. PHERP-132: SoD matrix

Файл: `docs/specs/PHERP-132-sod-matrix-finance-procurement-warehouse.md`.

PHERP-132 является проектной спецификацией, а не runtime-реализацией. Документ описывает SoD-матрицу для финансов, закупок, склада, бюджетирования, MDM и RBAC. В нем уже выделены рискованные роли и полномочия:

- `web_admin`: широкий admin-контур, MDM, закупки, платежи, склад, бюджетирование;
- `organization_admin`: широкий `lk`/`admin`/`mobile` контур, управление пользователями и критичными доменами;
- `organization_owner`: полный организационный доступ, требующий governance review;
- `finance_admin`: платежи, транзакции, бюджеты, лимиты и закрытие периодов;
- `accountant`: платежи, бюджеты и транзакции;
- `project_manager`: проектные бюджеты, платежи, закупки и складские операции;
- `foreman`: мобильная приемка, склад и часть procurement approvals;
- `supplier`: складские и проектные действия в ограниченном контуре.

В PHERP-132 предложены будущие SoD permissions `sod.*`, но они не являются runtime-правами в текущих `RoleDefinitions` и `lang/ru/permissions.php`.

### 2.2. PHERP-133: SoD enforcement и reporting

Файл: `docs/specs/PHERP-133-sod-enforcement-reporting.md`.

PHERP-133 также является проектной спецификацией. В ней определены будущие сервисы `SodRuleCatalog`, `SodCheckService`, `SodExceptionService`, `SodAuditService`, `SodViolationReportService` и `SodWorkflowResponseFactory`. Runtime-модуль `Core/Sod` в текущем коде не найден.

Для PHERP-136 это означает:

- recertification не должна считать `sod.*` действующими permissions;
- SoD risk flags нужно хранить как snapshot целевого SoD-анализа;
- до production-реализации PHERP-133 risk flags строятся на статическом анализе RoleDefinitions, custom roles и фактических назначений ролей;
- после PHERP-133 recertification должна читать `sod_violations`, `sod_exceptions`, role heatmap и active conflict summary как источник риска.

### 2.3. PHERP-134: immutable audit layer

Файл: `docs/specs/PHERP-134-immutable-audit-layer.md`.

PHERP-134 описал целевую модель immutable audit: actor, timestamp, before/after, reason, correlation id, source, organization/project context, retention, hash-chain, seals, filters, export и access control.

Для PHERP-136 важны принципы:

- критичные решения должны иметь неизменяемый audit trail;
- evidence хранится как masked snapshot, без секретов и лишних персональных данных;
- export evidence сам должен быть audit event;
- чтение чувствительных деталей должно быть отдельно защищено;
- корректировка старой записи запрещена, исправление делается новым компенсирующим событием.

### 2.4. PHERP-135: реализованный protected audit viewer

Текущий `main` содержит коммит `1055f41c feat[backend]: реализовать защищенный аудит PHERP-135`.

Реализовано в backend:

- миграция `database/migrations/2026_06_22_000001_create_immutable_audit_tables.php`;
- модели `ImmutableAuditEvent` и `ImmutableAuditSeal`;
- DTO `ImmutableAuditEventData` и `ImmutableAuditEventFilters`;
- сервисы `ImmutableAuditRecorder`, `ImmutableAuditQueryService`, `ImmutableAuditIntegrityService`, `ImmutableAuditExportService`, `ImmutableAuditRedactor`;
- source adapters для payment audit, MDM change requests и 1C exchange;
- Admin API `routes/api/v1/admin/immutable_audit.php`;
- контроллер `ImmutableAuditController`;
- resources для list/detail;
- переводы `lang/ru/immutable_audit.php`;
- тесты `tests/Unit/ImmutableAudit/*` и `tests/Feature/Api/V1/Admin/ImmutableAuditControllerTest.php`.

Реализовано в admin UI:

- `prohelper_admin/src/pages/Logs/ImmutableAuditPage.tsx`;
- `prohelper_admin/src/services/immutableAuditService.ts`;
- `prohelper_admin/src/types/immutableAudit.ts`;
- route `/logs/immutable-audit`;
- permissions constants `immutable_audit.events.view`, `immutable_audit.events.export`, `immutable_audit.events.view_sensitive`, `immutable_audit.integrity.verify`.

Реальные permissions, уже найденные в `lang/ru/permissions.php` и RoleDefinitions:

| Permission | Статус |
| --- | --- |
| `immutable_audit.events.view` | существует, переведен, назначен системным/организационным ролям |
| `immutable_audit.events.export` | существует, переведен, назначен системным/организационным ролям |
| `immutable_audit.events.view_sensitive` | существует, переведен, назначен системным security/system ролям |
| `immutable_audit.integrity.verify` | существует, переведен, назначен системным/организационным ролям |
| `immutable_audit.retention.manage` | существует, переведен, ограничен системным администратором |

Текущий enum/check constraint домена immutable audit допускает `payments`, `budgeting`, `mdm`, `rbac`, `one_c_exchange`, `warehouse`, `crm`, `period_close`, `procurement`, `sod`. Поэтому PHERP-136 в первом production-релизе должен писать access recertification audit events в домен `rbac` с `event_type=access_recertification.*`. Отдельный домен `access_recertification` является новым для реализации и потребует изменения check constraint.

## 3. Реальная модель ролей и контуров

Роли определяются JSON-файлами в `config/RoleDefinitions/{context}/{role_slug}.json`. Для PHERP-136 нельзя хардкодить роли в PHP; campaign scope должен хранить ссылку на context/role slug и snapshot читаемого названия.

Фактические контексты, которые нужно поддержать:

| Контекст RoleDefinitions | Примеры ролей | Интерпретация в recertification |
| --- | --- | --- |
| `admin` | `web_admin`, `finance_admin`, `admin_viewer`, `brigade_catalog_moderator` | Админский контур организации |
| `lk` | `organization_owner`, `organization_admin`, `accountant`, `viewer`, `supplier`, `brigade_manager`, `brigade_representative` | Личный кабинет организации |
| `mobile` | `worker`, `observer`, `foreman` | Мобильный контур полевых пользователей |
| `customer` | `customer_owner`, `customer_manager`, `customer_financier`, `customer_approver`, `customer_legal`, `customer_curator`, `customer_observer`, `customer_viewer` | Кабинет заказчика |
| `project` | `project_manager`, `site_engineer`, `contractor`, `project_viewer`, `parent_administrator` | Проектный контур, включается через project scope |
| `system` / `system_admin` | `system_admin`, `super_admin`, `security_auditor`, `support_operator`, `support_viewer`, `qa_engineer`, `content_manager` | Платформенный контур, проверяется отдельной кампанией security governance |

Требование YouTrack явно называет `admin`, `lk`, `mobile`, `customer`; `project`, `system` и `system_admin` нужны как supporting contexts, потому что доступы пользователей реально назначаются и там.

## 4. Целевая бизнес-модель

### 4.1. Campaign

Campaign - управляемый цикл пересмотра доступов в организации или платформенном контуре.

Минимальные поля:

| Поле | Назначение |
| --- | --- |
| `id` | UUID кампании |
| `organization_id` | Организация; nullable только для platform-level security campaign |
| `name` | Человекочитаемое название |
| `description` | Бизнес-цель проверки |
| `type` | `scheduled`, `ad_hoc`, `post_incident`, `role_change`, `project_close`, `employee_exit`, `external_audit` |
| `status` | `draft`, `scheduled`, `active`, `locked`, `completed`, `cancelled`, `archived` |
| `risk_mode` | `all`, `high_risk_only`, `sod_related`, `exceptions_only`, `stale_access` |
| `scope` | JSONB scope кампании |
| `owner_user_id` | Владелец кампании |
| `escalation_user_id` | Владелец эскалации |
| `starts_at` | Дата старта |
| `due_at` | Дедлайн кампании |
| `closed_at` | Фактическое закрытие |
| `created_by_user_id` | Кто создал |
| `launched_by_user_id` | Кто запустил |
| `completed_by_user_id` | Кто завершил |
| `snapshot_hash` | Контрольная сумма scope + item snapshot |
| `correlation_id` | Связь с audit/export/SoD событиями |

Campaign запускается только из `draft` или `scheduled`. После запуска scope фиксируется: изменение состава пользователей или ролей требует новой версии кампании или compensating event.

### 4.2. Campaign scope

Scope хранится как JSONB и должен быть полностью сериализуемым в evidence.

Пример целевого shape:

```json
{
  "organization_id": 15,
  "project_ids": [101, 102],
  "role_contexts": ["admin", "lk", "mobile", "customer", "project"],
  "role_slugs": ["web_admin", "organization_admin", "finance_admin", "foreman"],
  "user_ids": [501, 502],
  "permission_prefixes": ["payments.", "procurement.", "warehouse.", "mdm.", "one_c_exchange.", "immutable_audit."],
  "domains": ["admin", "lk", "mobile", "customer"],
  "include_inactive_users": false,
  "include_external_users": true,
  "risk_filters": {
    "include_sod_flags": true,
    "include_expiring_exceptions": true,
    "minimum_risk_level": "medium"
  }
}
```

Правила scope:

- `organization_id` обязателен для tenant кампаний;
- `project_ids` ограничивают проектные роли и проектные назначения;
- `role_contexts` ссылаются на реальные каталоги `config/RoleDefinitions`;
- `domains` описывают пользовательскую поверхность доступа: `admin`, `lk`, `mobile`, `customer`;
- если указан `user_ids`, кампания ограничивается этими пользователями;
- если указан `permission_prefixes`, в item попадают только назначения, содержащие эти permissions;
- scope должен хранить snapshot русских подписей ролей и permissions на момент запуска, чтобы будущие переименования не меняли evidence.

### 4.3. Campaign item

Item - единица проверки конкретного доступа.

Рекомендуемая гранулярность: один user + один access assignment + один scope. Для роли с большим числом permissions item хранит role-level decision и permission risk snapshot; отдельные permission-level items создаются только для high-risk permissions или exceptions.

Поля:

| Поле | Назначение |
| --- | --- |
| `id` | UUID item |
| `campaign_id` | Campaign |
| `organization_id` | Tenant scope |
| `project_id` | Nullable project scope |
| `subject_user_id` | Пользователь, чей доступ проверяется |
| `access_type` | `system_role`, `custom_role`, `direct_permission`, `temporary_access`, `exception`, `external_account` |
| `role_context` | Например `admin`, `lk`, `mobile`, `customer`, `project`, `system_admin` |
| `role_slug` | Реальный slug роли, если применимо |
| `permission_keys` | Snapshot permissions |
| `permission_labels` | Snapshot русских подписей |
| `domain_surface` | `admin`, `lk`, `mobile`, `customer` |
| `assignment_source_id` | Ссылка на assignment/custom role/exception, если есть |
| `assigned_by_user_id` | Кто выдал доступ, если известно |
| `assigned_at` | Когда доступ выдан, если известно |
| `last_used_at` | Последнее использование, новое для реализации |
| `risk_level` | `low`, `medium`, `high`, `critical` |
| `risk_flags` | Snapshot рисков |
| `reviewer_user_id` | Проверяющий |
| `review_owner_user_id` | Владелец доменного решения, если отличается |
| `due_at` | Дедлайн item |
| `status` | `pending`, `in_review`, `approved`, `revoke_requested`, `revoked`, `exception_requested`, `exception_approved`, `exception_expired`, `escalated`, `closed` |
| `decision_id` | Последнее решение |

### 4.4. Reviewer assignment

Владелец кампании отвечает за запуск и закрытие. Reviewer отвечает за конкретные item decisions.

Правила назначения reviewer:

- для project roles reviewer по умолчанию - руководитель проекта или parent administrator, если он не является subject user;
- для finance/payments - финансовый контролер или владелец организации, не участвующий в конфликтующей цепочке;
- для warehouse/mobile - руководитель складского/проектного контура, не subject user;
- для MDM/1C - steward или ответственный за мастер-данные;
- для customer roles - customer owner/curator со стороны заказчика, если организация использует customer контур;
- для system/system_admin - `security_auditor` или platform security owner.

Если reviewer совпадает с subject user, система должна требовать alternate reviewer. Если alternate reviewer не найден, item получает status `escalated`.

### 4.5. Deadlines

Нужно поддержать три уровня сроков:

| Уровень | Назначение |
| --- | --- |
| Campaign due date | Дата, к которой вся кампания должна быть закрыта |
| Item due date | Дата решения по конкретному доступу |
| Exception due date | Дата истечения временно принятого риска |

Дедлайны рассчитываются при launch:

- high/critical risk item: 3-5 рабочих дней;
- ordinary role review: 10-15 рабочих дней;
- external audit campaign: по параметрам кампании;
- exception: не дольше следующей кампании или 30 календарных дней для role-level exception, если иное не задано политикой.

Просрочка не должна автоматически отзывать доступ без policy-флага. Базовое поведение: escalation, audit event, report marker. Автоматический revoke является новым для реализации и должен быть отдельной политикой.

## 5. Решения review

### 5.1. `approve`

`approve` означает, что reviewer подтверждает необходимость доступа до следующей проверки.

Обязательные поля:

- `decision='approve'`;
- `reason` или выбранный business reason code;
- `valid_until` или `next_review_at`;
- reviewer snapshot;
- access snapshot;
- risk flags на момент решения.

Для high/critical risk approve без reason запрещен. Для item с active SoD flag approve должен сохранять ссылку на risk flag и требовать дополнительный reviewer или exception, если политика кампании это требует.

### 5.2. `revoke`

`revoke` означает, что доступ должен быть удален или сокращен.

Решение должно создавать revocation task, а не обязательно сразу менять RBAC:

- `decision='revoke'`;
- `revoke_reason`;
- `target_access_snapshot`;
- `revocation_due_at`;
- `executor_user_id`;
- `execution_status`: `pending`, `completed`, `failed`, `cancelled`;
- immutable audit event при решении и при фактическом исполнении.

Фактический revoke должен вызывать существующий `AuthorizationService` / domain role service, а не удалять связи напрямую. Если доступ задан через JSON `RoleDefinitions`, revoke означает remediation request: изменить роль или убрать user assignment/custom role.

### 5.3. `exception`

`exception` означает временное принятие риска.

Обязательные поля:

- `decision='exception'`;
- `exception_reason`;
- `risk_acceptance_owner_user_id`;
- `valid_until`;
- `compensating_controls`;
- `linked_sod_rule_ids`, если применимо;
- `exception_scope`: role, permission, user, project, organization;
- `review_after_at`;
- `approved_by_user_id`, если exception требует отдельного подтверждения.

Exception не должен быть бессрочным. Для high/critical risk нужен независимый approver, который не является subject user и не является reviewer item, если политика требует two-person control.

## 6. Risk flags и связь с SoD

`risk_flags` - snapshot риска, который попадает в item, decision и report.

Базовый shape:

```json
[
  {
    "code": "SOD-RBAC-002",
    "source": "sod_role_heatmap",
    "level": "high",
    "domain": "rbac",
    "message": "Роль совмещает создание, согласование и исполнение в финансовом контуре",
    "permission_keys": ["payments.invoice.create", "payments.transaction.approve", "payments.transaction.register"],
    "related_role_context": "admin",
    "related_role_slug": "finance_admin",
    "detected_at": "2026-06-22T10:00:00Z",
    "runtime_status": "target_from_pherp_133"
  }
]
```

Источники flags:

| Source | Статус |
| --- | --- |
| `role_definitions_static_scan` | Можно реализовать первым: анализ JSON RoleDefinitions и custom roles |
| `permission_prefix_policy` | Можно реализовать первым: high-risk prefixes `payments.`, `procurement.`, `warehouse.`, `mdm.`, `one_c_exchange.`, `immutable_audit.` |
| `sod_role_heatmap` | Новое для реализации PHERP-133, затем используется PHERP-136 |
| `sod_active_violation` | Новое для реализации PHERP-133 |
| `sod_exception_history` | Новое для реализации PHERP-133 |
| `stale_access` | Новое для реализации: last-used и user lifecycle source |
| `employee_exit_or_inactive` | Новое для реализации: HR/lifecycle source |

Риск не должен автоматически превращаться в отказ. Risk flag влияет на:

- приоритет item;
- дедлайн;
- необходимость reason;
- необходимость second reviewer;
- попадание в dangerous access report;
- запрет silent approve в high/critical случаях.

## 7. Evidence и immutable audit

### 7.1. Evidence snapshot

Evidence должен отвечать на вопрос: "почему этот доступ был оставлен, отозван или временно принят".

Состав evidence:

- campaign scope snapshot;
- список выбранных users/roles/projects/domains;
- role assignment snapshot;
- custom role permission snapshot;
- permission labels snapshot из `lang/ru/permissions.php` / `PermissionTranslator`;
- reviewer assignment snapshot;
- risk flags snapshot;
- SoD links snapshot, если есть;
- decision reason;
- revocation execution result;
- exception expiry and compensating controls;
- export metadata: кто выгрузил, когда, по каким фильтрам.

Если evidence включает файл или вложение, будущая реализация должна использовать S3 через `App\Services\Storage\FileService` и путь организации `org-{organization_id}/...`.

### 7.2. Immutable audit events

Для первого релиза PHERP-136 использовать существующий immutable audit domain `rbac`, потому что текущий check constraint уже допускает `rbac`.

Значения `Result` ниже относятся к целевому audit result-контракту PHERP-134 (`success`, `denied`, `failed`, `exception_used`, `scheduled`) и не являются статусами campaign/item. При production-реализации PHERP-137/PHERP-136 эти значения нужно явно поддержать в API/UI-лейблах audit viewer, если они используются в событиях recertification.

Целевые event types:

| Event type | Когда пишется | Result |
| --- | --- | --- |
| `access_recertification.campaign.created` | Создан draft | `success` |
| `access_recertification.campaign.launched` | Scope зафиксирован, items созданы | `success` |
| `access_recertification.item.assigned` | Reviewer назначен на item | `success` |
| `access_recertification.decision.approved` | Доступ подтвержден | `success` |
| `access_recertification.decision.revoke_requested` | Принято решение отозвать | `scheduled` |
| `access_recertification.revocation.completed` | Доступ фактически отозван | `success` |
| `access_recertification.revocation.failed` | Отзыв не выполнен | `failed` |
| `access_recertification.decision.exception_requested` | Запрошено принятие риска | `scheduled` |
| `access_recertification.decision.exception_approved` | Временное исключение согласовано | `exception_used` |
| `access_recertification.exception.expired` | Истек срок исключения | `success` |
| `access_recertification.item.overdue` | Item просрочен | `failed` или `denied` по политике |
| `access_recertification.campaign.completed` | Кампания закрыта | `success` |
| `access_recertification.report.exported` | Выгружен evidence/report | `success` |

Поле `subject_type`:

- `access_recertification_campaign`;
- `access_recertification_item`;
- `access_recertification_decision`;
- `user_role_assignment`;
- `organization_custom_role`;
- `sod_violation` после реализации PHERP-133.

`domain_context` должен включать:

- `campaign_id`;
- `item_id`;
- `decision`;
- `role_context`;
- `role_slug`;
- `domain_surface`;
- `risk_level`;
- `risk_flags`;
- `reviewer_user_id`;
- `subject_user_id`;
- `due_at`;
- `valid_until`;
- `revocation_status`;
- `linked_sod_rule_ids`.

### 7.3. Audit-on-read/export

CSV/XLSX/PDF export отчетов recertification должен писать `access_recertification.report.exported` с фильтрами, row count, actor, organization, correlation id и masking policy. Экспорт не должен включать секреты, токены, приватные ключи, полные реквизиты, bearer/session tokens или технические stack traces.

## 8. Целевые permissions

Ниже перечислены новые permissions для будущей реализации. Они не существуют в текущих `config/RoleDefinitions` и `lang/ru/permissions.php` и должны быть добавлены только в production-задаче реализации с русскими подписями и тестами `PermissionTranslator`.

| Permission | Новое для реализации | Назначение |
| --- | --- | --- |
| `access_recertification.campaigns.view` | да | Просмотр кампаний пересмотра доступов |
| `access_recertification.campaigns.manage` | да | Создание и изменение draft/scheduled кампаний |
| `access_recertification.campaigns.launch` | да | Запуск кампании и фиксация scope |
| `access_recertification.campaigns.complete` | да | Закрытие кампании |
| `access_recertification.reviews.view` | да | Просмотр назначенных review items |
| `access_recertification.reviews.decide` | да | Принятие решений approve/revoke/exception по item |
| `access_recertification.revocations.execute` | да | Исполнение revocation задач |
| `access_recertification.exceptions.approve` | да | Согласование временного принятия риска |
| `access_recertification.reports.view` | да | Просмотр отчетов |
| `access_recertification.reports.export` | да | Экспорт evidence и отчетов |

Рекомендуемые назначения для будущей реализации:

- `security_auditor`: view/report/export, exception approve для platform review;
- `system_admin`: полный набор, включая launch/complete и retention-sensitive evidence access;
- `organization_owner`: campaign view/manage/launch/complete внутри организации, но не self-review;
- `organization_admin`: campaign view/manage, review decisions по организационным ролям;
- `web_admin`: view/review/report по admin surface, без platform-sensitive прав;
- `finance_admin`: reviewer по finance/payments risk items, но не self-review;
- `customer_owner`: reviewer по customer context, если включен customer scope.

Назначения выше являются рекомендацией. Перед реализацией нужно проверить, что API ролей и UI показывают русские подписи, а технические slug-и не попадают в пользовательский интерфейс как основной текст.

## 9. API contract

Все маршруты являются новыми для реализации и должны возвращать `AdminResponse`. List endpoints должны возвращать `data`, `meta`, `summary`; клиентская нормализация должна учитывать пагинированный контракт.

Базовый prefix: `/api/v1/admin/access-recertification`.

| Method | Path | Permission | Назначение |
| --- | --- | --- | --- |
| `GET` | `/campaigns` | `access_recertification.campaigns.view` | Реестр кампаний |
| `POST` | `/campaigns` | `access_recertification.campaigns.manage` | Создать draft |
| `GET` | `/campaigns/{campaign}` | `access_recertification.campaigns.view` | Деталь кампании |
| `PATCH` | `/campaigns/{campaign}` | `access_recertification.campaigns.manage` | Изменить draft/scheduled |
| `POST` | `/campaigns/{campaign}/launch` | `access_recertification.campaigns.launch` | Зафиксировать scope и создать items |
| `POST` | `/campaigns/{campaign}/complete` | `access_recertification.campaigns.complete` | Закрыть кампанию |
| `POST` | `/campaigns/{campaign}/cancel` | `access_recertification.campaigns.manage` | Отменить кампанию до completion |
| `GET` | `/campaigns/{campaign}/items` | `access_recertification.reviews.view` | Список item |
| `GET` | `/items/{item}` | `access_recertification.reviews.view` | Деталь item |
| `POST` | `/items/{item}/decisions` | `access_recertification.reviews.decide` | Решение approve/revoke/exception |
| `POST` | `/items/{item}/reassign` | `access_recertification.campaigns.manage` | Назначить другого reviewer |
| `GET` | `/revocations` | `access_recertification.revocations.execute` | Очередь отзыва доступов |
| `POST` | `/revocations/{revocation}/complete` | `access_recertification.revocations.execute` | Зафиксировать исполнение revoke |
| `POST` | `/exceptions/{exception}/approve` | `access_recertification.exceptions.approve` | Согласовать временное принятие риска |
| `POST` | `/exceptions/{exception}/reject` | `access_recertification.exceptions.approve` | Отклонить временное принятие риска |
| `GET` | `/reports/summary` | `access_recertification.reports.view` | Сводка просрочек, рисков, исключений |
| `GET` | `/reports/overdue` | `access_recertification.reports.view` | Просроченные review items |
| `GET` | `/reports/dangerous-access` | `access_recertification.reports.view` | Опасные доступы |
| `GET` | `/reports/exceptions` | `access_recertification.reports.view` | Активные и истекающие exceptions |
| `GET` | `/reports/export` | `access_recertification.reports.export` | Evidence export |

### 9.1. Create campaign request

```json
{
  "name": "Квартальный пересмотр доступов Q3 2026",
  "description": "Проверка критичных доступов финансов, закупок, склада и MDM",
  "type": "scheduled",
  "risk_mode": "high_risk_only",
  "scope": {
    "organization_id": 15,
    "project_ids": [101, 102],
    "role_contexts": ["admin", "lk", "mobile", "customer"],
    "domains": ["admin", "lk", "mobile", "customer"],
    "permission_prefixes": ["payments.", "procurement.", "warehouse.", "mdm.", "one_c_exchange.", "immutable_audit."]
  },
  "owner_user_id": 501,
  "escalation_user_id": 502,
  "starts_at": "2026-07-01T09:00:00+03:00",
  "due_at": "2026-07-15T18:00:00+03:00"
}
```

Validation requirements:

- `due_at` must be after `starts_at`;
- scope organization must match current organization context;
- project ids must belong to organization;
- `role_contexts` must be among known RoleDefinitions contexts;
- `domains` must be among `admin`, `lk`, `mobile`, `customer`;
- owner and escalation users must be active in the organization;
- user-facing validation messages must use `trans_message(...)`.

### 9.2. Campaign list response

```json
{
  "success": true,
  "data": [
    {
      "id": "4ccf35cf-ef61-47f0-a36d-6b76a7f27bb5",
      "name": "Квартальный пересмотр доступов Q3 2026",
      "status": "active",
      "type": "scheduled",
      "risk_mode": "high_risk_only",
      "owner": {
        "id": 501,
        "name": "Анна Петрова"
      },
      "starts_at": "2026-07-01T09:00:00+03:00",
      "due_at": "2026-07-15T18:00:00+03:00",
      "summary": {
        "items_total": 128,
        "items_pending": 73,
        "items_overdue": 4,
        "approved": 31,
        "revoke_requested": 12,
        "exceptions_active": 8,
        "critical_risk": 6
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 1,
    "last_page": 1
  },
  "summary": {
    "active_campaigns": 1,
    "overdue_campaigns": 0,
    "open_high_risk_items": 6,
    "pending_revocations": 12
  }
}
```

### 9.3. Decision request

```json
{
  "decision": "exception",
  "reason": "Доступ нужен до завершения закрытия квартала, после чего будет отозван",
  "valid_until": "2026-07-31T18:00:00+03:00",
  "compensating_controls": [
    "Ежедневная сверка платежей вторым контролером",
    "Запрет самостоятельного подтверждения оплаты"
  ],
  "evidence_notes": "Согласовано с финансовым контролером",
  "linked_sod_rule_ids": ["SOD-PAY-004", "SOD-RBAC-002"]
}
```

Rules:

- `approve` requires `next_review_at` or campaign default;
- `revoke` requires `revoke_reason` and executor;
- `exception` requires `valid_until`, reason and compensating controls;
- high/critical risk exception requires independent approver;
- reviewer cannot decide own access;
- decision must write immutable audit event in the same transactional boundary as item update, or fail closed.

## 10. Reports

### 10.1. Просрочки

Report: overdue access reviews.

Поля:

- campaign;
- item;
- reviewer;
- subject user;
- role context/slug;
- domain surface;
- risk level;
- days overdue;
- escalation owner;
- last reminder timestamp;
- recommended action.

Цель: операционный контроль выполнения кампании.

### 10.2. Опасные доступы

Report: dangerous access.

Criteria:

- high/critical `risk_level`;
- role has conflicting permissions from PHERP-132/133;
- broad admin role: `web_admin`, `organization_admin`, `organization_owner`, `finance_admin`;
- sensitive permissions: payments approval/register, MDM apply/override, warehouse manage stock/write-offs, 1C tokens/mappings/import/export, immutable audit sensitive/export/integrity;
- access older than policy threshold without review;
- user inactive, external or project membership ended;
- active SoD violation or repeated exceptions after PHERP-133 implementation.

### 10.3. Exceptions

Report: accepted risks and temporary exceptions.

Поля:

- exception id;
- campaign/item;
- subject user;
- role/permission;
- risk level;
- approved by;
- valid until;
- days to expiry;
- compensating controls;
- linked SoD rules;
- revocation plan after expiry;
- evidence export link.

Expired exception must create overdue marker and escalation. It must not silently become permanent access.

## 11. Admin UI contract

Future admin implementation should add an operational control center, not a generic log screen.

Recommended route: `/access-recertification`.

Required zones:

- campaign registry with status, due date, owner, progress, overdue and risk counters;
- campaign detail with tabs: scope, items, decisions, revocations, exceptions, evidence;
- reviewer queue: "мои проверки";
- dangerous access report;
- overdue report;
- exceptions report;
- evidence export action guarded by `access_recertification.reports.export`;
- inline item decision dialog with approve/revoke/exception;
- reassign reviewer dialog for campaign owners;
- immutable audit link to `/logs/immutable-audit` filtered by correlation id.

UX states:

- loading: skeleton/compact progress;
- empty: explain which scope filters produced no items;
- error: business-readable message, no technical terms;
- permission denied: show page-level guard aligned with backend permission;
- partial data gap: show "недостаточно данных для автоматической проверки" and include item in report-only review.

The page must reuse typed services and response normalization patterns from `immutableAuditService.ts`: paginated list, `summary`, defensive optional fields.

## 12. Data gaps and production constraints

Current gaps that PHERP-136 implementation must handle explicitly:

- no runtime `Core/Sod` module yet;
- no runtime `sod_*` tables yet;
- no `access_recertification.*` permissions yet;
- no dedicated immutable audit domain `access_recertification` yet;
- `immutable_audit.events.view_sensitive` существует в ролях и переводах, но текущий detail-route immutable audit и admin detail UI отдельно не используют это право для показа `before_state`, `after_state`, `diff`, `domain_context`, actor snapshot и idempotency key; production-реализация recertification не должна показывать sensitive evidence без отдельного guard/redaction решения;
- no universal `last_used_at` for permissions/roles;
- no unified HR source for employee exit/inactive status in this spec;
- no automatic mapping from customer users to reviewer hierarchy beyond RoleDefinitions/customer roles;
- existing RoleDefinitions can contain broad permissions by design, so first release should report role-level conflicts instead of blocking role assignments globally.

Production-sized requirements:

- campaign launch must be async for large organizations;
- item generation must be idempotent by campaign version + assignment snapshot hash;
- reviewer queues must be paginated and filterable;
- reminders/escalations must be background jobs with retry and deduplication;
- exports must be async when row count exceeds safe synchronous threshold;
- all state transitions must be transaction-safe;
- revoke execution must be idempotent and auditable;
- no DB/tinker/seed/migration command is required for this specification.

## 13. Реализационный план

1. Add migrations/models for campaigns, items, decisions, exceptions, revocation tasks and exports.
2. Add permissions `access_recertification.*` to `lang/ru/permissions.php` and role JSONs with Russian labels and PermissionTranslator coverage.
3. Implement `AccessRecertificationScopeResolver` using RoleDefinitions, custom roles and role assignments.
4. Implement static risk scanner using PHERP-132/133 rule ids without assuming runtime SoD tables.
5. Implement campaign launch job with idempotent item generation and immutable audit event.
6. Implement reviewer assignment and self-review prevention.
7. Implement decisions approve/revoke/exception with evidence snapshot and immutable audit.
8. Implement revocation queue through existing authorization services.
9. Implement reports: overdue, dangerous access, exceptions, summary/export.
10. Integrate SoD runtime when PHERP-133 production module exists.
11. Add admin UI control center and typed service layer.
12. Add browser smoke for admin UI only if a local or deployed URL is available without forbidden dev-server/build commands.

## 14. Тестовые сценарии будущей реализации

Unit:

- scope resolver includes only selected organization/projects/role contexts/domains;
- reviewer assignment never assigns subject user as own reviewer;
- decision validator requires reason for high/critical approve and all exceptions;
- exception expiry creates overdue marker;
- risk scanner flags broad roles and PHERP-132/133 conflicts;
- immutable audit event builder uses domain `rbac` and event type `access_recertification.*`;
- permission translations exist for every `access_recertification.*` permission.

Contract:

- paginated campaign list returns `data`, `meta`, `summary`;
- item list tolerates missing `last_used_at` and missing SoD runtime links;
- decision endpoint returns business-readable validation errors;
- report export writes audit-on-export event.

Domain workflow:

- campaign launch creates stable item snapshots and cannot be launched twice;
- reviewer approves safe access and item closes with next review date;
- reviewer requests revoke, revocation task is created and later completed idempotently;
- high-risk exception requires independent approver and expires;
- campaign cannot complete with pending critical items unless policy allows documented exception;
- broad role conflicts are report-only in first release.

Feature/API tests must not use `RefreshDatabase` in this project unless a future explicit project rule changes that constraint.

## 15. Acceptance criteria

PHERP-136 считается готовой как проектная спецификация, если:

- описана campaign model, scope, owner/reviewer/deadline model;
- scope покрывает organization, projects, roles, users, domains `admin`, `lk`, `mobile`, `customer`;
- decisions `approve`, `revoke`, `exception` имеют обязательные поля, статусы и последствия;
- evidence и immutable audit events определены через существующий protected audit approach;
- явно описана связь с SoD risk flags и PHERP-132/133;
- описаны отчеты по просрочкам, опасным доступам и исключениям;
- новые permissions помечены как новое для реализации и не выданы за существующие runtime slug-и;
- учтены реальные `RoleDefinitions` contexts и существующие `immutable_audit.*` permissions;
- указано, что runtime-код, миграции, роли и переводы в PHERP-136 не менялись.

## 16. Ограничения PHERP-136

- Production-код не менялся.
- Миграции не создавались и не запускались.
- DB/tinker/seed/rollback/reset/delete/verify/dry-run команды не запускались.
- Dev-server и build для admin/land не запускались.
- `RoleDefinitions` и `lang/ru/permissions.php` не менялись.
- Context7 не использовался, потому что задача является локальной проектной спецификацией и не опирается на актуальный синтаксис внешних библиотек, SDK, CLI или сторонних API.
- Markdown в `docs/specs` игнорируется `.gitignore`, поэтому файл нужно добавлять в git через `git add -f docs/specs/PHERP-136-access-recertification-campaigns.md`.

## 17. Повторная проверка 2026-06-22

Статус PHERP-136 в YouTrack на момент повторной проверки: `Done`. Спецификация остается spec-only результатом: runtime-код, миграции, API, admin UI, роли и переводы не менялись.

Проверенные факты текущего `main`:

- спецификация `docs/specs/PHERP-136-access-recertification-campaigns.md` уже находится в git;
- PHERP-132/133 остаются проектными спецификациями, runtime-модуль `app/BusinessModules/Core/Sod` не найден;
- runtime permissions `sod.*` и `access_recertification.*` не найдены в `config/RoleDefinitions` и `lang/ru/permissions.php`;
- реальные contexts `RoleDefinitions`: `admin`, `customer`, `lk`, `mobile`, `project`, `system`, `system_admin`;
- immutable audit из PHERP-135 реализован в `app/BusinessModules/Core/ImmutableAudit`, admin API `routes/api/v1/admin/immutable_audit.php` и admin UI `/logs/immutable-audit`;
- существующие permissions `immutable_audit.*` переведены и назначены в `RoleDefinitions`;
- `immutable_audit.events.view_sensitive` переведен и назначен, но текущий PHERP-135 detail-route `GET /immutable-audit/events/{event}` защищен только `immutable_audit.events.view`, а admin detail UI не проверяет `VIEW_SENSITIVE`; PHERP-137 должен либо добавить отдельное применение этого права, либо не выводить sensitive evidence без redaction;
- домен immutable audit `access_recertification` отсутствует, поэтому первый production-релиз recertification должен использовать существующий домен `rbac` с `event_type=access_recertification.*` или отдельно менять check constraint.

Вывод повторной проверки: критерии PHERP-136 как проектной спецификации покрыты. Следующая задача должна быть отдельной production-реализацией с миграциями, русскими подписями прав, покрытием `PermissionTranslator`, API/admin UI и проверками без `RefreshDatabase`, если проектное правило не изменится.
