<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileStorageStreamOwnershipTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'estimate-stream-');
        self::assertIsString($path);
        $this->path = $path;
        file_put_contents($this->path, "estimate\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public static function streamOwnership(): array
    {
        return ['storage closes stream' => [true], 'caller closes stream' => [false]];
    }

    #[DataProvider('streamOwnership')]
    public function test_upload_accepts_both_storage_stream_lifetimes(bool $storageCloses): void
    {
        $received = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(
            static function (string $key, mixed $contents, string $mime, string $sha256) use ($storageCloses, &$received): CurrentStoredFile {
                $received = $contents;
                self::assertIsResource($contents);
                self::assertSame("estimate\n", stream_get_contents($contents));
                if ($storageCloses) {
                    $request = new Request('PUT', 'https://storage.example.invalid/object', [], $contents);
                    unset($request);
                    self::assertFalse(is_resource($contents));
                }

                return new CurrentStoredFile($key, 'test-etag', 9, $sha256, $mime);
            },
        );

        $stored = (new FileStorageService($files))->store($this->upload(), 38);

        self::assertStringStartsWith('org-38/estimate-imports/', $stored['path']);
        self::assertStringEndsWith('.csv', $stored['path']);
        self::assertSame('estimate.csv', $stored['name']);
        self::assertSame(9, $stored['size']);
        self::assertFalse(is_resource($received));
    }

    #[DataProvider('streamOwnership')]
    public function test_storage_failure_is_not_masked_by_stream_cleanup(bool $storageCloses): void
    {
        $received = null;
        $failure = new RuntimeException('storage unavailable');
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(
            static function (string $key, mixed $contents) use ($storageCloses, $failure, &$received): never {
                $received = $contents;
                if ($storageCloses) {
                    $request = new Request('PUT', 'https://storage.example.invalid/object', [], $contents);
                    unset($request);
                }
                throw $failure;
            },
        );

        try {
            (new FileStorageService($files))->store($this->upload(), 38);
            self::fail('Storage failure must propagate');
        } catch (\Throwable $exception) {
            self::assertSame($failure, $exception);
            self::assertFalse(is_resource($received));
        }
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile($this->path, 'estimate.csv', 'text/csv', null, true);
    }
}
