# PHERP-76. Multi-org / multi-1C integration profiles

Дата: 2026-06-07

Статус: спецификация для будущей реализации backend, API и admin UI.

Задача: PHERP-76, `[ERP-01][PHERP-19] Спроектировать настройки нескольких организаций и баз 1С`.

## 1. Назначение

Эта спецификация описывает целевую модель настроек обмена между МОСТ и 1С для сценариев, где в одной организации МОСТ может быть несколько юридических лиц, несколько баз 1С, разные контуры обмена и разные правила маршрутизации документов.

Документ не требует немедленного изменения кода и не создает миграции. Он фиксирует контракт, по которому можно реализовать:

- backend-модель данных;
- Admin API;
- delivery worker и monitoring, привязанные к конкретному профилю;
- admin UI для управления профилями интеграции;
- PHERP-77, где будет реализована проверка подключения.

## 2. Контекст существующего 1C-контура

Текущий backend уже содержит базовый контур обмена 1С:

- маршруты `prohelper/routes/api/v1/admin/one_c_exchange.php`;
- контроллер `prohelper/app/Http/Controllers/Api/V1/Admin/OneCExchangeController.php`;
- токены `App\Models\OneCExchangeToken`;
- ручной import/export через `App\Services\OneCManualExchangeService`;
- журнал через `OneCExchangeOperation`, `OneCExchangeMessage`, `OneCExchangeJournalService`;
- reference mappings через `OneCExchangeMapping`, `OneCMappingService`;
- conflict model через `OneCExchangeConflict`, `OneCExchangeConflictEvent`, `OneCExchangeConflictService`;
- monitoring через `OneCExchangeMonitoringService`;
- delivery worker через `OneCExchangeDeliveryOrchestrator`, `HttpOneCExchangeClient`, `one-c-exchange:deliver`;
- глобальную delivery-конфигурацию `prohelper/config/one_c_exchange.php`.

Текущий admin UI находится в:

- `prohelper_admin/src/pages/Integrations/OneCExchangePage.tsx`;
- `prohelper_admin/src/services/oneCExchangeService.ts`;
- `prohelper_admin/src/types/oneCExchange.ts`;
- компонентах `prohelper_admin/src/pages/Integrations/components`.

Сейчас контур почти везде ограничен `organization_id`, но не различает:

- legal entity;
- one_c_base;
- integration profile;
- production/test environment;
- endpoint и secret на уровне конкретного профиля.

Из-за этого будущий ERP-grade обмен не может безопасно поддерживать несколько юрлиц и несколько баз 1С без риска смешения данных.

## 3. Опорные документы

Спецификация опирается на уже принятые документы:

- `docs/specs/erp-roadmap/adr-prohelper-1c-accounting-boundaries.md`;
- `docs/specs/erp-roadmap/source-of-truth-matrix.md`;
- `docs/specs/erp-roadmap/prohibited-accounting-duplications.md`;
- `docs/specs/erp-roadmap/1c-exchange-event-catalog.md`;
- `docs/specs/erp-roadmap/1c-sync-journal-model.md`;
- `docs/specs/erp-roadmap/1c-reference-mapping-model.md`;
- `docs/specs/erp-roadmap/1c-document-mapping-rules.md`;
- `docs/specs/erp-roadmap/1c-exchange-conflict-model.md`;
- `docs/specs/erp-roadmap/1c-exchange-queue-idempotency.md`;
- `docs/specs/erp-roadmap/1c-exchange-failure-retry-actuality.md`;
- `docs/specs/PHERP-75-one-c-exchange-incidents-runbook.md`.

## 4. Непересекаемые source of truth

Базовое архитектурное правило не меняется:

- МОСТ остается операционной строительной ERP и operational source of truth.
- 1С остается бухгалтерским и налоговым source of truth.
- МОСТ не становится accounting-core, не рассчитывает регламентированную бухгалтерию и не подменяет 1С.
- 1С не управляет операционными статусами строительных процессов в МОСТ.

Операционные статусы МОСТ не являются бухгалтерским проведением 1С.

Примеры:

- заявка на оплату в МОСТ может быть согласована, но это не означает, что платежный документ проведен в 1С;
- акт может быть принят в операционном контуре МОСТ, но бухгалтерское отражение остается отдельным статусом 1С;
- складская операция в МОСТ может быть подтверждена на объекте, но налоговый или бухгалтерский учет движения остается в 1С;
- статус `sent` или `accepted` в обмене не равен `posted` в 1С.

Для всех интеграционных сущностей должны сохраняться отдельные оси:

- operational status в МОСТ;
- sync status в слое обмена;
- accounting status в 1С.

## 5. Термины

### 5.1. organization

`organization` - текущая организация МОСТ, tenant и операционный контур, который уже используется в backend через `organization_id`.

Организация МОСТ может содержать несколько юридических лиц. Поэтому `organization_id` нельзя использовать как единственный идентификатор стороны бухгалтерского обмена.

### 5.2. legal entity

`legal entity` - юридическое лицо внутри организации МОСТ.

Юрлицо определяет:

- ИНН, КПП, ОГРН или другие регистрационные реквизиты;
- 1С-организацию, в которую должен попадать документ;
- набор документов и справочников, которые относятся к этому юрлицу;
- права пользователей на просмотр и изменение настроек обмена.

Юрлицо не равно МОСТ organization. В простом сценарии у организации одно юрлицо, но модель должна поддерживать несколько.

### 5.3. one_c_base

`one_c_base` - конкретная база 1С или логический контур 1С, с которым МОСТ обменивается данными.

База 1С включает:

- environment;
- connector;
- endpoint;
- версию протокола;
- набор поддерживаемых scopes;
- статус подключения;
- технические настройки доставки и retry.

Одна база 1С может обслуживать несколько юридических лиц одной МОСТ organization, если это явно настроено через разные integration profiles.

### 5.4. connector

`connector` - способ связи с 1С.

На первом этапе целевой тип connector:

- `http` - отправка из delivery worker в endpoint 1С;
- `manual` - ручная выгрузка или загрузка без автоматической доставки.

Модель должна оставлять место для будущих типов:

- `agent` - промежуточный агент на стороне клиента;
- `file` - обмен через файлы;
- `webhook` - входящие события из 1С.

### 5.5. endpoint

`endpoint` - адрес подключения к connector для конкретной базы или профиля.

Endpoint не должен отображаться полностью, если в нем могут быть учетные данные, токены или чувствительные параметры. В UI допустимы только безопасные части: домен, порт, путь без query-параметров, fingerprint.

### 5.6. integration profile

`integration profile` - активная настройка обмена, которая связывает:

- organization;
- legal entity;
- one_c_base;
- environment;
- connector;
- exchange mode;
- routing;
- permissions;
- secrets;
- monitoring;
- audit/history.

Профиль является основной единицей маршрутизации документов, delivery worker, мониторинга, журнала, conflict resolution и ручных операций.

### 5.7. environment

`environment` - контур обмена:

- `production`;
- `test`;
- `sandbox`.

Данные production и test нельзя смешивать в одном профиле, одном idempotency key и одном accounting status.

## 6. Ключевые архитектурные решения

