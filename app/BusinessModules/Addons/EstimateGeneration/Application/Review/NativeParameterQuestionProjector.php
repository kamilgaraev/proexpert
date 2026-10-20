<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final class NativeParameterQuestionProjector
{
    public const CONTRACT = 'estimate-native-questions:v1';

    public function __construct(private readonly ?\Closure $translate = null) {}

    public function project(ProjectModelSnapshot $snapshot, string $token, array $sections, array $warnings,
        array $documentVersions, int $stateVersion, bool $editable, array $estimatingContext = []): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = array_column($snapshot->facts, null, 'id');
        $byParameter = $names = $facetParents = $conflicted = [];
        foreach ($snapshot->entities as $entity) {
            if (GeometryInventory::type($entity) === 'roof_facet' && is_string($entity->attributes['roof_id'] ?? null)) {
                $facetParents[$entity->attributes['roof_id']] = true;
            }
        }
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status !== 'unresolved') {
                continue;
            }
            foreach ($conflict->facts as $fact) {
                if (isset($facts[$fact->id]) && $facts[$fact->id]->version === $fact->version
                    && $facts[$fact->id]->sourceVersion === $fact->sourceVersion) {
                    $conflicted[$fact->entityId][FactVocabulary::parameter($fact->type)] = true;
                }
            }
        }
        foreach ($snapshot->facts as $fact) {
            $byParameter[$fact->entityId][FactVocabulary::parameter($fact->type)][] = $fact;
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null) && trim($fact->value['name']) !== '') {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $questions = $limitations = [];
        $singleStoreyHouse = $this->singleStoreyHouse($snapshot, $estimatingContext);
        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) === 'canonical_quantity_aggregate_unresolved'
                && in_array($warning['formula'] ?? null, ['foundation_beam_volume', 'foundation_slab_volume', 'foundation_volume'], true)) {
                continue;
            }
            if (($warning['code'] ?? null) !== 'canonical_quantity_unresolved') {
                $limitations['additional_review_required'] = true;

                continue;
            }
            $parent = $entities[$warning['entity_id'] ?? ''] ?? null;
            if ($parent === null || array_intersect($sections, FactVocabulary::entitySections(GeometryInventory::type($parent))) === []) {
                continue;
            }
            $formula = $warning['formula'] ?? '';
            if (! in_array($formula, ['floor_area', 'direct_floor_area', 'wall_net_area', 'sloped_roof_area', 'earthwork_volume',
                ...array_keys(FactVocabulary::foundationFormulas()), ...array_keys(FactVocabulary::engineeringFormulas())], true)) {
                $limitations['additional_review_required'] = true;

                continue;
            }
            foreach ($warning['inputs'] ?? [] as $issue) {
                $geometry = in_array($issue['code'] ?? null, ['geometry_operand_missing', 'geometry_operand_unresolved'], true);
                $reason = $geometry ? (($issue['code'] ?? null) === 'geometry_operand_missing' ? 'operand_missing' : ($issue['reason'] ?? null)) : ($issue['code'] ?? null);
                if (! in_array($reason, ['operand_missing', 'operand_not_confirmed', 'operand_origin_requires_confirmation', 'document_number_requires_confirmation'], true)) {
                    $limitations['additional_review_required'] = true;

                    continue;
                }
                $fact = $facts[$issue['fact_id'] ?? ''] ?? null;
                $entity = $entities[$geometry ? ($issue['entity_id'] ?? '') : ($fact?->entityId ?? $parent->id)] ?? null;
                if ($entity === null || ($geometry && $fact !== null && $fact->entityId !== $entity->id)) {
                    $limitations['source_review_required'] = true;

                    continue;
                }
                $type = GeometryInventory::type($entity);
                $parameter = $geometry ? ($issue['missing_operand'] ?? '') : ($fact?->type ?? ($issue['operand'] ?? ''));
                if ($parameter === 'measurement') {
                    $parameter = $formula === 'direct_floor_area' ? 'area' : (FactVocabulary::engineeringFormulas()[$formula]['parameter'] ?? '');
                }
                if (in_array($parameter, ['plan_areas', 'slope_rises', 'slope_runs'], true) && $type === 'roof') {
                    $parameter = isset($facetParents[$entity->id]) ? '' : ['plan_areas' => 'plan_area', 'slope_rises' => 'slope_rise', 'slope_runs' => 'slope_run'][$parameter];
                }
                $parameter = FactVocabulary::parameter($parameter);
                if (! FactVocabulary::supports($type, $parameter)
                    || array_intersect($sections, FactVocabulary::entitySections($type)) === []) {
                    $limitations['additional_review_required'] = true;

                    continue;
                }
                $previous = $byParameter[$entity->id][$parameter] ?? [];
                $manual = isset($entity->attributes['identity']['manual_key']) || isset($entity->attributes['properties']['identity']['manual_key']);
                $currentSource = $manual || ($documentVersions[$entity->id]['source_version'] ?? null) === $entity->sourceVersion;
                $writable = $editable && $currentSource && count($previous) <= 1
                    && ($previous === [] || $previous[0]->sourceVersion === $entity->sourceVersion)
                    && ! isset($conflicted[$entity->id][$parameter]);
                if (! $currentSource || count($previous) > 1 || isset($conflicted[$entity->id][$parameter])
                    || ($previous !== [] && $previous[0]->sourceVersion !== $entity->sourceVersion)) {
                    $limitations['source_review_required'] = true;
                }
                $key = 'native-parameter:'.hash('sha256', $entity->id.'|'.$parameter);
                $name = $names[$entity->id] ?? $entity->attributes['name'] ?? $entity->attributes['properties']['name'] ?? null;
                $name = is_string($name) && trim($name) !== '' ? mb_substr($name, 0, 160)
                    : $this->text('estimate_generation.native_questions.entities.'.$type);
                $unit = $parameter === 'equipment_count' ? 'count' : (in_array($parameter, ['area', 'plan_area'], true) ? 'm2' : 'm');
                $questions[$key] ??= ['key' => $key, 'question' => $this->text('estimate_generation.native_questions.question', [
                    'parameter' => $this->text('estimate_generation.native_questions.parameters.'.$parameter), 'object' => $name]),
                    'can_answer' => $writable, 'formula_ids' => [], 'answer' => ['kind' => 'project_parameter',
                        'entity_id' => $entity->id, 'entity_type' => $type, 'entity_name' => $name,
                        'parameter' => $parameter, 'unit' => $unit, 'entity_source_version' => $entity->sourceVersion],
                    'input_contract' => ['kind' => 'number', 'unit' => $unit, 'minimum' => '0',
                        'minimum_exclusive' => $parameter !== 'slope_rise', 'maximum' => '1000000000000',
                        'decimal_places' => $unit === 'count' ? 0 : 4]];
                $questions[$key]['can_answer'] = $questions[$key]['can_answer'] && $writable;
                $questions[$key]['formula_ids'][$formula] = $formula;
                if ($singleStoreyHouse && $type === 'foundation_beam' && $parameter === 'height'
                    && $formula === 'foundation_beam_volume' && $reason === 'operand_missing' && $previous === [] && $writable) {
                    $questions[$key]['estimating_suggestion'] = ['value' => '0.4', 'unit' => 'm', 'basis' => 'assumption',
                        'description' => $this->text('estimate_generation.native_questions.estimating_suggestions.single_storey_foundation_height')];
                }
            }
        }
        ksort($questions, SORT_STRING);
        foreach ($questions as &$question) {
            if (! $question['can_answer']) {
                unset($question['estimating_suggestion']);
            }
            sort($question['formula_ids'], SORT_STRING);
            $question['formula_ids'] = array_values($question['formula_ids']);
            $question['answer']['version'] = hash('sha256', CanonicalPipelineJson::encode([
                self::CONTRACT, $token, $stateVersion, $sections, $question,
            ]));
            $question['answer']['native_question'] = ['key' => $question['key'], 'version' => $question['answer']['version'],
                'input_fingerprint' => $token, 'entity_source_version' => $question['answer']['entity_source_version']];
        }
        unset($question);
        $total = count($questions);
        if ($total > 50) {
            $limitations['questions_truncated'] = true;
        }

        return ['contract_version' => self::CONTRACT, 'available' => true, 'input_fingerprint' => $token,
            'questions' => array_slice(array_values($questions), 0, 50), 'total_questions' => $total,
            'truncated' => $total > 50, 'limitations' => array_keys($limitations), 'cost_units' => '0'];
    }

    private function singleStoreyHouse(ProjectModelSnapshot $snapshot, array $context): bool
    {
        $type = $context['building_type'] ?? null;
        if (! is_string($type) || ! ObjectTypeSignalClassifier::isResidential($type)
            || ObjectTypeSignalClassifier::isIndustrial($type) || ! in_array($context['floors'] ?? null, [1, '1'], true)) {
            return false;
        }
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'floor_count' && ($fact->status !== 'confirmed' || ! in_array($fact->value, [1, '1', 1.0, '1.0'], true))) {
                return false;
            }
        }
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status === 'unresolved') {
                foreach ($conflict->facts as $fact) {
                    if ($fact->type === 'floor_count') {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function text(string $key, array $parameters = []): string
    {
        return $this->translate === null ? trans_message($key, $parameters) : ($this->translate)($key, $parameters);
    }
}
