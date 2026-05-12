# 1C: базовый обмен

Бесплатный модуль ручного обмена справочниками и документами между ProHelper и 1C.

## Доступ

Админка использует обычный JWT администратора.

Для подключения 1C создается отдельный токен организации. Токен показывается один раз после создания и хранится в базе только в виде хеша.

## Разделы обмена

- `counterparties` — контрагенты
- `employees` — сотрудники
- `projects` — проекты
- `materials` — материалы
- `cost_categories` — статьи затрат
- `acts` — акты
- `payment_documents` — платежные документы
- `advance_transactions` — подотчетные операции
- `procurement_documents` — закупочные документы

## Endpoints

### Статус

`GET /api/v1/admin/one-c-exchange/status`

Ответ:

```json
{
  "success": true,
  "message": "Статус обмена с 1C загружен.",
  "data": {
    "configured": true,
    "tokens_count": 1,
    "active_tokens_count": 1,
    "last_run": null,
    "available_scopes": ["materials", "projects"],
    "manual_only": true
  }
}
```

### Токены

`GET /api/v1/admin/one-c-exchange/tokens`

`POST /api/v1/admin/one-c-exchange/tokens`

```json
{
  "label": "Основная база"
}
```

`DELETE /api/v1/admin/one-c-exchange/tokens/{tokenId}`

### Сопоставления

`GET /api/v1/admin/one-c-exchange/mappings?scope=materials`

`POST /api/v1/admin/one-c-exchange/mappings`

```json
{
  "scope": "materials",
  "external_id": "1c-material-1",
  "external_name": "Цемент М500",
  "local_type": "materials",
  "local_id": 10,
  "payload": {
    "unit": "кг"
  }
}
```

### Ручной импорт

`POST /api/v1/admin/one-c-exchange/import`

```json
{
  "scope": "materials",
  "items": []
}
```

### Ручная выгрузка

`POST /api/v1/admin/one-c-exchange/export`

```json
{
  "scope": "payment_documents",
  "filters": {}
}
```

### Журнал

`GET /api/v1/admin/one-c-exchange/history`

## Не входит в бесплатный контур

- автоматический обмен по расписанию;
- готовая внешняя обработка 1C;
- несколько баз 1C на одну организацию;
- индивидуальные правила сопоставления под конкретную конфигурацию;
- двустороннее разрешение конфликтов.
