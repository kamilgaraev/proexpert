# API: Управление зависимостями задач в расписании

## Создание зависимости между задачами

**Эндпоинт**: `POST /api/v1/admin/schedules/{schedule_id}/dependencies`

**Описание**: Создает новую зависимость между двумя задачами в указанном расписании.

### Параметры запроса

**Headers:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**URL параметры:**
- `schedule_id` (integer, required) - ID расписания

**Body (JSON):**
```json
{
  "predecessor_task_id": 15,
  "successor_task_id": 18,
  "dependency_type": "FS",
  "lag_days": 2,
  "lag_hours": 0.0,
  "lag_type": "working_days",
  "description": "Заливка фундамента должна завершиться перед началом кладки стен",
  "is_hard_constraint": true,
  "priority": "high",
  "constraint_reason": "Технологический процесс",
  "advanced_settings": {
    "allow_split": false,
    "enforce_strict_timing": true
  }
}
```

### Обязательные поля
- `predecessor_task_id` - ID предшествующей задачи (должна существовать в данном расписании)
- `successor_task_id` - ID последующей задачи (должна существовать в данном расписании, не может совпадать с предшествующей)
- `dependency_type` - тип зависимости: `FS`, `SS`, `FF`, `SF`

### Опциональные поля
- `lag_days` - задержка в днях (может быть отрицательной)
- `lag_hours` - задержка в часах (от -999 до 999)
- `lag_type` - тип времени задержки: `working_days`, `calendar_days` (default: `working_days`)
- `description` - описание зависимости (max: 1000 символов)
- `is_hard_constraint` - жесткое ограничение (default: `false`)
- `priority` - приоритет: `low`, `normal`, `high`, `critical` (default: `normal`)
- `constraint_reason` - причина ограничения (max: 500 символов)
- `advanced_settings` - дополнительные настройки (объект)

### Типы зависимостей

| Код | Название | Описание |
|-----|----------|-----------|
| `FS` | Окончание → Начало | Задача Б может начаться только после окончания задачи А |
| `SS` | Начало → Начало | Задача Б может начаться только после начала задачи А |
| `FF` | Окончание → Окончание | Задача Б может закончиться только после окончания задачи А |
| `SF` | Начало → Окончание | Задача Б может закончиться только после начала задачи А |

### Ответы

**Успешное создание (201):**
```json
{
  "message": "Зависимость между задачами успешно создана",
  "data": {
    "id": 123,
    "dependency_type": "FS",
    "dependency_type_label": "Окончание - Начало",
    "lag_days": 2,
    "lag_hours": 0.0,
    "lag_type": "working_days",
    "description": "Заливка фундамента должна завершиться перед началом кладки стен",
    "is_hard_constraint": true,
    "priority": "high",
    "is_active": true,
    "validation_status": "valid",
    "created_at": "2025-01-30T14:42:08.000000Z",
    "predecessor_task": {
      "id": 15,
      "name": "Заливка фундамента",
      "planned_start_date": "2025-02-01",
      "planned_end_date": "2025-02-05"
    },
    "successor_task": {
      "id": 18,
      "name": "Кладка стен",
      "planned_start_date": "2025-02-08",
      "planned_end_date": "2025-02-15"
    },
    "created_by": {
      "id": 1,
      "name": "Администратор",
      "email": "admin@example.com"
    }
  }
}
```

**Ошибка валидации (400):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "predecessor_task_id": ["Предшествующая задача обязательна"],
    "successor_task_id": ["Последующая задача должна отличаться от предшествующей"],
    "dependency_type": ["Недопустимый тип зависимости. Допустимые: FS, SS, FF, SF"]
  }
}
```

**Расписание не найдено (404):**
```json
{
  "message": "График не найден"
}
```

**Циклическая зависимость (400):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "successor_task_id": ["Создание данной зависимости приведет к циклической зависимости"]
  }
}
```

