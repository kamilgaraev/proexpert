<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryProcessRunner;
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
        self::assertStringContainsString('runtime_sandbox_unavailable', file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/GeometryProcessRunner.php'));
        self::assertStringContainsString('runtime_platform_unsupported', file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/GeometryProcessRunner.php'));
        self::assertStringNotContainsString('apt download', file_get_contents($root.'/tests/Runtime/geometry-sandbox-runtime.sh'));
        self::assertStringContainsString('apt download', file_get_contents($root.'/tests/Runtime/bootstrap-geometry-sandbox-runtime.sh'));
    }

    #[Test]
    public function non_linux_production_execution_fails_closed(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            self::markTestSkipped('Non-Linux production policy test.');
        }

        $previousEnvironment = getenv('APP_ENV');
        $previousOptIn = getenv('GEOMETRY_ALLOW_UNISOLATED_LOCAL');
        putenv('APP_ENV=production');
        putenv('GEOMETRY_ALLOW_UNISOLATED_LOCAL=1');

        try {
            $this->expectException(GeometryExtractionException::class);
            $this->expectExceptionMessage('cad_runtime_platform_unsupported');
            (new GeometryProcessRunner)->run(
                [PHP_BINARY, '-r', 'exit(0);'],
                sys_get_temp_dir(),
                'cad',
                1,
                1024,
            );
        } finally {
            $this->restoreEnvironment('APP_ENV', $previousEnvironment);
            $this->restoreEnvironment('GEOMETRY_ALLOW_UNISOLATED_LOCAL', $previousOptIn);
        }
    }

    #[Test]
    public function php_runner_maps_wsl_sandbox_output_and_exit_code(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('WSL bridge integration test.');
        }

        $root = dirname(__DIR__, 4);
        $workspace = sys_get_temp_dir().'/most-geometry-runner-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace, 0700));
        self::assertNotFalse(file_put_contents($workspace.'/worker.sh', "printf runner-ok\nprintf runner-error >&2\nexit 17\n"));
        $wslWorker = '/mnt/'.strtolower($workspace[0]).str_replace('\\', '/', substr($workspace, 2)).'/worker.sh';
        (new Process(['wsl.exe', '-d', 'Ubuntu-22.04', '--', 'chmod', '0755', $wslWorker]))->mustRun();
        $previousSandbox = getenv('GEOMETRY_SANDBOX_BINARY');
        putenv('GEOMETRY_SANDBOX_BINARY='.$root.'/tests/Runtime/geometry-sandbox-wsl-bridge.cmd');

        try {
            $result = (new GeometryProcessRunner('Linux'))->run(
                [$wslWorker],
                $workspace,
                'cad',
                10,
                1024,
            );

            self::assertSame(17, $result['exit_code'], $result['stderr']);
            self::assertSame('runner-ok', $result['stdout']);
            self::assertSame('runner-error', $result['stderr']);
        } finally {
            $this->restoreEnvironment('GEOMETRY_SANDBOX_BINARY', $previousSandbox);
            @unlink($workspace.'/process.stdout');
            @unlink($workspace.'/process.stderr');
            @unlink($workspace.'/worker.sh');
            @rmdir($workspace);
        }
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

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
