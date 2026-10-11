# Передача давальческих материалов подрядчикам

## Сценарий

Передающая организация оформляет накладную М-15. При передаче подрядчику с собственной организацией в МОСТ у получателя доступен приходный ордер М-4 с отметкой о давальческом материале. Для этого обычного прихода М-7 не предлагается. При передаче внешнему подрядчику без организации в системе материал перемещается на его внешний склад; исходящая запись доступна как М-15.

Если пользователь не указал номер накладной, система формирует его из организации и ключа операции. Повтор запроса сохраняет номер и не создаёт дополнительную передачу. У ранее созданной исходящей передачи без номера история и М-15 используют обозначение `М-15-{идентификатор движения}` без изменения старой записи в БД.

Учётная цена сохраняется. Отсутствие оплаты давальческого материала не означает автоматическую нулевую оценку: [Инструкция к счёту 003](https://www.consultant.ru/document/cons_doc_LAW_29165/d080a5dc08a322d09d091cecdd0fa1c7ffeae8b0/) предусматривает оценку по договору. Изменение проводок, собственности складских партий или финансовых расчётов не входит в эту правку.

## Отчёт

В разделе «Отчёты», категории «Подрядчики», доступен отчёт «Давальческие материалы подрядчикам». Он показывает получателя, материал, количество и единицу измерения, дату передачи, склад, проект, учётную цену и стоимость, основание и номер М-15. Можно найти подрядчика или материал по названию, задать период и ограничить данные выбранным проектом.

Источник — исходящие движения текущей организации с признаком передачи подрядчику. Учитываются `write_off` при межорганизационной передаче и `transfer_out` при перемещении на внешний склад. Приходные записи и `transfer_in` исключены, поэтому передача не учитывается дважды. Архивирование склада, материала или подрядчика не убирает совершённые передачи из отчёта.

Итоги показывают число передач, подрядчиков и материалов, а также общую учётную стоимость по всему фильтру. Количества разных материалов и единиц не суммируются. Страница отчёта и её итоги читаются в одной транзакции; CSV формируется по тем же применённым фильтрам на момент выгрузки, включая все страницы. Экспорт ограничен 5000 строками. Это оперативный реестр передач; расход, возвраты и остаток у подрядчика он не рассчитывает.

Просмотр требует `warehouse.reports`, CSV дополнительно требует `warehouse.reports.export`, открытие М-15 — `warehouse.transfers`. Проверки организации и прав выполняются на сервере. Файлы экспорта сохраняются в приватном хранилище организации и пользователя.

## API

- `GET /api/v1/admin/reports/warehouse-catalog` — доступные оперативные складские отчёты.
- `GET /api/v1/admin/reports/contractor-material-transfers` — строки, итоги и пагинация.
- `GET /api/v1/admin/reports/contractor-material-transfers/export` — ссылка на CSV.
- Фильтры: `search`, `date_from`, `date_to`, `contractor_id`, `material_id`, `warehouse_id`, `project_id`, `page`, `per_page`.
- В ответ движения добавлены необязательные для старых клиентов поля `available_documents` и `is_tolling_material`; существующие типы движения сохранены.
- Дополнительные миграции и изменение старых данных не требуются.

## Проверки

Регрессии проверяют выбор М-15/М-4, сохранение цены, доступность старых записей, повтор запросов, исключение приходов из отчёта, привязку запроса к организации, безопасную передачу поисковой строки в SQL, разграничение экспорта и группировку накладных по подрядчику. Интерфейс проверяется для поиска, фильтров, пагинации, документов, экспорта, ошибок и пустого результата.

Проверки PostgreSQL и HTTP-сценария на реальной БД требуют разрешения с указанием тестового окружения согласно `AGENTS.md`. Выпуск выполняется из main штатными GitHub Actions после слияния Pull Request; миграции этой задачи не требуются.

## Изменённые файлы и результаты проверок

prohelper:

- `app/BusinessModules/Features/BasicWarehouse/Controllers/ContractorMaterialTransferReportController.php`
- `app/BusinessModules/Features/BasicWarehouse/DTO/ContractorMaterialTransferRow.php`
- `app/BusinessModules/Features/BasicWarehouse/Http/Requests/ContractorMaterialTransferReportRequest.php`
- `app/BusinessModules/Features/BasicWarehouse/Http/Resources/WarehouseMovementResource.php`
- `app/BusinessModules/Features/BasicWarehouse/Models/WarehouseMovement.php`
- `app/BusinessModules/Features/BasicWarehouse/Queries/ContractorMaterialTransferQuery.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/ContractorMaterialTransferReportService.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/ContractorTransferDocument.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/ContractorTransferService.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/Export/Forms/M15/M15ExportStrategy.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/Export/Forms/M4/M4ExportStrategy.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/Export/WarehouseMovementDocumentResolver.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/Export/WarehouseMovementFormExportService.php`
- `app/BusinessModules/Features/BasicWarehouse/Services/WarehouseService.php`
- `app/BusinessModules/Features/BasicWarehouse/routes.php`
- `docs/specs/contractor-tolling-material-transfers.md`
- `lang/ru/warehouse_basic.php`
- `tests/Unit/BasicWarehouse/ContractorTollingMaterialTest.php`

prohelper_admin:

- `src/App.tsx`
- `src/pages/Reports/ContractorMaterialTransferReportPage.test.tsx`
- `src/pages/Reports/ContractorMaterialTransferReportPage.tsx`
- `src/pages/Reports/ReportsPage.test.tsx`
- `src/pages/Reports/ReportsPage.tsx`
- `src/pages/Reports/components/reportsCatalog.types.ts`
- `src/pages/Reports/components/reportsCatalog.utils.ts`
- `src/pages/Reports/publishedReportDefinitions.ts`
- `src/pages/Warehouse/components/MovementHistoryDialog.test.tsx`
- `src/pages/Warehouse/utils/warehouseDocumentPolicy.test.ts`
- `src/pages/Warehouse/utils/warehouseDocumentPolicy.ts`
- `src/services/reportService.test.ts`
- `src/services/reportService.ts`
- `src/types/contractorMaterialTransferReport.ts`
- `src/types/warehouse.ts`

Проверки актуального main: 21 PHP-тест (101 проверка), 55 целевых Vitest-тестов, PHPStan/Larastan и ESLint для изменённых файлов, полная проверка TypeScript, production-сборка админки, проверка ссылок на ассеты и diff. Интеграционные тесты PostgreSQL не запускались: AGENTS.md требует отдельного разрешения с указанием локального тестового окружения.
