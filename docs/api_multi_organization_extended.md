# API Модуля "Мультиорганизация" - Расширенный функционал

## Обзор новых возможностей

Расширенная версия модуля "Мультиорганизация" включает полноценное управление дочерними организациями:

- ✅ **Полный CRUD для дочерних организаций** - создание, просмотр, редактирование, удаление
- ✅ **Управление пользователями дочерних организаций** - добавление, редактирование, удаление
- ✅ **Детальная статистика и аналитика** - по каждой дочерней организации
- ✅ **Гибкие настройки холдинга** - управление лимитами и правами доступа
- ✅ **Дашборд с ключевыми метриками** - общая сводка по холдингу
- ✅ **Фильтрация и сортировка** - удобный поиск организаций и пользователей

**Базовый URL:** `/api/v1/landing/multi-organization/`

---

## Управление дочерними организациями

### 1. Список дочерних организаций с фильтрацией

```http
GET /api/v1/landing/multi-organization/child-organizations
```

**Параметры запроса:**
- `search` - поиск по названию или ИНН
- `status` - фильтр по статусу (active, inactive, all)
- `sort_by` - сортировка (name, created_at, users_count, projects_count)
- `sort_direction` - направление сортировки (asc, desc)
- `per_page` - количество на странице (1-100)

**Пример запроса:**
```http
GET /api/v1/landing/multi-organization/child-organizations?search=строитель&status=active&sort_by=users_count&sort_direction=desc&per_page=20
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "organizations": [
      {
        "id": 124,
        "name": "ООО Строитель",
        "organization_type": "child",
        "hierarchy_level": 1,
        "tax_number": "1234567890",
        "registration_number": "123456789",
        "address": "г. Москва, ул. Строительная, 1",
        "phone": "+7 (495) 123-45-67",
        "email": "info@stroitel.ru",
        "users_count": 15,
        "projects_count": 8,
        "contracts_count": 5,
        "is_active": true,
        "created_at": "2024-01-15T10:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 20,
      "total": 45
    }
  }
}
```

### 2. Редактирование дочерней организации

```http
PUT /api/v1/landing/multi-organization/child-organizations/{childOrgId}
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "name": "ООО Новый Строитель",
  "description": "Обновленное описание компании",
  "inn": "9876543210",
  "kpp": "987654321",
  "address": "г. Москва, ул. Новая Строительная, 10",
  "phone": "+7 (495) 111-22-33",
  "email": "new@stroitel.ru",
  "is_active": true,
  "settings": {
    "notifications_enabled": true,
    "reports_frequency": "weekly",
    "auto_backup": true
  }
}
```

**Валидация:**
- `name` - sometimes|string|max:255
- `description` - nullable|string|max:1000
- `inn` - nullable|string|max:12
- `kpp` - nullable|string|max:9
- `address` - nullable|string|max:500
- `phone` - nullable|string|max:20
- `email` - nullable|email|max:255
- `is_active` - sometimes|boolean
- `settings` - sometimes|array

**Ответ:**
```json
{
  "success": true,
  "message": "Дочерняя организация обновлена",
  "data": {
    "id": 124,
    "name": "ООО Новый Строитель",
    "organization_type": "child",
    "hierarchy_level": 1,
    "tax_number": "9876543210",
    "registration_number": "987654321",
    "address": "г. Москва, ул. Новая Строительная, 10",
    "phone": "+7 (495) 111-22-33",
    "email": "new@stroitel.ru",
    "is_active": true,
    "created_at": "2024-01-15T10:00:00Z"
  }
}
```

### 3. Удаление дочерней организации

```http
DELETE /api/v1/landing/multi-organization/child-organizations/{childOrgId}
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "transfer_data_to": 125,
  "confirm_deletion": true
}
```

**Валидация:**
- `transfer_data_to` - nullable|integer|exists:organizations,id
- `confirm_deletion` - required|boolean|accepted

**Описание параметров:**
- `transfer_data_to` - ID организации для перевода данных (проекты, контракты, пользователи)
- `confirm_deletion` - подтверждение удаления (должно быть `true`)

**Ответ:**
```json
{
  "success": true,
  "message": "Дочерняя организация удалена"
}
```

**Примечания:**
- При наличии пользователей, обязательно указание `transfer_data_to`
- Все проекты и контракты переносятся в указанную организацию
- Пользователи добавляются в целевую организацию с ролью "employee"

### 4. Детальная статистика дочерней организации

```http
GET /api/v1/landing/multi-organization/child-organizations/{childOrgId}/stats
```

