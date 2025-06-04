# API организационной монетизации (billing)

## Префикс
`/api/v1/landing/billing/`

---

## 1. Получить список тарифов
**GET** `/plans`

**Ответ:**
```
[
  {
    "id": 1,
    "name": "Start",
    "slug": "start",
    "description": "...",
    "price": 4900,
    "currency": "RUB",
    "duration_in_days": 30,
    "max_foremen": 2,
    ...
  },
  ...
]
```

---

## 2. Получить список add-on'ов и подключённых
**GET** `/addons`

**Ответ:**
```
{
  "all": [ { "id": 1, "name": "White Label", ... }, ... ],
  "connected": [ { "id": 1, "subscription_addon_id": 1, ... }, ... ]
}
```

---

## 3. Получить текущую подписку организации
**GET** `/org-subscription`

**Ответ:**
```
{
  "id": 1,
  "organization_id": 1,
  "subscription_plan_id": 2,
  "status": "active",
  "starts_at": "2025-08-01T12:00:00Z",
  "ends_at": "2025-09-01T12:00:00Z",
  ...
}
```

---

## 4. Оформить/сменить подписку
**POST** `/org-subscribe`

**Тело запроса:**
```
{
  "plan_slug": "business"
}
```
**Ответ:**
```
{
  ... // объект подписки
}
```

---

## 5. Изменить параметры подписки (апгрейд/даунгрейд)
**PATCH** `/org-subscription`

**Тело запроса:**
```
{
  "plan_slug": "profi"
}
```
**Ответ:**
```
{
  ... // объект подписки
}
```

---

## 6. Подключить add-on
**POST** `/org-addon`

**Тело запроса:**
```
{
  "addon_id": 2
}
```
**Ответ:**
```
{
  ... // объект связи add-on
}
```

---

## 7. Отключить add-on
**DELETE** `/org-addon/{id}`

**Ответ:**
```
{
  "success": true
}
```

---

## 8. Совершить одноразовую покупку
**POST** `/org-one-time-purchase`

**Тело запроса:**
```
{
  "type": "export_over_limit",
  "description": "Экспорт сверх лимита",
  "amount": 500,
  "currency": "RUB"
}
```
**Ответ:**
```
{
  ... // объект покупки
}
```

---

## 9. Получить историю одноразовых покупок
**GET** `/org-one-time-purchases`

**Ответ:**
```
[
  { "id": 1, "type": "export_over_limit", ... },
  ...
]
``` 