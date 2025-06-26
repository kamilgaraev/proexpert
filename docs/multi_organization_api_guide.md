# 📋 Инструкция по интеграции модуля мультиорганизации

## 🏗️ Архитектура системы

### Структура данных
```
Organization (Родительская) - is_holding: true
    ↓
OrganizationGroup - slug: "my-company" (поддомен my-company.prohelper.pro)
    ↓
Organization (Дочерние) - parent_organization_id: родительская
```

### Типы организаций
- `single` - обычная организация
- `parent` - холдинг (родительская)
- `child` - дочерняя организация

## 🔐 Авторизация и права доступа

### Необходимые заголовки для всех запросов
```http
Authorization: Bearer {JWT_TOKEN}
Content-Type: application/json
```

### Роли пользователей
- `organization_owner` - владелец организации (может создавать холдинги и дочерние организации)
- `organization_admin` - администратор организации
- `member` - обычный пользователь

## 📡 API Эндпоинты

### 1. Проверка доступности модуля

```http
GET /api/v1/landing/multi-organization/check-availability
```

**Ответ (модуль доступен):**
```json
{
  "success": true,
  "available": true,
  "can_create_holding": true,
  "current_type": "single",
  "is_holding": false
}
```

**Ответ (модуль не активирован):**
```json
{
  "success": false,
  "available": false,
  "message": "Модуль \"Мультиорганизация\" не активирован",
  "required_module": "multi_organization"
}
```

### 2. Создание холдинга

```http
POST /api/v1/landing/multi-organization/create-holding
```

**Права доступа:** `organization_owner`

**Тело запроса:**
```json
{
  "name": "Строительный холдинг АБВ",
  "description": "Группа строительных компаний",
  "max_child_organizations": 25,
  "settings": {
    "consolidated_reports": true,
    "shared_materials": false,
    "unified_billing": true
  },
  "permissions_config": {
    "default_child_permissions": {
      "projects": ["read", "create", "edit"],
      "contracts": ["read", "create"],
      "materials": ["read", "create"],
      "reports": ["read"],
      "users": ["read"]
    }
  }
}
```

**Валидация:**
- `name` - required|string|max:255
- `description` - nullable|string|max:1000
- `max_child_organizations` - sometimes|integer|min:1|max:50
- `settings` - sometimes|array
- `permissions_config` - sometimes|array

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Холдинг успешно создан",
  "data": {
    "id": 1,
    "name": "Строительный холдинг АБВ",
    "slug": "stroitelnyy-kholding-abv",
    "description": "Группа строительных компаний",
    "parent_organization_id": 123,
    "created_by_user_id": 456,
    "status": "active",
    "max_child_organizations": 25,
    "created_at": "2025-06-26T15:30:00.000000Z"
  }
}
```

### 3. Получение иерархии организаций

```http
GET /api/v1/landing/multi-organization/hierarchy
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "parent": {
      "id": 123,
      "name": "Строительный холдинг АБВ",
      "slug": "stroitelnyy-kholding-abv",
      "organization_type": "parent",
      "is_holding": true,
      "hierarchy_level": 0,
      "tax_number": "1234567890",
      "registration_number": "123456789",
      "address": "г. Москва, ул. Строительная, 1",
      "created_at": "2025-06-26T15:30:00.000000Z"
    },
    "children": [
      {
        "id": 124,
        "name": "ООО Строитель-1",
        "organization_type": "child",
        "is_holding": false,
        "hierarchy_level": 1,
        "tax_number": "9876543210",
        "created_at": "2025-06-26T16:00:00.000000Z"
      }
    ],
    "total_stats": {
      "total_organizations": 3,
      "total_users": 45,
      "total_projects": 12,
      "total_contracts": 8
    }
  }
}
```

### 4. Добавление дочерней организации

```http
POST /api/v1/landing/multi-organization/add-child
```

**Права доступа:** `organization_owner`

**Тело запроса:**
```json
{
  "group_id": 1,
  "name": "ООО Новый Строитель",
  "description": "Дочерняя строительная компания",
  "inn": "1234567890",
  "kpp": "123456789",
  "address": "г. Москва, ул. Дочерняя, 5",
  "phone": "+7 (495) 123-45-67",
  "email": "info@novyy-stroitel.ru"
}
```

**Валидация:**
- `group_id` - required|integer|exists:organization_groups,id
- `name` - required|string|max:255
- `description` - nullable|string|max:1000
- `inn` - nullable|string|max:12
- `kpp` - nullable|string|max:9
- `address` - nullable|string|max:500
- `phone` - nullable|string|max:20
- `email` - nullable|email|max:255

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Дочерняя организация успешно добавлена",
  "data": {
    "id": 126,
    "name": "ООО Новый Строитель",
    "description": "Дочерняя строительная компания",
    "parent_organization_id": 123,
    "organization_type": "child",
    "is_holding": false,
    "hierarchy_level": 1,
    "tax_number": "1234567890",
    "created_at": "2025-06-26T17:00:00.000000Z"
  }
}
```

