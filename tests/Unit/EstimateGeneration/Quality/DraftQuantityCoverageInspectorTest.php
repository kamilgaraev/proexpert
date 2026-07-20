<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftQuantityCoverageInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DraftQuantityCoverageInspectorTest extends TestCase
{
    #[Test]
    public function it_classifies_valid_same_package_warnings_and_rejects_untrusted_entries(): void
    {
        $heatingWarning = [
            'quantity_key' => 'heating.unit',
            'reason' => 'heating_source_type_missing',
            'package_key' => 'heating',
            'message' => 'Источник тепла нужно определить.',
        ];
        $externalWarning = [
            'quantity_key' => 'networks.external',
            'reason' => 'external_network_route_missing',
            'package_key' => 'external_networks',
        ];

        $inspection = (new DraftQuantityCoverageInspector)->inspect([
            'local_estimates' => [
                [
                    'key' => 'heating',
                    'coverage_warnings' => [
                        $heatingWarning,
                        $heatingWarning,
                        ['quantity_key' => 'heating.unit', 'reason' => 'unknown_reason', 'package_key' => 'heating'],
                        ['quantity_key' => 'heating.unit', 'reason' => 'heating_source_type_missing', 'package_key' => 'foreign'],
                    ],
                ],
                ['key' => 'external_networks', 'coverage_warnings' => [$externalWarning]],
            ],
        ]);

        self::assertSame([$heatingWarning], $inspection['blocking']);
        self::assertSame([$externalWarning], $inspection['advisory']);
    }
}
