<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifestException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BenchmarkPathSafetyTest extends TestCase
{
    private string $root;

    private string $outside;

    /** @var array<string, mixed> */
    private array $manifest;

    /** @var list<string> */
    private array $junctions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $source = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $base = sys_get_temp_dir().'/most-benchmark-symlink-'.bin2hex(random_bytes(4));
        $this->root = $base.'/root';
        $this->outside = $base.'/outside';
        mkdir($this->root, 0750, true);
        mkdir($this->outside, 0750, true);
        $this->copyTree($source, $this->root);
        $this->manifest = json_decode((string) file_get_contents($this->root.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root);
        foreach ($this->junctions as $junction) {
            if (file_exists($junction)) {
                rmdir($junction);
            }
        }
        if (str_starts_with($base, rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'most-benchmark-symlink-')) {
            $this->removeTree($base);
        }
        parent::tearDown();
    }

    #[Test]
    public function direct_file_link_is_rejected(): void
    {
        $relative = 'development/photo-plan-001/input.ppm';
        $link = $this->root.'/'.$relative;
        $target = $this->outside.'/input.ppm';
        copy($link, $target);
        unlink($link);
        self::assertTrue(PHP_OS_FAMILY === 'Windows' ? link($target, $link) : symlink($target, $link));

        $this->expectException(BenchmarkManifestException::class);
        $this->expectExceptionMessage('fixture_file_invalid');
        BenchmarkManifest::fromArray($this->manifest, $this->root);
    }

    #[Test]
    public function intermediate_directory_symlink_is_rejected(): void
    {
        $relative = 'development/photo-plan-001';
        $link = $this->root.'/'.$relative;
        $target = $this->outside.'/photo-plan-001';
        mkdir($target, 0750, true);
        copy($link.'/input.ppm', $target.'/input.ppm');
        copy($link.'/expected.json', $target.'/expected.json');
        unlink($link.'/input.ppm');
        unlink($link.'/expected.json');
        rmdir($link);
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['cmd', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $target)]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } else {
            self::assertTrue(symlink($target, $link));
        }
        $this->junctions[] = $link;

        $this->expectException(BenchmarkManifestException::class);
        $this->expectExceptionMessage('fixture_file_invalid');
        BenchmarkManifest::fromArray($this->manifest, $this->root);
    }

    private function copyTree(string $source, string $destination): void
    {
        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $source.'/'.$name;
            $to = $destination.'/'.$name;
            if (is_dir($from)) {
                mkdir($to, 0750, true);
                $this->copyTree($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$name);
        }
        rmdir($path);
    }
}
