# Интеграция складских модулей - Руководство для фронтенда

## 📋 Оглавление
1. [Обзор модулей](#обзор-модулей)
2. [Архитектура UI](#архитектура-ui)
3. [API Reference - Базовый склад](#api-reference---базовый-склад)
4. [API Reference - Продвинутый склад](#api-reference---продвинутый-склад)
5. [Примеры запросов и ответов](#примеры-запросов-и-ответов)
6. [UI/UX Руководство](#uiux-руководство)
7. [Проверка доступных модулей](#проверка-доступных-модулей)

---

## 🎯 Обзор модулей

### Базовое управление складом (`basic-warehouse`)
**Бесплатный модуль** для базового складского учёта

**Возможности:**
- ✅ Один центральный склад организации
- ✅ Управление всеми типами активов (материалы, оборудование, инструменты, мебель, расходники)
- ✅ Приход материалов от поставщиков
- ✅ Списание материалов на проекты
- ✅ Перемещение активов между складами
- ✅ Возврат активов с проектов
- ✅ Простая инвентаризация с актами
- ✅ Учёт остатков по активам
- ✅ История всех операций

### Продвинутое управление складом (`advanced-warehouse`)
**Платный модуль** (3990 ₽/мес) для профессионального складского учёта

**Дополнительные возможности:**
- ✨ До 20 складов с зонами хранения
- ✨ Адресное хранение (стеллаж-полка-ячейка)
- ✨ Штрихкоды, RFID метки, QR коды
- ✨ Партионный и серийный учёт
- ✨ Резервирование активов для проектов
- ✨ Автоматическое пополнение (min/max)
- ✨ Прогноз потребности в материалах
- ✨ Аналитика оборачиваемости
- ✨ ABC/XYZ анализ запасов
- ✨ API для интеграции с 1С

---

## 🏗️ Архитектура UI

### ⚠️ ВАЖНО: Одна страница для обоих модулей

**Название страницы:** `"Склад"`

**Маршрут:** `/warehouse` или `/sklad`

**Принцип работы:**
1. При активном только **базовом складе** → показываем базовую версию страницы
2. При активном **продвинутом складе** → показываем расширенную версию той же страницы с дополнительными функциями
3. Продвинутый склад **включает в себя** весь функционал базового склада

```javascript
// Пример логики определения функционала
const hasBasicWarehouse = activeModules.includes('basic-warehouse');
const hasAdvancedWarehouse = activeModules.includes('advanced-warehouse');

// Отображаем расширенные функции только если есть продвинутый склад
const showAdvancedFeatures = hasAdvancedWarehouse;
const showBasicFeatures = hasBasicWarehouse || hasAdvancedWarehouse;
```

### Структура страницы "Склад"

```
Страница: Склад
├── Вкладки (Tabs)
│   ├── 📦 Остатки (всегда доступно)
│   ├── 📥 Приход (всегда доступно)
│   ├── 📤 Списание (всегда доступно)
│   ├── 🔄 Перемещения (всегда доступно)
│   ├── 📋 Инвентаризация (всегда доступно)
│   ├── 🔒 Резервирование (только для advanced-warehouse)
│   ├── 📊 Аналитика (только для advanced-warehouse)
│   └── ⚙️ Настройки (всегда доступно)
│
├── Боковая панель (если advanced-warehouse)
│   ├── 🏢 Список складов (многосклад)
│   └── 📍 Зоны хранения
│
└── Контекстные действия
    ├── Базовые операции
    └── Расширенные функции (если advanced-warehouse)
```

---

## 📡 API Reference - Базовый склад

### Base URL
```
/api/v1/warehouses
```

### 1. Управление складами

#### GET `/api/v1/warehouses` - Список складов
**Описание:** Получить список всех складов организации

**Запрос:**
```http
GET /api/v1/warehouses
Authorization: Bearer {token}
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "organization_id": 123,
      "name": "Центральный склад",
      "type": "main",
      "location": "г. Москва, ул. Складская 1",
      "address": "115280, Москва, ул. Складская, д. 1",
      "contact_person_id": 45,
      "description": "Основной склад организации",
      "is_active": true,
      "settings": {},
      "created_at": "2025-01-15T10:00:00.000000Z",
      "updated_at": "2025-01-15T10:00:00.000000Z"
    }
  ]
}
```

#### POST `/api/v1/warehouses` - Создать склад
**Запрос:**
```json
{
  "name": "Новый склад",
  "type": "branch",
  "location": "г. Москва, ул. Новая 10",
  "address": "123456, Москва, ул. Новая, д. 10",
  "contact_person_id": 67,
  "description": "Филиальный склад",
  "settings": {
    "enable_auto_calculation": true
  }
}
```

**Валидация:**
- `name` - обязательно, строка, макс 255
- `type` - обязательно, один из: `main`, `branch`, `mobile`, `virtual`
- `location` - опционально, строка, макс 500
- `address` - опционально, строка
- `contact_person_id` - опционально, существующий user_id
- `description` - опционально, строка
- `settings` - опционально, объект

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 2,
    "organization_id": 123,
    "name": "Новый склад",
    "type": "branch",
    ...
  },
  "message": "Склад успешно создан"
}
```

#### GET `/api/v1/warehouses/{id}` - Детали склада
**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Центральный склад",
    "type": "main",
    "balances": [
      {
        "id": 101,
        "material_id": 50,
        "available_quantity": 250.5,
        "reserved_quantity": 0,
        "total_quantity": 250.5,
        "average_price": 1500.00,
        "total_value": 375750.00,
        "last_movement_at": "2025-01-20T15:30:00.000000Z",
        "material": {
          "id": 50,
          "name": "Цемент М500",
          "unit": "т",
          "article": "CEM-M500"
        }
      }
    ]
  }
}
```

#### PUT `/api/v1/warehouses/{id}` - Обновить склад
**Запрос:**
```json
{
  "name": "Обновлённое название",
  "is_active": true,
  "settings": {
    "low_stock_threshold": 15
  }
}
```

**Ответ:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Склад успешно обновлен"
}
```

#### DELETE `/api/v1/warehouses/{id}` - Удалить склад
**Ответ:**
```json
{
  "success": true,
  "message": "Склад успешно удален"
}
```

### 2. Остатки и движения

#### GET `/api/v1/warehouses/{id}/balances` - Остатки на складе
**Query параметры:**
- `asset_type` - фильтр по типу актива (material, equipment, tool, furniture, consumable)
- `low_stock` - булево, показать только товары с низким остатком

**Запрос:**
```http
GET /api/v1/warehouses/1/balances?asset_type=material&low_stock=true
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "material_id": 50,
      "material_name": "Цемент М500",
      "available_quantity": 5.0,
      "reserved_quantity": 0,
      "average_price": 1500.00,
      "total_value": 7500.00,
      "low_stock_alert": true
    }
  ]
}
```

#### GET `/api/v1/warehouses/{id}/movements` - История движений
**Query параметры:**
- `movement_type` - фильтр по типу: `receipt`, `write_off`, `transfer_in`, `transfer_out`
- `date_from` - дата начала (YYYY-MM-DD)
- `date_to` - дата окончания (YYYY-MM-DD)

**Запрос:**
```http
GET /api/v1/warehouses/1/movements?movement_type=receipt&date_from=2025-01-01&date_to=2025-01-31
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 501,
      "movement_type": "receipt",
      "material_id": 50,
      "material_name": "Цемент М500",
      "quantity": 100.0,
      "price": 1500.00,
      "total_value": 150000.00,
      "document_number": "ПР-00245",
      "reason": "Приход от поставщика",
      "created_at": "2025-01-15T14:20:00.000000Z",
      "user": {
        "id": 10,
        "name": "Иванов Иван"
      }
    }
  ]
}
```

### 3. Операции со складом

#### POST `/api/v1/warehouses/operations/receipt` - Приход
**Запрос:**
```json
{
  "warehouse_id": 1,
  "material_id": 50,
  "quantity": 100.5,
  "price": 1500.00,
  "project_id": 25,
  "document_number": "ПР-00245",
  "reason": "Приход от поставщика ООО Стройматериалы",
  "metadata": {
    "supplier_invoice": "СФ-12345",
    "delivery_date": "2025-01-15"
  }
}
```

**Валидация:**
- `warehouse_id` - обязательно, существующий склад
- `material_id` - обязательно, существующий материал
- `quantity` - обязательно, число >= 0.001
- `price` - обязательно, число >= 0
- `project_id` - опционально, существующий проект
- `document_number` - опционально, строка макс 100
- `reason` - опционально, строка
- `metadata` - опционально, объект

**Ответ:**
```json
{
  "success": true,
  "data": {
    "movement_id": 501,
    "balance": {
      "material_id": 50,
      "available_quantity": 350.5,
      "average_price": 1500.00
    }
  },
  "message": "Активы успешно оприходованы"
}
```

#### POST `/api/v1/warehouses/operations/write-off` - Списание
**Запрос:**
```json
{
  "warehouse_id": 1,
  "material_id": 50,
  "quantity": 25.5,
  "project_id": 25,
  "document_number": "СП-00102",
  "reason": "Списание на проект 'Строительство ТЦ Мега'",
  "metadata": {
    "approved_by": "Петров П.П."
  }
}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "movement_id": 502,
    "balance": {
      "material_id": 50,
      "available_quantity": 325.0
    }
  },
  "message": "Активы успешно списаны"
}
```

#### POST `/api/v1/warehouses/operations/transfer` - Перемещение
**Запрос:**
```json
{
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "material_id": 50,
  "quantity": 50.0,
  "document_number": "ПМ-00085",
  "reason": "Перемещение на филиальный склад",
  "metadata": {}
}
```

**Валидация:**
- `from_warehouse_id` != `to_warehouse_id`

**Ответ:**
```json
{
  "success": true,
  "data": {
    "from_movement_id": 503,
    "to_movement_id": 504,
    "from_balance": {
      "available_quantity": 275.0
    },
    "to_balance": {
      "available_quantity": 50.0
    }
  },
  "message": "Активы успешно перемещены"
}
```

### 4. Инвентаризация

#### GET `/api/v1/warehouses/inventory` - Список актов
**Query параметры:**
- `warehouse_id` - фильтр по складу
- `status` - фильтр по статусу: `draft`, `in_progress`, `completed`, `approved`

**Ответ:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 10,
        "act_number": "INV-20250115-0001",
        "warehouse_id": 1,
        "status": "completed",
        "inventory_date": "2025-01-15",
        "created_by": 10,
        "commission_members": [10, 15, 20],
        "started_at": "2025-01-15T09:00:00.000000Z",
        "completed_at": "2025-01-15T17:30:00.000000Z",
        "summary": {
          "total_items": 150,
          "items_with_discrepancy": 5,
          "total_difference_value": -2500.00
        },
        "warehouse": {
          "id": 1,
          "name": "Центральный склад"
        },
        "creator": {
          "id": 10,
          "name": "Иванов Иван"
        }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```

