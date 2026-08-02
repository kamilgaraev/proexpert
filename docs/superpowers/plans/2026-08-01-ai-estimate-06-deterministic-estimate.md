# Предсказуемая итоговая AI-смета: план реализации

> **Для агентных исполнителей:** придерживаться TDD: сначала написать падающий тест, затем минимальную реализацию, после этого выполнить рефакторинг и релевантные проверки. В плане используются флажки `- [ ]`.

**Цель:** формировать готовую смету только из подтверждённой цифровой модели и давать источник каждой существенной величине.

**Архитектура:** ExtractQuantitiesStage получает confirmed projection, вычисляет объёмы по формуле, а последующие текущие стадии нормирования и цен получают прослеживаемые quantities.

**Технологии:** Laravel pipeline, существующие `ExtractQuantitiesStage`, `NormativeWorkItemPlannerService`, `FinalizedPackageDraftProjector`.

## Global Constraints

- Неподтверждённая величина никогда не попадает в итоговую смету.
- Изменение модели пересчитывает только зависимые объекты и не списывает новую квоту.

### Task 1: Перевести извлечение объёмов на подтверждённую модель

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/ExtractQuantitiesStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/ProjectModelQuantityResolver.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityCalculationTrace.php`
- Test: `tests/Unit/EstimateGeneration/Quantities/ProjectModelQuantityResolverTest.php`

- [ ] Написать тесты расчёта площади пола комнаты, площади стен с вычетом проёмов, площади двускатной кровли из плана и разреза.
- [ ] Требовать у quantity формулу, входные entity IDs, единицы, источник и статус подтверждения.
- [ ] Вернуть в состояние проверки конкретный список недостающих исходных величин вместо оценочного значения.

### Task 2: Обновить сметный pipeline и итоговый контракт

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PlanWorkItemsStage.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/BuildDraftStage.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/GeneratedEstimateItemMetadataFactory.php`
- Test: `tests/Feature/EstimateGeneration/ConfirmedModelEstimateGenerationTest.php`

- [ ] Передавать trace quantity в план работ, подбор норм и metadata строки сметы.
- [ ] Проверить, что существующие правила цен и прав доступа не меняются.
- [ ] Добавить API чтения основания строки сметы: источник, формула, пользовательская правка и связанные листы.
- [ ] Запустить feature-тест, минимальный набор unit-тестов и PHPStan затронутых файлов.

### Task 3: Добавить итоговый UX прослеживаемости

**Files:**
- Create: `prohelper_admin/src/features/estimate-generation/components/EstimateLineTraceDrawer.tsx`
- Modify: `prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`
- Test: `prohelper_admin/src/features/estimate-generation/components/EstimateLineTraceDrawer.test.tsx`

- [ ] Открывать источник строки сметы без ухода с текущего экрана.
- [ ] Показывать понятную формулу, исходные данные и ссылку на лист.
- [ ] Показывать «Требует проверки» вместо итоговой цены, когда не хватает обязательных данных.

