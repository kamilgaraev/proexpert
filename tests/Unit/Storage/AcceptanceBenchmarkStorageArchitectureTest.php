<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class AcceptanceBenchmarkStorageArchitectureTest extends TestCase
{
    public function test_benchmark_reader_does_not_select_storage_disk(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration/Benchmark/FileServiceAcceptanceBenchmarkObjectStore.php',
        );
        self::assertIsString($source);

        self::assertStringContainsString('readCurrent(', $source);
        self::assertStringNotContainsString('->disk()', $source);
        self::assertStringNotContainsString('readStream(', $source);
    }
}
