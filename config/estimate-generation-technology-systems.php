<?php

declare(strict_types=1);

$unavailablePrice = [
    'available' => false,
    'region' => null,
    'source' => null,
    'version' => null,
    'reason' => 'requires_regional_price_resolution',
];
$unavailableCost = [
    'available' => false,
    'currency' => null,
    'region' => null,
    'source' => null,
    'version' => null,
    'amount_minor' => null,
    'reason' => 'requires_quantities_and_regional_prices',
];
$workPlanningBySystem = [
    'pitched_roof.metal_tile' => [
        'install_underlay_membrane' => ['roof_covering_area', 'fsnb.roof.metal_profile_installation'],
        'install_counter_batten' => ['batten_length', 'fsnb.roof.timber_batten_installation'],
        'install_batten' => ['batten_length', 'fsnb.roof.timber_batten_installation'],
        'install_metal_tile' => ['roof_covering_area', 'fsnb.roof.metal_profile_installation'],
        'install_roof_accessories_and_flashings' => ['roof_covering_area', 'fsnb.roof.metal_profile_installation'],
    ],
    'pitched_roof.flexible_shingle' => [
        'install_solid_deck' => ['solid_deck_area', 'fsnb.roof.solid_deck_installation'],
        'install_underlay_carpet' => ['roof_covering_area', 'fsnb.roof.flexible_shingle_installation'],
        'install_flexible_shingle' => ['roof_covering_area', 'fsnb.roof.flexible_shingle_installation'],
        'install_roof_accessories_and_flashings' => ['roof_covering_area', 'fsnb.roof.flexible_shingle_installation'],
    ],
    'pitched_roof.standing_seam' => [
        'prepare_standing_seam_base' => ['roof_panel_area', 'fsnb.roof.metal_base_installation'],
        'install_separation_membrane' => ['roof_panel_area', 'fsnb.roof.standing_seam_installation'],
        'install_standing_seam_panels' => ['roof_panel_area', 'fsnb.roof.standing_seam_installation'],
        'form_seams_and_flashings' => ['seam_length', 'fsnb.roof.standing_seam_installation'],
    ],
];

