<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\TestCase;

final class CadSourceIntegrityTest extends TestCase
{
    public function test_packaged_parser_matches_production_and_application_integrity_pins(): void
    {
        $root = dirname(__DIR__, 3);
        $script = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py');
        self::assertIsString($script);
        $expected = hash('sha256', str_replace("\r\n", "\n", $script));
        $docker = file_get_contents($root.'/Dockerfile.prod');
        self::assertIsString($docker);
        self::assertSame(1, preg_match('/ESTIMATE_GENERATION_CAD_SCRIPT_SHA256="([a-f0-9]{64})"/', $docker, $production));
        self::assertSame($expected, $production[1], 'The immutable image must accept the actual packaged CAD parser.');
        $config = file_get_contents($root.'/config/estimate-generation.php');
        self::assertIsString($config);
        self::assertSame(1, preg_match("/'script_sha256'\s*=>[^\n]*'ESTIMATE_GENERATION_CAD_SCRIPT_SHA256',\s*'([a-f0-9]{64})'/", $config, $application));
        self::assertSame($expected, $application[1], 'Application verification must use the same parser as the immutable image.');
    }
}
