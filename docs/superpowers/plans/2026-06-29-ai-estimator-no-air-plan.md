# AI-сметчик без воздуха Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** довести AI-генерацию смет до режима, где каждая строка сметы имеет проверяемое основание: документ/чертеж/спецификацию, объем, норму ФСНБ/ФСБЦ, цену/ресурс или явный ручной выбор сметчика.

**Architecture:** система должна стать evidence-first pipeline: файл классифицируется, страницы разбираются по типу, факты и объемы складываются в граф доказательств, затем строятся нормативные work-intents, подбираются нормы и цены, а неопределенные места попадают в мастер проверки. LLM не имеет права сам придумывать строки сметы; он может классифицировать, извлекать кандидаты, объяснять и ранжировать, но финальная строка должна ссылаться на источник и нормативную базу.

**Tech Stack:** Laravel 11, PostgreSQL/jsonb, очереди Laravel, S3/FileService, текущие таблицы `estimate_norms`, `estimate_norm_resources`, `estimate_resource_prices`, `estimate_regional_price_versions`, React/Vite admin UI, OCR/layout providers, Python/OpenCV worker for raster drawings, DWG conversion provider, ФГИС ЦС/ФСНБ/ФСБЦ data already present in product domain.

---

## Важные вводные

- Не планируем BIM/IFC на этом этапе. Пользовательский вход сейчас: PDF, DWG, изображения планировок, сканы, спецификации, ведомости, текстовая документация и промпт.
- “Идеально” в продукте означает не “всегда сгенерировали много строк”, а “не выпустили неподтвержденную строку как смету”.
- Нормы на production есть по утверждению владельца продукта. Из текущей среды 2026-06-29 read-only SSH не дошел до banner exchange: `Connection timed out during banner exchange`. Поэтому в плане есть обязательный read-only аудит production-норм перед кодовыми задачами, но без выдуманных цифр.
- В локальном дереве есть незакоммиченные правки, начатые до остановки исправлений: `NormativeWorkItemPlannerService.php`, `NormativeWorkItemPlannerDensityTest.php`, `WorkIntentClassifierTest.php`, `NormativeSearchProfileCatalogTest.php`. Перед реализацией этого плана надо решить: сохранить их в отдельный коммит/стэш или откатить.

## Источники и технологические опоры

- ФГИС ЦС содержит федеральный реестр сметных нормативов и раздел ФСНБ-2022: https://fgiscs.minstroyrf.ru/frsn и https://fgiscs.minstroyrf.ru/frsn/fsnb.
- ФСБЦ-2022 по материалам опубликован во ФГИС ЦС: https://fgiscs.minstroyrf.ru/frsn/standard/fsbc/materials.
- Autodesk Platform Services Model Derivative API переводит design files/DWG в форматы для просмотра и извлекает metadata/object hierarchy/properties/geometries: https://aps.autodesk.com/en/docs/model-derivative/v2 и https://aps.autodesk.com/developer/overview/model-derivative-api.
- Azure Document Intelligence Layout model извлекает OCR, tables, selection marks и структуру документа: https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/prebuilt/layout.
- Tesseract требует правильного preprocessing и page segmentation mode для разных типов областей: https://tesseract-ocr.github.io/tessdoc/ImproveQuality.html.
- OpenCV Hough transform подходит для поиска линий на бинаризованных изображениях чертежей: https://docs.opencv.org/master/javadoc/org/opencv/imgproc/Imgproc.html.
- PaddleOCR PP-Structure покрывает layout/table recognition pipeline: https://github.com/PaddlePaddle/PaddleOCR/blob/main/ppstructure/table/README.md и https://paddlepaddle.github.io/PaddleOCR/main/en/version3.x/algorithm/PP-StructureV3/PP-StructureV3.html.
- LayoutParser дает модельный подход к document layout analysis: https://layout-parser.github.io/.

---

## Целевые инварианты