1. `one_c_base_id` должен быть first-class идентификатором, а не строкой в настройках.
2. `legal_entity_id` должен быть first-class идентификатором для бухгалтерских документов.
3. `integration_profile_id` должен попадать во все новые exchange operations, messages, mappings, conflicts и monitoring-срезы.
4. `external_id` уникален только внутри пары `organization_id + one_c_base_id + scope + external_object_type`.
5. Один и тот же `external_id` в разных базах 1С не является конфликтом.
6. Один `external_id` в одной базе 1С и одном scope, привязанный к нескольким активным локальным объектам, является conflict.
7. Один локальный объект может иметь разные mappings в разных базах 1С, если это явно разрешено routing rules.
8. Delivery worker должен отправлять операции в endpoint профиля, а не в глобальный endpoint из env.
9. Monitoring должен показывать состояние по профилю, базе, юрлицу, environment и scope.
10. Secrets никогда не возвращаются API и не пишутся в журнал, логи, audit или UI.
11. Отключение профиля не удаляет историю, mappings, journal и conflicts.
12. Изменение endpoint не переписывает историю старых operations/messages.

## 7. Целевая модель данных

Миграции в рамках PHERP-76 не создаются. Ниже описана целевая схема.

### 7.1. `one_c_legal_entities`

Юридические лица внутри МОСТ organization.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Внутренний идентификатор |
| `organization_id` | fk | да | МОСТ organization |
| `display_name` | text | да | Человекочитаемое название для UI |
| `legal_name` | text | да | Полное юридическое название |
| `tax_number` | text | да | ИНН или локальный налоговый номер |
| `tax_registration_code` | text nullable | нет | КПП или аналог |
| `registration_number` | text nullable | нет | ОГРН или аналог |
| `status` | enum | да | `active`, `inactive`, `archived` |
| `is_default` | boolean | да | Юрлицо по умолчанию для организации |
| `source` | enum | да | `manual`, `one_c`, `migration` |
| `safe_payload_preview` | jsonb nullable | нет | Только безопасные реквизиты без секретов |
| `created_by` | fk nullable | нет | Автор создания |
| `updated_by` | fk nullable | нет | Автор последнего изменения |
| `deactivated_by` | fk nullable | нет | Кто отключил |
| `deactivated_at` | timestamptz nullable | нет | Дата отключения |
| `created_at` | timestamptz | да | Создание |
| `updated_at` | timestamptz | да | Обновление |

Индексы и ограничения:

- index `one_c_legal_entities_organization_status_idx` по `organization_id, status`;
- unique partial `one_c_legal_entities_default_active_uniq` по `organization_id` where `is_default = true and status = 'active'`;
- unique partial `one_c_legal_entities_tax_active_uniq` по `organization_id, tax_number, tax_registration_code` where `status = 'active'`;
- check: `display_name <> ''`, `legal_name <> ''`, `tax_number <> ''`.

### 7.2. `one_c_bases`

Базы или логические контуры 1С, доступные внутри организации.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Внутренний идентификатор базы |
| `organization_id` | fk | да | МОСТ organization |
| `code` | text | да | Машинный код базы внутри организации |
| `name` | text | да | Название для админки |
| `environment` | enum | да | `production`, `test`, `sandbox` |
| `connector_type` | enum | да | `http`, `manual`, позднее `agent`, `file`, `webhook` |
| `endpoint_url_encrypted` | text nullable | нет | Зашифрованный endpoint |
| `endpoint_host` | text nullable | нет | Безопасное отображение домена |
| `endpoint_fingerprint` | text nullable | нет | Хеш endpoint для audit и диагностики |
| `protocol_version` | text nullable | нет | Версия протокола обмена |
| `connector_version` | text nullable | нет | Версия connector на стороне 1С |
| `status` | enum | да | `draft`, `active`, `paused`, `deactivated` |
| `connection_status` | enum | да | `untested`, `checking`, `ok`, `warning`, `failed`, `unauthorized`, `unconfigured` |
| `last_connection_check_at` | timestamptz nullable | нет | Последняя проверка |
| `last_connection_check_code` | text nullable | нет | Безопасный код результата |
| `last_successful_exchange_at` | timestamptz nullable | нет | Последний успешный обмен |
| `timeout_seconds` | integer | да | Таймаут запроса |
| `retry_policy` | jsonb | да | Лимиты retry без секретов |
| `supported_scopes` | jsonb | да | Список scopes, поддержанных базой |
| `created_by` | fk nullable | нет | Автор |
| `updated_by` | fk nullable | нет | Последний редактор |
| `deactivated_by` | fk nullable | нет | Кто отключил |
| `deactivated_at` | timestamptz nullable | нет | Дата отключения |
| `created_at` | timestamptz | да | Создание |
| `updated_at` | timestamptz | да | Обновление |

Индексы и ограничения:

- unique `one_c_bases_org_env_code_uniq` по `organization_id, environment, code`;
- index `one_c_bases_org_env_status_idx` по `organization_id, environment, status`;
- index `one_c_bases_org_connection_status_idx` по `organization_id, connection_status`;
- check: production endpoint не может быть пустым для active http connector;
- check: `timeout_seconds between 1 and 120`;
- check: `endpoint_url_encrypted` не может содержать незашифрованное значение в открытом виде.

Endpoint хранится отдельно от secrets, но также считается чувствительным значением.

### 7.3. `one_c_integration_profiles`

Профили интеграции. Это основная сущность PHERP-76.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Идентификатор профиля |
| `organization_id` | fk | да | МОСТ organization |
| `legal_entity_id` | fk | да | Юрлицо |
| `one_c_base_id` | fk | да | База 1С |
| `code` | text | да | Машинный код профиля внутри организации |
| `name` | text | да | Название профиля |
| `environment` | enum | да | Дублирует environment базы для быстрых фильтров и ограничений |
| `exchange_mode` | enum | да | `disabled`, `manual`, `outbound_only`, `inbound_only`, `bidirectional` |
| `status` | enum | да | `draft`, `active`, `paused`, `degraded`, `error`, `deactivated` |
| `status_reason_code` | text nullable | нет | Безопасная причина состояния |
| `is_default_for_legal_entity` | boolean | да | Профиль по умолчанию для юрлица и environment |
| `routing_priority` | integer | да | Приоритет при выборе профиля |
| `allowed_scopes` | jsonb | да | Разрешенные scopes |
| `schedule_config` | jsonb | да | Расписание обмена |
| `retry_policy` | jsonb | да | Политика повторов |
| `monitoring_config` | jsonb | да | Окна SLA и escalation |
| `manual_actions_enabled` | boolean | да | Разрешены ли ручные операции |
| `activated_at` | timestamptz nullable | нет | Когда включен |
| `paused_at` | timestamptz nullable | нет | Когда приостановлен |
| `deactivated_at` | timestamptz nullable | нет | Когда отключен |
| `created_by` | fk nullable | нет | Автор |
| `updated_by` | fk nullable | нет | Последний редактор |
| `deactivated_by` | fk nullable | нет | Кто отключил |
| `created_at` | timestamptz | да | Создание |
| `updated_at` | timestamptz | да | Обновление |

Индексы и ограничения:

