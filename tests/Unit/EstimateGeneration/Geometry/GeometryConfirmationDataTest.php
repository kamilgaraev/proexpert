<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\GeometryConfirmationParityCases;

final class GeometryConfirmationDataTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(GeometryConfirmationParityCases::class, 'cases')]
    public function geometry_scale_numbers_have_strict_php_types(mixed $realValue, array $indexes, bool $valid): void
    {
        $payload = GeometryConfirmationParityCases::payload($realValue, $indexes);
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $confirmation = GeometryConfirmationData::fromArray($payload);

        self::assertSame($realValue, $confirmation->scaleEvidence[0]['real_world_value']);
    }
}