#### POST `/api/v1/warehouses/inventory` - Создать акт
**Запрос:**
```json
{
  "warehouse_id": 1,
  "inventory_date": "2025-01-20",
  "commission_members": [10, 15, 20],
  "notes": "Плановая инвентаризация на конец месяца"
}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 11,
    "act_number": "INV-20250120-0002",
    "status": "draft",
    "items": [
      {
        "id": 201,
        "material_id": 50,
        "expected_quantity": 275.0,
        "actual_quantity": null,
        "unit_price": 1500.00,
        "material": {
          "id": 50,
          "name": "Цемент М500",
          "unit": "т"
        }
      }
    ]
  },
  "message": "Акт инвентаризации создан"
}
```

#### GET `/api/v1/warehouses/inventory/{id}` - Детали акта
**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 11,
    "act_number": "INV-20250120-0002",
    "status": "in_progress",
    "items": [ ... ],
    "warehouse": { ... },
    "creator": { ... }
  }
}
```

#### POST `/api/v1/warehouses/inventory/{id}/start` - Начать инвентаризацию
**Ответ:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Инвентаризация начата"
}
```

#### PUT `/api/v1/warehouses/inventory/{actId}/items/{itemId}` - Обновить позицию
**Запрос:**
```json
{
  "actual_quantity": 273.5,
  "notes": "Недостача 1.5т"
}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 201,
    "expected_quantity": 275.0,
    "actual_quantity": 273.5,
    "difference_quantity": -1.5,
    "difference_value": -2250.00,
    "notes": "Недостача 1.5т"
  },
  "message": "Позиция обновлена"
}
```

