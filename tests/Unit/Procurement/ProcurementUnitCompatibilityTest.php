<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\ProcurementUnitCompatibility;
use PHPUnit\Framework\TestCase;

final class ProcurementUnitCompatibilityTest extends TestCase
{
    public function test_accepts_same_unit_name_or_short_name_case_insensitively(): void
    {
        self::assertTrue(ProcurementUnitCompatibility::matches(' КГ ', 'килограмм', 'кг'));
        self::assertTrue(ProcurementUnitCompatibility::matches('килограмм', 'Килограмм', 'кг'));
    }

    public function test_rejects_unit_that_requires_unconfigured_conversion(): void
    {
        self::assertFalse(ProcurementUnitCompatibility::matches('т', 'килограмм', 'кг'));
        self::assertFalse(ProcurementUnitCompatibility::matches('', 'килограмм', 'кг'));
    }
}
