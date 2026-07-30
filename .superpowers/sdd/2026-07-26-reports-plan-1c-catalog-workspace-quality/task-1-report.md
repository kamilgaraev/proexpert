# Plan 1c Task 1 — канонические manifests и strict schemas

## Статус

`DONE`

Task 1 реализован изолированно на ветке `feat/reports-task12a`.

- Exact base: `85cdff3c7b9d2b58ca71876b96aca2175caed58e`
- Commit: текущий commit, содержащий этот отчёт
- Scope: только Plan 1c Task 1
- Task 2 / global Task 16 не начинался

## Реализация

Добавлены четыре canonical resources:

- management manifest с 28 уникальными identity в точном порядке;
- management Draft 2020-12 schema;
- official manifest с единственным `official_material_usage_m29`;
- official Draft 2020-12 schema.

Management contract закрывает:

- корень `catalog`, `contract_version`, `definitions`;
- exact contract version `1.0.0`;
- ровно 28 definitions;
- exact семь ordered catalog groups и byte-locked mapping кодов;
- waves `12/10/6`;
- обязательные versions, permissions, readiness и capabilities;
- unknown root/definition fields;
- non-empty filters, columns, sorts и formats для candidate/published;
- source/formula `ready` и delivery `verified` для published;
- duplicate permissions и formats.

Official contract отделён от management catalog и разрешает только один M-29 identity с exact title key и seal requirements.

Добавлены:

- `ReportCatalogGroup`;
- `ReportSourceReadiness`;
- `ReportFormulaReadiness`;
- `ReportDeliveryReadiness`;
- immutable `OfficialDocumentDefinition`;
- Opis-backed `Draft202012SchemaValidator`;
- internal `ReportSchemaValidationException`.

Internal schema exception содержит только переданный allowlisted schema ID и стабильный internal code `report_schema_invalid`. Opis error tree, fragments документа и manifest values в исключение не переносятся.

Корневые `$id` schemas записаны как абсолютные:

- `urn:most:reporting:management-catalog:v1`;
- `urn:most:reporting:official-document-catalog:v1`.

Это обязательное условие установленного `opis/json-schema` 2.6.0: относительный root ID `most.management-catalog.v1` завершается `ParseException: Root schema id must be an absolute URI`. Allowlisted ID internal exception остаётся `most.management-catalog.v1`.

## TDD evidence

До production-файлов созданы только:

- `tests/Unit/Reporting/Validation/Draft202012SchemaValidatorTest.php`;
- `tests/Architecture/Reporting/ReportManifestIdentityContractTest.php`.

RED:

- exact two-file PHPUnit command завершился exit 1;
- 14/14 tests падали из-за отсутствующих validator, manifests, enums и DTO;
- первая ошибка: `Class "App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator" not found`.

GREEN:

- тот же exact two-file PHPUnit command завершился exit 0;
- `OK (14 tests, 222 assertions)`.

Плановый текст указывает `178 assertions`, но canonical 14-test contract на текущем PHPUnit 11.5.0 считает 222 assertions. Все обязательные behaviors и оба exact test files сохранены; assertions не удалялись ради искусственного совпадения счётчика.

## Проверки

- PHPUnit, только два Task 1 файла: `OK (14 tests, 222 assertions)`.
- PHP syntax, девять изменённых PHP test/production файлов: ошибок нет.
- PHPStan enums, official DTO и validation: `[OK] No errors`, `--memory-limit=1G`.
- Pint: девять файлов проверены; три найденных style issue исправлены точечно.
- После Pint выполнен свежий `php -l` трёх затронутых файлов: ошибок нет.
- UTF-8 manifests, exact keys, code/group mapping, waves, duplicates и M-29 separation проверены architecture tests.
- Draft 2020-12 positive/negative validation проверена реальным `Opis\JsonSchema\CompliantValidator`.
- Dependencies, `composer.json` и `composer.lock` не изменялись.
- DB, auth, migrations, build, browser, SSH и production commands не запускались.
- Post-CI completion artifact намеренно не создавался.

Первый PHPStan запуск с локальным default limit 128 MiB завершился OOM во время project bootstrap до выдачи code diagnostics. Единственный повтор того же scoped command с `--memory-limit=1G` прошёл без ошибок.

## Concerns

Открытых замечаний по Task 1 implementation нет.

Локальный runtime проверок — PHP 8.3.7; совместимость с целевым PHP 8.2 дополнительно подтверждает CI.
