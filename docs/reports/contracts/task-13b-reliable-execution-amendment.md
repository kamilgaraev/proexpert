# Task 13B — контур надёжного исполнения отчётов

> Финальное ревью выявило незамкнутые runtime-инварианты. Их исправление и
> актуальное доказательство зафиксированы в
> `task-13c-runtime-reliability-amendment.md`; этот документ сохраняет
> историческую границу Task 13B и не является финальным evidence.

## Граница изменения

Поправка подготовлена поверх Task 13 (`909da5e8d`) и не включает работы Task 14.

## Манифест

- `app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php`
- `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Audit/AppendReportAuditEventJob.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Audit/LaravelReportAuditDispatcher.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/PublishReportDispatchIntentsCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportDispatchIntentsCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportExportExecutionLeasesCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportRunExecutionLeasesCommand.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Listeners/FinalizeFailedReportExportAttempt.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Listeners/FinalizeFailedReportRunAttempt.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php`
- `app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php`
- `lang/ru/reports.php`
- `routes/console.php`
- `tests/Architecture/Reporting/ReportingExecutionBindingsTest.php`
- `tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php`
- `tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php`
- `tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php`
- `docs/reports/contracts/task-13b-reliable-execution-amendment.md`

## Закрытые инварианты

- Команды исполнения зарегистрированы, а восстановление аренд запуска и экспорта поставлено в расписание.
- Аудит направлен в обслуживаемую очередь отчётов.
- Границы конфигурации согласованы с хранилищами и проверяются при загрузке модуля.
- Восстановление артефактов использует явную конфигурацию вместо скрытых констант.
- Слушатели отказов учитывают только точное сочетание подключения, очереди и класса задания.
- Для запуска и экспорта добавлены структурированные события жизненного цикла и измерение длительности.
- Ошибки, возвраты аренд и необрабатываемые задания покрыты телеметрией; критические локально определимые пороги формируют сигнал тревоги.
- Описания консольных команд и пользовательские сообщения локализованы через `trans_message`.
- Архитектурный контракт запрещает возврат незавершённых связей предыдущего каталога отчётов.

## Проверки

- Целевой PHPUnit: 67 тестов, 302 утверждения — успешно.
- Pint по 19 изменённым PHP-файлам — успешно, исправлены 2 стилевых замечания.
- PHPStan с `APP_ENV=testing` и лимитом памяти 1 ГБ — ошибок нет.
- `php -l` по 19 изменённым PHP-файлам — синтаксических ошибок нет.
- `git diff --check` — ошибок форматирования нет.

Проверки с подключением к базе данных, авторизационные и браузерные smoke-тесты не запускались согласно ограничению задачи.
