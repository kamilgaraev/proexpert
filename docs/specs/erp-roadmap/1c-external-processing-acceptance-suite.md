# Acceptance-suite для внешней обработки 1С ProHelper

Дата: 2026-06-07
Задача: PHERP-79, `[ERP-01][PHERP-20] Сформировать acceptance-suite для подрядчика 1С`
Статус документа: приемочный пакет для 1С-подрядчика, backend/admin ProHelper и приемочной команды

## 1. Назначение acceptance-suite

Документ задает приемочный набор проверок для внешней обработки 1С, расширения 1С или HTTP-сервиса 1С, который реализуется по ТЗ PHERP-78. По этому набору подрядчик 1С должен подтвердить, что обработка принимает сообщения ProHelper, возвращает безопасные статусы, защищает от дублей, поддерживает mapping/reconciliation и не нарушает accounting/tax boundaries.

Для кого:

- 1С-подрядчик - реализует обработку и прикладывает доказательства прохождения тестов.
- ProHelper backend/admin - проверяет контракт, безопасные ответы, audit/journal, retry и dead-letter поведение.
- Приемочная команда - принимает результат без устных уточнений и фиксирует отклонения.

Что проверяем:

- read-only `metadata` smoke-check;
- доставку ProHelper -> 1С;
- callbacks/events 1С -> ProHelper;
- scopes `counterparties`, `contracts`, `acts`, `payment_documents`, `procurement_documents`, `warehouse_movements`, `payroll_source`;
- `idempotency_key`, `correlation_id`, `payload_hash`, `source_hash`;
- safe error model, retry, dead-letter, mapping и reconciliation;
- безопасность: no raw payload, no stack trace, no secrets.

Границы source of truth:

- ProHelper остается construction ERP / operational source of truth.
- 1С остается бухгалтерским и налоговым source of truth.
- ProHelper не создает бухгалтерские проводки, налоговый учет, регламентированную отчетность, официальный payroll и официальный складской стоимостной учет.
- 1С не перезаписывает операционные workflow-статусы ProHelper. Учетные статусы 1С хранятся отдельно как accounting status.

Context7 не использовался: задача полностью документная и опирается на локальные specs/code/YouTrack. Актуальная внешняя документация по библиотекам, SDK, CLI или сторонним API не требуется.

## 2. Тестовый стенд и базовый контракт

### 2.1. Общие условия

Перед запуском acceptance-suite должны быть известны:

- environment: `test` или `sandbox`;
- profile: `integration_profile_id` или `profile_code`;
- база 1С: `one_c_base_id` или `base_code`;
- `metadata_path`, по умолчанию `/metadata`;
- auth mode: `bearer_token`, `basic` или `none` для изолированного стенда;
- список required scopes в ProHelper profile;
- список `supported_scopes`, который возвращает 1С;
- безопасный тестовый набор данных без настоящих секретов, банковских ключей и персональных данных сверх минимума.

### 2.2. Compatibility payload backend-а

Текущий backend-контур доставки ProHelper отправляет в 1С минимальный compatibility payload:

```json
{
  "operation_id": 1001,
  "organization_id": 15,
  "operation_key": "organization:15:base:main:scope:payment_documents:command:export_approved:period:2026-06-07:actor:schedule",
  "correlation_id": "corr_acceptance_0001",
  "direction": "prohelper_to_1c",
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "safe_payload_preview": {
    "number": "PAY-501",
    "amount": 125000,
    "currency": "RUB",
    "counterparty": "ООО Тестовый поставщик"
  }
}
```

1С-обработка должна принимать этот минимальный payload и возвращать безопасный JSON-ответ. При реализации ERP-grade контракта из PHERP-78 обработка должна дополнительно принимать `protocol_version`, `legal_entity_id`, `profile_code`, `base_code`, `environment`, `source_hash`, `payload_hash` и бизнес-поля scope.

### 2.3. Целевой ERP-grade envelope

Для production-sized приемки используется расширенный envelope:

```json
{
  "protocol_version": "1.0",
  "operation_id": 1001,
  "operation_key": "organization:15:base:main:scope:payment_documents:command:export_approved:period:2026-06-07:actor:schedule",
  "correlation_id": "corr_acceptance_0001",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "organization_id": 15,
  "legal_entity_id": 3,
  "integration_profile_id": 44,
  "one_c_base_id": 12,
  "profile_code": "main-accounting-test",
  "base_code": "main",
  "environment": "test",
  "direction": "prohelper_to_1c",
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "source_hash": "sha256:source-payment-501-v7",
  "payload_hash": "sha256:payload-payment-501-v7",
  "safe_payload_preview": {
    "number": "PAY-501",
    "date": "2026-06-07",
    "amount": 125000,
    "currency": "RUB",
    "counterparty": "ООО Тестовый поставщик"
  }
}
```

## 3. Матрица приемки

