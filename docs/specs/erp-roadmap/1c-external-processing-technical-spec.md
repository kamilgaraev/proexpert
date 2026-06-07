# ТЗ для внешней обработки 1С ProHelper

Дата: 2026-06-07
Задача: PHERP-78, `[ERP-01][PHERP-20] Подготовить ТЗ для внешней обработки 1С`
Статус документа: техническая спецификация для 1С-разработчика

## 1. Назначение документа

Документ описывает требования к внешней обработке 1С, расширению 1С или иному 1С-коннектору, который будет обмениваться данными с ProHelper.

Документ можно передать 1С-разработчику как техническое задание и промпт для реализации. Подрядчик должен реализовать сторону 1С так, чтобы она работала с ProHelper без устных уточнений по базовым сценариям: настройка подключения, проверка совместимости, прием сообщений, возврат статусов, защита от дублей, журналирование, безопасные ошибки, mapping и ручная сверка.

Основано на локальных документах:

- `adr-prohelper-1c-accounting-boundaries.md`;
- `source-of-truth-matrix.md`;
- `prohibited-accounting-duplications.md`;
- `1c-exchange-event-catalog.md`;
- `1c-document-mapping-rules.md`;
- `1c-reference-mapping-model.md`;
- `1c-exchange-queue-idempotency.md`;
- `1c-sync-journal-model.md`;
- `1c-multi-org-integration-profiles.md`;
- текущем backend-контуре `App\Services\OneCExchange`, моделях `OneCBase`, `OneCIntegrationProfile`, `OneCProfileSecret`, `OneCProfileAuditEvent` и маршрутах `routes/api/v1/admin/one_c_exchange.php`.

Context7 для подготовки документа не использовался: задача полностью документная и опирается на локальные ADR, roadmap-спеки, YouTrack и текущий код проекта. Актуальный синтаксис внешних библиотек, SDK, CLI или сторонних API здесь не требуется.

## 2. Цель и границы ответственности

### 2.1. Цель

Цель интеграции - передавать между ProHelper и 1С операционные данные строительной ERP, учетные подтверждения, справочники, статусы обмена и данные для сверки без превращения ProHelper в параллельную бухгалтерскую систему.

Внешняя обработка 1С должна:

- принимать от ProHelper утвержденные документы, справочники и пакеты источников;
- возвращать безопасные статусы приема, проведения, отказа или необходимости ручного сопоставления;
- сохранять idempotency key и correlation_id, чтобы повторная доставка не создавала дубли;
- отдавать metadata для smoke-check: `protocol_version`, `connector_version`, `supported_scopes`;
- вести журнал обмена на стороне 1С;
- не раскрывать raw payload, no stack trace, no secrets в пользовательских интерфейсах, журналах для пользователей и ответах API.

### 2.2. Accounting/tax boundaries

Базовое правило accounting/tax boundaries:

- ProHelper = construction ERP / operational source of truth.
- 1С = бухгалтерский и налоговый source of truth.

ProHelper владеет:

- объектами строительства, проектами, графиками, сметами и управленческими бюджетами;
- платежными заявками, согласованиями, приоритетами и платежным календарем;
- закупками, выбором поставщика, заказами и операционными поставками;
- оперативным складом строительной площадки, резервами, движениями и инвентаризацией;
- MDM-качеством операционных справочников;
- явкой, выработкой, нарядами и payroll-source строками;
- ролями, доступами, workflow и управленческой аналитикой.

1С владеет:

- бухгалтерскими документами и учетным отражением;
- проводками, планом счетов, субконто и закрытием периода;
- налоговым учетом, НДС, налоговыми регистрами и декларациями;
- регламентированной отчетностью;
- официальным складским и стоимостным учетом, если он включен в 1С;
- юридически значимой зарплатой, НДФЛ, взносами и кадрово-зарплатной отчетностью;
- учетными кодами, бухгалтерскими статусами и юридически значимыми реквизитами в пределах конфигурации 1С.

Запрещено дублировать в ProHelper:

- бухгалтерские проводки;
- налоговый учет;
- регламентированную отчетность;
- юридически значимый payroll;
- официальное закрытие периода;
- официальный план счетов и налоговые регистры;
- raw бухгалтерский журнал 1С.

Операционные статусы ProHelper не равны учетным статусам 1С:

- `approved` в ProHelper не означает, что документ принят или проведен в 1С;
- `signed` в ProHelper не означает юридически значимую подпись без ЭДО/КЭП;
- `paid` в ProHelper не означает бухгалтерское проведение без банка и/или 1С;
- `posted` допустим только как отдельный accounting status от 1С, если 1С явно вернула подтверждение.

## 3. Целевая архитектура внешней обработки 1С

### 3.1. Роль обработки

Внешняя обработка 1С является адаптером между 1С и ProHelper. Она не должна менять бизнес-границы и не должна скрыто создавать учетные факты без явного документа, статуса и журнала.

Обработка должна поддерживать два режима:

