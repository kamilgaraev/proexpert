<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService;
use App\Models\ImportSession;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Mockery;
use PHPUnit\Framework\TestCase;

final class BudgetEstimateImportFileStorageServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stores_import_files_inside_organization_prefix(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'estimate-import-test-');
        self::assertIsString($tmpPath);
        file_put_contents($tmpPath, 'test-content');
        $file = new UploadedFile(
            $tmpPath,
            'estimate.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->with(
                Mockery::pattern('/^org-39\/estimate-imports\/[0-9a-f-]+\.xlsx$/'),
                Mockery::on(static fn (mixed $value): bool => is_resource($value)
                    && stream_get_contents($value) === 'test-content'),
                Mockery::type('string'),
                hash('sha256', 'test-content'),
            )
            ->andReturnUsing(static fn (string $key): CurrentStoredFile => new CurrentStoredFile(
                $key,
                'etag',
                strlen('test-content'),
                hash('sha256', 'test-content'),
                'application/octet-stream',
            ));

        $result = (new FileStorageService($files))->store($file, 39);

        self::assertStringStartsWith('org-39/estimate-imports/', $result['path']);
        self::assertStringEndsWith('.xlsx', $result['path']);
        self::assertSame(strlen('test-content'), $result['size']);
    }

    public function test_normalizes_import_extension_before_building_storage_key(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'estimate-import-test-');
        self::assertIsString($tmpPath);
        file_put_contents($tmpPath, 'test-content');
        $file = new UploadedFile($tmpPath, 'ESTIMATE.XLSX', null, null, true);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->withArgs(static fn (string $key): bool => str_ends_with($key, '.xlsx'))
            ->andReturnUsing(static fn (string $key): CurrentStoredFile => new CurrentStoredFile(
                $key,
                'etag',
                strlen('test-content'),
                hash('sha256', 'test-content'),
                'application/octet-stream',
            ));

        $result = (new FileStorageService($files))->store($file, 39);

        self::assertSame('xlsx', $result['extension']);
        self::assertStringEndsWith('.xlsx', $result['path']);
    }

    public function test_downloads_and_deletes_import_file_through_current_object_gateway(): void
    {
        $path = 'org-39/estimate-imports/01989f5c-27f3-7ab8-9e34-5d436c15a004.xlsx';
        $session = (new ImportSession)->forceFill(['id' => 'session-1', 'file_path' => $path]);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'spreadsheet-content');
        rewind($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->twice()->with($path)->andReturnTrue();
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $files->shouldReceive('deleteCurrent')->once()->with($path);
        $service = new FileStorageService($files);

        $localPath = null;
        $contents = $service->withLocalCopy(
            $session,
            static function (string $path) use (&$localPath): string {
                $localPath = $path;
                self::assertFileExists($path);

                return (string) file_get_contents($path);
            },
        );

        self::assertSame('spreadsheet-content', $contents);
        self::assertIsString($localPath);
        self::assertFileDoesNotExist($localPath);
        self::assertTrue($service->delete($session));
    }

    public function test_managed_local_copy_is_removed_when_callback_fails(): void
    {
        $path = 'org-39/estimate-imports/01989f5c-27f3-7ab8-9e34-5d436c15a004.xlsx';
        $session = (new ImportSession)->forceFill(['id' => 'session-1', 'file_path' => $path]);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'spreadsheet-content');
        rewind($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturnTrue();
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $localPath = null;

        try {
            (new FileStorageService($files))->withLocalCopy(
                $session,
                static function (string $path) use (&$localPath): never {
                    $localPath = $path;

                    throw new \RuntimeException('callback_failed');
                },
            );
            self::fail('Callback exception was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('callback_failed', $exception->getMessage());
        }

        self::assertIsString($localPath);
        self::assertFileDoesNotExist($localPath);
    }
}
