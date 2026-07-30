# Task 8 — сохранённые представления отчётов

## Изменения

- Добавлены миграция, Eloquent-модель и tenant/owner-scoped store с soft delete, блокировками и частичным уникальным индексом default-представления.
- Добавлены DTO, статус, сервис, handlers, подписанный cursor, FormRequest, ресурс и admin controller.
- Добавлены маршруты CRUD и установки default; DI зарегистрирован в `ReportingCatalogServiceProvider`.
- Cursor подписывает организацию, владельца, фильтр отчёта, timestamp и ULID с domain-separated HMAC; некорректные токены отвечают `REPORT_CURSOR_INVALID`.

## Проверки

- `vendor/bin/phpunit tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php` — OK (1 test, 3 assertions).
- `vendor/bin/phpstan analyse ... --no-progress --memory-limit=1G` для сервисного слоя/store/cursor/controller — OK, 0 ошибок.
- `php -l` для изменённых и новых PHP-файлов — OK.
- `vendor/bin/pint --test` для изменённых и новых PHP-файлов — OK.
- `git diff --check` — OK.

## Самопроверка и ограничения

- Локальный общий bootstrap PHPUnit для DB-тестов запускает миграции SQLite и несовместимо падает на существующей PostgreSQL-функции `BTRIM`; DB-интеграционные проверки не повторялись и должны выполняться в PostgreSQL CI.
- Миграция локально не запускалась согласно ограничениям задачи.

## Исправления после ревью

- Исправлен сброс default-представления при создании: запрос использует только `organization_id` и `owner_id`.
- При расхождении `contract_version` с текущим published definition представление переводится в `needs_migration` под блокировкой.
- Мутации теперь используют owner-only lookup; organization-shared представления доступны для чтения, но не для изменения, удаления и выбора default другим пользователем.
- Cursor проверяет canonical ULID и canonical timestamp до формирования keyset SQL.
- Пустое имя после trim в PATCH отклоняется так же, как при создании; удалён дублирующий `saved_view_id` из safe fields.

### Проверки исправления

- Cursor unit-тесты — OK (2 tests, 6 assertions).
- PHPStan по изменённым Task 8 слоям — OK, 0 ошибок.
- `php -l`, Pint и `git diff --check` — OK.
