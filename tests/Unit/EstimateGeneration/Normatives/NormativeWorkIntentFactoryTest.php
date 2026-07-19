<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use PHPUnit\Framework\TestCase;

final class NormativeWorkIntentFactoryTest extends TestCase
{
    public function test_building_object_type_comes_from_plan_context_not_work_structure(): void
    {
        $intent = (new NormativeWorkIntentFactory)->intent([
            'key' => 'earthwork',
            'name' => 'Разработка грунта под фундаменты',
            'unit' => 'm3',
            'work_intent' => [
                'material' => null,
                'action' => 'excavation',
                'scope' => 'foundation',
                'object' => 'foundation',
                'expected_dimensions' => ['volume'],
                'preferred_section_prefixes' => ['01'],
            ],
        ], [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('residential', $intent->objectType);
        self::assertSame('foundation', $intent->structure);
    }

    public function test_multiple_allowed_sections_do_not_collapse_to_the_first_prefix(): void
    {
        $intent = (new NormativeWorkIntentFactory)->intent([
            'key' => 'foundation.concrete', 'name' => 'Бетонирование фундаментов', 'unit' => 'm3',
            'work_intent' => [
                'material' => 'concrete', 'action' => 'concreting', 'scope' => 'foundation',
                'object' => 'foundation', 'expected_dimensions' => ['volume'],
                'preferred_section_prefixes' => ['01', '06'],
            ],
        ], [
            'organization_id' => 1, 'project_id' => 89, 'session_id' => 58,
            'object_type' => 'house', 'applicability_date' => '2026-07-17', 'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('', $intent->normativeSection);
        self::assertSame(['01', '06'], $intent->normativeSections);
    }

    public function test_unrecorded_work_intent_keeps_all_sections_from_classifier(): void
    {
        $factory = new NormativeWorkIntentFactory(new WorkIntentClassifier(new NormativeScopeRuleCatalog));

        $intent = $factory->intent([
            'key' => 'foundation.concrete', 'name' => 'Бетонирование фундаментов', 'unit' => 'm3',
        ], [
            'organization_id' => 1, 'project_id' => 89, 'session_id' => 58, 'scope_type' => 'foundation',
            'object_type' => 'house', 'applicability_date' => '2026-07-17', 'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('', $intent->normativeSection);
        self::assertSame(['01', '06'], $intent->normativeSections);
    }

    public function test_partial_recorded_intent_is_completed_from_work_semantics(): void
    {
        $factory = new NormativeWorkIntentFactory(new WorkIntentClassifier(new NormativeScopeRuleCatalog));

        $intent = $factory->intent([
            'key' => 'earth.backfill',
            'name' => 'Обратная засыпка пазух с уплотнением',
            'unit' => 'm3',
            'work_intent' => ['scope' => 'foundation'],
        ], [
            'organization_id' => 1, 'project_id' => 89, 'session_id' => 58,
            'scope_type' => 'foundation', 'object_type' => 'house',
            'applicability_date' => '2026-07-17', 'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('backfill', $intent->technology);
        self::assertSame('foundation', $intent->structure);
        self::assertSame(['01'], $intent->normativeSections);
    }

    public function test_recorded_model_intent_cannot_override_strong_deterministic_semantics(): void
    {
        $factory = new NormativeWorkIntentFactory(new WorkIntentClassifier(new NormativeScopeRuleCatalog));

        $intent = $factory->intent([
            'key' => 'electrical.trays',
            'name' => 'Монтаж кабельных лотков',
            'unit' => 'm',
            'work_intent' => [
                'scope' => 'engineering',
                'action' => 'cable_installation',
                'system' => 'electrical',
            ],
        ], [
            'organization_id' => 1, 'project_id' => 89, 'session_id' => 58,
            'scope_type' => 'engineering', 'object_type' => 'house',
            'applicability_date' => '2026-07-17', 'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('cable_tray_installation', $intent->technology);
        self::assertSame('electrical', $intent->system);
    }

    public function test_specialization_provenance_is_limited_to_current_source_evidence(): void
    {
        $intent = (new NormativeWorkIntentFactory(new WorkIntentClassifier(new NormativeScopeRuleCatalog)))->intent([
            'key' => 'finish.floor',
            'name' => 'Чистовое покрытие пола',
            'unit' => 'm2',
            'work_intent' => [
                'scope' => 'finishing',
                'action' => 'floor_covering',
                'specialization_evidence' => [
                    [
                        'text' => 'Ведомость отделки: линолеум',
                        'source' => 'document',
                        'evidence_refs' => ['doc:1'],
                    ],
                    [
                        'text' => 'Паркет',
                        'source' => 'document',
                        'evidence_refs' => ['doc:forged'],
                    ],
                ],
            ],
        ], [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'scope_type' => 'finishing',
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame([[
            'text' => 'Ведомость отделки: линолеум',
            'source' => 'document',
            'evidence_refs' => ['doc:1'],
        ]], $intent->specializationEvidence);
    }

    public function test_only_catalog_signed_scenario_reaches_normative_intent(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $factory = new NormativeWorkIntentFactory(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
            null,
            $catalog,
        );
        $context = [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'scope_type' => 'finishing',
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => ['doc:1'],
        ];
        $item = [
            'key' => 'finish-norm-intent-1',
            'name' => 'Чистовое покрытие пола',
            'unit' => 'm2',
            'metadata' => ['quantity_key' => 'finish.floor'],
            'work_intent' => [
                'scope' => 'finishing',
                'action' => 'floor_covering',
                'specialization_scenario' => [
                    'version' => 'residential_finish_material:v1',
                    'text' => 'паркет',
                ],
            ],
        ];

        self::assertNull($factory->intent($item, $context, 'fsnb-2026.1')->specializationScenario);

        $item['specialization_scenario'] = $catalog->issue('finish.floor', 'residential');
        $intent = $factory->intent($item, $context, 'fsnb-2026.1');

        self::assertSame(['ламинат', 'ламинированн'], $intent->specializationScenario['material_markers'] ?? null);
        self::assertSame('residential_preliminary_common:v3', $intent->specializationScenario['scenario_id'] ?? null);
    }

    public function test_trusted_specialization_evidence_suppresses_preliminary_scenario(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $factory = new NormativeWorkIntentFactory(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
            null,
            $catalog,
        );
        $scenario = $catalog->issue('finish.floor', 'residential');
        self::assertIsArray($scenario);

        $intent = $factory->intent([
            'key' => 'finish-floor',
            'name' => 'Чистовое покрытие пола',
            'unit' => 'm2',
            'metadata' => ['quantity_key' => 'finish.floor'],
            'specialization_scenario' => $scenario,
            'specialization_evidence' => [[
                'text' => 'Ведомость отделки: линолеум',
                'source' => 'document',
                'evidence_refs' => ['doc:1'],
            ]],
        ], [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'scope_type' => 'finishing',
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => ['doc:1'],
        ], 'fsnb-2026.1');

        self::assertSame('Ведомость отделки: линолеум', $intent->specializationEvidence[0]['text'] ?? null);
        self::assertNull($intent->specializationScenario);
    }

    public function test_structured_source_reference_preserves_matching_specialization_evidence(): void
    {
        $factory = new NormativeWorkIntentFactory(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
        );

        $intent = $factory->intent([
            'key' => 'external-wall',
            'name' => 'Кладка наружных стен',
            'normative_search_text' => 'кладка наружных стен из кирпича',
            'unit' => 'm3',
            'metadata' => ['quantity_key' => 'walls.external_volume'],
            'specialization_evidence' => [[
                'text' => 'Материал наружной стены: кирпич',
                'source' => 'building_model',
                'evidence_refs' => ['14201'],
            ]],
        ], [
            'organization_id' => 1,
            'project_id' => 89,
            'session_id' => 58,
            'scope_type' => 'walls',
            'object_type' => 'house',
            'applicability_date' => '2026-07-17',
            'source_refs' => [['evidence_id' => '14201']],
        ], 'fsnb-2026.1');

        self::assertSame(['14201'], $intent->sourceEvidence);
        self::assertSame(['14201'], $intent->specializationEvidence[0]['evidence_refs'] ?? null);
        self::assertSame('brick', $intent->material);
        self::assertNull($intent->specializationScenario);
    }
}
