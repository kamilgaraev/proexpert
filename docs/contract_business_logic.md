 # Бизнес-логика и финансовый контроль контрактов

## Реализованные возможности

### 1. Финансовый контроль

#### 1.1 Проверка лимитов контракта
- ✅ Автоматическая проверка при создании выполненных работ
- ✅ Блокировка работ превышающих лимит контракта (с допуском 1%)
- ✅ Валидация при обновлении существующих работ

#### 1.2 Автоматический расчет показателей
- ✅ Общая сумма выполненных работ по контракту
- ✅ Оставшаяся сумма по контракту
- ✅ Процент выполнения контракта
- ✅ Сумма всех платежей и актов

#### 1.3 Блокировка работ
- ✅ Завершенные контракты (статус: `completed`)
- ✅ Расторгнутые контракты (статус: `terminated`)

### 2. Автоматизация статусов

#### 2.1 Workflow контрактов
- ✅ `draft` → `active` при первой подтвержденной работе
- ✅ `active` → `completed` при 100% выполнении
- ✅ Автоматическое обновление после каждой работы

#### 2.2 Система уведомлений
- ✅ Логирование при приближении к лимиту (90%+)
- ✅ Предупреждения в API ответах
- 🔄 Email уведомления (готова инфраструктура)

## Новые API endpoints

### Аналитика по контракту
```http
GET /api/v1/admin/contracts/{contract}/analytics
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "contract_id": 1,
    "contract_number": "ДОГ-123/2025",
    "total_amount": 1000000.00,
    "completed_works_amount": 750000.00,
    "remaining_amount": 250000.00,
    "completion_percentage": 75.00,
    "total_paid_amount": 500000.00,
    "total_performed_amount": 700000.00,
    "status": "active",
    "is_nearing_limit": false,
    "can_add_work": true,
    "completed_works_count": 25,
    "confirmed_works_count": 20
  }
}
```

### Выполненные работы по контракту
```http
GET /api/v1/admin/contracts/{contract}/completed-works?per_page=15
```

## Модель Contract - новые методы

### Accessor'ы (автоматически доступны как свойства)
```php
$contract->completed_works_amount  // Сумма подтвержденных работ
$contract->remaining_amount        // Оставшаяся сумма
$contract->completion_percentage   // Процент выполнения
$contract->total_paid_amount       // Сумма платежей
$contract->total_performed_amount  // Сумма актов
```

### Методы валидации
```php
$contract->canAddWork($amount)     // Можно ли добавить работу
$contract->isNearingLimit()        // Приближение к лимиту (90%+)
$contract->updateStatusBasedOnCompletion() // Обновить статус
```

## Обработка ошибок

### ContractException
```php
ContractException::contractCompleted()       // Контракт завершен
ContractException::contractTerminated()      // Контракт расторгнут
ContractException::amountExceedsLimit()      // Превышен лимит
ContractException::contractNearingLimit()   // Приближение к лимиту
```

### Пример обработки в frontend
```javascript
try {
  await createCompletedWork(workData);
} catch (error) {
  if (error.status === 422) {
    // Показать пользователю ошибку валидации
    showError(error.message);
  }
}
```

## Логирование

Система автоматически логирует:
- Приближение контрактов к лимиту
- Изменения статусов контрактов
- Попытки превышения лимитов

**Пример лога:**
```
[warning] Контракт #ДОГ-123/2025 приближается к лимиту: 92.5%
Context: {
  "contract_id": 1,
  "organization_id": 1,
  "completed_amount": 925000.00,
  "total_amount": 1000000.00,
  "completion_percentage": 92.5
}
```

## Тестирование

Для проверки работы системы используйте:
```bash
php artisan tinker test_contract_validation.php
```

## Расширения (готовы к реализации)

### Email уведомления
```php
// В notifyContractNearingLimit()
Mail::to($contract->organization->email)
    ->send(new ContractNearingLimitMail($contract));
```

### Push уведомления
```php
// Через broadcasting
broadcast(new ContractLimitWarning($contract));
```

### Dashboard виджеты
- Прогресс-бары выполнения контрактов
- Список контрактов требующих внимания
- Топ контрактов по объему

## Производительность

### Оптимизация запросов
- Используйте `with()` для загрузки связей
- Кэшируйте аналитику для больших контрактов
- Индексы на `contract_id` и `status` в `completed_works`

### Пример оптимизированного запроса
```php
$contracts = Contract::with(['completedWorks' => function($query) {
    $query->where('status', 'confirmed');
}])->get();