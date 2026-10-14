<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Simulates complete user assumptions with existing formula/geometry rules; never persists dimensions. */
final readonly class ParametricEvaluationScenarios
{
    public const VERSION = 'evaluation-parameter-cases:v1';

    public function __construct(private CurrentProjectDerivedQuantityService $quantities,
        private WorkItemQuantityMapper $mapper = new WorkItemQuantityMapper,
        private EvaluationScenarioSet $scenarios = new EvaluationScenarioSet) {}

    public function build(array $cases, array $works, array $positions, ProjectModelSnapshot $snapshot, string $token,
        ?array $technology, ?array $completeness, array $decisions, string $actorId): array
    {
        if (count($positions) > 2000) {
            throw new InvalidArgumentException('evaluation_scenario_work_budget_exceeded');
        }
        if (! array_is_list($cases) || count($cases) !== 3 || ! ctype_digit($actorId) || (int) $actorId < 1) {
            throw new InvalidArgumentException('evaluation_scenario_parameters_invalid');
        }
        $current = array_column($snapshot->facts, null, 'id');
        $definitions = [];
        $recipes = [];
        $normalized = [];
        $expectedBindings = null;
        foreach ($cases as $case) {
            $id = $case['id'] ?? null;
            $parameters = $case['parameters'] ?? null;
            if (! in_array($id, ['minimum', 'base', 'maximum'], true) || isset($normalized[$id])
                || ! is_string($case['basis'] ?? null) || trim($case['basis']) === '' || strlen($case['basis']) > 1000
                || ! is_array($parameters) || ! array_is_list($parameters) || $parameters === [] || count($parameters) > 64) {
                throw new InvalidArgumentException('evaluation_scenario_parameters_invalid');
            }
            $overrides = [];
            $bindings = [];
            $proofs = [];
            $proposedDecisions = [];
            foreach ($parameters as $parameter) {
                if (! is_array($parameter)) {
                    throw new InvalidArgumentException('evaluation_scenario_parameters_invalid');
                }
                $factId = $parameter['source_fact_id'] ?? null;
                $fact = is_string($factId) ? ($current[$factId] ?? null) : null;
                if (! $fact instanceof Fact || isset($overrides[$factId]) || $fact->status !== 'confirmed'
                    || ! in_array($fact->origin, ['document', 'user_input', 'user_assumption'], true)
                    || ! is_string($fact->unit) || WorkQuantityInputUnit::parse($fact->unit) === null
                    || (! is_string($fact->value) && ! is_int($fact->value)) || ! OrdinaryEstimateDecimal::fitsQuantity($fact->value)
                    || ! is_int($parameter['source_fact_version'] ?? null) || $parameter['source_fact_version'] !== $fact->version
                    || ($parameter['entity_id'] ?? null) !== $fact->entityId || ($parameter['unit'] ?? null) !== $fact->unit
                    || ! is_string($parameter['source_value_hash'] ?? null)
                    || ! hash_equals(DirectQuantityBinding::valueHash($fact->value), $parameter['source_value_hash'])) {
                    throw new InvalidArgumentException('evaluation_scenario_parameter_stale');
                }
                $value = $parameter['value'] ?? null;
                if (! is_string($value) || preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,4})?\z/', $value) !== 1
                    || ! OrdinaryEstimateDecimal::fitsQuantity($value) || BigDecimal::of($value)->isLessThan(0)) {
                    throw new InvalidArgumentException('evaluation_scenario_parameter_value_invalid');
                }
                $value = (string) BigDecimal::of($value)->strippedOfTrailingZeros();
                $normalizedValue = WorkQuantityInputUnit::normalize($value, $fact->unit);
                if (($normalizedValue['unit'] ?? null) === 'count' && BigDecimal::of($normalizedValue['value'])->strippedOfTrailingZeros()->getScale() > 0) {
                    throw new InvalidArgumentException('evaluation_scenario_parameter_value_invalid');
                }
                $binding = ['source_fact_id' => $factId, 'source_fact_version' => $fact->version,
                    'source_value_hash' => $parameter['source_value_hash'], 'entity_id' => $fact->entityId, 'unit' => $fact->unit];
                $bindings[$factId] = $binding;
                $proofId = 'scenario-proof:'.hash('sha256', $id.'|'.$factId.'|'.$value);
                $proofs[] = new Evidence($proofId, $fact->organizationId, $fact->projectId, $fact->sessionId,
                    $fact->sourceVersion, 'input:'.$fact->sessionId, 'user_input', nativeReference: 'parameter_cases.'.$id);
                $overrides[$factId] = new Fact($fact->id, $fact->organizationId, $fact->projectId, $fact->sessionId,
                    $fact->sourceVersion, $fact->entityId, $fact->type, $value, $fact->unit, 0.0, 'user_input',
                    'confirmed', [$proofId], $fact->version + 1, $fact->supersedesFactId);
                $proposedDecisions[] = new Decision('scenario-decision:'.hash('sha256', $proofId), $fact->organizationId,
                    $fact->projectId, $fact->sessionId, $fact->sourceVersion, 'fact', $fact->id, $fact->id, 'user',
                    $actorId, trim($case['basis']), $fact->version + 1, [$proofId]);
            }
            ksort($bindings, SORT_STRING);
            if ($expectedBindings !== null && $bindings !== $expectedBindings) {
                throw new InvalidArgumentException('evaluation_scenario_parameter_scope_incompatible');
            }
            $expectedBindings = $bindings;
            $proposed = new ProjectModelSnapshot($snapshot->entities,
                array_map(static fn (Fact $fact): Fact => $overrides[$fact->id] ?? $fact, $snapshot->facts),
                [...$snapshot->evidence, ...$proofs], $snapshot->conflicts);
            $originalDecisions = array_values(array_filter($decisions,
                static fn (Decision $decision): bool => ! isset($overrides[$decision->selectedFactId ?? ''])));
            $projection = $this->quantities->simulate($proposed, $token, $technology, $completeness, [...$originalDecisions, ...$proposedDecisions]);
            if (($projection['warnings'][0]['code'] ?? null) === 'stage5_projection_not_current') {
                $projection = $this->quantities->simulateInput($proposed, $token, [...$originalDecisions, ...$proposedDecisions]);
            }
            $amounts = [];
            $used = [];
            foreach ($positions as $position) {
                $key = $position['key'];
                $work = $works[$key] ?? null;
                $entityId = $work['metadata']['scope_entity_id'] ?? $work['entity_id'] ?? null;
                $purpose = $work['work_key'] ?? null;
                if (! is_string($purpose) || ! $this->mapper->isKnownWorkKey($purpose)) {
                    $purpose = $work['metadata']['quantity_key'] ?? null;
                }
                if (! is_string($entityId) || ! is_string($purpose)) {
                    continue;
                }
                $quantity = $this->mapper->mapForEntity($purpose, $projection['quantities'], $entityId);
                $factor = $quantity === null ? null : NormativeUnitNormalizer::safeQuantityFactorDecimal($quantity->unit, (string) ($position['unit'] ?? ''));
                if ($quantity === null || $factor === null) {
                    continue;
                }
                $operandIds = $this->operandIds($quantity->formulaInputs);
                $changed = array_intersect(array_keys($overrides), $operandIds);
                if ($changed === []) {
                    continue;
                }
                $used = [...$used, ...$changed];
                $amount = (string) BigDecimal::of($quantity->amount)->multipliedBy($factor)->strippedOfTrailingZeros();
                $amounts[] = ['work_item_key' => $key, 'value' => $amount, 'unit' => $position['unit']];
                $recipes[$id][$key] = ['status' => 'user_assumption', 'formula_key' => $quantity->formulaKey,
                    'formula_version' => $quantity->formulaVersion, 'formula_inputs' => $quantity->formulaInputs,
                    'source_parameters' => array_values($bindings), 'value' => $amount, 'unit' => $position['unit'],
                    'source_formula_value' => $quantity->amount, 'source_formula_unit' => $quantity->unit, 'unit_conversion_factor' => $factor];
            }
            if (array_diff(array_keys($overrides), $used) !== []) {
                throw new InvalidArgumentException('evaluation_scenario_parameters_not_calculable');
            }
            $definitions[] = ['id' => $id, 'basis' => trim($case['basis']), 'quantities' => $amounts];
            $normalized[$id] = ['id' => $id, 'basis' => trim($case['basis']), 'parameters' => array_map(
                static fn (string $factId): array => [...$bindings[$factId], 'value' => (string) $overrides[$factId]->value], array_keys($bindings))];
        }

        return ['version' => self::VERSION, 'definitions' => $this->scenarios->validate($definitions, $positions),
            'parameter_cases' => array_map(static fn (string $id): array => $normalized[$id], ['minimum', 'base', 'maximum']),
            'recipes' => $recipes];
    }

    private function operandIds(array $values): array
    {
        $ids = [];
        foreach ($values as $key => $value) {
            if ($key === 'fact_id' && is_string($value)) {
                $ids[] = $value;
            } elseif (is_array($value)) {
                $ids = [...$ids, ...$this->operandIds($value)];
            }
        }

        return array_values(array_unique($ids));
    }
}
