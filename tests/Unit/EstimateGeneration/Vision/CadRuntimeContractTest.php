<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CadRuntimeContractTest extends TestCase
{
    #[Test]
    public function cad_runtime_returns_versioned_json_contract_for_real_dxf(): void
    {
        $result = $this->runtime()->extract(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf'
        );

        self::assertSame(1, $result->schemaVersion);
        self::assertSame('mm', $result->sourceUnit);
        self::assertSame('confirmed', $result->unitStatus);
        self::assertNotEmpty($result->layers);
        self::assertNotEmpty($result->entities);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->sourceFingerprint);
    }

    #[Test]
    public function signature_mismatch_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cad-contract-').'.dwg';
        file_put_contents($path, 'not-a-dwg');

        try {
            $this->expectExceptionMessage('cad_signature_mismatch');
            $this->runtime()->extract($path);
        } finally {
            @unlink($path);
        }
    }

    private function runtime(): CadConversionRuntime
    {
        return new CadConversionRuntime(
            pythonBinary: 'python',
            scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py',
            dwgreadBinary: (string) getenv('LIBREDWG_DWGREAD_BINARY')
        );
    }
}
