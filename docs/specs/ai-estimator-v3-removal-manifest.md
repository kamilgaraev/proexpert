# Removal manifest мультиагентного AI-сметчика МОСТ v3

## Назначение

Документ фиксирует результаты Task 1 на чистом `origin/main` (`6f2ff2f96`) и определяет единственный допустимый cutover. Он не разрешает параллельный legacy-контур, compatibility shim или fallback. Удаление runtime-кода выполняется только после перевода подтверждённых production readers на канонические `Evidence`, `ProjectModelRepository`, `Decision/proposal`, role runs и read-only API оснований расчёта.

## Baseline

- Backend worktree: `feat/ai-estimator-v3-multi-agent`, чистый `origin/main` `6f2ff2f96`.
- Admin worktree: `feat/ai-estimator-v3-workspace`, чистый `origin/main` `121679c6`.
- Backend architecture baseline: 7 тестов, 9 437 assertions, успешно.
- Admin `vitest list src/features/estimate-generation`: успешно, 379 тестов обнаружено.
- Admin `tsc --noEmit`: существующая ошибка вне scope в `src/pages/MachineryOperations/MachineryOperationsPage.tsx:129`; worktree до изменений был чистым.
- Остановленный worktree `fix-vision-semantic-upgrade-backfill` прочитан только через `git diff`; его файлы и git-состояние не изменялись.

## Решение по остановленному diff

### KEEP — переносить идеи вручную в новые канонические границы

- Safe provider error capture из `TimewebVisionProvider`: типизированные provider/contract failures остаются техническими и не попадают в пользовательский текст.
- Существующая physical-attempt state machine, breaker, retry lineage и AI usage/cost journal.
- Проверка immutable accepted render: `source_version`, derivative hash/bytes/storage key, accepted output version и bounded S3 read.
- Fixtures страниц 3–4 и 18–19: ведомость листов, общие данные, условный фундамент, размеры/площади, фасадные материалы, проёмы и архитектурные наблюдения.
- Идея безопасной русской presentation: machine code + translation params + bounded locator, без provider text.

### REIMPLEMENT — только внутри v3

- Semantic version identity переносится в immutable `AiRoleRunInput`/input fingerprint, а не в `processing_mode=semantic_enrichment`.
- Provider-free reuse принятого наблюдения выполняется через completed role run и exact source/render fingerprint; успешная роль не вызывается повторно.
- Предметные вопросы проектируются после арбитража и synthesis: причина, влияние, рекомендация, варианты, `other`, `leave_unresolved`, source locator.
- Расширенные профессиональные типы не образуют allowlist смысла. Сервер валидирует shape, bounds, scope и evidence; арбитр решает семантическую приемлемость.

### DELETE / DO NOT PORT

- `SemanticEnrichmentContract` и ветка `processingMode=semantic_enrichment`.
- `SheetAnalysisOperationIdentity::semanticEnrichment()` и reason `semantic_contract_upgrade`.
- Повторный targeted-вызов как authoritative semantic projection.
- `SemanticReviewPresentation` с generic `sheet_parameter_required`/readiness и узким словарём наблюдений.
- Pre-arbitration отбрасывание неизвестных профессиональных фактов.
- Любые `needs_review`/provider limitation strings как пользовательский вопрос.
- Любой hidden fallback на старый primary/targeted pipeline.

## Production cutover manifest

### Targeted semantic path

| Класс/контур | Решение | Текущие readers/routes/tests | Целевой путь |
|---|---|---|---|
| `Vision/TargetedSheetRecheckPlanner`, `TargetedSheetRecheckPlan`, `TargetedSheetRecheckScope` | delete after cutover | `ProductionDocumentUnitProcessor`; unit tests planner/scope/provider | Три независимых observer role runs и арбитр |
| targeted branch в `ProductionDocumentUnitProcessor` | replace | document processing; `ProductionDocumentUnitProcessorTest` | Dispatch независимых observers без доступа к соседним outputs |
| `Application/Documents/Understanding/SheetAnalysisOperationJournal` и `Models/EstimateGenerationSheetAnalysisOperation` | delete after cutover | processor, retry/outer-lease tests, DI/model | `AiRoleRunRepository` поверх общей physical-attempt boundary |
| таблица `estimate_generation_sheet_analysis_operations` | delete after cutover | model и contract/PostgreSQL tests | Forward-only cleanup после нулевых production readers/writers |
| `ProjectSheetAnalysisValidator` | keep internal, narrow responsibility | provider/processor tests | Только JSON shape, bounds, locator/scope integrity; confirmed разрешает арбитр |
| `TimewebVisionProvider`, physical attempts, breaker, usage journal | keep internal | основной Vision integration и tests | Общий provider boundary для всех AI-ролей, одна pinned model |