**Дублирование зависимости (400):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "successor_task_id": ["Зависимость между этими задачами уже существует"]
  }
}
```

**Внутренняя ошибка (500):**
```json
{
  "message": "Ошибка при создании зависимости: {детали ошибки}"
}
```

### Примеры использования

**Создание простой зависимости Окончание-Начало:**
```bash
curl -X POST "https://api.prohelper.pro/api/v1/admin/schedules/2/dependencies" \
  -H "Authorization: Bearer your_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "predecessor_task_id": 15,
    "successor_task_id": 18,
    "dependency_type": "FS"
  }'
```

**Создание зависимости с задержкой:**
```bash
curl -X POST "https://api.prohelper.pro/api/v1/admin/schedules/2/dependencies" \
  -H "Authorization: Bearer your_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "predecessor_task_id": 15,
    "successor_task_id": 18,
    "dependency_type": "FS",
    "lag_days": 3,
    "lag_type": "working_days",
    "description": "Время на высыхание бетона",
    "is_hard_constraint": true,
    "priority": "critical"
  }'
```

**Создание параллельной зависимости:**
```bash
curl -X POST "https://api.prohelper.pro/api/v1/admin/schedules/2/dependencies" \
  -H "Authorization: Bearer your_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "predecessor_task_id": 12,
    "successor_task_id": 13,
    "dependency_type": "SS",
    "lag_days": 1,
    "description": "Работы могут выполняться параллельно с задержкой в 1 день"
  }'
```

## Получение списка зависимостей

**Эндпоинт**: `GET /api/v1/admin/schedules/{schedule_id}/dependencies`

**Описание**: Получить список всех зависимостей для указанного расписания.

### Параметры запроса

**Headers:**
```
Authorization: Bearer {jwt_token}
```

**URL параметры:**
- `schedule_id` (integer, required) - ID расписания

### Ответ

**Успешный ответ (200):**
```json
{
  "data": [
    {
      "id": 123,
      "predecessor_task_id": 15,
      "successor_task_id": 18,
      "dependency_type": "FS",
      "lag_days": 2,
      "lag_hours": 0.0,
      "is_critical": true,
      "is_active": true,
      "validation_status": "valid",
      "created_at": "2025-01-30T14:42:08.000000Z",
      "predecessor_task": {
        "id": 15,
        "name": "Заливка фундамента",
        "planned_start_date": "2025-02-01",
        "planned_end_date": "2025-02-05"
      },
      "successor_task": {
        "id": 18,
        "name": "Кладка стен",
        "planned_start_date": "2025-02-08",
        "planned_end_date": "2025-02-15"
      }
    }
  ]
}
```

### Пример использования

```bash
curl -X GET "https://api.prohelper.pro/api/v1/admin/schedules/2/dependencies" \
  -H "Authorization: Bearer your_jwt_token"
```

## Валидация зависимостей

### Автоматические проверки

1. **Проверка существования задач** - обе задачи должны существовать в базе данных
2. **Принадлежность к расписанию** - обе задачи должны принадлежать указанному расписанию
3. **Отличие задач** - предшествующая и последующая задачи должны быть разными
4. **Предотвращение циклов** - система проверяет простые циклические зависимости
5. **Уникальность** - между двумя задачами может быть только одна активная зависимость

### Бизнес-правила

- **Lag (задержка)** может быть отрицательной (опережение)
- **Жесткие ограничения** не могут быть изменены пользователем
- **Критические зависимости** влияют на критический путь проекта
- **Неактивные зависимости** игнорируются при расчетах

## Связанные эндпоинты

- `GET /api/v1/admin/schedules/{id}/tasks` - получить задачи расписания
- `POST /api/v1/admin/schedules/{id}/critical-path` - пересчитать критический путь
- `GET /api/v1/admin/schedules/{id}` - детали расписания
- `PUT /api/v1/admin/schedules/{id}/dependencies/{dependency_id}` - обновить зависимость (будет добавлено)
- `DELETE /api/v1/admin/schedules/{id}/dependencies/{dependency_id}` - удалить зависимость (будет добавлено)