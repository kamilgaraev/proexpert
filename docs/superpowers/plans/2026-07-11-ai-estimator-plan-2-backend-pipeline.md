# AI-сметчик МОСТ: Backend Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить `EstimateGenerationOrchestrator` и неявные side effects возобновляемым, идемпотентным конвейером с checkpoints, evidence graph, компактным snapshot API, учетом AI-расходов и диагностируемыми ошибками.

**Architecture:** `PipelineRunner` выполняет зарегистрированные стадии строго по `ProcessingStage`, сохраняет immutable output/checkpoint и переходит дальше только через workflow Plan 1. Документы обрабатываются page/chunk units; внешние вызовы проходят через usage recorder; ошибки нормализуются в safe failure records и не смешиваются с пользовательскими review issues.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL JSONB, Redis/Horizon, S3/FileService, `ShouldQueue`, PHPUnit, Larastan.

## Global Constraints

- Plan 1 полностью выполнен; использовать его `EstimateGenerationWorkflow`, `EstimateGenerationStatus`, `SessionSnapshotData` и permissions без дублирования.
- Не запускать миграции и DB-команды локально.
- Не изменять обычные сметы; `ApplyGeneratedEstimate` остается единственным writer.
- Не хранить полный prompt, секреты, персональные данные и содержимое документов в usage/error ledger.
- Каждый stage идемпотентен по `(session_id, stage, input_version)`.
- Повторная доставка job не создает повторные pages, evidence, packages или audit events.
- Большой документ обрабатывается page/chunk units и не загружается целиком в память.
- Старый orchestrator удаляется в конце плана; adapter/fallback не остается.
- Все пользовательские ошибки возвращаются через `trans_message(...)`; технические детали остаются в безопасном логе.

---

## Структура файлов

```text
app/BusinessModules/Addons/EstimateGeneration/Pipeline/
  ProcessingStage.php
  PipelineContext.php
  PipelineStage.php
  PipelineStageResult.php
  PipelineRegistry.php
  PipelineRunner.php
  Exceptions/PipelineStageException.php
  Stages/...

app/BusinessModules/Addons/EstimateGeneration/Application/Documents/
  CreateDocumentProcessingUnits.php
  ProcessDocumentUnit.php

app/BusinessModules/Addons/EstimateGeneration/Evidence/
  EvidenceType.php
  EvidenceData.php
  EvidenceRecorder.php
  EvidenceInvalidator.php

app/BusinessModules/Addons/EstimateGeneration/Observability/
  AiUsageData.php
  AiUsageRecorder.php
  FailureData.php
  FailureRecorder.php
  SensitiveDiagnosticSanitizer.php
```

### Task 1: Ввести pipeline contracts и registry

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/ProcessingStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineContext.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineStageResult.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineRegistry.php`
- Create: `tests/Unit/EstimateGeneration/Pipeline/PipelineRegistryTest.php`

**Interfaces:**
- Consumes: session ID, organization ID, project ID, state version and input version.
- Produces: строгий stage contract для runner и jobs.

- [ ] **Step 1: Написать падающий registry test**

```php
#[Test]
public function registry_returns_stages_in_the_only_valid_order(): void
{
    $registry = new PipelineRegistry([
        new FakeStage(ProcessingStage::BuildDraft),
        new FakeStage(ProcessingStage::UnderstandObject),
    ]);

    self::assertSame([
        ProcessingStage::UnderstandObject,
        ProcessingStage::BuildDraft,
    ], array_map(static fn (PipelineStage $stage) => $stage->stage(), $registry->ordered()));
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Pipeline/PipelineRegistryTest.php`

Expected: FAIL, pipeline classes отсутствуют.

- [ ] **Step 3: Реализовать ProcessingStage**

```php
enum ProcessingStage: string
{
    case UnderstandDocuments = 'understand_documents';
    case UnderstandObject = 'understand_object';
    case ExtractQuantities = 'extract_quantities';
    case PlanWorkItems = 'plan_work_items';
    case MatchNormatives = 'match_normatives';
    case AssembleResources = 'assemble_resources';
    case ResolvePrices = 'resolve_prices';
    case BuildDraft = 'build_draft';
    case ValidateDraft = 'validate_draft';

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }
}
```

- [ ] **Step 4: Реализовать contracts**

```php
interface PipelineStage
{
    public function stage(): ProcessingStage;

