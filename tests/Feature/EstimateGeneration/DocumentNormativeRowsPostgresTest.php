<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCalculationResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;

final class DocumentNormativeRowsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_actual_plan_stage_binds_six_residential_assets_to_persisted_accepted_count_assumptions(): void
    {
        Http::preventStrayRequests();
        [$context, $session] = $this->fixture();
        $actor = User::query()->findOrFail($session->user_id);
        $actor->update(['current_organization_id' => $session->organization_id, 'is_active' => true]);
        $actor->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session->update(['state_version' => 0, 'status' => 'draft', 'input_payload' => [
            ...$session->input_payload, 'object_type' => 'house', 'description' => 'Строительство жилого дома',
            'evaluation_mode' => 'universal',
        ]]);
        $entities = [];
        foreach (['bath', 'basin', 'wc', 'kitchen-sink', 'geyser-150-l', 'switchboard'] as $key) {
            $entities[] = ['key' => $key, 'type' => 'equipment', 'parameters' => [
                'equipment_count' => ['value' => '1', 'unit' => 'count', 'basis' => 'assumption'],
            ]];
        }
        $entities[] = ['key' => 'radiators', 'type' => 'radiator', 'parameters' => [
            'equipment_count' => ['value' => '3', 'unit' => 'count', 'basis' => 'assumption'],
        ]];
        app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => $entities]);
        $session->refresh();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $sourceVersion = $capture['snapshot']->entities[0]->sourceVersion;
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$scope, $sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), [], []]));
        self::assertTrue($models->replaceCompleteness(...[...$scope, $sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), 'rules:v1', str_repeat('d', 64), [], []]));
        $derived = app(CurrentProjectDerivedQuantityService::class)->derive(...$scope);
        self::assertCount(6, $derived['context']['equipment_entity_ids']);
        $countFacts = [];
        foreach ($capture['snapshot']->facts as $fact) {
            if ($fact->type === 'equipment_count') {
                self::assertSame('user_assumption', $fact->origin);
                $countFacts[$fact->entityId] = $fact->id;
            }
        }
        self::assertCount(7, $countFacts);
        $session->update(['status' => 'generating']);
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['equipment'], 'price_policy' => 'catalog', 'profile_id' => 'construction'];
        $graph = PipelineDefinitionGraph::standard();
        $model = new class implements EstimateComposerModel
        {
            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

                return ['work_intents' => array_map(static fn (array $candidate): array => [
                    'kind' => 'existing', 'candidate_id' => $candidate['candidate_id'], 'work_key' => null,
                    'name' => null, 'derived_quantity_id' => null, 'source_fact_ids' => $candidate['source_fact_ids'],
                    'technology_package_candidate' => $candidate['technology_package_candidate'],
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                ], $input->candidates)];
            }
        };
        $this->app->instance(RunEstimateComposer::class, new RunEstimateComposer(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-6-luna'));
        $this->app->instance(StageResultFactory::class, new StageResultFactory(new InMemoryPipelineArtifactStore, $graph));
        $quantityOutput = ['quantity_learning_hints' => [], 'quantity_coverage_warnings' => $derived['warnings'],
            'building_quantities' => (new QuantityCalculationResult($derived['quantities'], []))->toArray(),
            'stage6_generation_context' => $derived['context']];
        $planContext = $this->planContext($session, $analysis, $graph, $quantityOutput);
        $result = app(PlanWorkItemsStage::class)->execute($planContext);
        $items = [];
        foreach ($result->transientData['local_estimates'] as $local) {
            foreach ($local['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['residential_equipment_candidate'] ?? null) === true) {
                        $items[] = $item;
                    }
                }
            }
        }
        self::assertCount(13, $items);
        $identities = [];
        $evidenceIds = [];
        foreach ($items as $item) {
            self::assertTrue($item['skip_normative_matching']);
            if ($item['metadata']['composition_work_key'] === 'equipment.delivery') {
                self::assertArrayNotHasKey('entity_id', $item);
                self::assertArrayNotHasKey('quantity_evidence', $item);
                self::assertArrayNotHasKey('quantity', $item);

                continue;
            }
            $entity = $item['entity_id'];
            self::assertContains($entity, $derived['context']['equipment_entity_ids']);
            self::assertSame('1', $item['quantity']);
            self::assertSame('estimated', $item['quantity_evidence']['source']);
            self::assertSame($entity, $item['quantity_evidence']['formula_inputs']['entity_id']);
            self::assertSame($capture['token'], $item['quantity_evidence']['model_version']);
            self::assertContains($countFacts[$entity], array_column($item['source_refs'], 'fact_id'));
            self::assertContains($countFacts[$entity], $item['quantity_evidence']['assumptions']);
            self::assertTrue($item['metadata']['requires_specification']);
            self::assertTrue($item['metadata']['requires_technology_confirmation']);
            foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resourceType) {
                self::assertSame([], $item[$resourceType]);
            }
            self::assertNull($item['total_cost']);
            $node = app(EvidenceRepository::class)->node(...[...$scope, $item['quantity_evidence_id']]);
            self::assertNotNull($node);
            self::assertSame($planContext->baseInputVersion, $node->sourceVersion);
            self::assertSame($node->fingerprint, $item['quantity_evidence_fingerprint']);
            self::assertSame($node->sourceVersion, $item['quantity_evidence_source_version']);
            $identities[] = $item['metadata']['composition_work_key'].'@'.$entity;
            $evidenceIds[] = $node->id;
        }
        self::assertCount(12, array_unique($identities));
        self::assertCount(12, array_unique($evidenceIds));
        $workflow = $this->createMock(NormativeMatchingWorkflow::class);
        $workflow->expects(self::never())->method('match');
        $matcher = $this->createMock(ResourceAssemblyService::class);
        $matcher->expects(self::never())->method('assembleFromDecision');
        $results = app(StageResultFactory::class);
        $matching = new MatchNormativesStage($matcher, $workflow, new NormativeWorkIntentFactory, $results);
        foreach (['not_required', 'pinned'] as $pinStatus) {
            $plan = $result->transientData;
            $plan['normative_context_pin'] = ['status' => $pinStatus, 'dataset_version' => 'test-equipment-dataset', 'applicability_date' => '2026-10-20'];
            $output = $results->make($planContext, ProcessingStage::PlanWorkItems, $plan)->output;
            self::assertNotNull($output);
            $dependencies = [ProcessingStage::PlanWorkItems->value => $output->version];
            $matchContext = new PipelineContext($planContext->sessionId, $planContext->organizationId, $planContext->projectId,
                $planContext->stateVersion, PipelineInputVersion::for($graph->get(ProcessingStage::MatchNormatives), $planContext->baseInputVersion, $dependencies),
                $planContext->sessionStatus, priorOutputs: new PipelinePriorOutputs([ProcessingStage::PlanWorkItems->value => $output],
                    [ProcessingStage::PlanWorkItems->value => $plan]), generationAttemptId: $planContext->generationAttemptId,
                baseInputVersion: $planContext->baseInputVersion, stage: ProcessingStage::MatchNormatives, dependencyVersions: $dependencies);
            $matched = $matching->execute($matchContext);
            self::assertSame($plan['local_estimates'], $matched->transientData['local_estimates']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('observedDocumentRows')]
    public function test_actual_plan_stage_keeps_observed_amounts_for_review_without_accepting_quantity_evidence(array $quantities, string $name, string $unit, string $package): void
    {
        [$context, $session] = $this->fixture();
        $session->input_payload = [...$session->input_payload, 'object_type' => 'house', 'description' => 'Строительство жилого дома'];
        $session->save();
        foreach ($quantities as $quantity) {
            $this->document($session, 'ФЕР 08-02-001-01 '.$name.' '.$quantity.' '.$unit);
        }
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures'], 'price_policy' => 'catalog', 'profile_id' => 'construction'];
        $graph = PipelineDefinitionGraph::standard();
        $model = new class implements EstimateComposerModel
        {
            public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

                return ['work_intents' => array_map(static fn (array $candidate): array => [
                    'kind' => 'existing', 'candidate_id' => $candidate['candidate_id'], 'work_key' => null,
                    'name' => null, 'derived_quantity_id' => null, 'source_fact_ids' => $candidate['source_fact_ids'],
                    'technology_package_candidate' => $candidate['technology_package_candidate'],
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                ], $input->candidates)];
            }
        };
        $this->app->instance(RunEstimateComposer::class, new RunEstimateComposer(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-6-luna'));
        $this->app->instance(StageResultFactory::class, new StageResultFactory(new InMemoryPipelineArtifactStore, $graph));
        $planContext = $this->planContext($session, $analysis, $graph);
        $result = app(PlanWorkItemsStage::class)->execute($planContext);
        $references = $this->references($result->transientData);
        self::assertCount(count($quantities), $references);
        self::assertSame(array_map(static fn ($value): ?string => $value === null ? null : (string) $value, $quantities), array_column($references, 'quantity'));
        $packages = array_column($result->transientData['local_estimates'], null, 'key');
        self::assertCount(count($quantities), $this->references(['local_estimates' => [$packages[$package]]]));
        foreach ($references as $index => $item) {
            self::assertSame('quantity_review', $item['item_type']);
            self::assertSame('quantity_review_required', $item['pricing_blocker']);
            self::assertArrayNotHasKey('quantity_evidence', $item);
            self::assertArrayNotHasKey('quantity_evidence_id', $item);
            self::assertSame($quantities[$index] === null ? null : 0, $item['total_cost']);
            self::assertNotEmpty($item['source_refs'][0]['source_version']);
        }
        if (in_array(null, $quantities, true)) {
            $unknownReferenceKeys = array_column(array_filter($references, static fn (array $item): bool => $item['quantity'] === null), 'key');
            self::assertNotNull($planContext->baseInputVersion);
            $draft = [...$result->transientData, 'source_input_version' => $planContext->baseInputVersion];
            foreach ($draft['local_estimates'] as $localIndex => $local) {
                foreach ($local['sections'] as $sectionIndex => $section) {
                    foreach ($section['work_items'] as $itemIndex => $item) {
                        if ($item['item_type'] === 'quantity_review' && $item['quantity'] === null) {
                            $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = app(EstimatePricingService::class)->price([$item])[0];
                        }
                    }
                }
            }
            foreach ([false, true] as $universal) {
                $persistedDraft = $universal ? (new UniversalDraftProjector)->project($draft, static fn (array $item): bool => false) : $draft;
                app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $persistedDraft, $planContext->baseInputVersion);
                $packageIds = EstimateGenerationPackage::query()->where('session_id', $session->id)->pluck('id');
                $storedItems = EstimateGenerationPackageItem::query()->whereIn('package_id', $packageIds)->get()->keyBy('logical_key');
                foreach ($this->references($persistedDraft) as $reference) {
                    if ($universal && in_array($reference['key'], $unknownReferenceKeys, true)) {
                        self::assertSame('priced_work', $reference['item_type']);
                        self::assertTrue($reference['metadata']['requires_quantity_confirmation']);
                    }
                    $stored = $storedItems->get($reference['key']);
                    self::assertInstanceOf(EstimateGenerationPackageItem::class, $stored);
                    if ($reference['quantity'] === null) {
                        self::assertNull($stored->quantity);
                        self::assertNull($stored->pricing_finalized_at);
                        self::assertNull($stored->quantity_evidence_id);
                        $payload = (new EstimateGenerationPackagePresenter)->item($stored);
                        self::assertNull($payload['quantity']);
                        foreach (['unit_price', 'direct_cost', 'overhead_cost', 'profit_cost', 'total_cost'] as $field) {
                            self::assertNull($stored->getRawOriginal($field), $field);
                            self::assertNull($payload[$field], $field);
                        }
                        self::assertSame($reference['source_refs'], $payload['source_refs']);
                        self::assertSame($reference['pricing_blocker'], $payload['pricing_blocker']);
                    }
                }
                if ($universal) {
                    $this->confirmUniversalDocumentQuantity($session, $persistedDraft, $unknownReferenceKeys);
                }
            }
            $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_15_001600_allow_unknown_estimate_review_costs.php');
            try {
                $migration->down();
                self::fail('Unknown costs must prevent a destructive rollback.');
            } catch (\RuntimeException $exception) {
                self::assertSame('estimate_generation.unknown_review_cost_rollback_unsafe', $exception->getMessage());
            }
            self::assertTrue(EstimateGenerationPackageItem::query()->whereIn('package_id', $packageIds)->whereNull('total_cost')->exists());
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function observedDocumentRows(): array
    {
        return [
            'repeated normative reference' => [[10, 20], 'Кладка стен', 'м2', 'walls'],
            'single normative reference' => [[10], 'Кладка стен', 'м2', 'walls'],
            'missing and measured quantities' => [[null, 12], 'Кладка стен', 'м2', 'walls'],
            'two missing quantities' => [[null, null], 'Кладка стен', 'м2', 'walls'],
            'missing quantity and unit' => [[null], 'Кладка стен', '', 'walls'],
            'window reference' => [[10], 'Монтаж окон', 'шт', 'openings'],
        ];
    }

    #[DataProvider('profileDocumentRows')]
    public function test_real_gateway_parser_and_compiler_route_rows_to_profile_packages(string $objectType, string $name, string $unit, string $package, string $selectedSection, ?string $quantityKey = null): void
    {
        [$context, $session] = $this->fixture();
        $session->input_payload = [...$session->input_payload, 'object_type' => $objectType];
        $session->save();
        $document = $this->document($session, 'ФЕР 08-02-001-01 '.$name.' 10 '.$unit);
        if ($objectType === 'custom') {
            self::assertNotNull($quantityKey);
            EstimateGenerationQuantityTakeoff::query()->create([
                'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
                'session_id' => $session->id, 'document_id' => $document->id, 'scope_key' => $quantityKey,
                'name' => $name, 'unit' => $unit, 'quantity' => 10, 'confidence' => 0.8,
                'source_element_ids' => [], 'work_intent' => ['quantity_key' => $quantityKey],
                'source_refs' => [['document_id' => $document->id, 'source_version' => $document->source_version]],
                'normalized_payload' => ['quantity_key' => $quantityKey],
            ]);
        }
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => [$selectedSection]];
        $draft = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
        $packages = array_column($draft['local_estimates'], null, 'key');
        self::assertArrayHasKey($package, $packages);
        $references = $this->references($draft);
        self::assertCount(1, $references);
        self::assertSame($references, $this->references(['local_estimates' => [$packages[$package]]]));
        self::assertSame('10', $references[0]['quantity']);
        self::assertSame((int) $document->id, $references[0]['source_refs'][0]['document_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function profileDocumentRows(): array
    {
        return [
            'warehouse power supply' => ['warehouse', 'Прокладка электрического кабеля', 'м', 'power_supply', 'electricity'],
            'industrial power supply' => ['industrial', 'Прокладка электрического кабеля', 'м', 'power_supply', 'electricity'],
            'document electrical' => ['custom', 'Прокладка электрического кабеля', 'м', 'custom-electrical', 'electricity', 'electrical.power_lines'],
            'document walls' => ['custom', 'Кладка наружных стен', 'м2', 'custom-walls', 'structures', 'walls.external_volume'],
            'document openings' => ['custom', 'Монтаж окон', 'шт', 'custom-openings', 'structures', 'openings.windows'],
            'document finishing' => ['custom', 'Чистовое покрытие пола', 'м2', 'custom-finishing', 'finishing', 'finish.floor'],
            'house sewerage selected' => ['house', 'Прокладка труб канализации', 'м', 'sewerage', 'sewerage'],
            'document sewerage' => ['custom', 'Прокладка труб канализации', 'м', 'custom-sewerage', 'sewerage', 'sewerage.pipe'],
            'document water supply' => ['custom', 'Прокладка труб водоснабжения', 'м', 'custom-plumbing', 'water_supply', 'plumbing.pipe'],
            'document heating' => ['custom', 'Прокладка труб отопления', 'м', 'custom-heating', 'heating', 'heating.pipe'],
            'document ventilation' => ['custom', 'Монтаж вентиляционных воздуховодов', 'м', 'custom-ventilation', 'ventilation', 'ventilation.air_exchange'],
            'mixed water supply' => ['mixed_warehouse_office', 'Прокладка труб водоснабжения', 'м', 'plumbing', 'water_supply'],
            'warehouse sewerage selected' => ['warehouse', 'Прокладка труб канализации', 'м', 'sewerage', 'sewerage'],
            'industrial sewerage selected' => ['industrial', 'Прокладка труб канализации', 'м', 'sewerage', 'sewerage'],
            'mixed sewerage selected' => ['mixed_warehouse_office', 'Прокладка труб канализации', 'м', 'sewerage', 'sewerage'],
            'house facade' => ['house', 'Монтаж фасадных панелей', 'м2', 'facade', 'structures'],
            'warehouse facade' => ['warehouse', 'Монтаж фасадных панелей', 'м2', 'envelope', 'structures'],
            'industrial facade' => ['industrial', 'Монтаж фасадных панелей', 'м2', 'envelope', 'structures'],
            'mixed windows' => ['mixed_warehouse_office', 'Монтаж окон', 'шт', 'entrance_group', 'structures'],
            'mixed doors' => ['mixed_warehouse_office', 'Монтаж дверей', 'шт', 'entrance_group', 'structures'],
            'mixed gates' => ['mixed_warehouse_office', 'Монтаж ворот', 'шт', 'gates', 'structures'],
            'warehouse sandwich panels' => ['warehouse', 'Монтаж стеновых сэндвич-панелей', 'м2', 'envelope', 'structures'],
            'industrial sandwich panels' => ['industrial', 'Монтаж стеновых сэндвич-панелей', 'м2', 'envelope', 'structures'],
            'mixed sandwich panels' => ['mixed_warehouse_office', 'Монтаж стеновых сэндвич-панелей', 'м2', 'envelope', 'structures'],
            'warehouse wall panels' => ['warehouse', 'Монтаж стеновых панелей', 'м2', 'envelope', 'structures'],
            'industrial wall panels' => ['industrial', 'Монтаж стеновых панелей', 'м2', 'envelope', 'structures'],
            'mixed wall panels' => ['mixed_warehouse_office', 'Монтаж стеновых панелей', 'м2', 'envelope', 'structures'],
            'mixed internal panels' => ['mixed_warehouse_office', 'Монтаж внутренних стеновых панелей', 'м2', 'office_partitions', 'structures'],
        ];
    }

    public function test_selected_water_systems_keep_each_source_once_and_preserve_unselected_rows_for_review(): void
    {
        foreach (['warehouse', 'industrial', 'mixed_warehouse_office'] as $type) {
            foreach ([['water_supply'], ['sewerage'], ['water_supply', 'sewerage']] as $selected) {
                [$context, $session] = $this->fixture();
                $session->input_payload = [...$session->input_payload, 'object_type' => $type];
                $session->save();
                $document = $this->document($session, "ФЕР 08-02-001-01 Прокладка труб водоснабжения 10 м\n\nФЕР 08-02-001-01 Прокладка труб канализации 20 м");
                $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
                $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
                $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => $selected];
                $draft = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
                $packages = array_column($draft['local_estimates'], null, 'key');
                self::assertCount(2, $this->references($draft));
                foreach (['water_supply' => ['plumbing', '10'], 'sewerage' => ['sewerage', '20']] as $scope => [$key, $quantity]) {
                    $selectedSystem = in_array($scope, $selected, true);
                    if (! $selectedSystem) {
                        self::assertArrayNotHasKey($key, $packages);
                    }
                    $rows = $this->references(['local_estimates' => [$packages[$selectedSystem ? $key : 'document_review']]]);
                    self::assertCount(1, $rows);
                    self::assertSame($quantity, $rows[0]['quantity']);
                    self::assertSame($selectedSystem ? 'priced_work' : 'review_note', $rows[0]['item_type']);
                    self::assertSame($selectedSystem ? 'not_calculated' : 'not_applicable', $rows[0]['pricing_status']);
                    self::assertArrayNotHasKey('quantity_evidence', $rows[0]);
                    self::assertArrayNotHasKey('quantity_evidence_id', $rows[0]);
                    self::assertSame((int) $document->id, $rows[0]['source_refs'][0]['document_id']);
                }
                self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            }
        }
    }

    #[DataProvider('generationModes')]
    public function test_real_saved_mixed_opening_takeoffs_are_used_once_in_their_specialized_package(string $mode): void
    {
        $universal = str_starts_with($mode, 'universal');
        $explicitSanitaryPackage = $mode === 'universal_explicit_sanitary';
        [$context, $session] = $this->fixture();
        $session->input_payload = [...$session->input_payload, 'object_type' => 'mixed_warehouse_office'];
        $session->save();
        $document = $this->document($session, 'Ведомость окон, дверей, ворот и погрузочных узлов');
        $quantities = ['openings.windows' => 10, 'openings.doors' => 20, 'warehouse.gates' => 3, 'warehouse.loading_nodes' => 4];
        $quantities += ['lighting.lines' => 40, 'warehouse.low_current' => 60, 'office.network_points' => 6, 'server.room' => 7, 'plumbing.pipe' => 64];
        $quantities += ['sanitary.points' => 2, 'sanitary.showers' => 3, 'sanitary.toilets' => 4, 'sanitary.washbasins' => 5];
        if (! $universal || $explicitSanitaryPackage) {
            $quantities += ['sanitary.tile' => 10, 'sanitary.waterproofing' => 20, 'sanitary.floor_tile' => 30];
        }
        foreach ($quantities as $key => $quantity) {
            EstimateGenerationQuantityTakeoff::query()->create([
                'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
                'session_id' => $session->id, 'document_id' => $document->id, 'scope_key' => $key,
                'name' => $key, 'unit' => in_array($key, ['sanitary.tile', 'sanitary.waterproofing', 'sanitary.floor_tile'], true) ? 'м2' : (in_array($key, ['lighting.lines', 'warehouse.low_current', 'plumbing.pipe'], true) ? 'м' : 'шт'), 'quantity' => $quantity, 'confidence' => 0.8,
                'source_element_ids' => [], 'work_intent' => ['quantity_key' => $key],
                'source_refs' => [['document_id' => $document->id, 'source_version' => $document->source_version]],
                'normalized_payload' => ['quantity_key' => $key, 'review_required' => false],
            ]);
        }
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $analysis['planning_signals']['generation_mode'] = $universal ? 'ai_assisted' : $mode;
        if ($universal) {
            $analysis['evaluation_policy'] = ['mode' => 'universal', 'selected_sections' => ['structures', 'electricity', 'water_supply', 'sewerage', ...($explicitSanitaryPackage ? ['sanitary_rooms'] : [])]];
        }
        $draft = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
        $matches = [];
        foreach ($draft['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    $key = $item['quantity_formula'] ?? null;
                    if (is_string($key) && array_key_exists($key, $quantities)) {
                        $matches[$key][] = ['package' => $estimate['key'], 'item' => $item];
                    }
                }
            }
        }
        foreach ($quantities as $key => $quantity) {
            self::assertCount(1, $matches[$key] ?? [], $key);
            $owner = match (true) {
                str_starts_with($key, 'openings.') => 'entrance_group',
                str_starts_with($key, 'sanitary.') => ! $universal || $explicitSanitaryPackage ? 'sanitary_rooms' : 'plumbing',
                $key === 'lighting.lines' => 'lighting',
                in_array($key, ['warehouse.low_current', 'office.network_points'], true) => 'low_current',
                $key === 'server.room' => 'server_room',
                $key === 'plumbing.pipe' => $universal ? 'plumbing' : 'water_sewerage',
                default => 'gates',
            };
            self::assertSame($owner, $matches[$key][0]['package']);
            self::assertSame((string) $quantity, $matches[$key][0]['item']['quantity']);
            self::assertSame((int) $document->id, $matches[$key][0]['item']['source_refs'][0]['document_id']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function generationModes(): array
    {
        return [['universal'], ['strict'], ['ai_assisted'], ['universal_explicit_sanitary']];
    }

    public function test_real_source_observation_is_included_once_across_compatible_house_packages(): void
    {
        [$context, $session] = $this->fixture();
        $session->input_payload = [...$session->input_payload, 'object_type' => 'house',
            'description' => 'Строительство жилого дома', 'generation_mode' => 'ai_assisted'];
        $session->save();
        $text = "ФЕР 08-02-001-01 Прокладка электрического кабеля 10 м\n\nФЕР 08-02-001-01 Прокладка электрического кабеля 20 м";
        $first = $this->document($session, $text);
        $second = $this->document($session, $text."\n");
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $payload = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
        self::assertContains('electrical', array_column($payload['local_estimates'], 'key'));
        self::assertContains('lighting', array_column($payload['local_estimates'], 'key'));
        $electrical = array_column($payload['local_estimates'], null, 'key')['electrical'];
        self::assertCount(4, $this->references(['local_estimates' => [$electrical]]));
        $references = [];
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference') {
                        $references[] = $item;
                    }
                }
            }
        }
        self::assertCount(4, $references);
        self::assertSame(['10', '20', '10', '20'], array_column($references, 'quantity'));
        self::assertSame([$first->id, $first->id, $second->id, $second->id], array_map(static fn (array $item): int => $item['source_refs'][0]['document_id'], $references));
        self::assertSame([1, 3, 1, 3], array_map(static fn (array $item): int => $item['source_refs'][0]['line_number'], $references));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_unknown_scope_stays_visible_in_package_warnings_without_becoming_priced_work(): void
    {
        [$context, $session] = $this->fixture();
        $document = $this->document($session, 'ФЕР 08-02-001-01 Неопределённая специальная работа 10 м2');
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $draft = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
        $package = array_column($draft['local_estimates'], null, 'key')['document_review'];
        $rows = $this->references(['local_estimates' => [$package]]);
        self::assertCount(1, $rows);
        self::assertSame('review_note', $rows[0]['item_type']);
        self::assertSame('not_applicable', $rows[0]['pricing_status']);
        $summary = (new \App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter)->summary(
            new \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage([
                'key' => 'document_review', 'title' => $package['title'], 'metadata' => ['coverage_warnings' => $package['coverage_warnings']], 'totals' => [],
            ]));
        $message = $summary['coverage_warnings'][0]['message'];
        self::assertStringContainsString('Неопределённая специальная работа', $message);
        self::assertStringContainsString($document->filename, $message);
        self::assertStringContainsString('строка исходного текста 1', $message);
        self::assertStringContainsString('не включена в стоимость', $message);
        self::assertSame([], (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector)->candidates([$package]));
    }

    public function test_real_gateway_parser_and_planner_preserve_both_document_rows_and_versions(): void
    {
        [$context, $session] = $this->fixture();
        $documents = [];
        foreach ([10, 20] as $quantity) {
            $documents[] = $this->document($session, 'ФЕР 08-02-001-01 Кладка стен '.$quantity.' м2');
        }
        [, $foreignSession] = $this->fixture();
        $this->document($foreignSession, str_repeat('x', EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1));

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        self::assertSame(10, $queries);
        self::assertCount(2, $source['documents']);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $items = app(NormativeWorkItemPlannerService::class)->build(['key' => 'walls', 'scope_type' => 'walls'],
            ['key' => 'walls-section', 'construction_part' => 'walls'], $analysis);
        $references = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference'));
        self::assertCount(2, $references);
        self::assertSame(['10', '20'], array_column($references, 'quantity'));
        foreach ($references as $index => $item) {
            self::assertSame((int) $documents[$index]->id, $item['source_refs'][0]['document_id']);
            self::assertSame($documents[$index]->filename, $item['source_refs'][0]['filename']);
            self::assertSame($documents[$index]->source_version, $item['source_refs'][0]['source_version']);
            self::assertSame(1, $item['source_refs'][0]['line_number']);
            self::assertSame('quantity_review', $item['item_type']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_oversized_text_is_rejected_without_truncation(): void
    {
        [$context, $session] = $this->fixture();
        $this->document($session, str_repeat('x', EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1));
        $this->expectException(PipelineStageException::class);
        app(EloquentGenerationPipelineDataGateway::class)->source($context);
    }

    public function test_combined_document_text_is_bounded_before_loading_the_source(): void
    {
        [$context, $session] = $this->fixture();
        $text = str_repeat('x', intdiv(EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES, 2) + 1);
        $this->document($session, str_replace('x', 'y', $text));
        $this->document($session, $text);
        $this->expectException(PipelineStageException::class);
        app(EloquentGenerationPipelineDataGateway::class)->source($context);
    }

    private function confirmUniversalDocumentQuantity(EstimateGenerationSession $session, array $draft, array $keys): void
    {
        foreach ($draft['local_estimates'] as $localIndex => $local) {
            foreach ($local['sections'] as $sectionIndex => $section) {
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = array_values(array_filter(
                    $section['work_items'], static fn (array $item): bool => in_array($item['key'], $keys, true),
                ));
            }
        }
        $references = $this->references($draft);
        self::assertCount(count($keys), $references);
        self::assertCount(count($keys), array_unique(array_column(array_column($references, 'metadata'), 'quantity_key')));
        foreach ($references as $item) {
            self::assertSame('document_source:'.$item['metadata']['source_observation_id'], $item['metadata']['quantity_key']);
            self::assertArrayNotHasKey('scope_entity_id', $item['metadata']);
        }
        config(['estimate-generation.universal_enabled' => true]);
        $actor = User::query()->findOrFail($session->user_id);
        $actor->update(['is_active' => true, 'current_organization_id' => $session->organization_id]);
        $actor->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session->input_payload = [...$session->input_payload, 'evaluation_mode' => 'universal', 'price_policy' => 'catalog',
            'profile_id' => 'construction', 'selected_sections' => ['structures']];
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $models->snapshot(...$scope));
        foreach ($draft['evaluation_result']['scenarios'] as $scenario) {
            self::assertSame($keys, array_column($scenario['positions'], 'key'));
            foreach ($scenario['positions'] as $position) {
                self::assertNull($position['quantity']);
                self::assertNull($position['total_cost']);
                self::assertNull($position['entity_id']);
            }
        }
        $session->update(['status' => 'draft', 'input_payload' => $session->input_payload,
            'draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->state_version,
            'work_item_key' => $keys[0], 'value' => '12.5', 'unit' => $references[0]['unit'] ?? 'м2',
            'reason' => 'Пользователь отдельно подтвердил объём строки документа после проверки источника'];
        $saved = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $input);
        self::assertSame('0', $saved['cost_units']);
        self::assertEquals($saved, app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $input));
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $saved['state_version'], (string) Str::uuid());
        self::assertSame('0', $revision['cost_units']);
        foreach ($revision['result']['scenarios'] as $scenario) {
            self::assertSame($keys, array_column($scenario['positions'], 'key'));
            foreach ($scenario['positions'] as $index => $position) {
                self::assertSame($index === 0 ? '12.5' : null, $position['quantity']);
                self::assertNull($position['total_cost']);
                self::assertSame(CanonicalPipelineJson::encode($references[$index]['source_refs']), CanonicalPipelineJson::encode($position['source_refs']));
            }
        }
        $freshReferences = $this->references($session->fresh()->draft_payload);
        self::assertSame($saved['quantity_key'], $freshReferences[0]['metadata']['quantity_key']);
        self::assertArrayNotHasKey('scope_entity_id', $freshReferences[0]['metadata']);
        self::assertArrayNotHasKey('entity_id', $freshReferences[0]['metadata']);
        $snapshot = $models->snapshot(...$scope);
        $position = $revision['result']['scenarios'][0]['positions'][0];
        $quantityEntity = array_column($snapshot->entities, null, 'id')[$position['entity_id']];
        self::assertSame('quantity', $quantityEntity->type);
        self::assertSame($keys[0], $quantityEntity->attributes['identity']['work_item_key']);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'state_version' => 1, 'status' => 'generating',
            'input_payload' => ['generation_attempt_id' => $attempt]]);

        return [new PipelineContext((int) $session->id, (int) $organization->id, (int) $project->id,
            1, 'sha256:'.str_repeat('a', 64), 'generating', generationAttemptId: $attempt), $session];
    }

    private function references(array $draft): array
    {
        $items = [];
        foreach ($draft['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference') {
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    private function planContext(EstimateGenerationSession $session, array $analysis, PipelineDefinitionGraph $graph, ?array $quantities = null): PipelineContext
    {
        $quantities ??= ['quantity_learning_hints' => [], 'quantity_coverage_warnings' => [], 'building_quantities' => [], 'stage6_generation_context' => []];
        $payloads = [ProcessingStage::UnderstandObject->value => ['analysis' => $analysis], ProcessingStage::ExtractQuantities->value => $quantities];
        $outputs = [];
        foreach ([ProcessingStage::UnderstandObject, ProcessingStage::ExtractQuantities] as $stage) {
            $json = CanonicalPipelineJson::encode($payloads[$stage->value]);
            $dependencies = [];
            foreach ($graph->get($stage)->dependencies as $dependency) {
                $dependencies[$dependency->value] = 'sha256:'.hash('sha256', $dependency->value);
            }
            $outputs[$stage->value] = PipelineStageOutput::create($graph->get($stage), 'sha256:'.hash('sha256', $stage->value), $dependencies,
                new PipelineArtifactReference('memory_json_v1', 'test/'.$stage->value, 'sha256:'.hash('sha256', $json), strlen($json)));
        }
        $dependencies = array_map(static fn (PipelineStageOutput $output): string => $output->version, $outputs);
        $base = 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($session->input_payload));

        return new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id, 1,
            PipelineInputVersion::for($graph->get(ProcessingStage::PlanWorkItems), $base, $dependencies), 'generating',
            priorOutputs: new PipelinePriorOutputs($outputs, $payloads), generationAttemptId: $session->input_payload['generation_attempt_id'],
            baseInputVersion: $base, stage: ProcessingStage::PlanWorkItems, dependencyVersions: $dependencies);
    }

    private function document(EstimateGenerationSession $session, string $text): EstimateGenerationDocument
    {
        return EstimateGenerationDocument::query()->create(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $session->user_id,
            'filename' => 'volumes-'.substr(hash('sha256', $text), 0, 8).'.pdf', 'mime_type' => 'application/pdf',
            'status' => 'ready', 'quality_level' => 'good', 'checksum_sha256' => hash('sha256', $text),
            'source_version' => 'sha256:'.hash('sha256', $text), 'extracted_text' => $text,
            'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'quantity_source']]]);
    }
}
