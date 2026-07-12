<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalBenchmarkObjectReaderSafetyTest extends TestCase
{
    #[Test]
    public function expected_reader_rejects_symlinked_parent_and_terminal_file(): void
    {
        $root = sys_get_temp_dir().'/most-expected-path-'.bin2hex(random_bytes(5));
        mkdir($root.'/real', 0700, true);
        file_put_contents($root.'/real/expected.json', '{}');
        $linked = @symlink($root.'/real', $root.'/linked-parent');
        if (! $linked && PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $exit = 1;
            exec('cmd /c mklink /J "'.str_replace('/', '\\', $root.'/linked-parent').'" "'
                .str_replace('/', '\\', $root.'/real').'"', $output, $exit);
            $linked = $exit === 0 && is_dir($root.'/linked-parent');
        }
        if (! $linked) {
            unlink($root.'/real/expected.json');
            rmdir($root.'/real');
            rmdir($root);
            self::markTestSkipped('ОС не разрешила создать directory symlink/junction для проверки parent path.');
        }
        $case = $this->case($root, 'linked-parent/expected.json', hash_file('sha256', $root.'/real/expected.json'));
        try {
            (new LocalBenchmarkObjectReader)->read($case, 'expected', 4096);
            self::fail('Symlinked parent was accepted.');
        } catch (BenchmarkContractException) {
            self::addToAssertionCount(1);
        } finally {
            rmdir($root.'/linked-parent');
        }
        if (@symlink($root.'/real/expected.json', $root.'/expected-link.json')) {
            try {
                (new LocalBenchmarkObjectReader)->read($this->case($root, 'expected-link.json', hash_file('sha256', $root.'/real/expected.json')), 'expected', 4096);
                self::fail('Terminal symlink was accepted.');
            } catch (BenchmarkContractException) {
                self::addToAssertionCount(1);
            } finally {
                unlink($root.'/expected-link.json');
            }
        }
        unlink($root.'/real/expected.json');
        rmdir($root.'/real');
        rmdir($root);
    }

    #[Test]
    public function expected_reader_rejects_traversal_before_realpath(): void
    {
        $root = sys_get_temp_dir().'/most-expected-traversal-'.bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        $case = $this->case($root, '../expected.json', str_repeat('0', 64));

        try {
            $this->expectException(BenchmarkContractException::class);
            (new LocalBenchmarkObjectReader)->read($case, 'expected', 4096);
        } finally {
            rmdir($root);
        }
    }

    private function case(string $root, string $expectedLocator, string $expectedHash): BenchmarkCaseData
    {
        return new BenchmarkCaseData('safe-case', BenchmarkDatasetType::Regression, BenchmarkSourceType::Dxf,
            'input.dxf', $expectedLocator, str_repeat('1', 64), $expectedHash, 'CC0', 'test', ['safe'], 1,
            'benchmark-expected:v1', ['geometry'], $root);
    }
}