**Права доступа:** Владелец организации

**Ответ:**
```json
{
  "success": true,
  "data": {
    "users": {
      "total": 15,
      "active": 13,
      "owners": 2,
      "managers": 4,
      "employees": 9
    },
    "projects": {
      "total": 8,
      "active": 5,
      "completed": 2,
      "cancelled": 1,
      "total_budget": 2500000.00,
      "completed_budget": 800000.00
    },
    "contracts": {
      "total": 5,
      "active": 3,
      "completed": 2,
      "total_value": 1800000.00,
      "active_value": 1200000.00,
      "monthly_income": 150000.00
    },
    "financial": {
      "current_balance": 350000.00,
      "monthly_expenses": 85000.00,
      "profit_margin": 12.5,
      "cash_flow": 65000.00
    },
    "activity": {
      "last_login": "2024-01-20T14:30:00Z",
      "new_projects_this_month": 2,
      "completed_tasks_this_week": 15,
      "active_user_sessions": 8
    }
  }
}
```

---

## Управление пользователями дочерних организаций

### 5. Список пользователей дочерней организации

```http
GET /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users
```

**Параметры запроса:**
- `search` - поиск по имени или email
- `role` - фильтр по роли (admin, manager, employee)
- `status` - фильтр по статусу (active, inactive, all)
- `per_page` - количество на странице (1-50)

**Пример запроса:**
```http
GET /api/v1/landing/multi-organization/child-organizations/124/users?search=иван&role=manager&status=active&per_page=15
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "id": 15,
        "name": "Иван Петров",
        "email": "ivan.petrov@stroitel.ru",
        "is_owner": false,
        "is_active": true,
        "role": "manager",
        "permissions": [
          "projects.read",
          "projects.create",
          "projects.edit",
          "contracts.read",
          "users.read"
        ],
        "last_login": "2024-01-20T09:15:00Z",
        "joined_at": "2024-01-16T09:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 8
    }
  }
}
```

### 6. Добавление пользователя в дочернюю организацию

```http
POST /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "email": "new.manager@stroitel.ru",
  "role": "manager",
  "permissions": [
    "projects.read",
    "projects.create",
    "projects.edit",
    "contracts.read",
    "users.read",
    "materials.read",
    "materials.create"
  ],
  "send_invitation": true
}
```

**Валидация:**
- `email` - required|email|exists:users,email
- `role` - required|string|in:admin,manager,employee
- `permissions` - sometimes|array
- `send_invitation` - sometimes|boolean

**Описание ролей:**
- `admin` - Владелец организации (полные права)
- `manager` - Менеджер (управление проектами, пользователями)
- `employee` - Сотрудник (базовые права)

**Ответ:**
```json
{
  "success": true,
  "message": "Пользователь добавлен в дочернюю организацию",
  "data": {
    "id": 20,
    "name": "Новый Менеджер",
    "email": "new.manager@stroitel.ru",
    "role": "manager",
    "is_active": true,
    "permissions": [
      "projects.read",
      "projects.create",
      "projects.edit",
      "contracts.read",
      "users.read",
      "materials.read",
      "materials.create"
    ]
  }
}
```

### 7. Редактирование пользователя дочерней организации

```http
PUT /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/{userId}
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "role": "admin",
  "permissions": [
    "projects.read",
    "projects.create",
    "projects.edit",
    "projects.delete",
    "contracts.read",
    "contracts.create",
    "contracts.edit",
    "users.read",
    "users.create",
    "users.edit",
    "materials.read",
    "materials.create",
    "materials.edit",
    "reports.read",
    "reports.export"
  ],
  "is_active": true
}
```

**Валидация:**
- `role` - sometimes|string|in:admin,manager,employee
- `permissions` - sometimes|array
- `is_active` - sometimes|boolean

**Ответ:**
```json
{
  "success": true,
  "message": "Данные пользователя обновлены",
  "data": {
    "id": 20,
    "name": "Пользователь",
    "email": "user@stroitel.ru",
    "role": "admin",
    "is_active": true,
    "permissions": [
      "projects.read",
      "projects.create",
      "projects.edit",
      "projects.delete",
      "contracts.read",
      "contracts.create",
      "contracts.edit",
      "users.read",
      "users.create",
      "users.edit",
      "materials.read",
      "materials.create",
      "materials.edit",
      "reports.read",
      "reports.export"
    ]
  }
}
```

### 8. Удаление пользователя из дочерней организации

