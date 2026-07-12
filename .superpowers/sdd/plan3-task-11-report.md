# Plan 3 — Task 11: промежуточный отчёт

## Task A — INTERMEDIATE: реальные источники и geometry captures

### Повторная проверка Task A: независимая source traceability

После review tests-only capture boundary дополнен `RecordedVisionSourceTraceVerifier`. До создания envelope он независимо читает source bytes: PPM raster, embedded image stream scanned PDF или SVG DOM. Проверяются SHA источника, реальные wall segments, bitmap-глифы размеров, SVG IDs/text, точные координаты заявленных элементов, scalar масштаба и площадь 44 м². Verifier не читает expected labels или prediction. Негативные тесты отклоняют подменённый dimension label, SVG source ID и capture point.

Raster и scanned PDF используют помещение 320×220 px с подписями `8.0 m` и `5.5 m`, arrow/extension lines и единым масштабом `0.025 m/px`; площадь равна 44 м². Engineering SVG использует единый scalar `0.01 m/unit`, размеры 650×370, стабильные IDs, точные door/riser coordinates. Freehand capture воспроизводит точки source path и остаётся typed review из-за отсутствующего масштаба. Vector PDF теперь имеет реальный разрыв верхней стены 260→320, текст `OPENING 600 mm` и не содержит перекрывающего gap segment; fresh pypdfium gate проверяет это по production path primitives.

Повторный focused gate: `OK (6 tests, 66 assertions)`.

Шесть новых fixtures заменены на содержательные трассируемые входы. Vector PDF содержит замкнутый многолинейный план, внутреннюю стену, проём и видимые размеры `4400 mm`/`2900 mm`; recording создаётся production worker на pinned `pypdfium2 5.8.0`. Валидный maintainer-authored DWG декодируется тем же production CAD worker через scoped LibreDWG `0.13.4`; parser proof связывает source SHA-256, runtime version, canonical output SHA-256 и фактические entity/text/dimension counts.

Scanned PDF содержит встроенный raster 400×300 с видимыми стенами, дверным разрывом, размерными линиями и bitmap-глифами. Dimensioned raster — отдельный 400×300 PPM с реальными пикселями, без размеров в комментариях. Engineering SVG имеет стабильные IDs помещения, проёма, стояка, узла и двух размеров; recording связывает `riser-110` с `engineering-riser-110`. Freehand SVG содержит неуверенный пунктирный контур, кривую перегородку и видимый вопрос; recording использует согласованный `freehand-evidence` и typed `scale_missing`.

Builder больше не использует fabricated `vectorPayload` для PDF/DWG: оба capture получают точный JSON production worker. Focused gate свежо декодирует PDF и DWG и сравнивает полный payload/proof, проверяет конкретные пиксели, image dimensions, SVG IDs и уникальность всех шести payload hashes.

Проверка: `vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/ProductionReplaySourceCaptureTest.php` — `OK (5 tests, 57 assertions)`. Общий production-replay gate намеренно не считается зелёным: downstream projection/planner/reranker/catalog hashes после замены источников относятся к следующему bounded task. Статус Task 11 остаётся `INTERMEDIATE`.

## Corpus gate: восемь production replay случаев

Статус: **DONE_WITH_CONCERNS — corpus/threshold/LibreDWG gates закрыты; полный Plan 3 suite/review остаётся отдельным воротом**.

Корпус расширен до восьми независимых источников: dimensioned DXF, raster sketch, vector PDF, scanned PDF, DWG, dimensioned raster, uncertain freehand sketch и engineering layout. Только freehand остаётся typed review; остальные пять новых случаев проходят production `GeometryBuildingModelInputMapper → BuildingModelAssembler → NormalizedBuildingModelQuantityInputMapper → BuildingQuantityCalculator → WorkPlanCompiler → NormativeMatchingWorkflow → ResourceAssemblyService → EstimatePricingService`.