- unique `one_c_profiles_org_code_uniq` по `organization_id, code`;
- unique partial `one_c_profiles_default_uniq` по `organization_id, legal_entity_id, environment` where `is_default_for_legal_entity = true and status in ('active', 'degraded')`;
- index `one_c_profiles_org_status_idx` по `organization_id, status`;
- index `one_c_profiles_org_legal_entity_idx` по `organization_id, legal_entity_id`;
- index `one_c_profiles_org_base_idx` по `organization_id, one_c_base_id`;
- check: `environment` профиля должен совпадать с `environment` базы;
- check: `active` profile требует active legal entity и active one_c_base;
- check: `exchange_mode = 'disabled'` не может иметь `status = 'active'`;
- check: пустой `allowed_scopes` допустим только для `draft` или `disabled`.

### 7.4. `one_c_profile_secrets`

Секреты профилей. Значения никогда не возвращаются API.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Идентификатор секрета |
| `organization_id` | fk | да | МОСТ organization |
| `integration_profile_id` | fk | да | Профиль |
| `secret_type` | enum | да | `bearer_token`, `basic_auth`, `certificate`, `webhook_secret` |
| `encrypted_value` | text | да | Зашифрованное значение |
| `value_fingerprint` | text | да | Невосстановимый fingerprint |
| `label` | text nullable | нет | Короткая безопасная метка |
| `status` | enum | да | `active`, `rotating`, `revoked`, `expired` |
| `last_used_at` | timestamptz nullable | нет | Последнее использование |
| `expires_at` | timestamptz nullable | нет | Срок действия |
| `rotated_from_id` | fk nullable | нет | Предыдущий секрет |
| `created_by` | fk nullable | нет | Автор |
| `revoked_by` | fk nullable | нет | Кто отозвал |
| `revoked_at` | timestamptz nullable | нет | Когда отозван |
| `created_at` | timestamptz | да | Создание |
| `updated_at` | timestamptz | да | Обновление |

Индексы и ограничения:

- index `one_c_profile_secrets_profile_status_idx` по `integration_profile_id, status`;
- unique partial `one_c_profile_secrets_active_type_uniq` по `integration_profile_id, secret_type` where `status in ('active', 'rotating')`;
- check: `encrypted_value` не может быть пустым;
- check: `value_fingerprint` не равен `encrypted_value`.

Правило безопасности: ни один log, audit, journal, monitoring response, conflict response или validation error не должен содержать `encrypted_value` или исходный secret.

### 7.5. `one_c_document_routing_rules`

Правила routing для документов и справочников.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Идентификатор правила |
| `organization_id` | fk | да | МОСТ organization |
| `integration_profile_id` | fk | да | Куда маршрутизировать |
| `legal_entity_id` | fk nullable | нет | Юрлицо, если правило ограничено им |
| `scope` | enum | да | Scope обмена |
| `entity_type` | text nullable | нет | Тип локального документа |
| `direction` | enum | да | `import`, `export`, `prohelper_to_1c`, `1c_to_prohelper` |
| `condition` | jsonb | да | Детерминированное условие без исполняемого кода |
| `priority` | integer | да | Приоритет |
| `route_mode` | enum | да | `required`, `optional`, `blocked` |
| `status` | enum | да | `active`, `paused`, `archived` |
| `effective_from` | timestamptz nullable | нет | Начало действия |
| `effective_to` | timestamptz nullable | нет | Окончание действия |
| `created_by` | fk nullable | нет | Автор |
| `updated_by` | fk nullable | нет | Последний редактор |
| `created_at` | timestamptz | да | Создание |
| `updated_at` | timestamptz | да | Обновление |

Индексы и ограничения:

- index `one_c_routing_org_scope_direction_idx` по `organization_id, scope, direction, status`;
- index `one_c_routing_profile_idx` по `integration_profile_id, status`;
- unique partial `one_c_routing_priority_uniq` по `organization_id, scope, direction, priority` where `status = 'active'`;
- check: `effective_to is null or effective_to > effective_from`;
- check: `condition` не содержит секреты или raw document body.

Правило не должно быть исполняемым скриптом. Условия должны быть из разрешенного набора: legal entity, project, contract type, warehouse, document type, scope, direction, amount range, date range, organization branch.

### 7.6. `one_c_profile_audit_events`

Audit/history изменений настроек.

Поля:

| Поле | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `id` | uuid/bigint | да | Идентификатор audit event |
| `organization_id` | fk | да | МОСТ organization |
| `integration_profile_id` | fk nullable | нет | Профиль |
| `one_c_base_id` | fk nullable | нет | База |
| `legal_entity_id` | fk nullable | нет | Юрлицо |
| `routing_rule_id` | fk nullable | нет | Правило маршрутизации |
| `secret_id` | fk nullable | нет | Secret, только идентификатор |
| `actor_user_id` | fk nullable | нет | Пользователь |
| `action` | text | да | Тип действия |
| `from_status` | text nullable | нет | Предыдущее состояние |
| `to_status` | text nullable | нет | Новое состояние |
| `safe_diff` | jsonb | да | Безопасная разница без секретов |
| `reason` | text nullable | нет | Причина изменения |
| `request_context` | jsonb nullable | нет | Безопасный контекст запроса |
| `created_at` | timestamptz | да | Время события |

Индексы:

- index `one_c_profile_audit_org_created_idx` по `organization_id, created_at desc`;
- index `one_c_profile_audit_profile_created_idx` по `integration_profile_id, created_at desc`;
- index `one_c_profile_audit_action_idx` по `organization_id, action, created_at desc`.

`safe_diff` обязан скрывать endpoint credentials, secrets, raw payload, stack trace, SQL/constraint diagnostics и внутренние exception details.

### 7.7. Расширение существующих таблиц обмена

В будущих миграциях существующие таблицы должны получить nullable-поля на первом этапе rollout:

- `integration_profile_id`;
- `one_c_base_id`;
- `legal_entity_id`;
- `environment`.

Затрагиваемые таблицы:

- `one_c_exchange_runs`;
- `one_c_exchange_operations`;
- `one_c_exchange_messages`;
- `one_c_exchange_mappings`;
- `one_c_exchange_conflicts`;
- `one_c_exchange_conflict_events`;
- при необходимости `one_c_exchange_tokens`, если legacy tokens будут мигрировать в profile secrets.

Новые индексы:

- operations: `organization_id, integration_profile_id, status, next_attempt_at`;
- operations: `organization_id, one_c_base_id, scope, created_at`;
- operations: unique `organization_id, integration_profile_id, idempotency_key`;
- operations: unique `organization_id, integration_profile_id, operation_key`;
- messages: `organization_id, integration_profile_id, operation_id, created_at`;
- mappings: `organization_id, one_c_base_id, scope, external_object_type, external_id`;
- mappings: active unique by local object and profile/base;
- conflicts: `organization_id, integration_profile_id, status, severity, created_at`;
- conflicts: unique `organization_id, integration_profile_id, conflict_key`.

На первом этапе поля nullable нужны для совместимости с текущими историческими записями. Для новых операций после включения профилей эти поля обязательны на уровне сервиса.

## 8. Статусы и режимы

### 8.1. Profile status

| status | Значение |
| --- | --- |
| `draft` | Профиль создан, но не готов к обмену |
| `active` | Профиль участвует в routing и delivery |
| `paused` | Новые операции не создаются, ручные действия ограничены |
| `degraded` | Профиль работает, но есть проблемы мониторинга или частичные сбои |
| `error` | Профиль требует вмешательства |
| `deactivated` | Профиль отключен, история сохраняется |

