<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceReversalSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionAcceptanceReversalSourceTest extends TestCase
{
    #[Test]
    public function reversal_copies_the_accepted_identity_and_only_negates_quantity(): void
    {
        $accepted = new ProductionAcceptanceEvent;
        $accepted->forceFill([
            'accepted_quantity_delta' => '2.5001',
            'approved_rate_minor' => 125_045,
            'contractor_id' => 19,
            'conversion_version' => 'unit_4',
            'currency' => 'RUB',
            'currency_source' => 'performance_act_line.unit_price@contract_performance_act.currency',
            'event_type' => 'accepted',
            'planned_quantity' => '10.000',
            'reported_quantity' => '7.500',
            'task_id' => 31,
            'unit_code' => 'm3',
            'unit_dimension' => 'volume',
            'wbs_code' => '1.2',
            'work_id' => 77,
            'zone' => 'A',
        ]);

        $reversal = (new ProductionAcceptanceReversalSource)->fromAccepted($accepted);

        self::assertSame('-2.5001', $reversal['accepted_quantity_delta']);
        self::assertSame(125_045, $reversal['approved_rate']->minor);
        self::assertSame('RUB', $reversal['approved_rate']->currency);
        self::assertSame('10.000', $reversal['planned_quantity']);
        self::assertSame(77, $reversal['work_id']);
    }
}
