# API Конструктора отчетов

## 📖 Обзор

API для создания и управления кастомными отчетами с гибкой настройкой источников данных, фильтров, агрегаций и автоматической генерации.

**Base URL:** `/api/v1/admin/custom-reports`

**Аутентификация:** Bearer Token (JWT)

**Требования:** 
- Активный модуль `advanced-reports`
- Доступ к функционалу через middleware `module.access:advanced-reports`

---

## 📡 Справочные эндпоинты (Builder API)

Эндпоинты для получения метаданных, необходимых для построения UI конструктора отчетов.

### Получить список источников данных

**GET** `/builder/data-sources`

Возвращает все доступные источники данных для построения отчетов.

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "key": "projects",
      "label": "Проекты",
      "category": "core",
      "table": "projects"
    },
    {
      "key": "contracts",
      "label": "Контракты",
      "category": "finances",
      "table": "contracts"
    },
    {
      "key": "materials",
      "label": "Материалы",
      "category": "operations",
      "table": "materials"
    },
    {
      "key": "completed_works",
      "label": "Выполненные работы",
      "category": "operations",
      "table": "completed_works"
    },
    {
      "key": "users",
      "label": "Пользователи",
      "category": "core",
      "table": "users"
    },
    {
      "key": "contractors",
      "label": "Подрядчики",
      "category": "finances",
      "table": "contractors"
    },
    {
      "key": "material_receipts",
      "label": "Приемки материалов",
      "category": "operations",
      "table": "material_receipts"
    },
    {
      "key": "time_entries",
      "label": "Учет рабочего времени",
      "category": "operations",
      "table": "time_entries"
    }
  ]
}
```

---

### Получить поля источника данных

**GET** `/builder/data-sources/{source}/fields`

Возвращает список доступных полей для указанного источника данных.

**Параметры:**
- `{source}` - ключ источника данных (например: `projects`, `contracts`)

**Пример запроса:**
```
GET /builder/data-sources/projects/fields
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "key": "id",
      "label": "ID",
      "type": "integer",
      "filterable": true,
      "sortable": true,
      "aggregatable": false
    },
    {
      "key": "name",
      "label": "Название",
      "type": "string",
      "filterable": true,
      "sortable": true,
      "aggregatable": false
    },
    {
      "key": "budget_amount",
      "label": "Бюджет",
      "type": "decimal",
      "filterable": true,
      "sortable": true,
      "aggregatable": true,
      "format": "currency"
    },
    {
      "key": "status",
      "label": "Статус",
      "type": "string",
      "filterable": true,
      "sortable": true,
      "aggregatable": false
    },
    {
      "key": "start_date",
      "label": "Дата начала",
      "type": "date",
      "filterable": true,
      "sortable": true,
      "aggregatable": false,
      "format": "date"
    },
    {
      "key": "end_date",
      "label": "Дата окончания",
      "type": "date",
      "filterable": true,
      "sortable": true,
      "aggregatable": false,
      "format": "date"
    },
    {
      "key": "created_at",
      "label": "Дата создания",
      "type": "datetime",
      "filterable": true,
      "sortable": true,
      "aggregatable": false,
      "format": "datetime"
    }
  ]
}
```

---

### Получить связи источника данных

**GET** `/builder/data-sources/{source}/relations`

Возвращает доступные связи (для JOIN'ов) для указанного источника данных.

**Пример запроса:**
```
GET /builder/data-sources/projects/relations
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "name": "contracts",
      "label": "Контракты",
      "type": "hasMany",
      "related_source": "contracts",
      "foreign_key": "project_id",
      "local_key": "id"
    },
    {
      "name": "completed_works",
      "label": "Выполненные работы",
      "type": "hasMany",
      "related_source": "completed_works",
      "foreign_key": "project_id",
      "local_key": "id"
    },
    {
      "name": "owner",
      "label": "Владелец",
      "type": "belongsTo",
      "related_source": "users",
      "foreign_key": "user_id",
      "local_key": "id"
    }
  ]
}
```

---

### Получить доступные операторы фильтров

**GET** `/builder/operators`

**Ответ:**
```json
{
  "success": true,
  "data": [
    { "key": "=", "label": "Равно" },
    { "key": "!=", "label": "Не равно" },
    { "key": ">", "label": "Больше" },
    { "key": "<", "label": "Меньше" },
    { "key": ">=", "label": "Больше или равно" },
    { "key": "<=", "label": "Меньше или равно" },
    { "key": "like", "label": "Содержит" },
    { "key": "not_like", "label": "Не содержит" },
    { "key": "in", "label": "В списке" },
    { "key": "not_in", "label": "Не в списке" },
    { "key": "between", "label": "Между" },
    { "key": "is_null", "label": "Пусто" },
    { "key": "is_not_null", "label": "Не пусто" }
  ]
}
```

---

### Получить доступные агрегатные функции

**GET** `/builder/aggregations`

**Ответ:**
```json
{
  "success": true,
  "data": [
    { "key": "sum", "label": "Сумма" },
    { "key": "avg", "label": "Среднее" },
    { "key": "count", "label": "Количество" },
    { "key": "min", "label": "Минимум" },
    { "key": "max", "label": "Максимум" },
    { "key": "count_distinct", "label": "Уникальные значения" }
  ]
}
```

---

### Получить форматы экспорта

**GET** `/builder/export-formats`

**Ответ:**
```json
{
  "success": true,
  "data": [
    { "key": "csv", "label": "CSV", "mime": "text/csv" },
    { "key": "excel", "label": "Excel", "mime": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
    { "key": "pdf", "label": "PDF", "mime": "application/pdf" }
  ]
}
```

---

### Получить категории отчетов

**GET** `/builder/categories`

**Ответ:**
```json
{
  "success": true,
  "data": [
    { "key": "core", "label": "Основные" },
    { "key": "finances", "label": "Финансы" },
    { "key": "operations", "label": "Операционные" },
    { "key": "hr", "label": "Кадры" },
    { "key": "analytics", "label": "Аналитика" }
  ]
}
```

---

### Валидировать конфигурацию отчета

**POST** `/builder/validate`

Проверяет корректность конфигурации отчета перед сохранением.

**Тело запроса:**
```json
{
  "data_sources": {
    "primary": "projects",
    "joins": [
      {
        "table": "contracts",
        "type": "left",
        "on": ["projects.id", "contracts.project_id"]
      }
    ]
  },
  "query_config": {
    "where": [
      {
        "field": "projects.status",
        "operator": "=",
        "value": "active"
      }
    ]
  },
  "columns_config": [
    {
      "field": "projects.name",
      "label": "Название проекта",
      "order": 1,
      "format": "text"
    }
  ]
}
```

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Конфигурация валидна",
  "data": {
    "valid": true,
    "estimated_complexity": "medium"
  }
}
```

**Ответ (ошибка):**
```json
{
  "success": false,
  "message": "Ошибка валидации",
  "errors": {
    "data_sources.joins.0.on": ["Поле on должно содержать два элемента"],
    "columns_config.0.field": ["Поле projects.invalid_field не существует"]
  }
}
```

---

### Предпросмотр отчета

**POST** `/builder/preview`

Выполняет отчет с ограничением в 20 строк для предпросмотра.

**Тело запроса:**
```json
{
  "data_sources": {
    "primary": "projects"
  },
  "columns_config": [
    {
      "field": "projects.name",
      "label": "Название",
      "order": 1,
      "format": "text"
    },
    {
      "field": "projects.budget_amount",
      "label": "Бюджет",
      "order": 2,
      "format": "currency"
    }
  ],
  "query_config": {
    "where": [
      {
        "field": "projects.status",
        "operator": "=",
        "value": "active"
      }
    ]
  }
}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "columns": [
      { "field": "name", "label": "Название", "format": "text" },
      { "field": "budget_amount", "label": "Бюджет", "format": "currency" }
    ],
    "rows": [
      { "name": "Проект А", "budget_amount": "1500000.00" },
      { "name": "Проект Б", "budget_amount": "2300000.00" },
      { "name": "Проект В", "budget_amount": "980000.00" }
    ],
    "preview": true,
    "total_rows": 3,
    "execution_time_ms": 45
  }
}
```

---

## 📊 CRUD операции с отчетами

### Получить список отчетов

**GET** `/`

Возвращает список всех отчетов текущей организации.

**Query параметры:**
- `category` (optional) - фильтр по категории (`core`, `finances`, `operations`, `hr`, `analytics`)
- `is_favorite` (optional) - только избранные (`1` или `0`)
- `search` (optional) - поиск по названию/описанию

**Пример запроса:**
```
GET /?category=finances&is_favorite=1
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Финансы по проектам",
      "description": "Суммы контрактов по каждому проекту",
      "report_category": "finances",
      "is_shared": true,
      "is_favorite": true,
      "is_scheduled": true,
      "execution_count": 45,
      "last_executed_at": "2025-10-03T10:30:00.000000Z",
      "created_at": "2025-09-01T08:00:00.000000Z",
      "updated_at": "2025-10-03T10:30:00.000000Z",
      "user": {
        "id": 5,
        "name": "Иван Иванов",
        "email": "ivan@example.com"
      }
    }
  ]
}
```

---

### Получить один отчет

**GET** `/{id}`

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Финансы по проектам",
    "description": "Суммы контрактов по каждому проекту",
    "report_category": "finances",
    "data_sources": {
      "primary": "projects",
      "joins": [
        {
          "table": "contracts",
          "type": "left",
          "on": ["projects.id", "contracts.project_id"]
        }
      ]
    },
    "columns_config": [
      {
        "field": "projects.name",
        "label": "Проект",
        "order": 1,
        "format": "text"
      },
      {
        "field": "contracts.total_amount",
        "label": "Сумма контрактов",
        "order": 2,
        "format": "currency",
        "aggregation": "sum"
      }
    ],
    "query_config": {
      "where": []
    },
    "filters_config": [],
    "aggregations_config": {
      "group_by": ["projects.id", "projects.name"],
      "aggregations": [
        {
          "field": "contracts.total_amount",
          "function": "sum",
          "alias": "total_amount"
        }
      ]
    },
    "sorting_config": [
      {
        "field": "total_amount",
        "direction": "desc"
      }
    ],
    "is_shared": true,
    "is_favorite": true,
    "is_scheduled": true,
    "execution_count": 45,
    "last_executed_at": "2025-10-03T10:30:00.000000Z",
    "created_at": "2025-09-01T08:00:00.000000Z",
    "updated_at": "2025-10-03T10:30:00.000000Z"
  }
}
```

---

### Создать отчет

**POST** `/`

**Тело запроса (простой отчет без JOIN'ов):**
```json
{
  "name": "Список активных проектов",
  "description": "Все активные проекты с бюджетами",
  "report_category": "core",
  "data_sources": {
    "primary": "projects"
  },
  "columns_config": [
    {
      "field": "projects.name",
      "label": "Название проекта",
      "order": 1,
      "format": "text"
    },
    {
      "field": "projects.budget_amount",
      "label": "Бюджет",
      "order": 2,
      "format": "currency"
    },
    {
      "field": "projects.start_date",
      "label": "Дата начала",
      "order": 3,
      "format": "date"
    }
  ],
  "query_config": {
    "where": [
      {
        "field": "projects.status",
        "operator": "=",
        "value": "active"
      }
    ]
  },
  "sorting_config": [
    {
      "field": "projects.created_at",
      "direction": "desc"
    }
  ]
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет успешно создан",
  "data": {
    "id": 5,
    "name": "Список активных проектов",
    "description": "Все активные проекты с бюджетами",
    "report_category": "core",
    "organization_id": 1,
    "user_id": 3,
    "is_shared": false,
    "is_favorite": false,
    "is_scheduled": false,
    "execution_count": 0,
    "last_executed_at": null,
    "created_at": "2025-10-03T12:00:00.000000Z",
    "updated_at": "2025-10-03T12:00:00.000000Z"
  }
}
```

**Тело запроса (отчет с JOIN'ами и агрегацией):**
```json
{
  "name": "Финансы по проектам",
  "description": "Суммы контрактов по каждому проекту",
  "report_category": "finances",
  "data_sources": {
    "primary": "projects",
    "joins": [
      {
        "table": "contracts",
        "type": "left",
        "on": ["projects.id", "contracts.project_id"]
      }
    ]
  },
  "columns_config": [
    {
      "field": "projects.name",
      "label": "Проект",
      "order": 1,
      "format": "text"
    },
    {
      "field": "contracts.total_amount",
      "label": "Сумма контрактов",
      "order": 2,
      "format": "currency",
      "aggregation": "sum"
    },
    {
      "field": "contracts.id",
      "label": "Количество контрактов",
      "order": 3,
      "format": "number",
      "aggregation": "count"
    }
  ],
  "aggregations_config": {
    "group_by": ["projects.id", "projects.name"],
    "aggregations": [
      {
        "field": "contracts.total_amount",
        "function": "sum",
        "alias": "total_amount"
      },
      {
        "field": "contracts.id",
        "function": "count",
        "alias": "contracts_count"
      }
    ]
  },
  "sorting_config": [
    {
      "field": "total_amount",
      "direction": "desc"
    }
  ],
  "filters_config": [
    {
      "field": "projects.status",
      "label": "Статус проекта",
      "type": "select",
      "options": ["active", "completed", "on_hold"],
      "required": false,
      "default": null
    },
    {
      "field": "projects.start_date",
      "label": "Дата начала",
      "type": "date_range",
      "required": false
    }
  ]
}
```

---

### Обновить отчет

**PUT** `/{id}`

**Тело запроса:**
```json
{
  "name": "Финансы по проектам (обновлено)",
  "description": "Обновленное описание",
  "is_shared": true
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет успешно обновлен",
  "data": {
    "id": 5,
    "name": "Финансы по проектам (обновлено)",
    "description": "Обновленное описание",
    "is_shared": true,
    "updated_at": "2025-10-03T12:15:00.000000Z"
  }
}
```

---

### Удалить отчет

**DELETE** `/{id}`

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет успешно удален"
}
```

