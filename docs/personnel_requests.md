# Заявки на персонал (Personnel Requests)

Данная документация описывает функциональность заявок на персонал, добавленную в систему ProHelper.

## Обзор

Заявки на персонал позволяют прорабам создавать запросы на привлечение дополнительного персонала для выполнения работ на проектах. Система поддерживает различные типы персонала, указание требований, условий работы и оплаты.

## Типы персонала

Система поддерживает следующие типы персонала:

- **general_worker** - Разнорабочий
- **skilled_worker** - Квалифицированный рабочий  
- **foreman** - Прораб
- **engineer** - Инженер
- **specialist** - Специалист
- **operator** - Оператор
- **electrician** - Электрик
- **plumber** - Сантехник
- **welder** - Сварщик
- **carpenter** - Плотник
- **mason** - Каменщик
- **painter** - Маляр
- **security** - Охранник
- **driver** - Водитель
- **other** - Другое

## Поля заявки на персонал

### Основные поля (наследуются от обычных заявок)
- `title` - Заголовок заявки
- `description` - Описание заявки
- `priority` - Приоритет (low, medium, high, urgent)
- `required_date` - Требуемая дата выполнения
- `notes` - Дополнительные заметки
- `files` - Прикрепленные файлы

### Специфичные поля для персонала
- `personnel_type` - Тип требуемого персонала (обязательное)
- `personnel_count` - Количество человек (обязательное, min: 1)
- `personnel_requirements` - Требования к персоналу (опыт, навыки, сертификаты)
- `hourly_rate` - Почасовая ставка в рублях
- `work_hours_per_day` - Количество рабочих часов в день (1-24)
- `work_start_date` - Дата начала работы
- `work_end_date` - Дата окончания работы
- `work_location` - Место работы (адрес, участок)
- `additional_conditions` - Дополнительные условия работы

## API Endpoints

### Создание заявки на персонал

```http
POST /api/v1/mobile/site-requests/personnel
Content-Type: application/json
Authorization: Bearer {token}

{
  "organization_id": 1,
  "project_id": 5,
  "title": "Требуются сварщики для монтажа конструкций",
  "description": "Необходимы опытные сварщики для работы с металлоконструкциями",
  "priority": "high",
  "required_date": "2025-02-01",
  "personnel_type": "welder",
  "personnel_count": 3,
  "personnel_requirements": "Опыт работы от 3 лет, сертификат НАКС",
  "hourly_rate": 800.00,
  "work_hours_per_day": 8,
  "work_start_date": "2025-02-01",
  "work_end_date": "2025-02-15",
  "work_location": "Строительная площадка, участок А",
  "additional_conditions": "Предоставляется спецодежда и инструмент"
}
```

### Получение списка заявок с фильтрацией

```http
GET /api/v1/mobile/site-requests?request_type=personnel_request&status=pending
Authorization: Bearer {token}
```

### Обновление заявки на персонал

```http
PUT /api/v1/mobile/site-requests/{id}
Content-Type: application/json
Authorization: Bearer {token}

{
  "title": "Обновленный заголовок",
  "personnel_count": 5,
  "hourly_rate": 850.00
}
```

## События и уведомления

При создании заявки на персонал система автоматически:

1. Отправляет событие `PersonnelRequestCreated` через WebSocket
2. Уведомляет заинтересованных пользователей о новой заявке
3. Включает в уведомление ключевую информацию:
   - Тип и количество персонала
   - Даты работы
   - Почасовую ставку
   - Информацию об авторе и проекте

## Валидация

Система выполняет следующие проверки:

- Обязательные поля для заявок типа `personnel_request`:
  - `personnel_type`
  - `personnel_count`
- Ограничения значений:
  - `personnel_count`: минимум 1, максимум 100
  - `hourly_rate`: минимум 0, максимум 10,000
  - `work_hours_per_day`: от 1 до 24 часов
- Проверка дат:
  - `work_end_date` должна быть больше `work_start_date`
  - `required_date` не может быть в прошлом

## Структура базы данных

Добавлены следующие поля в таблицу `site_requests`:

```sql
ALTER TABLE site_requests ADD COLUMN personnel_type VARCHAR(50) NULL;
ALTER TABLE site_requests ADD COLUMN personnel_count INTEGER NULL;
ALTER TABLE site_requests ADD COLUMN personnel_requirements TEXT NULL;
ALTER TABLE site_requests ADD COLUMN hourly_rate DECIMAL(8,2) NULL;
ALTER TABLE site_requests ADD COLUMN work_hours_per_day INTEGER NULL;
ALTER TABLE site_requests ADD COLUMN work_start_date DATE NULL;
ALTER TABLE site_requests ADD COLUMN work_end_date DATE NULL;
ALTER TABLE site_requests ADD COLUMN work_location VARCHAR(500) NULL;
ALTER TABLE site_requests ADD COLUMN additional_conditions TEXT NULL;

-- Индексы для оптимизации запросов
CREATE INDEX idx_site_requests_personnel_type ON site_requests(personnel_type);
CREATE INDEX idx_site_requests_work_start_date ON site_requests(work_start_date);
CREATE INDEX idx_site_requests_work_end_date ON site_requests(work_end_date);
```

## Примеры использования

### Заявка на разнорабочих

```json
{
  "title": "Требуются разнорабочие для уборки территории",
  "personnel_type": "general_worker",
  "personnel_count": 5,
  "hourly_rate": 400.00,
  "work_hours_per_day": 8,
  "work_start_date": "2025-01-20",
  "work_end_date": "2025-01-25"
}
```

### Заявка на специалиста

```json
{
  "title": "Требуется инженер-геодезист",
  "personnel_type": "engineer",
  "personnel_count": 1,
  "personnel_requirements": "Высшее образование, опыт работы с тахеометром",
  "hourly_rate": 1200.00,
  "work_hours_per_day": 8,
  "additional_conditions": "Командировочные оплачиваются отдельно"
}
```

## Интеграция с существующей системой

Заявки на персонал полностью интегрированы с существующей системой заявок:

- Используют те же статусы и приоритеты
- Поддерживают прикрепление файлов
- Включены в общую систему уведомлений
- Доступны через мобильное приложение
- Поддерживают фильтрацию и сортировку

## Безопасность

- Все эндпоинты требуют аутентификации через Bearer token
- Пользователи могут создавать заявки только для проектов, к которым у них есть доступ
- Валидация входных данных на уровне запросов
- Защита от SQL-инъекций через использование Eloquent ORM