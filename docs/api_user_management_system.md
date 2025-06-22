# API Системы Управления Пользователями

## Обзор

Гибкая система управления пользователями в личном кабинете с поддержкой:
- Кастомных ролей организации
- Гранулярных разрешений
- Системы приглашений
- Лимитов подписки
- Управления пользователями

## Архитектура

### Компоненты системы

1. **OrganizationRole** - Кастомные роли организации
2. **UserInvitation** - Система приглашений
3. **Subscription Limits** - Контроль лимитов
4. **Permission System** - Гранулярные права доступа

### Базовые URL

```
/api/v1/landing/user-management/
```

## Управление Ролями

### 1. Получение списка ролей

```http
GET /api/v1/landing/user-management/roles
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "custom_roles": [
      {
        "id": 1,
        "name": "Менеджер проектов",
        "slug": "project_manager",
        "description": "Управление проектами и задачами",
        "color": "#3B82F6",
        "is_active": true,
        "is_system": false,
        "display_order": 1,
        "permissions": ["projects.view", "projects.edit", "users.view"],
        "permissions_formatted": {
          "Проекты": [
            {
              "slug": "projects.view",
              "name": "Просмотр проектов",
              "granted": true
            }
          ]
        },
        "users_count": 3,
        "created_at": "2024-01-15T10:30:00Z"
      }
    ],
    "system_roles": [
      {
        "slug": "organization_owner",
        "name": "Владелец организации",
        "description": "Полные права в организации",
        "color": "#DC2626",
        "is_system": true,
        "permissions": ["*"]
      }
    ]
  }
}
```

### 2. Создание роли

```http
POST /api/v1/landing/user-management/roles
```

**Тело запроса:**
```json
{
  "name": "Менеджер проектов",
  "slug": "project_manager",
  "description": "Управление проектами и задачами",
  "permissions": [
    "projects.view",
    "projects.edit",
    "projects.create",
    "users.view"
  ],
  "color": "#3B82F6",
  "is_active": true,
  "display_order": 1
}
```

**Валидация:**
- `name`: обязательно, строка, 2-100 символов
- `slug`: опционально, уникальный в рамках организации
- `permissions`: обязательно, массив допустимых разрешений
- `color`: опционально, HEX формат
- `is_active`: опционально, boolean
- `display_order`: опционально, integer ≥ 0

### 3. Получение доступных разрешений

```http
GET /api/v1/landing/user-management/roles/permissions/available
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "Пользователи": [
      {
        "slug": "users.view",
        "name": "Просмотр пользователей",
        "description": "Просмотр списка пользователей организации",
        "group": "Пользователи"
      }
    ],
    "Проекты": [
      {
        "slug": "projects.view",
        "name": "Просмотр проектов",
        "description": "Просмотр списка проектов",
        "group": "Проекты"
      }
    ]
  }
}
```

### 4. Назначение роли пользователю

```http
POST /api/v1/landing/user-management/roles/{roleId}/assign-user
```

**Тело запроса:**
```json
{
  "user_id": 123
}
```

### 5. Копирование роли

```http
POST /api/v1/landing/user-management/roles/{roleId}/duplicate
```

**Тело запроса:**
```json
{
  "name": "Копия роли менеджера"
}
```

## Система Приглашений

### 1. Создание приглашения

```http
POST /api/v1/landing/user-management/invitations
```

**Тело запроса:**
```json
{
  "email": "user@example.com",
  "name": "Иван Петров",
  "role_slugs": ["organization_admin", "foreman"],
  "metadata": {
    "welcome_message": "Добро пожаловать в команду!",
    "department": "Строительный отдел"
  }
}
```

**Валидация:**
- Проверка лимитов подписки
- Уникальность email в организации
- Отсутствие активных приглашений

### 2. Получение списка приглашений

