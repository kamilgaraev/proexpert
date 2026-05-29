<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Tests\TestCase;

class NormativeUnitNormalizerTest extends TestCase
{
    public function test_scaled_units_are_compatible_and_return_quantity_factor(): void
    {
        $this->assertTrue(NormativeUnitNormalizer::compatible('1000 м³', 'м3'));
        $this->assertSame(0.001, NormativeUnitNormalizer::quantityFactor('м3', '1000 м³'));
        $this->assertSame(1000.0, NormativeUnitNormalizer::quantityFactor('тыс. шт', 'шт'));
        $this->assertSame(0.1, NormativeUnitNormalizer::quantityFactor('0,1 т', 'т'));
    }

    public function test_unit_prefixes_do_not_create_false_matches(): void
    {
        $this->assertFalse(NormativeUnitNormalizer::compatible('мес', 'м'));
        $this->assertFalse(NormativeUnitNormalizer::compatible('тыс. шт', 'т'));
    }
}
