<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionAcceptanceEventRecorderTest extends TestCase
{
    #[Test]
    public function repeated_transition_requires_the_same_immutable_source_identity(): void
    {
        $event = new ProductionAcceptanceEvent();
        $event->forceFill([
            'event_type' => 'accepted',
            'source_line_type' => 'performance_act_line',
            'source_line_id' => 41,
            'accepted_quantity_delta' => '6.000',
            'unit_code' => 'piece',
        ]);
        $identity = new ProductionAcceptanceEventIdentity();

        $identity->assertMatches($event, [
            'event_type' => 'accepted',
            'source_line_type' => 'performance_act_line',
            'source_line_id' => 41,
            'accepted_quantity_delta' => '6.000',
            'unit_code' => 'piece',
        ]);
        self::assertTrue(true);

        $this->expectException(InvalidArgumentException::class);
        $identity->assertMatches($event, [
            'event_type' => 'accepted',
            'source_line_id' => 42,
        ]);
    }
}
