# API Системы Модулей Организации

## Обзор

Система модулей позволяет организациям подключать дополнительные функции платформы на платной основе.

**Базовый URL:** `/api/v1/landing/modules/`

**Авторизация:** Bearer Token (JWT)

---

## Получение модулей

### 1. Список модулей организации с статусами

```http
GET /api/v1/landing/modules/
```

**Описание:** Получает все доступные модули с указанием статуса активации для текущей организации.

**Ответ:**
```json
{
  "success": true,
  "data": {
    "analytics": [
      {
        "module": {
          "id": 1,
          "name": "BI-Аналитика",
          "slug": "bi_analytics",
          "description": "Расширенная бизнес-аналитика с интерактивными дашбордами",
          "price": 4900.00,
          "currency": "RUB",
          "features": ["Интерактивные дашборды", "Кастомные отчеты"],
          "category": "analytics",
          "icon": "chart-bar",
          "is_premium": true
        },
        "is_activated": true,
        "activation": {
          "id": 1,
          "activated_at": "2024-01-15T10:00:00Z",
          "expires_at": "2024-02-15T10:00:00Z",
          "status": "active"
        },
        "expires_at": "2024-02-15T10:00:00Z",
        "days_until_expiration": 15,
        "status": "active"
      }
    ]
  }
}
```

### 2. Доступные модули (каталог)

```http
GET /api/v1/landing/modules/available
```

**Описание:** Получает каталог всех доступных модулей без привязки к организации.

**Ответ:**
```json
{
  "success": true,
  "data": {
    "analytics": [
      {
        "id": 1,
        "name": "BI-Аналитика",
        "slug": "bi_analytics",
        "description": "Расширенная бизнес-аналитика",
        "price": 4900.00,
        "features": ["Интерактивные дашборды"]
      }
    ]
  }
}
```

## Управление модулями

### 3. Активация модуля

```http
POST /api/v1/landing/modules/activate
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "module_id": 1,
  "payment_method": "balance",
  "settings": {
    "custom_setting": "value"
  }
}
```

**Валидация:**
- `module_id` - required|integer|exists:organization_modules,id
- `payment_method` - sometimes|string|in:balance,card,invoice
- `settings` - sometimes|array

**Ответ:**
```json
{
  "success": true,
  "message": "Модуль успешно активирован",
  "data": {
    "id": 1,
    "organization_id": 123,
    "organization_module_id": 1,
    "activated_at": "2024-01-15T10:00:00Z",
    "status": "active",
    "paid_amount": 4900.00
  }
}
```

### 4. Деактивация модуля

```http
DELETE /api/v1/landing/modules/{moduleId}
```

**Права доступа:** Владелец организации

**Ответ:**
```json
{
  "success": true,
  "message": "Модуль успешно деактивирован"
}
```

### 5. Продление модуля

```http
PATCH /api/v1/landing/modules/{moduleId}/renew
```

**Права доступа:** Владелец организации

**Тело запроса:**
```json
{
  "days": 30
}
```

**Валидация:**
- `days` - sometimes|integer|min:1|max:365

## Проверка доступа

### 6. Проверка доступа к модулю

```http
POST /api/v1/landing/modules/check-access
```

**Тело запроса:**
```json
{
  "module_slug": "bi_analytics"
}
```

**Ответ:**
```json
{
  "success": true,
  "has_access": true
}
```

### 7. Истекающие модули

```http
GET /api/v1/landing/modules/expiring
```

**Описание:** Получает список модулей, которые истекают в ближайшие 7 дней.

**Ответ:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "organization_module_id": 1,
      "expires_at": "2024-01-22T10:00:00Z",
      "module": {
        "name": "BI-Аналитика",
        "slug": "bi_analytics"
      }
    }
  ]
}
```

## Middleware для проверки доступа

Для защиты роутов используйте middleware `module.access`:

```php
Route::middleware(['module.access:bi_analytics'])
    ->get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
```

## Хелперы

### Проверка доступа в коде:

```php
// Проверка доступа к модулю
if (hasModuleAccess('bi_analytics')) {
    // Модуль доступен
}

// Проверка разрешения модуля
if (hasModulePermission('analytics.export')) {
    // Разрешение есть
}

// Получение активных модулей
$modules = getActiveModules();
```

### Использование в Blade:

```blade
@if(hasModuleAccess('bi_analytics'))
    <a href="{{ route('analytics.dashboard') }}">Аналитика</a>
@endif
```

## Категории модулей

- **analytics** - Аналитика и отчеты
- **integrations** - Интеграции с внешними системами
- **automation** - Автоматизация процессов
- **customization** - Кастомизация интерфейса
- **security** - Безопасность
- **support** - Поддержка

## Коды ошибок

- `400` - Неверный запрос
- `401` - Не авторизован
- `403` - Нет прав доступа / Модуль недоступен
- `404` - Модуль или организация не найдены
- `422` - Ошибка валидации

### Специфичные ошибки

```json
{
  "success": false,
  "message": "Доступ к модулю запрещен",
  "required_module": "bi_analytics"
}
``` 