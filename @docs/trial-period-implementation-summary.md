# Сводка реализации Trial Period для модулей

## ✅ Реализованный функционал

### 1. Core логика (ModuleManager)

**Файл:** `app/Modules/Core/ModuleManager.php`

Добавлены методы:

- **`activateTrial(int $organizationId, string $moduleSlug): array`**
  - Активирует trial период для модуля
  - Проверяет, не был ли trial уже использован
  - Проверяет, не активирован ли уже модуль
  - Создает запись с `status = 'trial'` и `trial_ends_at`
  - Испускает событие `TrialActivated`
  - Возвращает количество дней trial

- **`hasUsedTrial(int $organizationId, int $moduleId): bool`**
  - Проверяет, использовала ли организация trial для модуля
  - Проверяет наличие записи с `trial_ends_at IS NOT NULL`

- **`convertTrialToPaid(int $organizationId, string $moduleSlug): array`**
  - Конвертирует trial в платную подписку
  - Проверяет достаточность средств на балансе
  - Списывает средства через BillingEngine
  - Обновляет статус с `'trial'` на `'active'`
  - Логирует конвертацию

### 2. События

**Файлы:**
- `app/Modules/Events/TrialActivated.php` - испускается при активации trial
- `app/Modules/Events/TrialExpired.php` - испускается при истечении trial

События содержат:
- `organizationId` - ID организации
- `moduleSlug` - slug модуля
- `trialDays` - количество дней trial (только TrialActivated)

### 3. Автоматическая обработка истекших trial

**Файл:** `app/Console/Commands/ConvertExpiredTrials.php`

Команда: `php artisan modules:convert-expired-trials`

Функционал:
- Находит все trial с `trial_ends_at <= now()` и `status = 'trial'`
- Обновляет их статус на `'expired'`
- Очищает кеш доступа для организации
- Испускает событие `TrialExpired`
- Логирует каждую обработку

**Автоматический запуск:** Каждый час через Laravel Scheduler (см. `routes/console.php`)

### 4. API Endpoints

**Файл:** `app/Http/Controllers/Api/V1/Landing/ModuleController.php`

**Маршруты:**
```
POST   /api/modules/{slug}/activate-trial        - Активировать trial
GET    /api/modules/{slug}/trial-availability    - Проверить доступность trial
POST   /api/modules/{slug}/convert-trial         - Конвертировать trial в платную подписку
```

**Middleware:** `auth:api_landing`, `jwt.auth`, `organization.context`, `authorize:modules.manage` (кроме trial-availability)

#### 4.1. POST `/api/modules/{slug}/activate-trial`

**Запрос:**
```json
POST /api/modules/advanced-dashboard/activate-trial
Authorization: Bearer {jwt_token}
```

**Успешный ответ:**
```json
{
  "success": true,
  "message": "Trial период модуля 'Продвинутый дашборд' активирован на 14 дней",
  "trial_days": 14,
  "trial_ends_at": "2025-10-18T12:00:00.000000Z"
}
```

**Ошибки:**
- `400` - Модуль не найден / уже активирован / недоступен
- `409` - Trial уже был использован
- `404` - Организация не найдена

#### 4.2. GET `/api/modules/{slug}/trial-availability`

**Запрос:**
```json
GET /api/modules/advanced-dashboard/trial-availability
Authorization: Bearer {jwt_token}
```

**Ответ:**
```json
{
  "success": true,
  "trial_available": true,
  "has_used_trial": false,
  "is_active": false,
  "trial_days": 14,
  "module": {
    "name": "Продвинутый дашборд",
    "slug": "advanced-dashboard",
    "price": 4990,
    "currency": "RUB",
    "billing_model": "subscription"
  }
}
```

#### 4.3. POST `/api/modules/{slug}/convert-trial`

**Запрос:**
```json
POST /api/modules/advanced-dashboard/convert-trial
Authorization: Bearer {jwt_token}
```