1. Нельзя сохранять строку сметы со статусом “готово”, если нет `estimate_norm_id` или явного ручного выбора нормы.
2. Нельзя показывать цену `0` как рассчитанную цену. Если норма/ресурс/цена не найдены, строка получает статус `needs_review`, `missing_norm`, `missing_quantity` или `missing_price`.
3. Нельзя создавать позицию из generic fallback вроде “Комплекс строительных работ” без документа, объема и предметного intent.
4. Нельзя добивать `target_items_min` искусственными строками.
5. Каждая позиция хранит `evidence_refs`: файл, страница, bbox/координаты, фрагмент текста или геометрический объект.
6. Каждая позиция хранит `quantity_basis`: как получен объем, единица, confidence, источник.
7. Норма подбирается через candidate list, а не одним слепым match. Для ручного мастера всегда храним top-k кандидатов.
8. Действия пользователя в мастере становятся learning evidence: выбранная норма положительный пример, отклоненные кандидаты отрицательные.
9. LLM output валидируется схемой и не может добавить строку, если она не проходит invariant checks.
10. Для больших файлов и нескольких параллельных генераций backend не выполняет тяжелый разбор синхронно; все через jobs, idempotency, progress и backpressure.

---

## File Structure

### Backend: ingestion and orchestration

- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPipelineService.php`  
  Оркестрация стадий, переходы статусов, запрет синхронных тяжелых операций.
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DocumentIngestionPipeline.php`  
  Единая точка регистрации файлов, страниц, производных артефактов.
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DocumentTypeClassifier.php`  
  Классификация: drawing, floor_plan, specification, estimate_reference, scan, text_document, unknown.
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Documents/PageUnderstandingData.php`  
  Структурированный результат по странице.
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Evidence/EvidenceRefData.php`  
  Единый контракт ссылок на доказательства.

### Backend: OCR/layout/specifications

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/LayoutProviderInterface.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/YandexLayoutProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/AzureDocumentLayoutProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/TableExtractionNormalizer.php`
- Modify: existing OCR services under `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/`

### Backend: drawings and quantity takeoff

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Drawings/DrawingRasterizationService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Drawings/DwgConversionProviderInterface.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Drawings/AutodeskDwgConversionProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Drawings/DrawingGeometryWorkerClient.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Drawings/DrawingTakeoffData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Drawings/QuantityTakeoffData.php`

### Python worker: raster drawing understanding

- Create: `tools/estimate-vision-worker/pyproject.toml`
- Create: `tools/estimate-vision-worker/app/main.py`
- Create: `tools/estimate-vision-worker/app/preprocess.py`
- Create: `tools/estimate-vision-worker/app/floor_plan_takeoff.py`
- Create: `tools/estimate-vision-worker/app/spec_table_takeoff.py`
- Create: `tools/estimate-vision-worker/tests/test_floor_plan_takeoff.py`
- Create: `tools/estimate-vision-worker/tests/fixtures/`

### Backend: evidence graph and normative assembly

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Evidence/EstimateEvidenceGraphService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/WorkIntentClassifier.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeSearchProfileCatalog.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/ResourceAssemblyService.php`

