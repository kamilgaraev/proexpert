<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DwgDxfGeometryProviderTest extends TestCase
{
    #[Test]
    public function real_synthetic_dwg_is_decoded_by_libredwg_runtime(): void
    {
        $binary = getenv('LIBREDWG_DWGREAD_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            self::markTestSkipped('Для fixture-контракта требуется LibreDWG 0.13.4.');
        }
        $root = dirname(__DIR__, 4);
        $runtime = new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py', $binary);
        $result = $runtime->extract($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dwg');

        self::assertStringContainsString('libredwg:0.13.4', $result->runtimeVersion);
        self::assertNotEmpty($result->entities);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->sourceFingerprint);
    }
}
