# AI-сметчик МОСТ: AI, чертежи и качество Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать доказуемое понимание PDF, DWG/DXF, изображений, фотографий и ручных набросков, извлечение объемов, безопасный нормативный подбор, версионированные цены и измеримый quality/learning pipeline.

**Architecture:** Все графические источники преобразуются в `NormalizedBuildingModel` с evidence и confidence. Deterministic quantity engine формирует объемы, normative retrieval создает ограниченный candidate set, LLM только rerank-ит его, pricing сохраняет snapshot. Golden datasets и benchmark runner блокируют регрессию до попадания результата в пользовательский workflow.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL JSONB, S3, Python 3 runtime, PDF vector extraction, DWG/DXF conversion adapter, Timeweb-compatible vision API, PHPUnit, benchmark fixtures, Larastan.

## Global Constraints

- Plan 1 и Plan 2 полностью выполнены.
- Перед выбором/изменением Python, CAD, PDF, vision SDK или API обязательно использовать Context7 и зафиксировать точную версию зависимости.
- Нельзя использовать постоянный rule-based или placeholder fallback для неподдержанного файла; вернуть диагностируемую ошибку или review requirement.
- AI не создает нормативы, цены и финансовые суммы.
- Геометрический объем без масштаба или подтвержденного контрольного размера имеет тип `estimated` и не может автоматически пройти blocking quality gate.
- Каждый элемент модели, объем, норматив и цена имеют evidence/version.
- Acceptance dataset не используется для настройки prompt, правил или порогов.
- Не запускать migrations и benchmark против production данных.
- Не изменять обычные сметы; применять результат только через use case Plan 1.
- `cad_placeholder_v1`, `RuleBasedDrawingAnalysisProvider` и постоянный `RuleBasedNormativeCandidateReranker` должны быть удалены до закрытия плана.

---

## Структура файлов

```text
app/BusinessModules/Addons/EstimateGeneration/Benchmark/
  BenchmarkDatasetType.php
  BenchmarkCaseData.php
  BenchmarkRunner.php
  BenchmarkReportData.php
  Metrics/...

app/BusinessModules/Addons/EstimateGeneration/Vision/
  Contracts/VisionProvider.php
  Contracts/CadGeometryProvider.php
  DTO/...
  Preprocessing/...
  Providers/TimewebVisionProvider.php
  Geometry/...
  Sketch/...

app/BusinessModules/Addons/EstimateGeneration/BuildingModel/
  DTO/NormalizedBuildingModelData.php
  DTO/FloorData.php
  DTO/RoomData.php
  DTO/WallData.php
  DTO/OpeningData.php
  DTO/EngineeringElementData.php
  BuildingModelAssembler.php
  BuildingModelRepository.php

app/BusinessModules/Addons/EstimateGeneration/Quantities/
  QuantitySource.php
  QuantityData.php
  BuildingQuantityCalculator.php
```

### Task 1: Создать dataset manifest и benchmark runner до AI-изменений