Tests-only capture builder вычисляет planner dependency из фактической модели/quantities/evidence. `CapturingReranker` вызывается внутри production matching workflow и фиксирует фактические `WorkIntentData`, decision context и candidate set для `RecordedPortRequestHasher::reranker`; decision payload авторизован независимо. Expected labels созданы вручную по источникам, спецификации work intent, catalog snapshots и точным ценам; `expected-authoring-plan3-task11.json` фиксирует `prediction_output_used=false`.

RED: первоначальный corpus test ожидал 8, но получал 2; первый фактический CLI после добавления geometry-only artifacts имел 6 review-only случаев. GREEN: пять определённых случаев получили закрытые planner/reranker envelopes, отдельные catalogs с двумя кандидатами (alt перед primary), exact regional prices и полную hash chain.

```text
ProductionReplayCommittedCasesTest: PASS
Focused benchmark/adapter/envelope/catalog/CLI: OK (56 tests, 105 assertions)
PHPStan changed production + capture builder: [OK] No errors
```

Два свежих CLI-запуска:

```text
attempted=8 succeeded=8 failed=0 skipped=0
work_recall=1 normative_top3=1 evidenced_applicable_items=1 technical_success_rate=1
fingerprint=ca0747e3153505bf295938d60795f4f9e26e7c251785a74aeb5d52faa4d8f8ab
case_results identical=true; fingerprint identical=true
```

LibreDWG выполнен scoped, без изменения глобального PATH: security runtime harness завершился `libredwg bootstrap runtime: PASS`; свежий cache вернул `dwgread 0.13.4`; реальный decode `simple-house.dwg` — `OK (1 test, 3 assertions)`.

## INTERMEDIATE: capture tooling и typed review outcome

Добавлен tests-only `RecordedFixtureCaptureBuilder`, использующий production request hashers для geometry, planner и reranker. Tooling не читает expected, отклоняет oracle-поля, проверяет source SHA, privacy/approval metadata и формирует стабильный reviewable inventory.

Production replay теперь моделирует clarification/incomplete geometry как технически успешный typed review outcome: `review_codes` и `review_items` содержат вопросы и ссылки на evidence, а work/normative/price коллекции остаются пустыми. Hard apply/readiness граница не ослаблена. Добавлена отдельная метрика `review_recall`.

```text
vendor/bin/phpunit tests/Unit/EstimateGeneration/Benchmark/RecordedFixtureCaptureBuilderTest.php tests/Unit/EstimateGeneration/Benchmark/BenchmarkMetricTest.php tests/Unit/EstimateGeneration/Benchmark/BenchmarkContractValidatorTest.php tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapterTest.php
OK (14 tests, 76 assertions)

vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkExpectedContract.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/Metrics/MetricRegistry.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapter.php --memory-limit=1G --no-progress
[OK] No errors
```

Статус остаётся `INTERMEDIATE`: corpus gate из восьми кейсов на этом этапе ещё не заявлен.

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

## Pinned LibreDWG runtime prerequisite

Статус: **GREEN — обязательный runtime smoke выполняется без skip**.

RED: контрактный тест `libredwg_bootstrap_is_repository_owned_pinned_and_user_local` завершился ошибкой из-за отсутствующего repo-owned bootstrap. GREEN: добавлен идемпотентный `tests/Runtime/bootstrap-libredwg-runtime.ps1`, который устанавливает официальный Windows x64 release asset LibreDWG 0.13.4 только в пользовательский cache.

Источник: `https://github.com/LibreDWG/libredwg/releases/download/0.13.4/libredwg-0.13.4-win64.zip`; SHA-256 из официального `dist.sha256`: `cb46bce034296e91cb1a982cd53ec1928b11f4f7f70512dd21513a27959688b5`; runtime: `C:\\Users\\kamilgaraev\\.cache\\most-libredwg\\0.13.4\\win64\\dwgread.exe`.