### Backend: review wizard and learning

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Review/EstimateReviewQueueService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Review/EstimateReviewDecisionService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningRecorder.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningEvidenceService.php`
- Create migrations for review decisions and evidence snapshots.

### Admin UI

- Modify: `prohelper_admin/src/pages/.../EstimateGenerationReviewPage.tsx` or current AI estimate detail page.
- Create: `prohelper_admin/src/components/estimate-generation/ReviewWizard.tsx`
- Create: `prohelper_admin/src/components/estimate-generation/NormCandidateList.tsx`
- Create: `prohelper_admin/src/components/estimate-generation/EvidencePanel.tsx`
- Create: `prohelper_admin/src/services/estimateGenerationReviewApi.ts`
- Create: `prohelper_admin/src/types/estimateGenerationReview.ts`

### Tests and QA

- Add unit tests under `tests/Unit/EstimateGeneration/`.
- Add feature/API tests under `tests/Feature/EstimateGeneration/`.
- Add Python worker tests under `tools/estimate-vision-worker/tests/`.
- Add golden dataset manifest under `tests/Fixtures/EstimateGeneration/golden/manifest.json`.
- Add documentation under `docs/ai-estimator/`.

---

## Task 0: Freeze Current State Before Implementation

**Files:**
- Inspect: all modified files from `git status --short`

- [ ] **Step 1: Inspect local dirty tree**

Run:

```bash
git status --short --branch
git diff -- app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php
git diff -- tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php
```

Expected: see only intentional paused edits and this plan file.

- [ ] **Step 2: Decide how to preserve paused edits**

If paused edits should be kept for later hardening, run:

```bash
git stash push -m "paused-ai-estimator-no-air-hardening" -- app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php tests/Unit/EstimateGeneration/WorkIntentClassifierTest.php tests/Unit/EstimateGeneration/NormativeSearchProfileCatalogTest.php
```

Expected: code tree clean except plan/docs.

- [ ] **Step 3: Commit only the plan**

```bash
git add docs/superpowers/plans/2026-06-29-ai-estimator-no-air-plan.md
git commit -m "docs[backend]: план доведения AI-сметчика без воздуха"
```

Expected: one docs commit, no production code mixed in.

---

## Task 1: Production Normative Inventory Audit

**Files:**
- Create: `docs/ai-estimator/production-normative-inventory.md`
- No code changes.

- [ ] **Step 1: Verify SSH access**

Run from Windows PowerShell:

```powershell
ssh -o BatchMode=yes -o ConnectTimeout=10 -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "echo ok"
```

Expected: `ok`. If it times out during banner exchange, stop and ask infrastructure to fix read-only SSH availability.

- [ ] **Step 2: Count normative tables read-only**

Run:

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode([
    \"dataset_versions\" => DB::table(\"estimate_dataset_versions\")->count(),
    \"norm_collections\" => DB::table(\"estimate_norm_collections\")->count(),
    \"norm_sections\" => DB::table(\"estimate_norm_sections\")->count(),
    \"norms\" => DB::table(\"estimate_norms\")->count(),
    \"norm_resources\" => DB::table(\"estimate_norm_resources\")->count(),
    \"resource_prices\" => DB::table(\"estimate_resource_prices\")->count(),
    \"regional_price_versions\" => DB::table(\"estimate_regional_price_versions\")->count(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

Expected: non-zero counts for norms, resources and prices.

- [ ] **Step 3: Inspect loaded datasets**

Run:

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode(DB::table(\"estimate_dataset_versions\")->select(\"id\",\"source_type\",\"version_key\",\"status\",\"rows_imported\",\"created_at\")->orderByDesc(\"id\")->limit(20)->get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

Expected: active parsed FSNB/FSBC/labor/regional datasets are visible.

- [ ] **Step 4: Inspect coverage by collection**

Run:

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode(DB::table(\"estimate_norm_collections as c\")->join(\"estimate_norms as n\", \"n.collection_id\", \"=\", \"c.id\")->selectRaw(\"c.norm_type, c.code, count(*) as norms\")->groupBy(\"c.norm_type\", \"c.code\")->orderBy(\"c.code\")->get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

Expected: GESN/GESNm/GESNp/GESNr-style collections are present.

- [ ] **Step 5: Inspect price linkage**

Run:

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode([
    \"norms_with_resources\" => DB::table(\"estimate_norms as n\")->join(\"estimate_norm_resources as r\", \"r.estimate_norm_id\", \"=\", \"n.id\")->distinct(\"n.id\")->count(\"n.id\"),
    \"priced_resources\" => DB::table(\"estimate_resource_prices\")->whereNotNull(\"unit_price\")->count(),
    \"regional_priced_resources\" => DB::table(\"estimate_resource_prices\")->whereNotNull(\"regional_price_version_id\")->count(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

Expected: linkage exists; missing linkage becomes a data quality task, not a generation fallback.

- [ ] **Step 6: Document audit result**

Write `docs/ai-estimator/production-normative-inventory.md`:

```markdown
# Production Normative Inventory

Дата проверки: YYYY-MM-DD

## Итог

- Нормы: <count>
- Ресурсы норм: <count>
- Цены ресурсов: <count>
- Региональные версии: <count>

## Риски

- <risk>

## Решения

