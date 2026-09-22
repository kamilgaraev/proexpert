<?php

declare(strict_types=1);

namespace Tests\Unit\MaterialConsumption;

use App\Exceptions\BusinessLogicException;
use App\Services\MaterialConsumption\MaterialConsumptionUnitConversion;
use Tests\TestCase;

final class MaterialConsumptionUnitConversionTest extends TestCase
{
    public function test_same_unit_does_not_require_coefficient(): void
    {
        $result = (new MaterialConsumptionUnitConversion)->convert('170', 5, 5, null);

        $this->assertSame('170', $result['quantity']);
        $this->assertNull($result['basis']);
    }

    public function test_tonne_to_kilogram_uses_explicit_coefficient(): void
    {
        $result = (new MaterialConsumptionUnitConversion)->convert('0.17', 1, 2, [
            'coefficient' => '1000',
            'reason' => '1 т = 1000 кг',
        ]);

        $this->assertSame('170', $result['quantity']);
        $this->assertSame('1000', $result['basis']['coefficient']);
    }

    public function test_mixed_units_without_coefficient_are_blocked(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage(trans_message('material_consumption.fact_unit_conversion_required'));

        (new MaterialConsumptionUnitConversion)->convert('2', 1, 2, null);
    }
}