### 8.2. Connection status

| status | Значение |
| --- | --- |
| `untested` | Проверка подключения еще не выполнялась |
| `checking` | Проверка выполняется |
| `ok` | Подключение прошло успешно |
| `warning` | Подключение доступно, но есть некритичные предупреждения |
| `failed` | Подключение недоступно |
| `unauthorized` | Ошибка авторизации |
| `unconfigured` | Не хватает endpoint, connector или secrets |

### 8.3. Exchange mode

| Режим | Значение |
| --- | --- |
| `disabled` | Обмен выключен |
| `manual` | Только ручные import/export |
| `outbound_only` | Только МОСТ -> 1С |
| `inbound_only` | Только 1С -> МОСТ |
| `bidirectional` | Двунаправленный обмен |

### 8.4. Environment

Production и test должны быть изолированы:

- разные integration profiles;
- разные idempotency keys;
- разные connection checks;
- разные monitoring slices;
- разные routing rules;
- разные permissions для опасных действий, если это требуется политикой организации.

## 9. Правила routing документов

### 9.1. Общий алгоритм выбора профиля

Для каждого нового exchange event backend должен выбрать один integration profile.

Порядок:

1. Если request/event явно содержит `integration_profile_id`, проверить его принадлежность текущей organization, legal entity, environment и allowed scopes.
2. Если указан `legal_entity_id`, найти активные routing rules для этого юрлица, scope, entity type и direction.
3. Если найдено одно активное правило с максимальным приоритетом, использовать его profile.
4. Если правил нет, использовать default profile для `legal_entity_id + environment`, если он поддерживает scope и direction.
5. Если найдено несколько равнозначных правил, создать conflict типа `routing_ambiguous`.
6. Если профиль не найден, операция получает status `requires_mapping` или `failed` с безопасным кодом `profile_not_configured`, в зависимости от типа события.

Бухгалтерские документы нельзя маршрутизировать без legal entity.

### 9.2. Детерминированность

routing должен быть детерминированным:

- один входной документ при неизменных настройках всегда выбирает один и тот же profile;
- изменение routing rules применяется только к новым операциям;
- старые operations/messages сохраняют старый `integration_profile_id`;
- idempotency key включает profile, one_c_base, legal entity и environment.

### 9.3. Формат operation key и idempotency key

Рекомендуемый формат operation key:

```text
org:{organization_id}:legal:{legal_entity_id}:base:{one_c_base_id}:profile:{integration_profile_id}:env:{environment}:scope:{scope}:command:{command_type}:period:{period}:actor:{actor_id}
```

Рекомендуемый формат idempotency key:

```text
org:{organization_id}:legal:{legal_entity_id}:base:{one_c_base_id}:profile:{integration_profile_id}:env:{environment}:direction:{direction}:scope:{scope}:entity:{entity_type}:{entity_id}:action:{action}:version:{business_version}
```

Нельзя использовать ключ, в котором нет `one_c_base_id` и `integration_profile_id`, для новых ERP-grade операций.

### 9.4. Scope-level routing

Для каждого scope нужно определить допустимые направления:

- `counterparties`, `materials`, `nomenclature`, `cost_categories`, `cost_centers`, `warehouses` - чаще reference mappings;
- `contracts`, `acts`, `payment_documents`, `procurement_documents`, `warehouse_documents` - документный обмен с legal entity;
- `advance_transactions`, `bank_transactions` - обмен с жестким разделением accounting status;
- `projects`, `organizations`, `employees` - справочники с повышенным риском пересечения прав.

Если scope не входит в `allowed_scopes` профиля, создание операции запрещено.

## 10. Ownership external ids и mappings

### 10.1. Граница владения

МОСТ владеет:

- локальными операционными идентификаторами;
- статусами строительных процессов;
- журналом отправки и обработки;
- routing rules;
- decision audit по conflict resolution.

1С владеет:

- бухгалтерскими external ids;
- регламентированными учетными статусами;
- проведением бухгалтерских и налоговых документов;
- бухгалтерскими реквизитами, если они отмечены как 1С-owned.

### 10.2. Уникальность mappings

Уникальность external id:

```text
organization_id + one_c_base_id + scope + external_object_type + external_id
```

Уникальность local object mapping:

```text
organization_id + integration_profile_id + scope + local_object_type + local_object_id
```

Правила:

- Один local object может иметь разные external ids в разных one_c_base.
- Один external id может повторяться в разных one_c_base без conflict.
- Один external id в одной one_c_base и одном scope не может вести к нескольким active local objects.
- Если 1С передала external id, который уже связан с другим local object в той же базе и scope, создается conflict.
- Изменение mapping создает history event и не переписывает старый journal.

### 10.3. Mapping status

Целевые статусы:

- `active`;
- `inactive`;
- `needs_review`;
- `conflict`;
- `superseded`;
- `archived`.

`superseded` используется при переносе mapping на другой profile/base или при безопасной замене external id.

### 10.4. Mapping history

Для всех изменений mapping нужны:

- кто изменил;
- когда изменил;
- reason;
- old/new безопасные значения;
- связанный conflict, если изменение было результатом conflict resolution;
- ссылка на profile и one_c_base.

Raw payload 1С не хранится и не отображается. Допустим только safe preview.

## 11. Conflict model для multi-base

Conflict должен всегда содержать:

- `organization_id`;
- `integration_profile_id`;
- `one_c_base_id`;
- `legal_entity_id`, если применимо;
- `scope`;
- `entity_type`;
- `entity_id`;
- `external_id`, если есть;
- `conflict_type`;
- `status`;
- `severity`;
- safe values МОСТ;
- safe values 1С;
- affected operations/messages.

Новые conflict types:

- `routing_ambiguous`;
- `profile_not_configured`;
- `legal_entity_missing`;
- `one_c_base_inactive`;
- `profile_scope_forbidden`;
- `external_id_duplicate_in_base`;
- `mapping_profile_mismatch`;
- `environment_mismatch`;
- `secret_missing_or_revoked`;
- `endpoint_changed_during_delivery`.

Conflict между разными базами 1С не создается только из-за совпадения external id. Conflict создается, если совпадение external id происходит внутри одной `one_c_base_id + scope + external_object_type`.

Conflict resolution UI должен показывать профиль, юрлицо и базу 1С как обязательный контекст решения.

## 12. Safe storage secrets

Обязательные правила:

- secrets хранятся только в зашифрованном виде;
- API никогда не возвращает исходное значение secret;
- UI показывает только label, fingerprint, status, дату создания, дату истечения и last used;
- logs не содержат secrets, raw payload, stack trace, SQL, constraint diagnostics, full endpoint with credentials;
- audit содержит только safe_diff;
- connection check не пишет raw response 1С;
- validation errors не содержат переданный secret;
- secret rotation создает новый secret и переводит старый в `rotating` или `revoked`;
- revoked secret нельзя использовать delivery worker.

Разрешенные операции с secrets:

- создать;
- заменить;
- отозвать;
- посмотреть metadata;
- проверить, что активный secret есть.

Операция просмотра исходного secret запрещена.

## 13. Permissions

Существующие permissions нужно сохранить:

- `one_c_exchange.view`;
- `one_c_exchange.manage_tokens`;
- `one_c_exchange.manage_mappings`;
- `one_c_exchange.import`;
- `one_c_exchange.export`;
- `one_c_exchange.history.view`;
- `one_c_exchange.retry`;
- `one_c_exchange.dead_letter.manage`;
- `one_c_exchange.conflicts.view`;
- `one_c_exchange.conflicts.resolve`.

Для профилей нужны новые permissions:

| Permission | Назначение |
| --- | --- |
| `one_c_exchange.profiles.view` | Просмотр списка и карточки профилей |
| `one_c_exchange.profiles.create` | Создание профилей |
| `one_c_exchange.profiles.update` | Изменение профилей, режимов и расписания |
| `one_c_exchange.profiles.deactivate` | Отключение и повторное включение |
| `one_c_exchange.profiles.test_connection` | Проверка подключения |
| `one_c_exchange.routing.manage` | Управление routing rules |
| `one_c_exchange.secrets.manage` | Создание, замена и отзыв secrets |
| `one_c_exchange.audit.view` | Просмотр audit/history настроек |
| `one_c_exchange.legal_entities.manage` | Управление юрлицами для обмена |
| `one_c_exchange.bases.manage` | Управление базами 1С |

При реализации нужно добавить русские названия permissions и групп в `prohelper/lang/ru/permissions.php` и обновить RoleDefinitions для нужных ролей.

Права должны быть scope-aware там, где это критично:

- пользователь, который решает conflicts по платежам, не обязательно должен решать payroll или warehouse conflicts;
- пользователь, который может запускать manual export в test, не обязательно может запускать production export;
- пользователь с правом `secrets.manage` не получает право видеть значения secrets.

## 14. Backend API contracts

Префикс: `/api/v1/admin/one-c-exchange`.

Все ответы должны использовать `AdminResponse`, а не прямой `response()->json()`.

Все ошибки должны быть безопасными:

- без raw payload;
- без stack trace;
- без secret;
- без полного endpoint с credentials;
- без внутренних exception details.

### 14.1. List profiles

`GET /profiles`

Permission: `one_c_exchange.profiles.view`.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `environment` | string nullable | `production`, `test`, `sandbox` |
| `status` | string nullable | Фильтр по profile status |
| `connection_status` | string nullable | Фильтр по connection status |
| `legal_entity_id` | string nullable | Фильтр по юрлицу |
| `one_c_base_id` | string nullable | Фильтр по базе 1С |
| `exchange_mode` | string nullable | Фильтр по режиму |
| `scope` | string nullable | Профили, поддерживающие scope |
| `search` | string nullable | Поиск по name, code, legal entity, base |
| `include_inactive` | boolean | Показывать отключенные |
| `page` | integer | Страница |
| `per_page` | integer | Размер страницы |

Response:

```json
{
  "success": true,
  "message": "Профили обмена 1С получены",
  "data": [
    {
      "id": "profile-uuid",
      "organization_id": "org-uuid",
      "name": "Основной контур 1С",
      "code": "main-production",
      "environment": "production",
      "exchange_mode": "bidirectional",
      "status": "active",
      "connection_status": "ok",
      "legal_entity": {
        "id": "legal-entity-uuid",
        "display_name": "ООО Строй",
        "tax_number": "7700000000"
      },
      "one_c_base": {
        "id": "base-uuid",
        "name": "Бухгалтерия предприятия",
        "connector_type": "http",
        "endpoint_host": "1c.example.ru"
      },
      "allowed_scopes": ["contracts", "acts", "payment_documents"],
      "is_default_for_legal_entity": true,
      "last_connection_check_at": "2026-06-07T08:00:00Z",
      "last_successful_exchange_at": "2026-06-07T08:10:00Z",
      "open_conflicts_count": 0,
      "problem_operations_count": 0
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 1
    },
    "summary": {
      "active": 1,
      "degraded": 0,
      "error": 0,
      "deactivated": 0
    }
  }
}
```

### 14.2. Detail profile

`GET /profiles/{profile}`

Permission: `one_c_exchange.profiles.view`.

Response должен включать:

- profile summary;
- legal entity;
- one_c_base;
- connector metadata без secrets;
- routing rules;
- allowed scopes;
- monitoring summary;
- latest journal status;
- open conflicts summary;
- audit summary.

### 14.3. Create profile

`POST /profiles`

Permission: `one_c_exchange.profiles.create`.

Request:

```json
{
  "name": "Основной контур 1С",
  "code": "main-production",
  "legal_entity_id": "legal-entity-uuid",
  "one_c_base_id": "base-uuid",
  "environment": "production",
  "exchange_mode": "bidirectional",
  "allowed_scopes": ["contracts", "acts", "payment_documents"],
  "is_default_for_legal_entity": true,
  "routing_priority": 100,
  "schedule_config": {
    "enabled": true,
    "interval_minutes": 15
  },
  "retry_policy": {
    "max_attempts": 5,
    "base_delay_seconds": 60
  }
}
```

Validation:

- legal entity belongs to current organization;
- base belongs to current organization;
- environment equals base environment;
- code is unique inside organization;
- active default profile for same legal entity and environment cannot be duplicated;
- allowed scopes are supported by base;
- production profile cannot use test base;
- active profile requires connection settings, but draft may be incomplete.

### 14.4. Update profile

`PATCH /profiles/{profile}`

Permission: `one_c_exchange.profiles.update`.

Allowed fields:

- `name`;
- `exchange_mode`;
- `allowed_scopes`;
- `routing_priority`;
- `is_default_for_legal_entity`;
- `schedule_config`;
- `retry_policy`;
- `monitoring_config`;
- `manual_actions_enabled`.

Restricted changes:

- `organization_id` cannot change;
- changing `legal_entity_id` is blocked if profile has operations, mappings or conflicts;
- changing `one_c_base_id` is blocked if profile has operations, mappings or conflicts;
- changing `environment` is not allowed;
- changing `code` is allowed only before activation.

### 14.5. Deactivate profile

`POST /profiles/{profile}/deactivate`

Permission: `one_c_exchange.profiles.deactivate`.

Request:

```json
{
  "reason": "Юрлицо больше не используется",
  "mode": "stop_new_operations"
}
```

Modes:

- `stop_new_operations` - запретить новые операции, текущие могут завершиться;
- `pause_all` - приостановить delivery для текущих операций;
- `archive_after_drain` - отключить после завершения очереди.

Response:

- profile status;
- blocked operations count;
- open conflicts count;
- warning, если профиль default для legal entity.

Rules:

- deactivation не удаляет mappings;
- deactivation не удаляет journal;
- deactivation не удаляет conflicts;
- default profile нельзя отключить без выбора нового default, если для legal entity есть active routing.

### 14.6. Reenable profile

`POST /profiles/{profile}/reenable`

Permission: `one_c_exchange.profiles.deactivate`.

Request:

```json
{
  "reason": "Профиль возвращен после проверки",
  "run_connection_check": true
}
```

Rules:

- revoked secrets не восстанавливаются автоматически;
- если `run_connection_check = true`, запуск проверки выполняется по контракту PHERP-77;
- нельзя включить profile, если base или legal entity inactive;
- нельзя включить production profile с failed connection status без отдельного подтверждения policy.