```http
GET /api/v1/landing/user-management/invitations?status=pending&email=user@example.com
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "email": "user@example.com",
      "name": "Иван Петров",
      "role_slugs": ["organization_admin"],
      "role_names": ["Администратор"],
      "status": "pending",
      "status_text": "Ожидает",
      "status_color": "yellow",
      "expires_at": "2024-01-22T10:30:00Z",
      "is_expired": false,
      "can_be_accepted": true,
      "invited_by": {
        "id": 1,
        "name": "Владелец Организации",
        "email": "owner@company.com"
      },
      "created_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

### 3. Принятие приглашения

```http
POST /api/v1/landing/user-management/invitation/{token}/accept
```

**Тело запроса (для нового пользователя):**
```json
{
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

### 4. Статистика приглашений

```http
GET /api/v1/landing/user-management/invitations/stats/overview
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "total": 25,
    "pending": 5,
    "accepted": 18,
    "expired": 2,
    "acceptance_rate": 72.0
  }
}
```

## Управление Пользователями Организации

### 1. Список пользователей

```http
GET /api/v1/landing/user-management/organization-users
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Иван Петров",
      "email": "ivan@example.com",
      "status": "active",
      "user_type": "organization_admin",
      "last_login_at": "2024-01-15T08:30:00Z",
      "roles": [
        {
          "id": 1,
          "name": "Администратор",
          "slug": "organization_admin",
          "color": "#EA580C"
        }
      ],
      "custom_roles": [
        {
          "id": 5,
          "name": "Менеджер проектов",
          "slug": "project_manager",
          "color": "#3B82F6"
        }
      ],
      "permissions": ["users.view", "projects.edit"],
      "created_at": "2024-01-10T12:00:00Z"
    }
  ]
}
```

### 2. Обновление ролей пользователя

```http
POST /api/v1/landing/user-management/organization-users/{userId}/roles
```

**Тело запроса:**
```json
{
  "system_roles": ["organization_admin"],
  "custom_roles": [5, 7],
  "action": "replace"
}
```

## Лимиты Подписки

### 1. Проверка лимитов

```http
GET /api/v1/landing/user-management/user-limits
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "has_subscription": true,
    "limits": {
      "users": {
        "limit": 50,
        "used": 23,
        "remaining": 27,
        "percentage_used": 46.0,
        "is_unlimited": false
      },
      "foremen": {
        "limit": 10,
        "used": 3,
        "remaining": 7,
        "percentage_used": 30.0,
        "is_unlimited": false
      }
    },
    "warnings": [
      {
        "type": "approaching_limit",
        "resource": "users",
        "message": "Приближаетесь к лимиту пользователей"
      }
    ]
  }
}
```

## Разрешения и Права

### Доступные разрешения

#### Пользователи
- `users.view` - Просмотр пользователей
- `users.create` - Создание пользователей
- `users.edit` - Редактирование пользователей
- `users.delete` - Удаление пользователей

#### Роли
- `roles.view` - Просмотр ролей
- `roles.create` - Создание ролей
- `roles.edit` - Редактирование ролей
- `roles.delete` - Удаление ролей

#### Проекты
- `projects.view` - Просмотр проектов
- `projects.create` - Создание проектов
- `projects.edit` - Редактирование проектов
- `projects.delete` - Удаление проектов

#### Договоры
- `contracts.view` - Просмотр договоров
- `contracts.create` - Создание договоров
- `contracts.edit` - Редактирование договоров
- `contracts.delete` - Удаление договоров

#### Материалы
- `materials.view` - Просмотр материалов
- `materials.create` - Создание материалов
- `materials.edit` - Редактирование материалов
- `materials.delete` - Удаление материалов

#### Отчеты
- `reports.view` - Просмотр отчетов
- `reports.export` - Экспорт отчетов

#### Финансы
- `finance.view` - Просмотр финансов
- `finance.manage` - Управление финансами

#### Настройки
- `settings.view` - Просмотр настроек
- `settings.edit` - Редактирование настроек

## Примеры использования

### JavaScript/Frontend

```javascript
// Создание роли
const createRole = async (roleData) => {
  const response = await fetch('/api/v1/landing/user-management/roles', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(roleData)
  });
  return response.json();
};

// Отправка приглашения
const sendInvitation = async (invitationData) => {
  const response = await fetch('/api/v1/landing/user-management/invitations', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(invitationData)
  });
  return response.json();
};

// Проверка лимитов перед созданием пользователя
const checkLimits = async () => {
  const response = await fetch('/api/v1/landing/user-management/user-limits', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  const data = await response.json();
  
  if (data.data.limits.users.remaining <= 0) {
    alert('Достигнут лимит пользователей по тарифному плану');
    return false;
  }
  return true;
};
```

## Коды Ошибок

- `400` - Неверный запрос
- `401` - Не авторизован
- `403` - Нет прав доступа
- `404` - Ресурс не найден
- `409` - Конфликт (дублирование)
- `422` - Ошибка валидации
- `429` - Превышен лимит запросов
- `500` - Внутренняя ошибка сервера

### Специфичные ошибки

```json
{
  "success": false,
  "error": "Достигнут лимит пользователей по вашему тарифному плану",
  "error_code": "SUBSCRIPTION_LIMIT_EXCEEDED",
  "data": {
    "current_limit": 10,
    "current_usage": 10
  }
}
```

## Рекомендации по UI/UX

### Конструктор ролей
- Группировка разрешений по категориям
- Визуальные индикаторы (иконки, цвета)
- Предварительный просмотр роли
- Шаблоны ролей

### Система приглашений
- Отслеживание статуса приглашений
- Автоматическое напоминание
- Массовая отправка приглашений
- История приглашений

### Управление лимитами
- Индикаторы использования лимитов
- Предупреждения при приближении к лимиту
- Предложения по апгрейду тарифа
- Детальная аналитика использования

### Безопасность
- Двухфакторная аутентификация
- Аудит действий пользователей
- Временные роли
- Автоматическая блокировка неактивных пользователей 