**Files:**
- Create: `tests/Fixtures/EstimateGeneration/benchmarks/manifest.json`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkDatasetType.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkCaseData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkReportData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkRunner.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/Metrics/MetricCalculator.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEstimateGenerationBenchmarkCommand.php`
- Create: `tests/Unit/EstimateGeneration/Benchmark/BenchmarkManifestTest.php`
- Create: `tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php`

**Interfaces:**
- Consumes: versioned fixture manifest and a callable pipeline implementation.
- Produces: JSON report with exact metric names used by CI and Filament.

- [ ] **Step 1: Написать manifest validation test**

```php
#[Test]
public function manifest_contains_all_required_graphical_cases_without_path_overlap(): void
{
    $manifest = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/EstimateGeneration/benchmarks/manifest.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    self::assertSame(1, $manifest['schema_version']);
    self::assertContains('vector_pdf', array_column($manifest['cases'], 'source_type'));
    self::assertContains('scanned_pdf', array_column($manifest['cases'], 'source_type'));
    self::assertContains('photo_plan', array_column($manifest['cases'], 'source_type'));
    self::assertContains('dimensioned_sketch', array_column($manifest['cases'], 'source_type'));
    self::assertContains('undimensioned_sketch', array_column($manifest['cases'], 'source_type'));
    self::assertContains('dwg', array_column($manifest['cases'], 'source_type'));
    self::assertContains('dxf', array_column($manifest['cases'], 'source_type'));

    $pathsByDataset = collect($manifest['cases'])->groupBy('dataset')->map->pluck('input_path')->map->all();
    self::assertSame([], array_values(array_intersect(
        $pathsByDataset['development'],
        $pathsByDataset['acceptance'],
    )));
}
```

- [ ] **Step 2: Создать manifest с реальными repository fixture paths**

Каждый case:

```json
{
  "id": "house-sketch-001",
  "dataset": "development",
  "source_type": "dimensioned_sketch",
  "input_path": "tests/Fixtures/EstimateGeneration/benchmarks/development/house-sketch-001/input.png",
  "expected_path": "tests/Fixtures/EstimateGeneration/benchmarks/development/house-sketch-001/expected.json",
  "tags": ["residential", "single_floor", "dimensions"]
}
```

Не добавлять клиентские документы в Git. Использовать обезличенные synthetic/licensed fixtures; закрытый acceptance corpus хранить в organization-scoped S3 и подключать через manifest URI в согласованном окружении.

- [ ] **Step 3: Запустить manifest test**

Run: `php artisan test tests/Unit/EstimateGeneration/Benchmark/BenchmarkManifestTest.php`

Expected: FAIL до создания всех обязательных fixture descriptors, затем PASS.

- [ ] **Step 4: Реализовать report contract**

```php
final readonly class BenchmarkReportData
{
    public function __construct(
        public string $runId,
        public BenchmarkDatasetType $dataset,
        public string $pipelineVersion,
        public array $modelVersions,
        public int $caseCount,
        public float $sheetClassificationAccuracy,
        public float $roomIou,
        public float $wallIou,
        public float $openingF1,
        public float $areaMape,
        public float $quantityMape,
        public float $workRecall,
        public float $normativeTop1,
        public float $normativeTop3,
        public float $costMape,
        public float $technicalSuccessRate,
        public float $evidencedApplicableItems,
        public int $durationMs,
        public string $costAmount,
        public string $currency,
    ) {}
}
```

- [ ] **Step 5: Реализовать command**

Signature:

```php
protected $signature = 'estimate-generation:benchmark
    {--dataset=regression : development|regression|acceptance}
    {--format=json : json|table}
    {--output= : Optional output path}';
```

Command запрещает `acceptance`, если `APP_ENV=production`, и не читает production sessions.

- [ ] **Step 6: Запустить command test и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Benchmark app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEstimateGenerationBenchmarkCommand.php tests/Fixtures/EstimateGeneration/benchmarks tests/Unit/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Benchmark
git commit -m "test[lk]: добавлен benchmark AI-сметчика"
```

Expected: PASS; empty fixture pipeline produces deterministic zero/baseline report, not exception.