### Manual geometry contour

| Классы | Решение | Текущие readers/routes/tests | Целевой путь |
|---|---|---|---|
| `ConfirmBuildingGeometry`, `GeometryConfirmationCommand`, `GeometryReviewedSource`, `GeometrySourceConfirmationFactory` | delete after cutover | `EstimateGenerationGeometryController`; geometry API/PostgreSQL tests | Внутренний `RunGeometryExpert`; оператор отвечает только на предметные вопросы |
| `GeometryConfirmationFaultInjector`, `NoopGeometryConfirmationFaultInjector` | delete after cutover | service-provider binding; geometry transaction tests | Role-run/ProjectModel atomic persistence tests |
| `GeometryRegenerationIntent`, `GeometryRegenerationIntentStore`, `EloquentGeometryRegenerationIntentStore` | delete after cutover | service-provider binding; geometry PostgreSQL tests | Role dependency invalidation по immutable fingerprints |
| таблицы `estimate_generation_geometry_confirmations`, `estimate_generation_geometry_regeneration_outbox` | delete after cutover | классы выше и geometry PostgreSQL tests | Forward-only cleanup после route/binding/no-reference gates |
| `AssemblePersistedVectorGeometry`, `PersistedVectorGeometryResult` | keep internal | geometry assembly/persistence paths and tests | Входные операнды геометра и evidence lineage |
| `BuildingGeometryMutator`, `GeometryDependencyInvalidator` | keep internal | project-model corrections/derived quantity invalidation | Только детерминированная мутация через Decision/proposal boundary |

### Legacy BuildingModel authoritative path

| Классы | Решение | Текущие readers/routes/tests | Целевой путь |
|---|---|---|---|
| `BuildingModelRepository`, `BuildingModelStore`, `EloquentBuildingModelStore`, `InMemoryBuildingModelStore`, `StoredBuildingModel` | delete after cutover | service-provider store binding; building-model unit/PostgreSQL tests | Единственный `Domain/ProjectModel/ProjectModelRepository` |
| `SessionBuildingModelBridge`, `EloquentSessionBuildingModelBridge` | delete after cutover | geometry, decision and derived-quantity readers; bridge tests | Readers переводятся на atomic current/history ProjectModel projection |
| `Models/EstimateGenerationBuildingModel` | delete after cutover | legacy stores/presenters/tests | Канонические `estimate_generation_project_model_*` модели |
| `BuildingModelAssembler`, `GeometryBuildingModelInputMapper`, `GenerationBuildingModelRefreshPolicy` | replace | current document/geometry workflow and tests | `RunProjectSynthesis` из arbitration + geometry snapshots |
| `ConfirmedProjectModelProjection`, `ConfirmedProjectModelProjector`, `DocumentFloorIdentityResolver`, `DocumentTotalAreaConstraintResolver`, `RoomAreaAnnotationParser` | replace | assembler/synthesis readers and unit tests | Полезные правила переносятся в synthesis validators/fixtures без второго store |
| DTO `BuildingModelSchema`, `NormalizedBuildingModelData`, `VisionBuildingModel*`, `GeometryConfirmationData`, `FloorData`, `RoomData`, `WallData`, `OpeningData`, `EngineeringElementData`, `AssumptionData`, `VisionClarificationData` | delete after cutover | legacy assembler/store/API/tests | Bounded v3 role DTO и canonical ProjectModel value objects |
| `ApplyProjectModelCorrection` | keep internal | project-model correction controller/tests | До Task 14; затем используется только из единой Decision/proposal boundary |
| `ProjectModelAssertion*`, `ProjectModelCandidate*`, `ProjectModelConflict*`, `ProjectModelEntity*`, `ProjectModelEvidence*`, `ProjectModelMerger`, `ProjectModelRelation`, `ProjectModelResolvedValue*`, `ProjectModelTypedList`, fingerprint/value classes | keep internal | canonical model merge, evidence, corrections and tests | Переиспользуются внутри `ProjectModelRepository`/synthesis, namespace не создаёт второй store |
| таблицы `estimate_generation_building_models`, `estimate_generation_building_model_evidence` | delete after cutover | legacy bridge/store, geometry/decision/presentation readers and PostgreSQL tests | Forward-only cleanup после перевода всех readers |