| Режим | Назначение | Требования |
| --- | --- | --- |
| `metadata` | read-only smoke-check подключения | не меняет документы, справочники, регистры учета и бизнес-данные |
| `exchange` | прием или отправка сообщений обмена | пишет только допустимые учетные объекты, регистры интеграции и безопасный журнал |

Если обработка поставляется не как `.epf`, а как расширение 1С, HTTP-сервис или агент, контракт обмена остается тем же.

### 3.2. Настройки

В обработке должны быть настройки:

| Настройка | Обязательность | Описание |
| --- | --- | --- |
| `connector_version` | да | Версия обработки или расширения 1С |
| `protocol_version` | да | Версия протокола обмена с ProHelper. Первый целевой вариант: `1.0` |
| `environment` | да | `production`, `test` или `sandbox` |
| `profile_code` | да | Код integration profile из ProHelper |
| `base_code` | да | Код базы 1С в ProHelper |
| `supported_scopes` | да | Scopes, которые реально реализованы в этой 1С-базе |
| `auth_mode` | да | `bearer`, `basic` или иной согласованный режим |
| `token_or_key` | да, если auth не `none` | Секрет для авторизации запросов ProHelper |
| `callback_url` | нет | URL ProHelper для асинхронных ответов 1С, если сценарий callback включен |
| `timeouts` | да | Таймауты HTTP и внутренних операций |
| `logging_level` | да | Уровень технического журнала без секретов и raw payload |
| `allow_document_write` | да | Отдельный флаг, запрещающий запись документов в test/smoke режимах |

Секреты должны храниться средствами 1С, предназначенными для защищенного хранения, либо в защищенном контуре инфраструктуры клиента. Нельзя хранить токены и пароли в открытых константах, пользовательских комментариях, текстовых файлах поставки или логах.

### 3.3. Контуры и профили

ProHelper поддерживает несколько организаций, юридических лиц, баз 1С и integration profiles.

Обработка должна принимать и сохранять контекст:

| Поле | Назначение |
| --- | --- |
| `organization_id` | Tenant/организация ProHelper |
| `legal_entity_id` | Юрлицо, если ProHelper передает его для бухгалтерского документа |
| `one_c_base_id` или `base_code` | Целевая база 1С |
| `integration_profile_id` или `profile_code` | Профиль обмена |
| `environment` | Контур `production`, `test`, `sandbox` |
| `scope` | Область обмена |

Правила изоляции:

- production и test нельзя смешивать в одном журнале idempotency и одном accounting status;
- idempotency key должен учитывать environment или приходить из ProHelper уже уникальным для environment;
- один и тот же `external_id` в разных базах 1С не является конфликтом;
- один `external_id` в одной базе, одном scope и одном типе объекта, связанный с несколькими ProHelper-объектами, является duplicate mapping conflict;
- отключение или пауза профиля не удаляет журнал и mappings.

### 3.4. Авторизация

Для запросов ProHelper -> 1С:

- основной вариант - HTTPS + `Authorization: Bearer <token>`;
- альтернативный вариант - HTTPS + Basic Auth, если согласовано в profile;
- секрет выбирается на стороне ProHelper из `OneCProfileSecret`;
- 1С должна проверять токен или учетные данные до чтения тела запроса;
- при ошибке авторизации 1С возвращает safe error code `unauthorized`.

Для запросов 1С -> ProHelper:

- использовать выданный ProHelper integration token или иной согласованный секрет;
- передавать `correlation_id`, `idempotency_key`, `scope`, `entity_type`, `entity_id`;
- не передавать секреты в query string;
- не логировать `Authorization`, токены, Basic credentials и ключи.

### 3.5. Проверка подключения

ProHelper уже имеет endpoint проверки профиля:

`POST /api/v1/admin/one-c-exchange/profiles/{profileId}/test-connection`

Этот endpoint вызывает metadata URL базы 1С. На стороне 1С нужно реализовать read-only endpoint:

`GET {configured_endpoint}/{metadata_path}`

По умолчанию `metadata_path = /metadata`.

Ожидаемые headers:

| Header | Значение |
| --- | --- |
| `Authorization` | Bearer или Basic credentials |
| `X-ProHelper-Connection-Check` | `read-only` |
| `X-ProHelper-Integration-Profile` | ID профиля ProHelper |

Ответ metadata:

```json
{
  "status": "ok",
  "protocol_version": "1.0",
  "connector_version": "1.0.0",
  "supported_scopes": [
    "counterparties",
    "contracts",
    "acts",
    "payment_documents",
    "procurement_documents",
    "warehouse_movements",
    "payroll_source"
  ],
  "environment": "test",
  "read_only": true,
  "server_time": "2026-06-07T12:00:00+03:00",
  "warnings": []
}
```

Требования к smoke-check:

- проверка должна быть read-only и не должна создавать или менять документы, справочники, проводки, регистры учета, payroll-данные или mappings;
- допустима только техническая проверка доступности, авторизации, версии протокола, версии обработки и списка supported_scopes;
- при несовместимой версии вернуть `incompatible_version`;
- если отсутствует обязательный scope, вернуть `missing_scope`;
- если токен неверный, вернуть HTTP `401` или `403` и safe error `unauthorized`;
- если обработка не настроена, вернуть `unconfigured`;
- не возвращать raw stack trace, технологический журнал, путь к файлам 1С, SQL/запросы, токены и секреты.