### 14.7. Test connection

`POST /profiles/{profile}/test-connection`

Permission: `one_c_exchange.profiles.test_connection`.

Этот endpoint является основной точкой PHERP-77. В PHERP-76 фиксируется контракт и требования, реализация проверки относится к следующей задаче.

Request:

```json
{
  "timeout_seconds": 10,
  "check_permissions": true,
  "check_protocol_version": true
}
```

Response:

```json
{
  "success": true,
  "message": "Подключение к 1С проверено",
  "data": {
    "profile_id": "profile-uuid",
    "connection_status": "ok",
    "checked_at": "2026-06-07T08:20:00Z",
    "duration_ms": 420,
    "safe_result_code": "connection_ok",
    "checks": [
      {
        "code": "endpoint_reachable",
        "status": "ok"
      },
      {
        "code": "authorization_ok",
        "status": "ok"
      },
      {
        "code": "protocol_supported",
        "status": "ok"
      }
    ]
  }
}
```

Failure response:

```json
{
  "success": false,
  "message": "Не удалось проверить подключение к 1С",
  "error": {
    "code": "connection_unauthorized",
    "details": {
      "profile_id": "profile-uuid",
      "safe_result_code": "authorization_failed"
    }
  }
}
```

### 14.8. Manage secrets

`POST /profiles/{profile}/secrets`

Permission: `one_c_exchange.secrets.manage`.

Request:

```json
{
  "secret_type": "bearer_token",
  "value": "new-secret-value",
  "label": "Основной токен",
  "expires_at": "2026-12-31T21:00:00Z"
}
```

Response:

```json
{
  "success": true,
  "message": "Секрет подключения обновлен",
  "data": {
    "id": "secret-uuid",
    "secret_type": "bearer_token",
    "status": "active",
    "label": "Основной токен",
    "fingerprint": "sha256:ab12...90",
    "expires_at": "2026-12-31T21:00:00Z",
    "created_at": "2026-06-07T08:30:00Z"
  }
}
```

API не возвращает `value`.

`DELETE /profiles/{profile}/secrets/{secret}`

Permission: `one_c_exchange.secrets.manage`.

Отзывает secret. Delivery worker перестает использовать его для новых попыток.

### 14.9. Routing rules

`GET /profiles/{profile}/routing-rules`

Permission: `one_c_exchange.profiles.view`.

`POST /profiles/{profile}/routing-rules`

Permission: `one_c_exchange.routing.manage`.

Request:

```json
{
  "scope": "payment_documents",
  "entity_type": "payment_request",
  "direction": "prohelper_to_1c",
  "priority": 100,
  "route_mode": "required",
  "condition": {
    "legal_entity_id": "legal-entity-uuid",
    "project_group": "north"
  },
  "effective_from": "2026-06-07T00:00:00Z"
}
```

Validation:

- profile active or draft;
- scope allowed by profile;
- no ambiguous active rule with same priority;
- condition uses only supported keys;
- condition has no raw document data or secrets.

### 14.10. Audit

`GET /profiles/{profile}/audit`

Permission: `one_c_exchange.audit.view`.

Query:

- `action`;
- `actor_user_id`;
- `from`;
- `to`;
- `page`;
- `per_page`.

Response содержит только safe_diff и metadata.

### 14.11. Legal entities

`GET /legal-entities`

Permission: `one_c_exchange.profiles.view`.

`POST /legal-entities`

Permission: `one_c_exchange.legal_entities.manage`.

`PATCH /legal-entities/{legalEntity}`

Permission: `one_c_exchange.legal_entities.manage`.

`POST /legal-entities/{legalEntity}/deactivate`

Permission: `one_c_exchange.legal_entities.manage`.

### 14.12. 1C bases

`GET /bases`

Permission: `one_c_exchange.profiles.view`.

`POST /bases`

Permission: `one_c_exchange.bases.manage`.

`PATCH /bases/{base}`

Permission: `one_c_exchange.bases.manage`.

`POST /bases/{base}/deactivate`

Permission: `one_c_exchange.bases.manage`.

Endpoint fields:

- create/update принимает endpoint;
- response возвращает только safe endpoint display;
- audit хранит endpoint fingerprint, а не полное значение.

### 14.13. Расширение существующих endpoints

Существующие endpoints должны поддержать фильтры:

- `integration_profile_id`;
- `one_c_base_id`;
- `legal_entity_id`;
- `environment`.

Затрагиваемые endpoints:

- `GET /status`;
- `GET /monitoring`;
- `GET /health`;
- `GET /metrics`;
- `GET /reference-mappings`;
- `GET /reference-mappings/{mapping}`;
- `GET /history`;
- `GET /journal`;
- `GET /journal/{operation}`;
- `POST /journal/{operation}/retry`;
- `POST /journal/{operation}/dead-letter`;
- `GET /conflicts`;
- `GET /conflicts/{conflict}`;
- `POST /conflicts/{conflict}/actions`;
- `POST /import`;
- `POST /export`.

Для обратной совместимости, если фильтры не указаны, backend может использовать default active profile текущей organization. Если default profile не настроен, response должен быть безопасным:

```json
{
  "success": false,
  "message": "Профиль обмена 1С не настроен",
  "error": {
    "code": "profile_not_configured"
  }
}
```

## 15. Ошибки API

Коды ошибок:

| Код | HTTP | Когда возникает |
| --- | --- | --- |
| `profile_not_configured` | 422 | Нет подходящего профиля |
| `profile_not_active` | 409 | Профиль выключен или приостановлен |
| `profile_scope_forbidden` | 422 | Scope не разрешен профилем |
| `routing_ambiguous` | 409 | Несколько равнозначных правил |
| `legal_entity_required` | 422 | Документ требует юрлицо |
| `legal_entity_inactive` | 409 | Юрлицо отключено |
| `one_c_base_inactive` | 409 | База 1С отключена |
| `environment_mismatch` | 422 | Профиль и база из разных контуров |
| `secret_required` | 422 | Нет активного секрета |
| `secret_revoked` | 409 | Secret отозван |
| `connection_check_failed` | 422 | Проверка подключения не прошла |
| `external_id_conflict` | 409 | Конфликт mapping внутри одной базы |
| `active_operations_exist` | 409 | Нельзя отключить профиль без решения очереди |
| `default_profile_required` | 409 | Нельзя убрать default без замены |
| `permission_denied` | 403 | Недостаточно прав |

Все ошибки должны быть человекочитаемыми в UI и переводиться через `trans_message(...)` в PHP-реализации.

## 16. Admin UX

Основной раздел остается `/integrations/1c`, но должен стать profile-aware.

### 16.1. Список профилей

Первый экран раздела должен показывать список integration profiles.

Колонки:

- статус профиля;
- статус подключения;
- название профиля;
- legal entity;
- one_c_base;
- environment;
- exchange mode;
- connector type;
- endpoint host;
- allowed scopes;
- последняя проверка подключения;
- последний успешный обмен;
- открытые conflicts;
- проблемные operations;
- действия.

Фильтры:

- status;
- connection status;
- environment;
- legal entity;
- one_c_base;
- exchange mode;
- scope;
- search.

Действия:

- открыть карточку;
- создать профиль;
- проверить подключение;
- приостановить;
- отключить;
- повторно включить;
- открыть journal по профилю;
- открыть conflicts по профилю;
- открыть routing.

