# Task 11: production replay adapter — intermediate handoff

Статус: `INTERMEDIATE`. Полный quality gate Task 11 не заявлен закрытым.

## Реализовано

- `ProductionReplayBenchmarkAdapter` соединяет projection loader, envelope loader и immutable catalog loader.
- Geometry проходит через production DTO, `GeometryBuildingModelInputMapper` и `BuildingModelAssembler`.
- Quantities проходят через `NormalizedBuildingModelQuantityInputMapper` и `BuildingQuantityCalculator`.
- Recorded planner проходит через `RecordedWorkPlannerProvider` и `WorkPlanCompiler`.
- Normative matching использует `RecordedCatalogNormativeCandidateSource`, production `NormativeHardGate`,
  `NormativeMatchingWorkflow` и `RecordedNormativeCandidateReranker`.
- Выбранный результат закрывается через `AcceptedNormativeDecisionData::fromWorkflowResult()` и
  `ResourceAssemblyService::assembleFromDecision()`.
- Цена разрешается production `EstimatePricingService`/`ResolveRegionalPrice` по exact immutable catalog row.
- Перед prediction вызывается production `DraftReadinessInspector`; заблокированный draft не считается success.

## Расширение production geometry contract

Vision opening принимается только при закрытом `geometry` с полями `wall_key`, `opening_type`, `offset`,
`width`, `height`. Vector opening принимается только при явном `semantic.kind=opening` и точных полях
`wall_handle`, `opening_type`, `offset`, `width`, `height`. Layer/name/shape эвристики не используются.
Неизвестная или неполная семантика остаётся fail-closed.

## TDD evidence

RED:

`php artisan test tests/Unit/EstimateGeneration/BuildingModel/GeometryBuildingModelInputMapperTest.php`

- Vision opening: `invalid_polyline`.
- Vector opening: `geometry_contract_entity_invalid`.

GREEN:

`php artisan test tests/Unit/EstimateGeneration/BuildingModel/GeometryBuildingModelInputMapperTest.php`

- `5 passed`, `15 assertions`.

Adapter identity RED: class отсутствовал. После минимальной реализации тест стал GREEN.

Static analysis:

`vendor/bin/phpstan analyse` для шести изменённых production PHP файлов: `[OK] No errors`.

## Anti-oracle boundary

- Projection manifest имеет exact keys и не принимает expected locator.
- Envelope запрещает `expected`, `prediction`, readiness и итоговые цены.
- Catalog запрещает expected/final/selected поля.
- `RecordedCatalogNormativeCandidateSource` строит кандидатов только из catalog records.
- Reranker проверяет exact candidate IDs и evidence refs, полученные от production retrieval.

## Открытый gate

Ещё не добавлены два независимых committed replay case с envelopes/catalog/projection/expected и CLI-прогон
двухслучайного manifest дважды. До этого нельзя заявлять Task 11 завершённой и нельзя делать threshold claim.

## Review fixes: RED → GREEN

После независимого review устранены shortcuts первого intermediate slice:

- Work intent больше не строится из первого catalog candidate. Adapter вызывает общий production
  `NormativeWorkIntentFactory`, который использует `WorkIntentClassifier` и тот же decision context, что
  `MatchNormativesStage`.
- `metrics.complete` теперь является производной метрикой `NormalizedBuildingModelData`; adapter не меняет её.
- `EstimateValidationService::validate()` рассчитывает duplicates, quality summary и review snapshot.
- Planner evidence принимается только при exact совпадении с evidence конкретных `QuantityData`; evidence всего
  building model больше не присваивается каждой позиции.
- Pricing finalization marker выводится из реально созданного price snapshot.
- `timeoutMs` проверяется до чтения fixtures, между тяжёлыми стадиями и в цикле позиций.
- `production-replay` зарегистрирован в Laravel `BenchmarkAdapterRegistry`, используемом обеими CLI-командами.

RED evidence:

`php artisan test tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapterTest.php tests/Unit/EstimateGeneration/BuildingModel/GeometryBuildingModelInputMapperTest.php`

- source assertion обнаружил `catalog->candidates[0]`, подставленные readiness поля и model-wide evidence;
- confirmed vector model не содержал производную `metrics.complete`;
- registry до изменения не содержал `production-replay`;
- нулевой CLI budget не имел раннего typed результата.

GREEN evidence:

`php artisan test tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapterTest.php tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkLaravelIntegrationTest.php tests/Unit/EstimateGeneration/BuildingModel tests/Unit/EstimateGeneration/Normatives/AcceptedNormativeDecisionDataTest.php tests/Unit/EstimateGeneration/Quality/ProductionReadinessGateTest.php`

- `73 passed`, `259 assertions` на покрывающем прогоне до добавления отдельного exact-evidence regression;
- exact-evidence regression отдельно: `5 passed`, `14 assertions` в adapter test;
- `vendor/bin/phpstan analyse` для adapter, normalized model и service provider: `[OK] No errors`.

Anti-oracle constraints не ослаблялись: adapter не читает expected и не принимает final prediction через ports
или catalog. Committed two-case replay corpus и двойной CLI replay по-прежнему остаются открытым gate.

## Exact quantity evidence review fix

Recorded planner intent теперь обязан содержать закрытый `quantity_key`. `RecordedWorkPlannerResponseData`
проверяет token и запрещает повторное сопоставление одного quantity нескольким intents. `WorkPlanCompiler`
переносит ключ в metadata позиции без изменения. Adapter ищет `QuantityData` только по exact key и требует
полного равенства отсортированных evidence sets. Выбор по позиции в массиве, названию или unit отсутствует.

RED:

`php artisan test tests/Unit/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapterTest.php --filter=planner_evidence`

- cross-quantity substitution (`floor_area` intent с evidence `opening_count`) ошибочно принималась.

GREEN:

`php artisan test tests/Unit/EstimateGeneration/Benchmark tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php`

- `105 passed`, `335 assertions`;
- happy exact mapping, cross-quantity substitution и duplicate quantity mapping покрыты отдельно;
- PHPStan трёх изменённых production boundaries: `[OK] No errors`.