### Task 2: Ввести нормализованную модель здания

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001000_create_estimate_generation_building_models_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationBuildingModel.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/NormalizedBuildingModelData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/FloorData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/RoomData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/WallData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/OpeningData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/DTO/EngineeringElementData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/BuildingModelAssembler.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/BuildingModelRepository.php`
- Create: `tests/Unit/EstimateGeneration/BuildingModel/NormalizedBuildingModelDataTest.php`

**Interfaces:**
- Consumes: vector/raster detections from later providers.
- Produces: one versioned building model per `(session_id, input_version)`.

- [ ] **Step 1: Написать DTO round-trip test**

```php
#[Test]
public function model_preserves_metric_geometry_and_evidence_ids(): void
{
    $model = new NormalizedBuildingModelData(
        unit: 'm',
        scaleStatus: 'confirmed',
        scaleMetersPerUnit: 0.01,
        floors: [new FloorData(
            key: 'floor-1',
            elevationM: 0.0,
            heightM: 2.8,
            rooms: [new RoomData(
                key: 'room-1',
                name: 'Кухня',
                polygon: [[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]],
                evidenceIds: [101],
                confidence: 0.94,
            )],
            walls: [],
            openings: [],
            engineeringElements: [],
        )],
        assumptions: [],
        modelVersion: 'building-model:v1',
    );

    self::assertSame($model->toArray(), NormalizedBuildingModelData::fromArray($model->toArray())->toArray());
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/BuildingModel/NormalizedBuildingModelDataTest.php`

Expected: FAIL.

- [ ] **Step 3: Реализовать readonly DTOs**

Все coordinates хранятся в метрах после scale confirmation; исходные pixel/vector coordinates остаются в evidence locator. `toArray/fromArray` не допускают отсутствующих keys и выбрасывают `InvalidArgumentException`.

- [ ] **Step 4: Создать schema и repository**

Таблица: session_id, input_version, model_version, scale_status, scale_meters_per_unit, model JSONB, assumptions JSONB, metrics JSONB, confirmed_by/at, timestamps. Unique `(session_id, input_version)`.

- [ ] **Step 5: Запустить test и commit**

```bash
php -l app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001000_create_estimate_generation_building_models_table.php
php artisan test tests/Unit/EstimateGeneration/BuildingModel
git add app/BusinessModules/Addons/EstimateGeneration/BuildingModel app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationBuildingModel.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001000_create_estimate_generation_building_models_table.php tests/Unit/EstimateGeneration/BuildingModel
git commit -m "feat[lk]: добавлена модель здания AI-сметчика"
```

Expected: PASS.

### Task 3: Реализовать raster preprocessing и vision provider contract

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Contracts/VisionProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VisionDocumentInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VisionAnalysisData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Preprocessing/RasterPreprocessor.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php`
- Modify: `config/estimate-generation.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Create: `tests/Unit/EstimateGeneration/Vision/RasterPreprocessorTest.php`
- Create: `tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php`

**Interfaces:**
- Consumes: S3 image/page, content type, correlation context.
- Produces: structured detections with source-space polygons, labels, confidence, scale candidates and usage metadata.

- [ ] **Step 1: Использовать Context7 перед выбором raster library/API syntax**

Зафиксировать в commit description/library note точную библиотеку, version и API для orientation, perspective correction и image normalization. Не добавлять dependency по памяти.

- [ ] **Step 2: Написать provider schema test**

```php
#[Test]
public function provider_rejects_unstructured_or_out_of_bounds_geometry(): void
{
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode([
                'sheet_type' => 'floor_plan',
                'elements' => [[
                    'type' => 'room',
                    'polygon' => [[-10, 0], [100, 0], [100, 100]],
                    'confidence' => 1.2,
                ]],
            ], JSON_THROW_ON_ERROR)],
        ]],
    ])]);

    $this->expectException(VisionContractException::class);
    app(TimewebVisionProvider::class)->analyze($this->input());
}
```

- [ ] **Step 3: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Vision`

Expected: FAIL.

- [ ] **Step 4: Реализовать provider interface**

```php
interface VisionProvider
{
    public function analyze(VisionDocumentInput $input): VisionAnalysisData;
}
```

`VisionAnalysisData` содержит sheetType, normalized `[0..1]` polygons, scaleCandidates, elements, warnings, provider, model, modelVersion и usage. JSON schema запрещает дополнительные поля и confidence вне `[0,1]`.

- [ ] **Step 5: Реализовать raster preprocessor**

Pipeline: EXIF orientation -> grayscale/contrast normalization -> perspective correction -> max-dimension resize с сохранением transform matrix -> quality metrics. Сохранять derivative через `FileService` в session path; не писать локально.

- [ ] **Step 6: Интегрировать usage recorder Plan 2**

Каждая attempt записывает image_count/detail, tokens, duration, result и cost. Provider не содержит silent model fallback: configured model failure возвращает recoverable/terminal failure согласно классификатору.

- [ ] **Step 7: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Vision/RasterPreprocessorTest.php tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Vision config/estimate-generation.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php tests/Unit/EstimateGeneration/Vision
git commit -m "feat[lk]: добавлен vision-анализ планов и изображений"
```

Expected: PASS.

### Task 4: Реализовать vector PDF и CAD provider без placeholder

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Contracts/CadGeometryProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VectorGeometryData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/PdfVectorGeometryProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/CadConversionRuntime.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/DwgDxfGeometryProvider.php`
- Replace: `app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py`
- Create: `app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py`
- Create: `tests/Unit/EstimateGeneration/Vision/PdfVectorGeometryProviderTest.php`
- Create: `tests/Unit/EstimateGeneration/Vision/DwgDxfGeometryProviderTest.php`
- Create: `tests/Unit/EstimateGeneration/Vision/CadRuntimeContractTest.php`

**Interfaces:**
- Consumes: PDF/DWG/DXF S3 object copied to ephemeral worker input.
- Produces: layers, blocks, lines, arcs, polylines, texts, dimensions, units, scale candidates and source handles.

- [ ] **Step 1: Использовать Context7 для выбранного CAD/PDF runtime**

