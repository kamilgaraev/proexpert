# API Лимитов Подписки

## Получение информации о лимитах подписки

**GET** `/api/v1/landing/billing/subscription/limits`

Возвращает информацию о текущих лимитах подписки пользователя, включая использование ресурсов и предупреждения.

### Заголовки запроса
```
Authorization: Bearer {JWT_TOKEN}
Accept: application/json
```

### Ответ

#### Структура ответа для пользователя с подпиской

```json
{
  "success": true,
  "data": {
    "has_subscription": true,
    "subscription": {
      "id": 123,
      "status": "active",
      "plan_name": "Профессиональный",
      "plan_description": "Расширенные возможности для строительных компаний",
      "is_trial": false,
      "trial_ends_at": null,
      "ends_at": "2024-12-31 23:59:59",
      "next_billing_at": "2024-12-01 00:00:00",
      "is_canceled": false
    },
    "limits": {
      "foremen": {
        "limit": 10,
        "used": 7,
        "remaining": 3,
        "percentage_used": 70.0,
        "is_unlimited": false,
        "status": "approaching"
      },
      "projects": {
        "limit": 50,
        "used": 23,
        "remaining": 27,
        "percentage_used": 46.0,
        "is_unlimited": false,
        "status": "normal"
      },
      "storage": {
        "limit_gb": 100.0,
        "used_gb": 45.67,
        "remaining_gb": 54.33,
        "percentage_used": 45.7,
        "is_unlimited": false,
        "status": "normal"
      }
    },
    "features": [
      "Неограниченное количество контрактов",
      "Расширенная отчетность",
      "Приоритетная поддержка"
    ],
    "warnings": [
      {
        "type": "foremen",
        "level": "warning",
        "message": "Приближаетесь к лимиту количества прорабов"
      }
    ],
    "upgrade_required": false
  }
}
```

#### Структура ответа для пользователя без подписки

```json
{
  "success": true,
  "data": {
    "has_subscription": false,
    "subscription": null,
    "limits": {
      "foremen": {
        "limit": 1,
        "used": 1,
        "remaining": 0,
        "percentage_used": 100.0,
        "is_unlimited": false,
        "status": "exceeded"
      },
      "projects": {
        "limit": 1,
        "used": 0,
        "remaining": 1,
        "percentage_used": 0.0,
        "is_unlimited": false,
        "status": "normal"
      },
      "storage": {
        "limit_gb": 0.1,
        "used_gb": 0.02,
        "remaining_gb": 0.08,
        "percentage_used": 20.0,
        "is_unlimited": false,
        "status": "normal"
      }
    },
    "features": [],
    "warnings": [
      {
        "type": "foremen",
        "level": "critical",
        "message": "Достигнут лимит бесплатного тарифа. Оформите подписку для добавления прорабов."
      }
    ],
    "upgrade_required": true
  }
}
```

### Описание полей

#### Поле `subscription`
- `id` - ID подписки
- `status` - Статус подписки (`trial`, `active`, `canceled`, `expired`, etc.)
- `plan_name` - Название тарифного плана
- `plan_description` - Описание тарифного плана
- `is_trial` - Является ли подписка пробной
- `trial_ends_at` - Дата окончания пробного периода
- `ends_at` - Дата окончания подписки
- `next_billing_at` - Дата следующего списания
- `is_canceled` - Отменена ли подписка

#### Поле `limits`
Содержит информацию о лимитах по трем категориям:

**foremen** - Лимиты на количество прорабов
**projects** - Лимиты на количество проектов  
**storage** - Лимиты на дисковое пространство

Для каждого лимита:
- `limit` - Максимальное количество (null для безлимитных планов)
- `used` - Текущее использование
- `remaining` - Остаток до лимита
- `percentage_used` - Процент использования
- `is_unlimited` - Безлимитный ли ресурс
- `status` - Статус лимита:
  - `normal` - Нормальное использование (< 60%)
  - `approaching` - Приближение к лимиту (60-79%)
  - `warning` - Предупреждение (80-99%)
  - `exceeded` - Лимит превышен (≥ 100%)
  - `unlimited` - Безлимитный ресурс

#### Поле `warnings`
Массив предупреждений о лимитах:
- `type` - Тип ресурса (`foremen`, `projects`, `storage`)
- `level` - Уровень предупреждения (`warning`, `critical`)
- `message` - Текст предупреждения

#### Поле `features`
Массив особенностей тарифного плана (только для пользователей с подпиской)

#### Поле `upgrade_required`
Булево значение, указывающее нужно ли пользователю обновить подписку

### Коды ошибок

- `401 Unauthorized` - Пользователь не авторизован
- `403 Forbidden` - Недостаточно прав доступа
- `500 Internal Server Error` - Внутренняя ошибка сервера

### Кэширование

Данные об использовании ресурсов кэшируются на 5 минут для оптимизации производительности.

### Примечания

- Расчет использования дискового пространства является приблизительным
- Лимиты проверяются в реальном времени при создании новых ресурсов
- Предупреждения генерируются при достижении 80% от лимита 