```text
vendor/bin/phpunit tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php --filter libredwg_bootstrap
OK (1 test, 7 assertions)

tests/Runtime/bootstrap-libredwg-runtime.ps1
C:\Users\kamilgaraev\.cache\most-libredwg\0.13.4\win64\dwgread.exe

$env:LIBREDWG_DWGREAD_BINARY = (& '.\\tests\\Runtime\\bootstrap-libredwg-runtime.ps1')
vendor/bin/phpunit tests/Unit/EstimateGeneration/Vision/DwgDxfGeometryProviderTest.php --filter real_synthetic_dwg
OK (1 test, 3 assertions)

Scoped covering run: OK (8 tests, 41 assertions)
```

Архив проверяется до распаковки, версия проверяется после неё. Docker, административные права и глобальный PATH не использовались. Smoke доказал реальное декодирование committed `simple-house.dwg` через LibreDWG 0.13.4.

### Security re-review LibreDWG bootstrap

Первоначальная формулировка выше была неполной: первая версия bootstrap рекурсивно искала executable в writable cache и не аутентифицировала опубликованную установку. После независимого review этот вариант заменён.

Текущий контракт закрепляет точный `dwgread.exe` в корне официального архива, SHA-256 binary `88f3c398bc1ff5a83c365fe8180018ef26947a63fff21fad8a032dd056a47c94` и SHA-256 отсортированного списка всех 75 путей архива `f9e13dea1b8f4ac19d4c91bd76c9b7c56c60f6c68f411b40981964d4d6a69c6b`. Cache считается валидным только при совпадении marker, archive SHA, версии, exact relative path и binary SHA. До этих проверок cached executable не запускается.

Установка сериализована named mutex. Архив скачивается только по HTTPS/TLS 1.2 с HTTPS-only redirect, ограниченными timeout/retry, проверяется по SHA до открытия и ручной распаковки. Проверяются absolute/UNC/drive/traversal paths, case-insensitive duplicates, link/reparse attributes, число записей, individual/total sizes и полный список путей. Распаковка идёт в новый staging; публикация — `Directory.Move` под lock без `-Force` в final.

```text
tests/Runtime/libredwg-bootstrap-runtime.ps1
libredwg bootstrap runtime: PASS

Покрыто: clean install; corrupt archive SHA; idempotent authenticated marker; fake cached binary/marker mismatch без исполнения; interrupted partial cache; traversal ZIP; 0.13.40/prefix/suffix false positives; exact output с non-zero exit.

vendor/bin/phpunit tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php --filter libredwg_bootstrap
OK (1 test, 15 assertions)

Clean cache bootstrap:
dwgread 0.13.4

Scoped real DWG decode:
OK (1 test, 3 assertions)
```

### Second security re-review LibreDWG bootstrap

Предыдущая версия marker покрывала только executable и список путей архива, поэтому не гарантировала неизменность DLL и остальных extracted files. Контракт заменён на pinned canonical manifest всех 63 regular files: для каждого фиксируются normalized relative path, length и SHA-256; SHA-256 полного манифеста — `be36775704db58bd820cad03c0e50212fa2d1041512c578d322ff1996a94de7a`. Marker связывает version, archive SHA, binary path/hash и file-manifest SHA. При каждом cache hit весь манифест пересчитывается до запуска `dwgread`; missing, extra, mutated или reparse file закрывает cache.

Archive source сначала канонизируется и единожды копируется через handle без совместного доступа на запись в private work. Hash, structural inspection и extraction используют только private copy. Каждый extraction target повторно канонизируется непосредственно перед `CreateNew` и проверяется внутри staging; reparse components запрещены.

Публикация не удаляет текущий final: под canonical mutex он переименовывается в unique backup, staging переименовывается в final, затем новый final полностью аутентифицируется. При injected second-move failure backup восстанавливается. Mutex вычисляется как SHA-256 canonical absolute lower-case Windows cache path; relative, case и `..` aliases получают одно имя.

```text
tests/Runtime/libredwg-bootstrap-runtime.ps1
libredwg bootstrap runtime: PASS

Поведенческие проверки: mutated DLL и extra executable не достигают version-process seam; archive replacement после private copy не влияет на extraction и traversal не выходит из staging; relative/case aliases имеют одинаковый mutex; два concurrent alias process публикуют одну установку; injected publish failure восстанавливает прежний authenticated final.

Clean-cache bootstrap: dwgread 0.13.4
Scoped real DWG decode: OK (1 test, 3 assertions)
```