```http
DELETE /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/{userId}
```

**Права доступа:** Владелец организации

**Ответ:**
```json
{
  "success": true,
  "message": "Пользователь исключен из дочерней организации"
}
```

**Ограничения:**
- Нельзя удалить единственного владельца организации
- При удалении владельца должен остаться хотя бы один другой владелец

---

## Настройки холдинга

### 9. Обновление настроек холдинга

```http
PUT /api/v1/landing/multi-organization/holding-settings
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "group_id": 1,
  "name": "Строительный Холдинг «Новая Эра»",
  "description": "Ведущая группа строительных компаний с полным циклом услуг",
  "max_child_organizations": 50,
  "settings": {
    "consolidated_reports": true,
    "shared_materials": true,
    "auto_backup": true,
    "notification_frequency": "daily",
    "data_retention_days": 365,
    "allow_cross_organization_projects": false
  },
  "permissions_config": {
    "default_child_permissions": {
      "projects": ["read", "create", "edit"],
      "contracts": ["read", "create"],
      "materials": ["read", "create", "edit"],
      "reports": ["read", "export"],
      "users": ["read"]
    },
    "parent_permissions": {
      "projects": ["read", "create", "edit", "delete"],
      "contracts": ["read", "create", "edit", "delete"],
      "materials": ["read", "create", "edit", "delete"],
      "reports": ["read", "export", "admin"],
      "users": ["read", "create", "edit", "delete"]
    },
    "cross_organization_access": {
      "allow_data_sharing": true,
      "require_approval": true
    }
  }
}
```

**Валидация:**
- `group_id` - required|integer|exists:organization_groups,id
- `name` - sometimes|string|max:255
- `description` - nullable|string|max:1000
- `max_child_organizations` - sometimes|integer|min:1|max:100
- `settings` - sometimes|array
- `permissions_config` - sometimes|array

**Ответ:**
```json
{
  "success": true,
  "message": "Настройки холдинга обновлены",
  "data": {
    "id": 1,
    "name": "Строительный Холдинг «Новая Эра»",
    "slug": "stroitelnyy-holding-novaya-era",
    "description": "Ведущая группа строительных компаний с полным циклом услуг",
    "parent_organization_id": 123,
    "max_child_organizations": 50,
    "current_child_count": 8,
    "status": "active",
    "settings": {
      "consolidated_reports": true,
      "shared_materials": true,
      "auto_backup": true,
      "notification_frequency": "daily",
      "data_retention_days": 365,
      "allow_cross_organization_projects": false
    },
    "updated_at": "2024-01-20T15:30:00Z"
  }
}
```

---

## Массовые операции

### 10. Массовое обновление статуса дочерних организаций

```http
PATCH /api/v1/landing/multi-organization/child-organizations/bulk-update
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "organization_ids": [124, 125, 126],
  "updates": {
    "is_active": true,
    "settings": {
      "notifications_enabled": true
    }
  }
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Обновлено организаций: 3",
  "data": {
    "updated_count": 3,
    "failed_count": 0,
    "updated_organizations": [124, 125, 126],
    "failed_organizations": []
  }
}
```

### 11. Экспорт данных дочерних организаций

```http
GET /api/v1/landing/multi-organization/child-organizations/export
```

**Параметры запроса:**
- `format` - формат экспорта (xlsx, csv, pdf)
- `include_stats` - включить статистику (true/false)
- `organization_ids` - конкретные организации (массив ID)

**Пример запроса:**
```http
GET /api/v1/landing/multi-organization/child-organizations/export?format=xlsx&include_stats=true&organization_ids[]=124&organization_ids[]=125
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "download_url": "/downloads/child-organizations-export-20240120.xlsx",
    "expires_at": "2024-01-21T15:30:00Z",
    "file_size": 245760,
    "records_count": 25
  }
}
```

---

## Аналитика и отчеты

### 12. Сводный отчет по холдингу

```http
GET /api/v1/landing/multi-organization/analytics/summary
```

**Параметры запроса:**
- `period` - период анализа (week, month, quarter, year)
- `include_trends` - включить тренды (true/false)

