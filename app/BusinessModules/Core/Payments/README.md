# Payments Module

Базовый (Core) системный модуль для управления счетами (invoices), платежами (payment transactions), графиками платежей и взаиморасчетами между организациями в холдинге.

## Ключевые особенности

- **Project-based изоляция** - опционально без проекта для общих расходов
- **Polymorphic связь** с документами (акты, договоры, склад, сметы)
- **Поддержка холдингов** и взаиморасчетов между организациями
- **Частичные оплаты** и графики платежей
- **Автоматическая просрочка** и напоминания
- **Дебиторская/кредиторская задолженность**

## Основные сущности

### Invoice (Счёт)
Финансовое обязательство - кто кому должен оплатить

### PaymentTransaction (Транзакция платежа)
Фактический платёж - перевод средств

### PaymentSchedule (График платежей)
Этапные платежи и рассрочка

### CounterpartyAccount (Взаиморасчёты)
Учёт взаимных обязательств между организациями

## Интеграция

Модуль интегрируется с:
- ActReporting - создание счетов по актам
- ContractManagement - графики платежей по договорам
- BasicWarehouse/AdvancedWarehouse - оплата поставщикам
- BudgetEstimates - этапные платежи по сметам

## API Endpoints

```
GET    /api/v1/admin/payments/invoices
POST   /api/v1/admin/payments/invoices
GET    /api/v1/admin/payments/invoices/{id}
PUT    /api/v1/admin/payments/invoices/{id}
DELETE /api/v1/admin/payments/invoices/{id}
POST   /api/v1/admin/payments/invoices/{id}/pay
POST   /api/v1/admin/payments/invoices/{id}/cancel

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
- `payments.invoice.create` - создание счетов
- `payments.invoice.edit` - редактирование счетов
- `payments.invoice.cancel` - отмена счетов
- `payments.transaction.register` - регистрация платежей
- `payments.transaction.approve` - подтверждение платежей
- `payments.reports.view` - просмотр отчётов

