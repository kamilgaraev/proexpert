# Очистка прежнего backend-контура отчётов

Дата: 2026-07-29

Статус: `DONE_WITH_CONCERNS`

Ветка: `feat/reports-backend-legacy-cleanup`

Базовый commit: `27016d9d9867382c43dda1fb86392c11396e4212`

## Результат

Удалена доказанно недостижимая HTTP-оболочка прежнего монолитного генератора отчётов и неиспользуемый оптимизатор запросов. Живые сервисы и потребители сохранены. Миграция живого consumer-кластера на канонический reporting-контур не выполнялась: в production-коде ещё нет реализаций и wiring канонического `ReportDataProvider`, которые могли бы сохранить существующий контракт.

## Доказательство мёртвого кода

### Старый `ReportController`

- `routes/api.php` не подключает `routes/api/v1/admin/reports.php`.
- Файл `routes/api/v1/admin/reports.php` отсутствует.
- `ReportingContractsServiceProvider` загружает только `app/BusinessModules/Core/Reporting/routes.php`.
- `tests/Architecture/Reporting/ReportingRouteSnapshotTest.php` фиксирует отсутствие legacy URI и legacy route-агрегатора.
- Статический поиск по `app`, `routes`, `config`, `bootstrap` и `tests` до удаления находил `App\Http\Controllers\Api\V1\Admin\ReportController` только в его собственном файле; ссылок в route registration, service providers, container bindings и тестах не было.

### Старые FormRequest

Двенадцать request-классов использовались только удалённым `ReportController`. `MaterialUsageReportRequest` не имел потребителей вообще. После удаления статический поиск по production-коду и тестам не находит ссылок на эти классы.

### `ReportQueryOptimizer`

Поиск `ReportQueryOptimizer`, `reportQueryOptimizer` и `report_query_optimizer` по репозиторию до удаления находил только объявление класса. Container binding, конструкторная зависимость, фабрика, строковый dynamic lookup и тесты отсутствовали.

## Удалённые пути

- `app/Http/Controllers/Api/V1/Admin/ReportController.php`
- `app/Http/Requests/Api/V1/Admin/Report/ActReportsReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ContractPaymentsReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ContractorSettlementsReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ForemanActivityReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/MaterialMovementsReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/MaterialUsageReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/OfficialMaterialUsageReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ProjectProfitabilityReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ProjectStatusSummaryReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/ProjectTimelinesReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/TimeTrackingReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/WarehouseStockReportRequest.php`
- `app/Http/Requests/Api/V1/Admin/Report/WorkCompletionReportRequest.php`
- `app/Services/Report/ReportQueryOptimizer.php`

Итого: 15 файлов, 1131 удалённая строка.

## Сохранённые живые зависимости

`App\Services\Report\ReportService` не удалён и не изменён, потому что остаётся runtime-зависимостью:

- `app/Http/Controllers/Api/V1/Admin/TimeTrackingController.php`;
- `GenerateContractorSettlementsReportTool`;
- `GenerateContractPaymentsReportTool`;
- `GenerateMaterialMovementsReportTool`;
- `GenerateProfitabilityReportTool`;
- `GenerateProjectTimelinesReportTool`;
- `GenerateTimeTrackingReportTool`;
- `GenerateWarehouseStockReportTool`;
- `GenerateWorkCompletionReportTool`.

Также не изменялись `MaterialReportService`, `ContractorReportService`, `AdvanceAccountReportService`, `ReportTemplateService`, `ReportFile`, старый module ownership и весь `app/BusinessModules/Core/Reporting/**`.

## Blocker миграции живого consumer-кластера

Канонический контракт `App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider` определён, но production-код не содержит ни одной его реализации. `ReportingContractsServiceProvider` регистрирует только error/access services; registry, binding assembler, data provider и execution/catalog actions не связаны. Это дополнительно зафиксировано тестом `provider_leaves_execution_and_catalog_actions_unbound`.

Существующие consumers ожидают синхронный массив либо `StreamedResponse` от конкретных методов `ReportService`, тогда как канонический порт требует `materialize()`/`result()` с `ReportExecutionContext`, `ReportQuery`, progress и snapshot. Без production provider, registry binding и доказанного паритета полей подмена создала бы новый адаптер-заглушку и изменила поведение. Поэтому live cutover отложен до завершения wiring.

## Проверки

- `php -l`: успешно для 18 сохранённых файлов на границе старого сервиса, живых consumers и канонического provider-контракта.
- Targeted PHPStan: `No errors` для `app/Services/Report`, `TimeTrackingController`, AI report tools, `ReportDataProvider` и `ReportingContractsServiceProvider`.
- Герметичные tests без DB: `ReportingRouteSnapshotTest` и `ReportProviderPortContractTest` — `26 tests`, `112 assertions`, успешно.
- Расширенный пробный запуск тех же contract tests вместе с двумя существующими AI-tool tests: contract tests прошли, два AI-tool tests завершились bootstrap-ошибками `cad_python_path_invalid` и отсутствующего binding `filesystem`. Ошибки возникают до проверяемого поведения из-за несвязанного общего bootstrap; конфигурация CAD/FS не подменялась.
- `git diff --check`: успешно.

## Ограничения волны

- Не выполнялись DB-команды, миграции, tinker, запуск серверов и сборок.
- Не изменялись production-данные и внешние системы.
- Не изменялся 19-path manifest Task8.
- Не добавлялись fallback, временные adapters или новые compatibility-слои.