$catalog = [
    'version' => '2026.08.11-v1',
    'recommendation_required_facts' => ['roof_type', 'roof_slope_degrees', 'roof_geometry'],
    'systems' => [
        [
            'id' => 'pitched_roof.metal_tile',
            'name_key' => 'estimate_generation.planning.technology.system.metal_tile',
            'applicability' => [['roof_type' => 'pitched'], ['minimum_slope_degrees' => '14']],
            'required_facts' => ['roof_area', 'roof_slope_degrees', 'roof_geometry'],
            'materials' => [
                ['intent' => 'metal_tile_sheet'], ['intent' => 'underlay_membrane'],
                ['intent' => 'counter_batten'], ['intent' => 'batten'], ['intent' => 'roof_fastener'],
                ['intent' => 'ridge_eaves_valley_and_abutment_elements'],
            ],
            'works' => [
                ['intent' => 'install_underlay_membrane'], ['intent' => 'install_counter_batten'],
                ['intent' => 'install_batten'], ['intent' => 'install_metal_tile'],
                ['intent' => 'install_roof_accessories_and_flashings'],
            ],
            'machinery' => [['intent' => 'lifting_equipment'], ['intent' => 'roof_access_equipment']],
            'norm_intents' => [
                ['stable_intent' => 'fsnb.roof.metal_profile_installation', 'max_candidates' => 5],
                ['stable_intent' => 'fsnb.roof.timber_batten_installation', 'max_candidates' => 5],
            ],
            'quantity_formulas' => [
                ['id' => 'roof_covering_area', 'expression' => 'roof_slope_area * waste_factor', 'operands' => ['roof_slope_area', 'waste_factor']],
                ['id' => 'batten_length', 'expression' => 'roof_slope_area / batten_spacing', 'operands' => ['roof_slope_area', 'batten_spacing']],
            ],
            'regional_price_availability' => $unavailablePrice,
            'cost_preview' => $unavailableCost,
            'risks' => ['requires_condensation_control', 'cut_edge_corrosion', 'wind_fastening_pattern'],
            'assumptions' => ['timber_substructure_is_permitted', 'roof_geometry_is_compatible_with_sheet_layout'],
            'score_rules' => [
                ['fact_type' => 'roof_type', 'values' => ['pitched'], 'score' => 20, 'reason' => 'pitched_roof_compatible'],
                ['fact_type' => 'roof_slope_degrees', 'min' => 14, 'score' => 6, 'reason' => 'slope_compatible'],
                ['fact_type' => 'roof_geometry', 'values' => ['simple_gable', 'simple_hip'], 'score' => 8, 'reason' => 'efficient_sheet_layout'],
                ['fact_type' => 'building_purpose', 'values' => ['residential', 'commercial'], 'score' => 4, 'reason' => 'purpose_compatible'],
                ['fact_type' => 'climate_zone', 'values' => ['snow_2', 'snow_3', 'snow_4'], 'score' => 3, 'reason' => 'climate_compatible'],
            ],
            'provenance' => [['source' => 'technology_catalog'], ['catalog_section' => 'pitched_roof_coverings']],
        ],
        [
            'id' => 'pitched_roof.flexible_shingle',
            'name_key' => 'estimate_generation.planning.technology.system.flexible_shingle',
            'applicability' => [['roof_type' => 'pitched'], ['minimum_slope_degrees' => '12']],
            'required_facts' => ['roof_area', 'roof_slope_degrees', 'roof_geometry', 'solid_deck_type'],
            'materials' => [
                ['intent' => 'flexible_shingle'], ['intent' => 'underlay_carpet'], ['intent' => 'solid_deck'],
                ['intent' => 'roofing_nails'], ['intent' => 'bitumen_mastic'], ['intent' => 'ridge_eaves_and_valley_elements'],
            ],
            'works' => [
                ['intent' => 'install_solid_deck'], ['intent' => 'install_underlay_carpet'],
                ['intent' => 'install_flexible_shingle'], ['intent' => 'install_roof_accessories_and_flashings'],
            ],
            'machinery' => [['intent' => 'lifting_equipment'], ['intent' => 'roof_access_equipment']],
            'norm_intents' => [
                ['stable_intent' => 'fsnb.roof.flexible_shingle_installation', 'max_candidates' => 5],
                ['stable_intent' => 'fsnb.roof.solid_deck_installation', 'max_candidates' => 5],
            ],
            'quantity_formulas' => [
                ['id' => 'roof_covering_area', 'expression' => 'roof_slope_area * waste_factor', 'operands' => ['roof_slope_area', 'waste_factor']],
                ['id' => 'solid_deck_area', 'expression' => 'roof_slope_area', 'operands' => ['roof_slope_area']],
            ],
            'regional_price_availability' => $unavailablePrice,
            'cost_preview' => $unavailableCost,
            'risks' => ['requires_ventilated_solid_deck', 'temperature_sensitive_installation', 'higher_waste_on_valleys'],
            'assumptions' => ['solid_deck_load_is_acceptable', 'ventilation_gap_can_be_provided'],
            'score_rules' => [
                ['fact_type' => 'roof_type', 'values' => ['pitched'], 'score' => 20, 'reason' => 'pitched_roof_compatible'],
                ['fact_type' => 'roof_slope_degrees', 'min' => 12, 'score' => 5, 'reason' => 'slope_compatible'],
                ['fact_type' => 'roof_geometry', 'values' => ['complex', 'multi_gable'], 'score' => 12, 'reason' => 'complex_geometry_compatible'],
                ['fact_type' => 'roof_geometry', 'values' => ['simple_gable', 'simple_hip'], 'score' => 3, 'reason' => 'standard_geometry_compatible'],
                ['fact_type' => 'solid_deck_type', 'values' => ['osb', 'plywood'], 'score' => 8, 'reason' => 'existing_solid_deck'],
                ['fact_type' => 'building_purpose', 'values' => ['residential'], 'score' => 3, 'reason' => 'purpose_compatible'],
            ],
            'provenance' => [['source' => 'technology_catalog'], ['catalog_section' => 'pitched_roof_coverings']],
        ],
        [
            'id' => 'pitched_roof.standing_seam',
            'name_key' => 'estimate_generation.planning.technology.system.standing_seam',
            'applicability' => [['roof_type' => 'pitched'], ['minimum_slope_degrees' => '3']],
            'required_facts' => ['roof_area', 'roof_slope_degrees', 'roof_geometry', 'deck_or_batten_type'],
            'materials' => [
                ['intent' => 'standing_seam_panel'], ['intent' => 'separation_membrane'],
                ['intent' => 'deck_or_batten'], ['intent' => 'concealed_clip'], ['intent' => 'ridge_eaves_valley_and_abutment_elements'],
            ],
            'works' => [
                ['intent' => 'prepare_standing_seam_base'], ['intent' => 'install_separation_membrane'],
                ['intent' => 'install_standing_seam_panels'], ['intent' => 'form_seams_and_flashings'],
            ],
            'machinery' => [['intent' => 'sheet_forming_or_seaming_equipment'], ['intent' => 'lifting_equipment'], ['intent' => 'roof_access_equipment']],
            'norm_intents' => [
                ['stable_intent' => 'fsnb.roof.standing_seam_installation', 'max_candidates' => 5],
                ['stable_intent' => 'fsnb.roof.metal_base_installation', 'max_candidates' => 5],
            ],
            'quantity_formulas' => [
                ['id' => 'roof_panel_area', 'expression' => 'roof_slope_area * waste_factor', 'operands' => ['roof_slope_area', 'waste_factor']],
                ['id' => 'seam_length', 'expression' => 'roof_slope_area / effective_panel_width', 'operands' => ['roof_slope_area', 'effective_panel_width']],
            ],
            'regional_price_availability' => $unavailablePrice,
            'cost_preview' => $unavailableCost,
            'risks' => ['requires_skilled_installers', 'thermal_movement', 'wind_clip_spacing'],
            'assumptions' => ['compatible_metal_grade_will_be_selected', 'continuous_panel_length_is_logistically_possible'],
            'score_rules' => [
                ['fact_type' => 'roof_type', 'values' => ['pitched'], 'score' => 20, 'reason' => 'pitched_roof_compatible'],
                ['fact_type' => 'roof_slope_degrees', 'min' => 3, 'score' => 10, 'reason' => 'low_slope_compatible'],
                ['fact_type' => 'roof_geometry', 'values' => ['simple_gable'], 'score' => 5, 'reason' => 'continuous_panel_layout'],
                ['fact_type' => 'climate_zone', 'values' => ['wind_4', 'wind_5', 'snow_4'], 'score' => 7, 'reason' => 'enhanced_climate_resistance'],
                ['fact_type' => 'design_priority', 'values' => ['durability', 'architectural'], 'score' => 8, 'reason' => 'design_priority_compatible'],
            ],
            'provenance' => [['source' => 'technology_catalog'], ['catalog_section' => 'pitched_roof_coverings']],
        ],
    ],
];

