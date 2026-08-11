<?php

declare(strict_types=1);

$package = static function (string $id, string $input, string $unit): array {
    $prepare = 'work:'.$id.':prepare';
    $execute = 'work:'.$id.':execute';

    return [
        'id' => 'package:'.$id,
        'works' => [
            ['id' => $prepare, 'name_key' => 'estimate_generation.planning.completeness.'.$id.'.prepare'],
            ['id' => $execute, 'name_key' => 'estimate_generation.planning.completeness.'.$id.'.execute'],
        ],
        'materials' => [['id' => 'material:'.$id, 'name_key' => 'estimate_generation.planning.completeness.'.$id.'.material']],
        'machinery' => [['id' => 'machinery:'.$id, 'name_key' => 'estimate_generation.planning.completeness.'.$id.'.machinery']],
        'norm_intents' => [['id' => 'norm:'.$id, 'candidate_refs' => ['fsnb:intent:'.$id], 'max_candidates' => 5]],
        'quantity_formulas' => [[
            'id' => 'formula:'.$id,
            'expression' => $input.' × 1',
            'input_fact' => $input,
            'unit' => $unit,
        ]],
        'dependencies' => [['from' => $prepare, 'to' => $execute]],
        'regional_price_availability' => [
            'available' => false,
            'region' => null,
            'source' => null,
            'version' => null,
            'reason' => 'price_check_required',
        ],
        'assumptions' => ['scope_requires_confirmation'],
        'risks' => ['quantity_or_conditions_may_change'],
    ];
};

$rule = static function (
    string $id,
    array $conditions,
    string $satisfaction,
    string $classification,
    string $input,
    string $unit,
) use ($package): array {
    return [
        'id' => $id,
        'version' => '1.0.0',
        'applicability_fact_types' => array_values(array_unique(array_column($conditions, 'fact_type'))),
        'conditions' => $conditions,
        'satisfaction_fact_type' => $satisfaction,
        'satisfaction' => ['fact_type' => $satisfaction, 'operator' => 'present', 'false_means_missing' => true],
        'classification' => $classification,
        'severity' => $classification === 'optional_recommendation' ? 'notice' : 'warning',
        'impact' => 'estimate_generation.planning.completeness.'.$id.'.impact',
        'exclusion_policy' => [
            'id' => 'user_scope_exclusion',
            'version' => '1.0.0',
            'allowed' => true,
            'requires_decision' => true,
            'requires_actor' => true,
            'requires_reason' => true,
        ],
        'work_package' => $package($id, $input, $unit),
    ];
};

$basePreparation = $rule(
    'base_preparation',
    [['fact_type' => 'foundation_type', 'operator' => 'in', 'values' => ['slab', 'strip', 'pile', 'columnar']]],
    'foundation_base_preparation',
    'technology_required',
    'foundation_area',
    'm2',
);
$basePreparation['work_package']['variant_fact_type'] = 'foundation_type';
$basePreparation['work_package']['variants'] = [
    'slab' => $package('base_preparation_slab', 'foundation_area', 'm2'),
    'strip' => $package('base_preparation_strip', 'foundation_area', 'm2'),
    'pile' => $package('base_preparation_pile', 'pile_count', 'item'),
    'columnar' => $package('base_preparation_columnar', 'foundation_column_count', 'item'),
];

return [
    'version' => '2026.08.11-v1',
    'rules' => [
        $rule('site_leveling', [['fact_type' => 'site_work', 'operator' => '=', 'value' => true]], 'site_leveling_specification', 'document_missing', 'site_area', 'm2'),
        $basePreparation,
        $rule('waterproofing', [['fact_type' => 'below_grade_structure', 'operator' => '=', 'value' => true]], 'waterproofing_specification', 'technology_required', 'waterproofing_area', 'm2'),
        $rule('scaffolding', [['fact_type' => 'work_height_m', 'operator' => '>=', 'value' => 3, 'unit' => 'm']], 'temporary_access_specification', 'optional_recommendation', 'facade_area', 'm2'),
        array_replace_recursive(
            $rule('fasteners', [['fact_type' => 'roof_type', 'operator' => 'in', 'values' => ['pitched', 'gable', 'hipped']]], 'roof_fastener_specification', 'technology_required', 'roof_area', 'm2'),
            ['technology_requirement' => ['decision_kind' => 'roof_covering_system', 'allow_recommended_applicable' => true]],
        ),
        $rule('waste_removal', [['fact_type' => 'demolition_or_waste_generation', 'operator' => '=', 'value' => true]], 'waste_removal_plan', 'optional_recommendation', 'waste_volume', 'm3'),
        $rule('system_testing', [['fact_type' => 'engineering_system', 'operator' => 'present']], 'test_program', 'document_missing', 'engineering_system_count', 'item'),
        $rule('landscaping_restoration', [['fact_type' => 'external_site_disturbance', 'operator' => '=', 'value' => true]], 'landscaping_restoration', 'technology_required', 'disturbed_area', 'm2'),
    ],
];
