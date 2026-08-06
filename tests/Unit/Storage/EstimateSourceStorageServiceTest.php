<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use App\Services\Storage\FileService;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EstimateSourceStorageServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_lists_only_sources_inside_requested_organization(): void
    {
        $prefix = 'org-7/estimate-sources/fsnb/2025';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('listCurrent')
            ->once()
            ->with($prefix)
            ->andReturn([
                $prefix.'/part-2.xml',
                $prefix.'/part-1.xml',
            ]);

        $result = (new EstimateSourceStorageService($files))->listFiles(
            7,
            'estimate-sources/fsnb/2025',
        );

        self::assertSame([
            $prefix.'/part-1.xml',
            $prefix.'/part-2.xml',
        ], $result);
    }

    public function test_opens_source_stream_through_current_object_gateway(): void
    {
        $key = 'org-7/estimate-sources/fsnb/2025/part-1.xml';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, '<root/>');
        rewind($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrent')->once()->with($key)->andReturn($stream);

        $opened = (new EstimateSourceStorageService($files))->openReadStream(7, $key);

        self::assertSame('<root/>', stream_get_contents($opened));
        fclose($opened);
    }

    public function test_rejects_source_key_owned_by_another_organization(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('readCurrent');

        $this->expectException(InvalidArgumentException::class);

        (new EstimateSourceStorageService($files))->openReadStream(
            7,
            'org-8/estimate-sources/fsnb/2025/part-1.xml',
        );
    }
}
