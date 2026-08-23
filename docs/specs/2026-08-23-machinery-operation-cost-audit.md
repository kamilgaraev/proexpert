# Независимый аудит эксплуатации техники МОСТ

Дата: 2026-08-23
Ветка backend: `audit/machinery-operation-cost-20260823` от `origin/main` (`00b7e611185e59c743e75f4aa9bbe22b5f373f81`)
Ветка admin: `audit/machinery-operation-cost-20260823` от `origin/main` (`9bd1a7ce034286a3cbea83933a8a17b6e5b44828`)
Ветка mobile: `audit/machinery-operation-cost-20260823` от `origin/main` (`f098af15a7b36aa5a34ab6a826c1c582d32c6405`)

## Метод

Источник истины — актуальный `origin/main` каждого репозитория. Предыдущие аудиты и зелёные тесты не считаются доказательством. Для каждого ME-пункта требуется воспроизводимый контрактный или регрессионный тест, минимальное исправление и свежая проверка. Миграции создаются только как код и локально не запускаются.

## Фактическая карта контура

- Реестр и назначение: `OrganizationAsset` → `MachineryAsset` → `MachineryAssignment` → проект/задача графика.
- Смена: `MachineryShiftReport`, мобильные команды create/finish/submit, согласование в admin.
- Факты смены: `MachineryDowntime`, `MachineryProductionRecord`, `MachineryFuelIssue`.
- Техническое состояние: `MachineryDefect`, `MachineryMaintenanceOrder`, `MaintenanceInspection`.
- Себестоимость: `ProjectMarginReportService` и `WipForecastReportService` читают канонические факты склада, смен и завершённого ТО; топливо учитывается только складским движением.
- Mobile offline: `MachineryAction` → `SyncQueueDraft` → `QueuedSyncOperation` → `SyncQueueService`.
- Журнал работ: смена хранит `schedule_task_id` и найденный однозначный `construction_journal_entry_id`; неоднозначная связь не подставляется автоматически и остаётся видимой как reconciliation warning.

## Подтверждённые замечания

### ME-001 — параллельный старт создаёт несколько открытых смен

- Статус: исправлен и проверен.
- Воспроизведение: два POST `/api/v1/mobile/machinery-operations/shift-reports` для одной техники с разными ключами идемпотентности проходят независимо. `createShiftReport()` блокирует технику, но не проверяет существующую открытую смену; уникального DB-инварианта нет.
- Ожидаемый инвариант: одна техника имеет не более одной открытой смены; второй старт возвращает бизнес-конфликт, а повтор той же команды возвращает исходную смену.
- Решение: серверная проверка под блокировкой техники плюс частичный уникальный индекс PostgreSQL для открытых статусов.
- Изменённые файлы: `MachineryOperationsService.php`, `MachineryShiftInvariant.php`, `2026_08_23_000001_harden_machinery_shift_lifecycle.php`, mobile workflow test, переводы.
- Test/evidence: `test_different_commands_cannot_start_two_open_shifts_for_one_asset`; общий PostgreSQL-прогон пяти критичных сценариев — `OK (5 tests, 38 assertions)`.
- Остаточный риск: тест воспроизводит две независимые команды последовательно под реальной PostgreSQL-схемой; DB partial unique index остаётся последней защитой при фактическом одновременном commit.

### ME-002 — завершение смены не является однократным переходом

- Статус: исправлен и проверен.
- Воспроизведение: `finishShift()` обновляет поля, но оставляет `status=draft`; повтор с новым ключом перезаписывает часы, топливо, счётчик и описание.
- Ожидаемый инвариант: завершение выполняется ровно один раз, фиксирует автора/время/исходное состояние и не допускает переписывания факта.
- Решение: атомарный переход `draft → completed`, блокировка строки смены, `finished_by_user_id`, `finished_at`, снимок факта; submit разрешён только из `completed`.
- Изменённые файлы: service, admin/mobile controllers, model/resource, lifecycle migration, admin/mobile contracts и mobile UI.
- Test/evidence: `test_operator_shift_lifecycle_is_idempotent_across_offline_retries`, `test_finish_is_terminal_and_actual_hours_are_derived_from_meter_delta`; общий прогон — `OK (5 tests, 38 assertions)`.
- Остаточный риск: Flutter integration test не запускался без подключённого поддерживаемого устройства; action/repository/UI покрыты unit/widget-проверками.