**Успешный ответ:**
```json
{
  "success": true,
  "message": "Модуль 'Продвинутый дашборд' успешно активирован",
  "paid_amount": 4990
}
```

**Ошибки:**
- `400` - Trial не найден / модуль не найден
- `402` - Недостаточно средств на балансе

### 5. Конфигурация модуля

**Пример:** `config/ModuleList/features/advanced-dashboard.json`

```json
{
  "pricing": {
    "base_price": 4990,
    "currency": "RUB",
    "included_in_plans": ["profi", "enterprise"],
    "duration_days": 30,
    "trial_days": 7
  }
}
```

`trial_days` - количество дней trial периода (по умолчанию 14, текущие модули настроены на 7 дней)

### 📦 Модули с активным trial периодом (7 дней)

**Features (5):**
1. ✅ `advanced-dashboard` - Продвинутый дашборд (4990 ₽)
2. ✅ `advanced-reports` - Продвинутые отчеты (2900 ₽)
3. ✅ `workflow-management` - Управление рабочими процессами (1990 ₽)
4. ✅ `time-tracking` - Учет рабочего времени (1490 ₽)
5. ✅ `schedule-management` - Управление расписанием (1990 ₽)

**Core (1):**
6. ✅ `multi-organization` - Мультиорганизация (5900 ₽)

**Addons (8):**
7. ✅ `integrations` - Интеграции (2900 ₽)
8. ✅ `rate-management` - Управление расценками (2490 ₽)
9. ✅ `contractor-portal` - Портал подрядчиков (3490 ₽)
10. ✅ `material-analytics` - Аналитика материалов (2990 ₽)
11. ✅ `act-reporting` - Управление актами (1990 ₽)
12. ✅ `advance-accounting` - Подотчетные средства (2490 ₽)
13. ✅ `system-logs` - Системные логи (1990 ₽)
14. ✅ `file-management` - Управление файлами (990 ₽)

**Итого:** 14 платных модулей с trial периодом на 7 дней

### 6. Автоматический scheduler

**Файл:** `routes/console.php`

```php
Schedule::command('modules:convert-expired-trials')
    ->hourly()
    ->withoutOverlapping(10)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled modules:convert-expired-trials command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-trial-expired.log'));
```

Команда запускается **каждый час** и обрабатывает истекшие trial периоды.

## 📋 Схема работы Trial

### Активация Trial

```
1. Пользователь нажимает "Попробовать 14 дней бесплатно"
2. Frontend → POST /api/modules/{slug}/activate-trial
3. ModuleManager проверяет:
   - Не использован ли trial ранее
   - Не активирован ли модуль
   - Платный ли модуль
4. Создается запись OrganizationModuleActivation:
   - status = 'trial'
   - trial_ends_at = now() + 14 days
   - expires_at = now() + 14 days
   - paid_amount = 0
5. Очищается кеш доступа
6. Испускается событие TrialActivated
7. Пользователь получает доступ к модулю
```

### Истечение Trial

```
1. Scheduler каждый час запускает modules:convert-expired-trials
2. Команда находит записи с trial_ends_at <= now() и status = 'trial'
3. Для каждой записи:
   - Обновляет status = 'expired'
   - Устанавливает expires_at = now()
   - Очищает кеш доступа
   - Испускает событие TrialExpired
4. Доступ к модулю блокируется
```

### Конвертация Trial в платную подписку

```
1. Пользователь нажимает "Активировать модуль"
2. Frontend → POST /api/modules/{slug}/convert-trial
3. ModuleManager проверяет:
   - Есть ли активный trial
   - Достаточно ли средств на балансе
4. BillingEngine списывает средства
5. Обновляется запись OrganizationModuleActivation:
   - status = 'active'
   - activated_at = now()
   - expires_at = now() + 30 days (для subscription)
   - paid_amount = 4990
6. Очищается кеш доступа
7. Логируется конвертация
```

## 🔒 Безопасность и ограничения

