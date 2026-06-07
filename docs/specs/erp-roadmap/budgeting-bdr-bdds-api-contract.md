# API-контракт бюджетирования БДР/БДДС

Задача: PHERP-80.

Дата проектирования: 2026-06-08.

Статус: спецификация будущего backend-контракта. Код и маршруты в рамках этой задачи не менялись.

## Принципы

Базовый префикс будущего Admin API: `/api/v1/admin/budgeting`.

Все ответы должны идти через `AdminResponse`, а не через прямой `response()->json()`. Контракт должен поддерживать `data`, `meta`, `summary`, бизнес-понятные сообщения и стандартные коды ошибок. В пользовательских сообщениях нельзя показывать внутренние термины вроде `payload`, `constraint`, `sql`, `exception`.

Контракт проектируется для управленческого бюджета. Он не должен создавать бухгалтерские проводки, налоговые регистры или бухгалтерское закрытие периода. Интеграция с 1С разрешена только через mapping, export-preview, exchange status и сверку, чтобы избежать prohibited accounting duplication.

Обязательные английские термины контракта: budget period, scenario, version, plan, fact, forecast, limit, closed period, source of truth.

## Общая форма ответа

```json
{
  "success": true,
  "message": "Данные бюджета получены",
  "data": {},
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 120
    },
    "filters": {}
  },
  "summary": {}
}
```

Для ошибок:

```json
{
  "success": false,
  "message": "Период закрыт. Создайте корректировку бюджета.",
  "errors": [
    {
      "field": "budget_period_id",
      "message": "Период недоступен для прямого изменения"
    }
  ]
}
```

Коды ошибок:

| Код | Когда используется |
| --- | --- |
| `400` | некорректная операция или несовместимые параметры |
| `403` | недостаточно прав |
| `404` | бюджетная сущность не найдена или недоступна пользователю |
| `409` | конфликт статуса, active version, closed period или limit |
| `422` | ошибка валидации формы, импорта или mapping |

## Справочники

### `GET /articles`

Возвращает дерево статей бюджета.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `organization_id` | integer | организация |
| `budget_kind` | string | `bdr`, `bdds`, `both` |
| `flow_direction` | string | `income`, `expense`, `inflow`, `outflow`, `neutral` |
| `is_active` | boolean | фильтр активности |
| `search` | string | поиск по коду и названию |

Response `data`:

```json
[
  {
    "id": "uuid",
    "code": "BDDDS.OUT.MATERIALS",
    "name": "Оплата материалов",
    "budget_kind": "bdds",
    "flow_direction": "outflow",
    "is_leaf": true,
    "parent_id": "uuid",
    "mappings": [
      {
        "system": "1c",
        "one_c_base_id": "main-1c",
        "integration_profile_id": "profile-main",
        "external_code": "10.01",
        "external_name": "Материалы"
      }
    ]
  }
]
```

### `POST /articles`

Создает статью бюджета.

Request:

```json
{
  "organization_id": 42,
  "parent_id": "uuid",
  "code": "BDR.EXP.SUBCONTRACT",
  "name": "Субподрядные работы",
  "budget_kind": "bdr",
  "flow_direction": "expense",
  "is_leaf": true,
  "cost_category_id": 15
}
```

Право: `budgeting.articles.manage`.

Валидации:

- код уникален в организации;
- родительская статья относится к совместимому бюджету;
- leaf-статья не может иметь дочерние строки;
- нельзя создать mapping с 1С без указания `one_c_base_id` и, если в организации несколько профилей обмена, `integration_profile_id`.

### `PUT /articles/{articleId}`

Обновляет название, активность, parent и управленческие атрибуты. При наличии исторических бюджетных строк нельзя менять `budget_kind` и `flow_direction`.

Право: `budgeting.articles.manage`.

### `POST /articles/import/preview`

Загружает XLSX/CSV справочник статей и возвращает preview без сохранения.

Request `multipart/form-data`:

| Поле | Тип | Описание |
| --- | --- | --- |
| `organization_id` | integer | организация |
| `file` | file | XLSX или CSV |
| `budget_kind` | string | ожидаемый тип бюджета |

Response `summary`:

```json
{
  "rows_total": 320,
  "rows_valid": 300,
  "rows_with_warnings": 12,
  "rows_invalid": 8
}
```

### `POST /articles/import/commit`

Сохраняет ранее проверенный импорт.

Request:

```json
{
  "import_batch_id": "uuid",
  "mode": "create_or_update"
}
```

Права: `budgeting.articles.import`, `budgeting.articles.manage`.

## ЦФО

### `GET /responsibility-centers`