### ME-003 — показания и часы не защищены сквозной монотонностью

- Статус: исправлен для штатного lifecycle; корректировки выделены как отдельный append-only контракт, но UI корректировки не добавлялся.
- Воспроизведение: start принимает произвольный `meter_start`, не сравнивая его с текущим счётчиком техники; finish принимает клиентский `actual_hours`, не выводя его из `meter_end - meter_start`; approve может записать показание меньше уже согласованного показания другой смены.
- Ожидаемый инвариант: start не ниже текущего серверного счётчика; end не ниже start и текущего счётчика; фактические часы/пробег рассчитываются сервером с точностью схемы; история не переписывается.
- Решение: серверная нормализация показаний под блокировкой canonical profile, расчёт delta и проверка при approve.
- Изменённые файлы: `MachineryShiftInvariant.php`, service/controllers, mobile/admin contracts, unit и feature tests.
- Test/evidence: unit-набор shift/operation invariants — `OK (13 tests, 26 assertions)`; feature delta/terminal finish входит в `OK (5 tests, 38 assertions)`.
- Остаточный риск: отдельный workflow корректировок показаний отсутствует и не будет имитироваться обычным update.

### ME-004 — топливо не привязано к смене и допускается при блокирующем состоянии

- Статус: исправлен и проверен.
- Воспроизведение: `createFuelIssue()` не принимает `shift_report_id`, не проверяет статус смены или техники и создаёт запись при `maintenance/unavailable`; стоимость принимает клиент.
- Ожидаемый инвариант: расход/заправка относятся к открытой смене, оператору и проекту; блокирующее ТО/дефект запрещает операцию; стоимость не вычисляется клиентом.
- Решение: обязательные shift/warehouse/material, проверка actor/project/asset и статуса, складское списание и стоимость на сервере в одной транзакции; idempotency key возвращает исходную запись и движение.
- Изменённые файлы: fuel migration/model/resource, service/controllers, mobile/admin API contracts и tests.
- Test/evidence: `test_fuel_retry_creates_one_warehouse_movement_and_server_cost`; общий прогон — `OK (5 tests, 38 assertions)`. Себестоимость топлива ровно один раз и обратное движение при отмене — `test_project_margin_counts_shift_and_fuel_cost_exactly_once`, `OK (1 test, 15 assertions)`.
- Остаточный риск: отдельная частичная корректировка/возврат топлива вне отмены всей смены требует самостоятельного reasoned adjustment workflow; удаление исходной записи или движения не разрешено.

### ME-005 — ТО/ремонт пересекается с активной эксплуатацией

- Статус: исправлен в переходах эксплуатации; проверка прямого unavailable добавлена после повторного аудита.
- Воспроизведение: `createMaintenanceOrder()` читает технику без `lockForUpdate`, не проверяет открытую смену/другое открытое ТО и переводит технику в maintenance даже во время активной смены.
- Ожидаемый инвариант: открытое блокирующее ТО/ремонт не пересекается с открытой сменой и не дублируется; возврат требует завершённого осмотра.
- Решение: единая блокировка техники, guards открытой смены/заказа и DB unique для открытого блокирующего заказа.
- Изменённые файлы: `MachineryOperationInvariant.php`, service, lifecycle migration, translations, unit/admin feature tests.
- Test/evidence: unit tests запрета ТО во время смены, второго открытого ТО, архивации и переназначения; admin feature `test_admin_cannot_mark_asset_unavailable_while_shift_is_open`.
- Остаточный риск: календарное планирование по порогам счётчика пока не моделируется существующими полями.

