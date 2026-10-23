<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(['power', 'кВт', 100.0], $this->unitTuple('100 кВт'));
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
        $this->assertTrue(NormativeUnitNormalizer::compatible('kw', '100 кВт'));
        $this->assertSame(0.001, NormativeUnitNormalizer::quantityFactor('м3', '1000 м³'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м2', '100 м²'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м3', '100 м³'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('м', '100 пог. м'));
        $this->assertSame(0.01, NormativeUnitNormalizer::quantityFactor('kw', '100 кВт'));
        $this->assertSame(1000.0, NormativeUnitNormalizer::quantityFactor('тыс. шт', 'шт'));
        $this->assertSame(0.1, NormativeUnitNormalizer::quantityFactor('0,1 т', 'т'));
    }

    public function test_unit_prefixes_do_not_create_false_matches(): void
    {
        $this->assertFalse(NormativeUnitNormalizer::compatible('мес', 'м'));
        $this->assertFalse(NormativeUnitNormalizer::compatible('тыс. шт', 'т'));
    }

    #[DataProvider('decimalQuantityFactorCases')]
    public function test_decimal_quantity_factor_preserves_unit_scales(string $workUnit, string $normUnit, ?string $expected): void
    {
        $this->assertSame($expected, NormativeUnitNormalizer::safeQuantityFactorDecimal($workUnit, $normUnit));
    }

    public static function decimalQuantityFactorCases(): array
    {
        return [
            'millimetres to metres' => ['мм', 'м', '0.001'],
            'centimetres to metres' => ['см', 'м', '0.01'],
            'metres to millimetres' => ['м', 'мм', '1000'],
            'metres to centimetres' => ['м', 'см', '100'],
            'millimetres to centimetres' => ['мм', 'см', '0.1'],
            'centimetres to millimetres' => ['см', 'мм', '10'],
            'scaled millimetres' => ['834,68 мм', 'м', '0.83468'],
            'scaled centimetres' => ['194,25 см', 'м', '1.9425'],
            'millimetres to kilometres' => ['mm', 'km', '0.000001'],
            'metres to kilometres' => ['м', 'км', '0.001'],
            'kilometres to metres' => ['км', 'м', '1000'],
            'square metre measure' => ['м²', '100 м2', '0.01'],
            'cubic metre measure' => ['м3', '1000 м³', '0.001'],
            'scaled tonnes' => ['0,1 т', 'кг', '100'],
            'thousand pieces' => ['тыс. шт', 'шт', '1000'],
            'nonterminating decimal' => ['м', '3 м', null],
            'incompatible dimensions' => ['м2', 'м', null],
            'unknown work unit' => ['неизвестно', 'м', null],
            'unknown norm unit' => ['м', 'неизвестно', null],
            'empty work unit' => ['', 'м', null],
            'zero work prefix keeps legacy unit' => ['0 м', 'м', '1'],
            'zero norm prefix keeps legacy unit' => ['м', '0 м', '1'],
            'negative work prefix' => ['-1 м', 'м', null],
            'negative norm prefix' => ['м', '-1 м', null],
        ];
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