Возвращает дерево ЦФО.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `organization_id` | integer | организация |
| `center_type` | string | `holding`, `organization`, `project`, `department`, `warehouse`, `contract`, `functional_area` |
| `linked_entity_type` | string | тип связанной сущности |
| `is_active` | boolean | фильтр активности |

### `POST /responsibility-centers`

Создает ЦФО.

Request:

```json
{
  "organization_id": 42,
  "parent_id": "uuid",
  "center_type": "project",
  "code": "CFO.PROJECT.1001",
  "name": "ЖК Северный, корпус 1",
  "owner_user_id": 77,
  "approver_user_id": 91,
  "linked_entity_type": "project",
  "linked_entity_id": 1001,
  "active_from": "2026-01-01",
  "active_to": null
}
```

Право: `budgeting.cfo.manage`.

## Периоды и сценарии

### `GET /periods`

Возвращает budget period.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `organization_id` | integer | организация |
| `year` | integer | финансовый или календарный год |
| `status` | string | `open`, `soft_closed`, `closed`, `reopened_for_adjustment`, `archived` |

### `POST /periods`

Создает период.

Request:

```json
{
  "organization_id": 42,
  "code": "2026",
  "name": "Бюджет 2026",
  "period_type": "year",
  "starts_at": "2026-01-01",
  "ends_at": "2026-12-31"
}
```

Право: `budgeting.periods.manage`.

### `POST /periods/{periodId}/close`

Закрывает период как closed period.

Request:

```json
{
  "closure_mode": "hard",
  "reason": "План-факт за декабрь проверен финансовым контролером"
}
```

Право: `budgeting.periods.close`.

### `POST /periods/{periodId}/reopen-adjustment`

Открывает закрытый период только для корректировки.

Request:

```json
{
  "reason": "Получен подписанный акт, относящийся к закрытому периоду",
  "expires_at": "2026-02-05T18:00:00+03:00"
}
```

Права: `budgeting.periods.reopen`, `budgeting.budgets.edit_approved`.

### `GET /scenarios`

Возвращает scenario справочник.

### `POST /scenarios`

Создает сценарий.

Request:

```json
{
  "organization_id": 42,
  "code": "base",
  "name": "Базовый сценарий",
  "scenario_type": "base",
  "is_default": true
}
```

Право: `budgeting.scenarios.manage`.

## Бюджеты и версии

### `GET /budgets`

Возвращает реестр бюджетов.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `organization_id` | integer | организация |
| `budget_kind` | string | `bdr`, `bdds`, `consolidated` |
| `period_id` | uuid | период |
| `scenario_id` | uuid | сценарий |
| `status` | string | статус версии |
| `project_id` | integer | проект |
| `responsibility_center_id` | uuid | ЦФО |
| `page`, `per_page` | integer | пагинация |

Response `summary`:

```json
{
  "active_versions": 2,
  "draft_versions": 4,
  "on_approval_versions": 1,
  "plan_total": 150000000,
  "fact_total": 127000000,
  "forecast_total": 162000000,
  "currency": "RUB"
}
```

### `POST /budgets`

Создает бюджетную оболочку и первую draft version.

Request:

```json
{
  "organization_id": 42,
  "budget_kind": "bdr",
  "budget_period_id": "uuid",
  "scenario_id": "uuid",
  "name": "БДР 2026",
  "description": "Базовый бюджет доходов и расходов"
}
```

Право: `budgeting.budgets.create`.

### `GET /budgets/{budgetId}`

Возвращает карточку бюджета, версии, summary и текущие права пользователя.

### `POST /budgets/{budgetId}/versions`

Создает новую version из пустого шаблона, активной версии или импортного файла.

Request:

```json
{
  "source_version_id": "uuid",
  "version_name": "БДР 2026, корректировка 1",
  "copy_lines": true,
  "copy_forecast": false
}
```

Право: `budgeting.budgets.edit`.

### `GET /versions/{versionId}/lines`

Возвращает строки версии с помесячными суммами.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `article_id` | uuid | статья |
| `responsibility_center_id` | uuid | ЦФО |
| `project_id` | integer | проект |
| `contract_id` | integer | договор |
| `view` | string | `tree`, `flat`, `monthly` |

### `PUT /versions/{versionId}/lines`

Заменяет набор строк draft version.

Request:

```json
{
  "lines": [
    {
      "id": "uuid",
      "budget_article_id": "uuid",
      "responsibility_center_id": "uuid",
      "project_id": 1001,
      "contract_id": 501,
      "counterparty_id": 44,
      "currency": "RUB",
      "description": "Материалы по корпусу 1",
      "amounts": [
        {
          "month": "2026-01",
          "plan": 1500000,
          "forecast": 1500000
        }
      ]
    }
  ]
}
```

