# PHERP-88. Backend/API план-факт бюджета

## Назначение

План-факт анализ бюджета в МОСТ строится как управленческий отчет по выбранной организации, периоду, версии бюджета и сценарию. Отчет не заменяет 1С и не формирует налоговый учет, бухгалтерские проводки, регламентированную отчетность или юридический payroll.

PHERP-88 подготавливает backend-контракт для PHERP-89, где admin UI сможет показать агрегаты и раскрыть строку до документов-источников.

## Endpoints

### `GET /api/v1/admin/budgeting/plan-fact`

Право: `budgeting.plan_fact.view`.

Возвращает агрегированный план, прогноз, факт, обязательства, отклонения и покрытие источников.

### `GET /api/v1/admin/budgeting/plan-fact/drill-down`

Право: `budgeting.plan_fact.view`.

Возвращает документы-источники для строки отчета по `drill_down_key`.

## Фильтры

| Параметр | Тип | Обязательность | Описание |
| --- | --- | --- | --- |
| `period_start` | date | да | Начало периода. |
| `period_end` | date | да | Конец периода. |
| `budget_version_uuid` | string | рекомендуется | Версия бюджета. Если не передана, backend выбирает единственную активную БДДС/консолидированную версию по сценарию и периоду. При неоднозначности возвращается ошибка выбора версии. |
| `scenario_uuid` | string | нет | Сценарий бюджета. Если передан вместе с версией, версия должна принадлежать этому сценарию. |
| `project_id` | integer | нет | Проект в текущей организации. |
| `responsibility_center_id` | string | нет | UUID или числовой id ЦФО. |
| `budget_article_id` | string | нет | UUID или числовой id бюджетной статьи. |
| `counterparty_id` | integer | нет | Контрагент текущей организации. |
| `currency` | string | нет | Трехбуквенный код валюты. |
| `group_by` | string/list | нет | Группировки: `month`, `budget_article`, `responsibility_center`, `project`, `currency`. Валюта всегда добавляется к группировке backend-ом. |
| `drill_down_key` | string | только drill-down | Ключ строки из ответа агрегатов. |
| `page` | integer | drill-down, нет | Страница источников, по умолчанию `1`. |
| `per_page` | integer | drill-down, нет | Размер страницы, максимум `500`, по умолчанию `100`. |

Организация берется из `current_organization_id` контекста admin API. Если клиент передает `organization_id`, он должен совпадать с текущей организацией.

## Источники данных

План и прогноз:

- `budget_amounts.plan_amount`;
- `budget_amounts.forecast_amount`;
- через `budget_lines` только выбранной `budget_version_id`;
- версия и сценарий не смешиваются.

Факт:

- завершенные `payment_transactions`;
- только через связанный `payment_documents`;
- учитываются только документы с `budget_article_id`, `responsibility_center_id`, `project_id/currency` и доступной бюджетной аналитикой.

Обязательства:

- активные `budget_limit_reservations` со статусом `reserved`;
- активные `payment_documents` без действующего резерва лимита, чтобы поддержать документы, где резерв еще не создан;
- платежные документы с действующим резервом не суммируются повторно.

Календарные источники:

- `payment_schedules` не суммируются отдельно;
- их покрытие отражается в `sources_coverage`;
- суммы календаря считаются через платежные документы, чтобы не задвоить обязательства.

1С:

- `one_c_base_id` и `integration_profile_id` используются только существующими маппингами бюджетных статей;
- отчет не смешивает разные базы 1С и не строит бухгалтерский учет.

## Расчет

Для каждой группы backend возвращает:

- `plan_amount`;
- `forecast_amount`;
- `actual_amount`;
- `committed_amount`;
- `variance_amount`;
- `variance_percent`;
- `risk_level`;
- `drill_down_key`.

Отклонение:

- для входящих статей: `actual_amount - plan_amount`;
- для исходящих статей: `plan_amount - actual_amount`;
- `committed_amount` не меняет само фактическое отклонение, но влияет на риск перерасхода.

Риск:

- `low`: неблагоприятного отклонения нет;
- `medium`: неблагоприятное отклонение меньше 10% плана;
- `high`: неблагоприятное отклонение от 10% до 25% плана;
- `critical`: неблагоприятное отклонение от 25% плана или есть расход без плана.

Валюты:

- суммы разных валют не складываются в общий total;
- `totals_by_currency` возвращается отдельно по каждой валюте;
- `currency` всегда входит в фактическую группировку.

## Контракт ответа агрегатов

Ответ оборачивается в `AdminResponse`.