#### POST `/api/v1/warehouses/inventory/{id}/complete` - Завершить инвентаризацию
**Ответ:**
```json
{
  "success": true,
  "data": {
    "status": "completed",
    "summary": {
      "total_items": 150,
      "items_with_discrepancy": 5,
      "total_difference_value": -2250.00
    }
  },
  "message": "Инвентаризация завершена"
}
```

#### POST `/api/v1/warehouses/inventory/{id}/approve` - Утвердить акт
**Описание:** Утверждает акт и применяет корректировки к остаткам

**Ответ:**
```json
{
  "success": true,
  "data": {
    "status": "approved",
    "approved_at": "2025-01-20T18:00:00.000000Z",
    "approved_by": 10
  },
  "message": "Акт утвержден, корректировки применены"
}
```

---

## 📡 API Reference - Продвинутый склад

### Base URL
```
/api/v1/advanced-warehouse
```

### ⚠️ Middleware
Все endpoints требуют:
- `auth:api` - аутентификация
- `organization.context` - контекст организации
- `module:advanced-warehouse` - проверка активации модуля

### 1. Аналитика

#### GET `/api/v1/advanced-warehouse/analytics/turnover` - Аналитика оборачиваемости
**Query параметры:**
- `date_from` - дата начала (опционально)
- `date_to` - дата окончания (опционально)
- `warehouse_id` - фильтр по складу (опционально)

**Запрос:**
```http
GET /api/v1/advanced-warehouse/analytics/turnover?date_from=2024-12-01&date_to=2025-01-31
Authorization: Bearer {token}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2024-12-01",
      "to": "2025-01-31"
    },
    "materials": [
      {
        "material_id": 50,
        "material_name": "Цемент М500",
        "turnover_ratio": 8.5,
        "average_stock": 150.0,
        "total_consumption": 1275.0,
        "days_supply": 42.35,
        "category": "fast_moving"
      }
    ],
    "summary": {
      "total_materials": 150,
      "fast_moving": 45,
      "medium_moving": 75,
      "slow_moving": 30
    }
  }
}
```

#### GET `/api/v1/advanced-warehouse/analytics/forecast` - Прогноз потребности
**Query параметры:**
- `horizon_days` - горизонт прогноза (7-365 дней, по умолчанию 90)
- `asset_ids` - массив ID материалов для прогноза (опционально)

**Запрос:**
```http
GET /api/v1/advanced-warehouse/analytics/forecast?horizon_days=60
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "forecast_period": 60,
    "generated_at": "2025-01-20T12:00:00.000000Z",
    "materials": [
      {
        "material_id": 50,
        "material_name": "Цемент М500",
        "current_stock": 275.0,
        "predicted_consumption": 450.0,
        "recommended_order_quantity": 250.0,
        "recommended_order_date": "2025-02-05",
        "stockout_risk": "medium",
        "confidence": 0.85
      }
    ]
  }
}
```

#### GET `/api/v1/advanced-warehouse/analytics/abc-xyz` - ABC/XYZ анализ
**Query параметры:**
- `date_from` - дата начала периода
- `date_to` - дата окончания периода

