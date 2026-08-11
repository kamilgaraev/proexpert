<?php

declare(strict_types=1);

$package = static function (string $id, string $input, string $unit): array {
    $prepare = 'work:'.$id.':prepare';
    $execute = 'work:'.$id.':execute';

    return [
        'works' => [
            ['id' => $prepare, 'name_key' => 'estimate_generation.completeness.'.$id.'.prepare'],
            ['id' => $execute, 'name_key' => 'estimate_generation.completeness.'.$id.'.execute'],
        ],
        'materials' => [['id' => 'material:'.$id, 'name_key' => 'estimate_generation.completeness.'.$id.'.material']],
        'machinery' => [['id' => 'machinery:'.$id, 'name_key' => 'estimate_generation.completeness.'.$id.'.machinery']],
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
    array $applicability,
    string $satisfaction,
    string $classification,
    string $input,
    string $unit,
) use ($package): array {
    return [
        'id' => $id,
        'version' => '1.0.0',
        'applicability_fact_types' => $applicability,
        'satisfaction_fact_type' => $satisfaction,
        'classification' => $classification,
        'severity' => $classification === 'optional_recommendation' ? 'notice' : 'warning',
        'impact' => 'estimate_generation.completeness.'.$id.'.impact',
        'exclusion_policy' => [
            'requires_decision' => true,
            'requires_actor' => true,
            'requires_reason' => true,
        ],
        'work_package' => $package($id, $input, $unit),
    ];
};

return [
    'version' => '2026.08.11-v1',
    'rules' => [
        $rule('site_leveling', ['site_work'], 'site_leveling_specification', 'document_missing', 'site_area', 'm2'),
        $rule('base_preparation', ['foundation_type'], 'foundation_base_preparation', 'technology_required', 'foundation_area', 'm2'),
        $rule('waterproofing', ['below_grade_structure'], 'waterproofing_specification', 'technology_required', 'waterproofing_area', 'm2'),
        $rule('scaffolding', ['work_height_m'], 'temporary_access_specification', 'optional_recommendation', 'facade_area', 'm2'),
        $rule('fasteners', ['roof_type'], 'roof_fastener_specification', 'technology_required', 'roof_area', 'm2'),
        $rule('waste_removal', ['demolition_or_waste_generation'], 'waste_removal_plan', 'optional_recommendation', 'waste_volume', 'm3'),
        $rule('system_testing', ['engineering_system'], 'test_program', 'document_missing', 'engineering_system_count', 'item'),
        $rule('landscaping_restoration', ['external_site_disturbance'], 'landscaping_restoration', 'technology_required', 'disturbed_area', 'm2'),
    ],
];
