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

## Independent review round 1

Статус: `FIXED`.

Закрыты четыре блокирующих замечания.

### Identity fence

- binding, query и current owner scope проверяются до первого owner-port вызова;
- mismatch немедленно возвращает failed evidence;
- materialize/result/page/cursor/drill call counts при mismatch остаются нулевыми.

### Page/cursor/drill parity

- `hasMore` теперь строго эквивалентен наличию непустого canonical next cursor;
- page limit, sort, row prefix и cursor chunk semantics проверяются точно;
- в round 1 drill expectation первоначально был добавлен в fixture; это решение заменено отдельным typed resolver в round 2;
- opaque signed request token не используется как row key;
- drill target row/column проверяются по candidate definition и cursor rows;
- drill rows, next cursor и typed resource links входят в result-identity hash.

### Leakage и redaction

- row keys закрыты allowlist `row_key + definition.columns`;
- undeclared поля fail closed;
- учитываются default `SENSITIVE`, sensitive/audit columns, totalsSensitive, totalsAudit и provenanceAudit;
- result/page/cursor/drill/totals/quality/provenance/resource-link projections рекурсивно проверяются;
- проверяются не только имена, но и secret/PII credential-like значения;
- link params с token/credential keys отклоняются.

### Filesystem symlink fence

- repository root, schema, evidence и directory path проверяются через `lstat`/`is_link` до `realpath`;
- проверяется вся цепочка существующих компонентов внутри repository root;
- schema/evidence symlink regressions добавлены;
- на текущей Windows оба symlink regressions явно `Skipped`, поскольку ОС вернула failure при создании file symlink; silent pass отсутствует.

### Проверки после round 1

- amended exact two-file PHPUnit gate: `24 tests`, `123 assertions`, `2 skipped` только по документированной platform incapability;
- PHPStan changed production: `[OK] No errors`, `--memory-limit=1G`;
- Pint changed PHP files: PASS после двух точечных style fixes;
- `git diff --check`: замечаний нет;
- published registry/assembler/map dependencies отсутствуют;
- Task 4 / global Task 18 не начинался.

## Independent review round 2

Статус: `FIXED`.

Закрыто новое блокирующее замечание к публичному контракту fixture.

### Канонический fixture contract

- `ReportConformanceFixture` восстановлен до точных семи обязательных constructor fields в каноническом порядке;
- `ReportDrillDownCell` и ожидаемый SHA-256 drill result удалены из DTO и test fixture builder;
- reflection regression фиксирует точные имена, типы, порядок, обязательность и отсутствие default values;
- evidence schema и canonical JSON fixture не изменялись: внутренние поля conformance fixture в evidence не сериализуются.

### Typed drill expectation dependency

- добавлены immutable `ReportConformanceDrillExpectation` и typed `ReportConformanceDrillExpectationResolver`;
- resolver принимает `fixtureHash`, а harness требует его единственным обязательным constructor dependency без fallback/default;
- resolved expectation обязан содержать тот же `fixtureHash`;
- missing expectation, exception resolver или hash mismatch возвращают failed evidence до первого owner-port вызова;
- точные проверки drill target row/column, request cursor/limit и полного result hash сохранены;
- resolver включён в component class hashes evidence;
- тесты используют детерминированный resolver и не создают runtime registry/map.

### TDD и проверки round 2

RED:

- amended exact two-file PHPUnit gate завершился exit 1;
- первая ошибка: отсутствовал `ReportConformanceDrillExpectationResolver`.

GREEN:

- amended exact two-file PHPUnit gate: `26 tests`, `160 assertions`, `2 skipped` только из-за недоступности file symlink на текущей Windows;
- PHPStan changed production scope: `[OK] No errors`, `--memory-limit=1G`;
- Pint changed production/test PHP files: PASS, исправлен один style issue;
- schema/canonical evidence fixture не изменялись;
- Task 4 / global Task 18 не начинался.
