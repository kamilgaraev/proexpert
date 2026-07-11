<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\Services\Storage\FileService;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileServiceBenchmarkPrivateObjectStoreTest extends TestCase
{
    #[Test]
    public function bounded_file_service_stream_is_read_without_database_or_url_generation(): void
    {
        $root = sys_get_temp_dir().'/most-private-store-'.bin2hex(random_bytes(4));
        mkdir($root.'/org-42/estimate-generation/benchmarks/acceptance', 0750, true);
        file_put_contents($root.'/org-42/estimate-generation/benchmarks/acceptance/manifest.json', '{"safe":true}');
        $local = new LocalFilesystemAdapter($root);
        $disk = new FilesystemAdapter(new Filesystem($local), $local, ['throw' => true]);
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('disk')->willReturn($disk);

        $result = (new FileServiceBenchmarkPrivateObjectStore($files))->read(
            'org-42/estimate-generation/benchmarks/acceptance/manifest.json',
            1024,
        );

        self::assertSame('{"safe":true}', $result);
        unlink($root.'/org-42/estimate-generation/benchmarks/acceptance/manifest.json');
        rmdir($root.'/org-42/estimate-generation/benchmarks/acceptance');
        rmdir($root.'/org-42/estimate-generation/benchmarks');
        rmdir($root.'/org-42/estimate-generation');
        rmdir($root.'/org-42');
        rmdir($root);
    }
}