- <decision>
```

---

## Task 2: Hard Contract “No Evidence, No Estimate Line”

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Evidence/EvidenceRefData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/EstimateLineGroundingData.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php`
- Test: `tests/Unit/EstimateGeneration/EstimateLineGroundingContractTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/EstimateGeneration/EstimateLineGroundingContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class EstimateLineGroundingContractTest extends TestCase
{
    public function test_unknown_package_without_evidence_produces_no_lines(): void
    {
        $items = $this->planner()->build(
            [
                'key' => 'unknown-engineering',
                'title' => 'Инженерные системы',
                'scope_type' => 'engineering',
                'source_refs' => [],
                'sections' => [[
                    'key' => 'unknown-engineering-section',
                    'title' => 'Инженерные системы',
                    'construction_part' => 'engineering',
                    'source_refs' => [],
                ]],
            ],
            [
                'key' => 'unknown-engineering-section',
                'title' => 'Инженерные системы',
                'construction_part' => 'engineering',
                'source_refs' => [],
            ],
            ['document_context' => ['facts_summary' => ['total_area_m2' => 120]]]
        );

        self::assertSame([], $items);
    }

    public function test_emitted_line_has_grounding_metadata(): void
    {
        $items = $this->planner()->build(
            [
                'key' => 'heating',
                'title' => 'Отопление',
                'scope_type' => 'heating',
                'source_refs' => [],
                'sections' => [[
                    'key' => 'heating-section',
                    'title' => 'Отопление',
                    'construction_part' => 'heating',
                    'source_refs' => [],
                ]],
            ],
            [
                'key' => 'heating-section',
                'title' => 'Отопление',
                'construction_part' => 'heating',
                'source_refs' => [],
            ],
            [
                'document_context' => [
                    'quantity_takeoffs' => [[
                        'scope_key' => 'heating_route_length',
                        'quantity' => 25.5,
                        'unit' => 'м',
                        'name' => 'Длина трасс отопления',
                        'source_refs' => [[
                            'type' => 'drawing',
                            'filename' => 'plan.pdf',
                            'page_number' => 1,
                        ]],
                    ]],
                ],
            ]
        );

        $pipe = array_values(array_filter($items, static fn (array $item): bool => ($item['quantity_formula'] ?? null) === 'heating.pipe'))[0] ?? null;

        self::assertIsArray($pipe);
        self::assertSame(25.5, (float) $pipe['quantity']);
        self::assertNotSame([], $pipe['source_refs']);
        self::assertSame('document_quantity', $pipe['metadata']['quantity_source']);
        self::assertSame('fsnb_required', $pipe['metadata']['normative_grounding_policy']);
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor(),
            new EstimatorScopeInferenceService(),
        );
    }
}
```

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/EstimateGeneration/EstimateLineGroundingContractTest.php
```

Expected: first test fails until generic fallback is removed.

- [ ] **Step 3: Remove generic fallback**

In `NormativeWorkItemPlannerService::definitions()`, do not call `packageDefinitions('custom', ...)` when package definitions are empty.

In `packageDefinitions()`, default branch returns `[]`.

- [ ] **Step 4: Run GREEN**

```bash
vendor/bin/phpunit tests/Unit/EstimateGeneration/EstimateLineGroundingContractTest.php tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php
```

Expected: all tests pass.

---

## Task 3: File/Page Classification Pipeline

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DocumentTypeClassifier.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/DTOs/Documents/ClassifiedDocumentData.php`
- Test: `tests/Unit/EstimateGeneration/DocumentTypeClassifierTest.php`

- [ ] **Step 1: Write failing classifier tests**

```php
public function test_classifies_floor_plan_image_by_filename_and_geometry_words(): void
{
    $classifier = new DocumentTypeClassifier();

    $result = $classifier->classify([
        'filename' => 'План дома рустама.jpg',
        'mime_type' => 'image/jpeg',
        'ocr_text' => '3255 5.14 м2 46.52 м2 8755 14845',
    ]);

    self::assertSame('floor_plan', $result->type);
    self::assertGreaterThanOrEqual(0.7, $result->confidence);
}

public function test_classifies_specification_table(): void
{
    $classifier = new DocumentTypeClassifier();

    $result = $classifier->classify([
        'filename' => 'Ведомость объемов работ.pdf',
        'mime_type' => 'application/pdf',
        'ocr_text' => 'Наименование работ Ед. изм. Количество',
    ]);

    self::assertSame('work_volume_statement', $result->type);
}
```

- [ ] **Step 2: Implement deterministic classifier**

Rules:
- `dwg`, `dxf` => `cad_drawing`
- image/pdf with many dimensions and room areas => `floor_plan`
- text/table with “ед. изм.”, “количество”, “объем работ” => `work_volume_statement`
- table with equipment/material columns => `specification`
- already estimated rows/codes => `reference_estimate`
- otherwise `text_document` or `unknown`

- [ ] **Step 3: Persist classification**

Add jsonb metadata fields if already available on document/page models; if not, create migration:

```php
$table->jsonb('classification')->nullable();
$table->jsonb('page_classifications')->nullable();
```

Do not run migration locally.

---

## Task 4: Layout/OCR Provider Contract

**Files:**
- Create: `LayoutProviderInterface.php`
- Create: `TableExtractionNormalizer.php`
- Modify: existing OCR provider services.
- Test: `tests/Unit/EstimateGeneration/Ocr/LayoutExtractionContractTest.php`