### 16.2. Карточка профиля

Карточка профиля должна иметь вкладки:

- Overview;
- Connection;
- Routing;
- Mappings;
- Journal;
- Conflicts;
- Monitoring;
- Audit;
- Rollout.

Overview:

- profile status;
- connection status;
- legal entity;
- one_c_base;
- environment;
- exchange mode;
- default marker;
- supported scopes;
- last connection check;
- last exchange;
- open conflicts;
- problem operations;
- next scheduled run.

Connection:

- connector type;
- safe endpoint display;
- protocol version;
- connector version;
- secret metadata без значения;
- кнопка test connection;
- кнопка rotate/revoke secret.

Routing:

- rules table;
- priority;
- scope;
- direction;
- condition summary;
- active/paused status;
- conflict warning if ambiguous.

Mappings:

- текущий `OneCMappingPanel`, но с фильтром profile/base/legal entity;
- duplicate warnings внутри одной базы;
- ссылки на conflict resolution.

Journal:

- текущий `OneCJournalPanel`, но операции показываются в контексте profile/base/legal entity;
- retry/dead-letter доступны только по правам и только если профиль допускает действие.

Conflicts:

- текущий `OneCConflictPanel`, но profile/base/legal entity обязательны в заголовке;
- actions должны проверять, что mapping меняется внутри правильной базы.

Monitoring:

- текущий monitoring panel, но срезы по profile;
- отдельные SLA для production/test;
- состояние delivery worker по профилю.

Audit:

- история изменения profile/base/legal entity/routing/secrets;
- safe_diff;
- actor;
- reason;
- timestamp.

Rollout:

- состояние включения профиля;
- checklist готовности;
- ссылки на последние checks;
- предупреждения перед production activation.

### 16.3. Loading, empty, error states

Loading:

- skeleton таблицы профилей;
- отдельные loading states для вкладок;
- кнопки опасных действий disabled во время выполнения.

Empty:

- нет профилей: показать действие "Создать профиль обмена 1С";
- нет legal entities: показать действие "Добавить юридическое лицо";
- нет bases: показать действие "Добавить базу 1С";
- нет mappings: показать безопасное описание, что связи еще не созданы;
- нет conflicts: показать нейтральное состояние без технических деталей.

Error:

- показывать безопасный текст;
- не показывать raw payload, stack trace, endpoint secret, internal exception;
- для `permission_denied` скрывать недоступные действия;
- для `profile_not_configured` предлагать перейти к настройке профиля, если есть права.

No access:

- если нет `one_c_exchange.profiles.view`, раздел не показывается;
- если нет `one_c_exchange.secrets.manage`, secret actions скрыты;
- если нет `one_c_exchange.profiles.test_connection`, test connection скрыт или disabled с понятным пояснением.

## 17. Delivery worker и monitoring

### 17.1. Delivery worker

Текущий `HttpOneCExchangeClient` использует глобальный endpoint/token из config. Целевая реализация должна:

- выбирать pending operations с учетом `integration_profile_id`;
- загружать endpoint и active secret профиля;
- проверять status профиля и базы перед отправкой;
- не отправлять операции через deactivated/paused profile;
- учитывать exchange mode;
- использовать per-profile retry policy;
- писать safe request/response preview без raw payload;
- обновлять operation/message с profile/base/legal entity context;
- создавать conflict или safe failure при routing/profile errors.

Очередь должна разделяться по:

- organization;
- legal entity;
- one_c_base;
- integration profile;
- scope;
- direction.

### 17.2. Monitoring

Monitoring должен агрегировать:

- profile status;
- connection status;
- delivery health;
- operations by sync status;
- conflicts by severity;
- mapping coverage;
- retry/dead-letter counts;
- last successful exchange;
- stale operations;
- unconfigured profiles;
- disabled delivery by profile.

Фильтры monitoring:

- `integration_profile_id`;
- `legal_entity_id`;
- `one_c_base_id`;
- `environment`;
- `scope`;
- `direction`;
- `from`;
- `to`;
- `window_hours`.

Production и test показываются раздельно.

## 18. Audit/history

Audit обязателен для:

- создания профиля;
- изменения режима обмена;
- изменения allowed scopes;
- изменения default profile;
- изменения endpoint;
- создания, rotation и revoke secrets;
- изменения routing rules;
- активации, pause, deactivate, reenable;
- test connection result;
- conflict resolution, если решение изменило mapping или статус операции.

Audit не должен хранить:

- raw payload;
- stack trace;
- secret;
- полное значение endpoint, если оно содержит credentials;
- внутренние exception details;
- SQL text и constraint diagnostics.

Для endpoint changes audit хранит:

- old endpoint fingerprint;
- new endpoint fingerprint;
- safe host/path display;
- actor;
- reason;
- timestamp.

## 19. Environments и production/test изоляция

Правила:

- production profile не может использовать test one_c_base;
- test profile не может писать в production accounting status;
- production operation не может быть повторно отправлена через test endpoint;
- test connection не должен создавать бухгалтерские документы;
- импорт test данных не должен создавать production mappings;
- journal и conflicts должны явно показывать environment;
- idempotency key включает environment.

Перед включением production profile нужно проверить:

- active legal entity;
- active one_c_base;
- active secret;
- successful connection check;
- routing rules без ambiguity;
- allowed scopes;
- permissions для администраторов;
- monitoring SLA;
- отсутствие blocking conflicts.

## 20. Rollout по организациям

Фаза 0. Спецификация:

- PHERP-76 фиксирует модель, API, UX, edge cases и PHERP-77.

Фаза 1. Backend schema:

- добавить таблицы legal entities, bases, profiles, secrets, routing, audit;
- добавить nullable references в journal/mappings/conflicts;
- legacy данные оставить работоспособными;
- миграции не запускать без отдельной команды.

Фаза 2. Legacy profile:

- для каждой organization с текущим активным 1С-токеном создать draft/manual legacy profile;
- global endpoint из env не переносить в открытую историю;
- secrets мигрировать только через безопасную процедуру.

Фаза 3. API:

- добавить `/profiles`, `/bases`, `/legal-entities`, `/routing-rules`;
- расширить существующие journal/mapping/conflict/monitoring endpoints фильтрами profile/base/legal entity.

Фаза 4. Admin UI:

- добавить список profiles;
- добавить карточку profile;
- встроить текущие panels как вкладки и фильтрованные срезы.

Фаза 5. Per-organization rollout:

- включать profile-aware routing флагом по organization;
- начинать с test environment;
- затем включать production по одному legal entity;
- мониторить operations/conflicts/dead-letter.

Фаза 6. Enforcement:

- для новых ERP-grade operations требовать `integration_profile_id`, `one_c_base_id`, `legal_entity_id` там, где нужен accounting context;
- запретить legacy operation key без profile/base для новых документных scopes.

## 21. Edge cases

### 21.1. Несколько баз 1С для одной organization

Допустимо:

- разные bases для разных legal entities;
- отдельные bases для production и test;
- отдельная base для отдельных scopes, если routing rules детерминированы.

Недопустимо:

- отправить один бухгалтерский документ в две production bases без явного правила split;
- использовать один idempotency key в двух bases;
- считать совпадающий external id между bases conflict.

