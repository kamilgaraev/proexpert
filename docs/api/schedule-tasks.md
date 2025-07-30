# API: Управление задачами расписания

## Создание задачи в расписании

**Эндпоинт**: `POST /api/v1/admin/schedules/{schedule_id}/tasks`

**Описание**: Создает новую задачу в указанном расписании проекта.

### Параметры запроса

**Headers:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Body (JSON):**
```json
{
  "name": "Заливка фундамента",
  "description": "Подготовка и заливка фундамента дома",
  "planned_start_date": "2025-02-01",
  "planned_end_date": "2025-02-05",
  "planned_duration_days": 5,
  "planned_work_hours": 40.0,
  "task_type": "task",
  "status": "not_started",
  "priority": "high",
  "estimated_cost": 150000.00,
  "work_type_id": 123,
  "assigned_user_id": 456,
  "parent_task_id": null,
  "wbs_code": "1.1",
  "required_resources": ["экскаватор", "бетономешалка"],
  "tags": ["фундамент", "строительство"],
  "notes": "Проверить прогноз погоды",
  "level": 1,
  "sort_order": 1
}
```

### Обязательные поля
- `name` - название задачи (max: 255 символов)
- `planned_start_date` - дата начала (формат: YYYY-MM-DD)
- `planned_end_date` - дата окончания (не раньше даты начала)
- `planned_duration_days` - длительность в днях (min: 1)

### Опциональные поля
- `description` - описание задачи (max: 5000 символов)
- `parent_task_id` - ID родительской задачи
- `work_type_id` - ID типа работ
- `assigned_user_id` - ID назначенного пользователя
- `task_type` - тип задачи: `task`, `milestone`, `summary`, `container` (default: `task`)
- `status` - статус: `not_started`, `in_progress`, `completed`, `cancelled`, `on_hold` (default: `not_started`)
- `priority` - приоритет: `low`, `normal`, `high`, `critical` (default: `normal`)
- `planned_work_hours` - планируемые трудозатраты в часах
- `estimated_cost` - предполагаемая стоимость
- `wbs_code` - код WBS (Work Breakdown Structure)
- `required_resources` - массив необходимых ресурсов
- `constraint_type` - тип ограничения
- `constraint_date` - дата ограничения
- `custom_fields` - объект с дополнительными полями
- `notes` - заметки (max: 2000 символов)
- `tags` - массив тегов
- `level` - уровень в иерархии (default: 0)
- `sort_order` - порядок сортировки (default: 0)

### Ответы

**Успешное создание (201):**
```json
{
  "message": "Задача успешно создана",
  "data": {
    "id": 789,
    "schedule_id": 2,
    "organization_id": 1,
    "name": "Заливка фундамента",
    "description": "Подготовка и заливка фундамента дома",
    "planned_start_date": "2025-02-01",
    "planned_end_date": "2025-02-05",
    "planned_duration_days": 5,
    "planned_work_hours": "40.00",
    "task_type": "task",
    "status": "not_started",
    "priority": "high",
    "estimated_cost": "150000.00",
    "created_at": "2025-01-30T14:18:20.000000Z",
    "updated_at": "2025-01-30T14:18:20.000000Z",
    "assigned_user": {
      "id": 456,
      "name": "Иван Петров",
      "email": "ivan@example.com"
    },
    "work_type": {
      "id": 123,
      "name": "Бетонные работы"
    },
    "parent_task": null
  }
}
```

**Ошибка валидации (400):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["Название задачи обязательно для заполнения"],
    "planned_start_date": ["Дата начала обязательна"],
    "planned_end_date": ["Дата окончания должна быть не раньше даты начала"]
  }
}
```

**График не найден (404):**
```json
{
  "message": "График не найден"
}
```

**Внутренняя ошибка (500):**
```json
{
  "message": "Ошибка при создании задачи: {детали ошибки}"
}
```

### Примеры использования

**Создание простой задачи:**
```bash
curl -X POST "https://api.prohelper.pro/api/v1/admin/schedules/2/tasks" \
  -H "Authorization: Bearer your_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Подготовка строительной площадки",
    "planned_start_date": "2025-02-01",
    "planned_end_date": "2025-02-02",
    "planned_duration_days": 2
  }'
```

**Создание задачи с полным набором полей:**
```bash
curl -X POST "https://api.prohelper.pro/api/v1/admin/schedules/2/tasks" \
  -H "Authorization: Bearer your_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Заливка фундамента",
    "description": "Подготовка и заливка фундамента дома площадью 150 кв.м",
    "planned_start_date": "2025-02-01",
    "planned_end_date": "2025-02-05",
    "planned_duration_days": 5,
    "planned_work_hours": 40.0,
    "task_type": "task",
    "status": "not_started", 
    "priority": "high",
    "estimated_cost": 150000.00,
    "work_type_id": 123,
    "assigned_user_id": 456,
    "wbs_code": "1.1",
    "required_resources": ["экскаватор", "бетономешалка"],
    "tags": ["фундамент", "строительство"],
    "notes": "Проверить прогноз погоды перед началом работ"
  }'
```

## Связанные эндпоинты

- `GET /api/v1/admin/schedules/{schedule_id}/tasks` - получить список задач расписания
- `GET /api/v1/admin/schedules/{schedule_id}` - получить детали расписания
- `PUT /api/v1/admin/schedules/{schedule_id}/tasks/{task_id}` - обновить задачу (будет добавлено)
- `DELETE /api/v1/admin/schedules/{schedule_id}/tasks/{task_id}` - удалить задачу (будет добавлено)