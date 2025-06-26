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
      "slug": "stroitelnyy-kholding-abv",
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

Фронтенд находится на ЛК сервере (89.111.152.112), а данные получает через API запросы к API серверу (89.111.153.146).

### API эндпоинты для фронтенда холдингов

**Базовый URL для API:** `https://api.prohelper.pro/api/v1/holding-api/`

#### 1. Публичные данные холдинга
```http
GET https://api.prohelper.pro/api/v1/holding-api/{slug}
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
      "description": "Группа строительных компаний",
      "parent_organization_id": 123,
      "status": "active",
      "created_at": "2025-06-26T15:30:00.000000Z"
    },
    "parent_organization": {
      "id": 123,
      "name": "Строительный холдинг АБВ",
      "legal_name": "ООО \"Строительный холдинг АБВ\"",
      "tax_number": "1234567890",
      "registration_number": "123456789",
      "address": "г. Москва, ул. Строительная, 1",
      "phone": "+7 (495) 123-45-67",
      "email": "info@holding-abv.ru",
      "city": "Москва",
      "description": "Ведущий холдинг в сфере строительства"
    },
    "stats": {
      "total_child_organizations": 2,
      "total_users": 45,
      "total_projects": 12,
      "total_contracts": 8,
      "total_contracts_value": 125000000,
      "active_contracts_count": 6
    }
  }
}
```

#### 2. Панель управления холдингом (требует авторизации)
```http
GET https://api.prohelper.pro/api/v1/holding-api/{slug}/dashboard
Authorization: Bearer {JWT_TOKEN}
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
      "description": "Группа строительных компаний",
      "parent_organization_id": 123,
      "status": "active"
    },
    "hierarchy": {
      "parent": {...},
      "children": [...],
      "total_stats": {...}
    },
    "user": {
      "id": 456,
      "name": "Иван Иванов",
      "email": "ivan@example.com"
    },
    "consolidated_stats": {
      "total_child_organizations": 2,
      "total_users": 45,
      "total_projects": 12,
      "total_contracts": 8,
      "total_contracts_value": 125000000,
      "active_contracts_count": 6,
      "recent_activity": [
        {
          "type": "project_created",
          "organization_name": "ООО Строитель-1",
          "description": "Создан проект: Жилой комплекс",
          "date": "2025-06-25T14:30:00.000000Z"
        }
      ],
      "performance_metrics": {
        "monthly_growth": 0,
        "efficiency_score": 0,
        "satisfaction_index": 0
      }
    }
  }
}
```

#### 3. Список дочерних организаций
```http
GET https://api.prohelper.pro/api/v1/holding-api/{slug}/organizations
Authorization: Bearer {JWT_TOKEN}
```

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 124,
      "name": "ООО Строитель-1",
      "description": "Дочерняя строительная компания",
      "organization_type": "child",
      "hierarchy_level": 1,
      "tax_number": "9876543210",
      "registration_number": "987654321",
      "address": "г. Москва, ул. Дочерняя, 2",
      "phone": "+7 (495) 987-65-43",
      "email": "info@stroitel1.ru",
      "created_at": "2025-06-26T16:00:00.000000Z",
      "stats": {
        "users_count": 15,
        "projects_count": 4,
        "contracts_count": 3,
        "active_contracts_value": 2500000
      }
    }
  ]
}
```

#### 4. Данные конкретной организации
```http
GET https://api.prohelper.pro/api/v1/holding-api/{slug}/organization/{organizationId}
Authorization: Bearer {JWT_TOKEN}
```

#### 5. Добавление дочерней организации
```http
POST https://api.prohelper.pro/api/v1/holding-api/{slug}/add-child
Authorization: Bearer {JWT_TOKEN}
```

**Тело запроса:** (такое же как в основном API)

## 🎨 Фронтенд интерфейс для поддоменов

**ВАЖНО:** Сейчас поддомены возвращают raw JSON. Нужно создать фронтенд интерфейс!

### Публичная страница холдинга
Создать красивую лендинг-страницу по адресу `https://{slug}.prohelper.pro/` которая:

1. **Загружает данные** через API `GET /`
2. **Отображает информацию о холдинге**:
   - Название и описание холдинга
   - Контактную информацию родительской организации
   - Общую статистику (количество компаний, проектов, стоимость контрактов)
3. **Включает элементы дизайна**:
   - Корпоративный стиль
   - Адаптивная верстка
   - SEO-оптимизация

### Панель управления холдингом
Создать административную панель по адресу `https://{slug}.prohelper.pro/dashboard` которая:

1. **Проверяет авторизацию** - при отсутствии JWT токена перенаправляет на вход
2. **Загружает данные** через API `GET /dashboard`
3. **Отображает**:
   - Консолидированную статистику
   - Список дочерних организаций с возможностью перехода
   - Управление настройками холдинга
   - Добавление новых дочерних организаций

### Пример структуры фронтенда:

```
public_html/holdings/
├── index.html          # Публичная страница (шаблон)
├── dashboard.html      # Панель управления (шаблон)
├── organizations.html  # Список дочерних организаций
├── css/
│   ├── holding-public.css
│   ├── holding-admin.css
├── js/
│   ├── holding-public.js
│   ├── holding-admin.js
│   ├── auth.js
└── assets/
```

### JavaScript для публичной страницы:

```javascript
// holding-public.js
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const response = await fetch('/');
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('holding-name').textContent = data.data.holding.name;
            document.getElementById('holding-description').textContent = data.data.holding.description;
            document.getElementById('total-companies').textContent = data.data.stats.total_child_organizations;
            document.getElementById('total-projects').textContent = data.data.stats.total_projects;
            // ... остальные поля
        }
    } catch (error) {
        console.error('Ошибка загрузки данных холдинга:', error);
    }
});
```

### JavaScript для панели управления:

```javascript
// holding-admin.js
document.addEventListener('DOMContentLoaded', async function() {
    const token = localStorage.getItem('jwt_token');
    
    if (!token) {
        window.location.href = '/login';
        return;
    }
    
    try {
        const response = await fetch('/dashboard', {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        if (response.status === 401) {
            window.location.href = '/login';
            return;
        }
        
        const data = await response.json();
        // Отобразить данные панели управления
    } catch (error) {
        console.error('Ошибка загрузки панели управления:', error);
    }
});
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

### Шаг 5: Создание фронтенда для поддоменов
1. Создать публичную страницу холдинга
2. Создать панель управления с авторизацией
3. Реализовать обработку данных и отображение статистики

## 💡 Рекомендации по UX

1. **Индикация текущей организации** - всегда показывать контекст
2. **Быстрое переключение** - удобный способ переключения между организациями
3. **Визуальная иерархия** - четко показывать структуру холдинга
4. **Ограничения и лимиты** - показывать текущие лимиты и прогресс
5. **Консолидированная аналитика** - сводная информация по всему холдингу 