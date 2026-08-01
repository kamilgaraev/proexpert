# Publication gate: финальная доверенная граница

## Результат

Publication gate остаётся pre-provider и fail-closed. Блок не регистрирует отчёты, не меняет каталог, маршруты, UI или runtime provider bindings.

Закрыты финальные замечания trust-boundary review:

- release evidence заменён на канонический Ed25519-артефакт с доверенным issuer/key registry и точной привязкой candidate manifest, official manifest, candidate definition, proof, binding, conformance evidence, release SHA, approver и CI provenance;
- отдельный signing job выпускает и сам проверяет артефакт только после зелёного publication gate, только для `push` в `refs/heads/main`, в environment `report-publication-release`; provenance берётся из GitHub context и фиксирует реальный repository `kamilgaraev/proexpert`, workflow, job, event, ref, environment, run и commit;
- delivery proof принимает только настоящий `ReportExportRenderer`, совпадающий с заявленным форматом; bundle hash закрепляет schema, fixture, assertions, renderer version, все output-affecting project files, переводы, PDF view/config, Dompdf dependency lock и версии runtime extensions;
- публикация, отключение, feature configuration и outbox delivery выполняются только через разграниченные PostgreSQL admission functions; таблицы и функции принадлежат отдельной `NOLOGIN NOINHERIT` owner-роли, прямой DML для runtime запрещён;
- DB constraints fail-closed связывают proof contract/release/approver с типизированными колонками и exact release artifact; `NULL`/`UNKNOWN` и неправильные JSON-типы не проходят; `SECURITY DEFINER` использует public-qualified relations и `pg_temp` последним;
- PostgreSQL CI создаёт реальный non-superuser issuer login без owner membership, доказывает admission function и отклоняет прямой DML и `SET ROLE owner`;
- future-dated release отклоняется, emergency disable получает время не раньше `published_at`, повторная публикация в PostgreSQL-тесте использует монотонную release identity;
- штатный publication CI job запускает unit/schema contracts до изолированного PostgreSQL suite и не допускает успешный skip.

## TDD и проверки

RED был подтверждён регрессиями для unsigned/self-declared CI document, поддельного XLSX renderer, future release, ложного repository/event/ref/environment, `NULL`/`UNKNOWN` JSONB и небезопасного `search_path`. После исправлений все DB-free сценарии fail-closed; PostgreSQL-сценарии переданы обязательному изолированному CI.

Финальный DB-free набор:

- publication/proof PHPUnit: 64 теста, 351 assertions;
- реальные renderer/parity/streaming contracts: 101 тест, 694 assertions;
- targeted PHPStan: 16 файлов, без ошибок;
- PHP syntax: 16 файлов, без ошибок;
- Pint: scoped-файлы без замечаний;
- scoped `git diff --check`: чисто.

Локально не запускались PostgreSQL, миграции, авторизационные smoke-тесты, dev-server и frontend build. Реальный PostgreSQL contract и self-verified signing artifact остаются обязательным CI evidence на точном commit SHA. Signing job не имеет обхода при отсутствии environment secret или несовпадении private/public key.

## Неизменённая граница

Provider activation, service-provider registration, report definitions, routes, admin UI, published catalog и включение feature mode `on` в этот блок не входят.

До provider activation deployment обязан настроить protected environment `report-publication-release` с matching signing key и выдать отдельным non-superuser DB principals только issuer/operator/runtime/outbox memberships. Общий superuser или owner membership запрещён и не является допустимым режимом запуска.
