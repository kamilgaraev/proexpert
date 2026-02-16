---
trigger: always_on
---

# Правила проекта

Этот файл содержит основные правила и стандарты разработки для данного проекта.

## 1. Общие правила (General)

### Стек технологий
- **PHP**: 8.2
- **Framework**: Laravel 11
- **Язык общения**: Всегда отвечай на **русском языке**.
- **Стиль кода**: PSR-12.
- **Строгая типизация**: Используй `declare(strict_types=1);` в новых файлах.

### Библиотеки и зависимости
Мы используем следующие ключевые библиотеки. Отдавай предпочтение им, а не установке новых:

- **Генерация PDF**: `barryvdh/laravel-dompdf`
- **Обработка изображений**: `intervention/image` (v3)
- **Очереди**: `laravel/horizon`
- **Вебсокеты**: `laravel/reverb`
- **Отладка**: `laravel/tinker`, `laravel/pail`
- **Файловое хранилище**: `league/flysystem-aws-s3-v3`
- **AI/LLM**: `openai-php/client`
- **Excel/Таблицы**: `phpoffice/phpspreadsheet`, `shuchkin/simplexlsx`
- **Redis**: `predis/predis`
- **Email**: `resend/resend-php`
- **Отслеживание ошибок**: `sentry/sentry-laravel`
- **HTTP клиент**: `symfony/http-client`
- **YAML**: `symfony/yaml`
- **Auth (JWT)**: `tymon/jwt-auth`

---

## 2. API Ответы (Responses)

Мы переходим на стандартизированные классы ответов. **НЕ используй** `response()->json()` напрямую в контроллерах.

### Использование
- **Admin API** (`app/Http/Controllers/Api/V1/Admin`):
  Используй `App\Http\Responses\AdminResponse`.
  ```php
  return AdminResponse::success($data);
  return AdminResponse::error('Сообщение об ошибке', 400);
  ```

- **Mobile API** (`app/Http/Controllers/Api/V1/Mobile`):
  Используй `App\Http\Responses\MobileResponse`.
  ```php
  return MobileResponse::success($data);
  ```

- **Landing/LK API** (`app/Http/Controllers/Api/Landing`):
  Используй `App\Http\Responses\LandingResponse`.
  ```php
  return LandingResponse::success($data);
  ```

### Рефакторинг
При редактировании существующего контроллера, пожалуйста, отрефактори возвращаемые значения для использования этих классов.

---

## 3. Архитектура (Architecture)

### Контроллеры (Controllers)
- Оставляй контроллеры "тонкими".
- Не помещай бизнес-логику в контроллеры. Переноси ее в **Сервисы**.
- Используй Dependency Injection (внедрение зависимостей) в конструкторах.

### Сервисы (Services)
- Расположены в `app/Services`.
- Должны содержать бизнес-логику.
- Должны возвращать DTO или Модели, а не HTTP ответы.

### DTO (Data Transfer Objects)
- Расположены в `app/DTOs` или `app/DataTransferObjects`.
- Используй DTO для передачи сложных данных между слоями.

### Модели (Models)
- Расположены в `app/Models`.
- Оставляй модели чистыми. Используй Scopes (заготовки запросов) и Traits (трейты) для переиспользуемой логики запросов.

---

## 4. Модульность (Modularity)

Проект следует модульной структуре, расположенной в `app/BusinessModules`. Каждый модуль — это самостоятельная единица со своим Service Provider, Сервисами и иногда HTTP логикой.

### Пример структуры модуля
В качестве референса для больших модулей используй `BudgetEstimates` (`app/BusinessModules/Features/BudgetEstimates`):

```
app/BusinessModules/Features/BudgetEstimates/
├── BudgetEstimatesModule.php       # Основное определение модуля
├── BudgetEstimatesServiceProvider.php # Service Provider (boot/register)
├── Controllers/                    # Контроллеры модуля
├── DTO/                            # Data Transfer Objects
├── Models/                         # Модели модуля (если изолированы)
├── Services/                       # Сервисы бизнес-логики
│   ├── Import/                     # Сложная логика, разбитая на части
│   └── Export/
└── routes.php                      # Маршруты модуля (если отделены)
```

### Принципы
1. **Изоляция**: Модули должны взаимодействовать друг с другом преимущественно через **Интерфейсы** или **Публичные Сервисы**, а не прямыми запросами к таблицам других модулей.
2. **Регистрация**: Каждый модуль должен иметь `ServiceProvider`, зарегистрированный в приложении (или автоматически обнаруженный).
3. **Типы модулей**:
   - `Core`: Фундаментальные возможности системы (Payments, Organizations, Users).
   - `Features`: Опциональная или бизнес-специфичная функциональность (BudgetEstimates, Procurement).
   - `Addons`: Подключаемые расширения (Integrations, Reporting).
4. **Хелперы**: Используй глобальные хелперы из `app/Modules/helpers.php` для проверки доступа (`hasModuleAccess`) или прав.

---

## 5. Система авторизации (Authorization)

Проект использует гибридную систему **RBAC (Role-Based Access Control)** и **ABAC (Attribute-Based Access Control)**.

### Определение ролей (JSON)
Роли определяются в JSON файлах, расположенных в `config/RoleDefinitions/{context}/{role_slug}.json`.
**НЕ хардкодь** роли в PHP классах. Всегда определяй их в JSON.

#### Структура файла
```json
{
  "name": "Название роли",
  "slug": "role_slug",
  "context": "system|organization|project", 
  "interface_access": ["admin", "lk", "mobile"],
  "system_permissions": ["permission.slug"],
  "module_permissions": {
    "module-slug": ["module.permission"]
  },
  "conditions": {
    "time": { ... }, // ABAC условия
    "budget": { ... }
  }
}
```

#### Контексты
1. **System** (`config/RoleDefinitions/system`): Глобальные роли (Super Admin, Support).
2. **Organization** (`config/RoleDefinitions/lk`): Роли, привязанные к организации (Owner, Accountant).
3. **Project** (`config/RoleDefinitions/project`): Роли, привязанные к конкретному проекту (Project Manager).

### Использование в коде

#### Проверка прав
Используй `AuthorizationService` или вспомогательные методы в модели `User`.

```php
// Проверка разрешения
if ($user->hasPermission('module.permission')) { ... }

// Проверка роли
if ($user->hasRole('project_manager')) { ... }
```

#### Middleware
Используй `RoleMiddleware` для защиты маршрутов.

- `|` (pipe) означает **ИЛИ**.
- `,` (запятая) означает **И**.

```php
// Пользователь должен быть 'super_admin' ИЛИ 'organization_owner'
Route::middleware(['role:super_admin|organization_owner'])->group(function () { ... });

// Пользователь должен быть 'project_manager' И 'finance_admin' (редкий случай)
Route::middleware(['role:project_manager,finance_admin'])->group(function () { ... });

// С контекстом (например, проверка роли в текущей организации)
Route::middleware(['role:organization_owner,organization'])->group(function () { ... });
```

### ABAC (Attribute-Based Access Control)
Роли могут иметь **условия**, оцениваемые во время выполнения (например, время суток, лимиты бюджета).
Они обрабатываются классом `ConditionEvaluator` в `App\Domain\Authorization\Services`.

### Сервисы и Классы
- **RoleScanner**: Загружает и кэширует JSON роли.
- **AuthorizationService**: Основная точка входа для проверок.
- **UserRoleAssignment**: Модель, связывающая пользователей с ролями внутри контекста.