| ID теста | Сценарий | Scope | Входные данные | Ожидаемый ответ 1С | Ожидаемый статус ProHelper | Retry policy | Audit/journal expectation | Критерий Pass/Fail |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| META-01 | Happy path metadata | metadata | GET `/metadata`, валидный auth, required scopes | HTTP 200, `status=ok`, `protocol_version=1.0`, `supported_scopes` | connection check `ok` | no retry | audit `connection_check_run`, steps ok, endpoint fingerprint | Pass, если smoke read-only и все scopes подтверждены |
| META-02 | Unauthorized metadata | metadata | Нет auth или неверный auth | HTTP 401/403, safe error `unauthorized` | connection check `unauthorized` | no retry до исправления секрета | audit без Authorization и secret values | Pass, если секреты не раскрыты |
| META-03 | Missing scope | metadata | `supported_scopes` без required scope | HTTP 200 или 422, safe error `missing_scope` | connection check `missing_scope`, profile warning | no retry до настройки scope | audit содержит `missing_scopes`, не raw payload | Pass, если обмен по unsupported scope не запускается |
| META-04 | Incompatible version | metadata | `protocol_version=0.9` или пустой | HTTP 426 или 409, safe error `incompatible_version` | connection check `incompatible_version` | no retry до обновления | audit содержит protocol/connector version | Pass, если документы не отправляются |
| META-05 | Unconfigured | metadata | Пустой endpoint, нет active secret или обработка не настроена | HTTP 503/422, safe error `unconfigured` | connection check `unconfigured` или `secret_missing` | no auto retry | audit содержит safe reason | Pass, если пользователь видит понятную причину |
| META-06 | Timeout/transport error | metadata | endpoint недоступен или задержка выше timeout | HTTP 408 или transport failure | connection check `timeout`/`transport_error` | ограниченный technical retry только по правилу стенда | audit фиксирует duration and code | Pass, если нет дублей и нет raw diagnostics |
| DEL-01 | Accepted document | `payment_documents` | payment document с mapping и hash | HTTP 200, `accepted=true`, `status=accepted`, `external_id` | operation `accepted`/`delivered`, accounting status `accepted` | no retry | 1С journal содержит `correlation_id`, `idempotency_key`, `payload_hash` | Pass, если external id сохранен и дубль не создан |
| DEL-02 | Posted document | `acts` | act с разрешенным accounting posting | HTTP 200, `accepted=true`, `status=posted`, `external_id` | operation `posted`, accounting status `posted` | no retry | journal содержит `posted_at` | Pass, если ProHelper не меняет операционный статус акта |
| DEL-03 | Rejected by business rule | `procurement_documents` | документ с бизнес-ошибкой 1С | HTTP 422, `status=rejected`, safe error `business_rejected` | operation `rejected`, conflict/review if needed | no auto retry | journal содержит safe next action | Pass, если причина безопасна и без stack trace |
| DEL-04 | Duplicate delivery same hash | любой документный scope | тот же `idempotency_key` и тот же `payload_hash` | HTTP 200, `status=duplicate` или `accepted`, existing `external_id` | no new object, status unchanged/accepted | no retry | journal содержит новый attempt и existing external id | Pass, если в 1С один учетный объект |
| DEL-05 | Duplicate conflict different hash | любой документный scope | тот же `idempotency_key`, другой `payload_hash` | HTTP 409, safe error `payload_hash_mismatch` | operation `conflict`/`dead_letter` after limit | no auto retry | conflict event, no silent overwrite | Pass, если перезапись запрещена |
| DEL-06 | Source outdated | `payroll_source` или документ | `source_hash` не совпадает с lock/source | HTTP 409, safe error `source_outdated` | operation `dead_letter` or review | no retry без новой версии | journal содержит old/new source hash fingerprint | Pass, если старый source не переотправлен |
| DEL-07 | Timeout then idempotency lookup | любой документный scope | первый request timeout, затем проверка по key | lookup возвращает accepted/existing или unknown | `retry_scheduled`, затем accepted/review | limited retry same `idempotency_key` | attempts связаны одним `correlation_id` | Pass, если повтор не создает дубль |
| DEL-08 | Temporary unavailable | любой scope | 1С вернула 503 | HTTP 503, safe error `temporary_unavailable`, `retryable=true` | `retry_scheduled` | backoff 1m, 5m, 15m, 1h, 3h | next_retry_at, attempt count | Pass, если после лимита dead-letter |
| CB-01 | Accounting status accepted | `payment_documents` | callback 1С с accepted | HTTP 200, ack accepted | accounting status `accepted` | no retry | callback audit linked by `correlation_id` | Pass, если operation status не дублирует business workflow |
| CB-02 | Accounting status posted | `acts` | callback 1С с posted | HTTP 200, ack posted | accounting status `posted` | no retry | posted timeline | Pass, если posted только по ответу 1С |
| CB-03 | Rejected with safe error | любой документ | callback rejected | HTTP 200/422, safe error saved | operation `rejected` | no auto retry | safe error without raw response | Pass, если пользовательский текст безопасен |
| CB-04 | Mapping required | `counterparties`/`contracts` | callback requires mapping | HTTP 200, `requires_mapping` | operation `requires_mapping` | no retry до mapping | mapping task and audit | Pass, если после mapping возможен safe requeue |
| CB-05 | Reconciliation mismatch | любой документ | суммы/даты/контрагент отличаются | HTTP 200, mismatch registered | conflict/review queue | no retry | reconciliation event | Pass, если нет silent overwrite |
| CB-06 | Duplicate callback | любой callback | тот же callback id/status | HTTP 200, duplicate ack | no state change | no retry | duplicate attempt/audit only | Pass, если состояние не меняется повторно |
| CB-07 | Stale callback old source_hash | любой callback | old `source_hash` | HTTP 409, safe error `source_outdated` | conflict/review | no retry | conflict with source hash | Pass, если старый callback не перезаписывает новый статус |
| MAP-01 | Mapping missing | `counterparties` | нет active mapping | `requires_mapping`, safe error `mapping_missing` | `requires_mapping` | no auto retry | mapping blocker | Pass, если документ не уходит дальше |
| MAP-02 | Duplicate mapping | любой mapping scope | один local object связан с несколькими external ids | `conflict`, safe error `duplicate_mapping` | conflict queue | no retry | duplicate candidates safe list | Pass, если требуется ручное решение |
| MAP-03 | Manual mapping resolution | любой mapping scope | выбран external object и reason | accepted after requeue | message queued/accepted | manual requeue only | audit reason and actor | Pass, если история старого mapping сохранена |
| REC-01 | Reconciliation mismatch amount/date/counterparty | документы | контрольные поля расходятся | safe error `accounting_conflict` | conflict/review | no retry | comparison fields without raw payload | Pass, если поле не перезаписано |
| SEC-01 | Security smoke | все scopes | error responses, journal export, UI preview | no raw payload, no stack trace, no secrets | safe preview only | not applicable | audit without secret values | Pass, если запрещенные данные отсутствуют |

## 4. Smoke-check metadata

Endpoint 1С: `GET {configured_endpoint}/{metadata_path}`. По умолчанию `metadata_path=/metadata`.

Обязательные headers:

| Header | Значение |
| --- | --- |
| `Authorization` | Bearer или Basic credentials, кроме изолированного стенда `auth_type=none` |
| `X-ProHelper-Connection-Check` | `read-only` |
| `X-ProHelper-Integration-Profile` | ID профиля ProHelper |

### META-01: happy path metadata

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer test-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "body": null
}
```

Response HTTP 200:

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

Expected safe code: `ok`.

### META-02: unauthorized

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer invalid-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "body": null
}
```

Response HTTP 401 or 403:

