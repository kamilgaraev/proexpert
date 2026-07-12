# План 3, задача 10: проверка гонки принятия benchmark-объекта

## Проверенный инвариант

`BenchmarkRunRepository::complete()` удерживает session advisory lock на объект до завершения проверки ссылки и точечного удаления версии. Поэтому процесс B не может принять объект между ошибкой перехода процесса A и очисткой A.

Проверены три ветки:

1. A создаёт версию, B блокируется, A получает принудительную ошибку PostgreSQL и удаляет только созданную точную версию. После освобождения lock B создаёт ту же content-addressed версию и фиксирует завершённый benchmark.
2. A работает без B: после ошибки перехода удаляется точная пара `key + VersionId`; удаление только по ключу отсутствует.
3. Для уже существующей версии с завершённой ссылкой conditional put возвращает `created=false`; вызов очистки не удаляет объект, ссылка и версия сохраняются.

Каждый дочерний процесс после завершения проверяет, что session advisory lock можно повторно получить и освободить.

## Реализация теста

- Два независимых PHP/Laravel-процесса запускаются через `proc_open`.
- Общий файловый fake имитирует versioned S3, conditional create/adopt и журналирует операции с точными `path + VersionId`.
- PostgreSQL trigger, ограниченный `application_name` процесса A, принудительно отклоняет только его terminal transition без копирования production-логики в тест.
- Production-код и порядок блокировки не изменялись.

## Результаты

- Focused adoption suite, запуск 1: `3 tests, 25 assertions`.
- Focused adoption suite, запуск 2: `3 tests, 25 assertions`.
- Полный PostgreSQL-контракт с миграцией `002200`, запуск 1: `1 test, 57 assertions`.
- Полный PostgreSQL-контракт с миграцией `002200`, запуск 2 на той же базе: `1 test, 57 assertions`.
- Immutable storage unit suite: `9 tests, 23 assertions`.

## Авторитетный финальный статус

Этот раздел заменяет приведённые выше промежуточные результаты.

- Focused adoption suite, запуск 1: `3 tests, 34 assertions`.
- Focused adoption suite, запуск 2: `3 tests, 34 assertions`.
- Полный PostgreSQL benchmark contract, запуск 1: `11 tests, 112 assertions`.
- Полный PostgreSQL benchmark contract, запуск 2 на той же `_contract` базе: `11 tests, 112 assertions`.
- Immutable storage unit suite: `20 tests, 41 assertions`.
- PHPStan по изменённым файлам: ошибок нет.
- PHP syntax и `git diff --check`: успешно.
- Production-код в финальных safety-коммитах не изменялся.
- PHPStan по benchmark-модулю и fake store: ошибок нет.
