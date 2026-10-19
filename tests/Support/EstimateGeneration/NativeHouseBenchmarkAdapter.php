<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use InvalidArgumentException;

final class NativeHouseBenchmarkAdapter implements BenchmarkPipelineAdapter
{
    public array $diagnostics = [];

    public function __construct(private readonly BenchmarkObjectReader $objects) {}

    public function id(): string
    {
        return 'synthetic-native-house-v1';
    }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        $input = json_decode($this->objects->read($case, 'input', 1_000_000), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($input) || array_diff(array_keys($input), ['schema_version', 'provenance', 'selected_sections', 'entities', 'conflicts']) !== []
            || ($input['schema_version'] ?? null) !== 1 || ($input['provenance'] ?? null) !== 'synthetic:documented-native-house:v1'
            || ! is_array($input['entities'] ?? null) || ! array_is_list($input['entities']) || count($input['entities']) > 128
            || ! is_array($input['conflicts'] ?? null) || count($input['conflicts']) > 32 || $timeoutMs < 1) {
            throw new InvalidArgumentException('native_house_fixture_invalid');
        }
        $selected = $input['selected_sections'] ?? null;
        $allowed = ['structures', 'finishing', 'roofing', 'earthworks', 'water_supply', 'sewerage', 'heating', 'ventilation', 'electricity', 'equipment'];
        if (! is_array($selected) || ! array_is_list($selected) || $selected === [] || array_diff($selected, $allowed) !== []
            || count(array_unique($selected)) !== count($selected)) {
            throw new InvalidArgumentException('native_house_scope_invalid');
        }
        $entities = $facts = $evidence = [];
        foreach ($input['entities'] as $row) {
            if (! is_array($row) || array_keys($row) !== ['id', 'semantic_type', 'measurements'] || ! is_string($row['id']) || ! is_string($row['semantic_type'])
                || ! is_array($row['measurements']) || ! array_is_list($row['measurements']) || count($row['measurements']) > 8 || isset($entities[$row['id']])) {
                throw new InvalidArgumentException('native_house_entity_invalid');
            }
            $entityVersion = 'sha256:'.hash('sha256', $case->inputSha256."\0".($row['measurements'][0][3] ?? 'empty'));
            $entities[$row['id']] = new Entity($row['id'], 10, 20, 30, $entityVersion, 'quantity', $row['id'], ['semantic_type' => $row['semantic_type']]);
            foreach ($row['measurements'] as $measurement) {
                if (! is_array($measurement) || ! array_is_list($measurement) || count($measurement) !== 4
                    || count(array_filter($measurement, 'is_string')) !== 4 || ! is_numeric($measurement[1]) || count($facts) >= 512) {
                    throw new InvalidArgumentException('native_house_measurement_invalid');
                }
                [$parameter, $value, $unit, $sheet] = $measurement;
                $source = 'sha256:'.hash('sha256', $case->inputSha256."\0".$sheet);
                $id = $row['id'].':'.$parameter.':'.$sheet;
                if (isset($facts[$id])) {
                    throw new InvalidArgumentException('native_house_fact_duplicate');
                }
                $evidenceId = 'evidence:'.$id;
                $fingerprint = hash('sha256', $case->inputSha256."\0".$evidenceId);
                $evidence[] = new Evidence($evidenceId, 10, 20, 30, $source, 'synthetic:'.$sheet, 'document', 1, null,
                    'synthetic:'.$id, 'native_numeric_parser', fingerprint: $fingerprint);
                $facts[$id] = new Fact($id, 10, 20, 30, $source, $row['id'], $parameter, $value, $unit, 1.0, 'document', 'confirmed', [$evidenceId],
                    nativeNumericProofs: [$evidenceId => ['entity_id' => $row['id'], 'source_version' => $source,
                        'parameter' => FactVocabulary::parameter($parameter), 'measurement' => FactVocabulary::measurementSignature($value, $unit),
                        'evidence_fingerprint' => $fingerprint]]);
            }
        }
        $conflicts = [];
        foreach ($input['conflicts'] as $index => $ids) {
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) < 2 || count($ids) > 4 || array_diff($ids, array_keys($facts)) !== []) {
                throw new InvalidArgumentException('native_house_conflict_invalid');
            }
            $conflicts[] = Conflict::betweenSources('conflict:'.$index, array_map(static fn (string $id): Fact => $facts[$id], $ids),
                'synthetic independent source conflict', 'sha256:'.$case->inputSha256);
        }
        $snapshot = new ProjectModelSnapshot(array_values($entities), array_values($facts), $evidence, $conflicts);
        $repository = new InMemoryProjectModelRepository;
        $native = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->simulateInput($snapshot, $case->inputSha256, []);
        $analysis = ['object' => ['object_type' => 'house'], 'planning_signals' => ['generation_mode' => 'ai_assisted'],
            'evaluation_policy' => ['mode' => 'universal', 'selected_sections' => $selected],
            'document_context' => ['canonical_building_quantities' => array_map(static fn ($quantity): array => $quantity->toArray(), $native['quantities'])]];
        $compiled = (new WorkPlanCompiler(new PackagePlannerService, new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            new NormativeContextPinResolver))->compile($analysis, deferNormativePin: true, workItemLimit: 200);
        $quantities = $workIds = $sections = $items = [];
        foreach ($native['quantities'] as $quantity) {
            $identity = $quantity->formulaInputs['entity_id'].':'.$quantity->formulaKey;
            if (isset($quantities[$identity])) {
                throw new InvalidArgumentException('native_house_quantity_identity_duplicate');
            }
            $quantities[$identity] = $quantity->amount;
        }
        foreach ($compiled['local_estimates'] as $estimate) {
            $sections[] = EvaluationSectionMap::forPackage($estimate['key']);
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    $key = $item['metadata']['composition_work_key'] ?? null;
                    if (! is_string($key) || $key === '') {
                        throw new InvalidArgumentException('native_house_work_identity_missing');
                    }
                    $identity = $estimate['key'].':'.$section['key'].':'.$key;
                    if (isset($items[$identity])) {
                        throw new InvalidArgumentException('native_house_work_identity_duplicate');
                    }
                    $items[$identity] = $item;
                    $workIds[] = $identity;
                }
            }
        }
        ksort($quantities, SORT_STRING);
        sort($workIds, SORT_STRING);
        $reviewCodes = array_values(array_unique(array_column($native['warnings'], 'code')));
        sort($reviewCodes, SORT_STRING);
        $this->diagnostics[$case->id] = ['snapshot' => $snapshot, 'native' => $native, 'compiled' => $compiled, 'items' => $items,
            'selected_sections' => $selected, 'uncovered_sections' => array_values(array_diff($selected, array_unique($sections))),
            'persisted_quantities' => $repository->quantities, 'current_quantities' => $repository->currentQuantities,
            'accuracy_calibrated' => false, 'estimate_complete' => false, 'paid_actions_enabled' => false];

        return BenchmarkPipelineResultData::success(['model_schema_version' => 'synthetic-native-house:v1', 'sheet_type' => 'native_house_candidates',
            'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [], 'areas' => [], 'quantities' => $quantities, 'work_ids' => $workIds,
            'normative_rankings' => [], 'costs' => [], 'applicable_item_ids' => [], 'evidence_ids_by_item' => [], 'review_codes' => $reviewCodes],
            ['native' => 'input-preview:v1', 'planning' => 'candidate-only:v1', 'corpus' => 'synthetic-native-house:v1'], '0', 'RUB');
    }
}
