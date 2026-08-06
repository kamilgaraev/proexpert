<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateStructureSnapshotStorage;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use League\Flysystem\UnableToDeleteFile;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EstimateStructureSnapshotStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_reads_snapshot_through_current_object_gateway(): void
    {
        $path = 'org-7/estimates/42/structure_snapshot.json';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, '{"sections":[{"id":1}]}');
        rewind($stream);

        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturnTrue();
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);

        $storage = new EstimateStructureSnapshotStorage($files);

        self::assertTrue($storage->exists($path));
        $readStream = $storage->readStream($path);
        self::assertSame('{"sections":[{"id":1}]}', stream_get_contents($readStream));
        fclose($readStream);
    }

    public function test_it_streams_json_with_sha256_through_current_object_gateway(): void
    {
        $path = 'org-7/estimates/42/structure_snapshot.json';
        $payload = ['sections' => [['id' => 1, 'name' => 'Раздел']], 'items' => []];
        $contents = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->with(
                $path,
                Mockery::on(static function (mixed $stream) use ($contents): bool {
                    return is_resource($stream) && stream_get_contents($stream) === $contents;
                }),
                'application/json',
                hash('sha256', $contents),
            )
            ->andReturn(new CurrentStoredFile(
                $path,
                'etag',
                strlen($contents),
                hash('sha256', $contents),
                'application/json',
            ));
        $storage = new EstimateStructureSnapshotStorage($files);
        $storage->putJson($path, $payload);

        self::assertTrue(true);
    }

    public function test_snapshot_cleanup_is_best_effort_after_publication(): void
    {
        $path = 'org-7/estimates/42/old.json';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturnTrue();
        $files->shouldReceive('deleteCurrent')
            ->once()
            ->with($path)
            ->andThrow(UnableToDeleteFile::atLocation($path));

        (new EstimateStructureSnapshotStorage($files))->delete($path);

        self::assertTrue(true);
    }

    public function test_snapshot_cleanup_ignores_obsolete_unscoped_key(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')
            ->once()
            ->with('shared/estimates/42/old.json')
            ->andThrow(new \InvalidArgumentException('organization_storage_path_invalid'));

        (new EstimateStructureSnapshotStorage($files))->delete('shared/estimates/42/old.json');

        self::assertTrue(true);
    }
}
