# Модуль "Управление закупками" (Procurement)

## Описание

Модуль "Управление закупками" предоставляет комплексную систему управления процессом закупок материалов: от заявок до оплаты счетов поставщиков и приема материалов на склад.

## Основные возможности

- ✅ Создание заявок на закупку из заявок с объекта
- ✅ Управление заказами поставщикам
- ✅ Прием и обработка коммерческих предложений
- ✅ Создание договоров поставки
- ✅ Интеграция с модулем платежей (автоматическое создание счетов)
- ✅ Интеграция со складом (автоматический прием материалов)
- ✅ Выбор поставщиков и сравнение КП
- ✅ Workflow закупок с отслеживанием статусов
- ✅ Дашборд и статистика по закупкам
- ✅ История всех операций

## Зависимости

Модуль требует активации следующих модулей:

- `organizations` - базовый модуль организаций
- `users` - модуль пользователей
- `basic-warehouse` - базовое управление складом (обязательно)
- `site-requests` - заявки с объекта (обязательно)

Опциональные интеграции:

- `payments` - для автоматического создания счетов
- `contract-management` - для расширенного управления договорами

## Архитектура

### Основные сущности

1. **PurchaseRequest** (Заявка на закупку)
   - Связь с `SiteRequest` (заявка с объекта)
   - Статусы: draft, pending, approved, rejected, cancelled

2. **PurchaseOrder** (Заказ поставщику)
   - Связь с `PurchaseRequest` и `Supplier`
   - Статусы: draft, sent, confirmed, in_delivery, delivered, cancelled

3. **SupplierProposal** (Коммерческое предложение)
   - Связь с `PurchaseOrder` и `Supplier`
   - Статусы: draft, submitted, accepted, rejected, expired

4. **Contract** (Договор поставки)
   - Расширение существующей модели `Contract`
   - Использует `supplier_id` вместо `contractor_id`
   - Категория: `procurement`

### Workflow

1. **Создание заявки на закупку**
   - Автоматически при одобрении заявки с объекта на материалы
   - Или вручную через API

2. **Одобрение заявки**
   - Менеджер по закупкам одобряет заявку

3. **Создание заказа поставщику**
   - Выбор поставщика
   - Создание заказа

4. **Отправка заказа поставщику**
   - Заказ отправляется поставщику

5. **Получение КП от поставщика**
   - Поставщик отправляет коммерческое предложение

6. **Принятие КП и создание договора**
   - Выбор лучшего КП
   - Создание договора поставки

7. **Создание счета на оплату**
   - Автоматически при создании заказа (если включено в настройках)

8. **Получение материалов на склад**
   - Автоматически при получении материалов (если включено в настройках)

## API Endpoints

### Заявки на закупку

- `GET /api/v1/admin/procurement/purchase-requests` - список заявок
- `POST /api/v1/admin/procurement/purchase-requests` - создание заявки
- `GET /api/v1/admin/procurement/purchase-requests/{id}` - просмотр заявки
- `POST /api/v1/admin/procurement/purchase-requests/{id}/approve` - одобрение
- `POST /api/v1/admin/procurement/purchase-requests/{id}/reject` - отклонение
- `POST /api/v1/admin/procurement/purchase-requests/{id}/create-order` - создание заказа

### Заказы поставщикам

- `GET /api/v1/admin/procurement/purchase-orders` - список заказов
- `POST /api/v1/admin/procurement/purchase-orders` - создание заказа
- `GET /api/v1/admin/procurement/purchase-orders/{id}` - просмотр заказа
- `POST /api/v1/admin/procurement/purchase-orders/{id}/send` - отправка поставщику
- `POST /api/v1/admin/procurement/purchase-orders/{id}/confirm` - подтверждение
- `POST /api/v1/admin/procurement/purchase-orders/{id}/create-contract` - создание договора

### Коммерческие предложения

- `GET /api/v1/admin/procurement/proposals` - список КП
- `POST /api/v1/admin/procurement/proposals` - создание КП
- `GET /api/v1/admin/procurement/proposals/{id}` - просмотр КП
- `POST /api/v1/admin/procurement/proposals/{id}/accept` - принятие КП
- `POST /api/v1/admin/procurement/proposals/{id}/reject` - отклонение КП

### Договоры поставки

- `GET /api/v1/admin/procurement/contracts` - список договоров поставки
- `POST /api/v1/admin/procurement/contracts` - создание договора поставки
- `GET /api/v1/admin/procurement/contracts/{id}` - просмотр договора

### Дашборд

