# Plan 1c Task 2 — fail-closed loader и nominal registries

## Статус

`DONE`

Task 2 реализован изолированно на ветке `feat/reports-task12a`.

- Exact base: `bd90d2f586923c89ed8674cd82d95dd7f550d29f`
- Commit: текущий commit, содержащий этот отчёт
- Scope: только Plan 1c Task 2 / global Task 16
- Task 3 / global Task 17 не начинался

## Реализация

Добавлены 14 production-файлов:

- immutable `LoadedReportManifest` с exact management/official count и duplicate-code invariants;
- metadata и scheduling DTO;
- metadata и scheduling registry contracts;
- single-read YAML loader;
- semantic validator;
- read-only permission catalog;
- raw-row `ReportDefinitionFactory`;
- nominal candidate/published registries;
- hash-aligned metadata/scheduling registries;
- отдельный M-29 registry.

Loader выполняет фиксированную последовательность:

1. один раз читает manifest bytes;
2. отклоняет невалидный UTF-8;
3. вычисляет SHA-256 исходных байтов;
4. разбирает уже прочитанную строку через Symfony YAML;
5. без изменения values преобразует PHP array в JSON-compatible object graph;
6. выполняет Draft 2020-12 validation через Task 1 Opis adapter;
7. выполняет semantic, permission, group, wave, readiness, capability и M-29 checks;
8. создаёт immutable `LoadedReportManifest`.

Management contract проверяет:

- ровно 28 строк и уникальные codes;
- exact семь group mappings;
- waves `12/10/6`;
- отсутствие M-29;
- уникальные IDs contract collections;
- закрытые title/permission keys;
- non-empty candidate/published filters, columns, sorts и formats;
- ready/ready/verified для published;
- scheduling capability consistency.

Official contract допускает только одну строку `official_material_usage_m29`.

`ReportPermissionCatalog` только читает `config/RoleDefinitions`, module permission sources и `lang/ru/permissions.php`. Roles, permission sources и переводы не изменяются.

`ReportDefinitionFactory` вычисляет `definitionHash` из `CanonicalJson` исходной definition row. Переведённые строки и `manifestOrdinal` в hash не входят.

Candidate registry возвращает только `CandidateReportDefinition`; published registry возвращает только `PublishedReportDefinition`. Candidate, blocked, draft и неизвестные codes на published boundary завершаются `REPORT_NOT_FOUND`.

Metadata и scheduling registries:

- используют тот же ordered published code set;
- создаются только при совпадении manifest bytes hash с published registry;
- сохраняют zero-based ordinal исходной YAML-позиции;
- не добавляют ordinal в raw definition data.

Добавлены восемь fixtures для valid management/official и отрицательных duplicate, permission, readiness, group, empty candidate capability и M-29 сценариев.

## TDD evidence

До production-файлов были созданы:

- `tests/Unit/Reporting/Catalog/YamlReportManifestLoaderTest.php`;
- `tests/Unit/Reporting/Catalog/ReportDefinitionRegistryTest.php`;
- восемь Task 2 manifest fixtures.

RED:

- exact two-file PHPUnit command завершился exit 1;
- 24/24 tests падали из-за отсутствующих Task 2 classes;
- первая ошибка: `Class "App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader" not found`.

GREEN:

- тот же exact two-file PHPUnit command завершился exit 0;
- `OK (24 tests, 103 assertions)`.

## Проверки

- PHPUnit, только два Task 2 файла: `OK (24 tests, 103 assertions)`.
- PHPStan, только 14 изменённых production-файлов: `[OK] No errors`, `--memory-limit=1G`.
- Pint: 16 PHP production/test файлов проверены, итоговый `--test` прошёл.
- `git diff --check`: замечаний нет.
- Dependencies, `composer.json` и `composer.lock` не изменялись.
- DB, auth, migrations, build, browser, SSH и production commands не запускались.

Первый PHPStan запуск с локальным default limit 128 MiB завершился OOM во время project bootstrap до выдачи code diagnostics. Единственный повтор того же scoped command с `--memory-limit=1G` прошёл без ошибок.

## Concerns

Открытых замечаний по Task 2 implementation нет.

Локальный runtime проверок — PHP 8.3.7; целевая версия проекта — PHP 8.2.