**Ответ:**
```json
{
  "success": true,
  "data": {
    "matrix": {
      "AX": {
        "count": 15,
        "materials": [
          {
            "material_id": 50,
            "material_name": "Цемент М500",
            "abc_category": "A",
            "xyz_category": "X",
            "value_share": 0.25,
            "demand_variability": 0.08
          }
        ]
      },
      "AY": { "count": 8, "materials": [] },
      "AZ": { "count": 2, "materials": [] },
      "BX": { "count": 12, "materials": [] },
      "BY": { "count": 20, "materials": [] },
      "BZ": { "count": 10, "materials": [] },
      "CX": { "count": 5, "materials": [] },
      "CY": { "count": 15, "materials": [] },
      "CZ": { "count": 63, "materials": [] }
    },
    "recommendations": [
      "Категория AX: жесткий контроль запасов, частые поставки малыми партиями",
      "Категория AZ: создать буферный запас из-за высокой вариативности"
    ]
  }
}
```

### 2. Резервирование

#### POST `/api/v1/advanced-warehouse/reservations` - Зарезервировать активы
**Запрос:**
```json
{
  "warehouse_id": 1,
  "material_id": 50,
  "quantity": 25.5,
  "project_id": 30,
  "expires_hours": 48,
  "reason": "Резерв для проекта 'Строительство ТЦ Мега'"
}
```

**Валидация:**
- `warehouse_id` - обязательно
- `material_id` - обязательно
- `quantity` - обязательно, >= 0.001
- `project_id` - опционально
- `expires_hours` - опционально, 1-168 (по умолчанию 24)
- `reason` - опционально

**Ответ:**
```json
{
  "success": true,
  "data": {
    "reservation_id": 101,
    "material_id": 50,
    "quantity": 25.5,
    "status": "active",
    "expires_at": "2025-01-22T12:00:00.000000Z",
    "reserved_by": {
      "id": 10,
      "name": "Иванов Иван"
    }
  },
  "message": "Активы зарезервированы"
}
```

#### GET `/api/v1/advanced-warehouse/reservations` - Список резерваций
**Query параметры:**
- `status` - фильтр по статусу: `active`, `expired`, `released`, `consumed`
- `warehouse_id` - фильтр по складу

**Ответ:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 101,
        "warehouse_id": 1,
        "material_id": 50,
        "quantity": 25.5,
        "status": "active",
        "project_id": 30,
        "reserved_by_id": 10,
        "expires_at": "2025-01-22T12:00:00.000000Z",
        "material": {
          "id": 50,
          "name": "Цемент М500"
        },
        "warehouse": {
          "id": 1,
          "name": "Центральный склад"
        },
        "project": {
          "id": 30,
          "name": "Строительство ТЦ Мега"
        },
        "reserved_by": {
          "id": 10,
          "name": "Иванов Иван"
        }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```

#### DELETE `/api/v1/advanced-warehouse/reservations/{reservationId}` - Снять резервирование
**Ответ:**
```json
{
  "success": true,
  "message": "Резервирование снято"
}
```

### 3. Автопополнение

#### POST `/api/v1/advanced-warehouse/auto-reorder/rules` - Создать правило
**Запрос:**
```json
{
  "warehouse_id": 1,
  "material_id": 50,
  "min_stock": 50.0,
  "max_stock": 300.0,
  "reorder_point": 100.0,
  "reorder_quantity": 200.0,
  "default_supplier_id": 15,
  "is_active": true,
  "notes": "Автоматический заказ при остатке ниже 100т"
}
```

**Валидация:**
- `min_stock` < `max_stock`
- `min_stock` <= `reorder_point` <= `max_stock`
- `reorder_quantity` > 0

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 25,
    "warehouse_id": 1,
    "material_id": 50,
    "min_stock": 50.0,
    "max_stock": 300.0,
    "reorder_point": 100.0,
    "reorder_quantity": 200.0,
    "is_active": true,
    "last_triggered_at": null
  },
  "message": "Правило автопополнения создано"
}
```

#### GET `/api/v1/advanced-warehouse/auto-reorder/rules` - Список правил
**Query параметры:**
- `warehouse_id` - фильтр по складу
- `active_only` - булево, показать только активные

**Ответ:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 25,
        "warehouse_id": 1,
        "material_id": 50,
        "min_stock": 50.0,
        "max_stock": 300.0,
        "reorder_point": 100.0,
        "reorder_quantity": 200.0,
        "is_active": true,
        "last_triggered_at": null,
        "material": {
          "id": 50,
          "name": "Цемент М500"
        },
        "warehouse": {
          "id": 1,
          "name": "Центральный склад"
        },
        "default_supplier": {
          "id": 15,
          "name": "ООО Стройматериалы"
        }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```

#### POST `/api/v1/advanced-warehouse/auto-reorder/check` - Проверить автопополнение
**Описание:** Проверяет все активные правила и возвращает список материалов, требующих заказа

**Ответ:**
```json
{
  "success": true,
  "data": {
    "items_to_reorder": [
      {
        "material_id": 50,
        "material_name": "Цемент М500",
        "warehouse_id": 1,
        "warehouse_name": "Центральный склад",
        "current_stock": 95.0,
        "reorder_point": 100.0,
        "reorder_quantity": 200.0,
        "default_supplier_id": 15,
        "estimated_delivery_date": "2025-01-25"
      }
    ],
    "total_items": 1,
    "checked_at": "2025-01-20T12:00:00.000000Z"
  }
}
```

### 4. Зоны хранения

#### GET `/api/v1/advanced-warehouse/warehouses/{warehouseId}/zones` - Список зон
**Query параметры:**
- `active_only` - булево, показать только активные
- `zone_type` - фильтр по типу: `storage`, `receiving`, `shipping`, `quarantine`, `returns`

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 50,
      "warehouse_id": 1,
      "name": "Зона А1",
      "code": "A1-R01-S03-C05",
      "zone_type": "storage",
      "rack_number": "R01",
      "shelf_number": "S03",
      "cell_number": "C05",
      "capacity": 1000.0,
      "max_weight": 5000.0,
      "current_utilization": 65.5,
      "storage_conditions": {
        "temperature": "room",
        "humidity": "normal"
      },
      "is_active": true,
      "notes": "Стеллаж для цемента"
    }
  ]
}
```

