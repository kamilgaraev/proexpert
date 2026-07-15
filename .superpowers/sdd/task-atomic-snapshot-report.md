# Atomic notification snapshot — отчёт

## Результат

- Начальная загрузка уведомлений теперь получает страницу списка и глобальную unread-сводку текущего доверенного контура в одной транзакции.
- Для PostgreSQL в начале собственной транзакции устанавливается `REPEATABLE READ` до первого запроса данных.
- В `meta` ответов admin, ЛК и mobile добавлены:
  - `unread_count`;
  - `unread_by_category`;
  - `unread_by_notification_type`;
  - `unread_by_type`;
  - `snapshot_sequence`.
- Фильтры списка не сужают unread-сводку: она считается глобально для видимого пользователю контура и организации.
- Customer-контракт сохранил прежнюю форму `data.items`/`data.meta`; `unread_count` переиспользует результат того же snapshot без дополнительного запроса после пагинации.
- Endpoint `/notifications/unread-count` сохранён для обратной совместимости и теперь сам формирует все агрегаты в одном snapshot.
- Элементы HTTP-списка и WebSocket-события содержат целочисленный `sequence`; клиент принимает из буфера только события с `sequence > snapshot_sequence`.

## Архитектура

- Оркестрация чтения перенесена в `NotificationQueryService`.
- Результат передаётся через типизированный `NotificationListSnapshot`.
- Контроллер только применяет разрешённые фильтры, презентует элементы и выбирает стандартный response wrapper.
- Для SQLite snapshot выполняется в обычной транзакции. Вложенный PostgreSQL snapshot завершается fail-closed до запросов данных, потому что сервер не может честно гарантировать установку `REPEATABLE READ` после начала внешней транзакции.
- PostgreSQL-вставки targets сериализуются отсортированными `pg_advisory_xact_lock` по паре пользователь/контур. Identity `sequence` выделяется после lock, а постановка задания регистрируется внутри той же внешней транзакции через `afterCommit`.
- Cursor считается по всем targets пользователя и контура без фильтра организации, видимости или удаления, поэтому запоздавшее старое событие всегда находится ниже HTTP-cursor.

## TDD и проверки

- RED: `NotificationAtomicSnapshotContractTest` — 3 ожидаемых падения до реализации.
- RED review-wave: подтверждены отсутствие fail-closed для вложенной PostgreSQL-транзакции, стабильного tie-breaker, commit cursor, advisory lock и `sequence` в HTTP/WebSocket.
- GREEN: весь `tests/Unit/Notifications` прошёл без ошибок.
- Итоговый unit-прогон разделён из-за длительного AST inventory: 79 тестов / 314 assertions и 3 sender-contract теста / 5733 assertions; суммарно 82 теста / 6047 assertions, оба процесса завершились с exit code 0.
- Дополнен DB-backed feature-тест `NotificationContourIsolationTest`: проверяет точную форму admin/ЛК/mobile/customer/count ответов, глобальную unread-сводку, `snapshot_sequence` и стабильную сортировку. Локально не запускался по запрету на DB-команды; предназначен для CI.
- `php -l` — все изменённые PHP-файлы без синтаксических ошибок.
- Laravel Pint — все изменённые PHP-файлы, успешно.
- PHPStan/Larastan — изменённый notification-модуль, ошибок нет.
- `git diff --check` — ошибок нет.

## Примечание о локальной проверке

Первая версия поведенческого теста transaction runner использовала полный `Tests\\TestCase` и запустила bootstrap миграций. Проверка `phpunit.xml` подтверждает `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`: production и постоянная локальная БД не затрагивались. После этого тест переписан на чистый PHPUnit с in-memory gateway, повторный DB bootstrap не используется. `git status` подтвердил отсутствие посторонних tracked-изменений вне notification-задачи.

## Изменения схемы

- Новая миграция не создавалась: обновлена существующая, ещё не развёрнутая миграция `notification_targets`.
- Для PostgreSQL добавлен `BIGINT GENERATED ALWAYS AS IDENTITY sequence`; синтаксис `generatedAs()->always()` проверен по официальной документации Laravel 11 и установленному framework source.
- Добавлен индекс `interface, sequence`; backfill присваивает sequence детерминированно в порядке notification UUID.