### 3.6. Журнал обмена на стороне 1С

Обработка должна вести собственный журнал интеграции. Реализация в 1С может быть регистром сведений, служебным справочником или другим штатным механизмом, но поля должны покрывать минимум:

| Поле | Назначение |
| --- | --- |
| `correlation_id` | Сквозная связь операции |
| `idempotency_key` | Защита от дублей |
| `operation_key` | Групповой запуск или команда |
| `organization_id` | Организация ProHelper |
| `profile_code` / `integration_profile_id` | Профиль обмена |
| `base_code` / `one_c_base_id` | База 1С |
| `environment` | Контур |
| `scope` | Область обмена |
| `direction` | Направление |
| `entity_type`, `entity_id` | Сущность ProHelper |
| `external_id` | Ссылка 1С |
| `status` | Статус обработки |
| `source_hash`, `payload_hash` | Контроль неизменности |
| `safe_payload_preview` | Безопасная сводка |
| `safe_error_code`, `safe_error_message` | Безопасная ошибка |
| `attempt_count` | Количество попыток |
| `created_at`, `updated_at`, `accepted_at`, `posted_at`, `rejected_at` | Временные метки |

Журнал 1С не должен показывать пользователям raw payload, no raw payload, no stack trace, no secrets. Для технического расследования допускается защищенный support-only контур, если клиент и команда безопасности согласовали доступ и retention.

## 4. Контракт ProHelper -> 1С

### 4.1. Endpoint доставки

Текущий backend отправляет delivery payload на endpoint, настроенный в профиле/конфигурации 1С. Внешняя обработка должна предоставить HTTP endpoint:

`POST {configured_endpoint}`

Endpoint должен принимать JSON и возвращать JSON. Все ответы должны быть безопасными для сохранения в журнале ProHelper после санитизации.

### 4.2. Envelope входящего сообщения

Минимальный envelope, который 1С должна принять:

```json
{
  "protocol_version": "1.0",
  "operation_id": 1001,
  "operation_key": "organization:15:base:main:scope:payments:command:export_approved:period:2026-06-07:actor:schedule",
  "correlation_id": "corr_01JZ0000000000000000000000",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:123:action:send:version:7",
  "organization_id": 15,
  "legal_entity_id": 3,
  "profile_code": "main-accounting-test",
  "base_code": "main",
  "environment": "test",
  "direction": "prohelper_to_1c",
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "123",
  "source_hash": "sha256:source-object-hash",
  "payload_hash": "sha256:payload-hash",
  "business_status": "approved",
  "sent_at": "2026-06-07T12:00:00+03:00",
  "safe_payload_preview": {
    "number": "PAY-123",
    "date": "2026-06-07",
    "amount": 150000.00,
    "currency": "RUB",
    "counterparty": "ООО Пример",
    "project": "Объект 12"
  },
  "payload": {
    "document": {
      "number": "PAY-123",
      "date": "2026-06-07",
      "amount": 150000.00,
      "currency": "RUB"
    }
  }
}
```

Обязательные поля:

| Поле | Обязательность | Правило |
| --- | --- | --- |
| `protocol_version` | да | Должна поддерживаться обработкой |
| `operation_id` | да | Идентификатор операции ProHelper |
| `operation_key` | да | Ключ групповой операции |
| `correlation_id` | да | Всегда сохранять в журнале 1С |
| `idempotency_key` | да | Всегда сохранять и проверять перед созданием/изменением данных |
| `organization_id` | да | Граница tenant |
| `scope` | да | Должен входить в `supported_scopes` |
| `entity_type`, `entity_id` | да | Идентификация объекта ProHelper |
| `source_hash` или `payload_hash` | да | Контроль актуальности и повторов |
| `payload` или `safe_payload_preview` | да | `payload` для записи, `safe_payload_preview` для журнала |

Если обязательного поля нет, 1С возвращает `validation_error` или более точный safe error code.

### 4.3. Ответ 1С на доставку

Успешный прием без проведения:

```json
{
  "accepted": true,
  "status": "accepted",
  "external_id": "1c-guid-or-ref",
  "external_code": "00001234",
  "accounting_status": "accepted",
  "correlation_id": "corr_01JZ0000000000000000000000",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:123:action:send:version:7",
  "payload_hash": "sha256:payload-hash",
  "protocol_version": "1.0",
  "connector_version": "1.0.0",
  "safe_message": "Документ принят 1С",
  "warnings": []
}
```

Успешное проведение, если scope допускает проведение:

```json
{
  "accepted": true,
  "status": "posted",
  "external_id": "1c-guid-or-ref",
  "accounting_status": "posted",
  "posted_at": "2026-06-07T12:01:05+03:00",
  "correlation_id": "corr_01JZ0000000000000000000000",
  "idempotency_key": "org:15:base:main:direction:export:scope:acts:entity:act:77:action:send:version:2",
  "payload_hash": "sha256:payload-hash",
  "safe_message": "Документ отражен в учете 1С"
}
```