#### POST `/api/v1/advanced-warehouse/warehouses/{warehouseId}/zones` - Создать зону
**Запрос:**
```json
{
  "name": "Зона А2",
  "code": "A2-R01-S01-C01",
  "zone_type": "storage",
  "rack_number": "R01",
  "shelf_number": "S01",
  "cell_number": "C01",
  "capacity": 800.0,
  "max_weight": 4000.0,
  "storage_conditions": {
    "temperature": "cold",
    "humidity": "low"
  },
  "notes": "Холодильная зона"
}
```

**Валидация:**
- Код зоны уникален в пределах склада

**Ответ:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Зона хранения создана"
}
```

#### GET `/api/v1/advanced-warehouse/warehouses/{warehouseId}/zones/{id}` - Детали зоны

#### PUT `/api/v1/advanced-warehouse/warehouses/{warehouseId}/zones/{id}` - Обновить зону

#### DELETE `/api/v1/advanced-warehouse/warehouses/{warehouseId}/zones/{id}` - Удалить зону

---

## 🔍 Проверка доступных модулей

### GET `/api/v1/modules` - Получить все модули с активациями
**Использовать для определения доступных функций**

**Запрос:**
```http
GET /api/v1/modules
Authorization: Bearer {token}
```

**Ответ (фрагмент):**
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "warehouse": [
      {
        "slug": "basic-warehouse",
        "name": "Базовое управление складом",
        "description": "Базовое складское управление с учетом всех типов активов",
        "type": "feature",
        "category": "warehouse",
        "billing_model": "free",
        "is_active": true,
        "can_deactivate": false,
        "permissions": [
          "warehouse.view",
          "warehouse.manage_stock",
          "warehouse.receipts",
          "warehouse.write_offs",
          "warehouse.transfers",
          "warehouse.inventory",
          "warehouse.reports"
        ],
        "features": [
          "Управление всеми типами активов",
          "Один центральный склад организации",
          ...
        ],
        "activation": {
          "activated_at": "2025-01-15T10:00:00.000000Z",
          "expires_at": null,
          "status": "active",
          "days_until_expiration": null
        }
      },
      {
        "slug": "advanced-warehouse",
        "name": "Продвинутое управление складом",
        "description": "Продвинутое складское управление с штрихкодами, RFID, аналитикой и автоматизацией",
        "type": "feature",
        "category": "warehouse",
        "billing_model": "subscription",
        "price": 3990,
        "currency": "RUB",
        "duration_days": 30,
        "is_active": false,
        "can_deactivate": true,
        "permissions": [
          "advanced_warehouse.view",
          "advanced_warehouse.multiple_warehouses",
          "advanced_warehouse.zones",
          "advanced_warehouse.barcode",
          ...
        ],
        "activation": null
      }
    ]
  }
}
```

### Логика проверки на фронтенде

```javascript
// Получаем список модулей
const response = await api.get('/api/v1/modules');
const warehouseModules = response.data.data.warehouse || [];

// Проверяем активацию базового склада
const basicWarehouse = warehouseModules.find(m => m.slug === 'basic-warehouse');
const hasBasicWarehouse = basicWarehouse?.is_active && basicWarehouse?.activation?.status === 'active';

// Проверяем активацию продвинутого склада
const advancedWarehouse = warehouseModules.find(m => m.slug === 'advanced-warehouse');
const hasAdvancedWarehouse = advancedWarehouse?.is_active && advancedWarehouse?.activation?.status === 'active';

// Определяем доступный функционал
const features = {
  // Базовые функции
  canManageWarehouses: hasBasicWarehouse || hasAdvancedWarehouse,
  canReceipt: hasBasicWarehouse || hasAdvancedWarehouse,
  canWriteOff: hasBasicWarehouse || hasAdvancedWarehouse,
  canTransfer: hasBasicWarehouse || hasAdvancedWarehouse,
  canInventory: hasBasicWarehouse || hasAdvancedWarehouse,
  
  // Продвинутые функции
  canUseMultipleWarehouses: hasAdvancedWarehouse,
  canManageZones: hasAdvancedWarehouse,
  canReserveAssets: hasAdvancedWarehouse,
  canUseAnalytics: hasAdvancedWarehouse,
  canUseForecast: hasAdvancedWarehouse,
  canUseAutoReorder: hasAdvancedWarehouse,
  
  // Лимиты
  maxWarehouses: hasAdvancedWarehouse ? 20 : 1,
  canUseBarcodes: hasAdvancedWarehouse,
  canUseRFID: hasAdvancedWarehouse,
};
```

