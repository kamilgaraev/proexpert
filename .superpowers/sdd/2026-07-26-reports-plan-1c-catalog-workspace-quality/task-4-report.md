# Plan 1c Task 4 — exact-set binding map и Plan 1b bridge

## Статус

`DONE`

Task 4 реализован изолированно на ветке `feat/reports-task12a`.

- Exact base: `7aa4285f2c2f67fa04847be54ac330708de51a7f`
- Commit: текущий commit, содержащий этот отчёт
- Scope: только Plan 1c Task 4 / global Task 18
- Task 5 / global Task 19 не начинался

## Реализация

Добавлены пять production-классов:

- `ReportCodeSetComparator`;
- `ReportBindingCompatibilityChecker`;
- `StrictReportDefinitionCandidateValidator`;
- `ImmutableReportDefinitionBindingAssembler`;
- `ReportingCatalogServiceProvider`.

`ReportCodeSetComparator` отдельно отклоняет wrong-type, unsafe и duplicate codes, сохраняет исходный порядок и сравнивает множества на отсортированных копиях.

Candidate validation:

- принимает только nominal `CandidateReportDefinitionRegistry`;
- требует exact equality candidate/binding sets;
- отклоняет missing, extra, duplicate и wrong-type binding до evidence lookup;
- проверяет identity wrapper относительно registry code;
- запрашивает evidence по точным code, definition hash и deterministic fixture hash;
- требует совпадение code, definition hash, contract version и fixture hash;
- требует passed source/formula evidence;
- пересчитывает SHA-256 файлов классов всех трёх provider ports;
- возвращает только `ReportCandidateValidationResult`, не создаёт runtime map.

Published assembly:

- требует exact equality published/registered sets до freeze;
- отдельно проверяет invalid/duplicate registry codes;
- проверяет identity wrapper, binding code/hash/contract и nullable readiness probe;
- сохраняет исходный manifest order независимо от порядка регистрации;
- оставляет регистрацию открытой после любой неуспешной сборки;
- замораживает assembler только после успешной compatibility-проверки;
- возвращает тот же собранный map при повторном обращении.

Для выполнения order-контракта устранена сортировка в двух существующих Plan 1a DTO:

- `ReportDefinitionBindingMap` сохраняет published insertion order;
- `ReportCandidateValidationResult` сохраняет candidate validation order.

Provider:

- зарегистрирован в `bootstrap/providers.php` ровно один раз;
- расположен сразу после `ReportingExecutionServiceProvider`;
- расположен до owner feature providers;
- публикует singleton registry, candidate registry, assembler, validator и binding map;
- загружает management manifest через существующий fail-closed Task 2 loader;
- поддерживает deployable empty/empty platform phase;
- не добавляет resolver, adapter map, fallback или новую зависимость.

Plan 1b продолжает напрямую использовать точные Plan 1a contracts: `ReportDefinitionRegistry` и конечный `ReportDefinitionBindingMap`, возвращаемый singleton `ReportDefinitionBindingAssembler`. Candidate registry в execution path не используется.

## Тесты

Добавлены шесть обязательных Task 4 test-файлов:

- `ReportCodeSetComparatorTest`;
- `ReportDefinitionCandidateValidatorTest`;
- `ImmutableBindingAssemblerTest`;
- `CandidatePublishedBoundaryTest`;
- `ReportingCatalogBindingsTest`;
- `PlanOneBPublishedBindingConsumptionTest`.

Проверены:

- сохранение non-lexicographic order;
- missing/extra/duplicate/wrong-type exact-set failures;
- exact definition/fixture evidence identity;
- все три provider class hashes;
- candidate/published nominal boundary и combined-interface `TypeError`;
- failed-before-freeze и open-after-failure;
- freeze only after success;
- empty/empty deployment;
- exact 28/28 release assembly;
- nullable readiness;
- singleton map identity;
- provider order и отсутствие execution resolver/adapters.

Финальный exact six-file gate:

- `OK (28 tests, 94 assertions)`.

