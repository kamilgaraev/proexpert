<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class UniversalEvaluationBuilder
{
    public function __construct(private ScenarioEstimateCalculator $calculator = new ScenarioEstimateCalculator,
        private ScopeCompletenessEvaluator $completeness = new ScopeCompletenessEvaluator,
        private EvaluationQuestionPrioritizer $questions = new EvaluationQuestionPrioritizer) {}

    public function build(array $draft, ProjectModelSnapshot $snapshot, array $decisions = [], array $technologies = []): array
    {
        $policy = $draft['evaluation_policy'] ?? [];
        $scope = $policy['selected_sections'] ?? [];
        $positions = [];
        $assumptions = [];
        $questionCandidates = [];
        $entityIds = array_column($snapshot->entities, null, 'id');
        $factsById = array_column($snapshot->facts, null, 'id');
        $proofsById = array_column($snapshot->evidence, null, 'id');
        $entityScope = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            $sectionKey = EvaluationSectionMap::forPackage((string) $estimate['key']);
            if ($scope !== [] && ! in_array($sectionKey, $scope, true) && ! in_array($estimate['key'], $scope, true)) {
                continue;
            }
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        continue;
                    }
                    $quantity = is_array($item['quantity_basis_details'] ?? null) ? $item['quantity_basis_details'] : [];
                    $entityId = $quantity['formula_inputs']['entity_id'] ?? $item['metadata']['entity_id'] ?? null;
                    if (! is_string($entityId) || ! isset($entityIds[$entityId])) {
                        $entityId = null;
                    } else {
                        $entityScope[$sectionKey][] = $entityId;
                    }
                    $position = ['key' => $item['key'], 'name' => $item['name'] ?? $item['key'], 'section' => $sectionKey,
                        'package_key' => $estimate['key'], 'entity_id' => $entityId, 'unit' => $item['unit'] ?? null,
                        'work_key' => $item['metadata']['requirement_key'] ?? $item['work_key'] ?? $item['key'],
                        'quantity' => $item['quantity'] ?? null, 'quantity_status' => $item['quantity_status'] ?? 'unknown',
                        'quantity_formula' => $quantity['formula_key'] ?? null, 'quantity_basis' => $quantity,
                        'quantity_evidence_details' => $this->quantityEvidenceDetails($quantity, $factsById, $proofsById),
                        'source_refs' => $item['source_refs'] ?? [],
                        'price_snapshot' => $this->price($item, $draft), 'technology' => $item['specialization_scenario'] ?? null,
                        'dependencies' => $item['metadata']['dependencies'] ?? [], 'warnings' => $item['validation_flags'] ?? []];
                    $positions[] = $position;
                    foreach ($quantity['assumptions'] ?? [] as $assumption) {
                        $assumptions[] = ['position_key' => $item['key'], 'basis' => $assumption];
                    }
                    if ($position['quantity'] === null) {
                        $questionCandidates[] = ['key' => 'quantity:'.$item['key'], 'question' => 'Уточните объём работы «'.$position['name'].'» и единицу измерения.',
                            'affected_position_keys' => [$item['key']], 'correctness_blocker' => true, 'cost_blocker' => true,
                            'easy_to_answer' => true, 'next_action' => 'confirm_source_parameters', 'effect' => 'Будут пересчитаны объём и стоимость этой работы.'];
                    } elseif ($position['price_snapshot'] === null) {
                        $questionCandidates[] = ['key' => 'price:'.$item['key'], 'question' => 'Выберите источник цены для работы «'.$position['name'].'».',
                            'affected_position_keys' => [$item['key']], 'correctness_blocker' => false, 'cost_blocker' => true,
                            'easy_to_answer' => true, 'next_action' => 'select_price_source', 'effect' => 'Цена из выбранного источника войдёт в стоимость работы.'];
                    }
                }
            }
        }
        if ($positions === []) {
            throw new InvalidArgumentException('evaluation_result_has_no_work_scope');
        }
        // Completeness describes the actual selected sections, never a whole project by implication.
        $coveredSections = array_values(array_unique(array_column($positions, 'section')));
        $scope = $scope === [] ? $coveredSections : array_values(array_unique(array_map(EvaluationSectionMap::forPackage(...), $scope)));
        foreach ($scope as $section) {
            $entityScope[$section] = array_values(array_unique($entityScope[$section] ?? []));
        }
        $coverage = $this->completeness->evaluate($snapshot, $policy['profile_id'] ?? 'construction',
            $policy['construction_type'] ?? 'new_construction', $entityScope, $positions, $decisions, $technologies);
        $requirements = $coverage['missing_requirements'];
        foreach ($requirements as $requirement) {
            $affected = array_values(array_column(array_filter($positions, static fn (array $position): bool => $position['section'] === $requirement['section'] && ($requirement['entity_id'] === null || $position['entity_id'] === $requirement['entity_id'])), 'key'));
            $questionCandidates[] = ['key' => 'requirement:'.$requirement['section'].':'.($requirement['entity_id'] ?? 'scope').':'.$requirement['requirement'],
                'question' => 'Уточните основание для «'.$this->requirementName($requirement['requirement']).'» в разделе «'.$requirement['section'].'».',
                'affected_position_keys' => $affected, 'correctness_blocker' => true, 'cost_blocker' => false,
                'easy_to_answer' => false, 'next_action' => $requirement['reason'] === 'document_requirement_missing' ? 'provide_required_document' : 'confirm_work_scope',
                'effect' => 'Ответ уточнит выбранный состав работ и статус полноты этого раздела.'];
        }
        foreach ($draft['audit_review_items'] ?? [] as $finding) {
            $requirements[] = ['requirement' => 'audit_finding', 'reason' => $finding['reason'] ?? 'audit_review_required',
                'position_keys' => isset($finding['item_key']) ? [$finding['item_key']] : []];
        }
        $result = $this->calculator->calculate([['id' => 'base', 'positions' => $positions, 'assumptions' => $assumptions]], $scope, $requirements);

        return [...$result, 'profile_id' => $coverage['profile_id'], 'profile_version' => $coverage['profile_version'],
            'coverage' => $coverage, 'priority_questions' => $this->questions->prioritize($questionCandidates),
            'scope_boundaries' => ['sections' => $scope, 'uncovered_sections' => array_values(array_diff($scope, $coveredSections))]];
    }

    private function price(array $item, array $draft): ?array
    {
        if (is_array($item['commercial_price_snapshot'] ?? null)) {
            return $item['commercial_price_snapshot'];
        }
        $price = $item['price_snapshot'] ?? null;
        $quantity = $item['quantity'] ?? null;
        if (! is_array($price) || ($price['source_type'] ?? null) !== 'regional_resource_aggregate'
            || ! in_array($item['pricing_status'] ?? null, ['calculated', 'calculated_review_required'], true)
            || ! in_array($item['pricing_blocker'] ?? null, [null, '', 'none'], true)
            || ! is_string($quantity) || ! BigDecimal::of($quantity)->isGreaterThan(0)
            || ! is_string($item['evaluation_price_basis_quantity'] ?? null)
            || ! BigDecimal::of($item['evaluation_price_basis_quantity'])->isEqualTo(BigDecimal::of($quantity))
            || ! is_string($price['final_amount'] ?? null) || ! BigDecimal::of($price['final_amount'])->isGreaterThan(0)
            || ! is_array($price['coefficients']['resource_evidence'] ?? null) || $price['coefficients']['resource_evidence'] === []) {
            return null;
        }
        $basis = ['kind' => 'normative_resource_sum', 'quantity' => $quantity,
            'work_cost' => $price['coefficients']['work_cost'] ?? '0.00', 'resources' => $price['coefficients']['resource_evidence'],
            'source_hash' => hash('sha256', CanonicalPipelineJson::encode($price))];

        return ['source_type' => 'normative', 'source_reference' => $price['source_reference'], 'verified' => true,
            'unit' => $item['unit'], 'currency' => $price['currency'],
            'unit_price' => (string) BigDecimal::of($price['final_amount'])->dividedBy($quantity, 18, RoundingMode::HalfUp),
            'as_of_date' => $draft['regional_context']['price_as_of_date'] ?? null,
            'level' => $draft['regional_context']['price_level'] ?? null, 'region_id' => $price['region_id'],
            'price_period_id' => $price['period_id'], 'price_version_id' => $price['version_id'],
            'calculation_basis' => $basis, 'conditions_missing' => ['price_level', 'vat_mode', 'delivery_included']];
    }

    private function requirementName(string $requirement): string
    {
        return match ($requirement) {
            'scope_specification' => 'состав оцениваемых работ', 'preparation', 'site_preparation' => 'подготовительные работы',
            'demolition' => 'демонтаж', 'waste_removal', 'soil_removal' => 'вывоз отходов или грунта',
            'existing_structures_protection' => 'защита существующих конструкций', 'roof_substrate' => 'основание кровли',
            'roof_covering' => 'покрытие кровли', 'roof_drainage' => 'водоотведение кровли',
            'equipment_specification', 'industrial_specification', 'pipeline_specification' => 'спецификация',
            'industry_work_checklist' => 'отраслевой перечень работ', 'technology_requirements' => 'технологические требования',
            'industrial_profile_validation' => 'проверка промышленного профиля', 'commissioning' => 'пусконаладка',
            default => str_contains($requirement, 'test_program') ? 'программа испытаний' : (str_contains($requirement, 'testing') ? 'испытания' : $requirement),
        };
    }

    /** @param array<string, Fact> $factsById @param array<string, Evidence> $proofsById */
    private function quantityEvidenceDetails(array $quantity, array $factsById, array $proofsById): array
    {
        $details = [];
        foreach ($quantity['formula_inputs']['operands'] ?? [] as $operand) {
            if (! is_array($operand)) {
                continue;
            }
            $fact = $factsById[$operand['fact_id'] ?? ''] ?? null;
            if ($fact === null) {
                continue;
            }
            foreach ($fact->evidenceIds as $id) {
                $proof = $proofsById[$id] ?? null;
                if ($proof === null || $proof->sourceVersion !== $fact->sourceVersion) {
                    continue;
                }
                $documentId = $proof->sourceType === 'document' && preg_match('/\Adocument:([1-9][0-9]*)\z/', $proof->sourceReference ?? $proof->sourceArtifactId, $matches) === 1 ? (int) $matches[1] : null;
                $details[$fact->id.':'.$id] = ['fact_id' => $fact->id, 'entity_id' => $fact->entityId, 'parameter' => $fact->type,
                    'value' => $fact->value, 'unit' => $fact->unit, 'basis' => $fact->origin, 'verification_status' => $fact->status,
                    'evidence_id' => $id, 'source_type' => $proof->sourceType, 'source_version' => $proof->sourceVersion,
                    'document_id' => $documentId, 'page' => $proof->page, 'region' => $proof->region, 'native_reference' => $proof->nativeReference];
            }
        }

        return array_values($details);
    }
}