---

## 🎨 UI/UX Руководство

### 1. Главная страница "Склад"

#### Заголовок
```
┌─────────────────────────────────────────────────────┐
│ 📦 Склад                                    [+ Склад]│
│                                                       │
│ [Если advanced-warehouse]                            │
│ Выбор склада: [Dropdown: Центральный склад ▼]       │
└─────────────────────────────────────────────────────┘
```

#### Tabs навигация

**Всегда доступно:**
- 📦 **Остатки** - таблица с текущими остатками
- 📥 **Приход** - форма и история прихода материалов
- 📤 **Списание** - форма и история списания
- 🔄 **Перемещения** - форма и история перемещений
- 📋 **Инвентаризация** - акты инвентаризации

**Только для advanced-warehouse:**
- 🔒 **Резервирование** - управление резервами
- 📊 **Аналитика** - графики и отчёты
- 🤖 **Автопополнение** - правила и рекомендации
- 📍 **Зоны** - управление зонами хранения

### 2. Вкладка "Остатки"

#### Фильтры
```
┌─────────────────────────────────────────────────────┐
│ 🔍 Поиск по названию/артикулу                       │
│                                                       │
│ Тип актива: [Все ▼]  Низкий остаток: [✓]           │
│                                                       │
│ [Если advanced-warehouse]                            │
│ Зона: [Все зоны ▼]  ABC класс: [Все ▼]             │
└─────────────────────────────────────────────────────┘
```

#### Таблица остатков
```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Материал          │ Артикул │ Доступно │ [Резерв] │ Ед. │ Цена  │ Сумма    │
├──────────────────────────────────────────────────────────────────────────────┤
│ Цемент М500       │ CEM-M500│ 275.0    │   25.5   │  т  │ 1500  │ 412500   │
│ Арматура 12мм     │ ARM-012 │  15.0 ⚠️ │    0     │  т  │ 55000 │ 825000   │
└──────────────────────────────────────────────────────────────────────────────┘

⚠️ - низкий остаток (если включена настройка)
[Резерв] - колонка только для advanced-warehouse
```

### 3. Вкладка "Приход"

#### Форма прихода
```
┌─────────────────────────────────────────────────────┐
│ Оприходовать материалы                              │
├─────────────────────────────────────────────────────┤
│ Склад: [Центральный склад ▼]                        │
│ Материал: [Выбрать материал... ▼]                   │
│ Количество: [______] Цена за ед.: [______]          │
│ Проект (опц): [Выбрать проект... ▼]                 │
│ № документа: [ПР-00245]                             │
│ Основание: [_________________________________]      │
│                                                       │
│ [Если advanced-warehouse]                            │
│ Зона хранения: [A1-R01-S03-C05 ▼]                   │
│ Партия: [______]  Серийный №: [______]              │
│                                                       │
│                       [Отмена] [Оприходовать]       │
└─────────────────────────────────────────────────────┘
```

#### История прихода
Таблица с фильтрами по датам, материалам, проектам

### 4. Вкладка "Инвентаризация"

#### Список актов
```
┌─────────────────────────────────────────────────────────────────────┐
│ [+ Создать акт]                    Фильтр: [Все статусы ▼]         │
├─────────────────────────────────────────────────────────────────────┤
│ № INV-20250115-0001    ✅ Утверждён    15.01.2025                  │
│ Центральный склад      150 позиций, 5 расхождений                  │
│ Комиссия: Иванов И., Петров П., Сидоров С.                         │
│                                                    [Открыть]        │
├─────────────────────────────────────────────────────────────────────┤
│ № INV-20250120-0002    ⏳ В процессе   20.01.2025                  │
│ Центральный склад      150 позиций, 85 проверено                   │
│                                          [Продолжить] [Завершить]  │
└─────────────────────────────────────────────────────────────────────┘
```

#### Процесс инвентаризации (статус: in_progress)
```
┌─────────────────────────────────────────────────────────────────────┐
│ Акт инвентаризации INV-20250120-0002                               │
│ Прогресс: ████████████░░░░░░░░ 85/150 (57%)                       │
├─────────────────────────────────────────────────────────────────────┤
│ Поиск: [__________]                          Показать: [Все ▼]     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ 📦 Цемент М500                                                      │
│    Ожидается: 275.0 т     Факт: [273.5__] т    ❌ Расх: -1.5 т     │
│    Примечание: [Недостача 1.5т________________]          [Сохранить]│
│                                                                      │
│ 📦 Арматура 12мм                                                    │
│    Ожидается: 15.0 т      Факт: [_____] т                 [Ввести] │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│                                       [Сохранить] [Завершить акт]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 5. Вкладка "Резервирование" (только advanced-warehouse)

```
┌─────────────────────────────────────────────────────────────────────┐
│ [+ Зарезервировать]             Фильтр: [Активные ▼] [Склад ▼]    │
├─────────────────────────────────────────────────────────────────────┤
│ 🔒 Цемент М500 - 25.5 т                                  АКТИВНО   │
│    Склад: Центральный     Проект: Строительство ТЦ Мега           │
│    Истекает: 22.01.2025 12:00 (через 1д 18ч)                      │
│    Зарезервировал: Иванов И.                    [Снять резерв]    │
├─────────────────────────────────────────────────────────────────────┤
│ 🔒 Арматура 12мм - 5.0 т                            ⚠️ ИСТЕКАЕТ    │
│    Склад: Центральный     Проект: Реконструкция БЦ                │
│    Истекает: 20.01.2025 18:00 (через 2ч)                          │
│    Зарезервировал: Петров П.           [Продлить] [Снять резерв]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 6. Вкладка "Аналитика" (только advanced-warehouse)

