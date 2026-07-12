<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessInspector;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use DateTimeImmutable;
use Throwable;

final readonly class ProductionReplayBenchmarkAdapter implements BenchmarkPipelineAdapter
{
    public const ID = 'production-replay';

    /** @param array<string, array{reference: string, sha256: string}> $projections */
    public function __construct(
        private RecordedReplayProjectionLoader $projectionsLoader,
        private RecordedPortEnvelopeLoader $envelopesLoader,
        private RecordedBenchmarkCatalogLoader $catalogLoader,
        private WorkPlanCompiler $compiler,
        private ResourceAssemblyService $assembly,
        private array $projections,
        private GeometryBuildingModelInputMapper $geometryMapper = new GeometryBuildingModelInputMapper,
        private BuildingModelAssembler $buildingAssembler = new BuildingModelAssembler,
        private NormalizedBuildingModelQuantityInputMapper $quantityMapper = new NormalizedBuildingModelQuantityInputMapper,
        private BuildingQuantityCalculator $quantityCalculator = new BuildingQuantityCalculator,
        private DraftReadinessInspector $readiness = new DraftReadinessInspector,
    ) {}

    public function id(): string { return self::ID; }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        try {
            $descriptor = $this->projections[$case->id] ?? throw new \InvalidArgumentException('recorded_projection_missing');
            $case = $this->projectionsLoader->load($case, $descriptor['reference'], $descriptor['sha256']);
            $ports = $this->envelopesLoader->loadProjection($case);
            $catalog = $this->catalogLoader->load($case);
            $geometry = $this->geometry($ports);
            $evidence = $this->evidenceMap($geometry);
            $model = $this->buildingAssembler->assembleVision($this->geometryMapper->map(
                $geometry['vision'], $geometry['vector'], $evidence,
            ))->model;
            $quantities = $this->quantityCalculator->calculate($this->quantityMapper->map($model));
            $analysis = $this->analysis($model->toArray(), $quantities->toArray(), $catalog);
            $plan = $this->compiler->compile($analysis, (new RecordedWorkPlannerProvider(
                $ports->require(RecordedPort::WorkPlanningModel),
            ))->provide());
            $regional = $analysis['regional_context'];
            $workflow = new NormativeMatchingWorkflow(
                new NormativeRetrievalService(new RecordedCatalogNormativeCandidateSource($catalog), new NormativeHardGate, 16, null),
                new RecordedNormativeCandidateReranker($ports->require(RecordedPort::NormativeReranker)),
            );
            $rankings = [];
            $costs = [];
            $evidenceByItem = [];
            $workIds = [];
            $priceLookup = array_column($catalog->prices, null, 'id');
            $pricing = new EstimatePricingService(new ResolveRegionalPrice(static fn (int $id): ?array => $priceLookup[$id] ?? null));
            foreach ($plan['local_estimates'] as &$estimate) {
                foreach ($estimate['sections'] as &$section) {
                    foreach ($section['work_items'] as &$item) {
                        if (($item['metadata']['generation_source'] ?? null) !== 'work_planner_provider') { continue; }
                        $intent = $this->intent($item, $catalog);
                        $context = new NormativeCandidateDecisionContextData(1, 1, 1, (string) $item['key'],
                            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'sha256:'.$case->inputSha256, 1,
                            'recorded-replay:v1', 'normative-rerank-v1', 'recorded-replay:v1', $intent->sourceEvidence);
                        $result = $workflow->match($intent, $context, true);
                        $selected = $result->selectedCandidateId();
                        $record = collect($catalog->resources)->firstWhere('candidate_id', $selected);
                        $item = $this->assembly->assembleFromDecision($item,
                            AcceptedNormativeDecisionData::fromWorkflowResult($result, is_array($record) ? $record : []), $regional);
                        $item = $pricing->price([$item], $regional)[0];
                        $item['pricing_finalized_at'] = '2026-07-12T00:00:00Z';
                        $item['evidence_ids'] = array_map('strval', $model->evidenceIds);
                        $workIds[] = (string) $item['key'];
                        $rankings[(string) $item['key']] = $result->rerankResult?->ordering ?? [];
                        $costs[(string) $item['key']] = (string) $item['total_cost'];
                        $evidenceByItem[(string) $item['key']] = array_values(array_unique([...$intent->sourceEvidence, ...$result->rerankResult?->evidenceRefs ?? []]));
                    }
                }
            }
            $draft = ['building_model' => [...$model->toArray(), 'metrics' => [...$model->metrics, 'complete' => true]],
                'local_estimates' => $plan['local_estimates'], 'quality_summary' => ['duplicate_work_items' => 0, 'review_items' => ['blocking' => 0]]];
            if ($this->readiness->inspect($draft)->blockingIssues !== []) {
                return BenchmarkPipelineResultData::technicalFailure('production_readiness_blocked');
            }

            $rooms = []; $walls = []; $openings = []; $areas = [];
            foreach ($model->floors as $floor) {
                foreach ($floor->rooms as $room) { $rooms[] = $room->key; if (($q = $quantities->get('floor_area')) !== null) { $areas[$room->key] = $q->amount; } }
                foreach ($floor->walls as $wall) { $walls[] = $wall->key; }
                foreach ($floor->openings as $opening) { $openings[] = $opening->key; }
            }
            $quantityMap = [];
            foreach ($quantities->all() as $quantity) { $quantityMap[$quantity->key] = $quantity->amount; }
            sort($workIds, SORT_STRING); ksort($rankings); ksort($costs); ksort($evidenceByItem); ksort($quantityMap);

            return BenchmarkPipelineResultData::success([
                'sheet_type' => $geometry['vision']?->sheetType ?? 'floor_plan', 'room_cells' => $rooms,
                'wall_cells' => $walls, 'opening_ids' => $openings, 'areas' => $areas, 'quantities' => $quantityMap,
                'work_ids' => $workIds, 'normative_rankings' => $rankings, 'costs' => $costs,
                'applicable_item_ids' => $workIds, 'evidence_ids_by_item' => $evidenceByItem,
                'model_schema_version' => 'production-replay-prediction:v1',
            ], ['building_model' => $model->modelVersion, 'geometry_mapper' => 'geometry-input-mapper:v1',
                'quantity' => 'building-quantity:v1', 'planner' => 'work-planner-v1', 'normative' => 'normative-rerank-v1'],
                '0', $catalog->currency);
        } catch (Throwable) {
            return BenchmarkPipelineResultData::technicalFailure('production_replay_failed');
        }
    }

    private function geometry(RecordedPortEnvelopeSet $ports): array
    {
        $vision = null; $vector = null;
        foreach ($ports->ports() as $port) {
            $envelope = $ports->require($port);
            if ($port === RecordedPort::VisionExtraction) {
                $vision = VisionAnalysisData::fromProviderArray($envelope->payload, $envelope->provider, $envelope->modelVersion,
                    $envelope->modelVersion, $envelope->payloadSchemaVersion, 'unavailable', null, null, 500);
            } elseif (in_array($port, [RecordedPort::DocumentExtraction, RecordedPort::CadExtraction], true)) {
                $vector = VectorGeometryData::fromArray($envelope->payload);
            }
        }
        return ['vision' => $vision, 'vector' => $vector];
    }

    private function evidenceMap(array $geometry): array
    {
        $refs = [];
        if ($geometry['vision'] instanceof VisionAnalysisData) { foreach ($geometry['vision']->evidence as $e) { $refs[] = $e->key; } }
        if ($geometry['vector'] instanceof VectorGeometryData) { foreach ($geometry['vector']->entities as $e) { $refs[] = 'vector:'.$e['handle']; } }
        $refs = array_values(array_unique($refs)); sort($refs, SORT_STRING);
        $mapped = []; foreach ($refs as $index => $ref) { $mapped[$ref] = $index + 1; }
        return $mapped;
    }

    private function analysis(array $model, array $quantities, RecordedBenchmarkCatalogData $catalog): array
    {
        $takeoffs = array_map(static fn (array $q): array => ['scope_key' => $q['key'],
            'quantity_key' => $q['key'] === 'floor_area' ? 'finish.floor' : $q['key'],
            'normalized_payload' => ['quantity_key' => $q['key'] === 'floor_area' ? 'finish.floor' : $q['key']]], $quantities['quantities']);
        return ['object' => ['object_type' => 'floor_plan_geometry', 'description' => 'floor plan',
            'area' => isset($quantities['quantities'][0]['amount']) ? (float) $quantities['quantities'][0]['amount'] : 0],
            'detected_structure' => ['scopes' => [['scope_type' => 'finishing', 'title' => 'Finishing', 'source_refs' => []]]],
            'document_context' => ['quantity_takeoffs' => $takeoffs], 'building_model' => $model,
            'regional_context' => ['dataset_id' => $catalog->datasetId, 'dataset_version' => $catalog->datasetVersion,
                'region_code' => $catalog->regionCode, 'region_id' => 77, 'price_zone_id' => 1, 'period_id' => 202607,
                'price_version' => $catalog->pricePeriod, 'estimate_regional_price_version_id' => 8],
            'generation_mode' => 'strict_documents'];
    }

    private function intent(array $item, RecordedBenchmarkCatalogData $catalog): WorkIntentData
    {
        $candidate = $catalog->candidates[0];
        return new WorkIntentData(1, 1, 1, (string) $item['key'], (string) $item['name'], (string) $item['unit'],
            (string) $candidate['unit_dimension'], (string) $candidate['material'], (string) $candidate['technology'],
            (string) $candidate['structure'], (string) $candidate['normative_section'], (string) $candidate['object_type'],
            $catalog->datasetVersion, $catalog->datasetStatus, $catalog->regionCode, new DateTimeImmutable('2026-07-12'),
            array_values(array_map('strval', $item['metadata']['quantity_source_refs'] ?? [])));
    }
}
