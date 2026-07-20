<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;
use PHPUnit\Framework\TestCase;

final class QuantityCoverageWarningTest extends TestCase
{
    public function test_residential_catalog_omission_reasons_satisfy_warning_contract(): void
    {
        foreach ([
            [
                'quantity_key' => 'foundation.prep',
                'reason' => 'foundation_footprint_missing',
                'package_key' => 'foundation',
            ],
            [
                'quantity_key' => 'sewerage.risers',
                'reason' => 'documented_wet_rooms_missing',
                'package_key' => 'sewerage',
            ],
        ] as $warning) {
            self::assertTrue(
                QuantityCoverageWarning::isValid($warning),
                sprintf('Residential quantity warning reason "%s" must be accepted.', $warning['reason']),
            );
        }
    }

    public function test_every_allowed_reason_has_a_human_readable_russian_message(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';
        $messages = $translations['quantity_coverage_warnings'] ?? [];

        foreach (QuantityCoverageWarning::reasons() as $reason) {
            self::assertArrayHasKey($reason, $messages);
            self::assertIsString($messages[$reason]);
            self::assertNotSame('', trim($messages[$reason]));
            self::assertStringNotContainsString($reason, $messages[$reason]);
        }
    }
}
