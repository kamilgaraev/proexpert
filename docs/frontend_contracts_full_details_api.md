# API Документация: Полная информация по контракту

## Обзор

Эндпоинт `GET /api/v1/admin/contracts/{id}/full` предоставляет полную информацию о контракте в одном запросе, включая все связанные данные, аналитику и статистику.

## Эндпоинт

**URL:** `/api/v1/admin/contracts/{id}/full`  
**Метод:** `GET`  
**Авторизация:** Bearer Token (обязательно)  
**Контекст организации:** Обязательно (через middleware или заголовок)

## Параметры запроса

### URL параметры
- `id` (integer, обязательный) - ID контракта

### Заголовки
- `Authorization: Bearer {token}` - JWT токен пользователя
- `Content-Type: application/json`
- `Accept: application/json`

## Структура ответа

### Успешный ответ (200 OK)

```json
{
  "success": true,
  "data": {
    "contract": {
      // Основная информация о контракте (ContractResource)
    },
    "analytics": {
      // Расширенная аналитика
    },
    "works_statistics": {
      // Статистика по выполненным работам
    },
    "recent_works": [
      // Все выполненные работы по контракту
    ],
    "performance_acts": [
      // Все акты выполненных работ
    ],
    "payments": [
      // Все платежи по контракту
    ],
    "child_contracts": [
      // Дочерние контракты (если есть)
    ]
  }
}
```

## Детальное описание полей ответа

### 1. contract (ContractResource)
Основная информация о контракте, включая:
- Базовые поля (id, number, date, total_amount, status, type)
- Информация о подрядчике (contractor)
- Информация о проекте (project)
- Родительский контракт (parent_contract)
- Даты начала и окончания
- Суммы и проценты выполнения

### 2. analytics (объект)
Расширенная аналитика контракта:

#### financial (финансовые показатели)
- `total_amount` (float) - Общая сумма контракта
- `completed_works_amount` (float) - Сумма выполненных работ
- `remaining_amount` (float) - Оставшаяся сумма
- `completion_percentage` (float) - Процент выполнения (0-100)
- `total_paid_amount` (float) - Общая сумма оплат
- `total_performed_amount` (float) - Общая сумма актов
- `gp_amount` (float) - Сумма ГП (государственная поддержка)
- `planned_advance_amount` (float) - Планируемая сумма аванса

#### status (статусные показатели)
- `current_status` (string) - Текущий статус контракта
- `is_nearing_limit` (boolean) - Приближается ли к лимиту (90%+)
- `can_add_work` (boolean) - Можно ли добавлять работы
- `is_overdue` (boolean) - Просрочен ли контракт
- `days_until_deadline` (integer|null) - Дней до дедлайна (может быть отрицательным)

#### counts (счетчики)
- `total_works` (integer) - Общее количество работ
- `confirmed_works` (integer) - Подтвержденные работы
- `pending_works` (integer) - Работы на рассмотрении
- `performance_acts` (integer) - Количество актов
- `approved_acts` (integer) - Утвержденные акты
- `payments_count` (integer) - Количество платежей
- `child_contracts` (integer) - Количество дочерних контрактов

### 3. works_statistics (объект)
Статистика по выполненным работам, сгруппированная по статусам:

#### pending (работы на рассмотрении)
- `count` (integer) - Количество работ
- `amount` (float) - Общая сумма работ
- `avg_amount` (float) - Средняя сумма работы

#### confirmed (подтвержденные работы)
- `count` (integer) - Количество работ
- `amount` (float) - Общая сумма работ
- `avg_amount` (float) - Средняя сумма работы

#### rejected (отклоненные работы)
- `count` (integer) - Количество работ
- `amount` (float) - Общая сумма работ
- `avg_amount` (float) - Средняя сумма работы

### 4. recent_works (массив)
Все выполненные работы по контракту:
- `id` (integer) - ID работы
- `work_type_name` (string) - Название типа работы
- `user_name` (string) - Имя исполнителя
- `quantity` (float) - Количество
- `total_amount` (float) - Общая сумма работы
- `status` (string) - Статус работы
- `completion_date` (string) - Дата выполнения (YYYY-MM-DD)
- `materials_count` (integer) - Количество использованных материалов
- `materials_amount` (float) - Сумма материалов

### 5. performance_acts (массив)
Все акты выполненных работ:
- `id` (integer) - ID акта
- `act_document_number` (string) - Номер документа акта
- `act_date` (string) - Дата акта (YYYY-MM-DD)
- `amount` (float) - Сумма акта
- `description` (string|null) - Описание
- `is_approved` (boolean) - Утвержден ли акт
- `approval_date` (string|null) - Дата утверждения (YYYY-MM-DD)

### 6. payments (массив)
Все платежи по контракту:
- `id` (integer) - ID платежа
- `payment_date` (string) - Дата платежа (YYYY-MM-DD)
- `amount` (float) - Сумма платежа
- `payment_type` (string) - Тип платежа
- `reference_document_number` (string|null) - Номер документа-основания
- `description` (string|null) - Описание платежа

### 7. child_contracts (массив)
Дочерние контракты (если есть):
- `id` (integer) - ID дочернего контракта
- `number` (string) - Номер контракта
- `total_amount` (float) - Общая сумма
- `status` (string) - Статус контракта
- `completion_percentage` (float) - Процент выполнения

## Возможные ошибки

### 400 Bad Request
```json
{
  "message": "Не определён контекст организации"
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 404 Not Found
```json
{
  "message": "Contract not found or does not belong to organization."
}
```

### 500 Internal Server Error
```json
{
  "message": "Internal server error"
}
```

## Рекомендации по использованию

### Кэширование
- Данные контракта относительно стабильны, можно кэшировать на 5-10 минут
- При обновлении контракта/работ/платежей - сбрасывать кэш
- Использовать локальное хранилище для часто просматриваемых контрактов

### Производительность
- Эндпоинт возвращает большой объем данных - используйте с осторожностью
- Не вызывайте часто - только при открытии детальной страницы контракта
- Показывайте индикатор загрузки, так как запрос может занять 1-3 секунды

### Обработка данных
- Проверяйте наличие всех полей перед использованием
- Обрабатывайте null значения для опциональных полей
- Используйте fallback значения для отображения

### UI/UX рекомендации
- Группируйте информацию по логическим блокам
- Выделяйте критические статусы (просроченные, требующие внимания)
- Используйте прогресс-бары для процентов выполнения
- Добавляйте цветовые индикаторы для статусов

### Безопасность
- Всегда проверяйте принадлежность контракта к текущей организации
- Не кэшируйте чувствительные данные в localStorage
- Логируйте доступ к детальной информации контрактов

## Интеграция с другими эндпоинтами

Этот эндпоинт дополняет существующие:
- `GET /contracts` - список контрактов с фильтрацией
- `GET /contracts/{id}` - базовая информация о контракте
- `GET /contracts/{id}/analytics` - только аналитика
- `GET /contracts/{id}/completed-works` - только работы
- `GET /dashboard/contracts-statistics` - общая статистика

Используйте полный эндпоинт только для детальных страниц контрактов, для списков и дашбордов используйте специализированные эндпоинты. 