# Plan 1c Task 5 — semantic versions, nominal promotion и publication ledger

## Статус

`DONE`

Task 5 реализован изолированно на ветке `feat/reports-task12a`.

- Exact base: `5ef0980feeda97b2544aba3e0abbf37a21032730`
- Commit: текущий commit, содержащий этот отчёт
- Scope: только Plan 1c Task 5 / global Task 19
- Task 6 / global Task 20 не начинался

## Реализация

Добавлены nominal DTO:

- `ReportDefinitionSemanticDiff`;
- `ReportPublicationLock`;
- `PublishedDefinitionRelease`.

`ReportDefinitionVersionPolicy` разделяет schema-valid formula, source-schema,
public contract и renderer fingerprints. Formula fingerprint использует
version из typed passed conformance evidence; source fingerprint включает
manifest filters/grain и typed source-schema identity; contract использует
filters/columns/sorts/formats/catalog group; renderer использует реальные
title/category/wave поля manifest. Незаконные скрытые `formula`/`source` keys
не используются. Изменение dimension требует строго большей соответствующей
semantic version; bump без реального dimension/evidence и semantic version
drift отклоняются. Permission/readiness-only изменение сохраняет все semantic
versions.

`ReportManifestPromotionService` выполняет полный fail-closed promotion:

1. строит expected definition через `ReportDefinitionFactory::fromManifest()`
   и сравнивает полный typed canonical projection candidate wrapper;
2. сверяет expected raw candidate SHA-256 с hash Task 2 loaded manifest;
3. требует exact ordered passed validation item для каждого candidate;
4. проверяет conformance code, definition, versions, passed status и digest;
5. требует `source=ready`, `formula=ready`, `delivery=verified`,
   `publication=candidate`;
6. применяет semantic version policy;
7. запрещает изменение любого unrelated canonical definition block;
8. формирует output только с target `candidate → published`;
9. повторно загружает output bytes через Task 1 Opis schema и Task 2 semantic
   loader;
10. извлекает `PublishedReportDefinition` из повторно созданного published
    registry и сверяет payload;
11. schema-validates lock до ledger publication.

`ReportPublicationLock` constructor-validates code, все typed SHA-256,
release SHA и UTC timestamp без дробных секунд. `PublishedDefinitionRelease`
дополнительно связывает raw output hash, published manifest hash, wrapper code
и published definition hash.

`FilesystemReportPublicationLedger`:

- использует event id
  `reports:definition:{code}:published:{definitionHash}`;
- повторный идентичный event обрабатывает идемпотентно;
- conflicting bytes для того же event id отклоняет;
- держит межпроцессный exclusive `flock` от reread до commit;
- сохраняет distinct concurrent events без lost update;
- перед commit создаёт и schema/hash-проверяет временные output, lock и ledger;
- публикует новые output/lock через atomic rename;
- проверяет каждый прочитанный event: exact event id из embedded lock,
  пересчитанный lock digest и уникальность event id;
- заменяет существующий ledger только preverified staged bytes через portable
  same-directory backup/rename/rollback, без `ftruncate()` final path;
- при любой ошибке rename/reread восстанавливает ledger и удаляет уже
  опубликованные артефакты.

## Deterministic provenance и offline script

`candidate.valid.yaml` — UTF-8 без BOM, только LF, ровно один terminal LF.
Checksum fixture содержит lowercase SHA-256 полных raw bytes и один terminal
LF.

`ReportCandidateValidationFixtureBuilder` принимает loaded manifest,
isolated candidate registry и реальный keyed binding array, сам запускает
concrete `StrictReportDefinitionCandidateValidator`, отказывается
сериализовать failed result и не принимает checksum/status/hash от caller.

`candidate-validation.valid.json`:

- является exact canonical JSON с одним terminal LF;
- использует fixed artifact/schema/status/path constants;
- сохраняет candidate order;
- содержит exact code/definition hash/passed/failure-codes каждого concrete
  validator item.

`promote-report-definition.php`:

- принимает только exact explicit args Task 5;
- разрешает только canonical repository-root paths;
- повторно читает raw candidate/checksum/validation bytes;
- проверяет BOM/CRLF/terminal LF и exact checksum bytes;
- валидирует canonical validation через Task 1 Opis adapter;
- независимо пересчитывает candidate code/definition tuple;
- повторно запускает concrete Task 4 validator;
- получает fixture hash из независимого deterministic fixture source, а не из
  evidence;
- в `--check` ничего не записывает и сравнивает tracked output/lock byte-for-byte;
- в normal mode stage/preverify делает до первого commit и публикует output,
  lock и ledger одной recoverable ledger-coordinated транзакцией.

## Forward-only dependency correction

Task 3 conformance fixture на exact base содержал synthetic `quality_report`,
которого нет в management manifest. По tracked amendment
`docs/reports/contracts/plan-1c-task19-conformance-repin-amendment.md` fixture
перезакреплён на exact `project_portfolio_health` candidate. Schema, DTO и
repository Task 3 не менялись. Forged evidence с другим fixture hash и
пересчитанным digest отклоняется.

