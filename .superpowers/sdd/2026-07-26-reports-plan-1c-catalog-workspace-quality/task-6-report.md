# Task 6 — generated seven-group catalog

## Result

Implemented generated catalog artifacts, permission/title translations, stable resource fixture and lock for the МОСТ reporting module.

- Platform manifest: `b6b44eac45e211acda9465c1d29f569403eddc1af9cf72d45c4da8f663263b07`.
- Phase: `platform`.
- Published count: `0`.
- Stable group order: `portfolio`, `projects`, `finance`, `procurement_warehouse`, `team`, `quality_safety`, `partners_customers`.
- Current TypeScript published-code union is `never`; it is generated from the actual published registry and contains no handwritten report identifiers.

## Architecture

- `GetReportCatalogHandler` reads only published definitions with metadata and scheduling capabilities, sorts by explicit group rank and manifest ordinal, and never exposes candidate definitions.
- The handler consumes the established catalog authorization object when supplied by the HTTP orchestration. Direct action use rechecks `VIEW` through `ReportAccessService`; forbidden definitions are omitted.
- The authorization context must match both actor and canonical scope before visibility is used.
- `ReportCatalogDefinitionView` is the resource-facing contract; `manifestOrdinal` remains internal and is absent from every wire artifact.
- The artifact generator verifies raw manifest hash identity, produces JSON, TypeScript, translation/resource artifacts and the lock. `release` requires 28 unique published reports and every catalog group to be non-empty.
- Translation generation validates Russian group, title and permission labels and emits no role assignments.

## Verification

Executed once after the implementation block:

```text
vendor/bin/phpunit tests/Unit/Reporting/Catalog/GetReportCatalogHandlerTest.php tests/Unit/Reporting/Generation/ReportCatalogArtifactGeneratorTest.php tests/Architecture/Reporting/ReportPermissionTranslationGenerationTest.php
OK (4 tests, 11 assertions)

php scripts/reporting/generate-reporting-contracts.php --phase=platform --check
reporting-contracts: clean

vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogDefinitionView.php app/BusinessModules/Core/Reporting/Application/Catalog/GetReportCatalogHandler.php app/BusinessModules/Core/Reporting/Application/Generation app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php --no-progress --memory-limit=1G
[OK] No errors

vendor/bin/pint --test <changed reporting PHP files>
PASS

git diff --check
exit 0
```

The first PHPStan invocation used the project default 128 MB and exhausted memory in a dependency parser; the same exact target completed without errors with the explicit 1 GB analysis limit. Formatting findings were fixed once, then the changed files passed Pint.

## Risks and follow-up

- The checked-in manifest currently has no `published` definitions, so platform artifacts intentionally contain an empty definitions list. This is valid platform output, not release evidence.
- Release generation remains intentionally blocked until the manifest has exactly 28 published definitions distributed across all seven groups.

## Commits

- `767b06be1 feat[reports]: добавлена семигрупповая генерация каталога`