    public function execute(PipelineContext $context): PipelineStageResult;
}

final readonly class PipelineContext
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $stateVersion,
        public string $inputVersion,
    ) {}
}

final readonly class PipelineStageResult
{
    public function __construct(
        public ProcessingStage $stage,
        public string $outputVersion,
        public array $metrics,
        public array $warnings = [],
    ) {}
}
```

- [ ] **Step 5: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Pipeline/PipelineRegistryTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Pipeline tests/Unit/EstimateGeneration/Pipeline/PipelineRegistryTest.php
git commit -m "feat[lk]: определен pipeline AI-сметчика"
```

Expected: PASS; commit создан.

### Task 2: Добавить checkpoints и compare-and-set execution

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPipelineCheckpoint.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineCheckpointStore.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineCheckpointStore.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineRunner.php`
- Create: `tests/Unit/EstimateGeneration/Pipeline/PipelineRunnerTest.php`

**Interfaces:**
- Consumes: `PipelineRegistry`, `PipelineContext`.
- Produces: `runNext(PipelineContext): PipelineStageResult|null` и persisted unique checkpoint.

- [ ] **Step 1: Написать idempotency test**

```php
#[Test]
public function completed_stage_is_not_executed_twice_for_same_input_version(): void
{
    $stage = new CountingStage(ProcessingStage::UnderstandObject);
    $runner = $this->runner([$stage]);
    $context = $this->context(inputVersion: 'sha256:a');

    $runner->runNext($context);
    $runner->runNext($context);

    self::assertSame(1, $stage->executions);
    self::assertSame(1, $this->checkpointStore->count());
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Pipeline/PipelineRunnerTest.php`

Expected: FAIL.

- [ ] **Step 3: Создать migration**

```php
Schema::create('estimate_generation_pipeline_checkpoints', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
    $table->string('stage', 80);
    $table->string('input_version', 80);
    $table->string('output_version', 80)->nullable();
    $table->string('status', 30);
    $table->jsonb('metrics')->default('{}');
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('completed_at')->nullable();
    $table->timestampsTz();
    $table->unique(['session_id', 'stage', 'input_version'], 'estimate_generation_checkpoint_unique');
});
```

- [ ] **Step 4: Реализовать runner**

Runner:

1. ищет первый stage без completed checkpoint для input version;
2. атомарно создает/захватывает `running` checkpoint;
3. вызывает stage;
4. сохраняет result и `completed_at`;
5. при исключении переводит checkpoint в `failed` через FailureRecorder;
6. не изменяет workflow status напрямую.

- [ ] **Step 5: Проверить test и migration syntax**

```bash
php -l app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php
php artisan test tests/Unit/EstimateGeneration/Pipeline/PipelineRunnerTest.php
```

Expected: PASS и `No syntax errors`.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationPipelineCheckpoint.php app/BusinessModules/Addons/EstimateGeneration/Pipeline tests/Unit/EstimateGeneration/Pipeline/PipelineRunnerTest.php
git commit -m "feat[lk]: добавлены checkpoints pipeline AI-сметчика"
```

### Task 3: Разбить документы на идемпотентные processing units

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000200_create_estimate_generation_processing_units_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationProcessingUnit.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/CreateDocumentProcessingUnits.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php`
- Replace: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationDocumentJob.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationUnitJob.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingUnitTest.php`

**Interfaces:**
- Consumes: document metadata and S3 object through `FileService`.
- Produces: page/sheet/image units identified by `(document_id, unit_type, unit_index, source_version)`.

- [ ] **Step 1: Написать test повторной доставки**

```php
#[Test]
public function duplicate_unit_job_reuses_the_same_unit_result(): void
{
    $unit = $this->createPendingUnit();
    $job = new ProcessEstimateGenerationUnitJob($unit->id);

    $job->handle(app(ProcessDocumentUnit::class));
    $firstResultVersion = $unit->refresh()->output_version;
    $job->handle(app(ProcessDocumentUnit::class));

    self::assertSame($firstResultVersion, $unit->refresh()->output_version);
    self::assertSame(1, EstimateGenerationDocumentPage::query()
        ->where('processing_unit_id', $unit->id)
        ->count());
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingUnitTest.php`

Expected: FAIL.

- [ ] **Step 3: Создать processing unit schema**

