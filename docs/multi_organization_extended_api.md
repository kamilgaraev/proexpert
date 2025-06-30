# Расширенный API Мульти-организации

## Новые эндпоинты

### Дашборд холдинга
`GET /api/v1/landing/multi-organization/dashboard`

### Управление дочерними организациями
- `GET /api/v1/landing/multi-organization/child-organizations` - список с фильтрацией
- `PUT /api/v1/landing/multi-organization/child-organizations/{id}` - редактирование
- `DELETE /api/v1/landing/multi-organization/child-organizations/{id}` - удаление
- `GET /api/v1/landing/multi-organization/child-organizations/{id}/stats` - статистика

### Управление пользователями дочерних организаций
- `GET /api/v1/landing/multi-organization/child-organizations/{id}/users` - список пользователей
- `POST /api/v1/landing/multi-organization/child-organizations/{id}/users` - добавление пользователя
- `PUT /api/v1/landing/multi-organization/child-organizations/{id}/users/{userId}` - редактирование пользователя
- `DELETE /api/v1/landing/multi-organization/child-organizations/{id}/users/{userId}` - удаление пользователя

### Настройки холдинга
- `PUT /api/v1/landing/multi-organization/holding-settings` - обновление настроек

## Ключевые особенности

✅ **Полный CRUD для дочерних организаций**
✅ **Управление пользователями с ролями и правами**  
✅ **Детальная статистика и аналитика**
✅ **Фильтрация, сортировка, пагинация**
✅ **Безопасное удаление с переносом данных**
✅ **Гибкие настройки холдинга**

## Примеры использования

### Получение списка дочерних организаций с фильтрацией:
```http
GET /api/v1/landing/multi-organization/child-organizations?search=строитель&status=active&sort_by=users_count&per_page=20
```

### Редактирование дочерней организации:
```http
PUT /api/v1/landing/multi-organization/child-organizations/124
Content-Type: application/json

{
  "name": "ООО Новый Строитель",
  "address": "г. Москва, ул. Новая, 10",
  "is_active": true
}
```

### Добавление пользователя в дочернюю организацию:
```http
POST /api/v1/landing/multi-organization/child-organizations/124/users
Content-Type: application/json

{
  "email": "manager@stroitel.ru",
  "role": "manager",
  "permissions": ["projects.read", "projects.create", "projects.edit"],
  "send_invitation": true
}
```

Полная документация включает детальные описания всех параметров, примеры ответов и коды ошибок. 