---

### Клонировать отчет

**POST** `/{id}/clone`

**Тело запроса (опционально):**
```json
{
  "name": "Копия отчета - Финансы по проектам"
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет успешно клонирован",
  "data": {
    "id": 6,
    "name": "Копия отчета - Финансы по проектам",
    "description": "Суммы контрактов по каждому проекту",
    "report_category": "finances",
    "is_shared": false,
    "is_favorite": false,
    "created_at": "2025-10-03T12:20:00.000000Z"
  }
}
```

---

### Добавить/удалить из избранного

**POST** `/{id}/favorite`

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет добавлен в избранное",
  "data": {
    "is_favorite": true
  }
}
```

---

### Изменить настройки общего доступа

**POST** `/{id}/share`

**Тело запроса:**
```json
{
  "is_shared": true
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Настройки доступа обновлены",
  "data": {
    "is_shared": true
  }
}
```

---

## 🚀 Выполнение отчетов

### Выполнить отчет с фильтрами

**POST** `/{id}/execute`

**Тело запроса:**
```json
{
  "filters": {
    "projects.status": "active",
    "projects.start_date": {
      "from": "2024-01-01",
      "to": "2024-12-31"
    }
  }
}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "execution_id": 125,
    "columns": [
      { "field": "name", "label": "Название проекта", "format": "text" },
      { "field": "budget_amount", "label": "Бюджет", "format": "currency" },
      { "field": "start_date", "label": "Дата начала", "format": "date" }
    ],
    "rows": [
      {
        "name": "Проект А",
        "budget_amount": "1500000.00",
        "start_date": "2024-03-15"
      },
      {
        "name": "Проект Б",
        "budget_amount": "2300000.00",
        "start_date": "2024-06-01"
      }
    ],
    "total_rows": 2,
    "execution_time_ms": 89,
    "applied_filters": {
      "projects.status": "active",
      "projects.start_date": {
        "from": "2024-01-01",
        "to": "2024-12-31"
      }
    }
  }
}
```

---

### Экспортировать отчет

**POST** `/{id}/execute?export={format}`

Возвращает файл для скачивания.

**Query параметры:**
- `export` - формат экспорта (`csv`, `excel`, `pdf`)

**Тело запроса:**
```json
{
  "filters": {
    "projects.status": "active"
  }
}
```

**Ответ:** Файл для скачивания с headers:
- `Content-Type`: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (для Excel)
- `Content-Disposition`: `attachment; filename="report_123_20251003.xlsx"`

---

### Получить историю выполнений отчета

**GET** `/{id}/executions`

**Query параметры:**
- `per_page` (optional) - количество записей на страницу (по умолчанию 15)
- `page` (optional) - номер страницы

**Ответ:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 125,
        "custom_report_id": 5,
        "user_id": 3,
        "status": "completed",
        "result_rows_count": 24,
        "execution_time_ms": 89,
        "export_format": null,
        "created_at": "2025-10-03T12:30:00.000000Z",
        "completed_at": "2025-10-03T12:30:00.000000Z",
        "user": {
          "id": 3,
          "name": "Иван Иванов"
        }
      },
      {
        "id": 124,
        "custom_report_id": 5,
        "user_id": 3,
        "status": "completed",
        "result_rows_count": 20,
        "execution_time_ms": 76,
        "export_format": "excel",
        "created_at": "2025-10-02T14:15:00.000000Z",
        "completed_at": "2025-10-02T14:15:00.000000Z"
      }
    ],
    "per_page": 15,
    "total": 45,
    "last_page": 3
  }
}
```