```json
{
  "status": "failed",
  "safe_error_code": "unauthorized",
  "safe_error_message": "Нет доступа к обмену с 1С",
  "retryable": false
}
```

Expected safe code: `unauthorized`.

### META-03: missing_scope

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer test-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "required_scopes": [
    "payment_documents",
    "payroll_source"
  ]
}
```

Response HTTP 200 or 422:

```json
{
  "status": "failed",
  "protocol_version": "1.0",
  "connector_version": "1.0.0",
  "supported_scopes": [
    "payment_documents"
  ],
  "safe_error_code": "missing_scope",
  "safe_error_message": "Модуль 1С не поддерживает обязательный раздел обмена",
  "missing_scopes": [
    "payroll_source"
  ],
  "retryable": false
}
```

Expected safe code: `missing_scope`.

### META-04: incompatible_version

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer test-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "expected_protocol_versions": [
    "1.0"
  ]
}
```

Response HTTP 409 or 426:

```json
{
  "status": "failed",
  "protocol_version": "0.9",
  "connector_version": "0.8.3",
  "supported_scopes": [
    "payment_documents"
  ],
  "safe_error_code": "incompatible_version",
  "safe_error_message": "Версия модуля обмена 1С не поддерживается",
  "retryable": false
}
```

Expected safe code: `incompatible_version`.