### 21.2. Одна база 1С для нескольких legal entities

Допустимо, если:

- у каждого legal entity есть отдельный integration profile;
- routing rules явно указывают legal entity;
- mappings хранят legal entity/profile context;
- conflicts показывают legal entity.

Риск: external id может совпадать между организациями внутри 1С. Поэтому uniqueness строится по one_c_base и scope, а связь с legal entity проверяется отдельно.

### 21.3. Смена endpoint

Правила:

- endpoint change создает audit event;
- старые operations/messages остаются с прежним profile context;
- retry старой операции использует текущий endpoint профиля, если операция не зафиксирована как snapshot-bound;
- если endpoint changed during delivery, попытка должна завершиться safe failure или retry по политике;
- перед production endpoint change желательно требовать connection check.

### 21.4. Отключение profile

Правила:

- новые operations не создаются;
- active queue обрабатывается согласно mode deactivation;
- mappings остаются read-only или archived по решению администратора;
- conflicts остаются видимыми;
- journal остается доступным;
- default profile нужно заменить, если есть active routing для legal entity.

### 21.5. Conflict external id между базами

Сценарий:

- base A вернула `external_id = 123`;
- base B вернула `external_id = 123`.

Это не conflict, если `one_c_base_id` разные.

Conflict создается только если:

- base одна и та же;
- scope один и тот же;
- external object type один и тот же;
- active mapping ведет к разным local objects.

### 21.6. Rollback и reenable

Rollback:

- pause profile;
- stop new operations;
- wait or dead-letter текущие операции по policy;
- вернуть предыдущий default profile;
- оставить audit/history;
- не удалять mappings.

Reenable:

- проверить active legal entity;
- проверить active base;
- проверить active secret;
- выполнить connection check;
- проверить routing ambiguity;
- восстановить default marker при необходимости.

### 21.7. Права доступа

Сценарии:

- пользователь может видеть profile, но не может менять secrets;
- пользователь может запускать manual import в test, но не production;
- пользователь может решать mapping conflicts, но не менять endpoint;
- пользователь может видеть journal, но не retry/dead-letter.

UI должен скрывать или блокировать действия по permissions. Backend всегда проверяет permissions независимо от UI.

### 21.8. Missing legal entity

Для accounting documents это blocking issue:

- operation не должна уйти в 1С;
- создается safe error `legal_entity_required` или conflict `legal_entity_missing`;
- администратор должен выбрать legal entity или исправить source document.

### 21.9. Profile scope removed

Если scope удален из allowed scopes:

- новые операции этого scope запрещены;
- существующие queued operations обрабатываются по policy;
- retry проверяет текущие allowed scopes и может перейти в `failed` с `profile_scope_forbidden`;
- audit фиксирует изменение.

### 21.10. Secret revoked during retry

Если secret revoked:

- новые delivery attempts не выполняются;
- operation получает safe error `secret_revoked`;
- monitoring показывает profile problem;
- администратор должен добавить новый secret и запустить retry, если это допустимо.

## 22. Изменения в текущем backend, которые потребуются позже

Код в PHERP-76 не меняется. Для будущей реализации нужно будет:

- добавить модели `OneCLegalEntity`, `OneCBase`, `OneCIntegrationProfile`, `OneCProfileSecret`, `OneCDocumentRoutingRule`, `OneCProfileAuditEvent`;
- добавить FormRequest для create/update/test/deactivate;
- добавить services для profile management, routing resolver, secret rotation, audit;
- расширить `OneCExchangeJournalService` profile context;
- расширить `OneCMappingService` unique logic by base/profile;
- расширить `OneCExchangeConflictService` profile-aware conflict keys;
- изменить `HttpOneCExchangeClient` на per-profile endpoint/secret;
- изменить operation repository claim logic с учетом profile status;
- добавить permissions и переводы;
- добавить тесты контрактов API, routing resolver, mapping uniqueness, secret redaction, conflict isolation.

## 23. Изменения в текущем admin UI, которые потребуются позже

Код в PHERP-76 не меняется. Для будущей реализации нужно будет:

- добавить типы `OneCIntegrationProfile`, `OneCBase`, `OneCLegalEntity`, `OneCRoutingRule`, `OneCProfileAuditEvent`;
- расширить `oneCExchangeService` методами profiles/bases/legal-entities/routing/secrets/audit;
- добавить profile list screen;
- добавить profile detail tabs;
- расширить существующие panels фильтрами profile/base/legal entity/environment;
- добавить permission guards для новых permissions;
- добавить безопасное отображение endpoint и secrets metadata;
- добавить Vitest для service normalization и основных UI states.

## 24. PHERP-77. Что делать дальше

PHERP-77 должна реализовать connection check на базе этой спецификации.

Минимальный scope PHERP-77:

1. Backend endpoint `POST /api/v1/admin/one-c-exchange/profiles/{profile}/test-connection`.
2. Permission `one_c_exchange.profiles.test_connection`.
3. Проверка active/draft profile, base, connector, endpoint и active secret.
4. Безопасные проверки:
   - endpoint reachable;
   - TLS/transport доступен, если применимо;
   - authorization ok;
   - protocol version supported;
   - connector version readable;
   - required scopes доступные или явно не проверяются;
   - response time within timeout.
5. Запись результата в profile/base:
   - `connection_status`;
   - `last_connection_check_at`;
   - `last_connection_check_code`;
   - `connector_version`;
   - `protocol_version`;
   - safe warning codes.
6. Audit event `connection_check_run`.
7. Admin UI action "Проверить подключение" в списке и карточке профиля.
8. Loading/error/success states без raw response, secrets и stack trace.
9. Тесты:
   - successful check;
   - unauthorized;
   - timeout;
   - unconfigured endpoint;
   - revoked secret;
   - permission denied;
   - no raw secret in response/log/audit.

PHERP-77 не должна создавать бухгалтерские документы в 1С. Проверка подключения должна быть read-only или использовать специальный ping/metadata endpoint connector.

## 25. Открытые вопросы для реализации

1. Нужен ли отдельный platform-level справочник баз 1С для сценария, где одна физическая база обслуживает несколько МОСТ organizations, или в первой реализации база всегда принадлежит одной organization.
2. Какие exact connector types будут поддержаны в первом релизе кроме `http` и `manual`.
3. Нужна ли отдельная роль для production activation, отличная от обычного update profile.
4. Нужно ли делать field-level ownership rules частью profile или отдельной схемой document mapping rules.
5. Какие scopes должны быть доступны в бесплатном базовом контуре, а какие только в ERP-grade профилях.
6. Нужно ли хранить immutable endpoint snapshot на operation/message для forensic history или достаточно endpoint fingerprint в audit.
7. Какой UI-паттерн выбрать для organization с десятками legal entities и сотнями routing rules.

## 26. Acceptance checklist

Для завершения PHERP-76 достаточно:

- создана эта спецификация;
- зафиксированы organization, legal entity, one_c_base, endpoint, routing, permissions, secrets, audit, status, mapping, conflict;
- отдельно зафиксировано, что операционные статусы МОСТ не являются бухгалтерским проведением 1С;
- описаны data model, API contracts, admin UX, edge cases, rollout;
- добавлен раздел PHERP-77;
- не создавались миграции и не запускались DB-команды.
