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

```
GET    /api/v1/admin/payments/documents
POST   /api/v1/admin/payments/documents
GET    /api/v1/admin/payments/documents/{id}
PUT    /api/v1/admin/payments/documents/{id}
DELETE /api/v1/admin/payments/documents/{id}
POST   /api/v1/admin/payments/documents/{id}/submit
POST   /api/v1/admin/payments/documents/{id}/register-payment
POST   /api/v1/admin/payments/documents/{id}/cancel

GET    /api/v1/admin/payments/transactions
POST   /api/v1/admin/payments/transactions
POST   /api/v1/admin/payments/transactions/{id}/refund

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

