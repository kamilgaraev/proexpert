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
