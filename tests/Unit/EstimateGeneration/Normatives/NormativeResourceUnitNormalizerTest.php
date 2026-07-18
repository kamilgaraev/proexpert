<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceUnitNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeResourceUnitNormalizerTest extends TestCase
{
    #[DataProvider('implicitUnits')]
    #[Test]
    public function implicit_fsnb_units_are_derived_only_for_unambiguous_resource_types(string $type, ?string $expected): void
    {
        self::assertSame($expected, NormativeResourceUnitNormalizer::normalize(null, $type));
        self::assertSame($expected, NormativeResourceUnitNormalizer::normalize('  ', $type));
    }

    public static function implicitUnits(): array
    {
        return [
            'labor' => ['labor', 'чел.-ч'],
            'machine labor' => ['machine_labor', 'чел.-ч'],
            'machine' => ['machine', 'маш.-ч'],
            'machinery' => ['machinery', 'маш.-ч'],
            'material stays unknown' => ['material', null],
            'equipment stays unknown' => ['equipment', null],
            'other stays unknown' => ['other', null],
        ];
    }

    #[Test]
    public function explicit_catalog_unit_is_preserved(): void
    {
        self::assertSame('100 шт', NormativeResourceUnitNormalizer::normalize(' 100 шт ', 'material'));
    }
}