### ME-006 — offline-очередь может нарушить порядок после конфликта

- Статус: исправлен и проверен.
- Воспроизведение: `retryDueOperations()` получает только `due()`; после перевода первого элемента в `needs_edit` следующий вызов исключает его и отправляет более позднюю зависимую операцию.
- Ожидаемый инвариант: неразрешённый предшественник блокирует хвост очереди до явного разрешения/отмены; порядок start → operations → finish сохраняется между запусками.
- Решение: обрабатывать упорядоченную полную очередь и останавливаться на первом неготовом/заблокированном элементе; добавить явный conflict status.
- Изменённые файлы: `SyncQueueService`, queue status/model, repository/action contracts, status panel и tests.
- Test/evidence: Flutter machinery/sync tests — `15 passed`; `flutter analyze` затронутых каталогов — `No issues found`.
- Остаточный риск: offline start пока не может автоматически подставить серверный shift id в уже созданную зависимую команду; потребуется доказать текущий UI-сценарий или добавить локальную ссылку.

### ME-007 — обязательный предсменный/послесменный контроль отсутствует в серверном автомате

- Статус: исправлен и проверен.
- Воспроизведение: shift API не содержит сущности/полей контроля; `MachineryDefect` создаётся отдельным endpoint, а non-critical дефекты не участвуют в допуске.
- Ожидаемый инвариант: старт и submit/approve требуют соответствующего контроля; блокирующие дефекты меняют серверный допуск.
- Решение: использовать отдельные append-only inspection records, а не UI-флаг; severity/result определяют допуск.
- Изменённые файлы: inspection model/migration, defect link, service/controllers/resources, admin/mobile contracts, mobile UI и tests.
- Test/evidence: `test_blocking_pre_shift_inspection_preserves_defect_and_prevents_operation`; входит в `OK (5 tests, 38 assertions)`. Mobile tests — `15 passed`.
- Остаточный риск: объём может потребовать отдельного релизного блока, если универсальной inspection-модели нет.

### ME-008 — себестоимость не связана с журналом работ и не является проводочным фактом

- Статус: исправлен и проверен в канонических project margin/WIP источниках.
- Воспроизведение: `MachineryCostService` агрегирует mutable operational rows запросом; `MachineryShiftReport` не содержит ссылки на запись журнала работ; в project actual-cost источниках техника явно не зарегистрирована.
- Ожидаемый инвариант: согласованный факт смены, топлива и ремонта учитывается ровно один раз и трассируется до проекта/графика/журнала.
- Решение: подключить только к существующему каноническому факту/проекции, без параллельного ledger.
- Изменённые файлы: shift journal-link migration/model/resource/service; `ProjectMarginReportService`, `WipForecastReportService`, `ProjectFinanceQueryService`, переводы и API types.
- Test/evidence: `test_project_margin_counts_shift_and_fuel_cost_exactly_once` — `OK (1 test, 15 assertions)`: одна строка `machinery_shift`, одна `warehouse_movement`, единое серверное округление суммы `5 × 1200 + 10.005 × 75.25 = 6752.88` в Project Margin и WIP; после отмены исходное списание исключается, а возврат не становится вторым расходом.
- Остаточный риск: автоматическая связь с журналом возможна только при единственной submitted/approved записи для project/task/date; неоднозначность намеренно не разрешается эвристикой.

### ME-009 — аннулирование смены не создавало обратных складских движений

