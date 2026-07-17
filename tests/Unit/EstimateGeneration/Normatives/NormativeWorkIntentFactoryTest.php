<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
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
}