Проверить license, headless CLI, DWG/DXF support, Windows/Linux deployment и exact version. Если выбранный runtime не умеет DWG, Plan не может закрыться под видом DXF-only поддержки.

- [ ] **Step 2: Написать CLI contract test**

```php
#[Test]
public function cad_runtime_returns_versioned_json_contract(): void
{
    $result = app(CadConversionRuntime::class)->extract(
        fixture_path('EstimateGeneration/benchmarks/development/simple-house/input.dxf')
    );

    self::assertSame(1, $result->schemaVersion);
    self::assertSame('mm', $result->sourceUnit);
    self::assertNotEmpty($result->layers);
    self::assertNotEmpty($result->entities);
    self::assertNotEmpty($result->sourceFingerprint);
}
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Vision/CadRuntimeContractTest.php`

Expected: FAIL.

- [ ] **Step 4: Определить JSON stdout contract Python scripts**

```json
{
  "schema_version": 1,
  "runtime_version": "cad-geometry:v1",
  "source_fingerprint": "sha256:...",
  "source_unit": "mm",
  "bounds": [0, 0, 12000, 9000],
  "layers": [{"name": "WALLS", "visible": true}],
  "entities": [{"handle": "A1", "type": "polyline", "layer": "WALLS", "points": [[0,0],[1000,0]]}],
  "texts": [],
  "dimensions": [],
  "warnings": []
}
```

Ошибки возвращаются через non-zero exit и JSON stderr `{code, safe_message, retryable}`. Скрипт не пишет output files вне переданного ephemeral directory.

- [ ] **Step 5: Реализовать providers и resource limits**

`CadConversionRuntime` использует Symfony Process с timeout, memory/file limits из config, проверяет signature/extension и JSON schema. Неподдержанный или поврежденный DWG получает failure code, а не пустую геометрию.

- [ ] **Step 6: Запустить fixture tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Vision/PdfVectorGeometryProviderTest.php tests/Unit/EstimateGeneration/Vision/DwgDxfGeometryProviderTest.php tests/Unit/EstimateGeneration/Vision/CadRuntimeContractTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Vision/Contracts/CadGeometryProvider.php app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VectorGeometryData.php app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry app/BusinessModules/Addons/EstimateGeneration/bin tests/Unit/EstimateGeneration/Vision
git commit -m "feat[lk]: добавлен разбор PDF и CAD-геометрии"
```

Expected: PASS на PDF, DWG и DXF fixtures.

### Task 5: Собрать building model и сценарий ручного наброска

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/GeometryFusionService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/ScaleResolver.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Sketch/SketchAssumption.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Sketch/SketchClarificationData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/Sketch/SketchClarificationService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/BuildingModelAssembler.php`
- Create: `tests/Unit/EstimateGeneration/Vision/ScaleResolverTest.php`
- Create: `tests/Unit/EstimateGeneration/Vision/SketchClarificationServiceTest.php`
- Create: `tests/Unit/EstimateGeneration/BuildingModel/BuildingModelAssemblerTest.php`

**Interfaces:**
- Consumes: vector geometry, vision detections and user-confirmed parameters.
- Produces: normalized metric building model or explicit clarification requirements.

- [ ] **Step 1: Написать scale safety tests**

```php
#[Test]
public function sketch_without_dimension_never_gets_confirmed_scale(): void
{
    $result = app(ScaleResolver::class)->resolve(
        vectorDimensions: [],
        visionDimensions: [],
        userControlDimension: null,
    );

    self::assertSame('missing', $result->status);
    self::assertNull($result->metersPerUnit);
}

#[Test]
public function user_control_dimension_confirms_scale_with_evidence(): void
{
    $result = app(ScaleResolver::class)->resolve([], [], new ControlDimensionData(
        pixelStart: [100, 100],
        pixelEnd: [600, 100],
        meters: 10.0,
        confirmedBy: 7,
    ));

    self::assertSame('confirmed', $result->status);
    self::assertSame(0.02, $result->metersPerUnit);
    self::assertNotNull($result->evidence);
}
```

- [ ] **Step 2: Написать sketch questions test**

```php
self::assertSame([
    'footprint_or_area',
    'floor_count',
    'floor_height',
    'wall_material',
    'foundation_type',
    'roof_type',
    'finish_level',
    'region',
], array_column(app(SketchClarificationService::class)->missingQuestions($input), 'key'));
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Vision/ScaleResolverTest.php tests/Unit/EstimateGeneration/Vision/SketchClarificationServiceTest.php`

