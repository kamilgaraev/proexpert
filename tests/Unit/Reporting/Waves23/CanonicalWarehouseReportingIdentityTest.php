<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\CanonicalWarehouseReportingIdentity;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CanonicalWarehouseReportingIdentityTest extends TestCase
{
    public function test_business_metadata_cannot_override_canonical_reporting_identity(): void
    {
        $identity = new CanonicalWarehouseReportingIdentity;

        $this->expectException(DomainException::class);

        $identity->merge(
            [
                'reporting_source_version' => 1,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'unit:7:v1',
                'reporting_inventory_project_id' => 12,
            ],
            ['unit_code' => 't'],
        );
    }

    public function test_equal_identity_is_accepted_and_canonical_values_win(): void
    {
        $identity = new CanonicalWarehouseReportingIdentity;
        $canonical = [
            'reporting_source_version' => 1,
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'unit_conversion_version' => 'unit:7:v1',
            'reporting_inventory_project_id' => 12,
        ];

        self::assertSame(
            ['reason' => 'transfer'] + $canonical,
            $identity->merge($canonical, ['reason' => 'transfer'] + $canonical),
        );
    }
}
