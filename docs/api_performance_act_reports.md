# API для отчетов по актам выполненных работ

## Обзор

API предоставляет возможности для работы с существующими актами выполненных работ:
- Просмотр всех актов организации с фильтрацией
- Экспорт актов в PDF и Excel форматы  
- Массовый экспорт нескольких актов

## Эндпоинты

### 1. В контексте контрактов

#### Экспорт акта в PDF
**GET** `/api/v1/admin/contracts/{contract}/performance-acts/{performance_act}/export/pdf`

**Описание**: Экспорт конкретного акта в PDF формат

**Ответ**: Скачивание PDF файла

#### Экспорт акта в Excel  
**GET** `/api/v1/admin/contracts/{contract}/performance-acts/{performance_act}/export/excel`

**Описание**: Экспорт конкретного акта в Excel формат

**Ответ**: Скачивание Excel файла

### 2. Отдельная страница отчетов

#### Получить список всех актов
**GET** `/api/v1/admin/act-reports`

**Параметры запроса**:
- `contract_id` (integer, опционально) - ID контракта
- `project_id` (integer, опционально) - ID проекта  
- `contractor_id` (integer, опционально) - ID подрядчика
- `is_approved` (boolean, опционально) - Статус утверждения
- `date_from` (date, опционально) - Дата начала периода
- `date_to` (date, опционально) - Дата окончания периода
- `search` (string, опционально) - Поиск по номеру акта, описанию, номеру контракта
- `sort_by` (string, опционально) - Поле сортировки (по умолчанию: act_date)
- `sort_direction` (string, опционально) - Направление сортировки (asc/desc, по умолчанию: desc)
- `per_page` (integer, опционально) - Количество записей на странице (по умолчанию: 15)

**Пример ответа**:
```json
{
  "data": [
    {
      "id": 1,
      "act_document_number": "АКТ-001-2024",
      "act_date": "2024-01-15",
      "amount": 150000.00,
      "is_approved": true,
      "contract": {
        "id": 1,
        "contract_number": "ДОГ-001-2024",
        "project": {
          "id": 1,
          "name": "Строительство дома"
        },
        "contractor": {
          "id": 1,
          "name": "ООО Стройсервис"
        }
      },
      "completed_works_count": 5
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 73
  },
  "statistics": {
    "total_acts": 73,
    "approved_acts": 65,
    "total_amount": 2500000.00
  }
}
```

#### Получить детали акта
**GET** `/api/v1/admin/act-reports/{act}`

**Пример ответа**:
```json
{
  "data": {
    "id": 1,
    "act_document_number": "АКТ-001-2024",
    "act_date": "2024-01-15",
    "amount": 150000.00,
    "is_approved": true,
    "description": "Акт выполненных работ за январь",
    "contract": {
      "id": 1,
      "contract_number": "ДОГ-001-2024",
      "project": {
        "name": "Строительство дома"
      },
      "contractor": {
        "name": "ООО Стройсервис"
      }
    },
    "completed_works": [
      {
        "id": 1,
        "work_type": {
          "name": "Кладка кирпича"
        },
        "quantity": 100,
        "unit": "м²",
        "unit_price": 1500.00,
        "total_amount": 150000.00,
        "completion_date": "2024-01-15",
        "executor": {
          "name": "Иванов И.И."
        },
        "materials": [
          {
            "name": "Кирпич керамический",
            "quantity": 1000,
            "unit": "шт"
          }
        ]
      }
    ]
  }
}
```

#### Обновить основную информацию акта
**PUT** `/api/v1/admin/act-reports/{act}`

**Тело запроса**:
```json
{
  "act_document_number": "АКТ-001-2024-УПД",
  "act_date": "2024-01-16",
  "description": "Обновленное описание акта",
  "is_approved": true
}
```

**Ответ**: Обновленные данные акта

#### Получить доступные работы для включения в акт
**GET** `/api/v1/admin/act-reports/{act}/available-works`

**Пример ответа**:
```json
{
  "data": [
    {
      "id": 5,
      "work_type_name": "Штукатурка стен",
      "user_name": "Петров П.П.",
      "quantity": 50.5,
      "price": 800.00,
      "total_amount": 40400.00,
      "completion_date": "2024-01-10",
      "is_included_in_approved_act": false,
      "is_included_in_current_act": true
    },
    {
      "id": 6,
      "work_type_name": "Покраска потолков",
      "user_name": "Сидоров С.С.",
      "quantity": 25.0,
      "price": 600.00,
      "total_amount": 15000.00,
      "completion_date": "2024-01-12",
      "is_included_in_approved_act": false,
      "is_included_in_current_act": false
    }
  ]
}
```

#### Обновить состав работ в акте
**PUT** `/api/v1/admin/act-reports/{act}/works`

**Тело запроса**:
```json
{
  "work_ids": [5, 6, 7]
}
```

**Ответ**: Обновленные данные акта с новым составом работ и пересчитанной суммой

#### Экспорт акта в PDF
**GET** `/api/v1/admin/act-reports/{act}/export/pdf`

**Ответ**: Скачивание PDF файла

#### Экспорт акта в Excel
**GET** `/api/v1/admin/act-reports/{act}/export/excel`

**Ответ**: Скачивание Excel файла

#### Массовый экспорт в Excel
**POST** `/api/v1/admin/act-reports/bulk-export/excel`

**Тело запроса**:
```json
{
  "act_ids": [1, 2, 3, 5, 8]
}
```

**Ответ**: Скачивание Excel файла со всеми выбранными актами

## Форматы экспорта

### PDF формат
- Структурированный документ с заголовком и реквизитами
- Таблица выполненных работ с материалами и ценами
- Места для подписей заказчика и подрядчика
- Форматирование для печати на А4

### Excel формат (один акт)
Колонки:
- Наименование работы
- Единица измерения
- Количество
- Цена за единицу
- Сумма
- Материалы
- Дата выполнения
- Исполнитель

### Excel формат (массовый экспорт)
Колонки:
- Номер акта
- Контракт
- Проект
- Подрядчик
- Дата акта
- Сумма
- Статус
- Наименование работы
- Единица измерения
- Количество
- Цена за единицу
- Сумма работы
- Материалы
- Дата выполнения
- Исполнитель

## Примеры использования

### Получение актов с фильтрацией
```
GET /api/v1/admin/act-reports?project_id=1&is_approved=true&date_from=2024-01-01&date_to=2024-12-31
```

### Поиск актов
```
GET /api/v1/admin/act-reports?search=АКТ-001&sort_by=amount&sort_direction=desc
```

### Экспорт акта в PDF
```
GET /api/v1/admin/act-reports/1/export/pdf
```

### Массовый экспорт актов
```
POST /api/v1/admin/act-reports/bulk-export/excel
Content-Type: application/json

{
  "act_ids": [1, 2, 3]
}
```

## Коды ошибок

- `400` - Неверные параметры запроса
- `404` - Акт не найден
- `500` - Внутренняя ошибка сервера

## Авторизация

Все эндпоинты требуют авторизации и проверки принадлежности актов к организации пользователя. 