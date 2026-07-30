# Task 13C — замыкание контура исполнения и наблюдаемости

## Граница

Поправка устраняет подтверждённые замечания финального ревью Task 13/13B.
Task 14 и каталог отчётов в неё не входят.

## Исполняемые инварианты

- Доставка аудита сначала возвращает истёкшие аренды, затем публикует ожидающие идентификаторы.
- Аренда аудита равна 300 секундам и строго превышает timeout задания 120 секунд.
- Пакеты, аренды, максимальное число попыток и watchdog batch собираются в одном типизированном runtime-контракте из `reporting_execution`.
- Задания сохраняют ID-only payload; service locator из отказа audit job удалён.
- Transport publisher, transport/audit stores, watchdogs и исполнители отправляют метрики из реальных production-переходов.
- Пороговые окна формируют критические сигналы для устойчивых ошибок публикации и исполнения, повторных возвратов аренд, регрессии длительности и доли прерванных загрузок.
- Boot проверяет точные реализации reader/resolver/ABAC, собирает resource-authorizer registry и при наличии опубликованного каталога полностью разрешает HTTP authorization orchestrator.
- Отрицательный architecture gate запрещает моделям хранения и service locator возвращаться в jobs, listeners, audit consumers, coordinators и watchdogs.

## Манифест

- `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntentPublisher.php`
- `app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchBackoffPolicy.php`
- `app/BusinessModules/Core/Reporting/Application/Execution/ReportExecutionRuntimeConfiguration.php`
- `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Audit/AppendReportAuditEventJob.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Audit/CoreReportAuditIntentConsumer.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/DeliverReportAuditIntentsCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportExportExecutionLeasesCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportRunExecutionLeasesCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuditIntentStore.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportCompletedArtifactRecoveryStore.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportStore.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/ReportExecutionAlertWindow.php`
- `app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php`
- `routes/console.php`
- `tests/Architecture/Reporting/ReportingExecutionBindingsTest.php`
- `tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php`
- `tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php`
- `tests/Support/Reporting/ReportRuntimeFixture.php`

## Проверка

Один целевой gate охватывает bindings/negative architecture, runtime-аудит и пороговую телеметрию. Проверки с базой данных, авторизационные и браузерные smoke-тесты не запускаются.

Финальная проверка изменённых рисков: architecture и telemetry — 70 тестов, 475 проверок; audit-регрессия — 7 тестов, 49 проверок. PHPStan для изменённого production-кода и форматирование изменённых файлов проходят без ошибок. Независимое повторное ревью двух финальных блокеров — PASS.