Бизнес-отказ:

```json
{
  "accepted": false,
  "status": "rejected",
  "retryable": false,
  "failure_type": "business_validation",
  "safe_error_code": "validation_error",
  "safe_error_message": "Не заполнено обязательное сопоставление договора",
  "safe_next_action": "Проверьте сопоставление договора и повторите отправку после исправления",
  "correlation_id": "corr_01JZ0000000000000000000000",
  "idempotency_key": "org:15:base:main:direction:export:scope:acts:entity:act:77:action:send:version:2"
}
```

Техническая ошибка:

```json
{
  "accepted": false,
  "status": "failed",
  "retryable": true,
  "failure_type": "server_error",
  "safe_error_code": "temporary_unavailable",
  "safe_error_message": "1С временно недоступна",
  "correlation_id": "corr_01JZ0000000000000000000000"
}
```

### 4.4. Статусы доставки

1С должна возвращать один из статусов:

| Статус | Значение | Retry |
| --- | --- | --- |
| `accepted` | Сообщение принято, объект зарегистрирован или поставлен в обработку 1С | нет |
| `posted` | Документ проведен в 1С, если проведение применимо для scope | нет |
| `rejected` | Бизнес-отказ, данные нужно исправить | нет автоматического retry |
| `requires_mapping` | Нужен mapping справочника или объекта | retry только после mapping |
| `conflict` | Найден конфликт ownership, дубль или расхождение контрольных полей | retry только после ручной проверки |
| `failed` | Техническая ошибка доставки или обработки | retry допустим, если `retryable=true` |
| `duplicate` | Повторная доставка с тем же idempotency key; объект уже известен | нет, вернуть существующий `external_id` |
| `incompatible_version` | Версия протокола не поддерживается | нет |
| `missing_scope` | Scope не поддержан этой обработкой или профилем | нет |
| `unauthorized` | Ошибка авторизации | нет |

`accepted` не равно `posted`. Если 1С только приняла объект, но не провела его, нужно вернуть `accepted`, а не `posted`.

### 4.5. Idempotency

1С должна хранить `idempotency_key` в регистре или дополнительном реквизите интеграции.

Правила:

- повторная доставка с тем же `idempotency_key` и тем же `payload_hash` возвращает уже известный результат;
- повторная доставка с тем же `idempotency_key`, но другим `payload_hash` возвращает `conflict`;
- retry технической ошибки идет с тем же `idempotency_key`;
- если payload изменился после `sent`, ProHelper должен прислать новую версию и новый `idempotency_key`;
- если 1С не может хранить ключ на документе, она должна хранить отдельный регистр `ProHelperExchangeIdempotency` с ключом, ссылкой на объект 1С, статусом и hash;
- duplicate delivery не должен создавать новый документ, новый справочник или новую проводку;
- timeout перед повтором требует lookup по `idempotency_key`, `external_id` или контрольным полям.

Контрольные поля для duplicate detection:

- organization/base/environment;
- scope;
- entity_type/entity_id;
- номер и дата документа;
- сумма и валюта;
- контрагент;
- договор;
- проект или объект;
- payload_hash/source_hash.

## 5. Контракт 1С -> ProHelper

### 5.1. Общие правила

Если 1С должна отправить событие в ProHelper асинхронно, она использует тот же envelope. До появления отдельного callback endpoint в конкретной реализации 1С должна уметь вернуть финальный статус синхронным ответом на delivery request.

Событие 1С -> ProHelper используется для:

- возврата `accepted`, `posted`, `rejected` по документам;
- назначения `external_id` и accounting code;
- обновления 1С-owned реквизитов;
- сообщения о duplicate mapping;
- сообщения о payroll package accepted/rejected;
- сверочных событий reconciliation.

### 5.2. Envelope исходящего события 1С

```json
{
  "protocol_version": "1.0",
  "event_type": "1c.act.accounting_posted",
  "direction": "1c_to_prohelper",
  "organization_id": 15,
  "legal_entity_id": 3,
  "profile_code": "main-accounting-production",
  "base_code": "main",
  "environment": "production",
  "scope": "acts",
  "entity_type": "act",
  "entity_id": "77",
  "external_id": "1c-guid-or-ref",
  "idempotency_key": "org:15:base:main:direction:inbound:scope:acts:entity:act:77:action:posted:version:1c-42",
  "correlation_id": "corr_01JZ0000000000000000000000",
  "source_hash": "sha256:1c-source-hash",
  "payload_hash": "sha256:event-payload-hash",
  "business_status": "approved",
  "accounting_status": "posted",
  "occurred_at": "2026-06-07T12:10:00+03:00",
  "safe_payload_preview": {
    "external_number": "00001234",
    "external_date": "2026-06-07",
    "amount": 150000.00,
    "status": "posted"
  }
}
```

Правила:

- 1С не должна менять операционный workflow ProHelper напрямую;
- 1С возвращает accounting status, external id, accounting code и safe error summary;
- ProHelper решает, какие доменные поля можно обновить;
- событие с тем же idempotency key обрабатывается повторно безопасно;
- событие с расхождением hash или контрольных полей переводится в conflict/reconciliation review.

### 5.3. События 1С -> ProHelper

| Событие | Scope | Назначение |
| --- | --- | --- |
| `1c.project.accounting_code_assigned` | `projects` | Назначен учетный код проекта или объекта |
| `1c.counterparty.legal_requisites_updated` | `counterparties` | Обновлены 1С-owned юридические реквизиты |
| `1c.contract.accounting_card_accepted` | `contracts` | Договор принят в учетную карточку 1С |
| `1c.act.accounting_posted` | `acts` | Акт проведен или отклонен в 1С |
| `1c.payment_document.accepted` | `payment_documents` | Платежный документ принят или отклонен 1С |
| `1c.payment_fact.accounting_posted` | `payments` | Факт платежа отражен в учете |
| `1c.procurement_document.accounting_posted` | `procurement_documents` | Документ закупки принят или проведен |
| `1c.warehouse_document.accounting_posted` | `warehouse_movements` | Складской документ принят или проведен |
| `1c.material.accounting_attributes_updated` | `materials` | Обновлены учетные атрибуты номенклатуры |
| `1c.employee.payroll_ref_assigned` | `employees` | Назначена payroll/accounting reference |
| `1c.payroll_export_package.accepted` | `payroll_source` | Payroll-source package принят или отклонен |

## 6. Справочники и документы

### 6.1. Supported scopes первого контура

Минимальный набор supported_scopes для обработки:

```json
[
  "counterparties",
  "projects",
  "contracts",
  "acts",
  "payment_documents",
  "procurement_documents",
  "materials",
  "warehouses",
  "warehouse_movements",
  "inventory_acts",
  "employees",
  "payroll_source",
  "reconciliation"
]
```

Если конкретная база 1С не поддерживает часть scopes, она должна честно вернуть фактический список в metadata. ProHelper поставит профиль в `missing_scope`, если профиль требует scope, которого нет в `supported_scopes`.

### 6.2. Контрагенты

| Параметр | Правило |
| --- | --- |
| Source of truth | ProHelper по операционной карточке и связям; 1С по учетным реквизитам |
| Требуемые поля | ИНН/КПП или другой устойчивый идентификатор, имя, тип, статус, local id |
| Mapping | `organization + base + scope + local_object + external_id` |
| Ошибки | `mapping_missing`, `duplicate_mapping`, `validation_error`, `conflict` |
| Нельзя | Тихо перезаписывать ProHelper-owned контакты и workflow из 1С |

Если 1С находит несколько кандидатов, она возвращает `requires_mapping` или `duplicate_mapping` с безопасным списком кандидатов без лишних персональных или банковских данных.

### 6.3. Договоры и акты

Договор:

- ProHelper владеет lifecycle, проектной привязкой, предметом и согласованием;
- 1С владеет учетной карточкой, accounting status и учетными реквизитами;
- после `sent` критичные поля меняются только через новую версию или корректировку.

Акт:

- ProHelper владеет фактом выполнения работ и операционной приемкой;
- 1С владеет бухгалтерским отражением;
- `approved` или `signed` в ProHelper не равны `posted` в 1С;
- отклонение 1С возвращается как `rejected`, автоматический retry запрещен.

Обязательные blockers:

- нет mapping договора;
- нет mapping контрагента;
- сумма/валюта невалидны;
- номер/дата противоречат уже принятому документу;
- payload_hash не совпадает при повторной доставке.

### 6.4. Платежи

Платежная заявка и платежный документ:

- ProHelper владеет заявкой, approval workflow, календарем и приоритетом;
- банк владеет фактом исполнения;
- 1С владеет учетным отражением платежа;
- `paid` в ProHelper не равно accounting `posted` без подтверждения банка и/или 1С.

1С должна принимать утвержденные платежные документы или возвращать:

- `accepted`, если документ принят в учетный/платежный контур;
- `posted`, если документ отражен в бухгалтерском учете;
- `rejected`, если реквизиты или бизнес-правила не прошли проверку;
- `requires_mapping`, если не хватает сопоставления получателя, договора, статьи или проекта.

Нельзя показывать полный банковский счет в safe payload preview без отдельного права. Реквизиты должны маскироваться.

### 6.5. Закупки

ProHelper владеет цепочкой закупки:

- заявка;
- выбор поставщика;
- предложение;
- purchase order;
- связь с оплатой, складом и проектом.

1С владеет учетным поступлением, счетами, счетами-фактурами и проводками.

Обработка должна:

- принимать утвержденные purchase orders и documents;
- проверять mapping поставщика, договора, склада, материалов и единиц измерения;
- возвращать `requires_mapping` при отсутствии справочника;
- возвращать `posted` только если документ действительно проведен в 1С.

### 6.6. Склад

