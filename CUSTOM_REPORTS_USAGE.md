# Руководство по использованию конструктора отчетов

## 📖 Обзор

Конструктор отчетов позволяет создавать кастомные отчеты с гибкой настройкой источников данных, фильтров, агрегаций и автоматической генерации.

## 🔑 Требования

- Активный модуль `advanced-reports`
- Permission `advanced_reports.custom_reports`

## 📡 API Эндпоинты

Все эндпоинты требуют аутентификации и находятся под middleware `module.access:advanced-reports`.

### Builder API (метаданные)

```
GET /api/v1/admin/custom-reports/builder/data-sources
GET /api/v1/admin/custom-reports/builder/data-sources/{source}/fields
GET /api/v1/admin/custom-reports/builder/data-sources/{source}/relations
GET /api/v1/admin/custom-reports/builder/operators
GET /api/v1/admin/custom-reports/builder/aggregations
GET /api/v1/admin/custom-reports/builder/export-formats
GET /api/v1/admin/custom-reports/builder/categories
POST /api/v1/admin/custom-reports/builder/validate
POST /api/v1/admin/custom-reports/builder/preview
```

### CRUD отчетов

```
GET    /api/v1/admin/custom-reports
POST   /api/v1/admin/custom-reports
GET    /api/v1/admin/custom-reports/{id}
PUT    /api/v1/admin/custom-reports/{id}
DELETE /api/v1/admin/custom-reports/{id}
POST   /api/v1/admin/custom-reports/{id}/clone
POST   /api/v1/admin/custom-reports/{id}/favorite
POST   /api/v1/admin/custom-reports/{id}/share
```

### Выполнение отчетов

```
POST /api/v1/admin/custom-reports/{id}/execute
GET  /api/v1/admin/custom-reports/{id}/executions
```

### Управление расписанием

```
GET    /api/v1/admin/custom-reports/{id}/schedules
POST   /api/v1/admin/custom-reports/{id}/schedules
GET    /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
PUT    /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
DELETE /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
POST   /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}/toggle
POST   /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}/run-now
```

## 🛠️ Примеры использования

### 1. Получить доступные источники данных

```bash
GET /api/v1/admin/custom-reports/builder/data-sources
```

Ответ:
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
    }
  ]
}
```

### 2. Создать простой отчет (без JOIN'ов)

```bash
POST /api/v1/admin/custom-reports
```

Body:
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

### 3. Создать отчет с JOIN'ами и агрегацией

```bash
POST /api/v1/admin/custom-reports
```

Body:
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
  ]
}
```

### 4. Добавить фильтры для пользователя

```json
{
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
      "required": true
    }
  ]
}
```

### 5. Выполнить отчет с фильтрами

```bash
POST /api/v1/admin/custom-reports/123/execute
```

Body:
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

### 6. Экспортировать отчет в Excel

```bash
POST /api/v1/admin/custom-reports/123/execute?export=excel
```

Body:
```json
{
  "filters": {
    "projects.status": "active"
  }
}
```

### 7. Создать расписание (ежедневная отправка)

```bash
POST /api/v1/admin/custom-reports/123/schedules
```

Body:
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

### 8. Создать расписание (еженедельная отправка по понедельникам)

```bash
POST /api/v1/admin/custom-reports/123/schedules
```

Body:
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

## 🎯 Доступные операторы фильтров

- `=` - Равно
- `!=` - Не равно
- `>` - Больше
- `<` - Меньше
- `>=` - Больше или равно
- `<=` - Меньше или равно
- `like` - Содержит
- `not_like` - Не содержит
- `in` - В списке
- `not_in` - Не в списке
- `between` - Между
- `is_null` - Пусто
- `is_not_null` - Не пусто

## 📊 Доступные агрегатные функции

- `sum` - Сумма
- `avg` - Среднее
- `count` - Количество
- `min` - Минимум
- `max` - Максимум
- `count_distinct` - Уникальные значения

## 📁 Форматы экспорта

- `csv` - CSV
- `excel` - Excel (XLSX)
- `pdf` - PDF

## 🗄️ Доступные источники данных

1. **projects** - Проекты
2. **contracts** - Контракты
3. **materials** - Материалы
4. **completed_works** - Выполненные работы
5. **users** - Пользователи
6. **contractors** - Подрядчики
7. **material_receipts** - Приемки материалов
8. **time_entries** - Учет рабочего времени

## 🔄 Console команды

### Выполнить запланированные отчеты

```bash
php artisan custom-reports:execute-scheduled
```

Добавить в crontab:
```
*/5 * * * * php /path/to/artisan custom-reports:execute-scheduled
```

### Показать список расписаний

```bash
php artisan custom-reports:list-schedules
php artisan custom-reports:list-schedules --active
```

### Очистить старые выполнения

```bash
php artisan custom-reports:cleanup-executions --days=90
```

## ⚠️ Ограничения

- Максимум JOIN'ов: 7
- Максимум строк результата: 10,000
- Таймаут выполнения: 30 секунд
- Максимум агрегаций: 10
- Максимум фильтров: 20
- Максимум колонок: 50
- Максимум кастомных отчетов на организацию: 50
- Максимум активных расписаний на организацию: 10

## 🐛 Отладка

### Проверить валидацию конфигурации

```bash
POST /api/v1/admin/custom-reports/builder/validate
```

### Предпросмотр отчета (первые 20 строк)

```bash
POST /api/v1/admin/custom-reports/builder/preview
```

Body: полная конфигурация отчета

## 📚 Дополнительно

Подробная документация в файле `CUSTOM_REPORTS_BUILDER_PLAN.md`.

