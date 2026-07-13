# Plan 3 — финальная широкая волна исправлений

Дата: 2026-07-13.

## Результат

Закрыты сквозные блокеры production-контура AI-сметчика МОСТ:

- единственным источником количества стал типизированный результат `ExtractQuantitiesStage`; промежуточный materializer и descriptor lookup удалены;
- readiness сведён к одному `DraftReadinessInspector`, повторно вычисляется под блокировкой перед публикацией и привязан к версии входа/попытке;
- production document processing маршрутизирует CAD в geometry provider, raster/sketch в preprocessing + Vision, а PDF постранично сохраняет отдельные geometry/preview artifacts;
- PDF worker создаёт bounded preview в приватном временном workspace и очищает его; пустой или raster-only PDF не выдаётся за vector geometry;
- production normative matching принимает только точное принятое решение из pinned catalog content и больше не вызывает legacy matcher;
- production CLI acceptance запускает единый master gate и пишет отчёт только через immutable output store.

Обычный модуль смет не изменялся. Все изменения ограничены `app/BusinessModules/Addons/EstimateGeneration`, его тестами и этим отчётом. Пользовательские `.cbmignore` и `.codebase-memory/` не добавлялись и не изменялись.

## Acceptance corpus и master gate

Изолированный acceptance-контур использует шесть независимых private, organization-scoped S3 cases с разными source bytes/digests: freehand SVG, scanned PDF, vector PDF, DXF, реальный DWG и инженерный SVG с неоднозначностью размеров/масштаба. Для каждого кейса отдельно фиксируются source SHA-256, capture/provider/model/schema provenance, privacy approval и review approval. Expected загружается runner только после prediction; projection не содержит expected/fixture-root state.

Master gate требует:

- не менее 6 кейсов;
- `failed=0`, `skipped=0`;
- `work_recall >= 0.90`;
- `normative_top3 >= 0.95`;
- `evidenced_applicable_items = 1.0`;
- `technical_success_rate >= 0.98`.

Негативные тесты подтверждают ненулевой результат отдельно для failure, skip и каждого из четырёх порогов. Production CLI вызывает этот gate после runner; отсутствие gate завершается fail-closed. Изолированный CLI-тест использует private-org S3-compatible in-memory store и `ProductionImmutableBenchmarkReportOutputStore`, без production credentials и внешних вызовов.

## RED → GREEN

RED полного авторитетного gate после смены PDF-контракта:

```text
Tests: 1 failed, 34 skipped, 517 passed (5133 assertions)
FAILED PdfVectorGeometryProviderTest > scanned only pdf is a typed review requirement
Expected exception: pdf_vector_geometry_missing
```

Причина: fixture был фактически пустым PDF, а старый тест требовал удалённый fail-closed обход. Контракт обновлён: пустая страница сохраняется с `classification=empty`, без выдуманных vector entities/text. Реальный scanned PDF отдельно проходит page-render → raster preprocessing → Vision contract.

GREEN focused PDF:

```text
php artisan test tests/Unit/EstimateGeneration/Vision/PdfVectorGeometryProviderTest.php --no-coverage
7 passed (28 assertions)
```

## Свежая верификация

Полный точный Plan 3 DB-less gate из `task-11-brief.md`, с repo-owned LibreDWG 0.13.4:

```text
$env:LIBREDWG_DWGREAD_BINARY = (& '.\tests\Runtime\bootstrap-libredwg-runtime.ps1')
php artisan test tests/Unit/EstimateGeneration/Benchmark tests/Unit/EstimateGeneration/BuildingModel tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/Quantities tests/Unit/EstimateGeneration/Normatives tests/Unit/EstimateGeneration/Pricing tests/Unit/EstimateGeneration/Quality tests/Feature/EstimateGeneration/Geometry tests/Feature/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Pricing tests/Architecture/EstimateGenerationNoPlaceholderTest.php --no-coverage
518 passed (5135 assertions), 34 skipped, 0 failures
```

Все 34 skip относятся к явно opt-in PostgreSQL suites. Waived ordinal `295/295` не запускался.

Focused F1:

```text
php artisan test tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php tests/Unit/EstimateGeneration/Vision/VerifiedCadExecutionTest.php tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php tests/Architecture/EstimateGenerationProductionReadinessTest.php --no-coverage
24 passed (151 assertions)
```

Focused F2 DB-less:

```text
vendor\bin\phpunit tests\Unit\EstimateGeneration\Migrations\TrainingBenchmarkOnlineMigrationTest.php tests\Unit\EstimateGeneration\Support\EstimateGenerationContractDatabaseProvisionerTest.php
OK (6 tests, 117 assertions)
```

Изолированный CLI acceptance/immutable output:

```text
php artisan test tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php
9 passed (27 assertions)
```

Целевой функциональный набор quantity/readiness/ingestion/normatives/acceptance:

```text
64 passed (285 assertions)
```

Acceptance master gate повторно:

```text
7 passed (14 assertions)
```

Production replay regression два последовательных раза:

```text
pass 1: 1 passed (3411 assertions)
pass 2: 1 passed (3411 assertions)
```

Статический анализ и стиль:

```text
vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/BusinessModules/Addons/EstimateGeneration
[OK] No errors

vendor/bin/pint --test <29 changed/new PHP files>
PASS

git diff --check
PASS
```

Real renderer smoke на committed scanned PDF: 1 страница, preview создан, размер 595×842, `page_role=empty`, provider `pymupdf`, model `geometry_v1`. Внешние/платные AI-вызовы, production DB, миграции и production state не использовались.

## Ограничения

Полный PostgreSQL ordinal suite не запускался согласно явному waiver. Production acceptance corpus и production S3 credentials локально не открывались; проверен тот же CLI-контракт на изолированном private S3-compatible store, включая неизменяемую запись отчёта.