#### Подвкладки аналитики
- **Оборачиваемость** - графики и таблица
- **Прогноз потребности** - временные ряды
- **ABC/XYZ анализ** - матрица 3×3

#### Пример: ABC/XYZ матрица
```
┌─────────────────────────────────────────────────────────────────────┐
│ ABC/XYZ Анализ запасов                        Период: [Последний   │
│                                                       квартал ▼]    │
├─────────────────────────────────────────────────────────────────────┤
│           │ X (стабильный) │ Y (умеренный) │ Z (нестабильный)     │
├───────────┼─────────────────┼───────────────┼──────────────────────┤
│ A (80%)   │  15 товаров ✓  │  8 товаров ⚠️ │  2 товара ❌         │
│           │ Жесткий контроль│ Плановые      │ Буферный запас      │
├───────────┼─────────────────┼───────────────┼──────────────────────┤
│ B (15%)   │  12 товаров    │  20 товаров   │  10 товаров          │
│           │ Периодический   │ Стандартный   │ Запас на макс        │
├───────────┼─────────────────┼───────────────┼──────────────────────┤
│ C (5%)    │   5 товаров    │  15 товаров   │  63 товара           │
│           │ По требованию   │ Минимальный   │ Разовые закупки      │
└─────────────────────────────────────────────────────────────────────┘

[Экспорт в Excel] [Экспорт в PDF]
```

### 7. Вкладка "Автопополнение" (только advanced-warehouse)

```
┌─────────────────────────────────────────────────────────────────────┐
│ [+ Добавить правило]    [🔍 Проверить сейчас]    [Все склады ▼]    │
├─────────────────────────────────────────────────────────────────────┤
│ 🟢 Цемент М500 (Центральный склад)                        АКТИВНО  │
│    Текущий остаток: 95.0 т    Точка заказа: 100.0 т    ⚠️ ЗАКАЗАТЬ│
│    Min: 50т  Max: 300т  Количество заказа: 200т                    │
│    Поставщик: ООО Стройматериалы                                   │
│                                            [Редактировать] [✓ Вкл] │
├─────────────────────────────────────────────────────────────────────┤
│ 🟢 Арматура 12мм (Центральный склад)                      АКТИВНО  │
│    Текущий остаток: 15.0 т    Точка заказа: 20.0 т       ✅ ОК     │
│    Min: 10т  Max: 50т  Количество заказа: 30т                      │
│                                            [Редактировать] [✓ Вкл] │
└─────────────────────────────────────────────────────────────────────┘

⚠️ ЗАКАЗАТЬ - необходимо пополнение
✅ ОК - остаток в норме
```

### 8. Вкладка "Зоны" (только advanced-warehouse)