### HTTP/API и presentation

| Класс/route | Решение | Текущие readers/tests | Целевой путь |
|---|---|---|---|
| `EstimateGenerationGeometryController`; GET `/{session}/geometry`; POST `/{session}/geometry/confirm` | delete after cutover | admin geometry API/client/step; backend geometry API tests | Read-only `analysis-basis` endpoint |
| `EstimateGenerationBuildingModelController`; GET `/{session}/building-model` | delete after cutover | admin building-model step; backend API tests | Read-only `analysis-basis` endpoint |
| legacy GET `/{session}/evidence/{evidence}` | replace | building-model controller/API tests | Tenant/ABAC-scoped bounded source/basis lookup |
| `GeometryReviewDataSource`, `EloquentGeometryReviewDataSource`, `GeometryReviewPayloadReader`, `GeometryReviewPayloadService`, `GeometryReviewSourcePresenter` | delete after cutover | controller, service-provider bindings, geometry API tests | `AnalysisBasisPayloadService` |
| `BuildingModelReadDataSource`, `EloquentBuildingModelReadDataSource`, `BuildingModelPayloadService` | delete after cutover | controller, service-provider bindings, building-model API tests | `AnalysisBasisPayloadService` + canonical ProjectModelRepository |
| `EstimateGenerationProjectModelReviewController`, `ProjectModelReviewPayloadService` и review presenters | replace | project-model review route/tests | Вопросы AI и основания расчёта; обязательная model review стадия отсутствует |
| `EstimateGenerationProjectModelCorrectionController` и correction presenters | replace | correction routes/tests | Единая Decision/proposal mutation boundary Task 14 |

### Admin

| Контур | Решение | Текущие readers/tests | Целевой путь |
|---|---|---|---|
| `steps/GeometryReviewStep.tsx` и tests | delete after cutover | `EstimateGenerationWorkspacePage` | Необязательный `AnalysisBasisDrawer` |
| `steps/BuildingModelStep.tsx` и tests | delete after cutover | `EstimateGenerationWorkspacePage` | Необязательный `AnalysisBasisDrawer` |
| geometry/building-model API methods, normalizers, fixtures и MSW handlers | replace/delete after cutover | API tests и два шага выше | Typed basis/question contracts |
| старые geometry/model step keys, readiness links и progress labels | delete after cutover | `model/steps.ts`, stepper/workspace tests | Пять стадий: объект, документы, вопросы AI, черновик, проверка и выпуск |

## Обязательные no-reference gates перед cleanup migration

1. Routes `geometry.confirm`, `building-model.show` и legacy project-model review/correction mutations отсутствуют.
2. Service-provider bindings legacy store/bridge/geometry confirmation/outbox отсутствуют.
3. Production search не находит readers/writers пяти cleanup-таблиц, кроме forward-only migration и её isolated PostgreSQL contract test.
4. `ProjectModelRepository` остаётся единственной authoritative model boundary; `Evidence`, Decisions/proposals, usage, physical attempts, packages/draft и pricing не удаляются.
5. Admin не импортирует удалённые steps/normalizers и не предлагает ручное подтверждение геометрии/модели.
6. Historical migrations не изменены; cleanup migration fail-fast проверяет ожидаемые definitions перед drop.