ProHelper владеет оперативным складом стройки:

- физические остатки на площадке;
- движения, резервы, выдачи, приемки;
- mobile scan;
- инвентаризация фактического наличия.

1С владеет официальным складским и стоимостным учетом, партиями, НДС и accounting stock.

Внешняя обработка должна поддержать:

- warehouse mapping;
- material mapping;
- receipt/write-off/transfer/inventory messages;
- проверку отрицательных остатков, партий, единиц измерения и склада;
- возврат `rejected`, `requires_mapping`, `conflict` или `posted`.

Операционный остаток ProHelper не является бухгалтерским остатком 1С.

### 6.7. Payroll-source

ProHelper владеет payroll-source:

- табель;
- явка;
- выработка;
- наряды;
- source rows;
- export package;
- source hash закрытого периода.

1С/ЗУП владеет:

- юридической зарплатой;
- налогами;
- взносами;
- удержаниями;
- кадровой и зарплатной отчетностью.

Внешняя обработка должна:

- принимать только закрытый и зафиксированный payroll-source package;
- сохранять source_hash;
- возвращать `accepted` или `rejected`;
- запрещать retry, если source_hash изменился после lock периода;
- не рассчитывать юридическую зарплату в ProHelper и не возвращать в ProHelper лишние персональные/налоговые детали.

## 7. Mapping и reconciliation

### 7.1. Mapping model

Каждый mapping должен быть уникален в контексте:

```text
organization_id + one_c_base_id/base_code + environment + scope + local_object_type + local_object_id
```

и связывать локальный объект с:

```text
external_object_type + external_id + external_code + external_display_name
```

Статусы mapping:

| Статус | Значение |
| --- | --- |
| `active` | Используется для обмена |
| `inactive` | Временно не используется |
| `needs_review` | Требуется ручная проверка |
| `conflict` | Найдено противоречие |
| `superseded` | Заменен новым mapping |
| `archived` | Историческая запись |

Физическое удаление mapping запрещено, если по нему есть документы, сообщения, attempts или audit events.

### 7.2. Missing mapping

Если mapping отсутствует:

- 1С возвращает `requires_mapping`;
- ProHelper блокирует сообщение до ручного сопоставления;
- автоматический retry запрещен;
- после создания mapping выполняется safe requeue с тем же idempotency key, если payload не менялся.

Safe error:

```json
{
  "status": "requires_mapping",
  "safe_error_code": "mapping_missing",
  "safe_error_message": "Не найдено сопоставление контрагента",
  "safe_next_action": "Создайте сопоставление и повторите отправку"
}
```

### 7.3. Duplicate mapping

Duplicate mapping возникает, если:

- один local object связан с несколькими external objects;
- один external object связан с несколькими local objects;
- совпадает ИНН/КПП, но отличаются названия или учетные коды;
- договор совпадает по номеру, но отличается контрагент;
- материал совпадает по названию, но отличается единица измерения;
- сотрудник совпадает по ФИО, но отличается табельный номер.

Действие:

- вернуть `conflict` или `requires_mapping`;
- не выполнять silent overwrite;
- показать safe candidates для ручной проверки;
- сохранить событие в журнале 1С и ProHelper;
- после ручного решения выполнить requeue с audit reason.

### 7.4. Reconciliation

Reconciliation нужен для сверки ProHelper vs 1С vs банк/ЭДО/ЗУП.

Сверка должна выявлять:

- документ есть в ProHelper, но не принят 1С;
- документ принят 1С, но не имеет mapping в ProHelper;
- суммы, даты или контрагент отличаются;
- платеж исполнен банком, но не отражен в 1С;
- акт проведен в 1С, но операционный документ ProHelper изменился после отправки;
- payroll package принят, но source_hash не совпадает;
- warehouse movement проведен в 1С, но оперативный склад имеет конфликт количества.

Reconciliation не исправляет данные автоматически. Она создает review queue и safe error summary.

## 8. Failure, retry и dead-letter

### 8.1. Safe error model

Ответ 1С должен содержать safe error fields:

| Поле | Описание |
| --- | --- |
| `safe_error_code` | Стабильный машинный код |
| `safe_error_message` | Человекочитаемая причина без технических деталей |
| `safe_next_action` | Что сделать оператору |
| `failure_type` | Категория для retry policy |
| `retryable` | Можно ли повторять автоматически |

Рекомендуемые safe error codes:

| Код | Категория | Retry |
| --- | --- | --- |
| `unauthorized` | authorization | нет |
| `missing_scope` | configuration | нет |
| `incompatible_version` | schema | нет |
| `mapping_missing` | mapping | после mapping |
| `duplicate_mapping` | mapping/conflict | после ручной проверки |
| `duplicate_delivery` | idempotency | нет, вернуть existing result |
| `validation_error` | business_validation | нет |
| `business_rejected` | business_validation | нет |
| `timeout` | technical | ограниченно |
| `transport_error` | technical | да |
| `temporary_unavailable` | technical | да |
| `server_error` | technical | да |
| `source_outdated` | actuality | нет |
| `payload_hash_mismatch` | conflict | нет |
| `accounting_conflict` | conflict | после ручной проверки |
| `dead_letter` | terminal | только manual requeue |

