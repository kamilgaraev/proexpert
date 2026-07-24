# Миграция расчета трудозатрат

## Текущее состояние

Таблица `production_labor_payroll_accruals` является историческим хранилищем начислений производственного модуля. Новые записи в нее не создаются и не используются при формировании актуальной ведомости. Модель, ресурс и маршрут `GET /api/v1/admin/production-labor/payroll-accruals` сохранены только для просмотра уже существующих данных.

Исторические статусы начислений (`prepared`, `approved`, `exported`) остаются частью read-only представления и не означают, что производственный модуль формирует новые начисления.

## Целевой источник истины

Единый pipeline расчета находится в модуле управления персоналом:

1. Табельные строки производственного модуля с `include_in_payroll = true` и назначенным сотрудником попадают в `workforce_payroll_source_rows` через `POST /api/v1/admin/workforce/payroll-periods/{periodId}/build-source`.
2. Источник сверяется с назначениями сотрудников, графиками, отсутствиями и фактической выработкой через `POST /api/v1/admin/workforce/payroll-periods/{periodId}/validate`. Результаты проверки хранятся в `workforce_payroll_validation_issues`.
3. После успешной проверки создается ведомость в `workforce_payroll_statements` и ее строки в `workforce_payroll_statement_rows` через `POST /api/v1/admin/workforce/payroll-periods/{periodId}/statements`.
4. Период и его источник фиксируются через `POST /api/v1/admin/workforce/payroll-periods/{periodId}/lock`; экспорт формируется через `POST /api/v1/admin/workforce/payroll-periods/{periodId}/export-packages`.

Расчетный период в `workforce_payroll_periods`, его source rows, validation issues, statements и statement rows образуют единственный актуальный контур расчета. Производственные наряды, фактическая выработка и табель служат входными данными этого контура, а не независимым источником денежных начислений.

## Удаленные активные пути

Удалены активные маршрут и обработчик подготовки начислений производственного модуля: `POST /api/v1/admin/production-labor/payroll-accruals/prepare`. Удалены связанные frontend-вызовы, кнопки и действия. В UI не должно быть API-константы, сервиса или действия, создающего записи в `production_labor_payroll_accruals`.

Маршрут `GET /api/v1/admin/production-labor/payroll-accruals` не относится к удаленному workflow и сохраняется для исторического просмотра.

## Проверка после изменения UI

1. Поиск по admin-коду не должен находить `payroll-accruals/prepare`, `preparePayroll`, `PREPARE_PAYROLL`, `prepare_payroll`, `prepare-payroll` или `PayrollPreparation`.
2. Создание и изменение наряда, выработки и табеля не должны выполнять POST-запросы к `/production-labor/payroll-accruals`.
3. В панели браузера денежные операции должны выполняться только по маршрутам `/workforce/payroll-periods/*`.
4. После построения источника проверить, что новые данные находятся в `workforce_payroll_source_rows`, а количество строк в `production_labor_payroll_accruals` не меняется.