### META-05: unconfigured

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer test-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "body": null
}
```

Response HTTP 422 or 503:

```json
{
  "status": "unconfigured",
  "safe_error_code": "unconfigured",
  "safe_error_message": "Обмен с 1С настроен не полностью",
  "missing_settings": [
    "base_code",
    "supported_scopes"
  ],
  "retryable": false
}
```

Expected safe code: `unconfigured`.

### META-06: timeout/transport_error

Request:

```json
{
  "method": "GET",
  "path": "/metadata",
  "headers": {
    "Authorization": "Bearer test-token",
    "X-ProHelper-Connection-Check": "read-only",
    "X-ProHelper-Integration-Profile": "44"
  },
  "timeout_seconds": 15
}
```

Response HTTP 408:

```json
{
  "status": "failed",
  "safe_error_code": "timeout",
  "safe_error_message": "Учетная система не ответила за отведенное время",
  "retryable": true
}
```

If no HTTP response is received, ProHelper records safe code `transport_error` or `timeout` depending on transport diagnostics. In both cases the user must not see endpoint secrets, Authorization header, stack trace or raw transport exception.

Expected safe code: `timeout` or `transport_error`.

## 5. Delivery ProHelper -> 1С

### DEL-01: accepted document

Request:

```json
{
  "protocol_version": "1.0",
  "operation_id": 2001,
  "operation_key": "organization:15:base:main:scope:payment_documents:command:export_approved:period:2026-06-07:actor:manual",
  "correlation_id": "corr_del_accepted_2001",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "organization_id": 15,
  "legal_entity_id": 3,
  "profile_code": "main-accounting-test",
  "base_code": "main",
  "environment": "test",
  "direction": "prohelper_to_1c",
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "source_hash": "sha256:source-payment-501-v7",
  "payload_hash": "sha256:payload-payment-501-v7",
  "safe_payload_preview": {
    "number": "PAY-501",
    "date": "2026-06-07",
    "amount": 125000,
    "currency": "RUB",
    "counterparty": "ООО Тестовый поставщик"
  }
}
```

Response HTTP 200:

```json
{
  "accepted": true,
  "status": "accepted",
  "external_id": "1c-payment-000501",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "correlation_id": "corr_del_accepted_2001",
  "payload_hash": "sha256:payload-payment-501-v7",
  "retryable": false
}
```

Expected ProHelper status: `accepted` or `delivered`, accounting status `accepted`.

### DEL-02: posted document

Request:

```json
{
  "protocol_version": "1.0",
  "operation_id": 2002,
  "correlation_id": "corr_del_posted_2002",
  "idempotency_key": "org:15:base:main:direction:export:scope:acts:entity:act:701:action:send:version:3",
  "organization_id": 15,
  "scope": "acts",
  "entity_type": "act",
  "entity_id": "701",
  "source_hash": "sha256:source-act-701-v3",
  "payload_hash": "sha256:payload-act-701-v3",
  "safe_payload_preview": {
    "number": "ACT-701",
    "date": "2026-06-07",
    "period": "2026-06",
    "amount": 320000,
    "counterparty": "ООО Тестовый заказчик"
  }
}
```

Response HTTP 200:

```json
{
  "accepted": true,
  "status": "posted",
  "external_id": "1c-act-000701",
  "accounting_status": "posted",
  "posted_at": "2026-06-07T12:10:00+03:00",
  "idempotency_key": "org:15:base:main:direction:export:scope:acts:entity:act:701:action:send:version:3",
  "correlation_id": "corr_del_posted_2002",
  "retryable": false
}
```

Expected ProHelper status: operation `posted`, accounting status `posted`; operational status акта не меняется автоматически.

### DEL-03: rejected document по бизнес-правилу

Request:

```json
{
  "protocol_version": "1.0",
  "operation_id": 2003,
  "correlation_id": "corr_del_rejected_2003",
  "idempotency_key": "org:15:base:main:direction:export:scope:procurement_documents:entity:purchase_order:801:action:send:version:2",
  "organization_id": 15,
  "scope": "procurement_documents",
  "entity_type": "purchase_order",
  "entity_id": "801",
  "source_hash": "sha256:source-po-801-v2",
  "payload_hash": "sha256:payload-po-801-v2",
  "safe_payload_preview": {
    "number": "PO-801",
    "date": "2026-06-07",
    "amount": 180000,
    "counterparty": "ООО Тестовый поставщик"
  }
}
```

Response HTTP 422:

```json
{
  "accepted": false,
  "status": "rejected",
  "safe_error_code": "business_rejected",
  "safe_error_message": "1С отклонила документ по бизнес-правилу",
  "safe_next_action": "Проверьте реквизиты поставщика и основание документа",
  "failure_type": "business_validation",
  "retryable": false,
  "correlation_id": "corr_del_rejected_2003"
}
```

Expected ProHelper status: `rejected`; automatic retry запрещен.

### DEL-04: duplicate delivery с тем же idempotency_key и payload_hash

Request:

```json
{
  "operation_id": 2004,
  "correlation_id": "corr_del_duplicate_2004",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "organization_id": 15,
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "source_hash": "sha256:source-payment-501-v7",
  "payload_hash": "sha256:payload-payment-501-v7",
  "safe_payload_preview": {
    "number": "PAY-501",
    "amount": 125000
  }
}
```

Response HTTP 200:

```json
{
  "accepted": true,
  "status": "duplicate",
  "safe_error_code": "duplicate_delivery",
  "safe_error_message": "Операция уже была получена ранее",
  "external_id": "1c-payment-000501",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "payload_hash": "sha256:payload-payment-501-v7",
  "retryable": false
}
```

Expected ProHelper status: successful duplicate acknowledgement; в 1С один учетный объект.

### DEL-05: duplicate/conflict с тем же idempotency_key, но другим payload_hash

Request:

```json
{
  "operation_id": 2005,
  "correlation_id": "corr_del_hash_conflict_2005",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:501:action:send:version:7",
  "organization_id": 15,
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "source_hash": "sha256:source-payment-501-v8",
  "payload_hash": "sha256:payload-payment-501-v8-different",
  "safe_payload_preview": {
    "number": "PAY-501",
    "amount": 127000
  }
}
```

Response HTTP 409:

```json
{
  "accepted": false,
  "status": "conflict",
  "safe_error_code": "payload_hash_mismatch",
  "safe_error_message": "Повторная доставка отличается от ранее принятой",
  "safe_next_action": "Проверьте документ и создайте новую версию обмена",
  "failure_type": "conflict",
  "retryable": false,
  "existing_external_id": "1c-payment-000501",
  "correlation_id": "corr_del_hash_conflict_2005"
}
```

Expected ProHelper status: `conflict` или manual review; silent overwrite запрещен.

### DEL-06: source_outdated

Request:

```json
{
  "operation_id": 2006,
  "correlation_id": "corr_del_source_outdated_2006",
  "idempotency_key": "org:15:base:zup:direction:export:scope:payroll_source:entity:payroll_package:2026-05:action:send:version:1",
  "organization_id": 15,
  "scope": "payroll_source",
  "entity_type": "payroll_package",
  "entity_id": "2026-05",
  "source_hash": "sha256:old-payroll-source-2026-05",
  "payload_hash": "sha256:old-payroll-payload-2026-05",
  "safe_payload_preview": {
    "period": "2026-05",
    "rows_count": 128,
    "amount_total": 845000
  }
}
```

Response HTTP 409:

```json
{
  "accepted": false,
  "status": "conflict",
  "safe_error_code": "source_outdated",
  "safe_error_message": "Исходные данные изменились после подготовки обмена",
  "safe_next_action": "Сформируйте новую версию пакета",
  "failure_type": "source_outdated",
  "retryable": false
}
```

Expected ProHelper status: `conflict`, `dead_letter` или review; retry с устаревшим source запрещен.

### DEL-07: timeout с последующей проверкой idempotency

Initial response HTTP 408:

```json
{
  "accepted": false,
  "status": "failed",
  "safe_error_code": "timeout",
  "safe_error_message": "Учетная система не ответила за отведенное время",
  "failure_type": "timeout",
  "retryable": true,
  "correlation_id": "corr_del_timeout_2007"
}
```

Status lookup request after timeout:

```json
{
  "method": "GET",
  "path": "/exchange/status",
  "query": {
    "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:502:action:send:version:1",
    "payload_hash": "sha256:payload-payment-502-v1"
  },
  "headers": {
    "Authorization": "Bearer test-token"
  }
}
```

Status lookup response HTTP 200:

```json
{
  "status": "accepted",
  "external_id": "1c-payment-000502",
  "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:502:action:send:version:1",
  "payload_hash": "sha256:payload-payment-502-v1",
  "safe_error_code": null,
  "retryable": false
}
```

Expected ProHelper status: first attempt `retry_scheduled`, final state accepted if lookup confirms existing object; if lookup unsupported or ambiguous, manual review/dead-letter after limit.

### DEL-08: temporary_unavailable с retry

Response HTTP 503:

```json
{
  "accepted": false,
  "status": "failed",
  "safe_error_code": "temporary_unavailable",
  "safe_error_message": "1С временно недоступна",
  "failure_type": "temporary_unavailable",
  "retryable": true,
  "retry_after_seconds": 300,
  "correlation_id": "corr_del_temp_unavailable_2008"
}
```

Expected ProHelper status: `retry_scheduled`, same `idempotency_key`, backoff 1 min -> 5 min -> 15 min -> 1 hour -> 3 hours, then `dead_letter`.

## 6. 1С -> ProHelper callbacks/events

Callbacks от 1С должны быть идемпотентными и должны содержать минимум: `event_id`, `event_type`, `correlation_id`, `idempotency_key`, `scope`, `entity_type`, `entity_id`, `external_id`, `source_hash`, `payload_hash` или callback hash, `accounting_status`, safe error fields when needed.

| ID | Сценарий | Request 1С -> ProHelper | Expected ProHelper response | Pass/Fail |
| --- | --- | --- | --- | --- |
| CB-01 | Accounting status accepted | `accounting_status=accepted`, existing `external_id` | HTTP 200, ack accepted | Pass, если accounting status обновлен отдельно от операционного workflow |
| CB-02 | Accounting status posted | `accounting_status=posted`, posted timestamp | HTTP 200, ack posted | Pass, если posted принят только от 1С |
| CB-03 | Rejected with safe error | `accounting_status=rejected`, safe error `business_rejected` | HTTP 200/422, rejection recorded | Pass, если safe message сохранен без raw response |
| CB-04 | Mapping required | `status=requires_mapping`, candidates safe list | HTTP 200, mapping blocker recorded | Pass, если retry не запускается до mapping |
| CB-05 | Reconciliation mismatch | `safe_error_code=accounting_conflict`, comparison fields | HTTP 200, conflict created | Pass, если нет silent overwrite |
| CB-06 | Duplicate callback | тот же `event_id` или same callback hash | HTTP 200, duplicate ack | Pass, если состояние не меняется повторно |
| CB-07 | Stale callback old source_hash | старый `source_hash` | HTTP 409, `source_outdated` | Pass, если новый accounting status не затирается |

Example accepted callback:

```json
{
  "event_id": "1c-event-accepted-0001",
  "event_type": "1c.payment_document.accepted",
  "direction": "1c_to_prohelper",
  "correlation_id": "corr_cb_accepted_3001",
  "idempotency_key": "org:15:base:main:direction:callback:scope:payment_documents:entity:payment_document:501:external:1c-payment-000501:status:accepted",
  "organization_id": 15,
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "501",
  "external_id": "1c-payment-000501",
  "source_hash": "sha256:source-payment-501-v7",
  "payload_hash": "sha256:callback-payment-501-accepted",
  "accounting_status": "accepted",
  "occurred_at": "2026-06-07T12:15:00+03:00"
}
```

Example posted callback:

```json
{
  "event_id": "1c-event-posted-0002",
  "event_type": "1c.act.accounting_posted",
  "direction": "1c_to_prohelper",
  "correlation_id": "corr_cb_posted_3002",
  "idempotency_key": "org:15:base:main:direction:callback:scope:acts:entity:act:701:external:1c-act-000701:status:posted",
  "organization_id": 15,
  "scope": "acts",
  "entity_type": "act",
  "entity_id": "701",
  "external_id": "1c-act-000701",
  "source_hash": "sha256:source-act-701-v3",
  "payload_hash": "sha256:callback-act-701-posted",
  "accounting_status": "posted",
  "posted_at": "2026-06-07T12:20:00+03:00"
}
```

Example rejected callback:

```json
{
  "event_id": "1c-event-rejected-0003",
  "event_type": "1c.procurement_document.rejected",
  "direction": "1c_to_prohelper",
  "correlation_id": "corr_cb_rejected_3003",
  "idempotency_key": "org:15:base:main:direction:callback:scope:procurement_documents:entity:purchase_order:801:external:1c-po-000801:status:rejected",
  "organization_id": 15,
  "scope": "procurement_documents",
  "entity_type": "purchase_order",
  "entity_id": "801",
  "external_id": "1c-po-000801",
  "source_hash": "sha256:source-po-801-v2",
  "payload_hash": "sha256:callback-po-801-rejected",
  "accounting_status": "rejected",
  "safe_error_code": "business_rejected",
  "safe_error_message": "Поставщик не найден в учетной базе",
  "safe_next_action": "Сопоставьте поставщика и повторите передачу"
}
```

Example reconciliation mismatch callback:

```json
{
  "event_id": "1c-event-mismatch-0004",
  "event_type": "1c.exchange.reconciliation_mismatch_detected",
  "direction": "1c_to_prohelper",
  "correlation_id": "corr_cb_mismatch_3004",
  "idempotency_key": "org:15:base:main:direction:callback:scope:payment_documents:entity:payment_document:503:external:1c-payment-000503:mismatch:amount",
  "organization_id": 15,
  "scope": "payment_documents",
  "entity_type": "payment_document",
  "entity_id": "503",
  "external_id": "1c-payment-000503",
  "source_hash": "sha256:source-payment-503-v2",
  "payload_hash": "sha256:callback-payment-503-mismatch",
  "accounting_status": "conflict",
  "safe_error_code": "accounting_conflict",
  "safe_error_message": "Сумма документа отличается от учетной системы",
  "comparison": {
    "field": "amount",
    "prohelper_value": 100000,
    "one_c_value": 99000
  }
}
```

## 7. Scopes

| Scope | Minimal required fields | Mapping dependencies | Forbidden duplication boundary | Expected 1С ownership | Expected ProHelper ownership |
| --- | --- | --- | --- | --- | --- |
| `counterparties` | local id, type, normalized name, INN/KPP or stable identifier, status, organization | organization, legal entity, existing 1С counterparty candidates | ProHelper не становится единственным владельцем юридических реквизитов для первички | учетный контрагент, юридические реквизиты, accounting code | MDM quality, операционные роли, контакты, supplier/contractor workflow |
| `contracts` | contract id, number, date, counterparty, amount/currency, project, subject, status/version | counterparty, organization, project/cost analytics | `active` в ProHelper не равен accounting posted в 1С | учетная карточка договора, external id, accounting status | lifecycle договора, workflow, проектные связи, версии |
| `acts` | act id, number, date, period, contract, amount, VAT flag if applicable, work lines summary | contract, counterparty, project, cost category | `approved`/`signed` не равны бухгалтерскому проведению | accepted/posted/rejected accounting status | выполнение работ, операционная приемка, связи с нарядами/сметой |
| `payment_documents` | payment id, date, amount, currency, payer/payee, masked bank requisites, purpose, due date, basis | counterparty, contract/act, legal entity, bank account mapping | `paid` в ProHelper не равен бухгалтерскому проведению или банковскому факту | учетное отражение платежа, payment accounting status | approval, priority, payment calendar, связи с заявками |
| `procurement_documents` | order/receipt id, supplier, date, amount, lines, project, warehouse/material summary | supplier, material, warehouse, contract if exists | confirmed/delivered не равны учетному поступлению | заказ/поступление/счет в 1С, accounting status | выбор поставщика, закупочный workflow, операционная поставка |
| `warehouse_movements` | movement id, movement type, warehouse, material, quantity, unit, date, project, source document | warehouse, material, project/cost analytics | оперативный склад не равен официальному складскому стоимостному учету | официальный складской документ, партии/стоимость, если включены | физическое движение, резервы, mobile scan, инвентаризация площадки |
| `payroll_source` | period id, package id, rows count, employee external refs, work dates/hours, source_hash, amount summary | employee/payroll ref, project/work order, legal entity | ProHelper не рассчитывает юридическую зарплату, НДФЛ, взносы и отчетность | payroll acceptance/rejection, ЗУП/1С расчет и налоги | явка, выработка, source rows, package lock and export status |

Acceptance cases по scopes:

| ID | Scope | Проверка | Expected result |
| --- | --- | --- | --- |
| SCOPE-01 | `counterparties` | Контрагент с INN/KPP сопоставляется или возвращает candidates | active mapping или `mapping_missing`/`duplicate_mapping` |
| SCOPE-02 | `contracts` | Договор без counterparty mapping не отправляется дальше | `requires_mapping`, no retry |
| SCOPE-03 | `acts` | Акт accepted/posted не меняет операционный факт выполнения работ | accounting status отдельно |
| SCOPE-04 | `payment_documents` | Банковские реквизиты маскированы в safe preview | no raw bank account in UI/journal |
| SCOPE-05 | `procurement_documents` | Item-level material mapping blocker не пересылает accepted items повторно | partial success respected |
| SCOPE-06 | `warehouse_movements` | Списание не обгоняет связанный приход внутри периода | ordering/dependency blocker |
| SCOPE-07 | `payroll_source` | Измененный source_hash блокирует retry старого пакета | `source_outdated`, no retry |

## 8. Mapping/reconciliation

| ID | Сценарий | Входные данные | Ожидаемое поведение 1С | Ожидаемое поведение ProHelper | Pass/Fail |
| --- | --- | --- | --- | --- | --- |
| MAP-01 | `mapping_missing` | Документ требует counterparty/contract/material mapping, active mapping отсутствует | `status=requires_mapping`, safe error `mapping_missing` | message `requires_mapping`, auto retry off | Pass, если документ не создан без mapping |
| MAP-02 | `duplicate_mapping` | Один local object связан с двумя external objects или наоборот | `status=conflict`, safe error `duplicate_mapping` | conflict queue, manual decision | Pass, если candidates безопасны |
| MAP-03 | Ambiguous candidate list | Несколько кандидатов похожи по ИНН/КПП/номеру договора | `requires_mapping`, safe candidates list | user selects mapping, no silent auto choice | Pass, если нет silent overwrite |
| MAP-04 | Manual mapping resolution | Пользователь выбрал external object и reason | next delivery accepted | audit reason, actor, previous mapping history | Pass, если requeue only after audit |
| REC-01 | Mismatch by amount | В 1С сумма отличается | `accounting_conflict` | reconciliation event and conflict | Pass, если сумма не перезаписана |
| REC-02 | Mismatch by date | В 1С дата отличается | `accounting_conflict` | review queue | Pass, если есть comparison fields |
| REC-03 | Mismatch by counterparty | external counterparty отличается | `accounting_conflict` или `duplicate_mapping` | conflict queue | Pass, если нужен manual resolution |
| REC-04 | Silent overwrite forbidden | 1С вернула новые critical fields | response accepted only as review signal | ProHelper не меняет owner fields автоматически | Pass, если field ownership соблюден |

Mapping response example:

```json
{
  "accepted": false,
  "status": "requires_mapping",
  "safe_error_code": "mapping_missing",
  "safe_error_message": "Не найдено сопоставление контрагента",
  "safe_next_action": "Создайте сопоставление и повторите отправку",
  "retryable": false,
  "candidates": [
    {
      "external_id": "1c-counterparty-001",
      "external_code": "000001",
      "display_name": "ООО Тестовый поставщик",
      "confidence_score": 82
    },
    {
      "external_id": "1c-counterparty-002",
      "external_code": "000002",
      "display_name": "ООО Тестовый поставщик Север",
      "confidence_score": 74
    }
  ]
}
```

## 9. Failure/retry/dead-letter

| Safe error code | Retry yes/no | Manual review yes/no | Expected next action |
| --- | --- | --- | --- |
| `unauthorized` | no | yes | Исправить auth, secret или права пользователя 1С, затем повторить check |
| `missing_scope` | no | yes | Включить scope в обработке 1С или убрать из required scopes профиля |
| `incompatible_version` | no | yes | Обновить обработку 1С или согласовать protocol version |
| `mapping_missing` | no auto, yes after mapping | yes | Создать/подтвердить mapping, затем safe requeue |
| `duplicate_mapping` | no | yes | Разобрать candidates, выбрать active mapping или закрыть дубль |
| `duplicate_delivery` | no | no, если hash совпал | Вернуть existing result, не создавать новый объект |
| `validation_error` | no | yes | Исправить данные ProHelper или mapping, создать новую версию при изменении payload |
| `business_rejected` | no | yes | Передать владельцу документа безопасную причину и next action |
| `timeout` | limited | yes after ambiguity | Проверить состояние по `idempotency_key`/external id, затем retry same key или review |
| `transport_error` | yes | yes after retry limit | Автоматический retry с backoff, затем dead-letter |
| `temporary_unavailable` | yes | yes after retry limit | Retry same `idempotency_key`, снизить нагрузку или проверить доступность 1С |
| `source_outdated` | no | yes | Сформировать новую версию source и новый `idempotency_key` |
| `payload_hash_mismatch` | no | yes | Проверить конфликт дубля, запретить silent overwrite |
| `accounting_conflict` | no | yes | Открыть reconciliation/conflict, выбрать ручное решение |
| `dead_letter` | no auto | yes | Поддержка проверяет причину, mapping/source/hash и делает manual requeue при допустимости |

Retry acceptance:

- technical retry uses the same `idempotency_key`;
- business rejection, mapping, duplicate conflict, source outdated and accounting conflict do not retry automatically;
- `posted` или `accounted` accounting status является terminal для retry;
- после лимита attempts сообщение переводится в `dead_letter`;
- manual requeue требует reason, actor, audit trail и проверки актуальности `source_hash`.

## 10. Security acceptance

Обязательные проверки для каждого smoke, delivery, callback, mapping и failure сценария:

| ID | Проверка | Pass criterion |
| --- | --- | --- |
| SEC-01 | no raw payload в UI и пользовательских журналах | Пользователь видит только `safe_payload_preview`, hashes и safe summary |
| SEC-02 | no stack trace | Ответы и user-facing journal не содержат stack trace, file path, SQL, exception details |
| SEC-03 | no secrets | Токены, пароли, Basic credentials, private keys, webhook secrets отсутствуют в ответах и журналах |
| SEC-04 | Authorization не логируется | Header `Authorization` отсутствует в exports, safe_context и screenshots |
| SEC-05 | endpoint query secrets не показываются | Endpoint display очищен от query string, показывается fingerprint или safe URL |
| SEC-06 | payload snapshot только support-only при отдельном решении | В acceptance artifacts нет full payload snapshot, только hash/ref без данных |
| SEC-07 | payroll и банковские данные минимизированы/маскированы | Payroll rows агрегированы, банковские счета masked, персональные данные минимальны |
| SEC-08 | TLS/HTTPS для production | Production endpoint использует HTTPS с валидным сертификатом |

Запрещено прикладывать к приемке:

- production токены и пароли;
- Authorization headers;
- raw payload платежей, payroll_source и банковских документов;
- stack traces, SQL, internal exception traces;
- полные банковские счета, паспортные, налоговые и payroll details;
- скриншоты с секретами, endpoint query secrets или персональными данными сверх минимума.

## 11. Test data pack

Все JSON-примеры ниже являются тестовыми и не содержат настоящих секретов.

### 11.1. Metadata response

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

### 11.2. Payment accepted

```json
{
  "request": {
    "operation_id": 4001,
    "correlation_id": "corr_pack_payment_4001",
    "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:901:action:send:version:1",
    "organization_id": 15,
    "scope": "payment_documents",
    "entity_type": "payment_document",
    "entity_id": "901",
    "source_hash": "sha256:source-payment-901-v1",
    "payload_hash": "sha256:payload-payment-901-v1",
    "safe_payload_preview": {
      "number": "PAY-901",
      "date": "2026-06-07",
      "amount": 125000,
      "currency": "RUB",
      "counterparty": "ООО Тестовый поставщик",
      "bank_account": "407028******4321"
    }
  },
  "response": {
    "accepted": true,
    "status": "accepted",
    "external_id": "1c-payment-000901",
    "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:901:action:send:version:1",
    "retryable": false
  }
}
```

### 11.3. Act posted

```json
{
  "request": {
    "operation_id": 4002,
    "correlation_id": "corr_pack_act_4002",
    "idempotency_key": "org:15:base:main:direction:export:scope:acts:entity:act:902:action:send:version:1",
    "organization_id": 15,
    "scope": "acts",
    "entity_type": "act",
    "entity_id": "902",
    "source_hash": "sha256:source-act-902-v1",
    "payload_hash": "sha256:payload-act-902-v1",
    "safe_payload_preview": {
      "number": "ACT-902",
      "date": "2026-06-07",
      "period": "2026-06",
      "amount": 240000,
      "counterparty": "ООО Тестовый заказчик"
    }
  },
  "response": {
    "accepted": true,
    "status": "posted",
    "external_id": "1c-act-000902",
    "accounting_status": "posted",
    "posted_at": "2026-06-07T12:30:00+03:00",
    "retryable": false
  }
}
```

### 11.4. Mapping missing

```json
{
  "request": {
    "operation_id": 4003,
    "correlation_id": "corr_pack_mapping_4003",
    "idempotency_key": "org:15:base:main:direction:export:scope:contracts:entity:contract:903:action:send:version:1",
    "organization_id": 15,
    "scope": "contracts",
    "entity_type": "contract",
    "entity_id": "903",
    "source_hash": "sha256:source-contract-903-v1",
    "payload_hash": "sha256:payload-contract-903-v1",
    "safe_payload_preview": {
      "number": "CNT-903",
      "date": "2026-06-01",
      "amount": 500000,
      "counterparty": "ООО Новый подрядчик"
    }
  },
  "response": {
    "accepted": false,
    "status": "requires_mapping",
    "safe_error_code": "mapping_missing",
    "safe_error_message": "Не найдено сопоставление контрагента",
    "safe_next_action": "Создайте сопоставление и повторите отправку",
    "retryable": false
  }
}
```

### 11.5. Duplicate delivery

```json
{
  "request": {
    "operation_id": 4004,
    "correlation_id": "corr_pack_duplicate_4004",
    "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:901:action:send:version:1",
    "organization_id": 15,
    "scope": "payment_documents",
    "entity_type": "payment_document",
    "entity_id": "901",
    "source_hash": "sha256:source-payment-901-v1",
    "payload_hash": "sha256:payload-payment-901-v1",
    "safe_payload_preview": {
      "number": "PAY-901",
      "amount": 125000
    }
  },
  "response": {
    "accepted": true,
    "status": "duplicate",
    "safe_error_code": "duplicate_delivery",
    "safe_error_message": "Операция уже была получена ранее",
    "external_id": "1c-payment-000901",
    "retryable": false
  }
}
```

### 11.6. Payload hash mismatch

```json
{
  "request": {
    "operation_id": 4005,
    "correlation_id": "corr_pack_hash_mismatch_4005",
    "idempotency_key": "org:15:base:main:direction:export:scope:payment_documents:entity:payment_document:901:action:send:version:1",
    "organization_id": 15,
    "scope": "payment_documents",
    "entity_type": "payment_document",
    "entity_id": "901",
    "source_hash": "sha256:source-payment-901-v2",
    "payload_hash": "sha256:payload-payment-901-v2-different",
    "safe_payload_preview": {
      "number": "PAY-901",
      "amount": 126000
    }
  },
  "response": {
    "accepted": false,
    "status": "conflict",
    "safe_error_code": "payload_hash_mismatch",
    "safe_error_message": "Повторная доставка отличается от ранее принятой",
    "safe_next_action": "Проверьте документ и создайте новую версию обмена",
    "retryable": false,
    "existing_external_id": "1c-payment-000901"
  }
}
```

### 11.7. Timeout retry

```json
{
  "request": {
    "operation_id": 4006,
    "correlation_id": "corr_pack_timeout_4006",
    "idempotency_key": "org:15:base:main:direction:export:scope:warehouse_movements:entity:warehouse_movement:904:action:send:version:1",
    "organization_id": 15,
    "scope": "warehouse_movements",
    "entity_type": "warehouse_movement",
    "entity_id": "904",
    "source_hash": "sha256:source-wh-904-v1",
    "payload_hash": "sha256:payload-wh-904-v1",
    "safe_payload_preview": {
      "number": "WH-904",
      "date": "2026-06-07",
      "movement_type": "receipt",
      "warehouse": "Склад тестового объекта"
    }
  },
  "response": {
    "accepted": false,
    "status": "failed",
    "safe_error_code": "timeout",
    "safe_error_message": "Учетная система не ответила за отведенное время",
    "failure_type": "timeout",
    "retryable": true,
    "retry_after_seconds": 300
  }
}
```

### 11.8. Payroll_source accepted

```json
{
  "request": {
    "operation_id": 4007,
    "correlation_id": "corr_pack_payroll_accepted_4007",
    "idempotency_key": "org:15:base:zup:direction:export:scope:payroll_source:entity:payroll_package:2026-05:action:send:version:1",
    "organization_id": 15,
    "scope": "payroll_source",
    "entity_type": "payroll_package",
    "entity_id": "2026-05",
    "source_hash": "sha256:source-payroll-2026-05-v1",
    "payload_hash": "sha256:payload-payroll-2026-05-v1",
    "safe_payload_preview": {
      "period": "2026-05",
      "rows_count": 128,
      "employees_count": 24,
      "amount_total": 845000
    }
  },
  "response": {
    "accepted": true,
    "status": "accepted",
    "external_id": "1c-payroll-package-2026-05",
    "accounting_status": "accepted",
    "retryable": false
  }
}
```

### 11.9. Payroll_source rejected

```json
{
  "request": {
    "operation_id": 4008,
    "correlation_id": "corr_pack_payroll_rejected_4008",
    "idempotency_key": "org:15:base:zup:direction:export:scope:payroll_source:entity:payroll_package:2026-05:action:send:version:2",
    "organization_id": 15,
    "scope": "payroll_source",
    "entity_type": "payroll_package",
    "entity_id": "2026-05",
    "source_hash": "sha256:source-payroll-2026-05-v2",
    "payload_hash": "sha256:payload-payroll-2026-05-v2",
    "safe_payload_preview": {
      "period": "2026-05",
      "rows_count": 129,
      "employees_count": 24,
      "amount_total": 851000
    }
  },
  "response": {
    "accepted": false,
    "status": "rejected",
    "safe_error_code": "business_rejected",
    "safe_error_message": "Пакет источников payroll отклонен учетной системой",
    "safe_next_action": "Проверьте сопоставления сотрудников и закрытие периода",
    "retryable": false
  }
}
```

## 12. Definition of Done для подрядчика

Подрядчик 1С считается готовым к сдаче, если выполнены пункты ниже.

### 12.1. Какие тесты должны пройти

- META-01 - META-06 по metadata smoke-check.
- DEL-01 - DEL-08 по доставке ProHelper -> 1С.
- CB-01 - CB-07 по callbacks/events 1С -> ProHelper, если callback mode входит в поставку.
- SCOPE-01 - SCOPE-07 по scope-specific acceptance.
- MAP-01 - MAP-04 и REC-01 - REC-04.
- Все safe error codes из раздела 9.
- SEC-01 - SEC-08.

### 12.2. Какие артефакты подрядчик должен приложить

- Версию обработки: `connector_version`, способ поставки, список файлов/расширений.
- Список поддержанных scopes и ограничений.
- Таблицу результатов acceptance-suite с Pass/Fail по ID тестов.
- Безопасные JSON request/response samples для каждого пройденного сценария.
- Скриншоты настроек без секретов.
- Скриншоты журнала 1С с `correlation_id`, `idempotency_key`, scope, status, safe error, external id.
- Описание retry/dead-letter поведения и лимитов.
- Описание mapping/reconciliation flow и ручных действий.
- Список известных отклонений и open questions.

### 12.3. Какие логи/скриншоты допустимы

- Safe journal screenshots без raw payload.
- Safe payload preview с маскированными банковскими и payroll данными.
- Correlation id, idempotency key, payload hash/source hash.
- Endpoint fingerprint или safe endpoint display без query secrets.
- Aggregated counters, durations, statuses, retry attempts.

### 12.4. Какие данные запрещено прикладывать

- Настоящие токены, пароли, Authorization headers, Basic credentials.
- Raw payload документов, payroll_source строк и банковских данных.
- Полные расчетные счета, персональные документы, налоговые номера сотрудников сверх минимального тестового набора.
- Stack trace, SQL, exception details, file paths платформы, внутренние дампы.
- Production identifiers клиентов, если они не маскированы и не согласованы.

### 12.5. Как фиксировать отклонения

Каждое отклонение фиксируется отдельной строкой:

| Поле | Что указывать |
| --- | --- |
| Test ID | ID из acceptance-suite |
| Expected | Ожидаемый ответ 1С, статус ProHelper, retry policy, audit expectation |
| Actual | Фактический безопасный результат |
| Impact | Блокирует production, блокирует scope, minor issue |
| Evidence | Safe screenshot/log/hash/correlation id |
| Owner | 1С-подрядчик, ProHelper backend/admin, приемочная команда |
| Next action | Исправить, уточнить open question, принять ограничение |

## 13. Open questions

Перед production-приемкой нужно закрыть:

1. Целевая конфигурация 1С: Бухгалтерия, УНФ, ERP, ЗУП, кастомная база или несколько баз.
2. Способ поставки: `.epf`, расширение, HTTP-сервис, агент, файловый обмен или гибрид.
3. Версии платформы 1С и режимы совместимости.
4. Права пользователя 1С: чтение справочников, создание документов, проведение, запись регистра интеграции, чтение статусов.
5. SLA по payments, acts, procurement, warehouse, payroll_source и metadata.
6. Scopes первого релиза и scopes, которые остаются неподдержанными.
7. Нужен ли lookup/status endpoint для timeout и idempotency verification.
8. Retention support-only payload snapshots, если полный payload snapshot вообще разрешен.
9. Нужен ли dual-control mapping для sensitive scopes: payroll_source, банковские реквизиты, контрагенты.
10. Какие документы получают `posted` в первом релизе, а какие только `accepted`.
11. Какие поля 1С может вернуть как read-only accounting attributes, а какие всегда требуют reconciliation.
12. Нужна ли поддержка ЭДО/КЭП как отдельный scope или внешний контур.

## 14. Acceptance summary для приемочной команды

Минимальный pass для передачи в To Verify:

- metadata smoke-check проходит или корректно возвращает safe error;
- delivery accepted/posted/rejected/duplicate/conflict/source_outdated/timeout/temporary_unavailable покрыты;
- callbacks accepted/posted/rejected/mapping/reconciliation/duplicate/stale покрыты или явно помечены как out of first release;
- все required scopes имеют minimal fields, mapping dependencies и ownership boundaries;
- mapping/reconciliation не допускают silent overwrite;
- retry/dead-letter policy соответствует safe error table;
- no raw payload, no stack trace, no secrets подтверждено артефактами;
- payroll_source не раскрывает персональные и юридически значимые payroll details;
- accounting/tax boundaries соблюдены: ProHelper не дублирует бухгалтерский и налоговый source of truth 1С.
