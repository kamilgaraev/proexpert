# R19 `workforce_capacity`: отчёт о реализации source-contract

Дата: 2026-08-01.

## Вердикт блока

Реализован только доказательный source-contract плановой кадровой ёмкости. Блок не добавляет report provider, definition, route, API, export, UI, публикацию, backfill и не использует attendance, overtime, actual hours, payroll rows или зарплатные данные.

## Архитектура

- Observation фиксирует одну `(organization, staff unit, project|null, month)` когорту и хранит immutable policy/formula/source pins, агрегаты, canonical JSON и SHA-256.
- Policy v1 закрепляет timezone организации, inclusive effective dates, active assignment/approved unavailability statuses, правило `affects_payroll=true` для существующего типа отсутствия, точную project/null-bucket атрибуцию, calendar precedence и запрет неявных восьми часов.
- Snapshot items сохраняют упорядоченные технические evidence rows без ФИО, контактов, табельного номера, комментариев, назначения поездки, оплаты, actual hours и overtime.
- Снимок и items append-only; PostgreSQL пересчитывает hashes, проверяет формулы, lineage, actor/service identity, полный evidence set и запрещает незапечатанный commit.
- Capture request хранит детерминированный cursor, число chunks/snapshots и terminal completion. Cohort advisory lock сериализует конкурентную запись одинакового источника.
- `WorkforceProService::store/update` и dismissal workflow вызывают единый capture boundary внутри owner-транзакции; ошибка evidence откатывает owner mutation.

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

- PHPUnit DB-free: 16 tests, 80 assertions — PASS.
- Targeted PHPStan/Larastan — PASS.
- Scoped Pint и `git diff --check` — PASS.
- Opt-in PostgreSQL suite и отдельный CI gate добавлены. Gate требует PostgreSQL 16, изолированную БД с суффиксом `_test`/`_testing`, exact checkout SHA и `--fail-on-skipped`.
- PostgreSQL suite локально не запускался: правила проекта запрещают локальные DB/migration-команды.
- Smoke/UI проверки не проводились по явному ограничению пользователя и из-за отсутствия provider/route/UI в этом блоке.

## Граница публикации

R19 не готов к runtime publication без отдельного provider/definition/reader/export/UI блока и formal admission. Source-contract намеренно не затрагивает R22, R23, R16, шаблоны и Core publication.
