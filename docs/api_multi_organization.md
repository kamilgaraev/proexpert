# API Модуля "Мультиорганизация"

## Обзор

Модуль "Мультиорганизация" позволяет создавать холдинговые структуры с дочерними организациями. Главная организация получает доступ ко всем данным дочерних, а дочерние видят только свои данные.

**Базовый URL:** `/api/v1/landing/multi-organization/`

**Авторизация:** Bearer Token (JWT)

**Требуемый модуль:** `multi_organization`

---

## Проверка доступности

### 1. Проверка доступа к модулю

```http
GET /api/v1/landing/multi-organization/check-availability
```

**Описание:** Проверяет, активирован ли модуль и может ли организация создать холдинг.

**Ответ при активном модуле:**
```json
{
  "success": true,
  "available": true,
  "can_create_holding": true,
  "current_type": "single",
  "is_holding": false
}
```

**Ответ при неактивном модуле:**
```json
{
  "success": false,
  "available": false,
  "message": "Модуль \"Мультиорганизация\" не активирован",
  "required_module": "multi_organization"
}
```

---

## Управление холдингом

### 2. Создание холдинга

```http
POST /api/v1/landing/multi-organization/create-holding
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "name": "Холдинг АБВ",
  "description": "Группа строительных компаний",
  "max_child_organizations": 20,
  "settings": {
    "consolidated_reports": true,
    "shared_materials": false
  },
  "permissions_config": {
    "default_child_permissions": {
      "projects": ["read", "create", "edit"],
      "reports": ["read"]
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

**Ответ:**
```json
{
  "success": true,
  "message": "Холдинг успешно создан",
  "data": {
    "id": 1,
    "name": "Холдинг АБВ",
    "slug": "holding-abv",
    "parent_organization_id": 123,
    "max_child_organizations": 20,
    "status": "active"
  }
}
```

### 3. Добавление дочерней организации

```http
POST /api/v1/landing/multi-organization/add-child
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "group_id": 1,
  "name": "ООО Строитель",
  "description": "Дочерняя строительная компания",
  "inn": "1234567890",
  "kpp": "123456789",
  "address": "г. Москва, ул. Строительная, 1",
  "phone": "+7 (495) 123-45-67",
  "email": "info@stroitel.ru"
}
```

**Валидация:**
- `group_id` - required|integer|exists:organization_groups,id
- `name` - required|string|max:255
- `inn` - nullable|string|max:12
- `kpp` - nullable|string|max:9
- `address` - nullable|string|max:500
- `phone` - nullable|string|max:20
- `email` - nullable|email|max:255

**Ответ:**
```json
{
  "success": true,
  "message": "Дочерняя организация успешно добавлена",
  "data": {
    "id": 124,
    "name": "ООО Строитель",
    "parent_organization_id": 123,
    "organization_type": "child",
    "hierarchy_level": 1
  }
}
```

---

## Получение данных

### 4. Иерархия организаций

```http
GET /api/v1/landing/multi-organization/hierarchy
```

**Описание:** Получает полную структуру холдинга с родительской и дочерними организациями.

**Ответ:**
```json
{
  "success": true,
  "data": {
    "parent": {
      "id": 123,
      "name": "Главная компания",
      "organization_type": "parent",
      "is_holding": true,
      "hierarchy_level": 0
    },
    "children": [
      {
        "id": 124,
        "name": "ООО Строитель",
        "organization_type": "child",
        "hierarchy_level": 1,
        "inn": "1234567890"
      }
    ],
    "total_stats": {
      "total_organizations": 2,
      "total_users": 25,
      "total_projects": 15,
      "total_contracts": 8
    }
  }
}
```

### 5. Доступные организации

```http
GET /api/v1/landing/multi-organization/accessible
```

**Описание:** Получает список организаций, к которым у пользователя есть доступ.

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Главная компания",
      "organization_type": "parent",
      "is_holding": true,
      "hierarchy_level": 0
    },
    {
      "id": 124,
      "name": "ООО Строитель",
      "organization_type": "child",
      "is_holding": false,
      "hierarchy_level": 1
    }
  ]
}
```

### 6. Данные организации

```http
GET /api/v1/landing/multi-organization/organization/{organizationId}
```

**Описание:** Получает подробные данные конкретной организации (если есть доступ).

**Ответ:**
```json
{
  "success": true,
  "data": {
    "organization": {
      "id": 124,
      "name": "ООО Строитель",
      "organization_type": "child",
      "inn": "1234567890",
      "address": "г. Москва, ул. Строительная, 1"
    },
    "stats": {
      "users_count": 8,
      "projects_count": 3,
      "contracts_count": 2,
      "active_contracts_value": 1500000.00
    },
    "recent_activity": {
      "last_project_created": "2024-01-15T10:00:00Z",
      "last_contract_signed": "2024-01-10T14:30:00Z",
      "last_user_added": "2024-01-12T09:15:00Z"
    }
  }
}
```

---

## Переключение контекста

### 7. Смена активной организации

```http
POST /api/v1/landing/multi-organization/switch-context
```

**Описание:** Переключает пользователя в контекст другой организации (если есть доступ).

**Тело запроса:**
```json
{
  "organization_id": 124
}
```

**Валидация:**
- `organization_id` - required|integer|exists:organizations,id

**Ответ:**
```json
{
  "success": true,
  "message": "Контекст организации изменен",
  "current_organization_id": 124
}
```

---

## Типы организаций

### Типы в поле `organization_type`:
- **`single`** - Обычная организация (по умолчанию)
- **`parent`** - Головная организация холдинга
- **`child`** - Дочерняя организация

### Уровни иерархии `hierarchy_level`:
- **`0`** - Головная организация
- **`1`** - Дочерняя организация первого уровня
- **`2+`** - Возможны дочерние организации второго уровня и глубже

---

## Права доступа

### Автоматические права головной организации:
- Просмотр всех данных дочерних организаций
- Создание отчетов по всем организациям
- Управление структурой холдинга
- Назначение прав доступа

### Права дочерних организаций:
- Доступ только к собственным данным
- Базовые операции в рамках своей организации
- Невозможность видеть данные других дочерних организаций

---

## Middleware для проверки доступа

```php
Route::middleware(['module.access:multi_organization'])
    ->get('/holding-reports', [ReportsController::class, 'holding']);
```

---

## Коды ошибок

- `400` - Неверный запрос
- `401` - Не авторизован  
- `403` - Нет прав доступа / Модуль недоступен
- `404` - Организация не найдена
- `422` - Ошибка валидации

### Специфичные ошибки

```json
{
  "success": false,
  "message": "Организация уже является холдингом"
}
```

```json
{
  "success": false,
  "message": "Достигнут лимит дочерних организаций"
}
```

---

## Примеры интеграции

### Проверка в контроллере:
```php
public function getConsolidatedReport(Request $request)
{
    if (!hasModuleAccess('multi_organization')) {
        return response()->json(['error' => 'Модуль недоступен'], 403);
    }
    
    $user = auth()->user();
    $accessibleOrgs = app(MultiOrganizationService::class)
        ->getAccessibleOrganizations($user);
    
    // Формирование отчета по всем доступным организациям
}
```

### Использование в Blade:
```blade
@if(hasModuleAccess('multi_organization'))
    <select name="organization_id">
        @foreach($accessibleOrganizations as $org)
            <option value="{{ $org->id }}">{{ $org->name }}</option>
        @endforeach
    </select>
@endif
``` 