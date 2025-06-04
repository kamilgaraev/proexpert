# API дашборда организации (остатки подписки и услуги)

## Эндпоинт
**GET** `/api/v1/landing/billing/org-dashboard`

---

## Описание
Возвращает информацию о текущем тарифе организации, остатках по лимитам и подключённых add-on'ах для отображения в дашборде.

---

## Пример ответа
```json
{
  "plan": {
    "name": "Business",
    "ends_at": "2025-09-01T12:00:00Z",
    "days_left": 12,
    "max_foremen": 10,
    "max_projects": 15,
    "max_storage_gb": 5,
    "used_foremen": 7,
    "used_projects": 12,
    "used_storage_gb": 3.2
  },
  "addons": [
    {
      "name": "White Label",
      "status": "active",
      "expires_at": null
    },
    {
      "name": "BI-аналитика",
      "status": "active",
      "expires_at": "2025-09-10T12:00:00Z"
    }
  ]
}
```

---

## Описание полей
### plan
- `name` — название тарифа
- `ends_at` — дата окончания действия подписки
- `days_left` — дней до окончания подписки
- `max_foremen` — лимит прорабов по тарифу
- `max_projects` — лимит объектов по тарифу
- `max_storage_gb` — лимит хранилища (ГБ)
- `used_foremen` — текущее количество прорабов
- `used_projects` — текущее количество объектов
- `used_storage_gb` — текущее использование хранилища (ГБ)

### addons
- `name` — название add-on'а
- `status` — статус (active, expired и т.д.)
- `expires_at` — дата окончания действия add-on'а (если есть)

---

## Пример запроса
```
GET /api/v1/landing/billing/org-dashboard
Authorization: Bearer <token>
```

**Ответ:** см. выше 