- [ ] **Step 1: Define provider output schema**

Every provider returns:

```php
[
    'pages' => [[
        'page_number' => 1,
        'width' => 2480,
        'height' => 3508,
        'text_blocks' => [[
            'text' => 'Прокладка труб отопления',
            'bbox' => [100, 200, 500, 240],
            'confidence' => 0.91,
        ]],
        'tables' => [[
            'bbox' => [50, 100, 2000, 900],
            'rows' => [[
                ['text' => 'Наименование', 'bbox' => [50, 100, 400, 140]],
            ]],
        ]],
        'figures' => [],
    ]],
]
```

- [ ] **Step 2: Add normalizer tests**

Test that provider-specific table cells normalize to rows with stable coordinates and confidence.

- [ ] **Step 3: Implement Yandex/current provider adapter**

Wrap current OCR output into the schema without changing existing storage.

- [ ] **Step 4: Add optional Azure/Paddle provider behind config**

Config keys:

```php
'estimate_generation.ocr.layout_provider' => env('ESTIMATE_OCR_LAYOUT_PROVIDER', 'current')
```

No hard dependency on Azure/Paddle in production until benchmark proves value.

---

## Task 5: Drawing Rasterization and CAD/DWG Handling

**Files:**
- Create: `DrawingRasterizationService.php`
- Create: `DwgConversionProviderInterface.php`
- Create: `AutodeskDwgConversionProvider.php`
- Test: `tests/Unit/EstimateGeneration/Drawings/DrawingRasterizationServiceTest.php`

- [ ] **Step 1: Define accepted inputs**

Supported:
- PDF page with drawing.
- PNG/JPG/TIFF scan or screenshot.
- DWG/DXF through conversion provider.

Unsupported:
- BIM/IFC/Revit models in this stage.

- [ ] **Step 2: Implement PDF/image page artifact contract**

Output:

```php
[
    'artifact_type' => 'page_image',
    'storage_path' => 'org-<id>/estimate-generation/<session>/pages/page-001.png',
    'dpi' => 300,
    'page_number' => 1,
    'width_px' => 3508,
    'height_px' => 2480,
]
```

- [ ] **Step 3: Implement DWG conversion provider interface**

Methods:

```php
public function supports(string $extension): bool;
public function convertToPageImages(StoredFileData $file): array;
public function extractMetadata(StoredFileData $file): array;
```

- [ ] **Step 4: Add Autodesk APS only as provider option**

Use APS when credentials configured. If not configured, DWG files are accepted but marked `needs_conversion_provider`, not silently ignored.

---

## Task 6: Python Vision Worker for Floor Plans

**Files:**
- Create Python worker files under `tools/estimate-vision-worker/`.
- Create Laravel client `DrawingGeometryWorkerClient.php`.
- Test: Python unit tests and Laravel client unit test.

- [ ] **Step 1: Worker API**

Endpoint:

```http
POST /v1/floor-plan/takeoff
Content-Type: application/json

{
  "page_image_path": "...",
  "ocr_blocks": [],
  "page_number": 1
}
```

Response:

```json
{
  "scale": {"ratio": 50.0, "unit": "mm_per_px", "confidence": 0.72},
  "rooms": [{"label": "Гостиная", "area_m2": 46.52, "bbox": [100, 100, 900, 700], "confidence": 0.83}],
  "dimensions": [{"value_mm": 8755, "line": [100, 900, 1000, 900], "confidence": 0.78}],
  "openings": [{"type": "door", "bbox": [300, 400, 360, 480], "confidence": 0.65}],
  "quantity_takeoffs": [
    {"quantity_key": "finish.floor", "value": 46.52, "unit": "м2", "source": "room_area_text", "confidence": 0.83}
  ]
}
```

- [ ] **Step 2: Implement deterministic baseline**

Use:
- binarization/thresholding,
- Hough lines for walls/dimensions,
- contour grouping for room polygons,
- OCR text bbox association for room labels/areas,
- scale from explicit dimension strings if present.

- [ ] **Step 3: Guardrails**

If scale not found and no explicit room areas exist, return `needs_manual_takeoff`, not guessed quantities.

- [ ] **Step 4: Laravel queues**

Each drawing page runs as a job with timeout, retry and progress. No request thread does OpenCV work.

---

## Task 7: Specification and Work Volume Statement Parser