Финальная проверка edge cases после добавления exact cache layout (75 entries, включая directories) и запрета reparse components:

```text
tests/Runtime/libredwg-bootstrap-runtime.ps1
clean-install: PASS
idempotent-marker: PASS
mutated-dll-no-launch: PASS
extra-file-no-launch: PASS
reparse-no-launch: PASS
partial-cache-recovery: PASS
traversal-rejection: PASS
archive-swap-isolation: PASS
canonical-mutex-alias: PASS
libredwg bootstrap runtime: PASS

tests/Runtime/libredwg-bootstrap-concurrency.ps1
libredwg bootstrap concurrency: PASS

tests/Runtime/libredwg-bootstrap-rollback.ps1
libredwg bootstrap rollback: PASS
```

### Final publication transaction review

Publication transaction теперь охватывает `staging -> final`, полную post-publish аутентификацию и backup cleanup. При любой ошибке, пока authenticated backup доступен, новый final атомарно переименовывается в `win64.failed.*`, backup возвращается в `win64`, восстановленная установка полностью проверяется и только затем quarantine удаляется bounded cleanup. Backup перед обычным удалением переименовывается в `win64.retired.*`; cleanup failure не делает невалидный final authoritative. Перед каждым cache-hit под canonical mutex выполняется reconciliation `backup/failed/retired`, поэтому stale generation не игнорируется.

```text
libredwg-bootstrap-rollback.ps1 -Scenario FAIL_SECOND_MOVE
publish-rollback-FAIL_SECOND_MOVE: PASS
libredwg bootstrap rollback: PASS

libredwg-bootstrap-rollback.ps1 -Scenario FAIL_POST_VALIDATE
publish-rollback-FAIL_POST_VALIDATE: PASS
libredwg bootstrap rollback: PASS

libredwg-bootstrap-rollback.ps1 -Scenario FAIL_BACKUP_CLEANUP
publish-rollback-FAIL_BACKUP_CLEANUP: PASS
libredwg bootstrap rollback: PASS
```

Каждый сценарий проверяет неизменный marker старой установки, отсутствие `win64.backup.*`/`win64.failed.*`/`win64.retired.*`, точный `dwgread 0.13.4` восстановленного runtime и чистый следующий idempotent cache-hit.

### Final reconciliation precision

Reconciliation больше не удаляет backup при одном лишь наличии final. Сначала полностью аутентифицируется final. Только valid final разрешает bounded cleanup backup/failed/retired. Если final invalid, ищется ровно один authenticated backup: invalid final уходит в quarantine, backup атомарно возвращается, повторно аутентифицируется, после чего quarantine очищается. При отсутствии или неоднозначности authenticated backup выполнение закрывается без уничтожения последней доверенной generation.

Backup-cleanup тест исправлен: injection происходит внутри `Remove-TreeBounded` после `backup -> win64.retired.*`. Первый вызов оставляет valid current final и один stale retired; следующий cache-hit сначала аутентифицирует final, затем очищает retired и возвращает runtime. Это не называется rollback старого runtime.

```text
libredwg-bootstrap-rollback.ps1 -Scenario CRASH_STATE_RESTORE
crash-state-restore: PASS
libredwg bootstrap rollback: PASS

libredwg-bootstrap-rollback.ps1 -Scenario FAIL_RETIRED_CLEANUP
publication-scenario-FAIL_RETIRED_CLEANUP: PASS
libredwg bootstrap rollback: PASS
```

Seeded crash state содержит invalid incomplete final и authenticated backup. Вызов использует отсутствующий archive path, поэтому восстановление доказано без download/extraction. Process seam записывает SHA каждого executable, достигшего version check; все записи равны pinned SHA `88f3c398bc1ff5a83c365fe8180018ef26947a63fff21fad8a032dd056a47c94`, то есть invalid final не запускался.