Expected: FAIL.

- [ ] **Step 4: Реализовать fusion rules**

Приоритет source: confirmed vector dimension > user control dimension > consistent multi-source vision dimension > missing. Конфликт более 2% между подтвержденными размерами создает blocking issue `geometry_scale_conflict`.

- [ ] **Step 5: Реализовать sketch assumptions**

Каждое допущение имеет key, value, source=`user|catalog_default`, confidence, evidence ID и `requires_confirmation`. Catalog default никогда не становится `evidenced`.

- [ ] **Step 6: Собрать model и запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/BuildingModel`

Expected: PASS; model без scale содержит только normalized source geometry и assumptions, но не metric quantities.

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry app/BusinessModules/Addons/EstimateGeneration/Vision/Sketch app/BusinessModules/Addons/EstimateGeneration/BuildingModel tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/BuildingModel
git commit -m "feat[lk]: реализовано понимание планов и набросков"
```

### Task 6: Добавить API подтверждения геометрии

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Http/Requests/ConfirmEstimateGenerationGeometryRequest.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Geometry/ConfirmBuildingGeometry.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Geometry/GeometryConfirmationCommand.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationGeometryController.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/routes.php`
- Create: `tests/Feature/EstimateGeneration/Geometry/EstimateGenerationGeometryApiTest.php`

**Interfaces:**
- Consumes: `state_version`, control dimension and JSON Patch-like element operations.
- Produces: new input/model version, user-confirmed evidence and invalidation of dependent outputs.

- [ ] **Step 1: Написать stale and confirmation tests**

```php
#[Test]
public function confirmation_requires_current_state_version_and_invalidates_quantities(): void
{
    $response = $this->actingAsAdmin()->postJson($this->url(), [
        'state_version' => $this->session->state_version,
        'scale' => [
            'pixel_start' => [100, 100],
            'pixel_end' => [600, 100],
            'meters' => 10,
        ],
        'operations' => [
            ['op' => 'replace', 'path' => '/floors/0/rooms/0/name', 'value' => 'Кухня'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('data.building_model.scale_status', 'confirmed');
    self::assertNotNull($this->quantityEvidence->refresh()->invalidated_at);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Feature/EstimateGeneration/Geometry/EstimateGenerationGeometryApiTest.php`

Expected: FAIL.

- [ ] **Step 3: Реализовать validation**

Разрешенные operations только для известных typed paths: room name/polygon, wall geometry/type/material, opening geometry/type, floor height, scale. Запретить arbitrary JSON path и foreign element IDs.

- [ ] **Step 4: Реализовать command**

В transaction: lock session/model, verify state version/organization/project, apply typed operations, record user evidence, increment model/input version, invalidate dependent evidence/checkpoints, return updated snapshot.

- [ ] **Step 5: Запустить test и commit**

```bash
php artisan test tests/Feature/EstimateGeneration/Geometry/EstimateGenerationGeometryApiTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Application/Geometry app/BusinessModules/Addons/EstimateGeneration/Http/Requests/ConfirmEstimateGenerationGeometryRequest.php app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationGeometryController.php app/BusinessModules/Addons/EstimateGeneration/routes.php tests/Feature/EstimateGeneration/Geometry
git commit -m "feat[lk]: добавлена проверка геометрии AI-сметы"
```

Expected: PASS.

### Task 7: Реализовать deterministic quantity engine

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantitySource.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/BuildingQuantityCalculator.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityFormulaCatalog.php`
- Replace internals: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DrawingGeometryAnalyzer.php`
- Create: `tests/Unit/EstimateGeneration/Quantities/BuildingQuantityCalculatorTest.php`
- Create: `tests/Unit/EstimateGeneration/Quantities/QuantityEvidenceTest.php`

**Interfaces:**
- Consumes: confirmed/estimated normalized building model.
- Produces: quantities for floors, ceilings, walls, openings, foundation, roof and engineering elements with formula/evidence.

- [ ] **Step 1: Написать exact geometry test**

```php
#[Test]
public function rectangular_room_quantities_are_deterministic(): void
{
    $result = app(BuildingQuantityCalculator::class)->calculate(
        $this->rectangularRoom(width: 4, length: 3, height: 2.8, doorArea: 1.8, windowArea: 2.4)
    );

    self::assertSame('12.000000', $result->get('floor_area')->amount);
    self::assertSame('12.000000', $result->get('ceiling_area')->amount);
    self::assertSame('37.800000', $result->get('net_wall_area')->amount);
    self::assertSame('evidenced', $result->get('net_wall_area')->source->value);
    self::assertNotEmpty($result->get('net_wall_area')->evidenceIds);
}
```

- [ ] **Step 2: Написать missing-scale test**

```php
self::assertSame(
    'estimated',
    app(BuildingQuantityCalculator::class)
        ->calculate($this->unscaledSketchWithAssumptions())
        ->get('floor_area')
        ->source
        ->value,
);
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Quantities`

Expected: FAIL.

- [ ] **Step 4: Реализовать decimal formulas**

Использовать decimal arithmetic принятого в проекте расчетного слоя; не использовать float для persisted amount. Формула каждой quantity возвращает `formula_key`, inputs и evidence IDs. Округление только на границе отображения, не между формулами.

- [ ] **Step 5: Перевести drawing analyzer на engine**

Удалить эвристическое вычисление без building model. `DrawingGeometryAnalyzer` либо становится thin adapter к calculator, либо удаляется после переноса callers.

- [ ] **Step 6: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Quantities tests/Unit/EstimateGeneration/GeometryQuantityGuardTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Quantities app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DrawingGeometryAnalyzer.php tests/Unit/EstimateGeneration/Quantities tests/Unit/EstimateGeneration/GeometryQuantityGuardTest.php
git commit -m "feat[lk]: добавлен расчет объемов по геометрии"
```

Expected: PASS.

### Task 8: Перестроить normative retrieval и LLM reranking без fallback

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Normatives/DTO/NormativeCandidateSetData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeRetrievalService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeHardGate.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/NormativeCandidateRerankerInterface.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php`
- Delete after migration: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/RuleBasedNormativeCandidateReranker.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSearchService.php`
- Create: `tests/Unit/EstimateGeneration/Normatives/NormativeHardGateTest.php`
- Modify: `tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php`

**Interfaces:**
- Consumes: typed work intent, unit, material, technology, structure, section, object type and dataset versions.
- Produces: hard-gated candidates and optional LLM ordering; no invented candidate.

- [ ] **Step 1: Написать hard gate tests**

```php
#[Test]
public function incompatible_unit_candidate_is_removed_before_llm(): void
{
    $set = app(NormativeHardGate::class)->filter(
        workItem: $this->workItem(unit: 'м2', material: 'кирпич'),
        candidates: [
            $this->candidate(code: 'A', unit: 'м2', material: 'кирпич'),
            $this->candidate(code: 'B', unit: 'м3', material: 'бетон'),
        ],
    );

    self::assertSame(['A'], array_column($set->candidates, 'code'));
    self::assertSame(['B'], array_column($set->rejected, 'code'));
}
```

- [ ] **Step 2: Написать no-fallback test**

При provider timeout `LLMNormativeCandidateReranker` должен выбросить typed `NormativeRerankingUnavailable` с recoverable=true. Он не возвращает rule-based ordering как успешный AI-result.

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Normatives/NormativeHardGateTest.php tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php`

Expected: FAIL.

- [ ] **Step 4: Реализовать retrieval и hard gates**

Retrieval возвращает максимум configured N кандидатов с lexical/semantic scores и dataset version. Hard gates выполняются до внешнего вызова. Если после gates нет кандидатов, создается blocking review item `normative_not_found`.

- [ ] **Step 5: Реализовать strict reranker contract**

```php
interface NormativeCandidateRerankerInterface
{
    public function rerank(
        WorkIntentData $workItem,
        NormativeCandidateDecisionContextData $context,
        NormativeCandidateSetData $candidateSet,
    ): NormativeRerankResultData;
}
```

Response schema принимает только candidate IDs из входного set и explanation codes из allow-list. Unknown ID делает response invalid.

- [ ] **Step 6: Удалить rule-based reranker и запустить tests**

```bash
php artisan test tests/Unit/EstimateGeneration/Normatives
rg -n "RuleBasedNormativeCandidateReranker" app/BusinessModules/Addons/EstimateGeneration tests/Unit/EstimateGeneration
```

Expected: tests PASS; `rg` exit 1 после cleanup.

- [ ] **Step 7: Commit**

```bash
git add -A app/BusinessModules/Addons/EstimateGeneration/Normatives app/BusinessModules/Addons/EstimateGeneration/Services/Normatives tests/Unit/EstimateGeneration/Normatives
git commit -m "refactor[lk]: усилен подбор нормативов AI-сметчика"
```

### Task 9: Сделать versioned price snapshot обязательным

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pricing/PriceSnapshotData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pricing/ResolveRegionalPrice.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pricing/MissingRegionalPrice.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPackageItem.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001100_add_price_snapshots_to_estimate_generation_package_items.php`
- Create: `tests/Unit/EstimateGeneration/Pricing/ResolveRegionalPriceTest.php`
- Create: `tests/Feature/EstimateGeneration/Pricing/EstimateGenerationPriceSnapshotTest.php`

**Interfaces:**
- Consumes: normative resource, region, zone, price period and version.
- Produces: immutable `PriceSnapshotData` persisted with generated package item.

- [ ] **Step 1: Написать no-cross-region test**

```php
#[Test]
public function price_from_another_region_is_never_used(): void
{
    $this->seedPrice(regionId: 16, amount: '100.00');

    $this->expectException(MissingRegionalPrice::class);
    app(ResolveRegionalPrice::class)->handle($this->request(regionId: 77));
}
```

- [ ] **Step 2: Написать historical snapshot test**

После изменения справочника `package_item.price_snapshot` и total generated draft остаются прежними.

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing`

Expected: FAIL.

- [ ] **Step 4: Реализовать DTO**

```php
final readonly class PriceSnapshotData
{
    public function __construct(
        public int $regionId,
        public int $zoneId,
        public int $periodId,
        public int $versionId,
        public string $sourceType,
        public string $sourceReference,
        public string $baseAmount,
        public array $coefficients,
        public string $finalAmount,
        public string $currency,
        public string $capturedAt,
    ) {}
}
```

- [ ] **Step 5: Перевести pricing service и package persistence**

При отсутствии exact region/zone/period/version price создать blocking issue; не использовать latest/first/neighbor fallback. Смена regional context инвалидирует price evidence и downstream draft checkpoint.

- [ ] **Step 6: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing
git add app/BusinessModules/Addons/EstimateGeneration/Pricing app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPackageItem.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001100_add_price_snapshots_to_estimate_generation_package_items.php tests/Unit/EstimateGeneration/Pricing tests/Feature/EstimateGeneration/Pricing
git commit -m "feat[lk]: зафиксированы региональные цены AI-сметы"
```

Expected: PASS.

### Task 10: Версионировать learning datasets и benchmark runs

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001200_rebuild_estimate_generation_training_and_benchmarks.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationTrainingDataset.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationTrainingExample.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationBenchmarkRun.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/BenchmarkRunRepository.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Training/EstimateGenerationTrainingDatasetService.php`
- Create: `tests/Unit/EstimateGeneration/Training/TrainingDatasetTrustPolicyTest.php`
- Create: `tests/Feature/EstimateGeneration/Benchmark/BenchmarkPersistenceTest.php`

**Interfaces:**
- Consumes: approved examples and benchmark reports.
- Produces: versioned development/regression/acceptance datasets and immutable runs.

- [ ] **Step 1: Написать acceptance isolation test**

```php
#[Test]
public function acceptance_examples_cannot_be_used_for_training_or_prompt_selection(): void
{
    $dataset = $this->dataset(type: 'acceptance');

    self::assertFalse(app(TrainingDatasetTrustPolicy::class)->canTrain($dataset));
    self::assertFalse(app(TrainingDatasetTrustPolicy::class)->canTuneRules($dataset));
    self::assertTrue(app(TrainingDatasetTrustPolicy::class)->canBenchmark($dataset));
}
```

- [ ] **Step 2: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Training/TrainingDatasetTrustPolicyTest.php`

Expected: FAIL.

- [ ] **Step 3: Добавить dataset version/status/type**

Types: `development`, `regression`, `acceptance`. Statuses: `draft`, `processing`, `review_required`, `approved`, `rejected`, `archived`. Example попадает в approved dataset только после `reviewed_by` и `reviewed_at`.

- [ ] **Step 4: Создать benchmark run persistence**

Поля: run UUID, dataset/version, pipeline version, model versions JSONB, normative/price versions, metrics JSONB, case results JSONB/S3 reference, duration, cost, currency, status, started/completed timestamps.

- [ ] **Step 5: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Training tests/Feature/EstimateGeneration/Benchmark
git add app/BusinessModules/Addons/EstimateGeneration/Benchmark app/BusinessModules/Addons/EstimateGeneration/Models app/BusinessModules/Addons/EstimateGeneration/Services/Training app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001200_rebuild_estimate_generation_training_and_benchmarks.php tests/Unit/EstimateGeneration/Training tests/Feature/EstimateGeneration/Benchmark
git commit -m "feat[lk]: версионировано обучение AI-сметчика"
```

Expected: PASS.

### Task 11: Объединить quality gates и удалить недоказуемые реализации

**Files:**
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateGenerationQualityGateService.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimatorReadinessService.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/RuleBasedDrawingAnalysisProvider.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/RuleBasedNormativeCandidateReranker.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php`
- Create: `tests/Unit/EstimateGeneration/Quality/ProductionReadinessGateTest.php`
- Create: `tests/Architecture/EstimateGenerationNoPlaceholderTest.php`

**Interfaces:**
- Consumes: building model, evidence, quantities, normative decisions, price snapshots and benchmark metrics.
- Produces: one readiness result used by workflow and apply.

- [ ] **Step 1: Написать production gate test**

```php
#[Test]
public function draft_with_unconfirmed_scale_or_estimated_quantity_cannot_be_applied(): void
{
    $report = app(EstimatorReadinessService::class)->evaluate(
        $this->sessionWith(scaleStatus: 'missing', quantitySource: 'estimated')
    );

    self::assertFalse($report['can_apply']);
    self::assertContains('geometry_scale_unconfirmed', array_column($report['blocking_issues'], 'code'));
    self::assertContains('estimated_quantity_unconfirmed', array_column($report['blocking_issues'], 'code'));
}
```

- [ ] **Step 2: Написать placeholder architecture test**

```php
#[Test]
public function runtime_contains_no_placeholder_or_rule_based_drawing_provider(): void
{
    $source = $this->moduleSource();
    self::assertStringNotContainsString('cad_placeholder_v1', $source);
    self::assertStringNotContainsString('RuleBasedDrawingAnalysisProvider', $source);
    self::assertStringNotContainsString('RuleBasedNormativeCandidateReranker', $source);
}
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/ProductionReadinessGateTest.php tests/Architecture/EstimateGenerationNoPlaceholderTest.php`

Expected: FAIL.

- [ ] **Step 4: Переписать quality result**

Единый result содержит `can_generate`, `can_apply`, blocking issues, warnings, metrics и next action. Blocking codes включают scale conflict, missing evidence, estimated quantity, normative missing, unit mismatch, missing price snapshot, duplicate candidate и unresolved review.

- [ ] **Step 5: Удалить placeholder/rule-based runtime**

Перевести provider bindings на `VisionProvider` и `CadGeometryProvider`. Удалить ветку `model: 'cad_placeholder_v1'`; unsupported file создает typed failure/review issue.

- [ ] **Step 6: Запустить полный Plan 3 gate**

```bash
php artisan test tests/Unit/EstimateGeneration/Benchmark tests/Unit/EstimateGeneration/BuildingModel tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/Quantities tests/Unit/EstimateGeneration/Normatives tests/Unit/EstimateGeneration/Pricing tests/Unit/EstimateGeneration/Quality tests/Feature/EstimateGeneration/Geometry tests/Feature/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Pricing tests/Architecture/EstimateGenerationNoPlaceholderTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Benchmark app/BusinessModules/Addons/EstimateGeneration/BuildingModel app/BusinessModules/Addons/EstimateGeneration/Vision app/BusinessModules/Addons/EstimateGeneration/Quantities app/BusinessModules/Addons/EstimateGeneration/Normatives app/BusinessModules/Addons/EstimateGeneration/Pricing app/BusinessModules/Addons/EstimateGeneration/Services/Quality --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

- [ ] **Step 7: Запустить regression benchmark**

Run: `php artisan estimate-generation:benchmark --dataset=regression --format=json --output=storage/app/benchmarks/regression.json`

Expected: report создан; `work_recall >= 0.90`, `normative_top3 >= 0.95`, `evidenced_applicable_items = 1.00`, `technical_success_rate >= 0.98`. Если fixture corpus пока меньше целевого, Plan не закрывать: добавить утвержденные обезличенные fixtures, не снижать порог.

- [ ] **Step 8: Commit**

```bash
git add -A app/BusinessModules/Addons/EstimateGeneration tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration tests/Architecture/EstimateGenerationNoPlaceholderTest.php
git commit -m "refactor[lk]: завершено ядро качества AI-сметчика"
```