Право: `budgeting.budgets.edit`.

Валидации:

- версия должна быть `draft`;
- period не должен быть `closed`;
- статья должна быть листовой;
- ЦФО должен быть активным в периоде;
- валюта должна соответствовать правилам организации;
- пользователь должен иметь доступ к выбранным проектам и ЦФО.

### `POST /versions/{versionId}/submit`

Отправляет draft version на согласование.

Право: `budgeting.budgets.submit`.

### `POST /versions/{versionId}/approve`

Согласует version.

Request:

```json
{
  "comment": "Бюджет проверен, лимиты согласованы"
}
```

Право: `budgeting.budgets.approve`.

### `POST /versions/{versionId}/reject`

Отклоняет version.

Request:

```json
{
  "comment": "Нужно уточнить расходы по субподряду"
}
```

Право: `budgeting.budgets.approve`.

### `POST /versions/{versionId}/activate`

Делает approved version активной.

Право: `budgeting.budgets.activate`.

При активации предыдущая active version той же комбинации `organization + budget period + scenario + budget_kind` получает статус `replaced`.

## Импорт бюджета

### `POST /versions/{versionId}/import/preview`

Загружает XLSX/CSV строки бюджета и возвращает preview.

Request `multipart/form-data`:

| Поле | Тип | Описание |
| --- | --- | --- |
| `file` | file | XLSX или CSV |
| `template_code` | string | код шаблона |
| `mapping_mode` | string | `by_code`, `by_name`, `manual` |

Response `summary`:

```json
{
  "rows_total": 1200,
  "rows_valid": 1175,
  "rows_with_warnings": 20,
  "rows_invalid": 5,
  "plan_total": 98000000,
  "currency": "RUB"
}
```

### `POST /versions/{versionId}/import/commit`

Применяет подтвержденный импорт к draft version.

Request:

```json
{
  "import_batch_id": "uuid",
  "mode": "replace_lines"
}
```

Право: `budgeting.import.commit`.

## Plan/fact/forecast и отчеты

### `GET /versions/{versionId}/plan-fact`

Возвращает план-факт по версии.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `period_from` | date | начало периода |
| `period_to` | date | конец периода |
| `group_by` | string | `article`, `cfo`, `project`, `contract`, `month` |
| `include_forecast` | boolean | включить forecast |

Response `data`:

```json
[
  {
    "budget_article_id": "uuid",
    "budget_article_name": "Субподрядные работы",
    "responsibility_center_id": "uuid",
    "responsibility_center_name": "ЖК Северный",
    "plan": 12000000,
    "fact": 8500000,
    "forecast": 13200000,
    "variance": -3500000,
    "variance_percent": -29.17,
    "currency": "RUB"
  }
]
```

### `GET /reports/bdr`

Возвращает отчет БДР по управленческому начислению.

### `GET /reports/bdds`

Возвращает отчет БДДС по денежному движению.

### `GET /facts`

Возвращает нормализованные fact записи.

Query:

| Поле | Тип | Описание |
| --- | --- | --- |
| `budget_kind` | string | `bdr` или `bdds` |
| `source_type` | string | тип исходного документа |
| `source_id` | integer | id исходного документа |
| `budget_article_id` | uuid | статья |
| `responsibility_center_id` | uuid | ЦФО |
| `project_id` | integer | проект |

### `GET /facts/{factId}/drill-down`

Возвращает источник факта и безопасный набор полей для перехода в UI.

Response `data`:

```json
{
  "fact_id": "uuid",
  "source_type": "payment_transaction",
  "source_id": 9912,
  "source_document_number": "PAY-2026-00091",
  "source_date": "2026-02-14",
  "amount": 450000,
  "currency": "RUB",
  "budget_article": {
    "id": "uuid",
    "name": "Оплата материалов"
  },
  "responsibility_center": {
    "id": "uuid",
    "name": "ЖК Северный"
  },
  "links": {
    "payment_document_id": 118,
    "project_id": 1001,
    "contract_id": 501,
    "counterparty_id": 44
  },
  "one_c_sync_status": "accepted",
  "accounting_status": "posted"
}
```

## Лимиты

### `POST /limits/check`

Проверяет limit перед бизнес-операцией.

Request:

```json
{
  "operation_type": "payment_document_approval",
  "operation_id": 118,
  "organization_id": 42,
  "budget_period_id": "uuid",
  "budget_article_id": "uuid",
  "responsibility_center_id": "uuid",
  "project_id": 1001,
  "amount": 450000,
  "currency": "RUB"
}
```

Response `data`:

