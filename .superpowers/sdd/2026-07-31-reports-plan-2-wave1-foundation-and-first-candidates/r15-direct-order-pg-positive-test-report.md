# R15: положительный PostgreSQL-контракт прямого заказа

## Результат

- Фикстура прямого заказа больше не наследует заявку, запрос поставщику, предложение и решение из полной закупочной цепочки.
- Для прямого заказа создаются отдельные заявка и строка заявки, реальный поставщик, сторона поставщика, заказ и позиция заказа.
- Тест сначала сохраняет `request_created` без фактов поставщика, предложения и заказа, затем сохраняет `order_sent` с теми же проектом и зафиксированной версией политики.
- Для `order_sent` проверяются код события, сторона поставщика, заказ, позиция заказа и строго пустая пара предложение/версия предложения.
- Дополнительно проверяется неизменность project/policy/calendar provenance между обоими событиями.

## Границы

- Production-код, миграция R15, RBAC, R22, шаблоны и CI не изменялись.
- PostgreSQL и миграции локально не запускались согласно правилам проекта.

## Проверки

- `php -l tests/Feature/Procurement/Reporting/Cycle/ProcurementCycleSourcePostgresTest.php`
- `php -l tests/Support/Procurement/Reporting/ProcurementCyclePostgresFixture.php`
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G tests/Feature/Procurement/Reporting/Cycle/ProcurementCycleSourcePostgresTest.php tests/Support/Procurement/Reporting/ProcurementCyclePostgresFixture.php`
- `git diff --check`

Все выполненные проверки прошли успешно.
