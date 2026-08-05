<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\DTO\CurrentStoredFile;
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

    private function recordingDisk(): FilesystemAdapter
    {
        return new class extends FilesystemAdapter
        {
            /** @var list<string> */
            public array $deletedKeys = [];

            public function __construct() {}

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
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, 'stored-body');
                rewind($stream);

                return $stream;
            }

            public function delete($paths): bool
            {
                $this->deletedKeys[] = (string) $paths;

                return true;
            }
        };
    }
}
