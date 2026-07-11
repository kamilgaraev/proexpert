# Plan 2 — Task 6: отчёт реализации

## Результат

- Диагностика AI-сметчика переведена на неизменяемый журнал: идентичность сбоя, события возникновения и закрытия разделены, а текущее состояние вычисляется представлением PostgreSQL.
- Повторная доставка одного события идемпотентна благодаря стабильному `event_id`; отдельная попытка получает новый идентификатор.
- Закрытие сбоя привязано к точной последовательности возникновения, tenant-границы защищены составными внешними ключами и ограничениями.
- Добавлен строгий workflow fence: устаревшие, завершённые и сменившие версию сессии фоновые задачи не меняют состояние.
- Recorder и workflow handler обязательны в pipeline, unit processing и OCR/geometry processing; ошибка наблюдаемости не подменяет исходное исключение.
- Контекст диагностики ограничен закрытым набором безопасных скалярных полей, диапазонами и лимитом JSON. Секреты, пути, токены и произвольные строки отбрасываются.
- Raw throwable messages удалены из логов, API, уведомлений, checkpoint и session diagnostics модуля.
- OCR и извлечение геометрии используют типизированные категории ошибок и сохраняют retry-семантику.
- Обычные сметы и shared AI Assistant не изменялись.

## Проверки

- DB-less regression после Pint: `158 passed (964 assertions)`.
- PHPStan/Larastan по затронутым областям с `--memory-limit=1G`: `No errors`.
- `php -l`: 44 изменённых PHP-файла без синтаксических ошибок.
- `git diff --check`: замечаний нет.

## PostgreSQL-only

Добавлен opt-in контрактный тест неизменяемого журнала: идемпотентность, contention, resolve/reopen, tenant/privacy constraints, запрет изменения истории и cascade lifecycle.

Тест локально не запускался, поскольку требует изолированного PostgreSQL и `RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1`. PostgreSQL-миграции и прикладные DB-команды не запускались. Один запуск существующего Feature QueueTest ошибочно инициировал bootstrap изолированной тестовой БД; его результат не использовался, после этого выполнялись только DB-less и статические проверки.

## Второй корректирующий проход

- Draft generation подключён к единственной production-границе `DraftPipelineEntrypoint -> PipelineRunner -> LegacyDraftPipelineStageAdapter`; прежний orchestrator выполняется адаптером ровно один раз и может быть заменён стадиями Task 7.
- Queue jobs и whole-document OCR используют неизменяемый snapshot, захваченный до начала работы: tenant, session, точные state version/status, attempt identity, event/correlation IDs. Устаревшие и завершённые попытки не меняют workflow.
- Resolve сериализуется блокировкой identity и разрешён только для последнего активного occurrence; повторный/stale resolve не создаёт событие.
- `sequence` объявлен `GENERATED ALWAYS AS IDENTITY`; приложение не передаёт его явно.
- Удалено сохранение HTTP response body, исходного пути и raw import fragment из диагностических записей. Provider/model ограничены закрытыми slug-доменами и фильтром token/path-like значений.
- PostgreSQL opt-in matrix дополнена invalid identity/event cases, nullable tenant bypass, explicit sequence, stale/duplicate resolve, concurrent resolve и concurrent distinct occurrences. Она написана, но не запускалась.
- Финальный DB-less gate: `165 passed (990 assertions)`; PHPStan: `No errors`.
