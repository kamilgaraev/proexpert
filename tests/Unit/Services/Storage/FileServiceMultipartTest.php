<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\DTO\TemporaryFileLink;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\FileService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FileServiceMultipartTest extends TestCase
{
    private const PART_SIZE = 5 * 1024 * 1024;

    public function test_report_storage_methods_have_the_current_object_contract(): void
    {
        $expected = [
            'startMultipart' => [['string', 'string', 'int', 'array'], MultipartUpload::class],
            'uploadPart' => [[MultipartUpload::class, 'int', 'string', 'string'], MultipartPart::class],
            'completeMultipart' => [[MultipartUpload::class, 'array', 'array'], StoredFile::class],
            'abortMultipart' => [[MultipartUpload::class], 'void'],
            'headCurrent' => [['string', 'int'], StoredFile::class],
            'createTemporaryLink' => [['string', 'int'], TemporaryFileLink::class],
            'deleteCurrent' => [['string'], 'void'],
        ];

        foreach ($expected as $name => [$parameterTypes, $returnType]) {
            $method = new ReflectionMethod(FileService::class, $name);

            self::assertTrue($method->isPublic());
            self::assertSame(
                $parameterTypes,
                array_map(
                    static fn ($parameter): string => (string) $parameter->getType(),
                    $method->getParameters(),
                ),
            );
            self::assertSame($returnType, (string) $method->getReturnType());
        }

        $describe = new ReflectionMethod(FileService::class, 'describeCurrent');
        self::assertSame(['string', 'int'], array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $describe->getParameters(),
        ));
    }

    public function test_dtos_preserve_current_object_identity_and_immutable_upload_metadata(): void
    {
        $metadata = $this->metadata();
        $path = 'org-7/reports/exports/01J00000000000000000000001/artifact.csv';
        $upload = new MultipartUpload($path, 'upload-1', 'text/csv', self::PART_SIZE, $metadata);
        $part = new MultipartPart(
            $path,
            $upload->uploadId,
            1,
            'etag-1',
            self::PART_SIZE,
            hash('sha256', 'part'),
        );
        $link = new TemporaryFileLink(
            'https://storage.example.test/file',
            $path,
            new DateTimeImmutable('+60 seconds'),
        );
        $stored = new StoredFile($path, 'etag-1', 4, hash('sha256', 'body'), 'text/csv');

        self::assertSame($metadata, $upload->metadata);
        self::assertSame('upload-1', $part->uploadId);
        self::assertSame($path, $link->storageKey);
        self::assertSame(hash('sha256', 'body'), $stored->sha256);

        $this->expectException(\Error::class);
        $upload->metadata['actor_id'] = '42';
    }

    #[DataProvider('invalidDtoProvider')]
    public function test_dtos_fail_closed_for_invalid_values(callable $construct): void
    {
        $this->expectException(InvalidArgumentException::class);
        $construct();
    }

    public static function invalidDtoProvider(): iterable
    {
        $metadata = self::validMetadata();

        yield 'foreign path' => [static fn () => new MultipartUpload(
            'reports/export.csv',
            'upload',
            'text/csv',
            self::PART_SIZE,
            $metadata,
        )];
        yield 'small part size' => [static fn () => new MultipartUpload(
            'org-7/reports/export.csv',
            'upload',
            'text/csv',
            self::PART_SIZE - 1,
            $metadata,
        )];
        yield 'part zero' => [static fn () => new MultipartPart(
            'org-7/reports/export.csv',
            'upload',
            0,
            'etag',
            1,
            str_repeat('a', 64),
        )];
        yield 'invalid link URL' => [static fn () => new TemporaryFileLink(
            'not-a-url',
            'org-7/reports/export.csv',
            new DateTimeImmutable('+60 seconds'),
        )];
        yield 'foreign link key' => [static fn () => new TemporaryFileLink(
            'https://storage.example.test/file',
            '../export.csv',
            new DateTimeImmutable('+60 seconds'),
        )];
        yield 'invalid stored checksum' => [static fn () => new StoredFile(
            'org-7/reports/export.csv',
            'etag',
            1,
            str_repeat('A', 64),
            'text/csv',
        )];
    }

    public function test_report_operations_use_current_key_without_provider_version_parameters(): void
    {
        $commands = [];
        $bytes = str_repeat('a', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $metadata = $this->metadata();
        $handler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['UploadId' => 'upload-1']);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['ETag' => '"part-etag"']);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['ETag' => '"artifact-etag"']);
            },
            static function (CommandInterface $command) use (&$commands, $metadata): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'ETag' => '"artifact-etag"',
                    'ContentLength' => self::PART_SIZE,
                    'ContentType' => 'text/csv',
                    'Metadata' => $metadata,
                ]);
            },
            static function (CommandInterface $command) use (&$commands, $bytes): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['Body' => Utils::streamFor($bytes)]);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result;
            },
        ]);
        $disk = $this->createMock(Filesystem::class);
        $disk->expects(self::once())
            ->method('delete')
            ->with('org-7/reports/exports/01J00000000000000000000001/artifact.csv')
            ->willReturn(true);
        $files = $this->files($handler, $disk);
        $path = 'org-7/reports/exports/01J00000000000000000000001/artifact.csv';

        $upload = $files->startMultipart($path, 'text/csv', self::PART_SIZE, $metadata);
        $part = $files->uploadPart($upload, 1, $bytes, $checksum);
        $completed = $files->completeMultipart($upload, [$part], [
            'IfNoneMatch' => '*',
            'ApplicationChecksumSHA256' => $checksum,
        ]);
        $headed = $files->headCurrent($path);
        $link = $files->createTemporaryLink($path, 60);
        $files->abortMultipart($upload);
        $files->deleteCurrent($path);

        self::assertSame($checksum, $completed->sha256);
        self::assertSame($checksum, $headed->sha256);
        self::assertSame($path, $link->storageKey);
        self::assertSame(
            ['CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload', 'HeadObject', 'GetObject', 'AbortMultipartUpload'],
            array_column($commands, 0),
        );
        self::assertSame('reports-test', $commands[0][1]['Bucket']);
        self::assertSame($metadata, $commands[0][1]['Metadata']);
        self::assertSame('*', $commands[2][1]['IfNoneMatch']);
        foreach ($commands as [, $arguments]) {
            self::assertArrayNotHasKey('VersionId', $arguments);
        }
    }

    public function test_invalid_checksum_and_ttl_fail_before_an_aws_request(): void
    {
        $handler = new MockHandler([new Result(['UploadId' => 'upload-1'])]);
        $files = $this->files($handler);
        $upload = $files->startMultipart(
            'org-7/reports/export.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );

        try {
            $files->uploadPart($upload, 1, 'bytes', str_repeat('0', 64));
            self::fail('Checksum mismatch was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('multipart_part_checksum_mismatch', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temporary_link_ttl_invalid');
        $files->createTemporaryLink($upload->organizationPath, 301);
    }

    public function test_completion_rejects_a_provider_response_for_another_object_identity(): void
    {
        $bytes = str_repeat('x', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $handler = new MockHandler([
            new Result(['UploadId' => 'upload-1']),
            new Result(['ETag' => '"part-etag"']),
            new Result([
                'Key' => 'org-7/reports/another.csv',
                'Bucket' => 'reports-test',
                'ETag' => '"artifact-etag"',
            ]),
        ]);
        $files = $this->files($handler);
        $upload = $files->startMultipart(
            'org-7/reports/export.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );
        $part = $files->uploadPart($upload, 1, $bytes, $checksum);

        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_multipart_completion_identity_invalid');
        $files->completeMultipart($upload, [$part], [
            'IfNoneMatch' => '*',
            'ApplicationChecksumSHA256' => $checksum,
        ]);
    }

    public function test_current_report_metadata_is_normalized_to_lowercase(): void
    {
        $bytes = 'body';
        $metadata = [];
        foreach ($this->metadata() as $key => $value) {
            $metadata[strtoupper($key)] = $value;
        }
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $metadata,
            ]),
            new Result(['Body' => Utils::streamFor($bytes)]),
        ]);

        $description = $this->files($handler)->describeCurrent('org-7/reports/export.csv', 100);

        self::assertSame($this->metadata(), $description['metadata']);
    }

    public function test_negative_max_bytes_streams_current_object_without_retaining_body(): void
    {
        $bytes = str_repeat('stream-chunk-', 10_000);
        $stream = new TrackingReadableStream($bytes);
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $this->metadata(),
            ]),
            new Result(['Body' => $stream]),
        ]);

        $description = $this->files($handler)->describeCurrent(
            'org-7/reports/export.csv',
            -strlen($bytes),
        );

        self::assertSame('', $description['body']);
        self::assertSame(strlen($bytes), $description['size']);
        self::assertSame(hash('sha256', $bytes), $description['sha256']);
        self::assertLessThanOrEqual(8192, $stream->maxRequestedBytes);
    }

    #[DataProvider('invalidVerificationBoundProvider')]
    public function test_invalid_verification_bounds_fail_before_storage(int $maxBytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('s3_object_size_invalid');
        $this->files(new MockHandler)->describeCurrent('org-7/object.csv', $maxBytes);
    }

    public static function invalidVerificationBoundProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'minimum integer' => [PHP_INT_MIN];
    }

    public function test_current_report_metadata_case_collision_fails_closed(): void
    {
        $metadata = $this->metadata();
        $metadata['ORGANIZATION_ID'] = $metadata['organization_id'];
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'ContentLength' => 4,
                'ContentType' => 'text/csv',
                'Metadata' => $metadata,
            ]),
        ]);

        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_report_metadata_invalid');
        $this->files($handler)->describeCurrent('org-7/reports/export.csv', 100);
    }

    private function files(MockHandler $handler, ?Filesystem $disk = null): FileService
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://storage.example.test',
            'use_path_style_endpoint' => true,
            'credentials' => false,
            'handler' => $handler,
        ]);
        $logging = $this->createMock(LoggingService::class);
        $disk ??= $this->createStub(Filesystem::class);

        return new class($logging, $client, $disk) extends FileService
        {
            public function __construct(
                LoggingService $logging,
                private readonly S3ClientInterface $client,
                private readonly Filesystem $filesystem,
            ) {
                parent::__construct($logging);
            }

            public function disk(?Organization $organization = null): FilesystemAdapter|Filesystem
            {
                return $this->filesystem;
            }

            protected function reportS3Client(): S3ClientInterface
            {
                return $this->client;
            }

            protected function reportBucket(): string
            {
                return 'reports-test';
            }
        };
    }

    private function metadata(): array
    {
        return self::validMetadata();
    }

    private static function validMetadata(): array
    {
        return [
            'organization_id' => '7',
            'export_id' => '01J00000000000000000000001',
            'export_hash' => str_repeat('a', 64),
            'run_id' => '01J00000000000000000000002',
            'result_hash' => str_repeat('b', 64),
            'snapshot_id' => 'snapshot-1',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ];
    }
}

final class TrackingReadableStream
{
    public int $maxRequestedBytes = 0;

    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function read(int $length): string
    {
        $this->maxRequestedBytes = max($this->maxRequestedBytes, $length);
        $chunk = substr($this->bytes, $this->offset, $length);
        $this->offset += strlen($chunk);

        return $chunk;
    }
}
