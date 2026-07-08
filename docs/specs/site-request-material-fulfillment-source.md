# Выбор источника обеспечения для материальных заявок

## Цель

Синхронизировать верхние действия карточки заявки, правый блок и нижний workflow через backend-контракт `action_summary`, а для материальных заявок добавить развилку до закупки: склад, закупка или смешанный вариант.

## Backend Contract

### `GET /api/v1/admin/site-requests/{id}/fulfillment-options`

Возвращает:

```json
{
  "request": {
    "id": 531,
    "material_id": 42,
    "material_name": "Кирпич облицовочный",
    "requested_quantity": 1000,
    "unit": "шт",
    "status": "approved"
  },
  "decision": null,
  "warehouses": [
    {
      "id": 3,
      "name": "Основной склад",
      "available_quantity": 600,
      "reserved_quantity": 0,
      "can_cover_full_request": false
    }
  ],
  "summary": {
    "total_available_quantity": 600,
    "missing_quantity": 400,
    "recommended_source": "mixed"
  },
  "permissions": {
    "can_use_warehouse": true,
    "can_create_purchase_request": true,
    "can_use_mixed": true
  }
}
```

### `POST /api/v1/admin/site-requests/{id}/fulfillment-decision`

Body:

```json
{
  "source": "mixed",
  "warehouse_id": 3,
  "warehouse_quantity": 600,
  "purchase_quantity": 400,
  "notes": "Закрываем доступный остаток со склада"
}
```

Правила:

- `warehouse`: требует `warehouse.manage_stock`, резервирует материал, создает `ProjectMaterialDelivery` с `source_type = warehouse`, закупку не создает.
- `purchase`: требует `procurement.purchase_requests.create`, создает `PurchaseRequest` на весь объем.
- `mixed`: требует оба права, резервирует складскую часть и создает `PurchaseRequest` только на недостающий объем.
- Повторный запрос идемпотентен: если решение уже есть в `metadata.fulfillment_decision`, новые документы не дублируются.
- При изменившемся остатке возвращается `409`.

## `action_summary`

`SiteRequestResource` возвращает:

```json
{
  "primary_action": {
    "key": "determine_fulfillment_source",
    "label": "Определить источник обеспечения",
    "href": "/site-requests/531?fulfillment=1",
    "method": "GET",
    "required_permission": "site_requests.view",
    "is_enabled": true,
    "disabled_reason": null,
    "scope": "procurement_chain",
    "priority": 100
  },
  "secondary_actions": [],
  "menu_actions": [],
  "blockers": []
}
```

Верхняя кнопка в admin UI использует `action_summary.primary_action`. Если в цепочке уже есть следующий закупочный шаг, например `open_purchase_order`, header показывает его вместо локального действия `Взять в работу`.

## Workflow

Для материальной заявки после `approved`:

1. `fulfillment_source_required`
2. `warehouse_reserved`, `warehouse_in_transit`, `project_material_accepted` для складской ветки
3. `purchase_request_created` и следующие закупочные стадии для закупочной ветки

Складские стадии не показываются в обычной закупочной цепочке без складской доставки.

## Admin UX

- Блок заявки называется `Контур обеспечения`.
- CTA в шапке и CTA в `ProcurementChainPanel` выполняются через общий executor.
- Modal `Определить источник обеспечения` показывает остатки, рекомендацию, права и disabled states.
- После сохранения решения карточка заявки и chain обновляются.

## Ограничения v1

- Экран выбора источника реализован только в admin UI.
- Mobile не получает новый экран в этой итерации.
- Новые миграции не требуются: решение хранится в `site_requests.metadata.fulfillment_decision`, документы создаются через существующие `ProjectMaterialDelivery` и `PurchaseRequest`.