### 5. Получение доступных организаций

```http
GET /api/v1/landing/multi-organization/accessible
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Строительный холдинг АБВ",
      "organization_type": "parent",
      "is_holding": true,
      "hierarchy_level": 0
    },
    {
      "id": 124,
      "name": "ООО Строитель-1",
      "organization_type": "child",
      "is_holding": false,
      "hierarchy_level": 1
    }
  ]
}
```

### 6. Получение данных конкретной организации

```http
GET /api/v1/landing/multi-organization/organization/{organizationId}
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "organization": {
      "id": 124,
      "name": "ООО Строитель-1",
      "organization_type": "child",
      "is_holding": false,
      "hierarchy_level": 1,
      "created_at": "2025-06-26T16:00:00.000000Z"
    },
    "stats": {
      "users_count": 15,
      "projects_count": 4,
      "contracts_count": 3,
      "active_contracts_value": 2500000
    },
    "recent_activity": {
      "last_project_created": "2025-06-25T14:30:00.000000Z",
      "last_contract_signed": "2025-06-24T10:15:00.000000Z",
      "last_user_added": "2025-06-23T09:00:00.000000Z"
    }
  }
}
```

### 7. Переключение контекста организации

```http
POST /api/v1/landing/multi-organization/switch-context
```

**Тело запроса:**
```json
{
  "organization_id": 124
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Контекст организации изменен",
  "current_organization_id": 124
}
```

## 🌐 Поддомены холдингов

### Структура поддоменов
После создания холдинга доступен поддомен:
```
https://{slug}.prohelper.pro/
```

### API эндпоинты для поддоменов

#### Главная страница холдинга (публичная)
```http
GET https://{slug}.prohelper.pro/
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "holding": {
      "id": 1,
      "name": "Строительный холдинг АБВ",
      "slug": "stroitelnyy-kholding-abv",
      "description": "Группа строительных компаний"
    },
    "stats": {
      "total_child_organizations": 2,
      "total_users": 45,
      "total_projects": 12,
      "total_contracts_value": 125000000
    }
  }
}
```

#### Панель управления холдингом (требует авторизации)
```http
GET https://{slug}.prohelper.pro/dashboard
Authorization: Bearer {JWT_TOKEN}
```

#### Список дочерних организаций
```http
GET https://{slug}.prohelper.pro/organizations
Authorization: Bearer {JWT_TOKEN}
```

## ⚠️ Обработка ошибок

### 403 Forbidden - Модуль не активирован
```json
{
  "success": false,
  "available": false,
  "message": "Модуль \"Мультиорганизация\" не активирован",
  "required_module": "multi_organization"
}
```

### 403 Forbidden - Нет прав доступа
```json
{
  "success": false,
  "message": "Нет прав для добавления дочерней организации"
}
```

### 400 Bad Request - Превышен лимит
```json
{
  "success": false,
  "message": "Достигнут лимит дочерних организаций"
}
```

### 422 Unprocessable Entity - Ошибки валидации
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email has already been taken."]
  }
}
```

## 🔄 Процесс интеграции (пошагово)

### Шаг 1: Проверка доступности модуля
1. Вызвать `GET /api/v1/landing/multi-organization/check-availability`
2. Если `available: false` - показать сообщение о необходимости активации модуля
3. Если `can_create_holding: false` - организация уже является холдингом

### Шаг 2: Создание холдинга
1. Показать форму создания холдинга
2. Отправить `POST /api/v1/landing/multi-organization/create-holding`
3. После успешного создания перенаправить на страницу управления

### Шаг 3: Управление дочерними организациями
1. Получить иерархию: `GET /api/v1/landing/multi-organization/hierarchy`
2. Отобразить список дочерних организаций
3. Для добавления новой использовать `POST /api/v1/landing/multi-organization/add-child`

### Шаг 4: Переключение между организациями
1. Получить доступные организации: `GET /api/v1/landing/multi-organization/accessible`
2. При переключении использовать: `POST /api/v1/landing/multi-organization/switch-context`

## 💡 Рекомендации по UX

1. **Индикация текущей организации** - всегда показывать контекст
2. **Быстрое переключение** - удобный способ переключения между организациями
3. **Визуальная иерархия** - четко показывать структуру холдинга
4. **Ограничения и лимиты** - показывать текущие лимиты и прогресс
5. **Консолидированная аналитика** - сводная информация по всему холдингу 