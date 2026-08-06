<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\DTO\CurrentMultipartCompletion;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\FileService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileServiceCurrentObjectTest extends TestCase
{
    private const PART_SIZE = 5 * 1024 * 1024;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_writes_downloads_and_deletes_current_private_object_without_version_identity(): void
    {
        $contents = 'private-file-body';
        $checksum = hash('sha256', $contents);
        $key = 'org-42/reports/exports/01J4EXPORT/01J4OBJECT.pdf';
        $captured = null;
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('putObject')
            ->once()
            ->with(Mockery::on(static function (array $arguments) use (&$captured): bool {
                $captured = $arguments;

                return true;
            }))
            ->andReturn(new Result(['ETag' => '"etag-1"', 'VersionId' => 'internal-version']));
        $disk = $this->recordingDisk();
        $files = $this->service($client, $disk);

        $stored = $files->putPrivate($key, $contents, 'application/pdf', $checksum);
        $link = $files->temporaryDownloadUrl($key, 300);
        self::assertTrue($files->existsCurrent($key));
        $readStream = $files->readCurrent($key);
        $files->deleteCurrent($key);

        self::assertInstanceOf(CurrentStoredFile::class, $stored);
        self::assertSame($key, $stored->key);
        self::assertSame(strlen($contents), $stored->sizeBytes);
        self::assertSame($checksum, $stored->sha256);
        self::assertSame('application/pdf', $stored->mime);
        self::assertFalse(property_exists($stored, 'versionId'));
        self::assertIsArray($captured);
        self::assertSame('prohelper-storage', $captured['Bucket'] ?? null);
        self::assertSame($key, $captured['Key'] ?? null);
        self::assertSame($contents, $captured['Body'] ?? null);
        self::assertSame('private', $captured['ACL'] ?? null);
        self::assertSame('application/pdf', $captured['ContentType'] ?? null);
        self::assertSame(['sha256' => $checksum], $captured['Metadata'] ?? null);
        self::assertSame('*', $captured['IfNoneMatch'] ?? null);
        self::assertStringNotContainsStringIgnoringCase('versionid', $link);
        self::assertSame('stored-body', stream_get_contents($readStream));
        fclose($readStream);
        self::assertSame([$key], $disk->deletedKeys);
    }

    public function test_it_hashes_seekable_stream_and_restores_its_cursor_before_upload(): void
    {
        $contents = 'streamed-private-file';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('putObject')
            ->once()
            ->with(Mockery::on(static fn (array $arguments): bool => is_resource($arguments['Body'] ?? null)
                && ftell($arguments['Body']) === 0))
            ->andReturn(new Result(['ETag' => '"etag-stream"']));

        $stored = $this->service($client, $this->recordingDisk())->putPrivate(
            'org-42/legal-archive/01J4DOCUMENT/01J4OBJECT.pdf',
            $stream,
            'application/pdf',
            hash('sha256', $contents),
        );

        self::assertSame(strlen($contents), $stored->sizeBytes);
        self::assertSame(0, ftell($stream));
        fclose($stream);
    }

    public function test_it_completes_a_current_multipart_object_and_streams_its_sha256(): void
    {
        $contents = str_repeat('m', self::PART_SIZE);
        $path = 'org-42/pir/projects/11/packages/21/model-uploads/user-15/upload-1/model.ifc';
        $upload = new MultipartUpload(
            $path,
            'provider-upload-1',
            'application/x-step',
            self::PART_SIZE,
            ['organization_id' => '42', 'user_id' => '15'],
        );
        $part = new MultipartPart(
            $path,
            $upload->uploadId,
            1,
            'part-etag',
            strlen($contents),
            hash('sha256', $contents),
        );
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('completeMultipartUpload')
            ->once()
            ->with(Mockery::on(static fn (array $arguments): bool => ($arguments['Bucket'] ?? null) === 'prohelper-storage'
                && ($arguments['Key'] ?? null) === $path
                && ($arguments['UploadId'] ?? null) === 'provider-upload-1'
                && ($arguments['IfNoneMatch'] ?? null) === '*'
                && ($arguments['MultipartUpload']['Parts'] ?? null) === [[
                    'PartNumber' => 1,
                    'ETag' => 'part-etag',
                ]]))
            ->andReturn(new Result([
                'Bucket' => 'prohelper-storage',
                'Key' => $path,
                'ETag' => 'object-etag',
                'VersionId' => 'internal-only',
            ]));
        $disk = $this->recordingDisk($contents);

        $files = $this->service($client, $disk);
        $completion = $files->completeCurrentMultipart(
            $upload,
            [$part],
            strlen($contents),
        );
        $stored = $files->verifyCurrentMultipart($completion);

        self::assertInstanceOf(CurrentMultipartCompletion::class, $completion);
        self::assertSame($path, $stored->key);
        self::assertSame('object-etag', $stored->etag);
        self::assertSame(strlen($contents), $stored->sizeBytes);
        self::assertSame(hash('sha256', $contents), $stored->sha256);
        self::assertSame('application/x-step', $stored->mime);
        self::assertSame([], $disk->deletedKeys);
    }

    public function test_it_deletes_a_completed_current_object_when_streamed_size_does_not_match(): void
    {
        $path = 'org-42/pir/model-uploads/user-15/upload-2/model.ifc';
        $upload = new MultipartUpload(
            $path,
            'provider-upload-2',
            'application/x-step',
            self::PART_SIZE,
            ['organization_id' => '42', 'user_id' => '15'],
        );
        $part = new MultipartPart(
            $path,
            $upload->uploadId,
            1,
            'part-etag',
            self::PART_SIZE,
            str_repeat('a', 64),
        );
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('completeMultipartUpload')
            ->once()
            ->andReturn(new Result(['ETag' => 'object-etag']));
        $disk = $this->recordingDisk('short-body');
        $files = $this->service($client, $disk);

        $completion = $files->completeCurrentMultipart($upload, [$part], self::PART_SIZE);

        try {
            $files->verifyCurrentMultipart($completion);
            self::fail('Multipart object with a mismatched size was accepted.');
        } catch (VersionedObjectIntegrityException $exception) {
            self::assertSame('s3_multipart_current_object_size_mismatch', $exception->getMessage());
        }

        self::assertSame([$path], $disk->deletedKeys);
    }

    public function test_it_keeps_a_completed_object_after_transient_read_failure_and_allows_retry(): void
    {
        $contents = str_repeat('r', self::PART_SIZE);
        $path = 'org-42/pir/model-uploads/user-15/upload-3/model.ifc';
        $upload = new MultipartUpload(
            $path,
            'provider-upload-3',
            'application/x-step',
            self::PART_SIZE,
            ['organization_id' => '42', 'user_id' => '15'],
        );
        $part = new MultipartPart(
            $path,
            $upload->uploadId,
            1,
            'part-etag',
            self::PART_SIZE,
            hash('sha256', $contents),
        );
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('completeMultipartUpload')
            ->once()
            ->andReturn(new Result(['ETag' => 'object-etag']));
        $disk = $this->recordingDisk($contents, 1);
        $files = $this->service($client, $disk);
        $completion = $files->completeCurrentMultipart($upload, [$part], self::PART_SIZE);

        try {
            $files->verifyCurrentMultipart($completion);
            self::fail('Transient read failure was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('storage_object_read_failed', $exception->getMessage());
        }

        self::assertSame([], $disk->deletedKeys);
        $stored = $files->verifyCurrentMultipart($completion);
        self::assertSame(hash('sha256', $contents), $stored->sha256);
    }

    #[DataProvider('invalidWriteProvider')]
    public function test_it_rejects_invalid_write_before_calling_s3(
        string $key,
        string $contents,
        string $mime,
        string $checksum,
    ): void {
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldNotReceive('putObject');
        $files = $this->service($client, $this->recordingDisk());

        $this->expectException(InvalidArgumentException::class);

        $files->putPrivate($key, $contents, $mime, $checksum);
    }

    public static function invalidWriteProvider(): iterable
    {
        yield 'outside organization' => [
            'reports/exports/file.pdf',
            'body',
            'application/pdf',
            hash('sha256', 'body'),
        ];
        yield 'checksum mismatch' => [
            'org-42/reports/exports/file.pdf',
            'body',
            'application/pdf',
            str_repeat('a', 64),
        ];
        yield 'empty body' => [
            'org-42/reports/exports/file.pdf',
            '',
            'application/pdf',
            hash('sha256', ''),
        ];
        yield 'unsafe mime' => [
            'org-42/reports/exports/file.pdf',
            'body',
            "application/pdf\nunsafe",
            hash('sha256', 'body'),
        ];
    }

    private function service(S3ClientInterface $client, FilesystemAdapter $disk): FileService
    {
        $logging = new class extends LoggingService
        {
            public function __construct() {}
        };

        return new class($logging, $client, $disk) extends FileService
        {
            public function __construct(
                LoggingService $logging,
                private readonly S3ClientInterface $client,
                private readonly FilesystemAdapter $adapter,
            ) {
                parent::__construct($logging);
            }

            public function disk(?Organization $organization = null): FilesystemAdapter
            {
                return $this->adapter;
            }

            protected function s3Client(): S3ClientInterface
            {
                return $this->client;
            }
        };
    }

    private function recordingDisk(string $contents = 'stored-body', int $readFailures = 0): FilesystemAdapter
    {
        return new class($contents, $readFailures) extends FilesystemAdapter
        {
            /** @var list<string> */
            public array $deletedKeys = [];

            public function __construct(
                private readonly string $contents,
                private int $readFailures,
            ) {}

            public function getConfig(): array
            {
                return ['bucket' => 'prohelper-storage'];
            }

            public function temporaryUrl($path, $expiration, array $options = []): string
            {
                return 'https://download.example.test/'.rawurlencode((string) $path).'?expires=300';
            }

            public function readStream($path)
            {
                if ($this->readFailures > 0) {
                    $this->readFailures--;

                    return false;
                }

                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $this->contents);
                rewind($stream);

                return $stream;
            }

            public function exists($path): bool
            {
                return true;
            }

            public function delete($paths): bool
            {
                $this->deletedKeys[] = (string) $paths;

                return true;
            }
        };
    }
}
