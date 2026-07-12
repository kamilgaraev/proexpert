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