## TDD evidence

До production-кода созданы четыре обязательных test-файла.

RED:

- exact four-file PHPUnit command завершился exit 1;
- отсутствовал `ReportDefinitionVersionPolicy`, publication classes и fixtures.

GREEN после implementation и review fixes:

- exact four-file PHPUnit gate: `OK (26 tests, 214 assertions)`;
- exact offline `--check`: `promotion-check: PASS`.

Покрыты semantic drift, unrelated block, reload published wrapper, strict
schemas, validator-derived bytes, ledger idempotency/conflict, injected
transaction rollback, два конкурентных distinct append и caller-authored
conformance с пересчитанным digest.

## Exact manifest

- `app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinitionSemanticDiff.php`
- `app/BusinessModules/Core/Reporting/Domain/DTO/ReportPublicationLock.php`
- `app/BusinessModules/Core/Reporting/Domain/DTO/PublishedDefinitionRelease.php`
- `app/BusinessModules/Core/Reporting/Application/Publication/ReportDefinitionCanonicalProjector.php`
- `app/BusinessModules/Core/Reporting/Application/Publication/ReportDefinitionVersionPolicy.php`
- `app/BusinessModules/Core/Reporting/Application/Publication/ReportManifestPromotionService.php`
- `app/BusinessModules/Core/Reporting/Infrastructure/Publication/FilesystemReportPublicationLedger.php`
- `docs/reports/contracts/report-publication-lock.schema.json`
- `docs/reports/contracts/report-publication-ledger.schema.json`
- `docs/reports/contracts/report-candidate-validation.schema.json`
- `docs/reports/contracts/plan-1c-task19-conformance-repin-amendment.md`
- `scripts/reporting/promote-report-definition.php`
- `tests/Fixtures/Reporting/Publication/candidate.valid.yaml`
- `tests/Fixtures/Reporting/Publication/candidate.valid.sha256`
- `tests/Fixtures/Reporting/Publication/candidate-validation.valid.json`
- `tests/Fixtures/Reporting/Publication/published.expected.yaml`
- `tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json`
- `tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json`
- `tests/Support/Reporting/Publication/ReportCandidateValidationFixtureBuilder.php`
- `tests/Unit/Reporting/Publication/ReportDefinitionVersionPolicyTest.php`
- `tests/Unit/Reporting/Publication/ReportManifestPromotionServiceTest.php`
- `tests/Unit/Reporting/Publication/ReportCandidateValidationFixtureBuilderTest.php`
- `tests/Architecture/Reporting/ReportPublicationSchemaTest.php`
- `.superpowers/sdd/2026-07-26-reports-plan-1c-catalog-workspace-quality/task-5-report.md`

## Проверки

- Exact Task 5 PHPUnit + script gate:
  `OK (26 tests, 214 assertions)`, `promotion-check: PASS`.
- Task 3 repin schema regression:
  `OK (4 tests, 14 assertions)`.
- PHPStan changed production scope, `--memory-limit=1G`:
  `[OK] No errors`.
- Pint `--test`, 12 changed production/test/script PHP-файлов: `PASS`.
- `git diff --check`: замечаний нет.
- Candidate/checksum/validation/output/lock byte identities проверены.
- Dependencies, `composer.json` и `composer.lock` не изменялись.
- DB, auth, migrations, build, browser, SSH и production commands не запускались.

## Independent review

Первоначально закрыты три blocking finding:

- output/lock/ledger теперь preverified до commit и откатываются как единая
  recoverable transaction;
- ledger append сериализован межпроцессной блокировкой и покрыт concurrent
  two-process regression;
- fixture identity берётся из независимого deterministic registry, forged
  evidence с новым digest отклоняется.

### Review round 1

Закрыты ещё шесть blocking finding:

- version policy переведён с schema-invalid synthetic keys на fingerprint
  реальных production-loaded manifest rows и typed conformance evidence;
- добавлен `ReportDefinitionCanonicalProjector`; forged wrapper с настоящими
  code/hash, но чужим typed payload отклоняется;
- ledger read после schema/canonical validation пересчитывает event id и lock
  digest, а также запрещает duplicate event ids до idempotency/conflict;
- final ledger больше не переписывается через `ftruncate`; existing/new ledger
  покрыты injected backup/replace/rollback regressions;
- fixture builder независимо связывает ordered manifest candidate definitions
  с registry через factory и полный typed projection; foreign registry
  отклоняется;
- один table-driven gate покрывает BOM/CRLF/terminal LF, checksum byte matrix,
  stale candidate/evidence/output, canonical validation/path/item matrix,
  forged passed inputs, `--check` no-write, normal ledger publication/reread,
  schema enums и semantic ledger tampering.

## Concerns

Открытых замечаний по Task 5 implementation нет.

Локальный runtime проверок — PHP 8.3.7; целевая версия проекта — PHP 8.2.
