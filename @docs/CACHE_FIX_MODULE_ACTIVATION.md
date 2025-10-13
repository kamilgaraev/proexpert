# Исправление проблемы с кешем при активации модулей

## Проблема
После активации/деактивации модулей изменения не отображались моментально в UI из-за того, что кеш `modules_with_status_{$organizationId}` не очищался.

## Решение
Добавлена очистка ключа кеша `modules_with_status_{$organizationId}` во всех местах, где происходит изменение статуса модулей.

## Измененные файлы

### 1. `app/Modules/Core/ModuleManager.php`
**Метод:** `clearOrganizationModuleCaches()`
- Добавлен ключ `modules_with_status_{$organizationId}` в массив очищаемых кешей

**Где используется:**
- После активации модуля (`activateModule`)
- После деактивации модуля (`deactivateModule`)
- После активации trial (`activateTrial`)
- После конвертации trial в платный (`convertTrialToPaid`)

### 2. `app/Modules/Core/AccessController.php`
**Метод:** `clearAccessCache()`
- Добавлен ключ `modules_with_status_{$organizationId}` в массив очищаемых кешей

**Где используется:**
- Вызывается из `ModuleManager` при всех операциях с модулями
- Вызывается явно из контроллеров после операций с модулями

### 3. `app/Observers/OrganizationModuleActivationObserver.php`
**Метод:** `clearModuleCache()`
- Добавлена очистка кеша `modules_with_status_{$organizationId}`

**Где используется:**
- Автоматически срабатывает при изменении записей `OrganizationModuleActivation` (created, updated, deleted)

### 4. `app/Domain/Authorization/Services/ModulePermissionChecker.php`
**Метод:** `clearModuleCache()`
- Добавлена очистка кеша `modules_with_status_{$organizationId}`

**Где используется:**
- При активации модуля через `ModulePermissionChecker`
- При деактивации модуля через `ModulePermissionChecker`

### 5. `app/Http/Controllers/Api/V1/Landing/ModuleController.php`
Добавлен вызов `clearAccessCache($organizationId)` после успешного выполнения операций:
- **Метод `renew()`** - после продления модуля
- **Метод `activateTrial()`** - после активации trial версии
- **Метод `convertTrialToPaid()`** - после конвертации trial в платную версию

Методы `activate()` и `deactivate()` уже содержали вызовы очистки кеша.

## Кеши, которые очищаются при изменении статуса модулей

1. `modules_with_status_{$organizationId}` - **НОВЫЙ** - список модулей с их статусами для UI
2. `org_active_modules_{$organizationId}` - список активных модулей организации
3. `active_modules_{$organizationId}` - альтернативный ключ для активных модулей
4. `org_module_access_{$organizationId}_{$moduleSlug}` - доступ к конкретному модулю
5. `user_permissions_{$userId}_{$organizationId}` - права пользователя
6. `user_permissions_full_{$userId}_{$organizationId}` - полные права пользователя
7. `user_available_permissions_{$userId}_{$organizationId}` - доступные разрешения

## Проверка работы

После применения исправлений:
1. Активация модуля происходит моментально
2. Деактивация модуля отображается сразу
3. Продление модуля обновляет данные в UI без задержки
4. Trial активация и конвертация работают без задержек
5. Массовая активация модулей корректно обновляет UI

## TTL кешей

- `modules_with_status_{$organizationId}` - 5 минут (300 секунд) в `ModuleController::index()`
- `org_active_modules_{$organizationId}` - 1 минута (60 секунд) в `AccessController::getActiveModules()`
- `org_module_access_{$organizationId}_{$moduleSlug}` - 1 минута (60 секунд) в `AccessController::hasModuleAccess()`

Теперь эти кеши явно очищаются при любых изменениях, что предотвращает отображение устаревших данных.

## Дополнительные улучшения

Использование Observer (`OrganizationModuleActivationObserver`) обеспечивает автоматическую очистку кеша даже при прямых изменениях в БД, что повышает надежность системы.

---
**Дата исправления:** 13 октября 2025  
**Затронутые модули:** Все модули системы  
**Критичность:** Высокая (UX issue)

