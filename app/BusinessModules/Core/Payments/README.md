# Payments Module

Базовый (Core) системный модуль для управления платежными документами (payment documents), платежами (payment transactions), графиками платежей и взаиморасчетами между организациями в холдинге.

## Ключевые особенности

- **Project-based изоляция** - опционально без проекта для общих расходов
- **Polymorphic связь** с документами (акты, договоры, склад, сметы)
- **Поддержка холдингов** и взаиморасчетов между организациями
- **Частичные оплаты** и графики платежей
- **Автоматическая просрочка** и напоминания
- **Дебиторская/кредиторская задолженность**
- **Workflow утверждения** платежных документов
- **Двустороннее взаимодействие** - получатели, зарегистрированные в системе, видят входящие документы и могут подтверждать получение

## Основные сущности

### PaymentDocument (Платежный документ)
Единая сущность для всех типов платежных документов: счета, платежные требования, платежные поручения и т.д.
Поддерживает направление (incoming/outgoing), тип счета (act, advance, progress и т.д.) и polymorphic связь с источниками.

### PaymentTransaction (Транзакция платежа)
Фактический платёж - перевод средств, связанный с PaymentDocument

### PaymentSchedule (График платежей)
Этапные платежи и рассрочка, связанные с PaymentDocument

### CounterpartyAccount (Взаиморасчёты)
Учёт взаимных обязательств между организациями

## Интеграция

Модуль интегрируется с:
- ActReporting - создание документов по актам
- ContractManagement - графики платежей по договорам
- BasicWarehouse/AdvancedWarehouse - оплата поставщикам
- BudgetEstimates - этапные платежи по сметам

## API Endpoints

### Основные операции с документами
```
GET    /api/v1/admin/payments/documents
POST   /api/v1/admin/payments/documents
GET    /api/v1/admin/payments/documents/{id}
PUT    /api/v1/admin/payments/documents/{id}
DELETE /api/v1/admin/payments/documents/{id}
POST   /api/v1/admin/payments/documents/{id}/submit
POST   /api/v1/admin/payments/documents/{id}/register-payment
POST   /api/v1/admin/payments/documents/{id}/cancel
```

### API для получателей (входящие документы)
```
GET    /api/v1/admin/payments/incoming/documents
GET    /api/v1/admin/payments/incoming/documents/{id}
POST   /api/v1/admin/payments/incoming/documents/{id}/view
POST   /api/v1/admin/payments/incoming/documents/{id}/confirm
GET    /api/v1/admin/payments/incoming/statistics
```

### Транзакции
```
GET    /api/v1/admin/payments/transactions
POST   /api/v1/admin/payments/transactions
POST   /api/v1/admin/payments/transactions/{id}/refund
```

### Отчеты
```
GET    /api/v1/admin/payments/reports/receivables
GET    /api/v1/admin/payments/reports/payables
GET    /api/v1/admin/payments/reports/cash-flow
GET    /api/v1/admin/payments/reports/aging
```

## Permissions

- `payments.view` - просмотр платежей
- `payments.document.create` - создание документов
- `payments.document.edit` - редактирование документов
- `payments.document.cancel` - отмена документов
- `payments.transaction.register` - регистрация платежей
- `payments.transaction.approve` - подтверждение платежей
- `payments.reports.view` - просмотр отчётов

## Двустороннее взаимодействие

Модуль поддерживает опциональное двустороннее взаимодействие с получателями платежей.

### Определение получателя-организации

Получатель считается зарегистрированным в системе, если:
1. `payee_organization_id` заполнено (прямая связь)
2. ИЛИ `payee_contractor_id` → `contractor.source_organization_id` заполнено (через подрядчика)

### Функциональность для зарегистрированных получателей

Если получатель зарегистрирован как организация:
- ✅ Видит входящие документы через API `/api/v1/admin/payments/incoming/documents`
- ✅ Получает уведомления о создании документов и регистрации платежей
- ✅ Может подтверждать получение платежа через API
- ✅ Видит статистику входящих платежей

### Graceful Degradation

Если получатель НЕ зарегистрирован:
- ✅ Документ создается и работает как обычно
- ✅ Уведомления не отправляются (нет кому)
- ✅ Подтверждение получения недоступно
- ✅ Система работает в одностороннем режиме (как раньше)

**Важно:** Отсутствие получателя-организации не вызывает ошибок и не ломает логику системы.

### Примеры использования

#### Создание документа для зарегистрированного получателя
```php
$document = $service->create([
    'payee_organization_id' => 123, // Организация зарегистрирована
    // ... другие поля
]);
// Автоматически определится recipient_organization_id
// Получатель получит уведомление
```

#### Создание документа для незарегистрированного получателя
```php
$document = $service->create([
    'payee_contractor_id' => 456, // Подрядчик без source_organization_id
    // ... другие поля
]);
// recipient_organization_id останется null
// Документ работает как обычно, уведомления не отправляются
```