**Ответ:**
```json
{
  "success": true,
  "data": {
    "period": "month",
    "summary": {
      "total_organizations": 8,
      "active_organizations": 7,
      "total_users": 156,
      "active_users": 142,
      "total_projects": 45,
      "active_projects": 28,
      "total_revenue": 15750000.00,
      "total_expenses": 12200000.00,
      "profit": 3550000.00,
      "profit_margin": 22.5
    },
    "trends": {
      "organizations_growth": 12.5,
      "users_growth": 8.3,
      "projects_growth": 15.2,
      "revenue_growth": 18.7
    },
    "top_performing_organizations": [
      {
        "id": 124,
        "name": "ООО Строитель",
        "revenue": 2500000.00,
        "projects_completed": 8,
        "efficiency_score": 95.2
      }
    ],
    "areas_for_improvement": [
      {
        "organization_id": 127,
        "organization_name": "ООО Медленный Строитель",
        "issue": "low_project_completion_rate",
        "recommendation": "Увеличить количество активных менеджеров"
      }
    ]
  }
}
```

---

## Системы разрешений

### Доступные разрешения:

**Проекты (projects):**
- `read` - просмотр проектов
- `create` - создание проектов
- `edit` - редактирование проектов
- `delete` - удаление проектов

**Контракты (contracts):**
- `read` - просмотр контрактов
- `create` - создание контрактов
- `edit` - редактирование контрактов
- `delete` - удаление контрактов

**Материалы (materials):**
- `read` - просмотр материалов
- `create` - создание материалов
- `edit` - редактирование материалов
- `delete` - удаление материалов

**Пользователи (users):**
- `read` - просмотр пользователей
- `create` - добавление пользователей
- `edit` - редактирование пользователей
- `delete` - удаление пользователей

**Отчеты (reports):**
- `read` - просмотр отчетов
- `export` - экспорт отчетов
- `admin` - административные отчеты

---

## Примеры использования

### Создание полноценной дочерней организации:

```javascript
// 1. Создание дочерней организации
const createChildOrg = async () => {
  const response = await fetch('/api/v1/landing/multi-organization/add-child', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      group_id: 1,
      name: 'ООО Новая Строительная',
      description: 'Специализация на жилищном строительстве',
      inn: '1234567890',
      kpp: '123456789',
      address: 'г. Москва, ул. Новая, 15',
      phone: '+7 (495) 123-45-67',
      email: 'info@novstroi.ru'
    })
  });
  
  const org = await response.json();
  return org.data;
};

// 2. Добавление администратора
const addAdmin = async (orgId) => {
  return fetch(`/api/v1/landing/multi-organization/child-organizations/${orgId}/users`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'admin@novstroi.ru',
      role: 'admin',
      permissions: ['projects.read', 'projects.create', 'projects.edit', 'projects.delete',
                   'contracts.read', 'contracts.create', 'contracts.edit',
                   'users.read', 'users.create', 'users.edit',
                   'materials.read', 'materials.create', 'materials.edit',
                   'reports.read', 'reports.export'],
      send_invitation: true
    })
  });
};

// 3. Полная настройка организации
const setupOrganization = async () => {
  const org = await createChildOrg();
  await addAdmin(org.id);
  
  console.log(`Организация ${org.name} создана и настроена`);
};
```

### Мониторинг активности дочерних организаций:

```javascript
const monitorChildOrganizations = async () => {
  // Получение списка всех дочерних организаций
  const response = await fetch('/api/v1/landing/multi-organization/child-organizations?per_page=100');
  const data = await response.json();
  
  // Проверка статистики каждой организации
  for (const org of data.data.organizations) {
    const statsResponse = await fetch(`/api/v1/landing/multi-organization/child-organizations/${org.id}/stats`);
    const stats = await statsResponse.json();
    
    // Анализ производительности
    if (stats.data.projects.active === 0) {
      console.warn(`Организация ${org.name} не имеет активных проектов`);
    }
    
    if (stats.data.users.active < 3) {
      console.warn(`Организация ${org.name} имеет мало активных пользователей`);
    }
    
    if (stats.data.financial.profit_margin < 10) {
      console.warn(`Организация ${org.name} имеет низкую прибыльность`);
    }
  }
};
```

---

## Безопасность и ограничения

### Ограничения API:
- Максимум 100 дочерних организаций на холдинг
- Максимум 50 пользователей на запрос при фильтрации
- Лимит 1000 запросов в час на пользователя
- Размер экспортируемых файлов до 50MB

### Аудит действий:
Все операции с дочерними организациями логируются:
- Создание/изменение/удаление организаций
- Добавление/изменение/удаление пользователей
- Изменение настроек холдинга
- Экспорт данных

### Резервное копирование:
- Автоматическое резервное копирование настроек холдинга
- Возможность восстановления удаленных организаций в течение 30 дней
- Архивирование данных неактивных организаций 