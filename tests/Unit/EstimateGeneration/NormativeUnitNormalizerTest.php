<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use PHPUnit\Framework\TestCase;

class NormativeUnitNormalizerTest extends TestCase
{
    public function test_parses_normative_units_with_dimensions_and_multipliers(): void
    {
        $this->assertSame(['length', 'м', 1000.0], $this->unitTuple('км'));
        $this->assertSame(['length', 'м', 100.0], $this->unitTuple('100 м'));
        $this->assertSame(['area', 'м2', 100.0], $this->unitTuple('100 м2'));
        $this->assertSame(['volume', 'м3', 1000.0], $this->unitTuple('1000 м3'));
        $this->assertSame(['mass', 'кг', 1000.0], $this->unitTuple('т'));
        $this->assertSame(['piece', 'шт', 1.0], $this->unitTuple('шт'));
        $this->assertSame(['piece', 'шт', 1.0], $this->unitTuple('точка'));
        $this->assertSame(['piece', 'шт', 1.0], $this->unitTuple('компл'));
    }

    public function test_incompatible_dimensions_have_no_safe_quantity_factor(): void
    {
        $this->assertNull(NormativeUnitNormalizer::safeQuantityFactor('м2', 'км'));
        $this->assertNull(NormativeUnitNormalizer::safeQuantityFactor('м', 'шт'));
        $this->assertNull(NormativeUnitNormalizer::safeQuantityFactor('мес', 'м'));
    }

    public function test_compatible_units_convert_to_norm_quantity(): void
    {
        $this->assertSame(0.001, NormativeUnitNormalizer::safeQuantityFactor('м', 'км'));
        $this->assertSame(0.83468, NormativeUnitNormalizer::safeQuantityFactor('834,68 м', 'км'));
        $this->assertSame(0.01, NormativeUnitNormalizer::safeQuantityFactor('м2', '100 м2'));
        $this->assertSame(1.9425, NormativeUnitNormalizer::safeQuantityFactor('194,25 м2', '100 м2'));
    }

    public function test_scaled_units_are_compatible_and_return_quantity_factor(): void
    {
        $this->assertTrue(NormativeUnitNormalizer::compatible('1000 м³', 'м3'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('100 м²', 'м2'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('m3', 'м3'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('m²', 'м2'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('sqm', 'м2'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('cbm', 'м3'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('pcs', 'шт'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('pcs', 'компл'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('kg', 'кг'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('100 м³', 'м3'));
        $this->assertTrue(NormativeUnitNormalizer::compatible('100 пог. м', 'м'));
        $this->assertSame(0.001, NormativeUnitNormalizer::quantityFactor('м3', '1000 м³'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м2', '100 м²'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м3', '100 м³'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м', '100 пог. м'));
        $this->assertSame(1000.0, NormativeUnitNormalizer::quantityFactor('тыс. шт', 'шт'));
        $this->assertSame(0.1, NormativeUnitNormalizer::quantityFactor('0,1 т', 'т'));
    }

    public function test_unit_prefixes_do_not_create_false_matches(): void
    {
        $this->assertFalse(NormativeUnitNormalizer::compatible('мес', 'м'));
        $this->assertFalse(NormativeUnitNormalizer::compatible('тыс. шт', 'т'));
    }

    /**
     * @return array{string, string, float}
     */
    private function unitTuple(string $unit): array
    {
        $parsed = NormativeUnitNormalizer::parseDetailed($unit);

        return [$parsed->dimension, $parsed->baseUnit, $parsed->multiplier];
    }
}
