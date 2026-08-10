# Evidence очистки runtime AI-сметчика v2

## Граница проверки

Проверен backend МОСТ в ветке `refactor/ai-estimate-v2-cleanup`. Субагенты не использовались. Миграции и команды с подключением к БД локально не запускались.

Этап 1 зафиксирован коммитами:

- `5a5017578` — архитектурная граница удаления;
- `b4039f324` — удаление внутренней AI-бухгалтерии;
- `92ddbe05e` — упрощение восстановления после сбоев;
- `7570ce07c` — упрощение evaluation/benchmark-контура;
- `943ccfcf1` — единые адаптеры документов;
- `79daf5132` — единый pipeline и публикация;
- `d5158a048` — append-only журнал решений.

## Caller map

| Контур DELETE | Проверенные поверхности | Результат |
|---|---|---|
| Внутренний бюджет AI-попыток | `app`, `bootstrap`, `config`, `routes`, service provider, jobs и schedule | Authorizer/guard/reconcile runtime отсутствуют; фактический usage и пользовательская квота сохранены |
| Изменяющий failure workflow | application handlers, operations, Filament resources/actions, routes и bindings | Resolve/reopen/claim registry отсутствует; остались только безопасная запись и read-only история сбоев |
| Training leases и online migration runtime | jobs, console schedule, service provider, Filament actions, models | Постоянный processor/recovery/action state machine отсутствуют; read-only corpus и явный benchmark сохранены |
| Параллельные document adapters | detector, processor, provider bindings, CAD/XLSX namespaces | Runtime использует `DocumentUnitAdapter` и один `DocumentRepresentation`; native extractors не публикуют второй контракт |
| Finalization outbox/delivery | pipeline, jobs, service provider, operational snapshot | Все callers удалены; публикация выполняется `PublishDraftOnce` через `applied_estimate_id` |
| Correction chain | controller, read models, review presentation, merger | Apply/revert идут через `EstimateDecisionRepository`; текущее значение читается напрямую по последней записи |
| Frontend constants/actions | `prohelper_admin/src` | Mutating failure/training actions, budget reservations и finalization delivery callers не найдены |

Запреты закреплены `EstimateGenerationV2RuntimeBoundaryTest`: тест сканирует production-код, bindings, config и schedules, исключая только исторические миграции. Дополнительно он защищает сохранение quota, usage store, единого pipeline, обычного writer сметы, пользовательских routes и `AuthorizationService`.

## Forward-only schema cleanup

1. `2026_08_10_000100_drop_internal_ai_budget_accounting.php` удаляет функции `eg_*_ai_budget` и таблицу `estimate_generation_ai_budget_reservations`. Таблица `estimate_generation_ai_operations`, журнал фактического usage и пользовательская quota не удаляются.
2. `2026_08_10_000200_drop_obsolete_runtime_state.php` переводит незавершённые training datasets из `processing` в `draft`, удаляет training lease trigger/index/columns и таблицы старой finalization delivery. Reviewed examples, benchmark records, failure history и quota reservations сохраняются.

Обе миграции имеют необратимый `down()`, который бросает `RuntimeException`. Они проверены чтением исходника и unit-контрактами; локальный запуск запрещён.

По результатам независимого аудита добавлена `2026_08_10_000250_create_evaluation_corpus.php`. Она создаёт каноническое постоянное хранилище evaluation examples с tenant scope, версиями контрактов, состояниями candidate/reviewed/rejected и неизменяемыми данными review decision. Release gate и явная CLI-команда читают только reviewed examples. Существующие training-таблицы не переиспользованы: их контракт требует dataset membership и хранит строки сметы, но не полный expected facts/decisions/quantities contract и не audit reason для решения по примеру. Миграция forward-only и имеет необратимый `down()`; локально она не запускалась.

Одиннадцать исторических migration-файлов `2026_07_12_001700`–`002200` и `2026_08_01_000150`, `000225`, `000250`, `000275`, `000300` восстановлены байт-в-байт к состоянию `e5d6ede38`. `EstimateGenerationHistoricalMigrationsImmutabilityTest` фиксирует их SHA-256 без обращения к Git history во время выполнения.

Публичный quota snapshot разделяет два контекста: organization-level snapshot не публикует lifecycle-статус конкретной сессии, а detail/list читают статус reservation по `(organization_id, session_id)` независимо от месяца. Месячный usage по-прежнему учитывает только confirmed reservations текущего периода; query budget batch snapshot не увеличен.

Порядок deploy:

1. Выпустить код без legacy callers.
2. Наблюдать production-логи и метрики, подтверждая отсутствие обращений к удаляемой схеме.
3. Выполнить schema cleanup отдельным контролируемым deploy.

До шага 3 возможен rollback к предыдущему коду. После schema cleanup rollback к версии, использующей удалённые таблицы, функции или lease-поля, запрещён: допустим только roll-forward исправлением либо согласованное восстановление БД из резервной копии.

## Самостоятельный review-pass

Повторно проверены production callers, container bindings, schedules, routes, Filament actions, admin frontend constants, schema references и защищаемые продуктовые контракты. Во время review найден и удалён последний read-side caller finalization tables в operational snapshot; query budget уменьшен с 14 до 13. Также удалены training lease-поля модели и ограничение статуса `processing`.

Финальный architecture gate выявил прежнюю прямую зависимость fallback-каталога нормативов AI-модуля от concrete service модуля смет. Adapter переведён на существующие query scopes общей модели `NormativeRate`; повторный boundary test и его unit-тесты прошли.

Потери пользовательских возможностей не обнаружены: квота 10 + купленные генерации, фактическая стоимость AI, безопасный retry, загрузка документов, snapshot/progress, review, генерация и запись обычной сметы МОСТ остаются.

## Проверки

- Финальный runtime/ordinary-estimate/operational-snapshot architecture gate, migration safety и fallback normative adapter: `41 tests, 1187 assertions` — PASS.
- Decision journal, correction API, merger и runtime boundary: `27 tests, 80 assertions` — PASS.
- Изменённый correction history presenter: `1 test, 5 assertions` — PASS.
- Остальные агрегированные contract-тесты Stage 1 (usage ledger, failure recovery, evaluation corpus, document adapters, single pipeline): PASS.
- PHPStan по всем изменённым PHP-файлам финального блока: PASS.
- `git diff --check`: PASS.

DB-dependent PostgreSQL contracts и сами migrations локально не запускались. Они остаются CI/deploy gates:

```powershell
vendor\bin\phpunit tests\Feature\EstimateGeneration\Pipeline\PipelineCheckpointPostgresContractTest.php tests\Feature\EstimateGeneration\EloquentPipelineCheckpointPostgresContentionTest.php tests\Feature\EstimateGeneration\EstimateGenerationUsageLedgerPostgresTest.php tests\Feature\EstimateGeneration\EstimateGenerationFailureLedgerPostgresTest.php
```

Старый общий presentation test содержит независимое ранее существовавшее строгое сравнение `int` и `float` координат; изменённый history-presenter проверен отдельным зелёным сценарием.