$unitByOperand = [
    'roof_area' => 'm2',
    'waste_factor' => 'ratio',
    'batten_spacing' => 'm',
    'effective_panel_width' => 'm',
];
foreach ($catalog['systems'] as &$system) {
    foreach (['materials', 'machinery'] as $collection) {
        foreach ($system[$collection] as &$item) {
            $item['id'] = $collection.'.'.$item['intent'];
        }
        unset($item);
    }
    $previousWorkId = null;
    foreach ($system['works'] as &$work) {
        $work['id'] = 'works.'.$work['intent'];
        [$work['quantity_formula_id'], $work['norm_intent_id']] =
            $workPlanningBySystem[$system['id']][$work['intent']];
        $work['depends_on'] = $previousWorkId === null ? [] : [$previousWorkId];
        $previousWorkId = $work['id'];
    }
    unset($work);
    foreach ($system['norm_intents'] as &$intent) {
        $intent['id'] = $intent['stable_intent'];
    }
    unset($intent);
    foreach ($system['quantity_formulas'] as &$formula) {
        $formula['expression'] = str_replace('roof_slope_area', 'roof_area', $formula['expression']);
        $operandNames = array_map(
            static fn (string $name): string => $name === 'roof_slope_area' ? 'roof_area' : $name,
            $formula['operands'],
        );
        $formula['result_unit'] = str_contains($formula['id'], 'area') ? 'm2' : 'm';
        $formula['operands'] = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'type' => $name === 'roof_area' ? 'fact' : 'parameter',
                'unit' => $unitByOperand[$name],
            ],
            $operandNames,
        );
    }
    unset($formula);
}
unset($system);

return $catalog;
