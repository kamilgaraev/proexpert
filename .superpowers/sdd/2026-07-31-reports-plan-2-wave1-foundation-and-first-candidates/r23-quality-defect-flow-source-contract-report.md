# R23 `quality_defect_flow`: implementation report

Дата: 2026-08-01.

## Вердикт блока

Реализован только доказательный source-contract потока дефектов качества. Он не публикует отчёт и не добавляет provider, definition, route, API, export, UI, catalog activation или backfill. Legacy `quality_defect_status_history` остаётся UI-журналом и не используется как самостоятельное доказательство.

## Архитектура

- `quality_defect_flow_policies` хранит immutable policy v1 с явными переходами, return/reopen, terminal reason, календарным UTC clock и детерминированным порядком.
- `quality_defect_flow_events` хранит append-only события с event-time snapshot, policy pin, source identity, sequence, source/evidence SHA-256.
- `quality_defect_flow_gaps` хранит только immutable quarantine identity. При отсутствии доказанного initial event текущая карточка не реконструируется, `project_id` намеренно остаётся `null`.
- `QualityDefectFlowOwnerEventSink` вызывается только внутри транзакции владельца. Ошибка recorder откатывает карточку, status history и evidence.
- PostgreSQL-триггеры запрещают UPDATE/DELETE, проверяют lineage, policy pin, transition, terminal reason, initial/contiguous sequence, time ordering, membership и acceptance-link retargeting.

## Owner-writer coverage

| Owner path | Canonical event |
|---|---|
| `QualityDefectService::create` | `created` |
| `assign` | `assigned` |
| `start` | `started` |
| `resolve` | `submitted_for_review` |
| `verify(true)` | `verified_resolved` |
| `verify(false)` | `returned_for_rework` |
| `reject` | `rejected` |
| `cancel` | `cancelled` + `cancelled_by_user` |
| `HandoverAcceptanceService::addFinding` | тот же canonical `created` boundary |

Прямой runtime-вызов `QualityDefect::create` в Handover удалён. Статический inventory оставляет production create/status-history writer только в `QualityDefectService`; тесты и отдельный demo cleanup не считаются runtime source writers.

## Классификация данных

Snapshot содержит только organization/project/defect/contractor/task/assignee IDs, severity, due-date value/presence, inspection flag и стабильную source-link classification. Title, description, comment, photo URL/caption, arbitrary metadata и display data не входят ни в payload, ни в reader-visible hashes.

Acceptance source хранит только `acceptance_scope_id` и `acceptance_session_id`; PostgreSQL проверяет общий organization/project и запрещает их последующее retargeting после evidence.

## Проверки

- `php -l`: все 31 R23 PHP-файлы — PASS.
- PHPUnit DB-free: 37 tests, 92 assertions — PASS.
- Scoped PHPStan/Larastan: 23 files — PASS.
- Scoped Pint: 31 files — PASS.
- Writer inventory и no-exposure search выполнены.
- Opt-in PostgreSQL suite добавлен, но локально не запускался: правила проекта запрещают локальные DB/migration-команды. Suite pre-connection gated флагом `QUALITY_DEFECT_FLOW_POSTGRES_TESTS=1`, требует `pgsql`, пустой `DB_URL` и имя отдельной БД с суффиксом `_test`/`_testing`.
- Smoke/UI проверки не проводились по явному ограничению пользователя и из-за авторизации.

## Граница публикации

R23 остаётся заблокированным для runtime publication до отдельного reader/provider блока с bounded filters, keyset pagination, source watermark/snapshot admission и definition-aware ABAC. CI workflow в этом коммите не изменяется: общий workflow сейчас принадлежит параллельному publication-блоку; R23 job будет добавлен отдельным безопасным follow-up после его коммита.
