# G09/G10: approved close, source version and watermark discovery

Дата: 2026-07-31

Статус: `BLOCKED_WITH_EVIDENCE`

## Вывод

В Budgeting не найден авторитетный источник, который одновременно фиксирует одобренное закрытие, версию плана и состояние всех фактических источников G09/G10. Поэтому новый runtime-адаптер Reporting, `ReportDataProvider`, маршрут, UI и изменение manifest не создавались.

Наличие сохранённого `ReportSourceSnapshot` не заменяет этот источник: он делает уже записанный результат воспроизводимым, но сам по себе не доказывает, что исходные данные были одобренно закрыты.

## Проверенные существующие концепции

| Концепция | Подтверждено кодом | Недостающая гарантия |
| --- | --- | --- |
| `BudgetVersion` | В workflow есть `approved_at`, `approved_by`, `activated_at` и UUID версии. | Нет неизменяемого состава `budget_lines`/`budget_amounts`, их content hash, retention-политики или версии фактических источников. |
| `BudgetPeriodClosure` | Перед закрытием проверяются активные версии; в metadata записывается management summary с количеством версий и суммами. | Нет идентификаторов выбранных версий, полного source payload, per-source watermark/cutoff, content hash и retention horizon. Период допускает reopen/reclose workflow. |
| `EpmDataMartSnapshot` | Сохраняются payload, derived `source_hash`, generated/stale timestamps и source refs. | Это результат произвольного перерасчёта из live service, не owner-approved close. Новый run помечает прежний snapshot `superseded_at`; close/version identity и retention policy отсутствуют. |

## Влияние на кандидатов

- G09 читает budget, acts, completed work, payments, warehouse movements и time entries. Одобренная версия бюджета покрывает лишь часть plan-side входов.
- G10 читает budget, payment transactions, reservations, payment documents и schedules. UUID бюджета и aggregate-row counts не являются source-version или factual watermark.
- `as_of`, `stale_at`, `source_hash` и текущие поля `watermarks` materializer-ов описывают момент захвата и производный результат, а не подтверждённую границу исходных данных.

## Требование к следующему source-owner изменению

Нужна отдельная authoritative approved-close модель или контракт вне runtime Reporting со следующими неизменяемыми полями:

- `close_id`, organization и включительный reporting period;
- выбранные plan/scenario version identities;
- per-source update watermark и cutoff для каждого factual source;
- formula/source-schema version и canonical content hash;
- approved-by/approved-at, lifecycle/restatement relation;
- retention deadline либо policy reference.

После этого G09/G10 writer должен читать только одобренный source state и получить source-derived replay acceptance test: последующие изменения upstream не меняют result/rows/drills выбранного close. До выполнения этого условия оба кандидата сохраняют `blocked_by_source_readiness` и `provider: null`.

## Изменённая документация

- `docs/reports/wave-1-source-contracts.md`: явно отделены capture metadata от update watermark, указаны ограничения `BudgetVersion`, `BudgetPeriodClosure` и `EpmDataMartSnapshot`, описан required approved-close contract.

## Проверки

Изменена только документация и evidence-отчёт. DB-команды, миграции, tinker, runtime provider и тесты не запускались; достаточно `git diff --check`.