- `GET /api/v1/admin/procurement/dashboard` - данные дашборда
- `GET /api/v1/admin/procurement/dashboard/statistics` - статистика

## Интеграции

### SiteRequests → Procurement

При одобрении заявки с объекта на материалы автоматически создается заявка на закупку (если включено в настройках).

**Событие:** `SiteRequestApproved`
**Слушатель:** `CreatePurchaseRequestFromSiteRequest`

### Procurement → Payments

При создании заказа поставщику автоматически создается счет на оплату (если включено в настройках).

**Событие:** `PurchaseOrderCreated`
**Слушатель:** `CreateInvoiceFromPurchaseOrder`

### Procurement → BasicWarehouse

При получении материалов от поставщика автоматически обновляется склад (если включено в настройках).

**Событие:** `MaterialReceivedFromSupplier`
**Слушатель:** `UpdateWarehouseOnMaterialReceipt`

### Procurement → Contracts

Договоры поставки используют существующую модель `Contract` с расширением:
- Добавлено поле `supplier_id`
- Добавлено поле `contract_category` (значение: `procurement`)
- Используется `work_type_category = SUPPLY`

## Настройки модуля

Модуль поддерживает следующие настройки:

```php
[
    // Общие настройки
    'enable_notifications' => true,
    'auto_create_purchase_request' => true,
    'auto_create_invoice' => true,
    'auto_receive_to_warehouse' => true,

    // Workflow
    'require_approval' => true,
    'require_supplier_selection' => true,
    'default_currency' => 'RUB',

    // Уведомления
    'notify_on_request_created' => true,
    'notify_on_order_sent' => true,
    'notify_on_proposal_received' => true,
    'notify_on_material_received' => true,

    // Интеграции
    'enable_site_requests_integration' => true,
    'enable_payments_integration' => true,
    'enable_warehouse_integration' => true,

    // Кеширование
    'cache_ttl' => 300,
]
```

## Permissions

Модуль предоставляет следующие permissions:

- `procurement.view` - просмотр модуля
- `procurement.manage` - управление модулем
- `procurement.purchase_requests.*` - управление заявками на закупку
- `procurement.purchase_orders.*` - управление заказами
- `procurement.supplier_proposals.*` - управление КП
- `procurement.contracts.*` - управление договорами поставки
- `procurement.dashboard.view` - просмотр дашборда
- `procurement.statistics.view` - просмотр статистики

## Установка

1. Модуль автоматически обнаруживается через `ModuleScanner`
2. ServiceProvider зарегистрирован в `bootstrap/providers.php`
3. Миграции загружаются автоматически при активации модуля

## Использование

### Создание заявки на закупку из заявки с объекта

```php
$purchaseRequestService = app(\App\BusinessModules\Features\Procurement\Services\PurchaseRequestService::class);
$purchaseRequest = $purchaseRequestService->createFromSiteRequest($siteRequest);
```

### Создание заказа поставщику

```php
$purchaseOrderService = app(\App\BusinessModules\Features\Procurement\Services\PurchaseOrderService::class);
$order = $purchaseOrderService->create($purchaseRequest, $supplierId, [
    'total_amount' => 100000,
    'currency' => 'RUB',
    'delivery_date' => now()->addDays(7),
]);
```

### Создание договора поставки

```php
$contractService = app(\App\BusinessModules\Features\Procurement\Services\PurchaseContractService::class);
$contract = $contractService->createFromOrder($order);
```

## Расширение модели Contract

Модуль расширяет существующую модель `Contract` для поддержки договоров поставки:

- Добавлено поле `supplier_id` (nullable, FK → suppliers)
- Добавлено поле `contract_category` (enum: 'work' | 'procurement' | 'service')
- Добавлен relationship `supplier()`
- Добавлен scope `procurementContracts()`
- Добавлен метод `isProcurementContract()`

## Валидация

При создании договора поставки проверяется:

1. Активация модулей `procurement` и `basic-warehouse`
2. Либо `contractor_id`, либо `supplier_id` должен быть заполнен
3. Нельзя указать оба одновременно
4. Существование поставщика в организации

## Лимиты

- `max_purchase_requests_per_month` - максимальное количество заявок в месяц (null = без лимита)
- `max_purchase_orders_per_month` - максимальное количество заказов в месяц (null = без лимита)
- `max_supplier_proposals_per_order` - максимальное количество КП на заказ (10)
- `retention_days` - срок хранения данных (365 дней)

## Версия

Текущая версия: **1.0.0**

## Лицензия

Proprietary - ProHelper Platform

