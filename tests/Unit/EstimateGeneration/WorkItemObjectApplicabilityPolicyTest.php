<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemObjectApplicabilityPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemObjectApplicabilityPolicyTest extends TestCase
{
    #[Test]
    public function residential_house_excludes_industrial_envelope_templates(): void
    {
        $analysis = ['object' => ['object_type' => 'residential_house']];

        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.wall_panels', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.panel_flashings', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.floor_concrete', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.floor_rebar', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('office.ceiling', $analysis));
        self::assertTrue(WorkItemObjectApplicabilityPolicy::allows('facade.area', $analysis));
    }

    #[Test]
    public function residential_description_is_used_when_object_type_is_generic(): void
    {
        $analysis = ['object' => [
            'object_type' => 'custom',
            'building_type' => 'custom',
            'description' => 'Индивидуальный жилой дом площадью 180 м2',
        ]];

        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.wall_panels', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.panel_flashings', $analysis));
        self::assertTrue(WorkItemObjectApplicabilityPolicy::allows('facade.area', $analysis));
    }
}