---

## 📅 Управление расписанием

### Получить список расписаний отчета

**GET** `/{id}/schedules`

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "custom_report_id": 5,
      "schedule_type": "daily",
      "schedule_config": {
        "time": "09:00"
      },
      "recipient_emails": ["manager@example.com", "director@example.com"],
      "export_format": "excel",
      "is_active": true,
      "last_run_at": "2025-10-03T09:00:00.000000Z",
      "next_run_at": "2025-10-04T09:00:00.000000Z",
      "created_at": "2025-09-01T10:00:00.000000Z"
    }
  ]
}
```

---

### Создать расписание

**POST** `/{id}/schedules`

**Тело запроса (ежедневная отправка):**
```json
{
  "schedule_type": "daily",
  "schedule_config": {
    "time": "09:00"
  },
  "recipient_emails": ["manager@example.com", "director@example.com"],
  "export_format": "excel",
  "filters_preset": {
    "projects.status": "active"
  }
}
```

**Тело запроса (еженедельная отправка по понедельникам):**
```json
{
  "schedule_type": "weekly",
  "schedule_config": {
    "time": "09:00",
    "day_of_week": 1
  },
  "recipient_emails": ["weekly-report@example.com"],
  "export_format": "pdf"
}
```

**Тело запроса (ежемесячная отправка первого числа):**
```json
{
  "schedule_type": "monthly",
  "schedule_config": {
    "time": "08:00",
    "day_of_month": 1
  },
  "recipient_emails": ["monthly@example.com"],
  "export_format": "excel"
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Расписание успешно создано",
  "data": {
    "id": 10,
    "custom_report_id": 5,
    "schedule_type": "daily",
    "schedule_config": {
      "time": "09:00"
    },
    "recipient_emails": ["manager@example.com", "director@example.com"],
    "export_format": "excel",
    "is_active": true,
    "next_run_at": "2025-10-04T09:00:00.000000Z",
    "created_at": "2025-10-03T13:00:00.000000Z"
  }
}
```

---

### Получить одно расписание

**GET** `/{id}/schedules/{scheduleId}`

**Ответ:**
```json
{
  "success": true,
  "data": {
    "id": 10,
    "custom_report_id": 5,
    "schedule_type": "daily",
    "schedule_config": {
      "time": "09:00"
    },
    "filters_preset": {
      "projects.status": "active"
    },
    "recipient_emails": ["manager@example.com"],
    "export_format": "excel",
    "is_active": true,
    "last_run_at": "2025-10-03T09:00:00.000000Z",
    "next_run_at": "2025-10-04T09:00:00.000000Z",
    "created_at": "2025-09-01T10:00:00.000000Z"
  }
}
```

---

### Обновить расписание

**PUT** `/{id}/schedules/{scheduleId}`

**Тело запроса:**
```json
{
  "schedule_config": {
    "time": "10:00"
  },
  "recipient_emails": ["new@example.com"]
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Расписание успешно обновлено",
  "data": {
    "id": 10,
    "schedule_config": {
      "time": "10:00"
    },
    "recipient_emails": ["new@example.com"],
    "next_run_at": "2025-10-04T10:00:00.000000Z",
    "updated_at": "2025-10-03T13:15:00.000000Z"
  }
}
```

---

### Удалить расписание

**DELETE** `/{id}/schedules/{scheduleId}`

**Ответ:**
```json
{
  "success": true,
  "message": "Расписание успешно удалено"
}
```

---

### Включить/выключить расписание

**POST** `/{id}/schedules/{scheduleId}/toggle`

**Ответ:**
```json
{
  "success": true,
  "message": "Расписание отключено",
  "data": {
    "is_active": false
  }
}
```

---

### Запустить расписание немедленно

**POST** `/{id}/schedules/{scheduleId}/run-now`

Выполняет отчет и отправляет на указанные email адреса.

**Ответ:**
```json
{
  "success": true,
  "message": "Отчет выполнен и отправлен",
  "data": {
    "execution_id": 126,
    "sent_to": ["manager@example.com", "director@example.com"],
    "execution_time_ms": 95
  }
}
```

---

## 📚 Справочник

### Операторы фильтров

| Оператор | Описание | Пример значения |
|----------|----------|-----------------|
| `=` | Равно | `"active"` |
| `!=` | Не равно | `"completed"` |
| `>` | Больше | `1000000` |
| `<` | Меньше | `5000000` |
| `>=` | Больше или равно | `0` |
| `<=` | Меньше или равно | `10000` |
| `like` | Содержит | `"%проект%"` |
| `not_like` | Не содержит | `"%тест%"` |
| `in` | В списке | `["active", "on_hold"]` |
| `not_in` | Не в списке | `["archived"]` |
| `between` | Между | `{"from": "2024-01-01", "to": "2024-12-31"}` |
| `is_null` | Пусто | `null` |
| `is_not_null` | Не пусто | `null` |

---

### Агрегатные функции

| Функция | Описание | Применимо к типам |
|---------|----------|-------------------|
| `sum` | Сумма | `integer`, `decimal` |
| `avg` | Среднее | `integer`, `decimal` |
| `count` | Количество | Все типы |
| `min` | Минимум | `integer`, `decimal`, `date`, `datetime` |
| `max` | Максимум | `integer`, `decimal`, `date`, `datetime` |
| `count_distinct` | Уникальные значения | Все типы |

---

### Форматы экспорта

| Формат | MIME Type | Расширение |
|--------|-----------|------------|
| `csv` | `text/csv` | `.csv` |
| `excel` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | `.xlsx` |
| `pdf` | `application/pdf` | `.pdf` |

---

### Источники данных

| Ключ | Название | Категория |
|------|----------|-----------|
| `projects` | Проекты | `core` |
| `contracts` | Контракты | `finances` |
| `materials` | Материалы | `operations` |
| `completed_works` | Выполненные работы | `operations` |
| `users` | Пользователи | `core` |
| `contractors` | Подрядчики | `finances` |
| `material_receipts` | Приемки материалов | `operations` |
| `time_entries` | Учет рабочего времени | `operations` |

---

### Типы расписаний

| Тип | Параметры конфигурации | Пример |
|-----|------------------------|--------|
| `daily` | `time` | `{"time": "09:00"}` |
| `weekly` | `time`, `day_of_week` (0-6, 0=воскресенье) | `{"time": "09:00", "day_of_week": 1}` |
| `monthly` | `time`, `day_of_month` (1-31) | `{"time": "08:00", "day_of_month": 1}` |

---

### Категории отчетов

| Ключ | Название |
|------|----------|
| `core` | Основные |
| `finances` | Финансы |
| `operations` | Операционные |
| `hr` | Кадры |
| `analytics` | Аналитика |

---

### Форматы полей

| Формат | Описание | Пример вывода |
|--------|----------|---------------|
| `text` | Текст | `"Проект А"` |
| `number` | Число | `42` |
| `currency` | Валюта | `"1 500 000.00 ₽"` |
| `percent` | Проценты | `"75.5%"` |
| `date` | Дата | `"2024-10-03"` |
| `datetime` | Дата и время | `"2024-10-03 14:30:00"` |
| `boolean` | Да/Нет | `"Да"` / `"Нет"` |

---

## ⚠️ Ограничения

| Параметр | Лимит |
|----------|-------|
| Максимум JOIN'ов | 7 |
| Максимум строк результата | 10,000 |
| Таймаут выполнения запроса | 30 секунд |
| Максимум агрегаций | 10 |
| Максимум фильтров | 20 |
| Максимум колонок | 50 |
| Максимум отчетов на организацию | 50 |
| Максимум активных расписаний на организацию | 10 |

---

## 💡 Примеры сценариев использования

### Сценарий 1: Создание отчета по проектам
1. `GET /builder/data-sources` - получить список источников
2. `GET /builder/data-sources/projects/fields` - получить поля проектов
3. `POST /builder/validate` - проверить конфигурацию
4. `POST /builder/preview` - предпросмотр данных
5. `POST /` - создать отчет
6. `POST /{id}/execute` - выполнить отчет

### Сценарий 2: Настройка автоматической отправки
1. `GET /{id}` - получить существующий отчет
2. `POST /{id}/execute` - проверить работу отчета
3. `POST /{id}/schedules` - создать расписание
4. `POST /{id}/schedules/{scheduleId}/run-now` - протестировать отправку

### Сценарий 3: Экспорт данных
1. `POST /{id}/execute` - получить данные в JSON
2. `POST /{id}/execute?export=excel` - скачать Excel
3. `GET /{id}/executions` - просмотреть историю

---

## 📝 Примечания

- Все даты возвращаются в формате ISO 8601 (UTC)
- Расписания выполняются автоматически каждые 5 минут
- История выполнений хранится 90 дней
- Email отправляется асинхронно через очередь
- При ошибке выполнения создается запись со статусом `failed`
- Экспорт в PDF ограничен 1000 строками для производительности

---

## 🔗 Связанные ресурсы

- Подробная техническая документация: `CUSTOM_REPORTS_BUILDER_PLAN.md`
- Конфигурация источников данных: `config/custom-reports.php`

