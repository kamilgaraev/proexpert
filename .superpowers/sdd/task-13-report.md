# Отчёт по задаче 13: Filament datasets, benchmark, settings и budgets

Статус: **DONE_WITH_CONCERNS**

## Реализовано

- Training dataset resource перенесён в `App\Filament\Resources\EstimateGeneration` без alias/fallback; старый resource и его pages удалены.
- Acceptance-наборы изолированы от обучения и настройки правил, но разрешены для benchmark при явном подтверждении и QA-доступе.
- Для development-наборов добавлен закрытый trusted-review workflow с запретом self-review и проверками на уровне приложения и PostgreSQL.
- Все изменяющие действия datasets и запуск benchmark проходят через application services с permission, tenant, state/kind, optimistic concurrency, idempotency и audit guards.
- Benchmark list/view использует ограниченную проекцию, ограниченную пагинацию и privacy-safe presenter; raw case payload, prompts, stack traces и секреты в UI не выдаются.
- Очередной benchmark исполняет сохранённый запуск через команду Plan 3 и завершает его через существующий repository; production case-results сохраняются отдельно в immutable private object store.
- Добавлены immutable versioned global/organization snapshots настроек и бюджетов, CAS, idempotency и аудит изменений.
- Settings schema закрыта: фиксированные stages/formats/rules/currencies, exact decimal money и thresholds; API keys, credentials, raw prompts и secret-bearing endpoints не принимаются и не отображаются.
- Новые операции benchmark получают snapshot настроек только при создании; существующие операции не изменяются.
- Добавлены PostgreSQL-ограничения и immutable triggers. Миграция trusted review расположена после миграции Plan 3.

## Проверки

- RED до реализации: 29 tests, 15 errors и 10 failures.
- Focused DB-less tests: 32 passed, 173 assertions.
- Полный DB-less Filament-модуль: 109 passed, 842 assertions.
- PHPStan/Larastan по затронутому production scope с `--memory-limit=1G`: ошибок не найдено.
- Pint по 26 затронутым PHP-файлам: PASS.
- `php -l` по 26 затронутым PHP-файлам: PASS.
- `git diff --check`: PASS.

## Ограничения проверки

- Миграции, seeders, tinker и любые команды с подключением к БД не запускались согласно owner constraint.
- Фактическое применение PostgreSQL DDL, очередь и S3-path необходимо проверить в staging после миграции, с production-sized acceptance dataset и ограничениями worker timeout/memory.
- Сборка frontend не запускалась: задача backend/Filament и правила проекта запрещают build админки.

## Исправления по повторному review

- Authority benchmark перенесён из payload очереди в закрытый immutable `execution_snapshot` сохранённого запуска. Job содержит только `run_id` и idempotency key.
- Snapshot фиксирует tenant/dataset identity, immutable manifest locator/hash, adapter/prompt, settings snapshot, models, normative/price/currency и pipeline versions. PostgreSQL insert trigger сверяет snapshot с выбранным dataset.
- Executor повторно загружает запуск и dataset в tenant scope, сверяет сохранённый manifest с immutable dataset stats, проверяет доступность adapter и отклоняет любое несовпадение фактического отчёта до terminal completion.
- Private S3 manifest выбранного dataset используется для development, regression и acceptance; acceptance дополнительно сохраняет owner/QA gates. Локальные repository fixtures в production по-прежнему запрещены.
- Небезопасный production default `production-replay` удалён из Filament; default `current-baseline` зарегистрирован во всех окружениях.
- Dataset actions переведены на закрытую kind/status/trusted-status matrix. Development primary approval отделён от trusted approval; regression/acceptance используют обычный primary review и не получают learning actions.
- Upgrade migration временно снимает approved-dataset immutability trigger, backfill-ит ранее approved development datasets ограниченным evidence из `approved_by/approved_at`, затем восстанавливает immutable trigger до завершения миграции.
- Settings page реактивно загружает exact global/organization snapshot, сбрасывает organization при global scope, использует атомарный epoch guard и переносит загруженную version в CAS baseline.
- Создание dataset теперь требует проверенный JSON manifest: manifest проверяется на закрытый контракт и наличие cases выбранного kind, сохраняется приватно в tenant S3 path, а locator/hash и dataset content hash фиксируются в immutable stats до review. Processing сохраняет эту identity и не затирает её агрегатной статистикой.

## Исправления по второму повторному review

- Acceptance corpus loader снова строго acceptance-only. Для development/regression добавлены отдельные loader/reader, принимающие только относительные нормализованные locator-ы и разрешающие их исключительно под точным tenant import prefix.
- Обычный processing доступен всем трём dataset kinds через единую закрытую action matrix; learning/tuning по-прежнему возможны только для trusted-approved development.
- Dataset import переведён на S3-first staging через immutable conditional object store. Content-addressed prefix не зависит от DB ID, включает hashes всех uploads; DB-транзакция создаёт только dataset и memberships после успешной загрузки. Компенсация удаляет исключительно объекты с `created=true`, поэтому retry переиспользует ранее существовавшие immutable objects.
- Manifest case locators связываются с uploads по SHA-256; неизвестные supporting files сохраняются отдельно и не получают authority manifest case.
- Benchmark dispatch самостоятельно загружает текущий effective settings snapshot (organization override или global), отклоняет stale requested identity и формирует models/currency/limits только из authoritative snapshot.
- PostgreSQL insert guard сверяет settings identity, scope, organization, hash, models, limits и currency execution snapshot с текущей записью settings; execution snapshot остаётся неизменяемым.
- Immutable object store получил закрытый whitelist для content-addressed import manifest/object paths и acceptance paths; исполняемый unit-контракт подтверждает разрешённые и запрещённые пути.

## Проверки второго повторного review

- Целевые Filament-тесты: 43 passed, 257 assertions.
- Полный DB-less Filament-модуль: 120 passed, 926 assertions.
- Benchmark command: 9 passed, 27 assertions.
- Immutable object store: 3 passed, 9 assertions.
- PHPStan/Larastan по изменённым production-файлам с `--memory-limit=512M`: ошибок не найдено.
- Pint по 17 изменённым PHP-файлам: PASS.
- `php -l` по всем изменённым PHP-файлам: PASS.
- `git diff --check`: PASS.
