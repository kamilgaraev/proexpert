# Plan 3 — Task 11: промежуточный отчёт

Статус: **INTERMEDIATE — benchmark gate открыт, Task 11 не завершена**.

## Безопасно реализовано

- Введён единый неизменяемый `ReadinessResult` с `can_generate`, `can_apply`, блокирующими причинами, предупреждениями, метриками и следующим действием.
- `DraftReadinessInspector` используется snapshot и границей применения черновика; старые AI-черновики без подтверждённой production-модели не получают новый допуск.
- Удалены rule-based drawing runtime, его interface/binding и устаревшие тесты. Production bindings используют `VisionProvider` и `CadGeometryProvider`.
- Benchmark runner выполняет prediction до чтения expected labels; отдельный regression test фиксирует разделение портов.

## Проверки безопасной части

```text
vendor/bin/phpunit tests/Unit/EstimateGeneration/Quality/ProductionReadinessGateTest.php tests/Architecture/EstimateGenerationNoPlaceholderTest.php tests/Unit/EstimateGeneration/EstimatorReadinessServiceTest.php
OK (27 tests, 124 assertions) — совместно с focused benchmark tests в последнем прогоне.

vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Services/Quality app/BusinessModules/Addons/EstimateGeneration/Services/EstimateDraftPersistenceService.php app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionSnapshot.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/CurrentBaselineBenchmarkAdapter.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php --memory-limit=1G --no-progress
[OK] No errors
```

Расширенный SQLite-набор содержит независимую инфраструктурную ошибку `no such function: BTRIM` в warehouse migration и не используется как доказательство готовности Task 11.

## Открытый benchmark gate

Свежий честный regression benchmark после удаления недопустимых full-prediction artifacts:

```json
{
  "attempted_count": 4,
  "succeeded_count": 0,
  "failed_count": 4,
  "skipped_count": 0,
  "work_recall": 0,
  "normative_top3": 0,
  "evidenced_applicable_items": 0,
  "technical_success_rate": 0,
  "deterministic_fingerprint": "90b3892d9b06dd1830fa79b92e4c33236832dfe815da7d1b05bad0570024fd14"
}
```

Пороги не снижались. Для завершения необходимы отдельные закрытые recorded envelopes только для недетерминированных external/AI ports, offline immutable catalog ports и production replay через DTO validators, `BuildingModelAssembler`, quantities, normative hard gates, pricing snapshots и readiness. Expected labels должны загружаться только после prediction. Regression-корпус требуется расширить минимум до восьми независимых разнообразных случаев с disjoint acceptance corpus.

Полный Plan 3 gate пока также имеет два failure и 18 skips: worker-contract ожидает отсутствующий успешный replay, а LibreDWG runtime gate не настроен через `LIBREDWG_DWGREAD_BINARY`.

## Slice: два committed input-side production replay кейса

Статус: **INTERMEDIATE — два кейса проходят, общий gate Task 11 остаётся открытым**.

Добавлены два независимых regression-кейса в отдельном `production-replay-manifest.json`: vector DXF с подтверждёнными единицами, комнатой, стеной и типизированным дверным проёмом; raster/vision sketch с комнатой, стеной, типизированным проёмом и двумя согласованными свидетельствами масштаба.

Вход и expected физически разделены. Projection, recorded envelopes и catalog snapshots не содержат expected/prediction/readiness/final/total-cost полей. Каждый catalog содержит два применимых кандидата: альтернативный расположен первым, production reranker выбирает второй primary-кандидат. Цены разрешаются только по точным `price_id`, region/zone/period/version snapshot.

### RED → GREEN

```text
vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/ProductionReplayCommittedCasesTest.php
RED: BenchmarkManifestException: manifest_size_invalid
Причина: production replay manifest и committed cases отсутствовали.

После fixtures и wiring:
OK (1 test, 30 assertions)
```

Тест выполняет runner дважды и проверяет одинаковые fingerprint/case results, отсутствие oracle-полей, non-oracle порядок кандидатов и exact prices.

### Focused tests и статический анализ

```text
vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/ProductionReplayCommittedCasesTest.php tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapterTest.php tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkLaravelIntegrationTest.php tests/Unit/EstimateGeneration/Benchmark/RecordedPortEnvelopeTest.php tests/Unit/EstimateGeneration/Benchmark/RecordedPortEnvelopeLoaderTest.php tests/Unit/EstimateGeneration/Benchmark/RecordedBenchmarkCatalogLoaderTest.php tests/Unit/EstimateGeneration/Benchmark/BenchmarkRunnerTest.php tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php
OK (31 tests, 138 assertions)

vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkManifest.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapter.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/RecordedPortEnvelope.php app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEstimateGenerationBenchmarkCommand.php app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEstimateGenerationBenchmarkCaseCommand.php --memory-limit=1G --no-progress
[OK] No errors
```

`php -l` выполнен для всех пяти изменённых production PHP-файлов и нового E2E-теста: ошибок нет.

### Реальный CLI, два запуска

```text
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v1 --prompt-version=recorded-ports:v1 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-run-1.json
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v1 --prompt-version=recorded-ports:v1 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-run-2.json
```

Оба запуска: attempted=2, succeeded=2, failed=0, skipped=0. Идентичный fingerprint: `beb8c0c6daef5a9abd6e8ce7d228191d979f9a2ae3c16578dc5775da5dc34e07`; `case_results` идентичны.

Все метрики двухкейсного slice имеют macro=1 и micro=1: `area_mape`, `cost_mape`, `evidenced_applicable_items`, `normative_top1`, `normative_top3`, `opening_f1`, `quantity_mape`, `room_iou`, `sheet_classification_accuracy`, `technical_success_rate`, `wall_iou`, `work_recall`.

```text
reg-replay-vector-wall-opening-001 prediction_identical=true sha256=d883940b4ea22d4172b2aa7897d0f32b09f6720538034ee4dae01ab58db02c69
reg-replay-vision-sketch-001 prediction_identical=true sha256=7b1a10fe64be90ad76aa1a27347258e556b3f3b4036d64b3c77520a20a5b3554
forbidden_artifact_fields: none
```

Это не подтверждает общие thresholds и не завершает Task 11: regression corpus требуется расширить минимум до восьми разнообразных независимых кейсов и пройти полный Plan 3 gate, включая LibreDWG runtime gate.