### 8.2. Retry policy

Автоматический retry разрешен только для технических ошибок:

- timeout;
- transport/network error;
- rate limit;
- временная недоступность 1С;
- HTTP 408, 429, 500, 502, 503, 504.

Автоматический retry запрещен для:

- unauthorized;
- missing_scope;
- incompatible_version;
- validation error;
- business rejection;
- requires_mapping;
- duplicate mapping;
- conflict;
- already posted;
- source hash changed;
- payroll legal rejection.

Backoff по умолчанию:

```text
1 min -> 5 min -> 15 min -> 1 hour -> 3 hours
```

Retry всегда использует тот же idempotency key, если payload актуален. Если изменились бизнес-поля, нужен новый version, новый source_hash и новый idempotency key.

### 8.3. Timeout

Timeout опасен, потому что неизвестно, создала ли 1С документ.

Перед повторной отправкой 1С-обработка или ProHelper должны проверить:

- запись в регистре idempotency;
- наличие `external_id`;
- контрольные поля;
- payload_hash/source_hash.

Если состояние нельзя подтвердить, сообщение переводится в manual review или dead-letter после лимита.

Пользовательский safe error:

```text
Ожидаем подтверждение от 1С. Повтор будет выполнен после проверки состояния документа.
```

### 8.4. Dead-letter

`dead-letter` означает, что автоматическая обработка прекращена.

Причины:

- исчерпан лимит retry;
- timeout неоднозначен после проверок;
- повторяется schema mismatch;
- profile/scope поставлен на hold;
- source_hash устарел;
- обнаружен duplicate conflict;
- требуется ручная бухгалтерская проверка.

Возврат из dead-letter разрешен только после:

- проверки актуальности source_hash;
- проверки mapping blockers;
- проверки, что документ не `posted` в 1С;
- указания причины manual requeue;
- записи audit trail;
- сохранения истории attempts.

## 9. Требования безопасности

### 9.1. Transport security

Требования:

- production обмен только по TLS/HTTPS;
- сертификат должен быть валидным для production;
- тестовые self-signed сертификаты допустимы только в test/sandbox по отдельному согласованию;
- секреты нельзя передавать в query string;
- таймауты обязательны;
- 1С должна ограничивать доступ к endpoint по сети, если инфраструктура клиента это позволяет.

### 9.2. Secrets

Нельзя:

- логировать токены, пароли, Basic credentials, private keys, client secrets;
- возвращать secrets в metadata, delivery response, safe_payload_preview или журнал;
- хранить секреты в plain text файлах поставки;
- показывать endpoint query parameters, если в них есть секреты.

Нужно:

- хранить fingerprint секрета вместо значения;
- поддержать rotation/revoke;
- фиксировать audit event без значения секрета;
- маскировать sensitive fields.

### 9.3. Audit

Аудит обязателен для:

- запуска connection check;
- приема сообщения;
- duplicate delivery;
- business rejection;
- mapping decision;
- conflict resolution;
- manual retry/requeue;
- изменения настроек обработки;
- rotation/revoke секрета;
- смены endpoint.

Audit event не должен содержать raw payload, stack trace, secrets, SQL, internal exception details.

### 9.4. Data minimization

Обработка должна принимать и хранить только данные, необходимые для конкретного scope.

Особые ограничения:

- payroll-source не должен передавать лишние персональные и налоговые данные;
- банковские счета маскируются в пользовательских preview;
- raw payload хранится только при отдельном согласовании, в защищенном support-only контуре и с ограниченным retention;
- пользовательские ошибки не должны содержать `payload`, `exception`, `SQL`, `constraint`, стек вызовов или имена внутренних классов.

## 10. Acceptance checklist для 1С-подрядчика

Подрядчик считается готовым к приемке, если пройдены сценарии ниже.

### 10.1. Happy path

- metadata endpoint возвращает `protocol_version`, `connector_version`, `supported_scopes`;
- ProHelper отправляет payment document с idempotency key;
- 1С принимает сообщение, сохраняет idempotency key, возвращает `accepted` и `external_id`;
- повтор той же доставки возвращает тот же `external_id`, дубль не создается;
- журнал 1С содержит correlation_id, operation_key, idempotency_key, scope, entity_type/entity_id и safe preview.

### 10.2. Timeout

- 1С или тестовый стенд имитирует timeout;
- ProHelper получает retryable safe error `timeout`;
- повторная попытка не создает дубль, если первый запрос фактически был принят;
- при невозможности определить состояние сообщение уходит в manual review или dead-letter.

### 10.3. Unauthorized

- запрос без токена или с неверным токеном возвращает HTTP `401` или `403`;
- ответ содержит safe error `unauthorized`;
- в ответе и журнале нет токена, пароля, endpoint secret и stack trace.

### 10.4. Missing scope

