# Task 11: production mapper boundary

## Реализованный контракт

- `GeometryBuildingModelInputMapper` принимает production DTO `VisionAnalysisData` и `VectorGeometryData`, формирует `FusedGeometryElementData`, применяет `GeometryFusionService` и `ScaleResolver`, сохраняет locator, fingerprint, coordinate space/transform, runtime/model version, evidence и confidence.
- Неподдерживаемая или неоднозначная геометрия становится blocking review issue. Отсутствующий или конфликтующий масштаб не создаёт метрические размеры.
- `NormalizedBuildingModelQuantityInputMapper` преобразует все этажи, помещения, стены, проёмы и инженерные элементы в точную входную схему `BuildingQuantityCalculator`; числа передаются десятичными строками, evidence/confidence/source сохраняются.
- Runtime-стадия `ExtractQuantitiesStage` использует тот же mapper. Контракт стадии повышен до schema v2 и содержит `building_quantities`; это инвалидирует старый checkpoint стадии и зависимый `PlanWorkItems`.
- Compatibility boundary `DrawingGeometryAnalyzer` также больше не передаёт произвольный массив напрямую в калькулятор.

## Handoff для replay adapter

Replay adapter должен восстанавливать production DTO и вызывать эти же публичные boundaries:

1. Vision/CAD replay: `GeometryBuildingModelInputMapper::map(...)` → `BuildingModelAssembler::assembleVision(...)`.
2. Quantity replay: `NormalizedBuildingModelData::fromArray(...)` → `NormalizedBuildingModelQuantityInputMapper::map(...)` → `BuildingQuantityCalculator::calculate(...)`.
3. Adapter не должен копировать mapping logic, нормализовать float самостоятельно или подставлять scale/dimensions.
4. `evidenceIdsByRef` должен быть построен persistence/replay boundary до mapper-вызова и включать refs всех элементов, scale candidates и review issues.

## Проверки

- Fixtures: Vision, Vector/CAD и sketch без масштаба.
- Exact provenance, mapper → assembler → calculator, fail-closed scale и runtime spy integration.
- Pipeline schema/invalidation, PHP syntax и PHPStan для BuildingModel/Vision/Quantities/Pipeline boundaries.

## Handoff: normative decision → resources

Production bridge добавлен без benchmark-only преобразования:

`ResourceAssemblyService::assembleFromDecision(array $workItem, AcceptedNormativeDecisionData $decision, array $regionalContext): array`

`AcceptedNormativeDecisionData::fromWorkflowResult()` принимает выбранного кандидата реального
`NormativeMatchingWorkflow` и одну точную запись versioned каталога. Запись обязана совпадать по
`candidate_id`, `normative_id`, `dataset_id`, `dataset_version`, `dataset_status` и unit; ресурсы
обязаны быть непустыми и содержать положительный `price_id`. Цена в decision не подставляется:
`EstimatePricingService` разрешает её по точному regional price snapshot.

Обязательный regional context bridge: `dataset_id`, `dataset_version`, `region_id`,
`price_zone_id`, `period_id`, `price_version`; для pricing дополнительно передаётся
`estimate_regional_price_version_id`. Несовпадение dataset или неполный context закрывает поток.

Legacy accepted match теперь проходит через `AcceptedNormativeDecisionData::fromLegacyMatch()` и
тот же внутренний assembly core. Review/rejected/missing ветви остаются fail-closed в существующем
decision path.
