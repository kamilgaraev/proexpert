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
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('sewerage.risers', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('sewerage.revisions', $analysis));
        self::assertTrue(WorkItemObjectApplicabilityPolicy::allows('sewerage.pipe', $analysis));
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

    #[Test]
    public function unknown_and_custom_objects_fail_closed_for_office_and_warehouse_work(): void
    {
        foreach (['custom', 'cottage', 'private_house'] as $objectType) {
            $analysis = ['object' => ['object_type' => $objectType]];

            self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('office.partitions', $analysis), $objectType);
            self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('office.ceiling', $analysis), $objectType);
            self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('warehouse.wall_panels', $analysis), $objectType);
            self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('ventilation.warehouse_points', $analysis), $objectType);
        }
    }

    #[Test]
    public function explicit_warehouse_allows_only_warehouse_work(): void
    {
        $analysis = ['object' => ['object_type' => 'warehouse']];

        self::assertTrue(WorkItemObjectApplicabilityPolicy::allows('warehouse.wall_panels', $analysis));
        self::assertTrue(WorkItemObjectApplicabilityPolicy::allows('ventilation.warehouse_points', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('office.partitions', $analysis));
        self::assertFalse(WorkItemObjectApplicabilityPolicy::allows('ventilation.office_points', $analysis));
    }
}