- metadata возвращает список supported_scopes без обязательного scope;
- ProHelper фиксирует `missing_scope`;
- 1С не принимает сообщения unsupported scope;
- пользователь видит безопасную причину без технического лога.

### 10.5. Incompatible version

- ProHelper запрашивает metadata;
- 1С возвращает unsupported `protocol_version` или пустой protocol;
- ProHelper получает `incompatible_version`;
- обмен документами не запускается до обновления обработки или профиля.

### 10.6. Mapping missing

- ProHelper отправляет акт без mapping договора или контрагента;
- 1С возвращает `requires_mapping` и safe error `mapping_missing`;
- автоматический retry не выполняется;
- после создания mapping повтор проходит с тем же idempotency key, если payload не изменился.

### 10.7. Duplicate delivery

- ProHelper отправляет один и тот же документ дважды с одинаковым idempotency key и payload_hash;
- 1С не создает второй документ;
- 1С возвращает `duplicate` или `accepted` с existing external_id;
- журнал содержит оба attempts, но один учетный объект.

### 10.8. Duplicate conflict

- ProHelper отправляет повтор с тем же idempotency key, но другим payload_hash или контрольными полями;
- 1С возвращает `conflict` и safe error `payload_hash_mismatch` или `duplicate_delivery_conflict`;
- retry запрещен до ручной проверки;
- raw payload не показывается пользователю.

### 10.9. Retry after failure

- 1С возвращает временную техническую ошибку `temporary_unavailable`;
- ProHelper планирует retry;
- следующая попытка использует тот же idempotency key;
- после успеха статус становится `accepted` или `posted`;
- журнал сохраняет attempts и duration.

### 10.10. Business rejection

- 1С возвращает `rejected` из-за бизнес-валидации;
- ProHelper не делает автоматический retry;
- пользователь видит safe next action;
- после исправления бизнес-данных ProHelper создает новую версию сообщения, если payload изменился.

### 10.11. Payroll-source boundary

- ProHelper отправляет закрытый payroll-source package;
- 1С возвращает `accepted` или `rejected`;
- ProHelper не получает и не рассчитывает юридическую зарплату, НДФЛ, взносы и регламентированную отчетность;
- при изменении source_hash после lock retry запрещен.

### 10.12. Security smoke

- в metadata, delivery responses, journal UI и экспортируемом отчете нет raw payload;
- no raw payload, no stack trace, no secrets выполняется для всех ошибок;
- банковские реквизиты и персональные данные маскируются;
- audit содержит fingerprint и safe context, но не secret values.

## 11. Open questions

Перед production-внедрением нужно закрыть вопросы:

1. Какая конфигурация 1С является целевой для первого production-контура: Бухгалтерия, УНФ, ERP, ЗУП, кастомная база или несколько баз.
2. Какой способ поставки выбран: `.epf`, расширение 1С, HTTP-сервис в расширении, агент на стороне клиента, файловый обмен или гибрид.
3. Какие версии платформы 1С и режимы совместимости поддерживаются.
4. Какая модель прав пользователя 1С нужна обработке: чтение справочников, создание документов, проведение, запись регистров интеграции, чтение статусов.
5. Какие scopes входят в первый релиз, а какие остаются в metadata как неподдержанные.
6. Какие документы должны получать `posted` в первом релизе, а какие только `accepted`.
7. Нужен ли отдельный endpoint lookup/status для проверки timeout по idempotency key.
8. Какой SLA нужен по платежам, актам, закупкам, складу и payroll-source.
9. Какой регламент обновления обработки: версии, обратная совместимость, rollback, окно обновления.
10. Где хранить защищенный технический payload snapshot, если он вообще нужен.
11. Какие mapping scopes допускают auto mapping, а какие требуют dual control.
12. Какие поля 1С могут обновлять ProHelper без ручной заявки, а какие всегда идут через reconciliation/review.
13. Нужна ли отдельная поддержка ЭДО/КЭП в первом релизе или это отдельный integration scope.
14. Какая политика retention применяется к журналу 1С, payload hashes, payroll-source и персональным данным.

## 12. Definition of Done

ТЗ считается реализованным 1С-подрядчиком, когда:

- обработка установлена в test/sandbox;
- metadata smoke-check проходит read-only;
- `protocol_version`, `connector_version`, `supported_scopes` возвращаются корректно;
- все поддержанные scopes явно перечислены;
- delivery endpoint принимает envelope ProHelper;
- idempotency key сохраняется и защищает от duplicate delivery;
- correlation_id проходит через журнал 1С и ответы;
- source_hash/payload_hash используются для проверки актуальности;
- статусы `accepted`, `posted`, `rejected`, `failed`, `requires_mapping`, `conflict`, `dead-letter` обработаны по правилам;
- mapping_missing, duplicate mapping и reconciliation cases уходят в ручную проверку;
- retry выполняется только для технических ошибок;
- no raw payload, no stack trace, no secrets подтверждено проверками;
- бухгалтерские проводки, налоговый учет и юридический payroll не дублируются в ProHelper;
- acceptance checklist из раздела 10 пройден и приложен к сдаче.
