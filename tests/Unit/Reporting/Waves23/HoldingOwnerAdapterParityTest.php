<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldingOwnerAdapterParityTest extends TestCase
{
    #[Test]
    public function fixed_and_percentage_allocations_match_hand_calculated_owner_fixture(): void
    {
        $projector = new HoldingAllocationFactProjector();

        $fixed = $projector->project([
            'organization_id' => 1,
            'holding_id' => 5,
            'contributor_organization_id' => 10,
            'project_id' => 100,
            'contract_id' => 50,
            'allocation_id' => 70,
            'source_type' => 'contract',
            'source_id' => 50,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => 12_500,
            'allocated_percentage' => null,
            'contract_amount_minor' => 50_000,
            'currency' => 'RUB',
            'recognized_on' => '2026-07-29',
            'flow_class' => 'internal',
        ]);
        $percentage = $projector->project([
            'organization_id' => 1,
            'holding_id' => 5,
            'contributor_organization_id' => 11,
            'project_id' => 100,
            'contract_id' => 50,
            'allocation_id' => 71,
            'source_type' => 'contract',
            'source_id' => 71,
            'source_version' => 3,
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => null,
            'allocated_percentage' => '25.00000000',
            'contract_amount_minor' => 50_000,
            'currency' => 'RUB',
            'recognized_on' => '2026-07-29',
            'flow_class' => 'internal',
        ]);

        self::assertInstanceOf(HoldingAllocationFact::class, $fixed);
        self::assertSame(12_500, $fixed->amountMinor);
        self::assertSame(12_500, $percentage->amountMinor);
        self::assertNotSame($fixed->sourceKey(), $percentage->sourceKey());
    }

    #[Test]
    public function flow_class_uses_only_the_pinned_hierarchy_membership(): void
    {
        $projector = new HoldingAllocationFactProjector();

        self::assertSame('internal', $projector->classify(10, 11, [10, 11, 12]));
        self::assertSame('external', $projector->classify(10, 99, [10, 11, 12]));
        self::assertSame('unclassified', $projector->classify(98, 99, [10, 11, 12]));
        self::assertSame('external', $projector->classify(10, null, [10, 11, 12]));
    }
}
