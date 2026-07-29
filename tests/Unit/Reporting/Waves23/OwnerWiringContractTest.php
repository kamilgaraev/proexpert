<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OwnerWiringContractTest extends TestCase
{
    #[Test]
    public function owner_slice_exposes_exact_bindings_without_activation_authority(): void
    {
        $path = dirname(__DIR__, 4).'/docs/reports/traceability/r26-r28-owner-wiring.json';

        try {
            $contract = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail($exception->getMessage());
        }

        self::assertFalse($contract['activation_allowed']);
        self::assertSame('plan-1c', $contract['publication_owner']);
        self::assertSame(
            ['contractor_scorecard', 'handover_readiness', 'customer_sla'],
            array_column($contract['reports'], 'code'),
        );

        foreach ($contract['reports'] as $report) {
            foreach (['provider', 'row_query', 'drill_down', 'readiness_probe'] as $binding) {
                self::assertTrue(class_exists($report[$binding]), $report['code'].':'.$binding);
            }
        }
    }
}
