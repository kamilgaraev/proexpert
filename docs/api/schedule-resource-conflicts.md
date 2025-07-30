# API: Конфликты ресурсов в расписаниях

## Получение конфликтов ресурсов для конкретного расписания

**Эндпоинт**: `GET /api/v1/admin/schedules/{schedule_id}/resource-conflicts`

**Описание**: Получить информацию о конфликтах ресурсов в указанном расписании проекта.

### Параметры запроса

**Headers:**
```
Authorization: Bearer {jwt_token}
```

**URL параметры:**
- `schedule_id` (integer, required) - ID расписания

### Ответы

**Успешный ответ без конфликтов (200):**
```json
{
  "data": [],
  "meta": {
    "conflicts_count": 0,
    "has_conflicts": false,
    "message": "Конфликтов ресурсов не обнаружено"
  }
}
```

**Успешный ответ с конфликтами (200):**
```json
{
  "data": {
    "schedule_id": 2,
    "schedule_name": "График строительства дома",
    "conflicted_tasks": [
      {
        "id": 15,
        "name": "Заливка фундамента",
        "planned_start_date": "2025-02-01",
        "planned_end_date": "2025-02-05",
        "assigned_user": {
          "id": 123,
          "name": "Иван Петров"
        },
        "resources": [
          {
            "id": 45,
            "name": "Экскаватор JCB",
            "is_overallocated": true,
            "allocation_percentage": 150.0,
            "available_hours": 8,
            "required_hours": 12
          }
        ]
      }
    ],
    "conflicted_resources": [
      {
        "id": 45,
        "name": "Экскаватор JCB",
        "type": "equipment",
        "is_overallocated": true,
        "overallocated_days": ["2025-02-01", "2025-02-02"],
        "total_allocation": 150.0,
        "max_allocation": 100.0
      }
    ]
  },
  "meta": {
    "conflicts_count": 2,
    "has_conflicts": true,
    "message": "Обнаружены конфликты ресурсов"
  }
}
```

**Расписание не найдено (404):**
```json
{
  "message": "График не найден"
}
```

**Внутренняя ошибка (500):**
```json
{
  "message": "Ошибка при получении конфликтов ресурсов: {детали ошибки}"
}
```

### Пример использования

```bash
curl -X GET "https://api.prohelper.pro/api/v1/admin/schedules/2/resource-conflicts" \
  -H "Authorization: Bearer your_jwt_token"
```

---

## Получение всех расписаний с конфликтами ресурсов

**Эндпоинт**: `GET /api/v1/admin/schedules/resource-conflicts`

**Описание**: Получить список всех расписаний организации, в которых есть конфликты ресурсов.

### Параметры запроса

**Headers:**
```
Authorization: Bearer {jwt_token}
```

### Ответы

**Успешный ответ без конфликтов (200):**
```json
{
  "data": [],
  "meta": {
    "total_schedules_with_conflicts": 0,
    "message": "Конфликтов ресурсов не обнаружено"
  }
}
```

**Успешный ответ с конфликтами (200):**
```json
{
  "data": [
    {
      "id": 2,
      "name": "График строительства дома",
      "project_id": 15,
      "status": "active",
      "planned_start_date": "2025-02-01",
      "planned_end_date": "2025-12-31",
      "overall_progress_percent": 25.5,
      "critical_path_calculated": true,
      "critical_path_duration_days": 280,
      "tasks_count": 45,
      "dependencies_count": 38,
      "resources_count": 12,
      "project": {
        "id": 15,
        "name": "Строительство частного дома",
        "status": "active"
      },
      "created_by": {
        "id": 1,
        "name": "Администратор",
        "email": "admin@company.com"
      }
    },
    {
      "id": 7,
      "name": "Реконструкция офиса",
      "project_id": 22,
      "status": "active",
      "planned_start_date": "2025-03-01",
      "planned_end_date": "2025-06-30",
      "overall_progress_percent": 45.0,
      "critical_path_calculated": false,
      "tasks_count": 23,
      "dependencies_count": 18,
      "resources_count": 8
    }
  ],
  "meta": {
    "total_schedules_with_conflicts": 2,
    "message": "Найдены графики с конфликтами ресурсов"
  }
}
```

### Пример использования

```bash
curl -X GET "https://api.prohelper.pro/api/v1/admin/schedules/resource-conflicts" \
  -H "Authorization: Bearer your_jwt_token"
```

## Типы конфликтов ресурсов

### 1. Переаллокация ресурсов (Over-allocation)
Ресурс назначен на несколько задач одновременно, превышая его доступность:
- `allocation_percentage > 100%` 
- `required_hours > available_hours`

### 2. Конфликт времени (Time Conflict)
Ресурс назначен на пересекающиеся по времени задачи:
- Задачи выполняются в одно время
- Ресурс может работать только в одном месте

### 3. Недоступность ресурса (Resource Unavailability)
Ресурс не доступен в запланированное время:
- Ресурс в ремонте/обслуживании
- Отпуск/больничный для человеческих ресурсов
- Занят другими проектами

## Решение конфликтов

Для решения конфликтов ресурсов можно:

1. **Перепланировать задачи** - изменить даты начала/окончания
2. **Перераспределить ресурсы** - назначить другие доступные ресурсы
3. **Разделить работы** - разбить большие задачи на меньшие части
4. **Добавить ресурсы** - привлечь дополнительное оборудование/персонал
5. **Изменить последовательность** - поменять порядок выполнения задач

## Связанные эндпоинты

- `GET /api/v1/admin/schedules` - список всех расписаний
- `GET /api/v1/admin/schedules/{id}` - детали расписания
- `GET /api/v1/admin/schedules/{id}/tasks` - задачи расписания
- `POST /api/v1/admin/schedules/{id}/critical-path` - расчет критического пути
- `GET /api/v1/admin/schedules/overdue` - просроченные расписания