**Files:**
- Create: `SpecificationTableParser.php`
- Create: `WorkVolumeStatementParser.php`
- Test: `tests/Unit/EstimateGeneration/SpecificationTableParserTest.php`

- [ ] **Step 1: Parse common columns**

Columns:
- `Наименование`
- `Ед. изм.`
- `Количество`
- `Марка/тип`
- `Примечание`

- [ ] **Step 2: Normalize rows into quantity takeoffs**

Example output:

```php
[
    'quantity_key' => 'heating.radiators',
    'name' => 'Радиатор биметаллический',
    'value' => 12,
    'unit' => 'шт',
    'source_refs' => [[
        'type' => 'table_cell',
        'filename' => 'spec.pdf',
        'page_number' => 4,
        'bbox' => [120, 540, 300, 580],
    ]],
]
```

- [ ] **Step 3: Unknown rows go to review**

Rows with no known quantity key become `unmapped_specification_rows`, visible in review, not estimate lines.

---

## Task 8: Evidence Graph

**Files:**
- Create: `EstimateEvidenceGraphService.php`
- Create migration: `estimate_generation_evidence_nodes`
- Create migration: `estimate_generation_evidence_edges`
- Test: `tests/Unit/EstimateGeneration/EstimateEvidenceGraphServiceTest.php`

- [ ] **Step 1: Create node types**

Node types:
- `document`
- `page`
- `ocr_block`
- `table_cell`
- `drawing_dimension`
- `room`
- `quantity_takeoff`
- `work_intent`
- `norm_candidate`
- `review_decision`

- [ ] **Step 2: Add idempotent upsert**

Natural key:

```php
hash(session_id, document_id, page_number, node_type, bbox, normalized_text)
```

- [ ] **Step 3: Estimate line must reference graph nodes**

Modify persistence to reject generated items with no evidence nodes unless source is explicit user/manual decision.

---

## Task 9: Strict Work Intent Catalog

**Files:**
- Modify: `NormativeWorkItemPlannerService.php`
- Modify: `WorkIntentClassifier.php`
- Modify: `NormativeSearchProfileCatalog.php`
- Test: `NormativeWorkItemPlannerDensityTest.php`, `WorkIntentClassifierTest.php`, `NormativeSearchProfileCatalogTest.php`

- [ ] **Step 1: Remove all generic intent fallback**

Rules:
- Unknown package returns no items.
- Unknown scope returns no items.
- Scope-level fallback to electrical/plumbing is forbidden.
- Package-specific catalog is allowed only for known package keys.

- [ ] **Step 2: Add missing known packages**

House:
- `stairs`

Warehouse/mixed:
- `metal_frame`
- `fire_safety`
- `low_current`
- `server_room`
- `entrance_group`

Only add rows that map to a clear norm search profile.

- [ ] **Step 3: Add test for every package key from `PackagePlannerService`**

Test loops through package keys and asserts:

```php
self::assertNotContains('Комплекс строительных работ', $names);
self::assertNotContains('custom', array_column($items, 'work_category'));
self::assertSame(count($items), count(array_unique(array_column($items, 'normative_search_key'))));
```

---

## Task 10: Norm Candidate Search and Match Quality

**Files:**
- Modify: `NormativeCandidateSearchService.php`
- Modify: `EstimateNormativeMatcher.php`
- Modify: `NormativeMatchDecisionService.php`
- Test: existing norm safety tests plus new `NormativeCandidateSearchCoverageTest.php`

- [ ] **Step 1: Add match contract**

Candidate response must include:
- `estimate_norm_id`
- code/name/unit
- section code/name
- resources count
- priced resources count
- confidence
- hard warnings
- evidence keys

- [ ] **Step 2: Hard reject wrong domain**

Examples:
- heating equipment cannot use pipe-only norm if profile is equipment.
- sewerage cannot use water supply unless candidate contains sewerage terms.
- electrical cannot use site/earthwork.

- [ ] **Step 3: Always return top-k for review**

Even if no safe auto match exists, store top candidates as review options.

- [ ] **Step 4: Add coverage metric**

For each generated item:

```php
norm_candidate_count >= 1
safe_candidate_count >= 1
auto_priced === true only if confidence >= threshold and no hard warnings
```

---

## Task 11: Resource and Price Assembly

**Files:**
- Modify: `ResourceAssemblyService.php`
- Modify: `EstimateNormativeMatcher.php`
- Test: `ResourceAssemblySafetyTest.php`

