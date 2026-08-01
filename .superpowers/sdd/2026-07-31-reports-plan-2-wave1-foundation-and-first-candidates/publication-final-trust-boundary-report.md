# Publication gate: финальная доверенная граница

## Результат

Publication gate остаётся pre-provider и fail-closed. Блок не регистрирует отчёты, не меняет каталог, маршруты, UI или runtime provider bindings.

Закрыты финальные замечания trust-boundary review:

- release evidence заменён на канонический Ed25519-артефакт с доверенным issuer/key registry и точной привязкой candidate manifest, official manifest, candidate definition, proof, binding, conformance evidence, release SHA, approver и CI provenance;
- delivery proof принимает только настоящий `ReportExportRenderer`, совпадающий с заявленным форматом, и закрепляет хеш renderer-контракта вместе с интерфейсом, реализацией, schema, fixture, assertions и renderer version;
- публикация, отключение, feature configuration и outbox delivery выполняются только через разграниченные PostgreSQL admission functions; таблицы и функции принадлежат отдельной `NOLOGIN NOINHERIT` owner-роли, прямой DML для runtime запрещён;
- DB constraints связывают proof contract/release/approver с типизированными колонками и release artifact; trigger functions работают как `SECURITY DEFINER` с фиксированным `search_path`;
- future-dated release отклоняется, emergency disable получает время не раньше `published_at`, повторная публикация в PostgreSQL-тесте использует монотонную release identity;
- штатный publication CI job запускает unit/schema contracts до изолированного PostgreSQL suite и не допускает успешный skip.

## TDD и проверки

RED был подтверждён тремя регрессиями: unsigned/self-declared CI document, поддельный XLSX renderer и future release принимались старой реализацией. После реализации все три сценария fail-closed.

Финальный DB-free набор:

- PHPUnit: 39 тестов, 222 assertions;
- targeted PHPStan: 17 файлов, без ошибок;
- PHP syntax: 17 файлов, без ошибок;
- Pint: 26 scoped-файлов;
- scoped `git diff --check`: чисто.

Локально не запускались PostgreSQL, миграции, авторизационные smoke-тесты, dev-server и frontend build. Реальный PostgreSQL contract остаётся обязательным CI evidence на точном commit SHA.

## Неизменённая граница

Provider activation, service-provider registration, report definitions, routes, admin UI, published catalog и включение feature mode `on` в этот блок не входят.
