<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
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
}
