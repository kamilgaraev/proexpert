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
- `/notifications/unread-count` возвращает `snapshot_sequence` из той же repeatable-read транзакции, что и unread-агрегаты.
- `mark-all-read` возвращает совместимый `count` и новый `sequence_cut`; обновляются только видимые непрочитанные targets с `sequence <= sequence_cut`, поэтому уведомления, зафиксированные после cut, не помечаются прочитанными.
- Организационные WebSocket-события публикуются только в канал `...{interface}.org.{organization_id}`, глобальные — только в `...{interface}.global`; прежний широкий user/interface канал удалён.
- Авторизация организационного канала требует точного пользователя, разрешённого контуром endpoint-интерфейса и активного членства пользователя в организации.
- `organization_id` в корне и `data` WebSocket-события всегда перезаписывается каноническим значением модели уведомления.
- Cursor читается за O(1) из `notification_interface_cursors`; таблица создаётся и backfill-ится в ещё не развёрнутой миграции targets, а persistence продвигает cursor атомарно после вставки targets под advisory lock.
- `mark-all-read` использует обычную READ COMMITTED `DB::transaction`: cursor фиксируется перед update, а условие `sequence <= sequence_cut` исключает более поздние вставки без риска repeatable-read serialization failure.
- Production-драйверы кроме PostgreSQL завершаются fail-closed; детерминированная SQLite-ветка разрешена только в testing.
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
- RED final-contract wave: подтверждены отсутствие cursor в `/unread-count` и отсутствие server cut в `mark-all-read`.
- RED isolation/performance wave: подтверждены широкий WebSocket-канал, отсутствие проверки организации, исторический `MAX(sequence)`, repeatable-read mark-all и молчаливый non-PG fallback.
- GREEN: весь `tests/Unit/Notifications` прошёл без ошибок.
- Итоговый unit-прогон разделён из-за длительного AST inventory: 91 тест / 378 assertions и 3 sender-contract теста / 5739 assertions; суммарно 94 теста / 6117 assertions, оба процесса завершились с exit code 0.
- Дополнен DB-backed feature-тест `NotificationContourIsolationTest`: проверяет точную форму ответов админки/ЛК/mobile/customer/count, глобальную unread-сводку, `snapshot_sequence` и стабильную сортировку. Локально не запускался по запрету на DB-команды; предназначен для CI.
- `php -l` — все изменённые PHP-файлы без синтаксических ошибок.
- Laravel Pint — все изменённые PHP-файлы, успешно.
- Добавлен opt-in PostgreSQL integration test `NotificationPostgresConcurrencyTest` (`RUN_NOTIFICATION_POSTGRES_TESTS=1`): через production `NotificationService`, два процесса и IPC/barriers проверяет advisory-lock commit order/cursor и параллельный mark-as-read/mark-all с исключением post-cut вставки. Локально не запускался по запрету на DB-команды.
- PHPStan/Larastan — изменённый notification-модуль, ошибок нет.
- `git diff --check` — ошибок нет.

## Примечание о локальной проверке

Первая версия поведенческого теста transaction runner использовала полный `Tests\\TestCase` и запустила bootstrap миграций. Проверка `phpunit.xml` подтверждает `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`: production и постоянная локальная БД не затрагивались. После этого тест переписан на чистый PHPUnit с in-memory gateway, повторный DB bootstrap не используется. `git status` подтвердил отсутствие посторонних tracked-изменений вне notification-задачи.

## Изменения схемы

- Новая миграция не создавалась: обновлена существующая, ещё не развёрнутая миграция `notification_targets`.
- Для PostgreSQL добавлен `BIGINT GENERATED ALWAYS AS IDENTITY sequence`; синтаксис `generatedAs()->always()` проверен по официальной документации Laravel 11 и установленному framework source.
- Добавлен индекс `interface, sequence`; backfill присваивает sequence детерминированно в порядке notification UUID.

## Финальная волна проверки контуров и конкуренции

- `NotificationPresenter` всегда формирует корневой `interface` из текущего `NotificationTarget`, а не из общего `data`. Один notification ID корректно представляется как `admin` и `lk` для разных targets.
- `mark-all-read` делегирует атомарное обновление отдельному `NotificationMarkAllReadGateway`. Это сохраняет production-запрос и позволяет поставить точный тестовый барьер после чтения cursor cut и непосредственно перед `UPDATE`.
- PostgreSQL concurrency-тест больше не использует таймер как доказательство блокировки: ожидание второго отправителя подтверждается через `pg_stat_activity.wait_event_type = Lock` и `wait_event = advisory`.
- Cleanup concurrency-тестов в `finally` освобождает IPC-барьеры, завершает зависшие дочерние процессы и обязательно вызывает `waitpid`.
- Добавлен обязательный workflow `.github/workflows/notification-concurrency.yml` для pull request и push в `main`: PostgreSQL 16, PHP 8.2, миграции тестовой БД и точный запуск `NotificationPostgresConcurrencyTest` с `RUN_NOTIFICATION_POSTGRES_TESTS=1`.
- RED подтверждён для presenter-контракта, mark-all gateway, детерминированных барьеров и отсутствующего CI workflow. После реализации целевые тесты: 11 тестов / 100 assertions.
- Полный unit-набор уведомлений и авторизации канала: 97 тестов / 6147 assertions, exit code 0.
- Workflow успешно разобран `Symfony Yaml`; source-контракт проверяет trigger, PostgreSQL service, PHP 8.2, opt-in flag и точную PHPUnit-команду. Поставляемый skill-валидатор `ci_monitor.cjs` отсутствует в ожидаемом каталоге, поэтому `--help` и `check-actions` недоступны; `gh` не использовался.
- DB-backed feature- и PostgreSQL concurrency-тесты локально не запускались по запрету проекта на локальные DB-команды; они закреплены за выделенным CI workflow.