Поля: `document_id`, `unit_type`, `unit_index`, `source_version`, `status`, `attempt_count`, `output_version`, `failure_id`, timestamps. Unique: `document_id, unit_type, unit_index, source_version`.

- [ ] **Step 4: Реализовать unit creation**

```php
public function handle(EstimateGenerationDocument $document): Collection
{
    return DB::transaction(function () use ($document): Collection {
        return collect($this->detector->units($document))->map(
            fn (DocumentUnitData $unit) => EstimateGenerationProcessingUnit::query()->firstOrCreate(
                [
                    'document_id' => $document->id,
                    'unit_type' => $unit->type,
                    'unit_index' => $unit->index,
                    'source_version' => $document->source_version,
                ],
                ['status' => 'pending', 'attempt_count' => 0],
            ),
        );
    });
}
```

- [ ] **Step 5: Сделать old document job только dispatcher-ом**

Он создает units и dispatch-ит `ProcessEstimateGenerationUnitJob` с `WithoutOverlapping("estimate-generation-unit:{$unitId}")`; чтение/парсинг документа в старом job удалить.

- [ ] **Step 6: Запустить tests и commit**

```bash
php artisan test tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingUnitTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Application/Documents app/BusinessModules/Addons/EstimateGeneration/Jobs app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationProcessingUnit.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000200_create_estimate_generation_processing_units_table.php tests/Feature/EstimateGeneration/Pipeline/DocumentProcessingUnitTest.php
git commit -m "feat[lk]: документы AI-сметы разбиты на единицы обработки"
```

Expected: PASS; повторная job не увеличивает количество outputs.

### Task 4: Ввести единый evidence graph

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000300_create_estimate_generation_evidence_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationEvidence.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evidence/EvidenceType.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evidence/EvidenceData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evidence/EvidenceRecorder.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evidence/EvidenceInvalidator.php`
- Create: `tests/Unit/EstimateGeneration/Pipeline/EvidenceRecorderTest.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/EvidenceInvalidationTest.php`

**Interfaces:**
- Consumes: document/page/unit coordinates and derived entity identifiers.
- Produces: immutable evidence node and parent-child edges; invalidation cascade by source version.

- [ ] **Step 1: Написать recorder test**

```php
#[Test]
public function same_evidence_fingerprint_is_recorded_once(): void
{
    $data = new EvidenceData(
        sessionId: 10,
        type: EvidenceType::Measured,
        sourceType: 'document_page_region',
        sourceId: 44,
        sourceVersion: 'sha256:a',
        locator: ['page' => 2, 'bbox' => [0.1, 0.2, 0.4, 0.5]],
        value: ['amount' => 12.4, 'unit' => 'м2'],
        confidence: 0.93,
        producer: 'pdf_geometry:v1',
    );

    $first = $this->recorder->record($data);
    $second = $this->recorder->record($data);

    self::assertSame($first->id, $second->id);
}
```

- [ ] **Step 2: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Pipeline/EvidenceRecorderTest.php`

Expected: FAIL.

- [ ] **Step 3: Создать schema**

`estimate_generation_evidence`: session, type, source_type/id/version, locator JSONB, value JSONB, confidence decimal, producer, fingerprint, invalidated_at. `estimate_generation_evidence_edges`: parent_id, child_id, relation. Unique fingerprint и edge.

- [ ] **Step 4: Реализовать fingerprint и invalidation**

```php
$fingerprint = hash('sha256', json_encode([
    $data->sessionId,
    $data->type->value,
    $data->sourceType,
    $data->sourceId,
    $data->sourceVersion,
    $data->locator,
    $data->value,
    $data->producer,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
```

`EvidenceInvalidator::invalidateSource(string $sourceType, int $sourceId, string $oldVersion)` помечает node и всех descendants через iterative breadth-first traversal с visited set; не удаляет историю.

