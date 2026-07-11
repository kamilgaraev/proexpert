<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CadProductionRuntimeContractTest extends TestCase
{
    #[Test]
    public function production_image_pins_geometry_runtimes_and_licenses(): void
    {
        $root = dirname(__DIR__, 4);
        $dockerfile = file_get_contents($root.'/Dockerfile.prod');
        $requirements = file_get_contents($root.'/docker/geometry/requirements.lock');
        $notice = file_get_contents($root.'/docker/geometry/THIRD_PARTY_NOTICES.md');

        self::assertStringContainsString('LIBREDWG_VERSION=0.13.4', $dockerfile);
        self::assertStringContainsString('7e153ea4dac4cbf3dc9c50b9ef7a5604e09cdd4c5520bcf8017877bbe1422cd5', $dockerfile);
        self::assertStringContainsString('pypdfium2==5.8.0', $requirements);
        self::assertStringContainsString('ezdxf==1.4.4', $requirements);
        self::assertStringContainsString('GPL-3.0-or-later', $notice);
        self::assertStringContainsString('MIT', $notice);
        self::assertMatchesRegularExpression('/FROM alpine:3\.20@sha256:[a-f0-9]{64}/', $dockerfile);
        self::assertMatchesRegularExpression('/FROM php:8\.2-cli-alpine@sha256:[a-f0-9]{64}/', $dockerfile);
        self::assertStringContainsString('bubblewrap', $dockerfile);
        self::assertStringContainsString('--require-hashes', $dockerfile);
        self::assertStringContainsString('geometry-sandbox', $dockerfile);
        self::assertStringContainsString('--ro-bind / /', file_get_contents($root.'/docker/geometry/geometry-sandbox.sh'));
        self::assertStringContainsString('Corresponding Source', $notice);
        self::assertStringContainsString('memory_limit_kib', file_get_contents($root.'/config/estimate-generation.php'));
        self::assertStringContainsString('geometry_sandbox_unavailable', file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/GeometryProcessRunner.php'));
    }

    #[Test]
    public function sandbox_executable_denies_outside_writes_and_enforces_limits(): void
    {
        $root = dirname(__DIR__, 4);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = '/mnt/'.strtolower($root[0]).str_replace('\\', '/', substr($root, 2)).'/tests/Runtime/geometry-sandbox-runtime.sh';
            $process = new Process(['wsl.exe', '-d', 'Ubuntu-22.04', '--', 'bash', $path]);
        } else {
            $process = new Process(['bash', $root.'/tests/Runtime/geometry-sandbox-runtime.sh']);
        }
        $process->setTimeout(120);
        $process->mustRun();

        self::assertStringContainsString('geometry sandbox runtime: PASS', $process->getOutput());
    }
}