```
┌─────────────────────────────────────────────────────────────────────┐
│ Зоны хранения: Центральный склад          [+ Добавить зону]        │
├─────────────────────────────────────────────────────────────────────┤
│ Тип: [Все типы ▼]                                                  │
├─────────────────────────────────────────────────────────────────────┤
│ 📦 Зона А1 (A1-R01-S03-C05)                      Хранение  65.5%   │
│    Стеллаж R01 → Полка S03 → Ячейка C05                           │
│    Вместимость: 655кг / 1000кг    Макс вес: 5000кг                 │
│    Условия: Комнатная температура, обычная влажность               │
│                                              [Редактировать] [📍]  │
├─────────────────────────────────────────────────────────────────────┤
│ ❄️ Зона А2 (A2-R01-S01-C01)                     Хранение  45.2%   │
│    Стеллаж R01 → Полка S01 → Ячейка C01                           │
│    Вместимость: 361кг / 800кг     Макс вес: 4000кг                 │
│    Условия: Холодильная, низкая влажность                          │
│                                              [Редактировать] [📍]  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 💡 Рекомендации по UX

### 1. Условное отображение функционала

```javascript
// Пример компонента React
function WarehousePage() {
  const { hasBasicWarehouse, hasAdvancedWarehouse } = useWarehouseModules();
  
  return (
    <div className="warehouse-page">
      <PageHeader title="Склад" />
      
      {/* Dropdown выбора склада - только для advanced */}
      {hasAdvancedWarehouse && (
        <WarehouseSelector />
      )}
      
      <Tabs>
        {/* Базовые вкладки - всегда */}
        <Tab label="Остатки"><StockTab /></Tab>
        <Tab label="Приход"><ReceiptTab /></Tab>
        <Tab label="Списание"><WriteOffTab /></Tab>
        <Tab label="Перемещения"><TransferTab /></Tab>
        <Tab label="Инвентаризация"><InventoryTab /></Tab>
        
        {/* Продвинутые вкладки - условно */}
        {hasAdvancedWarehouse && (
          <>
            <Tab label="Резервирование"><ReservationTab /></Tab>
            <Tab label="Аналитика"><AnalyticsTab /></Tab>
            <Tab label="Автопополнение"><AutoReorderTab /></Tab>
            <Tab label="Зоны"><ZonesTab /></Tab>
          </>
        )}
      </Tabs>
    </div>
  );
}
```

### 2. Индикаторы статуса

- **Низкий остаток:** ⚠️ желтый значок
- **Нулевой остаток:** ❌ красный значок
- **Резерв активен:** 🔒 зелёный
- **Резерв истекает:** ⚠️ жёлтый
- **Резерв истёк:** ❌ красный
- **Акт утверждён:** ✅ зелёная галочка
- **В процессе:** ⏳ часы

### 3. Уведомления и подсказки

Когда пользователь пытается использовать функцию продвинутого склада без активации модуля:

```javascript
// Toast уведомление
showUpgradePrompt({
  title: 'Требуется Продвинутый склад',
  message: 'Эта функция доступна только в продвинутом модуле склада',
  features: [
    'Аналитика оборачиваемости',
    'Прогноз потребности',
    'ABC/XYZ анализ',
    'Резервирование активов'
  ],
  price: '3990 ₽/мес',
  actions: [
    { label: 'Узнать больше', onClick: () => showModuleDetails('advanced-warehouse') },
    { label: 'Активировать', onClick: () => activateModule('advanced-warehouse') }
  ]
});
```

### 4. Прогрессивное раскрытие

Не показывайте все функции сразу. Используйте:
- **Закладки (Tabs)** для группировки
- **Accordion** для дополнительных настроек
- **Modals/Sidepanels** для форм создания/редактирования
- **Tooltips** для объяснения сложных полей

### 5. Быстрые действия

Добавьте контекстные меню в таблицы:

```
Цемент М500  275.0т  1500₽  [⋮]
                            ├─ 📥 Приход
                            ├─ 📤 Списать
                            ├─ 🔄 Переместить
                            ├─ 🔒 Зарезервировать (если advanced)
                            └─ 📊 История
```

---

## 🔐 Проверка прав доступа

Кроме проверки модулей, учитывайте также права (`permissions`):

```javascript
// Пример проверки прав
const userPermissions = useUserPermissions();

const canManageWarehouse = 
  userPermissions.includes('warehouse.manage_stock') ||
  userPermissions.includes('advanced_warehouse.view');

const canUseReservations = 
  hasAdvancedWarehouse && 
  userPermissions.includes('advanced_warehouse.reservations');
```

---

## 📊 Статусы и константы

### Типы складов
- `main` - Центральный склад
- `branch` - Филиальный склад
- `mobile` - Мобильный склад
- `virtual` - Виртуальный склад

### Типы движений
- `receipt` - Приход
- `write_off` - Списание
- `transfer_in` - Перемещение (приход)
- `transfer_out` - Перемещение (расход)

### Статусы инвентаризации
- `draft` - Черновик
- `in_progress` - В процессе
- `completed` - Завершена
- `approved` - Утверждена

### Статусы резервирования
- `active` - Активна
- `expired` - Истёкла
- `released` - Снята
- `consumed` - Использована

### Типы зон хранения
- `storage` - Хранение
- `receiving` - Приёмка
- `shipping` - Отгрузка
- `quarantine` - Карантин
- `returns` - Возвраты

---

## 🚀 Быстрый старт

### Минимальный пример интеграции

```javascript
// 1. Проверяем доступные модули
const modules = await getActiveModules();
const warehouse = modules.data.warehouse || [];

const hasBasic = warehouse.some(m => 
  m.slug === 'basic-warehouse' && m.is_active
);

const hasAdvanced = warehouse.some(m => 
  m.slug === 'advanced-warehouse' && m.is_active
);

// 2. Загружаем список складов
const warehouses = await api.get('/api/v1/warehouses');

// 3. Загружаем остатки для первого склада
const balances = await api.get(`/api/v1/warehouses/${warehouses.data[0].id}/balances`);

// 4. Если есть продвинутый модуль - загружаем аналитику
if (hasAdvanced) {
  const analytics = await api.get('/api/v1/advanced-warehouse/analytics/turnover');
}
```

---

## ✅ Чек-лист интеграции

- [ ] Проверка активных модулей при загрузке
- [ ] Условное отображение вкладок "Резервирование", "Аналитика", "Автопополнение", "Зоны"
- [ ] Ограничение на 1 склад для базового модуля
- [ ] Формы прихода/списания/перемещения
- [ ] Процесс инвентаризации (создание → запуск → заполнение → завершение → утверждение)
- [ ] Таблица остатков с фильтрами
- [ ] История движений с фильтрами по датам
- [ ] Резервирование активов (только advanced)
- [ ] Аналитические дашборды (только advanced)
- [ ] Управление зонами хранения (только advanced)
- [ ] Правила автопополнения (только advanced)
- [ ] Уведомления при попытке использовать функции advanced без активации
- [ ] Экспорт данных в Excel/PDF

---

## 📞 Поддержка

При возникновении вопросов по интеграции обращайтесь к backend команде или смотрите примеры в `/docs/openapi/`.

**Хороший код!** 🚀

