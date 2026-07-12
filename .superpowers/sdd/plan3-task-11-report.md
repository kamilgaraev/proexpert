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

## Review fixes production replay slice

Статус: **INTERMEDIATE — findings закрыты для двух кейсов, общий Task 11 gate остаётся открытым**.

RED подтверждён отдельными тестами:

```text
RecordedPortEnvelopeTest: valid envelope rejected до добавления обязательного privacy_result.
RecordedPortRequestHasherTest: RecordedPortRequestHasher class not found.
BenchmarkManifestTest: expected_contract_invalid при загрузке manifest до запуска adapter.
```

Исправления:

- manifest до prediction проверяет только input descriptor/bytes; expected безопасно читается и проверяется по root/traversal/symlink/size/SHA/schema в runner после adapter return;
- parent/worker используют один registered immutable manifest reference, привязанный к normalized locator и SHA; unregistered locator, traversal и tampered SHA отклоняются;
- envelopes требуют `privacy_result=passed`, missing/unknown значения отклоняются;
- `input_dependency_sha256` теперь является canonical SHA production request: geometry — source locator/hash/port, planner — building model/quantities/evidence, reranker — intent/context/ordered candidate set;
- DXF заменён содержательным планом с room polyline, wall, explicit `A-OPENING-DOOR`, dimension layer и 4000/3000 mm cues;
- raster заменён планом 320×240 с внешними/внутренними стенами, дверным разрывом и дугой, двумя видимыми размерными линиями `6.0 m`/`4.0 m`;
- recursive forbidden scan покрывает expected/labels/metrics/prediction/readiness/final/price_total/cost_total варианты; singular semantic `label` разрешён только как поле vision element.

Проверки:

```text
Focused: OK (45 tests, 802 assertions)
PHPStan changed production files: [OK] No errors
php -l changed production files: no syntax errors
Visual raster review: room/walls/door/dimension cues visible.
DXF structural review: LWPOLYLINE=1, R1=1, A-WALL=2, A-OPENING-DOOR=2, 4000 mm=1, 3000 mm=1.
```

Финальные CLI после полной hash propagation:

```text
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v2 --prompt-version=recorded-ports:v2 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-review-run-1.json
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v2 --prompt-version=recorded-ports:v2 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-review-run-2.json
```

Оба запуска: attempted=2, succeeded=2, failed=0, skipped=0. `case_results` и metrics идентичны. Fingerprint обоих запусков: `a644bf519955c4b6b342d8fb7762da018e159227a1c2623c79e192ae3a8626c7`.

Threshold completion не заявляется: два кейса не закрывают требование полного разнообразного regression corpus и Plan 3 gate.

## Re-review: full reranker request и expected path components

Статус: **INTERMEDIATE**.

- Reranker dependency SHA переведён на `recorded-reranker-request:v2`: полный `WorkIntentData`, полный `NormativeCandidateDecisionContextData`, полный ordered `NormativeCandidateSetData`, каждый candidate DTO, rejected candidates, metadata/status/blocking/scoring versions. DateTime нормализуется с microseconds/timezone, key ordering canonical, float semantics сохраняются JSON_PRESERVE_ZERO_FRACTION.
- Tests подтверждают изменение SHA для одинаковых IDs при изменении semantic score, material, candidate/source evidence, intent region/applicability, context schema/model contract, set status/blocking issues и candidate order; dependency verifier отклоняет mismatch.
- Expected reader теперь до `realpath` проходит каждый component через `lstat`, отвергает traversal, terminal symlink и symlinked parent. На Windows test создаёт directory junction через `mklink /J` без admin и проходит без skip; на Unix используется symlink.

```text
Fresh full covering suite после junction fallback: OK (48 tests, 813 assertions), без skips.
PHPStan changed production files: [OK] No errors.
```

Финальные CLI после нового reranker request hash и projection SHA:

```text
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v3 --prompt-version=recorded-ports:v3 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-rereview-run-1.json
php artisan estimate-generation:benchmark --dataset=regression --adapter=production-replay --pipeline-version=production-replay-cases:v3 --prompt-version=recorded-ports:v3 --manifest=production-replay-manifest.json --format=json --output=task11/production-replay-rereview-run-2.json
```

Оба: attempted=2, succeeded=2, failed=0, skipped=0; `case_results` идентичны; fingerprint `ce95a125af47dcc4213e7533e606cff6a52cb972688b12518d5dfd2af537ea4e`.

Task 11 thresholds не заявляются завершёнными.