- [ ] **Step 5: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Pipeline/EvidenceRecorderTest.php tests/Feature/EstimateGeneration/Pipeline/EvidenceInvalidationTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Evidence app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationEvidence.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000300_create_estimate_generation_evidence_table.php tests/Unit/EstimateGeneration/Pipeline tests/Feature/EstimateGeneration/Pipeline/EvidenceInvalidationTest.php
git commit -m "feat[lk]: добавлен evidence graph AI-сметчика"
```

Expected: PASS.

### Task 5: Добавить usage ledger с snapshot стоимости

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationAiUsage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiUsageData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiUsageRecorder.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiCostCalculator.php`
- Replace: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrUsageLogger.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/Clients/TimewebVisionOcrClient.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php`
- Create: `tests/Unit/EstimateGeneration/Observability/AiCostCalculatorTest.php`
- Create: `tests/Feature/EstimateGeneration/EstimateGenerationUsageLedgerTest.php`

**Interfaces:**
- Consumes: provider response usage, model price snapshot and operation context.
- Produces: one immutable usage row per external attempt.

- [ ] **Step 1: Написать cost test**

```php
#[Test]
public function cost_uses_the_price_snapshot_of_the_call(): void
{
    $cost = app(AiCostCalculator::class)->calculate(
        inputTokens: 1_000,
        cachedInputTokens: 400,
        outputTokens: 200,
        priceSnapshot: [
            'input_per_million' => '0.50',
            'cached_input_per_million' => '0.10',
            'output_per_million' => '2.00',
            'currency' => 'USD',
        ],
    );

    self::assertSame('0.000740', $cost->amount);
    self::assertSame('USD', $cost->currency);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Observability/AiCostCalculatorTest.php`

Expected: FAIL.

- [ ] **Step 3: Создать usage schema**

Поля: correlation/session/document/page/unit IDs, organization/project, stage, provider, model, input/cached/output/reasoning tokens, image_count, image_detail, page_count, duration_ms, attempt, status, HTTP code, price_snapshot JSONB, cost_amount decimal(18,8), currency, created_at. Индексы по created_at, organization, model, stage, status.

- [ ] **Step 4: Реализовать recorder**

```php
public function record(AiUsageData $data): void
{
    EstimateGenerationAiUsage::query()->create([
        ...$data->dimensions(),
        'price_snapshot' => $data->priceSnapshot,
        'cost_amount' => $this->calculator->calculateFrom($data)->amount,
        'currency' => $data->priceSnapshot['currency'],
    ]);
}
```

Recorder вызывается в `finally`, поэтому неуспешные attempts тоже учитываются. Prompt и response body в DTO отсутствуют.

- [ ] **Step 5: Перевести OCR и reranker на recorder**

Удалить `OcrUsageLogger` после переноса callers. Каждый provider attempt получает correlation ID и точный stage.

- [ ] **Step 6: Запустить tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Observability/AiCostCalculatorTest.php tests/Feature/EstimateGeneration/EstimateGenerationUsageLedgerTest.php
git add -A app/BusinessModules/Addons/EstimateGeneration/Observability app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationAiUsage.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php app/BusinessModules/Addons/EstimateGeneration/Services/Ocr app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking tests/Unit/EstimateGeneration/Observability tests/Feature/EstimateGeneration/EstimateGenerationUsageLedgerTest.php
git commit -m "feat[lk]: добавлен учет стоимости AI-сметчика"
```

Expected: PASS; test подтверждает запись failed attempt без prompt body.

### Task 6: Нормализовать failures и безопасную диагностику

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationFailure.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureCategory.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureRecorder.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/SensitiveDiagnosticSanitizer.php`
- Create: `tests/Unit/EstimateGeneration/Observability/SensitiveDiagnosticSanitizerTest.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/PipelineFailureRecoveryTest.php`

**Interfaces:**
- Consumes: throwable and operation context.
- Produces: fingerprinted safe failure with category `recoverable`, `user_action_required` or `terminal`.

- [ ] **Step 1: Написать sanitizer test**

```php
#[Test]
public function sanitizer_masks_secrets_and_document_content(): void
{
    $result = app(SensitiveDiagnosticSanitizer::class)->sanitize([
        'Authorization' => 'Bearer secret-token',
        'api_key' => 'secret-key',
        'prompt' => 'полный текст документа',
        'provider_code' => 'timeout',
    ]);

    self::assertSame('[REDACTED]', $result['Authorization']);
    self::assertSame('[REDACTED]', $result['api_key']);
    self::assertArrayNotHasKey('prompt', $result);
    self::assertSame('timeout', $result['provider_code']);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Observability/SensitiveDiagnosticSanitizerTest.php`

Expected: FAIL.

- [ ] **Step 3: Реализовать failure schema и recorder**

Поля: fingerprint, category, code, stage, provider, model, organization/session/document/unit IDs, safe_context JSONB, attempt, first_seen_at, last_seen_at, occurrence_count, resolved_at. Fingerprint не включает message с пользовательскими данными.

- [ ] **Step 4: Интегрировать runner и jobs**

Recoverable failure оставляет unit/checkpoint retryable; user-action failure переводит workflow в соответствующий review status; terminal failure переводит session в `failed` через workflow command и сохраняет только `failure_code`.

- [ ] **Step 5: Запустить recovery tests и commit**

```bash
php artisan test tests/Unit/EstimateGeneration/Observability/SensitiveDiagnosticSanitizerTest.php tests/Feature/EstimateGeneration/Pipeline/PipelineFailureRecoveryTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Observability app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationFailure.php app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php tests/Unit/EstimateGeneration/Observability tests/Feature/EstimateGeneration/Pipeline/PipelineFailureRecoveryTest.php
git commit -m "feat[lk]: нормализованы ошибки AI-сметчика"
```

Expected: PASS.

### Task 7: Перенести генерацию в stage services

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/UnderstandDocumentsStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/UnderstandObjectStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/ExtractQuantitiesStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/PlanWorkItemsStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/MatchNormativesStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/AssembleResourcesStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/ResolvePricesStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/BuildDraftStage.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/ValidateDraftStage.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Replace: `app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php`
- Create: `tests/Unit/EstimateGeneration/Pipeline/PipelineStageBoundaryTest.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationPipelineE2ETest.php`

**Interfaces:**
- Consumes: существующие parser/planner/matcher/pricing/persistence services через узкие stage dependencies.
- Produces: persisted stage outputs и полный pipeline без orchestrator.

- [ ] **Step 1: Написать stage boundary test**

```php
#[Test]
public function every_stage_declares_one_processing_stage_and_returns_versioned_result(): void
{
    foreach (app(PipelineRegistry::class)->ordered() as $stage) {
        $result = $stage->execute($this->fixtureContext($stage->stage()));

        self::assertSame($stage->stage(), $result->stage);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->outputVersion);
    }
}
```

- [ ] **Step 2: Запустить tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Pipeline/PipelineStageBoundaryTest.php`

Expected: FAIL.

- [ ] **Step 3: Реализовать stages по одному**

Каждый stage:

```php
final readonly class UnderstandObjectStage implements PipelineStage
{
    public function __construct(private ObjectUnderstandingService $service) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandObject;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $output = $this->service->handle($context);

        return new PipelineStageResult(
            stage: $this->stage(),
            outputVersion: 'sha256:' . hash('sha256', json_encode($output, JSON_THROW_ON_ERROR)),
            metrics: $output->metrics(),
            warnings: $output->warnings(),
        );
    }
}
```

Создавать узкий application service, если существующий service принимает модель и сам изменяет несколько подсистем. Не переносить весь старый service в stage.

- [ ] **Step 4: Сделать generation job runner-loop**

Job получает только session ID и input version, применяет session/organization rate limiting и вызывает `runNext`. Если остается stage, dispatch-ит следующий job; не выполняет весь pipeline одним process.

- [ ] **Step 5: Запустить E2E и retry tests**

```bash
php artisan test tests/Unit/EstimateGeneration/Pipeline tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationPipelineE2ETest.php
```

Expected: PASS; checkpoint count равен числу стадий, повторный запуск не меняет output counts.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php tests/Unit/EstimateGeneration/Pipeline tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationPipelineE2ETest.php
git commit -m "refactor[lk]: генерация разбита на возобновляемые стадии"
```

### Task 8: Заменить API тяжелого polling компактным snapshot

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/SessionSnapshotData.php`
- Split: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationSessionController.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationActionController.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/routes.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationSnapshotApiTest.php`

**Interfaces:**
- Consumes: checkpoints, processing units, evidence, failures, usage and workflow snapshot.
- Produces: один lightweight `GET .../sessions/{session}/snapshot` с `state_version` и `ETag`.

- [ ] **Step 1: Написать API contract test**

```php
#[Test]
public function unchanged_snapshot_returns_not_modified(): void
{
    $first = $this->actingAsAdmin()->getJson($this->snapshotUrl());
    $etag = $first->headers->get('ETag');

    $second = $this->actingAsAdmin()->withHeader('If-None-Match', $etag)->getJson($this->snapshotUrl());

    $first->assertOk()->assertJsonStructure(['data' => [
        'id', 'status', 'state_version', 'processing_stage', 'processing_progress',
        'available_actions', 'blocking_issues', 'warnings', 'next_action',
        'documents_summary', 'estimate_summary', 'review_summary', 'usage_summary',
    ]]);
    $second->assertStatus(304);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationSnapshotApiTest.php`

Expected: FAIL.

- [ ] **Step 3: Реализовать operational snapshot**

Builder выполняет агрегированные count/sum queries, не загружает pages, facts или package items. ETag: `W/"estimate-generation-{sessionId}-{stateVersion}-{updatedAtUnix}"`.

- [ ] **Step 4: Разделить controller**

`EstimateGenerationSessionController`: index/store/show/snapshot. `EstimateGenerationActionController`: process/generate/retry/cancel/archive/apply. Package/document/review endpoints остаются в собственных controllers. Удалить перенесенные methods из старого controller.

- [ ] **Step 5: Запустить API tests и commit**

```bash
php artisan test tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationSnapshotApiTest.php tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php
git add app/BusinessModules/Addons/EstimateGeneration/Application/Sessions app/BusinessModules/Addons/EstimateGeneration/Http/Controllers app/BusinessModules/Addons/EstimateGeneration/routes.php tests/Feature/EstimateGeneration/Pipeline/EstimateGenerationSnapshotApiTest.php
git commit -m "refactor[lk]: упрощен API статуса AI-сметчика"
```

Expected: PASS.

### Task 9: Удалить orchestrator и закрыть Plan 2

**Files:**
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/DocumentProcessingStatusService.php` если полностью заменен unit/checkpoint contract.
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Modify: `tests/Unit/EstimateGeneration/EstimateGenerationModuleRegistrationTest.php`
- Create: `tests/Architecture/EstimateGenerationPipelineArchitectureTest.php`
- Update: `docs/workflows/ai-estimator.md`

**Interfaces:**
- Consumes: completed stage pipeline.
- Produces: отсутствие legacy orchestration и один execution path.

- [ ] **Step 1: Написать architecture test**

```php
#[Test]
public function module_has_no_legacy_orchestrator_or_direct_stage_chaining(): void
{
    self::assertFileDoesNotExist(app_path(
        'BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php'
    ));

    $source = $this->moduleSource();
    self::assertStringNotContainsString('EstimateGenerationOrchestrator', $source);
}
```

- [ ] **Step 2: Проверить callers через graph/rg**

Run: `rg -n "EstimateGenerationOrchestrator|DocumentProcessingStatusService" app tests`

Expected: только целевые registrations/tests до удаления.

- [ ] **Step 3: Удалить legacy classes и registrations**

Не оставлять wrapper или class alias. Перенести полезные assertions старых tests в stage/E2E tests и удалить tests, проверяющие старую форму orchestration.

- [ ] **Step 4: Выполнить полный gate**

```bash
php artisan test tests/Unit/EstimateGeneration/Pipeline tests/Unit/EstimateGeneration/Observability tests/Architecture/EstimateGenerationPipelineArchitectureTest.php tests/Architecture/EstimateGenerationProductionReadinessTest.php tests/Architecture/EstimateGenerationPlan2CorrectiveContractTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Pipeline app/BusinessModules/Addons/EstimateGeneration/Evidence app/BusinessModules/Addons/EstimateGeneration/Observability app/BusinessModules/Addons/EstimateGeneration/Application app/BusinessModules/Addons/EstimateGeneration/Http --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

PostgreSQL contracts are a separate opt-in deployment gate and are not part of the local DB-less command:

```bash
RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1 php artisan test --group=postgres-contract
```

Expected in an isolated migrated PostgreSQL environment: tenant composite foreign keys, lease/contention, snapshot watermark, source query budget and cascade contracts pass.

- [ ] **Step 5: Проверить legacy absence**

Run: `rg -n "EstimateGenerationOrchestrator|OcrUsageLogger" app/BusinessModules/Addons/EstimateGeneration`

Expected: exit code 1.

- [ ] **Step 6: Commit**

```bash
git add -A app/BusinessModules/Addons/EstimateGeneration tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration tests/Architecture docs/workflows/ai-estimator.md
git commit -m "refactor[lk]: удален старый pipeline AI-сметчика"
```
