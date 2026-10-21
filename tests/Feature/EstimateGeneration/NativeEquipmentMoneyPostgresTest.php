<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeEquipmentMoneyPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_count_scaled_norm_and_resource_money_survive_confirmation_apply_and_ordinary_recalculation(): void
    {
        [$actor, $session, $catalog, $source] = $this->fixture();
        $workKey = $this->installation($session)['key'];
        app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
        $revision = $this->prepareInstallationScope($actor, $session, $source, $workKey);
        $item = $this->installation($session);
        self::assertSame('3', $item['quantity']);
        self::assertSame('count', $item['unit']);
        self::assertSame('100 шт', $item['normative_match']['unit']);
        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $item['normative_match']['unit']);
        self::assertSame('0.01', $factor);
        self::assertSame('0.03', (string) BigDecimal::of($item['quantity'])->multipliedBy($factor)->strippedOfTrailingZeros());
        self::assertSame($source->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertSame('0.3', $item['labor'][0]['quantity']);
        self::assertSame('10', $item['labor'][0]['quantity_per_unit']);
        self::assertSame('0.1', (string) BigDecimal::of($item['labor'][0]['quantity_per_work_unit'])->strippedOfTrailingZeros());
        self::assertSame([], $item['materials']);
        self::assertSame([], $item['machinery']);
        self::assertSame('30.00', $item['total_cost'], json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame('30.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('36.60', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertSame($catalog['norm_id'], $item['normative_match']['norm_id']);
        self::assertSame($catalog['context']['normative_dataset_version'], $item['normative_match']['dataset_version']['version_key']);
        self::assertSame($catalog['resource_id'], $item['labor'][0]['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $item['labor'][0]['normative_ref']['price_id']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $this->confirmationInput($session, $revision));
        $published = app(ApplyGeneratedEstimate::class)->handle(new ApplyGeneratedEstimateCommand((int) $session->id,
            (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor));
        $estimate = Estimate::query()->findOrFail($published->estimateId);
        $this->assertPersistedMoney($estimate, $source, $catalog);
        app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
        $this->assertPersistedMoney($estimate->fresh(), $source, $catalog);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('missingCatalogInputs')]
    public function test_missing_norm_or_price_cannot_confirm_native_count_money(bool $withNorm, bool $withPrice): void
    {
        [$actor, $session, $catalog, $source] = $this->fixture($withNorm, $withPrice);
        $workKey = $this->installation($session)['key'];
        $this->prepareInstallationScope($actor, $session, $source, $workKey);
        if ($withNorm) {
            try {
                app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                    $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
                self::fail('A norm without a priced resource was selected.');
            } catch (ValidationException) {
                self::assertNull($this->installation($session)['total_cost']);
            }
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        self::assertSame([], $revision['result']['coverage']['missing_requirements']);
        self::assertFalse($revision['result']['can_confirm_for_apply']);
        self::assertNull($this->installation($session)['total_cost']);
        try {
            app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                $this->confirmationInput($session, $revision));
            self::fail('Missing normative money was confirmed.');
        } catch (ValidationException) {
            self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        }
        $current = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 100)['snapshot'];
        $original = array_values(array_filter($current->facts, static fn (Fact $fact): bool => $fact->id === $source->id));
        self::assertCount(1, $original);
        self::assertSame('3', $original[0]->value);
        self::assertSame('document', $original[0]->origin);
        self::assertSame(0, Estimate::query()->where('project_id', $session->project_id)->count());
        Http::assertNothingSent();
    }

    public static function missingCatalogInputs(): array
    {
        return ['missing norm' => [false, true], 'missing labor price' => [true, false]];
    }

    private function prepareInstallationScope(User $actor, EstimateGenerationSession $session, Fact $source, string $workKey): array
    {
        app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'normative',
                'selected_work_item_keys' => [$workKey], 'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
                'scope_reason' => 'Проверяется только монтаж по явной спецификации оборудования.']);
        app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        $target = ['state_version' => $session->fresh()->state_version, 'entity_id' => $source->entityId,
            'section' => 'equipment', 'requirement' => 'equipment_specification'];
        $sources = app(GetEvaluationRequirementSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $target);
        self::assertCount(1, $sources['sources']);
        app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            [...$target, 'request_id' => (string) Str::uuid(), 'disposition' => 'document_verified',
                'document_fact_id' => $sources['sources'][0]['fact_id'],
                'reason' => 'Проверена текущая спецификация котла и её применимость к выбранному монтажу.']);
        foreach (['equipment_purchase', 'delivery', 'commissioning'] as $requirement) {
            app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                    'entity_id' => $source->entityId, 'section' => 'equipment', 'requirement' => $requirement,
                    'disposition' => 'not_applicable', 'reason' => 'Проверяется только монтаж оборудования заказчика; этот этап вне состава сметы.']);
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());

        return $revision;
    }

    private function confirmationInput(EstimateGenerationSession $session, array $revision): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];
    }

    private function assertPersistedMoney(Estimate $estimate, Fact $source, array $catalog): void
    {
        self::assertSame('30.00', $estimate->total_amount);
        self::assertSame('36.60', $estimate->total_amount_with_vat);
        self::assertSame(1, $estimate->items()->whereNull('parent_work_id')->count());
        self::assertSame(1, $estimate->items()->whereNotNull('parent_work_id')->count());
        $parent = $estimate->items()->whereNull('parent_work_id')->sole();
        $child = $estimate->items()->whereNotNull('parent_work_id')->sole();
        self::assertSame('3', (string) BigDecimal::of($parent->quantity)->strippedOfTrailingZeros());
        self::assertSame('0.3', (string) BigDecimal::of($parent->labor_hours)->strippedOfTrailingZeros());
        self::assertSame('0', (string) BigDecimal::of($parent->machinery_hours)->strippedOfTrailingZeros());
        self::assertSame('30.00', $parent->total_amount);
        self::assertSame('0.3', (string) BigDecimal::of($child->quantity)->strippedOfTrailingZeros());
        self::assertSame('30.00', $child->total_amount);
        self::assertSame('0.1', (string) BigDecimal::of($child->metadata['quantity_per_unit'])->strippedOfTrailingZeros());
        self::assertSame($catalog['norm_id'], $child->metadata['normative_ref']['norm_id']);
        self::assertSame($catalog['resource_id'], $child->metadata['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $child->metadata['normative_ref']['price_id']);
        $resource = $parent->resources()->sole();
        self::assertSame('0.1', (string) BigDecimal::of($resource->quantity_per_unit)->strippedOfTrailingZeros());
        self::assertSame('0.3', (string) BigDecimal::of($resource->total_quantity)->strippedOfTrailingZeros());
        self::assertSame('100', (string) BigDecimal::of($resource->unit_price)->strippedOfTrailingZeros());
        self::assertSame('30.00', $resource->total_amount);
        self::assertSame('22', $parent->metadata['generated_source_money']['money']['vat_rate']);
        self::assertSame('6.60', $parent->metadata['generated_source_money']['money']['vat_amount']);
        self::assertSame($source->id, $parent->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
    }

    private function fixture(bool $withNorm = true, bool $withPrice = true): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        foreach ([['Штука', 'шт', 'piece'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $this->catalog($withNorm, $withPrice);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
                'price_policy' => 'normative', 'regional_context' => $catalog['context']]]);
        app(SaveSessionPricingSource::class)->handle($actor, (int) $project->id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
                    'delivery' => 'outside_scope', 'basis' => 'Синтетическая проверка числового пересчёта; доставка вне выбранного состава.']]);
        $source = $this->publishCount($session);
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $organization->id, (int) $project->id, (int) $session->id);
        $quantity = (new WorkItemQuantityMapper)->mapForEntity('equipment.install', $preview['quantities'], $source->entityId);
        self::assertNotNull($quantity);
        self::assertSame('3', $quantity->amount);
        $local = ['key' => 'equipment', 'scope_type' => 'engineering', 'title' => 'Оборудование'];
        $section = ['key' => 'equipment', 'title' => 'Оборудование', 'construction_part' => 'equipment'];
        $analysis = ['object' => ['object_type' => 'residential'], 'regional_context' => $catalog['context'],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'document_context' => ['canonical_building_quantities' => [$quantity->toArray()]]];
        $items = app(NormativeWorkItemPlannerService::class)->build($local, $section, $analysis, projectReferences: []);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft = $this->emptyDraft($session, $catalog);
        $draft['local_estimates'] = [[...$local, 'sections' => [[...$section, 'work_items' => $items]]]];
        $draft = (new EquipmentWorkItemExpander)->expand($draft, $preview['quantities'], 50, EquipmentEstimateScope::select($snapshot, $decisions));
        $items = array_map(fn (array $row): array => (new CanonicalWorkItemQuantityBinder)->bind($row, $preview['quantities']),
            $draft['local_estimates'][0]['sections'][0]['work_items']);
        $draft['local_estimates'][0]['sections'][0]['work_items'] = $items;
        $installation = array_values(array_filter($items, static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
        self::assertCount(1, $installation);
        $item = $installation[0];
        self::assertSame('3', $quantity->amount);
        self::assertSame('3', $item['quantity']);
        self::assertSame($source->entityId, $item['metadata']['scope_entity_id']);
        self::assertSame($source->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertArrayNotHasKey('specialization_scenario', $item);
        self::assertArrayNotHasKey('work_intent', $item);
        $session->refresh()->update(['status' => 'estimate_review_required',
            'draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);

        return [$actor, $session, $catalog, $source];
    }

    private function emptyDraft(EstimateGenerationSession $session, array $catalog): array
    {
        return ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'regional_context' => $catalog['context'], 'object_profile' => ['object_type' => 'residential'],
            'evaluation_policy' => ['selected_sections' => ['equipment'], 'price_policy' => 'normative']];
    }

    private function installation(EstimateGenerationSession $session): array
    {
        $rows = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'];
        $installation = array_values(array_filter($rows,
            static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
        self::assertCount(1, $installation);

        return $installation[0];
    }

    private function publishCount(EstimateGenerationSession $session): Fact
    {
        $version = 'sha256:'.hash('sha256', 'synthetic-scaled-unit2026Q4-native-boiler-count3');
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $session->user_id,
            'filename' => 'synthetic-scaled-unit2026Q4.xlsx', 'mime_type' => 'application/octet-stream',
            'source_version' => $version, 'checksum_sha256' => substr($version, 7), 'status' => 'ready']);
        $page = EstimateGenerationDocumentPage::query()->create(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'document_id' => $document->id, 'page_number' => 1, 'source_version' => $version]);
        $context = new DocumentUnitExecutionContext(1, (int) $session->organization_id, (int) $session->project_id, (int) $session->id,
            (int) $document->id, DocumentUnitType::SpreadsheetSheet, 1, $version, [], $document->filename,
            'application/octet-stream', $document->filename, 'claim-token', 1, 0, 'draft', (int) $page->id);
        $cells = [];
        foreach ([['equipment:boiler', 'equipment_specification', 'Электрический котёл отопления массой до 30 кг.', ''],
            ['equipment:boiler', 'equipment_count', '3', 'шт']] as $index => $row) {
            foreach ($row as $column => $value) {
                $cells[] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Оборудование',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'],
                ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']], 'cells' => $cells]);
        self::assertNotNull($publication);
        app(ProjectModelEvidenceWriter::class)->writeArbitration($publication->claims, $publication->decisions,
            $context->documentId, 1, $publication->numericSources);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100)['snapshot'];
        $counts = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'));
        self::assertCount(1, $counts);
        $source = $counts[0];
        self::assertSame('3', $source->value);
        self::assertSame('шт', $source->unit);
        self::assertSame('document', $source->origin);
        $proofs = array_values(array_filter($snapshot->evidence, static fn ($evidence): bool => in_array($evidence->id, $source->evidenceIds, true)));
        self::assertCount(1, $proofs);
        self::assertTrue((new NativeNumericFactProof)->matches($source, $proofs[0]));
        self::assertSame('xlsx:sheet:Оборудование!C3', $proofs[0]->nativeReference);

        return $source;
    }

    private function catalog(bool $withNorm, bool $withPrice): array
    {
        $now = now();
        $suffix = strtolower((string) Str::ulid());
        $key = 'synthetic-scaled-unit2026Q4-'.$suffix;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => 'SYNTHETIC-'.$suffix,
            'name' => 'Synthetic scaled-unit numeric fixture 2026 Q4', 'norm_type' => 'gesn', 'source_file' => 'synthetic-scaled-unit2026Q4', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '20', 'name' => 'Synthetic equipment',
            'path' => '20/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = $withNorm ? DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '20-99-999-01',
            'section_code' => '20', 'section_name' => 'Synthetic equipment', 'section_id' => $section,
            'name' => 'Монтаж оборудования по требованиям изготовителя',
            'work_composition' => json_encode(['Установка и крепление оборудования по согласованной спецификации'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'unit' => '100 шт', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]) : null;
        $resourceCode = 'R08-LABOR-'.$suffix;
        $resource = $withNorm ? DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '10.000000',
            'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]) : null;
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'R08-'.substr($suffix, -10), 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = $withPrice ? DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices,
            'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '100.0000',
            'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]) : null;
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resource, 'price_id' => $price, 'context' => ['region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4,
            'version_key' => $key, 'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