- **Один trial на организацию:** Повторное использование trial для одного модуля невозможно
- **Только для платных модулей:** Бесплатные модули не имеют trial периода
- **Автоматическое истечение:** Trial автоматически истекает через указанное количество дней
- **Защита от дублирования:** При попытке активировать trial повторно возвращается ошибка `TRIAL_ALREADY_USED`
- **Проверка баланса:** При конвертации проверяется достаточность средств

## 📊 Мониторинг

### Логи

**Business logs:**
- `module.trial.activation.started` - начало активации trial
- `module.trial.activation.completed` - успешная активация trial
- `module.trial.activation.failed` - ошибка активации trial
- `module.trial.converted` - успешная конвертация trial в платную подписку

**Technical logs:**
- `module.trial.conversion.failed` - техническая ошибка конвертации

### Файлы логов

- `storage/logs/schedule-trial-expired.log` - лог работы scheduler для истекших trial
- `storage/logs/laravel.log` - основной лог приложения с business/technical событиями

### Метрики для отслеживания

1. Количество активаций trial за период
2. Конверсия trial → платная подписка (%)
3. Количество истекших trial без конвертации
4. Средняя длительность использования trial до конвертации
5. Модули с наибольшей конверсией trial

## 🚀 Готово к использованию

### Backend
- ✅ Логика активации trial
- ✅ Логика проверки доступности trial
- ✅ Логика конвертации trial в платную подписку
- ✅ Автоматическое истечение trial
- ✅ API endpoints
- ✅ Логирование
- ✅ События для расширения функционала

### Требуется (Frontend)
- ⏳ Кнопка "Попробовать 14 дней бесплатно" в карточке модуля
- ⏳ Индикатор оставшихся дней trial
- ⏳ Уведомление о скором истечении trial
- ⏳ Кнопка "Активировать модуль" при истечении trial
- ⏳ UI для конвертации trial в платную подписку

### Требуется (Система уведомлений)
- ⏳ Уведомление за 3 дня до истечения trial
- ⏳ Уведомление за 1 день до истечения trial
- ⏳ Уведомление при истечении trial
- ⏳ Email-рассылка о trial периодах

## 📝 Примеры использования

### Проверка доступности trial

```bash
curl -X GET "https://api.example.com/api/modules/advanced-dashboard/trial-availability" \
  -H "Authorization: Bearer {jwt_token}"
```

### Активация trial

```bash
curl -X POST "https://api.example.com/api/modules/advanced-dashboard/activate-trial" \
  -H "Authorization: Bearer {jwt_token}"
```

### Конвертация trial

```bash
curl -X POST "https://api.example.com/api/modules/advanced-dashboard/convert-trial" \
  -H "Authorization: Bearer {jwt_token}"
```

### Проверка истекших trial вручную

```bash
php artisan modules:convert-expired-trials
```

## 🔧 Настройка

### Изменение длительности trial

Отредактируйте `config/ModuleList/{type}/{module-slug}.json`:

```json
{
  "pricing": {
    "trial_days": 30
  }
}
```

### Изменение частоты проверки истекших trial

Отредактируйте `routes/console.php`:

```php
Schedule::command('modules:convert-expired-trials')
    ->everyThirtyMinutes()  // или ->daily(), ->everyFiveMinutes()
```

## 🎯 Метрики успеха

Для оценки эффективности trial периода отслеживайте:

1. **Trial Conversion Rate** = (Конвертированные trial / Всего активаций trial) × 100%
2. **Average Days to Convert** = Среднее время от активации trial до конвертации
3. **Trial Drop-off Rate** = (Истекшие без конвертации / Всего активаций trial) × 100%

Целевые показатели:
- Trial Conversion Rate: > 25%
- Average Days to Convert: < 7 дней
- Trial Drop-off Rate: < 60%

---

**Дата реализации:** 4 октября 2025  
**Версия:** 1.0.0  
**Статус:** ✅ Готово к production  
**Модулей с trial:** 14 модулей (7 дней trial каждый)