- [ ] **Step 1: Price status matrix**

Statuses:
- `priced`
- `review_priced`
- `missing_norm`
- `missing_resources`
- `missing_prices`
- `missing_quantity`
- `needs_manual_norm`

- [ ] **Step 2: Zero price guard**

If all cost components are zero:

```php
$item['pricing_status'] = 'missing_prices';
$item['pricing_blocker'] = 'missing_resource_prices';
$item['total_cost'] = null;
```

Do not show `0 ₽` as calculated total.

- [ ] **Step 3: Regional version required when selected**

If session has `estimate_regional_price_version_id`, prefer matching regional prices and record fallback to base explicitly.

---

## Task 12: Manual Review Wizard

**Files:**
- Backend review services and routes.
- Admin React components listed above.
- Tests: backend feature tests and Vitest component tests.

- [ ] **Step 1: Backend endpoints**

Endpoints:
- `GET /admin/estimate-generation/sessions/{session}/review-queue`
- `GET /admin/estimate-generation/items/{item}/norm-candidates`
- `POST /admin/estimate-generation/items/{item}/review-decision`
- `POST /admin/estimate-generation/sessions/{session}/finalize-reviewed-estimate`

- [ ] **Step 2: Review queue response**

Response groups:
- `missing_norm`
- `low_confidence_norm`
- `missing_quantity`
- `missing_price`
- `ready_but_reviewable`

- [ ] **Step 3: UI behavior**

For each item:
- left: generated work and source evidence,
- center: candidates with code, name, unit, resources/prices,
- right: preview cost impact and warnings,
- action: choose candidate, adjust quantity, reject line, split line, merge duplicate.

- [ ] **Step 4: Learning event**

Every choice writes:

```php
[
    'work_item_snapshot' => ...,
    'selected_norm_id' => ...,
    'rejected_norm_ids' => [...],
    'quantity_snapshot' => ...,
    'source_quality_score' => ...,
]
```

---

## Task 13: Learning Without Bad Feedback Loops

**Files:**
- Modify learning services.
- Test: `EstimateGenerationLearningEvidenceServiceTest.php`

- [ ] **Step 1: Separate sources**

Learning source types:
- `golden_estimate_upload`
- `manual_review_choice`
- `manual_review_rejection`
- `auto_generated_confirmed`

- [ ] **Step 2: Never train on unreviewed generated lines**

Evidence service ignores auto-generated lines until user confirms or finalizes.

- [ ] **Step 3: Weight examples**

Weights:
- Grand-Smeta эталон: 1.0
- manual review choice: 0.85
- auto-generated confirmed: 0.55
- rejection: negative weight

---

## Task 14: Prompt Layer Rewrite

**Files:**
- Locate current prompt builders with `rg -n "prompt|LLM|schema|estimate" app/BusinessModules/Addons/EstimateGeneration`.
- Modify prompt builder files.
- Test: prompt contract tests.

- [ ] **Step 1: Define LLM allowed responsibilities**

Allowed:
- classify document/page,
- extract candidate facts,
- propose work intent from evidence,
- explain candidate choice.

Forbidden:
- invent estimate lines,
- invent quantities,
- invent norm code,
- invent price.

- [ ] **Step 2: Schema validation**

All LLM JSON output must validate:

```json
{
  "evidence_refs": [],
  "facts": [],
  "uncertainties": [],
  "no_estimate_lines": true
}
```

- [ ] **Step 3: Prompt tests**

Test that prompt contains:
- “do not create estimate rows”
- “return unknown when evidence is missing”
- “norm code must come only from provided candidates”

---

## Task 15: Golden Dataset and Metrics

**Files:**
- Create: `docs/ai-estimator/golden-dataset.md`
- Create: `tests/Fixtures/EstimateGeneration/golden/manifest.json`
- Create: `app/Console/Commands/EstimateGenerationEvaluateGoldenDatasetCommand.php`

- [ ] **Step 1: Dataset structure**

Each case:

```json
{
  "case_id": "house-floor-plan-001",
  "input_files": ["plan.pdf", "spec.pdf"],
  "golden_estimate": "grand-smeta-export.xml",
  "region": "16",
  "price_period": "2026-q2",
  "expected_scopes": ["foundation", "walls", "heating"],
  "notes": "real anonymized project"
}
```

- [ ] **Step 2: Metrics**

