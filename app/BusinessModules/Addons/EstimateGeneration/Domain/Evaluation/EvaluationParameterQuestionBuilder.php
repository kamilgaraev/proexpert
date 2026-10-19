<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;

/** Pure presentation of unresolved server formulas; questions never create measured facts. */
final class EvaluationParameterQuestionBuilder
{
    private const LABELS = ['area' => 'площадь', 'length' => 'длину', 'width' => 'ширину', 'height' => 'высоту',
        'thickness' => 'толщину', 'depth' => 'глубину', 'plan_area' => 'площадь в плане',
        'slope_rise' => 'подъём ската', 'slope_run' => 'горизонтальную проекцию ската',
        'equipment_count' => 'количество оборудования', 'pipe_length' => 'длину трубопровода', 'cable_length' => 'длину кабеля', 'duct_length' => 'длину воздуховода'];

    private const ENTITIES = ['room' => 'Помещение', 'wall' => 'Стена', 'opening' => 'Проём в стене',
        'site' => 'Участок земляных работ', 'roof' => 'Кровля', 'roof_facet' => 'Грань кровли', 'roof_opening' => 'Проём в кровле',
        'foundation_beam' => 'Фундаментная балка', 'foundation_slab' => 'Фундаментная плита',
        'equipment' => 'Оборудование', 'radiator' => 'Радиатор', 'water_pipe' => 'Трубопровод водоснабжения',
        'sewer_pipe' => 'Трубопровод канализации', 'technological_pipeline' => 'Технологический трубопровод',
        'electrical_circuit' => 'Кабельная цепь', 'ventilation_duct' => 'Воздуховод'];

    public function candidates(array $positions, ProjectModelSnapshot $snapshot, array $warnings): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = array_column($snapshot->facts, null, 'id');
        $names = $supplied = $parameterVersions = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed'
                && is_array($fact->value) && is_string($fact->value['name'] ?? null)) {
                $names[$fact->entityId] = $fact->value['name'];
            }
            $parameter = FactVocabulary::parameter($fact->type);
            $parameterVersions[$fact->entityId][$parameter][$fact->id] = [
                'version' => $fact->version, 'source_version' => $fact->sourceVersion, 'value' => $fact->value,
                'unit' => $fact->unit, 'origin' => $fact->origin, 'status' => $fact->status, 'evidence_ids' => $fact->evidenceIds,
            ];
            if ($fact->status === 'confirmed' && $fact->origin === 'user_input' && is_numeric($fact->value)
                && FactVocabulary::factor($parameter === 'equipment_count' ? 'count' : (in_array($parameter, ['area', 'plan_area'], true) ? 'area' : 'length'), $fact->unit ?? '') !== null) {
                $supplied[$fact->entityId][$parameter] = true;
            }
        }
        $byEntity = [];
        foreach ($warnings as $warning) {
            if (is_array($warning) && ($warning['code'] ?? null) === 'canonical_quantity_unresolved'
                && is_string($warning['entity_id'] ?? null)) {
                $byEntity[$warning['entity_id']][] = $warning;
            }
        }
        $mapper = new WorkItemQuantityMapper;
        $groups = [];
        foreach ($positions as $position) {
            if ($position['quantity'] !== null || ! is_string($position['entity_id'] ?? null)) {
                continue;
            }
            foreach ($byEntity[$position['entity_id']] ?? [] as $warning) {
                $formula = (string) ($warning['formula'] ?? '');
                $purpose = CurrentProjectDerivedQuantityService::formulaPurpose($formula);
                if (! is_string($purpose) || ! is_string($position['unit'] ?? null)
                    || ! $mapper->acceptsExactSource($position['work_key'], $purpose, $position['unit'])) {
                    continue;
                }
                foreach ($warning['inputs'] ?? [] as $issue) {
                    if (! is_array($issue) || ! in_array($issue['code'] ?? null, [
                        'operand_missing', 'operand_not_confirmed', 'operand_origin_requires_confirmation', 'engineering_measurement_incompatible',
                    ], true)) {
                        continue;
                    }
                    $fact = $facts[$issue['fact_id'] ?? ''] ?? null;
                    $entity = $entities[$fact?->entityId ?? $warning['entity_id']] ?? null;
                    $type = $entity?->attributes['semantic_type'] ?? $entity?->attributes['properties']['semantic_type'] ?? $entity?->type;
                    $parameter = FactVocabulary::parameter($fact?->type ?? (string) ($issue['operand'] ?? ''));
                    if ($parameter === 'measurement' && $warning['formula'] === 'direct_floor_area') {
                        $parameter = 'area';
                    }
                    if ($parameter === 'measurement' && isset(FactVocabulary::engineeringFormulas()[$formula])) {
                        $parameter = FactVocabulary::engineeringFormulas()[$formula]['parameter'];
                    }
                    if ($entity === null || ! is_string($type) || ! isset(self::ENTITIES[$type], self::LABELS[$parameter])
                        || ! FactVocabulary::supports($type, $parameter)) {
                        continue;
                    }
                    // A stale missing-input warning must not ask again for an already supplied parameter.
                    if ($issue['code'] === 'operand_missing' && isset($supplied[$entity->id][$parameter])) {
                        continue;
                    }
                    $name = $names[$entity->id] ?? (is_string($entity->attributes['name'] ?? null) && trim($entity->attributes['name']) !== ''
                        ? mb_substr($entity->attributes['name'], 0, 160) : self::ENTITIES[$type]);
                    $key = 'parameter:'.$entity->id.':'.$parameter;
                    $unit = $parameter === 'equipment_count' ? 'count' : (in_array($parameter, ['area', 'plan_area'], true) ? 'm2' : 'm');
                    $groups[$key] ??= ['key' => $key, 'question' => 'Уточните '.self::LABELS[$parameter].' объекта «'.$name.'».',
                        'affected_position_keys' => [], 'correctness_blocker' => true, 'cost_blocker' => true, 'easy_to_answer' => true,
                        'next_action' => 'confirm_project_parameter', 'effect' => 'Ответ уточнит объём и стоимость связанных работ.',
                        'answer' => ['kind' => 'project_parameter', 'entity_id' => $entity->id, 'entity_type' => $type,
                            'entity_name' => $name, 'parameter' => $parameter, 'unit' => $unit]];
                    $groups[$key]['affected_position_keys'][] = $position['key'];
                }
            }
        }
        foreach ($groups as &$group) {
            $group['affected_position_keys'] = array_values(array_unique($group['affected_position_keys']));
            $answer = $group['answer'];
            $entity = $entities[$answer['entity_id']];
            $group['answer']['version'] = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
                'entity' => [$entity->id, $entity->sourceVersion, $entity->attributes], 'parameter' => $answer['parameter'],
                'name' => $answer['entity_name'], 'facts' => $parameterVersions[$entity->id][$answer['parameter']] ?? [],
            ]));
        }
        unset($group);

        return array_values($groups);
    }
}
