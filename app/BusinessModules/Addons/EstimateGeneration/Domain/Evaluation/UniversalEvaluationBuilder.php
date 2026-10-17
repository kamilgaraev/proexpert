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
        private EvaluationQuestionPrioritizer $questions = new EvaluationQuestionPrioritizer,
        private EvaluationSourceConflictProjector $sourceConflicts = new EvaluationSourceConflictProjector) {}

    public function build(array $draft, ProjectModelSnapshot $snapshot, array $decisions = [], array $technologies = []): array
    {
        $policy = $draft['evaluation_policy'] ?? [];
        $regionalTerms = (new EvaluationRegionalPriceConditions)->accepted($draft, $snapshot, $decisions);
        $scope = $policy['selected_sections'] ?? [];
        $workScope = new EvaluationWorkScope;
        $workSelection = $policy['scope_selection']['work_scope'] ?? null;
        $expectedSelection = ['selected_sections' => $scope, 'profile_id' => $policy['profile_id'] ?? 'construction',
            'construction_type' => $policy['construction_type'] ?? 'new_construction',
            'price_policy' => $policy['price_policy'] ?? 'normative', 'profile_version' => ScopeCompletenessEvaluator::VERSION,
            ...($workSelection === null ? [] : ['work_scope' => $workSelection])];
        $workScopeCurrent = $workSelection !== null && $workScope->current($draft, $workSelection)
            && (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $policy['scope_selection'] ?? null, 'evaluation_scope', $expectedSelection);
        $selectedKeys = $workScopeCurrent ? array_fill_keys(array_column($workSelection['selected_items'], 'key'), true) : null;
        $excludedItems = [];
        $auditDispositions = [];
        $positions = [];
        $assumptions = [];
        $scopeExclusions = [];
        $questionCandidates = [];
        $entityIds = array_column($snapshot->entities, null, 'id');
        $factsById = array_column($snapshot->facts, null, 'id');
        $proofsById = array_column($snapshot->evidence, null, 'id');
        $workContexts = new EvaluationWorkContextProjector($snapshot);
        $entityScope = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            if (($estimate['key'] ?? null) === 'document_review') {
                foreach ($estimate['coverage_warnings'] ?? [] as $warning) {
                    if (($warning['reason'] ?? null) === 'work_scope_review_required'
                        && is_string($warning['quantity_key'] ?? null) && is_string($warning['message'] ?? null)) {
                        $scopeExclusions[] = ['key' => $warning['quantity_key'], 'message' => $warning['message']];
                    }
                }
            }
            $sectionKey = EvaluationSectionMap::forPackage((string) $estimate['key']);
            if ($scope !== [] && ! in_array($sectionKey, $scope, true) && ! in_array($estimate['key'], $scope, true)) {
                continue;
            }
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        continue;
                    }
                    if ($selectedKeys !== null && ! isset($selectedKeys[$item['key']])) {
                        $excludedItems[] = ['key' => $item['key'], 'name' => $item['name'] ?? $item['key'],
                            'section' => $sectionKey, 'reason' => $workSelection['reason']];

                        continue;
                    }
                    $quantity = is_array($item['quantity_basis_details'] ?? null) ? $item['quantity_basis_details'] : [];
                    $entityId = $item['metadata']['scope_entity_id'] ?? $quantity['formula_inputs']['entity_id'] ?? $item['metadata']['entity_id'] ?? null;
                    if (! is_string($entityId) || ! isset($entityIds[$entityId])) {
                        $entityId = null;
                    } else {
                        $entityScope[$sectionKey][] = $entityId;
                    }
                    $position = ['key' => $item['key'], 'name' => $item['name'] ?? $item['key'], 'section' => $sectionKey,
                        'package_key' => $estimate['key'], 'entity_id' => $entityId, 'unit' => $item['unit'] ?? null,
                        'work_key' => $item['work_key'] ?? $item['metadata']['composition_work_key'] ?? $item['key'],
                        'coverage_requirement' => $item['metadata']['requirement_key']
                            ?? ScopeCompletenessEvaluator::workRequirement($sectionKey, $item['work_key'] ?? $item['metadata']['composition_work_key'] ?? $item['key']),
                        'quantity' => $item['quantity'] ?? null, 'quantity_status' => $item['quantity_status'] ?? 'unknown',
                        'quantity_formula' => $quantity['formula_key'] ?? null, 'quantity_basis' => $quantity,
                        'quantity_evidence_details' => $this->quantityEvidenceDetails($quantity, $factsById, $proofsById),
                        'source_refs' => $item['source_refs'] ?? [],
                        'price_snapshot' => $this->price($item, $draft, $regionalTerms), 'technology' => $item['specialization_scenario'] ?? null,
                        'dependencies' => $item['metadata']['dependencies'] ?? [], 'warnings' => $item['validation_flags'] ?? []];
                    if ($entityId === null && ! isset($item['metadata']['scope_entity_id']) && ! isset($item['metadata']['entity_id'])
                        && ! isset($quantity['formula_inputs']['entity_id'])) {
                        $context = $workContexts->project($item, $estimate['key'], $section['key'] ?? null, $sectionKey);
                        if ($context !== null) {
                            $position['proposed_entity_context'] = $context;
                        }
                    }
                    $positions[] = $position;
                    foreach ($quantity['assumptions'] ?? [] as $assumption) {
                        $assumptions[] = ['position_key' => $item['key'], 'basis' => $assumption];
                    }
                    if (is_array($item['metadata']['material_assumption'] ?? null)) {
                        $assumptions[] = ['position_key' => $item['key'], 'basis' => $item['metadata']['material_assumption']];
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
        $parameterQuestions = (new EvaluationParameterQuestionBuilder)->candidates($positions, $snapshot,
            $draft['quantity_coverage_warnings'] ?? []);
        $parameterPositions = array_fill_keys(array_merge([], ...array_column($parameterQuestions, 'affected_position_keys')), true);
        $questionCandidates = array_values(array_filter($questionCandidates, static fn (array $question): bool => ! str_starts_with($question['key'], 'quantity:') || ! isset($parameterPositions[$question['affected_position_keys'][0]])));
        array_push($questionCandidates, ...$parameterQuestions);
        // Completeness describes the actual selected sections, never a whole project by implication.
        $coveredSections = array_values(array_unique(array_column($positions, 'section')));
        $scope = $scope === [] ? $coveredSections : array_values(array_unique(array_map(EvaluationSectionMap::forPackage(...), $scope)));
        foreach ($scope as $section) {
            $entityScope[$section] = array_values(array_unique($entityScope[$section] ?? []));
        }
        $coverage = $this->completeness->evaluate($snapshot, $policy['profile_id'] ?? 'construction',
            $policy['construction_type'] ?? 'new_construction', $entityScope, $positions, $decisions, $technologies);
        $coverage = (new EvaluationCoverageEvidenceProjector)->project($coverage, $snapshot);
        $requirements = $coverage['missing_requirements'];
        if ($workSelection !== null && ! $workScopeCurrent) {
            $requirements[] = ['requirement' => 'work_scope_confirmation', 'reason' => 'work_scope_selection_changed'];
        }
        if (($policy['required_technology_revision'] ?? 0) > ($policy['technology_revision'] ?? 0)) {
            $requirements[] = ['section' => 'roofing', 'entity_id' => null, 'requirement' => 'technology_composition_refresh',
                'reason' => 'technology_selection_changed'];
            $questionCandidates[] = ['key' => 'technology:composition-refresh',
                'question' => 'Выбранная технология изменилась. Получите предложение стоимости ИИ для обновления состава работ.',
                'affected_position_keys' => array_column(array_filter($positions, static fn (array $position): bool => $position['section'] === 'roofing'), 'key'),
                'correctness_blocker' => true, 'cost_blocker' => true, 'easy_to_answer' => true,
                'next_action' => 'quote_composition_refresh', 'effect' => 'Новый состав будет подготовлен отдельно; текущие позиции и созданная смета сохранятся.'];
        }
        foreach ($requirements as $requirement) {
            if (in_array($requirement['requirement'], ['technology_composition_refresh', 'work_scope_confirmation'], true)) {
                continue;
            }
            $affected = array_values(array_column(array_filter($positions, static fn (array $position): bool => $position['section'] === $requirement['section'] && ($requirement['entity_id'] === null || $position['entity_id'] === $requirement['entity_id'])), 'key'));
            $questionCandidates[] = ['key' => 'requirement:'.$requirement['section'].':'.($requirement['entity_id'] ?? 'scope').':'.$requirement['requirement'],
                'question' => 'Уточните основание для «'.$this->requirementName($requirement['requirement']).'» в разделе «'.$requirement['section'].'».',
                'affected_position_keys' => $affected, 'correctness_blocker' => true, 'cost_blocker' => false,
                'easy_to_answer' => false, 'next_action' => $requirement['reason'] === 'document_requirement_missing' ? 'provide_required_document' : 'confirm_work_scope',
                'effect' => 'Ответ уточнит выбранный состав работ и статус полноты этого раздела.'];
        }
        $conflicts = $this->sourceConflicts->project($positions, $snapshot);
        $positions = $conflicts['positions'];
        array_push($requirements, ...$conflicts['requirements']);
        array_push($questionCandidates, ...$conflicts['questions']);
        $excluded = $workScopeCurrent ? array_column($workSelection['excluded_audit_findings'] ?? [], null, 'finding_id') : [];
        $acceptedMaterials = $workScopeCurrent ? array_column($workSelection['accepted_material_findings'] ?? [], null, 'finding_id') : [];
        $currentItems = $workScope->items($draft);
        $commercialFindings = [];
        foreach ($draft['audit_review_items'] ?? [] as $finding) {
            $findingKey = $finding['item_key'] ?? null;
            $findingId = $finding['finding_id'] ?? null;
            $hash = $workScope->findingHash($finding);
            if (isset($excluded[$findingId]) && is_string($findingKey) && ! isset($selectedKeys[$findingKey])
                && hash_equals($excluded[$findingId]['fingerprint'], $hash)) {
                $auditDispositions[] = ['finding_id' => $findingId, 'item_key' => $findingKey, 'status' => 'outside_confirmed_work_scope',
                    'reason' => $workSelection['reason'], 'decision_id' => $policy['scope_selection']['decision_id']];

                continue;
            }
            $currentItem = $currentItems[$findingKey]['item'] ?? null;
            if (is_array($currentItem) && ($finding['type'] ?? null) === 'suspicious_zero'
                && ! is_array($currentItem['commercial_price_snapshot'] ?? null)
                && is_string($currentItem['price_snapshot']['final_amount'] ?? null)
                && $this->price($currentItem, $draft, $regionalTerms) !== null && is_numeric($currentItem['total_cost'] ?? null)
                && BigDecimal::of((string) $currentItem['total_cost'])->isEqualTo((string) $currentItem['price_snapshot']['final_amount'])) {
                $auditDispositions[] = ['finding_id' => $findingId, 'item_key' => $findingKey, 'status' => 'corrected_by_native_pricing',
                    'price_snapshot_hash' => hash('sha256', CanonicalPipelineJson::encode($currentItem['price_snapshot']))];

                continue;
            }
            if (is_array($currentItem) && ($finding['type'] ?? null) === 'suspicious_zero'
                && is_array($currentItem['commercial_price_snapshot'] ?? null)) {
                $commercialFindings[] = [...$finding, 'finding_id' => $findingId, 'item_key' => $findingKey];

                continue;
            }
            if (isset($acceptedMaterials[$findingId]) && is_array($currentItem)
                && hash_equals($acceptedMaterials[$findingId]['fingerprint'], $hash)
                && $workScope->materialBinding($currentItem) !== null
                && CanonicalPipelineJson::encode($workScope->materialBinding($currentItem))
                    === CanonicalPipelineJson::encode($acceptedMaterials[$findingId]['material_binding'])) {
                $auditDispositions[] = ['finding_id' => $findingId, 'item_key' => $findingKey, 'status' => 'material_assumption_accepted',
                    'decision_id' => $policy['scope_selection']['decision_id']];

                continue;
            }
            $requirements[] = ['requirement' => 'audit_finding', 'reason' => $finding['reason'] ?? 'audit_review_required',
                'position_keys' => isset($finding['item_key']) ? [$finding['item_key']] : []];
        }
        foreach ($draft['estimate_composition']['request_info'] ?? [] as $index => $request) {
            if (in_array($request['reason'] ?? null, ['estimate_composer_work_section_not_selected', 'estimate_composer_physical_duplicate_covered'], true)) {
                continue;
            }
            $requirements[] = ['section' => $scope[0], 'entity_id' => null, 'requirement' => 'work_source_review',
                'reason' => 'work_source_unconfirmed'];
            $questionCandidates[] = ['key' => 'work-source:'.$index,
                'question' => 'Уточните состав и основание работы'.(is_string($request['work_name'] ?? null) ? ' «'.$request['work_name'].'»' : '').'.',
                'affected_position_keys' => array_column($positions, 'key'), 'correctness_blocker' => true, 'cost_blocker' => true,
                'easy_to_answer' => false, 'next_action' => 'confirm_work_source',
                'effect' => 'Предложенная работа остаётся за пределами оценки до проверки основания и обновления состава.'];
        }
        $contingency = null;
        $contingencySelection = $policy['contingency_selection'] ?? null;
        if (is_array($contingencySelection)) {
            try {
                $terms = (new EvaluationContingency)->terms($contingencySelection);
                if (is_string($contingencySelection['decision_id'] ?? null)
                    && (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $contingencySelection, 'evaluation_contingency',
                        [...$terms, 'decision_id' => $contingencySelection['decision_id']])) {
                    $contingency = (new EvaluationContingency)->component($contingencySelection);
                }
            } catch (InvalidArgumentException) {
                // An invalid monetary choice is a visible blocker, never a zero reserve.
            }
        }
        $legacyReserve = ! BigDecimal::of((string) ($draft['contingency_amount'] ?? '0'))->isZero()
            || ! BigDecimal::of((string) ($draft['contingency_percent'] ?? '0'))->isZero();
        if ($contingency === null && ($contingencySelection !== null || $legacyReserve)) {
            $requirements[] = ['section' => 'financial', 'requirement' => 'contingency_confirmation', 'reason' => 'contingency_not_confirmed'];
            $questionCandidates[] = ['key' => 'contingency_confirmation', 'question' => 'Подтвердите фиксированный резерв и условия НДС или явно задайте нулевой резерв.',
                'affected_position_keys' => array_column($positions, 'key'), 'correctness_blocker' => true, 'cost_blocker' => true,
                'easy_to_answer' => true, 'next_action' => 'confirm_contingency', 'effect' => 'Резерв изменит общий итог выбранного состава.'];
        }
        $expectedScope = ['selected_sections' => $scope, 'profile_id' => $coverage['profile_id'],
            'construction_type' => $coverage['construction_type'], 'price_policy' => $policy['price_policy'] ?? 'normative',
            'profile_version' => $coverage['profile_version'], ...($workSelection === null ? [] : ['work_scope' => $workSelection])];
        $scopeConfirmed = ($workSelection === null || $workScopeCurrent)
            && (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $policy['scope_selection'] ?? null, 'evaluation_scope', $expectedScope);
        $scenarios = (new EvaluationScenarioSet)->project($positions, $assumptions, $snapshot, $decisions, $policy['scenario_selections'] ?? null);
        $scenarios = (new EvaluationScenarioCurrencyProjector)->project($scenarios, $policy['currency_selections'] ?? [], $snapshot, $decisions);
        if ($contingency !== null) {
            foreach ($scenarios as &$scenario) {
                $scenario['contingency'] = $contingency;
            }
            unset($scenario);
        }
        if ($commercialFindings !== []) {
            $commercialMoneyByKey = $this->correctedCommercialMoneyByKey($scenarios, $scope);
            foreach ($commercialFindings as $finding) {
                $money = $commercialMoneyByKey[$finding['item_key']] ?? null;
                if ($money === null) {
                    $requirements[] = ['requirement' => 'audit_finding', 'reason' => $finding['reason'] ?? 'audit_review_required',
                        'position_keys' => [$finding['item_key']]];

                    continue;
                }
                $auditDispositions[] = ['finding_id' => $finding['finding_id'], 'item_key' => $finding['item_key'],
                    'status' => 'corrected_by_commercial_pricing', 'source_hash' => $money['price_snapshot']['source_hash'],
                    'calculated_amount' => $money['amount'],
                    'price_snapshot_hash' => hash('sha256', CanonicalPipelineJson::encode($money['price_snapshot']))];
            }
        }
        $result = $this->calculator->calculate($scenarios, $scope, $requirements, $scopeConfirmed);
        foreach ($result['missing_requirements'] as &$requirement) {
            if (! is_array($requirement)) {
                continue;
            }
            $entity = $entityIds[$requirement['entity_id'] ?? ''] ?? null;
            $name = $entity?->attributes['name'] ?? $entity?->attributes['identity']['label'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $requirement['entity_name'] = mb_substr($name, 0, 160);
            }
        }
        unset($requirement);

        return [...$result, 'profile_id' => $coverage['profile_id'], 'profile_version' => $coverage['profile_version'],
            ...($scopeExclusions === [] ? [] : ['scope_exclusions' => $scopeExclusions]),
            ...($workSelection === null ? [] : ['selected_work_item_keys' => $selectedKeys === null ? null : array_keys($selectedKeys),
                'excluded_work_items' => $excludedItems, 'work_scope_reason' => $workSelection['reason'] ?? null]),
            'audit_dispositions' => $auditDispositions,
            'coverage' => $coverage, 'priority_questions' => $this->questions->prioritize($questionCandidates),
            'scope_boundaries' => ['sections' => $scope, 'uncovered_sections' => array_values(array_diff($scope, $coveredSections))]];
    }

    private function price(array $item, array $draft, ?array $regionalTerms = null): ?array
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
        $resources = (new NormativePriceEvidenceProjector)->resources($price, $quantity);
        if ($resources === null) {
            return null;
        }
        $resources = $this->priceIdentity($resources);
        $basis = ['kind' => 'normative_resource_sum', 'quantity' => $quantity,
            'work_cost' => $price['coefficients']['work_cost'] ?? '0.00', 'resources' => $resources,
            'source_hash' => hash('sha256', CanonicalPipelineJson::encode($this->priceIdentity($price)))];
        $regionalOnly = array_filter($basis['resources'], static fn ($resource): bool => ! is_array($resource)
            || in_array($resource['source_type'] ?? null, ['fsnb_base', 'fsbc_base', 'fsnb_2022', 'fsbc', 'normative_rate_base'], true)
            || str_starts_with((string) ($resource['coefficients']['price_kind'] ?? ''), 'base_catalog')
            || (($resource['source_type'] ?? null) !== 'regional_catalog'
                && ((int) ($resource['version_id'] ?? 0) !== (int) ($price['version_id'] ?? -1)
                    || (int) ($resource['region_id'] ?? 0) !== (int) ($price['region_id'] ?? -1)
                    || (int) ($resource['zone_id'] ?? 0) !== (int) ($price['zone_id'] ?? -1)
                    || (int) ($resource['period_id'] ?? 0) !== (int) ($price['period_id'] ?? -1)))) === [];
        $terms = $regionalOnly && ($regionalTerms['estimate_regional_price_version_id'] ?? null) === ($price['version_id'] ?? null)
            ? $regionalTerms : null;

        return ['source_type' => 'normative', 'source_reference' => $price['source_reference'], 'verified' => true,
            'source_hash' => $basis['source_hash'],
            'unit' => $item['unit'], 'currency' => $price['currency'],
            'unit_price' => (string) BigDecimal::of($price['final_amount'])->dividedBy($quantity, 18, RoundingMode::HalfUp),
            'as_of_date' => $terms['as_of_date'] ?? $draft['regional_context']['price_as_of_date'] ?? null,
            'level' => $regionalOnly ? 'current' : null, 'region_id' => $price['region_id'],
            'price_period_id' => $regionalOnly ? $price['period_id'] : null, 'price_version_id' => $regionalOnly ? $price['version_id'] : null,
            'resource_sources' => $basis['resources'],
            'calculation_basis' => $basis,
            ...($terms === null ? [] : ['vat_mode' => $terms['vat_mode'], 'vat_rate' => $terms['vat_rate'],
                'delivery_included' => $terms['delivery'] === 'included', 'delivery_outside_scope' => $terms['delivery'] === 'outside_scope',
                'conditions_basis' => $terms['basis'], 'conditions_decision_id' => $terms['decision_id']]),
            'conditions_missing' => [...($regionalOnly ? [] : ['price_level']), ...($terms === null ? ['vat_mode', 'delivery_included'] : [])]];
    }

    private function correctedCommercialMoneyByKey(array $scenarios, array $scope): array
    {
        $result = $this->calculator->calculate($scenarios, $scope, [], true);
        $money = [];
        $invalid = [];
        foreach ($result['scenarios'] as $scenario) {
            foreach ($scenario['positions'] as $position) {
                $price = $position['price_snapshot'];
                if (! is_array($price) || ! in_array($price['source_type'] ?? null, ['catalog', 'supplier', 'verified_analog'], true)
                    || $position['pricing_status'] !== 'calculated'
                    || ($price['conditions_missing'] ?? []) !== [] || $position['money_breakdown'] === null
                    || ! is_string($price['source_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $price['source_hash']) !== 1
                    || ! BigDecimal::of($position['total_cost'])->isGreaterThan(0)) {
                    $invalid[$position['key']] = true;

                    continue;
                }
                if (! isset($money[$position['key']]) || $scenario['id'] === 'base') {
                    $money[$position['key']] = ['price_snapshot' => $price, 'amount' => $position['total_cost']];
                }
            }
        }

        return array_diff_key($money, $invalid);
    }

    private function priceIdentity(array $snapshot): array
    {
        unset($snapshot['captured_at']);
        foreach ($snapshot as $key => $value) {
            if (is_array($value)) {
                $snapshot[$key] = $this->priceIdentity($value);
            }
        }

        return $snapshot;
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
                $details[$fact->id.':'.$id] = ['fact_id' => $fact->id, 'entity_id' => $fact->entityId, 'parameter' => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::parameter($fact->type),
                    'value' => $fact->value, 'unit' => $fact->unit, 'basis' => $fact->origin, 'verification_status' => $fact->status,
                    'evidence_id' => $id, 'source_type' => $proof->sourceType, 'source_version' => $proof->sourceVersion,
                    'document_id' => $documentId, 'page' => $proof->page, ...$proof->sourceDisplayLocator()];
            }
        }

        return array_values($details);
    }
}
