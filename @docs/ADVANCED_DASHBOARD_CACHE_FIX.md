# Исправление проблемы с кешированием в модуле Advanced Dashboard

## Проблема

Пользователь сообщил о следующих проблемах:
1. Создал дашборд, перезагрузил страницу - дашборд пропал из списка
2. Попытался создать новый дашборд - тоже пропал
3. Спустя время у него появилось 5 дашбордов одновременно

**Причина:** Слишком агрессивное кеширование списка дашбордов (10 минут TTL) без надежной инвалидации кеша после операций создания/обновления/удаления.

## Внесенные исправления

### 1. Уменьшение TTL кеша (600 → 60 секунд)

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php`

Изменено время кеширования списка дашбордов:
- **Было:** 600 секунд (10 минут)
- **Стало:** 60 секунд (1 минута)

```php
// Метод getUserDashboards()
return Cache::remember($cacheKey, 60, function () use (...) { ... });

// Метод getDefaultDashboard()
return Cache::remember($cacheKey, 60, function () use (...) { ... });
```

### 2. Добавлена инвалидация кеша при расшаривании

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php`

Методы `shareDashboard()` и `unshareDashboard()` теперь очищают кеш:
- Для владельца дашборда
- Для всех пользователей, с которыми был расшарен дашборд

```php
public function shareDashboard(int $dashboardId, array $userIds = [], string $visibility = 'team'): Dashboard
{
    // ... обновление дашборда ...
    
    // Очищаем кеш владельца
    $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
    
    // Очищаем кеш для всех пользователей, с которыми расшарили
    foreach ($userIds as $userId) {
        $this->clearUserDashboardCache($userId, $dashboard->organization_id);
    }
    
    return $dashboard->fresh();
}
```

### 3. Реализация Tagged Cache

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php`

Добавлена поддержка tagged cache для более надежной инвалидации:

```php
protected function getDashboardCacheTags(int $userId, int $organizationId): array
{
    return [
        "dashboards",
        "user:{$userId}",
        "org:{$organizationId}",
    ];
}

protected function clearUserDashboardCache(int $userId, int $organizationId): void
{
    if ($this->supportsTaggedCache()) {
        // Используем tagged cache для полной инвалидации
        $tags = $this->getDashboardCacheTags($userId, $organizationId);
        Cache::tags($tags)->flush();
    } else {
        // Fallback для драйверов без поддержки тегов
        Cache::forget("user_dashboards_{$userId}_{$organizationId}_true");
        Cache::forget("user_dashboards_{$userId}_{$organizationId}_false");
        Cache::forget("default_dashboard_{$userId}_{$organizationId}");
    }
}
```

### 4. Автоматическая инвалидация через Observer

**Новый файл:** `app/BusinessModules/Features/AdvancedDashboard/Observers/DashboardObserver.php`

Создан Observer, который автоматически инвалидирует кеш при любых изменениях модели Dashboard:

```php
class DashboardObserver
{
    public function created(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    public function updated(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    public function deleted(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    public function restored(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }
}
```

**Регистрация Observer:**

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/AdvancedDashboardServiceProvider.php`

```php
protected function registerObservers(): void
{
    \App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard::observe(
        \App\BusinessModules\Features\AdvancedDashboard\Observers\DashboardObserver::class
    );
}
```

### 5. Исправление контроллера

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Http/Controllers/DashboardManagementController.php`

Добавлена инвалидация кеша в методе `update()`:

```php
public function update(Request $request, int $id): JsonResponse
{
    // ... валидация и обновление ...
    
    $dashboard->update($validated);
    
    // Инвалидация кеша после обновления
    $this->layoutService->clearDashboardCache($dashboard->user_id, $dashboard->organization_id);
    
    // ... логирование и ответ ...
}
```

### 6. Публичный метод для инвалидации кеша

**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php`

Добавлен публичный метод для использования в контроллерах:

```php
public function clearDashboardCache(int $userId, int $organizationId): void
{
    $this->clearUserDashboardCache($userId, $organizationId);
}
```

## Как работает исправление

1. **Создание дашборда:**
   - Dashboard создается в БД
   - Observer автоматически инвалидирует кеш через `created()`
   - При следующем запросе списка кеш обновляется (TTL 60 сек)

2. **Обновление дашборда:**
   - Dashboard обновляется в БД
   - Observer автоматически инвалидирует кеш через `updated()`
   - Дополнительно контроллер вызывает `clearDashboardCache()`

3. **Расшаривание дашборда:**
   - Метод `shareDashboard()` очищает кеш владельца и всех shared пользователей
   - Observer дополнительно инвалидирует кеш через `updated()`

4. **Удаление дашборда:**
   - Observer автоматически инвалидирует кеш через `deleted()`
   - Метод `deleteDashboard()` дополнительно вызывает `clearUserDashboardCache()`

## Результат

✅ **Проблема решена:**
- Список дашбордов обновляется в течение 1 минуты (вместо 10 минут)
- При создании/обновлении/удалении кеш инвалидируется немедленно
- Используется tagged cache для более надежной инвалидации (если поддерживается драйвером)
- Observer обеспечивает автоматическую инвалидацию при любых изменениях модели

## Рекомендации

### Для продакшена:

1. **Проверить драйвер кеша:**
   ```php
   // В .env должен быть redis или memcached для tagged cache
   CACHE_DRIVER=redis
   ```

2. **Мониторинг кеша:**
   - Использовать endpoint для получения статистики кеша
   - Отслеживать частоту инвалидации

3. **Настройка TTL:**
   - Текущее значение: 60 секунд
   - Можно увеличить до 120-300 секунд при стабильной работе

### Для разработки:

1. **Тестирование:**
   ```bash
   # Тест создания дашборда
   POST /api/v1/admin/advanced-dashboard/dashboards
   
   # Проверка списка (должен появиться сразу)
   GET /api/v1/admin/advanced-dashboard/dashboards
   ```

2. **Отладка кеша:**
   ```php
   // Проверить ключи кеша
   Cache::get("user_dashboards_{$userId}_{$organizationId}_true");
   
   // Проверить статистику
   GET /api/v1/admin/advanced-dashboard/cache/stats
   ```

## Миграция

Для применения исправлений:

1. **Обновить код:**
   ```bash
   git pull origin main
   ```

2. **Очистить весь кеш дашбордов:**
   ```bash
   php artisan cache:clear
   ```

3. **Перезапустить воркеры (если используются):**
   ```bash
   php artisan queue:restart
   ```

4. **Проверить работу:**
   - Создать тестовый дашборд
   - Проверить появление в списке
   - Обновить дашборд
   - Проверить обновление в списке

## Изменённые файлы

1. `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php` - основной сервис
2. `app/BusinessModules/Features/AdvancedDashboard/Http/Controllers/DashboardManagementController.php` - контроллер
3. `app/BusinessModules/Features/AdvancedDashboard/AdvancedDashboardServiceProvider.php` - регистрация observer
4. `app/BusinessModules/Features/AdvancedDashboard/Observers/DashboardObserver.php` - новый observer

## Дата исправления

13 октября 2025

