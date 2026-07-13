<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProductionReplayBuilderIsolationTest extends TestCase
{
    private string $root;

    private string $output;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $this->output = sys_get_temp_dir().'/most-replay-builder-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->output);
    }

    #[Test]
    public function case_filtered_build_is_published_only_to_explicit_isolated_output(): void
    {
        $before = $this->treeHashes($this->root);

        $process = $this->runBuilder([
            'BUILD_PRODUCTION_REPLAY_CASE' => 'vector-pdf-001',
            'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => $this->output,
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertSame($before, $this->treeHashes($this->root));
        $inventory = $this->json($this->output.'/production-replay-corpus-inventory.json');
        self::assertSame(['vector-pdf-001'], array_column($inventory['cases'], 'slug'));
        $fixtures = $this->json($this->output.'/recordings/manifest.json')['fixtures'];
        self::assertSame(['reg-replay-vector-pdf-001'], array_values(array_unique(array_column($fixtures, 'case_id'))));
        self::assertSame(4, count($fixtures));
        $this->assertDescriptorsResolve($fixtures);
    }

    #[Test]
    public function geometry_only_partial_build_has_only_source_and_confirmation_descriptors(): void
    {
        $before = $this->treeHashes($this->root);
        $process = $this->runBuilder([
            'BUILD_PRODUCTION_REPLAY_CASE' => 'dwg-layout-001',
            'BUILD_PRODUCTION_REPLAY_GEOMETRY_ONLY' => '1',
            'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => $this->output,
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertSame($before, $this->treeHashes($this->root));
        $fixtures = $this->json($this->output.'/recordings/manifest.json')['fixtures'];
        self::assertSame(['cad_extraction', 'geometry_confirmation'], array_column($fixtures, 'port'));
        $this->assertDescriptorsResolve($fixtures);
    }

    #[Test]
    public function partial_build_rejects_missing_or_source_tree_output_before_writing(): void
    {
        $before = $this->treeHashes($this->root);
        $missing = $this->runBuilder(['BUILD_PRODUCTION_REPLAY_CASE' => 'vector-pdf-001']);
        self::assertNotSame(0, $missing->getExitCode());
        self::assertStringContainsString('partial_output_dir_required', $missing->getErrorOutput().$missing->getOutput());

        $source = $this->runBuilder([
            'BUILD_PRODUCTION_REPLAY_CASE' => 'vector-pdf-001',
            'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => $this->root.'/unsafe-output',
        ]);
        self::assertNotSame(0, $source->getExitCode());
        self::assertStringContainsString('partial_output_dir_unsafe', $source->getErrorOutput().$source->getOutput());
        self::assertDirectoryDoesNotExist($this->root.'/unsafe-output');
        self::assertSame($before, $this->treeHashes($this->root));
    }

    #[Test]
    public function partial_build_rejects_non_empty_and_linked_output_directories(): void
    {
        $nonEmpty = $this->output.'-non-empty';
        mkdir($nonEmpty, 0700, true);
        file_put_contents($nonEmpty.'/sentinel', 'preserve');
        try {
            $process = $this->runBuilder([
                'BUILD_PRODUCTION_REPLAY_CASE' => 'vector-pdf-001',
                'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => $nonEmpty,
            ]);
            self::assertNotSame(0, $process->getExitCode());
            self::assertStringContainsString('partial_output_dir_unsafe', $process->getErrorOutput().$process->getOutput());
            self::assertSame('preserve', file_get_contents($nonEmpty.'/sentinel'));
        } finally {
            unlink($nonEmpty.'/sentinel');
            rmdir($nonEmpty);
        }

        $target = $this->output.'-target';
        $link = $this->output.'-link';
        mkdir($target, 0700, true);
        try {
            $linked = false;
            if (PHP_OS_FAMILY === 'Windows') {
                $junction = new Process(['cmd', '/c', 'mklink', '/J', $link, $target]);
                $junction->run();
                $linked = $junction->isSuccessful();
            } else {
                $linked = symlink($target, $link);
            }
            if ($linked) {
                $process = $this->runBuilder([
                    'BUILD_PRODUCTION_REPLAY_CASE' => 'vector-pdf-001',
                    'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => $link,
                ]);
                self::assertNotSame(0, $process->getExitCode());
                self::assertStringContainsString('partial_output_dir_unsafe', $process->getErrorOutput().$process->getOutput());
                rmdir($link);
            } else {
                self::assertDirectoryDoesNotExist($link);
            }
        } finally {
            is_link($link) && rmdir($link);
            rmdir($target);
        }
    }

    #[Test]
    public function default_full_build_is_byte_idempotent_and_keeps_full_descriptor_counts(): void
    {
        $before = $this->treeHashes($this->root);
        $process = $this->runBuilder([]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertSame($before, $this->treeHashes($this->root));
        $fixtures = $this->json($this->root.'/recordings/manifest.json')['fixtures'];
        $counts = [];
        foreach ($fixtures as $fixture) {
            $counts[$fixture['case_id']] = ($counts[$fixture['case_id']] ?? 0) + 1;
        }
        self::assertSame([
            'reg-replay-vector-wall-opening-001' => 3,
            'reg-replay-vision-sketch-001' => 3,
            'reg-replay-vector-pdf-001' => 4,
            'reg-replay-scanned-pdf-001' => 3,
            'reg-replay-dwg-layout-001' => 4,
            'reg-replay-dimensioned-raster-001' => 3,
            'reg-replay-freehand-review-001' => 1,
            'reg-replay-engineering-layout-001' => 3,
        ], $counts);
    }

    private function runBuilder(array $environment): Process
    {
        $environment += [
            'BUILD_PRODUCTION_REPLAY_CASE' => false,
            'BUILD_PRODUCTION_REPLAY_GEOMETRY_ONLY' => false,
            'BUILD_PRODUCTION_REPLAY_OUTPUT_DIR' => false,
        ];
        $process = new Process([PHP_BINARY, $this->root.'/build-production-replay-corpus.php'], dirname(__DIR__, 4), $environment + $_ENV);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function assertDescriptorsResolve(array $fixtures): void
    {
        foreach ($fixtures as $fixture) {
            $path = $this->output.'/'.$fixture['locator'];
            self::assertFileExists($path);
            self::assertSame($fixture['sha256'], hash_file('sha256', $path));
        }
    }

    private function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    private function treeHashes(string $root): array
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $hashes[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