Канонический текст ожидал 139 assertions. На фактическом контракте PHPUnit 11.5.0 счётчик равен 94; все 28 обязательных сценариев сохранены, искусственные assertions не добавлялись.

## Проверки

- PHPUnit, только шесть Task 4 файлов: `OK (28 tests, 94 assertions)`.
- PHPStan, только изменённый production scope: `[OK] No errors`, `--memory-limit=1G`.
- Pint `--test`, 14 изменённых production/test PHP-файлов: `PASS`.
- `git diff --check`: замечаний нет.
- Первый PHPStan запуск с default 128 MiB завершился OOM до диагностик.
- Второй запуск после добавления provider выявил реальный bootstrap gap для `LoadedReportManifest`; provider дополнен fail-closed manifest binding.
- Финальный PHPStan после исправления bootstrap прошёл без ошибок.
- Dependencies, `composer.json` и `composer.lock` не изменялись.
- DB, auth, migrations, build, browser, SSH и production commands не запускались.

## Concerns

Открытых замечаний по Task 4 implementation нет.

Локальный runtime проверок — PHP 8.3.7; целевая версия проекта — PHP 8.2.

## Review round 1

Замечания Task 18 закрыты в отдельном fix-коммите поверх первоначальной реализации.

- `GetReportRowsHandler`, `GetReportDrillDownHandler`, `ReportExportCoordinator`,
  `ReportExportExecutionService` и `MaterializeReportRunJob` напрямую получают
  `ReportDefinitionBindingMap`; повторная runtime assembly удалена.
- Единственная identity map принадлежит singleton-binding контейнера. Успешный
  assembler является one-shot: последующие `register()` и `assemble()` закрыты.
  Любая ошибка exact-set, compatibility или readiness оставляет регистрацию открытой.
- Производный `sha256(code)` удалён. Candidate validation требует
  `ReportConformanceFixtureHashRegistry`, который возвращает hash фактического
  `ReportConformanceFixture`; отсутствие fixture закрывает публикацию до evidence lookup.
- Negative matrix независимо проверяет binding code/hash/version, evidence
  code/hash/contract/fixture/pass, каждый из трёх provider hashes и runtime
  code/hash/version/readiness.
- Добавлен tracked forward-only amendment
  `docs/reports/contracts/plan-1a-task18-order-amendment.md`: candidate result сохраняет
  `candidateCodes()` order, published map сохраняет manifest `publishedCodes()` order;
  публичные сигнатуры DTO и существующие evidence artifacts не переписываются.
- Поведенческий container test регистрирует Contracts → Execution → Catalog, собирает
  точный singleton map и реально выполняет run, rows, drill-down и export. Падающий
  candidate spy остаётся с нулём обращений.

### Дополнительный manifest

- `app/BusinessModules/Core/Reporting/Domain/Contracts/ReportConformanceFixtureHashRegistry.php`
- `app/BusinessModules/Core/Reporting/Application/Catalog/ImmutableReportConformanceFixtureHashRegistry.php`
- `docs/reports/contracts/plan-1a-task18-order-amendment.md`
- `tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php`
- direct-map изменения в rows/drill/export/run consumers и их затронутых тестах.

### Финальные проверки review round 1

- Единый DB-less gate Task 4 + Plan 1a order contract + read consumers:
  `OK (58 tests, 273 assertions)`.
- PHPStan для 11 изменённых production-файлов: `[OK] No errors`,
  `--memory-limit=1G`.
- Pint `--dirty`: `20 files`, style исправлен; исполняемое поведение не менялось.
- `git diff --check`: замечаний нет.
- Расширенный диагностический запуск с существующими job suites выявил их
  самостоятельную test-bootstrap проблему: `A facade root has not been set` при
  обращении к Laravel `Log`. Direct-map сигнатуры job/export execution отдельно
  покрыты контрактным тестом и PHPStan; production-обход для тестовой проблемы не добавлялся.
- DB, миграции, build, browser, SSH и production commands не запускались.
