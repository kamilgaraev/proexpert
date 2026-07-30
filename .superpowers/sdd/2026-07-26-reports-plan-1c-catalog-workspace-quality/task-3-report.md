# Plan 1c Task 3 — candidate source/formula conformance

## Статус

`DONE`

Task 3 реализован изолированно на ветке `feat/reports-task12a`.

- Exact base: `ee2b7bebd60e2983aa9e43876277a6091181cc64`
- Commit: текущий commit, содержащий этот отчёт
- Scope: только Plan 1c Task 3 / global Task 17
- Task 4 / global Task 18 не начинался

## Реализация

Добавлены exact DTO и repository contract:

- `ReportConformanceFixture`;
- `ReportSourceConformanceEvidence`;
- `ReportFormulaConformanceEvidence`;
- `ReportDefinitionConformanceEvidence`;
- `ReportConformanceEvidenceRepository`.

Все assertion-code lists типизированы, уникальны и сортируются. Component class hashes типизированы как `Sha256Hash`, индексируются уникальным class name и сериализуются в стабильном class-sorted порядке. Fixture ограничивает page limit диапазоном `1..100`, cursor chunk size диапазоном `1..5000` и не допускает отрицательный expected row count.

`ReportDefinitionConformanceEvidence`:

- допускает только status `passed|failed`;
- проверяет согласованность status с source/formula outcomes;
- требует точный assertion count;
- проверяет commit SHA;
- считает digest через `CanonicalJson` по всем constructor fields без поля digest;
- `passed()` требует passed source, formula, root status и отсутствие failed assertion.

Candidate-only `ReportSourceConformanceHarness`:

- первым параметром принимает строго `CandidateReportDefinition`;
- сразу извлекает `payload()`;
- не зависит от published registry, assembler или binding map;
- вызывает только Plan 1a owner ports с их существующими arity;
- преобразует fixture `ReportDrillDownRequest` в typed owner-port `ReportDrillDownInput`;
- проверяет code/hash/contract/formula/source-schema identity;
- проверяет owner scope и snapshot identity/immutability;
- проверяет availability, exact row count и unique `row_key`;
- проверяет page/cursor semantics;
- сравнивает totals, quality и provenance;
- пересчитывает canonical source hash и totals hash;
- проверяет typed route-based available resource links;
- отклоняет sensitive keys и classified sensitive columns;
- отклоняет non-finite/non-canonical values;
- возвращает failed evidence при unavailable, source/query/definition drift, scope drift, row leakage и provider failure;
- не создаёт runtime registry/map.

Добавлен `FilesystemReportConformanceEvidenceRepository`:

- canonical path `build/reports/conformance/{code}/{definitionHash}/{fixtureHash}.json`;
- repository-root и schema root fence;
- запрет symlink/alternate path;
- strict Opis validation до создания временного файла;
- canonical byte validation временного файла;
- atomic rename;
- reread, повторная Opis validation и digest verification после rename;
- typed hydration при чтении.

Generated conformance evidence добавлен в `.gitignore` и не входит в tracked artifacts.

Схема `report-conformance-evidence.schema.json` использует Draft 2020-12, рекурсивно закрывает все объекты, требует все constructor fields и digest, а также не допускает raw `rows`, `filters`, `query`, `url`, `pii` через закрытые object contracts.

## TDD evidence

До production-файлов созданы:

- `tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php`;
- `tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php`;
- test fixture builder.

RED:

- exact two-file PHPUnit command завершился exit 1;
- 18/18 tests не проходили;
- первая ошибка: `Class "App\BusinessModules\Core\Reporting\Application\Conformance\ReportSourceConformanceHarness" does not exist`.

GREEN:

- тот же exact two-file PHPUnit command завершился exit 0;
- `OK (18 tests, 87 assertions)`.

## Проверки

- PHPUnit, только два Task 3 файла: `OK (18 tests, 87 assertions)`.
- PHPStan, только изменённый production scope: `[OK] No errors`, `--memory-limit=1G`.
- Pint: 10 изменённых PHP production/test файлов; три style issue исправлены.
- После Pint выполнен свежий exact two-file PHPUnit gate: `OK (18 tests, 87 assertions)`.
- `git diff --check`: замечаний нет.
- Generated path подтверждён правилом `.gitignore`.
- Dependencies, `composer.json` и `composer.lock` не изменялись.
- DB, auth, migrations, build, browser, SSH и production commands не запускались.

Первый PHPStan запуск с локальным default limit 128 MiB завершился OOM во время project bootstrap до выдачи code diagnostics. Единственный повтор того же scoped command с `--memory-limit=1G` прошёл без ошибок.

## Concerns

Открытых замечаний по Task 3 implementation нет.

Локальный runtime проверок — PHP 8.3.7; целевая версия проекта — PHP 8.2.