Metrics:
- document classification accuracy,
- table extraction F1,
- room area error,
- quantity takeoff MAPE,
- norm match@1,
- norm match@5,
- priced line rate,
- false positive line count,
- zero-price-ready count,
- manual review workload.

- [ ] **Step 3: Acceptance thresholds before production confidence**

Initial target:
- false positive line count = 0 for ready lines,
- zero-price-ready count = 0,
- norm match@5 >= 0.85 on golden cases,
- quantity MAPE <= 15% where plan contains explicit dimensions/areas,
- manual review workload measured, not hidden.

---

## Task 16: Queue, Backpressure, and Idempotency

**Files:**
- Modify pipeline services and jobs.
- Add tests for job status transitions.

- [ ] **Step 1: Split heavy stages**

Stages:
- upload registered,
- document classified,
- pages rendered,
- OCR/layout done,
- drawing takeoff done,
- evidence graph built,
- work intents built,
- norm candidates matched,
- review queue ready,
- estimate finalized.

- [ ] **Step 2: Add idempotency key per stage**

Key:

```php
hash(session_id, document_id, page_number, stage, source_file_checksum)
```

- [ ] **Step 3: Add resource limits**

Limits:
- max pages per batch job,
- max image megapixels,
- max DWG conversion time,
- retry count,
- dead-letter status.

---

## Task 17: Admin UX for Trust

**Files:**
- Admin components and API client.

- [ ] **Step 1: Replace “Готово” semantics**

Statuses:
- `Черновик`
- `Нужно выбрать норму`
- `Нужно уточнить объем`
- `Нужно выбрать цену`
- `Готово после проверки`

- [ ] **Step 2: Evidence-first UI**

Every item card shows:
- source document/page,
- extracted quantity,
- selected norm/candidates,
- price source,
- warnings.

- [ ] **Step 3: No technical text**

UI text must not show internal terms like `fallback`, `payload`, `dto`, `sql`.

---

## Task 18: Rollout

**Files:**
- Config, docs, feature flags.

- [ ] **Step 1: Feature flags**

Flags:
- `AI_ESTIMATOR_STRICT_NO_AIR=true`
- `AI_ESTIMATOR_DRAWING_TAKEOFF=true`
- `AI_ESTIMATOR_REVIEW_WIZARD=true`
- `AI_ESTIMATOR_AUTOPRICE_READY_LINES=false` initially

- [ ] **Step 2: Deploy sequence**

1. Deploy schema and backend read paths.
2. Enable strict no-air mode for new sessions only.
3. Enable review wizard.
4. Enable drawing takeoff on internal/admin sessions.
5. Enable limited auto-ready only after golden metrics pass.

---

## Technical Debt to Remove

- Generic “Комплекс строительных работ” fallback.
- `target_items_min` as a reason to invent lines.
- Any UI state where zero price looks calculated.
- Any generated line without `source_refs`.
- Any learning example from unreviewed generated output.
- Any synchronous OCR/CAD/vision work in request thread.
- Any norm match that stores only one candidate and discards alternatives.

---

## Execution Order

1. Task 0: freeze current worktree.
2. Task 1: production normative audit.
3. Task 2: no-evidence contract.
4. Task 9: strict work intent catalog.
5. Task 10 and 11: norm/price safety.
6. Task 12 and 13: review wizard and learning.
7. Task 3, 4 and 7: document/spec parsing.
8. Task 5 and 6: drawings and geometry takeoff.
9. Task 15: golden dataset and metrics.
10. Task 16 to 18: scale, UX, rollout.

This order is intentional: first stop bad output, then improve coverage.

---

## Self-Review

Spec coverage:
- Чертежи/планировки: Tasks 3, 5, 6, 8, 15.
- Спецификации/ведомости: Tasks 3, 4, 7, 8.
- Нормы/ФСНБ/ФСБЦ: Tasks 1, 10, 11.
- Промпт плохо выходит: Task 14 limits prompt responsibilities.
- Без воздуха: Tasks 2, 9, 11.
- Мастер выбора и обучение: Tasks 12, 13.
- Нагрузка на backend: Task 16.
- Без BIM: stated as non-scope.

Placeholder scan:
- No `TBD`.
- No “implement later”.
- Unknowns are explicit gates, not hidden placeholders.

Type consistency:
- `EvidenceRefData`, `QuantityTakeoffData`, `DrawingTakeoffData`, `EstimateLineGroundingData` are introduced before use.
- Review statuses are reused consistently across backend and UI.

