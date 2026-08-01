# R19 `workforce_capacity`: отчёт о реализации source-contract

Дата: 2026-08-01.

## Вердикт блока

Реализован только доказательный source-contract плановой кадровой ёмкости. Блок не добавляет report provider, definition, route, API, export, UI, публикацию, backfill и не использует attendance, overtime, actual hours, payroll rows или зарплатные данные.

## Архитектура

- Observation фиксирует одну `(organization, staff unit, project|null, month)` когорту и хранит immutable policy/formula/source pins, агрегаты, canonical JSON и SHA-256.
- Policy v1 закрепляет timezone организации, inclusive effective dates, active assignment/approved unavailability statuses, правило `affects_payroll=true` для существующего типа отсутствия, точную project/null-bucket атрибуцию, calendar precedence и запрет неявных восьми часов.
- Snapshot items сохраняют упорядоченные технические evidence rows без ФИО, контактов, табельного номера, комментариев, назначения поездки, оплаты, actual hours и overtime.
- Снимок и items append-only; PostgreSQL пересчитывает hashes, проверяет формулы, lineage, actor/service identity, полный evidence set и запрещает незапечатанный commit.
- Deferred capture использует request-scoped immutable child tables: отдельные compact ranges и типизированные frozen source rows. В request остаются только command/policy/version/business-date pins, lifecycle state и счётчики; массивы source rows и giant JSON в request отсутствуют. Каждый созданный snapshot связан именно со своей capture request, а повторная идемпотентная запись не может переиспользовать строку другой заявки.
- Ranges и frozen rows материализуются set-based операциями `INSERT … SELECT` внутри owner-транзакции. Это исключает row caps, межзапросное изменение источника и удержание неограниченного результата в памяти драйвера.
- Каждый `change_capture` выполняется асинхронно. Ограниченный синхронный путь сохранён только для явных `scheduled_close` и `manual_recompute`; deferred worker читает исключительно frozen child rows и держит в памяти только одну атомарную когорту. Успешное продвижение сбрасывает retry budget, поэтому число когорт не ограничено числом попыток.
- Capture request использует закрытый автомат `preparing → pending → processing → completed|dead_lettered`, lease, attempt counter, claim token и compare-and-swap переходы. Cursor, число chunks/snapshots и frozen counts меняются только допустимыми переходами; recovery повторно ставит только доступные или просроченные заявки.
- Zero-affected capture запечатывается сразу как terminal `completed` с нулевыми range/source/snapshot counts и не отправляет пустую задачу в очередь.
- Exact source-schema/formula pins выбирают evaluator через закрытый registry. Неизвестная пара версий завершается безопасным доменным кодом, а технический текст исключения не попадает в persisted error contract.
- Frozen snapshot сверяет policy definition/canonical/hash с immutable request pins и не зависит от текущего timezone организации. Evidence assignment/absence/trip дополнительно доказывает попадание в `as_of_date`; публичная lineage JSON связана с routing-колонками snapshot item.
- Идентификаторы staff unit/project/employee в immutable snapshots/items не имеют FK к изменяемым owner-строкам. Live capture проверяет владельцев триггером, а frozen capture опирается только на request ranges и frozen source rows, поэтому удаление исходной строки после freeze не ломает worker.
- Cohort advisory lock сериализует запись одинаковой когорты. Профильные mutation-методы `WorkforceProService` удерживают owner lock и вызывают единый capture boundary внутри owner-транзакции; ошибка evidence откатывает owner mutation.
- Dismissal использует двухфазный lifecycle capture: affected ranges фиксируются до изменения владельца, frozen source — после изменения в той же транзакции. В command pins не сохраняются вложенные assignments.
- Старый `WorkforceCapacityFrozenSource`, `WorkforceCapacityFrozenGeneration`, budget-based giant-JSON путь и соответствующие DI bindings удалены; runtime fallback на прежнюю модель отсутствует.

## Формулы и gaps

- `authorized_fte = staff_unit.headcount`.
- `assigned_fte` — сумма exact decimal assignment rate.
- `available_fte`, `open_fte`, `overallocated_fte` рассчитываются fixed-point арифметикой без float.
- `scheduled_hours` строится только из weekly pattern и явных schedule-day overrides. Missing/inactive/invalid schedule даёт `null` и gap.
- Null project bucket хранится отдельно; cross-project unavailability не вычитается молча и даёт `cross_scope_unavailability`.
- Missing/inactive staff unit, отсутствующий schedule и неполный source-contract не превращаются в искусственный ноль.

## RBAC-граница

DB-free disclosure policy требует точный `workforce.view`, совпадающую organization и project scope. Null bucket доступен только org-wide viewer. Audit lineage дополнительно требует `workforce.audit.view`. Это подготовка source boundary, а не опубликованный reader/provider.

## Проверки

- PHPUnit DB-free: 67 tests, 360 assertions — PASS суммарно; после исправлений ревью повторно выполнен затронутый набор 12 tests, 93 assertions — PASS.
- Targeted PHPStan/Larastan — PASS.
- Scoped Pint и `git diff --check` — PASS.
- Opt-in PostgreSQL suite и отдельный CI gate добавлены. Gate требует PostgreSQL 16, изолированную БД с суффиксом `_test`/`_testing`, exact checkout SHA и `--fail-on-skipped`.
- PostgreSQL suite, DB-команды и миграции локально не запускались: это запрещено правилами проекта. Локальная граница проверки — DB-free unit, статический анализ, syntax/style и contract tests.
- Smoke/UI проверки не проводились по явному ограничению пользователя и из-за отсутствия provider/route/UI в этом блоке.

## Граница публикации

R19 не готов к runtime publication без отдельного provider/definition/reader/export/UI блока и formal admission. Source-contract намеренно не затрагивает R22, R23, R16, шаблоны и Core publication.
