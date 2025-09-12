# Система управления пользователями с кастомными ролями

## Обзор

Система управления пользователями была обновлена для интеграции с новой системой авторизации, которая поддерживает кастомные роли организаций. Теперь владельцы организаций могут создавать собственные роли с индивидуальными наборами прав и назначать их пользователям.

## Основные API эндпоинты

### Управление кастомными ролями

#### Просмотр ролей
- `GET /api/v1/landing/user-management/custom-roles` - Список всех ролей организации
- `GET /api/v1/landing/user-management/custom-roles/{role}` - Детали конкретной роли
- `GET /api/v1/landing/user-management/custom-roles/{role}/users` - Пользователи с данной ролью

#### Создание и управление ролями
- `POST /api/v1/landing/user-management/custom-roles` - Создать новую роль
- `PUT /api/v1/landing/user-management/custom-roles/{role}` - Обновить роль
- `DELETE /api/v1/landing/user-management/custom-roles/{role}` - Удалить роль
- `POST /api/v1/landing/user-management/custom-roles/{role}/clone` - Клонировать роль

#### Назначение ролей
- `POST /api/v1/landing/user-management/custom-roles/{role}/assign` - Назначить роль пользователю
- `DELETE /api/v1/landing/user-management/custom-roles/{role}/unassign` - Отозвать роль

### Управление пользователями

#### Создание пользователей с кастомными ролями
- `POST /api/v1/landing/user-management/create-user-with-custom-roles` - Создать пользователя с назначением кастомных ролей

Параметры запроса:
```json
{
  "name": "string",
  "email": "string",
  "password": "string",
  "password_confirmation": "string",
  "custom_role_ids": [1, 2, 3],
  "send_credentials": false
}
```

#### Управление ролями существующих пользователей
- `GET /api/v1/landing/user-management/organization-users/{userId}/roles` - Получить роли пользователя
- `POST /api/v1/landing/user-management/organization-users/{userId}/roles` - Обновить роли пользователя
- `POST /api/v1/landing/user-management/organization-users/{userId}/assign-role/{roleId}` - Назначить роль
- `DELETE /api/v1/landing/user-management/organization-users/{userId}/unassign-role/{roleId}` - Отозвать роль

### Вспомогательные эндпоинты

#### Получение доступных ролей и прав
- `GET /api/v1/landing/user-management/available-roles` - Список всех доступных ролей (системные + кастомные)
- `GET /api/v1/landing/user-management/available-permissions` - Доступные права для создания ролей
- `GET /api/v1/landing/user-management/custom-roles/permissions/available` - Права для кастомных ролей

## Структура кастомных ролей

Кастомные роли хранятся в таблице `organization_custom_roles` и имеют следующую структуру:

```json
{
  "id": 1,
  "organization_id": 123,
  "name": "Менеджер проектов",
  "slug": "project-manager-custom",
  "description": "Управление проектами и командой",
  "system_permissions": [
    "projects.view",
    "projects.create",
    "users.view"
  ],
  "module_permissions": {
    "projects": ["manage", "assign_users"],
    "reports": ["view", "export"]
  },
  "interface_access": ["lk", "admin"],
  "conditions": {
    "project_access": "assigned_only"
  },
  "is_active": true,
  "created_by": 456
}
```

## Права доступа

### Для просмотра ролей
- `roles.view_custom,organization` - Просмотр кастомных ролей организации

### Для создания ролей
- `roles.create_custom,organization` - Создание новых кастомных ролей

### Для управления ролями
- `roles.manage_custom,organization` - Редактирование и удаление кастомных ролей

### Для управления пользователями
- `users.manage,organization` - Управление пользователями организации
- `users.manage_roles,organization` - Назначение и отзыв ролей пользователей
- `users.invite,organization` - Приглашение новых пользователей

## Примеры использования

### 1. Создание кастомной роли "Бухгалтер проектов"

```bash
POST /api/v1/landing/user-management/custom-roles
Content-Type: application/json

{
  "name": "Бухгалтер проектов",
  "description": "Ведение финансовой отчетности по проектам",
  "system_permissions": [
    "billing.view",
    "reports.view",
    "projects.view"
  ],
  "module_permissions": {
    "billing": ["view", "export"],
    "reports": ["view", "create", "export"]
  },
  "interface_access": ["lk", "admin"],
  "conditions": {
    "project_access": "financial_only"
  }
}
```

### 2. Создание пользователя с назначением кастомных ролей

```bash
POST /api/v1/landing/user-management/create-user-with-custom-roles
Content-Type: application/json

{
  "name": "Анна Иванова",
  "email": "anna@company.com",
  "password": "SecurePassword123",
  "password_confirmation": "SecurePassword123",
  "custom_role_ids": [15, 23],
  "send_credentials": true
}
```

### 3. Получение доступных ролей для селекта

```bash
GET /api/v1/landing/user-management/available-roles

Response:
{
  "success": true,
  "data": {
    "system_roles": [
      "organization_owner",
      "organization_admin",
      "project_manager",
      "accountant"
    ],
    "custom_roles": [
      {
        "id": 15,
        "name": "Бухгалтер проектов",
        "slug": "project-accountant-custom",
        "description": "Ведение финансовой отчетности по проектам",
        "is_active": true
      }
    ],
    "organization_id": 123
  }
}
```

## Интеграция с существующими системами

Новая система полностью совместима с существующими API для создания пользователей админ-панели через `AdminPanelUserController`. При создании пользователей теперь можно использовать как системные роли (через `role_slug`), так и кастомные роли.

## Безопасность

- Все операции с ролями требуют соответствующих прав доступа
- Владельцы организаций могут создавать роли только в рамках доступных им прав
- Кастомные роли не могут превышать права создателя
- Проверка контекста организации обязательна для всех операций

## Миграция

Существующие пользователи с системными ролями продолжают работать без изменений. Новая система дополняет, а не заменяет существующую систему ролей.