- Статус: исправлен и проверен.
- Воспроизведение: канонического перехода отмены смены не было; связанное списание топлива продолжало попадать в складскую стоимость, а удаление нарушило бы историю.
- Ожидаемый инвариант: смена и топливная операция остаются в истории с actor/time/reason; складское списание неизменно, создаётся отдельное обратное движение; повтор команды не создаёт второй возврат.
- Решение: `cancelShift()` под блокировками, audit-поля смены/топлива, отдельное receipt-reversal через штатный `WarehouseService`, явная ссылка `reversal_movement_id`; cost projection исключает исходное списание через аннулированную топливную операцию.
- Изменённые файлы: cancellation migration/model/resource/service/admin route/API contract, Project Margin/WIP warehouse sources, tests/translations.
- Test/evidence: первый красный прогон подтвердил DB-защиту `linked warehouse movement identity is immutable`; реализация перестала изменять исходное движение. Зелёный прогон exact-once/reversal — `OK (1 test, 12 assertions)`, включая повтор cancel и восстановление складского остатка.
- Остаточный риск: отдельная частичная корректировка количества топлива вне отмены всей смены не добавлялась; для неё нужен самостоятельный reasoned adjustment workflow.

### ME-010 — роли мобильного контура смешивали обязанности оператора, кладовщика и руководителя

- Статус: исправлен и проверен.
- Воспроизведение: роль `foreman` одновременно могла начинать смену и регистрировать топливо, тогда как отдельные роли оператора техники и кладовщика уже присутствуют в JSON definitions.
- Ожидаемый инвариант: оператор ведёт собственную смену, кладовщик оформляет топливо, механик регистрирует простой/ремонт, руководитель согласует; проверки маршрутов используют JSON definitions, `RoleMiddleware` и `AuthorizationService`, без hardcoded role slug в бизнес-сервисе.
- Решение: право `machinery-operations.fuel.manage` передано `storekeeper`; у `foreman` оставлены согласование смен и простой, старт смены относится к `machine_operator`.
- Изменённые файлы: `config/RoleDefinitions/mobile/foreman.json`, `config/RoleDefinitions/mobile/storekeeper.json`, `tests/Unit/Mobile/MobileAccessRoutesTest.php`.
- Test/evidence: `test_machinery_workflow_permissions_are_separated_by_operational_role` — `OK (1 test, 12 assertions)`; admin workflow/RBAC regression — `OK (4 tests, 53 assertions)`.
- Остаточный риск: отдельной сущности удостоверений/квалификаций оператора в текущей модели МОСТ нет; подтверждённая серверная граница сейчас основана на роли, назначении, организации и проекте.

## Проверенные без дефекта инварианты

- Назначение защищено organization/project scope и PostgreSQL exclusion constraint по периоду.
- Mobile mutation с одним и тем же непустым `Idempotency-Key` возвращает исходную модель и отклоняет другой payload.
- Списки смен и ТО имеют `per_page` с верхней границей 100; основные relations загружаются eager.

## Журнал evidence

1. PHP unit invariants: `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/MachineryOperations/MachineryShiftInvariantTest.php tests/Unit/MachineryOperations/MachineryOperationInvariantTest.php` → `OK (13 tests, 26 assertions)`.
2. PostgreSQL lifecycle/fuel/inspection: целевой filter в `MachineryOperationsMobileWorkflowTest.php` → `OK (5 tests, 38 assertions)`.
3. Project cost exact-once, денежное округление Project Margin/WIP и rollback/reversal: `--filter test_project_margin_counts_shift_and_fuel_cost_exactly_once` → `OK (1 test, 15 assertions)`.
4. Flutter: целевые machinery/sync tests → `15 passed`; analyze затронутых каталогов → `No issues found`.
5. Admin: `npx tsc --noEmit` → exit code 0; ESLint четырёх затронутых файлов → exit code 0; целевой Vitest → локальный файл `5 passed`, совокупный discovery-набор `96 passed`.
6. Backend admin workflow/RBAC: `OK (4 tests, 53 assertions)` и `OK (1 test, 12 assertions)`.
7. Larastan/PHPStan по затронутому backend-блоку → `[OK] No errors`; Pint `--test` → `PASS 28 files`; `git diff --check` → без ошибок.
8. PHP syntax: все изменённые executable PHP и пять миграций → `No syntax errors detected`.

Ручные команды миграции не выполнялись. PostgreSQL-схема создавалась исключительно штатным test bootstrap PHPUnit в отдельной БД `most_backend_testing`.