```json
{
  "success": true,
  "message": "План-факт бюджета загружен.",
  "data": {
    "filters": {
      "organization_id": 10,
      "budget_version_uuid": "uuid",
      "scenario_uuid": "uuid",
      "project_id": 25,
      "responsibility_center_id": "uuid",
      "budget_article_id": "uuid",
      "counterparty_id": null,
      "currency": "RUB",
      "group_by": ["month", "budget_article", "responsibility_center", "project", "currency"]
    },
    "period": {
      "from": "2026-01-01",
      "to": "2026-03-31",
      "start_month": "2026-01-01",
      "end_month": "2026-03-01"
    },
    "summary": {
      "rows_count": 12,
      "currencies": ["RUB"],
      "highest_risk_level": "high",
      "has_actuals": true,
      "has_commitments": true
    },
    "totals_by_currency": [
      {
        "currency": "RUB",
        "plan_amount": 1000000,
        "forecast_amount": 950000,
        "actual_amount": 910000,
        "committed_amount": 120000,
        "variance_amount": 90000,
        "variance_percent": 9,
        "risk_level": "high",
        "rows_count": 12
      }
    ],
    "rows": [
      {
        "group": {
          "month": "2026-01",
          "budget_article": 101,
          "responsibility_center": 5,
          "project": 25,
          "currency": "RUB"
        },
        "budget_article": {
          "id": "uuid",
          "code": "OPEX",
          "name": "Операционные расходы",
          "budget_kind": "bdds",
          "flow_direction": "outflow"
        },
        "responsibility_center": {
          "id": "uuid",
          "code": "CFO-01",
          "name": "Производство",
          "center_type": "cost"
        },
        "project": {
          "id": 25,
          "name": "Проект",
          "status": "active"
        },
        "counterparty": null,
        "scenario": {
          "id": "uuid",
          "code": "base",
          "name": "Базовый",
          "scenario_type": "base"
        },
        "currency": "RUB",
        "plan_amount": 100000,
        "forecast_amount": 95000,
        "actual_amount": 91000,
        "committed_amount": 12000,
        "variance_amount": 9000,
        "variance_percent": 9,
        "risk_level": "medium",
        "drill_down_key": "..."
      }
    ],
    "groups": [
      { "key": "month", "selected": true },
      { "key": "budget_article", "selected": true },
      { "key": "responsibility_center", "selected": true },
      { "key": "project", "selected": true },
      { "key": "currency", "selected": true }
    ],
    "drill_down_available": true,
    "sources_coverage": [
      {
        "source_type": "budget_amounts",
        "available": true,
        "included_aggregate_rows": 10,
        "missing_budget_analytics_count": 0,
        "coverage_note": "План и прогноз взяты из строк выбранной версии бюджета."
      }
    ],
    "warnings": [
      "Часть платежных данных не попала в расчет, потому что в них не указаны бюджетная статья или ЦФО."
    ],
    "meta": {
      "generated_at": "2026-06-08T10:00:00+03:00",
      "drill_down_endpoint": "/api/v1/admin/budgeting/plan-fact/drill-down",
      "budget_version": {},
      "scenario": {},
      "source_of_truth": {}
    }
  }
}
```

## Контракт drill-down

```json
{
  "success": true,
  "message": "Документы план-факт анализа загружены.",
  "data": {
    "filters": {},
    "period": {},
    "group": {
      "group_by": ["month", "budget_article", "currency"],
      "dimensions": {
        "month": "2026-01",
        "budget_article": 101,
        "currency": "RUB"
      }
    },
    "summary": {
      "items_count": 2,
      "actual_amount": 91000,
      "committed_amount": 12000,
      "variance_contribution": -103000,
      "currencies": ["RUB"]
    },
    "items": [
      {
        "source_type": "payment_transaction",
        "source_id": 9001,
        "number": "BANK-123",
        "title": "BANK-123",
        "date": "2026-01-18",
        "amount": 91000,
        "currency": "RUB",
        "status": "completed",
        "route_hint": {
          "name": "admin.payments.transactions.show",
          "params": { "id": 9001 },
          "api_path": "/api/v1/admin/payments/transactions/9001"
        },
        "variance_contribution": -91000
      }
    ],
    "warnings": [],
    "meta": {
      "page": 1,
      "per_page": 100,
      "total": 2,
      "budget_version": {},
      "scenario": {}
    }
  }
}
```

## Ошибки и предупреждения

Ошибки API возвращаются через `AdminResponse::error(...)` с человекочитаемыми переводами:

- не определен контекст организации;
- выбранная организация не совпадает с текущей;
- некорректный период;
- версия бюджета не найдена или не относится к периоду;
- версия и сценарий не совпадают;
- активная версия не может быть выбрана однозначно;
- недоступная группировка;
- некорректный `drill_down_key`.

Предупреждения не блокируют расчет:

- часть платежей не имеет бюджетной статьи или ЦФО;
- календарные строки покрываются платежными документами;
- для drill-down строки нет документов-источников.
