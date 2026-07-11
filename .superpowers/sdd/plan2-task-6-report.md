# Plan 2 — Task 6: отчёт реализации

## Результат

- Добавлен tenant-scoped журнал нормализованных сбоев AI‑сметчика с закрытыми категориями `recoverable`, `user_action_required`, `terminal`.
- Добавлены typed mapping для OCR, reranker, pipeline claim/contract, storage, configuration, validation и document unit claim/lineage ошибок; неизвестные исключения сводятся к `unexpected_internal_failure`.
- Fingerprint не зависит от throwable message, prompt, документа или персональных данных.
- Добавлен рекурсивный closed-allowlist sanitizer с ограничениями глубины, ширины и строки, фильтрацией не-JSON значений и token-like строк.
- Aggregate failure и immutable occurrence history разделены. `event_id` делает повторную доставку одного logical failure идемпотентной, а новый physical failure атомарно увеличивает occurrence count.
- Record/resolve/reopen выполняются через PostgreSQL invoker functions и controlled mutation triggers. Добавлены composite tenant FKs, CHECK constraints, индексы и cascade lifecycle.
- PipelineRunner, unit OCR, document manifest job и generation failed hook подключены к ledger. Recorder/handler failure не заменяет исходный throwable и не меняет retry semantics.
- Recoverable не меняет workflow; user-action переводит документную или generation стадию в review; terminal переводит сессию в failed только через workflow и сохраняет safe `failure_code`.
- Успешный повтор закрывает активные occurrences без удаления истории. Повтор после resolve переоткрывает aggregate.
- Raw throwable message удалён из runtime job/log/notification/session/checkpoint diagnostic paths. Обычные сметы и shared AI Assistant не изменялись.

## TDD

RED был зафиксирован до production implementation:

- отсутствовали `SensitiveDiagnosticSanitizer`, `FailureCategory`, `FailureNormalizer`;
- отсутствовали `FailureStore`, recorder non-masking и controlled resolve;
- отсутствовали persistence schema/model/store;
- `PipelineFailureDetails` менял fingerprint при изменении throwable message;
- unit recovery не записывал failure и не закрывал его после успешного retry;
- отсутствовал typed workflow handler.

После каждого RED добавлялся минимальный contract, затем выполнялся GREEN и regression.

## Проверки

- DB-less regression: `141 passed (564 assertions)`.
- PHPStan/Larastan, 137 затронутых backend files, `--memory-limit=1G`: `No errors`.
- Pint: `45 files`, `10 style issues fixed`, повторный тестовый gate GREEN.
- `php -l`: все изменённые PHP-файлы без синтаксических ошибок.
- `git diff --check`: чисто.

## PostgreSQL-only

Написан opt-in `EstimateGenerationFailureLedgerPostgresTest`, но локально не запускался:

- committed-winner contention на двух независимых connections;
- повтор одного `event_id` не увеличивает occurrence;
- новые events увеличивают aggregate и сохраняют immutable history;
- resolve/reopen;
- tenant/privacy/collision/controlled mutation constraints;
- session cascade и disposable fixture cleanup.

Для запуска требуется изолированное PostgreSQL-окружение и `RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1`.

Миграции локально не запускались.