```json
{
  "decision": "soft_block",
  "message": "Сумма превышает доступный лимит по статье на 120 000 ₽",
  "limit": {
    "id": "uuid",
    "planned_amount": 1000000,
    "used_amount": 1120000,
    "available_amount": -120000,
    "enforcement_mode": "soft_block"
  },
  "required_permission": "budgeting.limits.override"
}
```

Права:

- чтение лимитов: `budgeting.limits.view`;
- управление лимитами: `budgeting.limits.manage`;
- override: `budgeting.limits.override`.

### `POST /limits/decisions`

Фиксирует ручное решение по превышению лимита.

Request:

```json
{
  "limit_check_id": "uuid",
  "decision": "override",
  "reason": "Аварийная поставка материалов для критического этапа"
}
```

## Корректировки

### `POST /adjustments`

Создает корректировку утвержденного бюджета.

Request:

```json
{
  "budget_version_id": "uuid",
  "reason": "Перенос платежей поставщика на второй квартал",
  "lines": [
    {
      "budget_line_id": "uuid",
      "month": "2026-04",
      "delta_amount": 300000,
      "new_amount": 1800000
    }
  ]
}
```

Право: `budgeting.budgets.edit_approved`.

### `POST /adjustments/{adjustmentId}/submit`

Отправляет корректировку на согласование.

### `POST /adjustments/{adjustmentId}/approve`

Согласует корректировку.

Право: `budgeting.budgets.approve`.

### `POST /adjustments/{adjustmentId}/apply`

Применяет согласованную корректировку и создает новую version или delta в зависимости от выбранной политики.

Право: `budgeting.budgets.activate`.

## Интеграция с 1С

### `GET /1c/mappings/articles`

Показывает mapping статей бюджета с аналитиками 1С.

### `POST /1c/mappings/articles`

Создает или обновляет mapping.

Request:

```json
{
  "budget_article_id": "uuid",
  "one_c_base_id": "main-1c",
  "integration_profile_id": "profile-main",
  "external_code": "20.01",
  "external_name": "Основное производство",
  "mapping_payload": {
    "cost_account": "20",
    "cost_item": "Субподряд"
  }
}
```

Право: `budgeting.articles.map_1c`.

### `POST /1c/export-preview`

Показывает, какие утвержденные версии или корректировки будут отправлены в 1С.

### `POST /1c/export`

Создает операцию обмена для утвержденной version или adjustment.

Request:

```json
{
  "source_type": "budget_version",
  "source_id": "uuid",
  "one_c_base_id": "main-1c",
  "integration_profile_id": "profile-main",
  "export_scope": "budget_articles_and_limits"
}
```

Правила:

- экспорт разрешен только для `approved`, `active` или примененных корректировок;
- operation key должен включать организацию, `one_c_base_id`, `integration_profile_id`, source type, source id и version hash;
- повторный экспорт не должен молча перезаписывать принятые данные;
- статус обмена не должен менять бюджетный статус без явного действия пользователя.

## Права по группам endpoint

| Endpoint group | Основные права |
| --- | --- |
| periods | `budgeting.periods.view`, `budgeting.periods.manage`, `budgeting.periods.close`, `budgeting.periods.reopen` |
| scenarios | `budgeting.scenarios.view`, `budgeting.scenarios.manage` |
| budgets | `budgeting.budgets.view`, `budgeting.budgets.create`, `budgeting.budgets.edit`, `budgeting.budgets.submit`, `budgeting.budgets.approve`, `budgeting.budgets.activate`, `budgeting.budgets.edit_approved` |
| articles | `budgeting.articles.view`, `budgeting.articles.manage`, `budgeting.articles.import`, `budgeting.articles.map_1c` |
| cfo | `budgeting.cfo.view`, `budgeting.cfo.manage` |
| reports | `budgeting.plan_fact.view`, `budgeting.plan_fact.export` |
| limits | `budgeting.limits.view`, `budgeting.limits.manage`, `budgeting.limits.override` |
| imports | `budgeting.import.preview`, `budgeting.import.commit` |
| audit | `budgeting.audit.view` |
| 1C sync | `budgeting.sync.view`, `budgeting.sync.export` |

## Контрактные риски

- Нельзя отдавать UI технические ключи вместо русских подписей permissions, статусов и ошибок.
- Нужно сохранить совместимость с текущим контрактом `AdminResponse`, включая `meta`, `summary` и пагинацию.
- Нельзя смешивать fact БДР и fact БДДС в одном источнике без `budget_kind`.
- Нельзя использовать `payment_documents.paid_amount` как единственную истину по деньгам.
- Нужно явно возвращать пользователю состояние `closed period`, чтобы frontend не показывал доступные действия, которые backend отклонит.
- Нужно ограничить drill-down правами пользователя на исходный документ, проект, организацию и ЦФО.
