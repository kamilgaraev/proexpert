<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FileServiceMultipartTest extends TestCase
{
    private const PART_SIZE = 5 * 1024 * 1024;

    public function test_report_storage_methods_have_the_locked_contract(): void
    {
        $expected = [
            'startMultipart' => [['string', 'string', 'int', 'array'], MultipartUpload::class],
            'uploadPart' => [[MultipartUpload::class, 'int', 'string', 'string'], MultipartPart::class],
            'completeMultipart' => [[MultipartUpload::class, 'array', 'array'], StoredFile::class],
            'abortMultipart' => [[MultipartUpload::class], 'void'],
            'headVersion' => [['string', 'string'], StoredFile::class],
            'createTemporaryLink' => [['string', 'string', 'int'], TemporaryFileLink::class],
            'deleteVersion' => [['string', 'string'], 'void'],
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

        $describe = new ReflectionMethod(FileService::class, 'describeVersion');
        self::assertCount(3, $describe->getParameters());
        self::assertSame(['string', '?string', 'int'], array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $describe->getParameters(),
        ));
    }

    public function test_dtos_reject_invalid_identity_and_preserve_immutable_upload_metadata(): void
    {
        $metadata = $this->metadata();
        $upload = new MultipartUpload(
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'upload-1',
            'text/csv',
            self::PART_SIZE,
            $metadata,
        );
        $part = new MultipartPart(
            $upload->organizationPath,
            $upload->uploadId,
            1,
            'etag-1',
            self::PART_SIZE,
            hash('sha256', 'part'),
        );
        $link = new TemporaryFileLink(
            'https://storage.example.test/file?versionId=version-1',
            'version-1',
            new DateTimeImmutable('+60 seconds'),
        );

        self::assertSame($metadata, $upload->metadata);
        self::assertSame('upload-1', $part->uploadId);
        self::assertSame('version-1', $link->versionId);

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
        yield 'large part size' => [static fn () => new MultipartUpload(
            'org-7/reports/export.csv',
            'upload',
            'text/csv',
            (64 * 1024 * 1024) + 1,
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
        yield 'part above S3 maximum' => [static fn () => new MultipartPart(
            'org-7/reports/export.csv',
            'upload',
            10001,
            'etag',
            1,
            str_repeat('a', 64),
        )];
        yield 'uppercase checksum' => [static fn () => new MultipartPart(
            'org-7/reports/export.csv',
            'upload',
            1,
            'etag',
            1,
            str_repeat('A', 64),
        )];
        yield 'literal null part upload id' => [static fn () => new MultipartPart(
            'org-7/reports/export.csv',
            'null',
            1,
            'etag',
            1,
            str_repeat('a', 64),
        )];
        yield 'invalid link URL' => [static fn () => new TemporaryFileLink(
            'not-a-url',
            'version',
            new DateTimeImmutable('+60 seconds'),
        )];
        yield 'unversioned link identity' => [static fn () => new TemporaryFileLink(
            'https://storage.example.test/file?versionId=null',
            'null',
            new DateTimeImmutable('+60 seconds'),
        )];
        yield 'stored file literal null version' => [static fn () => new StoredFile(
            'org-7/reports/export.csv',
            'null',
            'etag',
            1,
            new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(str_repeat('a', 64)),
            'text/csv',
        )];
    }

    public function test_all_seven_operations_use_the_reports_bucket_and_exact_version_identity(): void
    {
        $commands = [];
        $bytes = str_repeat('a', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $checksumBase64 = base64_encode(hex2bin($checksum));
        $compositeChecksum = base64_encode(hash('sha256', hex2bin($checksum), true)).'-1';
        $metadata = $this->metadata();
        $handler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['UploadId' => 'upload-1']);
            },
            static function (CommandInterface $command) use (&$commands, $checksumBase64): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result(['ETag' => '"part-etag"', 'ChecksumSHA256' => $checksumBase64]);
            },
            static function (CommandInterface $command) use (&$commands, $compositeChecksum): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'ETag' => '"artifact-etag"',
                    'VersionId' => 'version-1',
                    'ChecksumSHA256' => $compositeChecksum,
                ]);
            },
            static function (CommandInterface $command) use (&$commands, $compositeChecksum, $metadata): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'ETag' => '"artifact-etag"',
                    'VersionId' => 'version-1',
                    'ContentLength' => self::PART_SIZE,
                    'ContentType' => 'text/csv',
                    'ChecksumSHA256' => $compositeChecksum,
                    'Metadata' => $metadata,
                ]);
            },
            static function (CommandInterface $command) use (&$commands, $bytes): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'VersionId' => 'version-1',
                    'Body' => Utils::streamFor($bytes),
                ]);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result();
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result();
            },
        ]);
        $files = $this->files($handler);
        $path = 'org-7/reports/exports/01J00000000000000000000001/artifact.csv';

        $upload = $files->startMultipart($path, 'text/csv', self::PART_SIZE, $metadata);
        $part = $files->uploadPart($upload, 1, $bytes, $checksum);
        $completed = $files->completeMultipart($upload, [$part], [
            'IfNoneMatch' => '*',
            'ApplicationChecksumSHA256' => $checksum,
        ]);
        $headed = $files->headVersion($path, 'version-1');
        $link = $files->createTemporaryLink($path, 'version-1', 60);
        $files->abortMultipart($upload);
        $files->deleteVersion($path, 'version-1');

        self::assertSame('version-1', $completed->versionId);
        self::assertSame($checksum, $headed->checksum->value);
        self::assertSame('version-1', $link->versionId);
        self::assertStringContainsString('versionId=version-1', $link->url);
        self::assertSame(
            [
                'CreateMultipartUpload',
                'UploadPart',
                'CompleteMultipartUpload',
                'HeadObject',
                'GetObject',
                'AbortMultipartUpload',
                'DeleteObject',
            ],
            array_column($commands, 0),
        );
        self::assertSame('reports-test', $commands[0][1]['Bucket']);
        self::assertArrayNotHasKey('ChecksumAlgorithm', $commands[0][1]);
        self::assertSame($metadata, $commands[0][1]['Metadata']);
        self::assertArrayNotHasKey('ChecksumSHA256', $commands[1][1]);
        self::assertSame('*', $commands[2][1]['IfNoneMatch']);
        self::assertArrayNotHasKey('ChecksumSHA256', $commands[2][1]);
        self::assertArrayNotHasKey('MpuObjectSize', $commands[2][1]);
        self::assertSame('version-1', $commands[3][1]['VersionId']);
        self::assertArrayNotHasKey('ChecksumMode', $commands[3][1]);
        self::assertSame('version-1', $commands[4][1]['VersionId']);
        self::assertSame('version-1', $commands[6][1]['VersionId']);
    }

    public function test_invalid_receipts_checksum_and_ttl_fail_before_an_aws_request(): void
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

        $part = new MultipartPart(
            $upload->organizationPath,
            $upload->uploadId,
            2,
            'etag',
            self::PART_SIZE,
            str_repeat('a', 64),
        );

        try {
            $files->completeMultipart($upload, [$part], [
                'IfNoneMatch' => '*',
                'ApplicationChecksumSHA256' => str_repeat('0', 64),
            ]);
            self::fail('Skipped first part was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('multipart_parts_invalid', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temporary_link_ttl_invalid');
        $files->createTemporaryLink($upload->organizationPath, 'version-1', 301);
    }

    public function test_completion_rejects_a_provider_response_for_another_object_identity(): void
    {
        $bytes = str_repeat('x', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $checksumBase64 = base64_encode(hex2bin($checksum));
        $handler = new MockHandler([
            new Result(['UploadId' => 'upload-1']),
            new Result(['ETag' => '"part-etag"', 'ChecksumSHA256' => $checksumBase64]),
            new Result([
                'Key' => 'org-7/reports/another.csv',
                'Bucket' => 'reports-test',
                'ETag' => '"artifact-etag"',
                'VersionId' => 'version-1',
                'ChecksumSHA256' => $checksumBase64,
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

    public function test_multi_part_completion_uses_provider_composite_shape_but_returns_true_application_hash(): void
    {
        $first = str_repeat('a', self::PART_SIZE);
        $second = str_repeat('b', self::PART_SIZE);
        $firstHash = hash('sha256', $first);
        $secondHash = hash('sha256', $second);
        $applicationHash = hash('sha256', $first.$second);
        $commands = [];
        $handler = new MockHandler([
            new Result(['UploadId' => 'upload-2']),
            new Result(['ETag' => '"part-1"', 'ChecksumSHA256' => base64_encode(hex2bin($firstHash))]),
            new Result(['ETag' => '"part-2"', 'ChecksumSHA256' => base64_encode(hex2bin($secondHash))]),
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command->toArray();

                return new Result([
                    'ETag' => '"artifact-etag-2"',
                    'VersionId' => 'version-2',
                ]);
            },
        ]);
        $files = $this->files($handler);
        $upload = $files->startMultipart(
            'org-7/reports/two-parts.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );
        $parts = [
            $files->uploadPart($upload, 1, $first, $firstHash),
            $files->uploadPart($upload, 2, $second, $secondHash),
        ];

        $stored = $files->completeMultipart($upload, $parts, [
            'IfNoneMatch' => '*',
            'ApplicationChecksumSHA256' => $applicationHash,
        ]);

        self::assertSame($applicationHash, $stored->checksum->value);
        self::assertArrayNotHasKey('ChecksumSHA256', $commands[0]);
        self::assertArrayNotHasKey('MpuObjectSize', $commands[0]);
        self::assertSame([], array_filter(
            $commands[0]['MultipartUpload']['Parts'],
            static fn (array $part): bool => array_key_exists('ChecksumSHA256', $part),
        ));
    }

    public function test_literal_null_version_and_missing_metadata_fail_closed(): void
    {
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => 4,
                'ContentType' => 'text/csv',
                'Metadata' => array_diff_key($this->metadata(), ['renderer_version' => true]),
            ]),
        ]);
        $files = $this->files($handler);

        foreach ([
            static fn () => $files->headVersion('org-7/reports/export.csv', 'null'),
            static fn () => $files->createTemporaryLink('org-7/reports/export.csv', 'null', 60),
            static fn () => $files->deleteVersion('org-7/reports/export.csv', 'null'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Literal null version was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_report_metadata_invalid');
        $files->headVersion('org-7/reports/export.csv', 'version-1');
    }

    public function test_versioned_report_metadata_is_normalized_to_lowercase(): void
    {
        $bytes = 'body';
        $metadata = [];
        foreach ($this->metadata() as $key => $value) {
            $metadata[strtoupper($key)] = $value;
        }
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $metadata,
            ]),
            new Result(['VersionId' => 'version-1', 'Body' => Utils::streamFor($bytes)]),
        ]);

        $description = $this->files($handler)->describeVersion(
            'org-7/reports/export.csv',
            'version-1',
            100,
        );

        self::assertSame($this->metadata(), $description['metadata']);
    }

    public function test_zero_max_bytes_streams_exact_version_without_retaining_body(): void
    {
        $bytes = str_repeat('stream-chunk-', 10_000);
        $stream = new TrackingReadableStream($bytes);
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $this->metadata(),
            ]),
            new Result(['VersionId' => 'version-1', 'Body' => $stream]),
        ]);

        $description = $this->files($handler)->describeVersion(
            'org-7/reports/export.csv',
            'version-1',
            0,
        );

        self::assertSame('', $description['body']);
        self::assertSame(strlen($bytes), $description['size']);
        self::assertSame(hash('sha256', $bytes), $description['sha256']);
        self::assertLessThanOrEqual(8192, $stream->maxRequestedBytes);
    }

    public function test_positive_max_bytes_preserves_body_and_enforces_limit(): void
    {
        $bytes = 'legacy-body';
        $success = $this->files(new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $this->metadata(),
            ]),
            new Result(['VersionId' => 'version-1', 'Body' => Utils::streamFor($bytes)]),
        ]))->describeVersion('org-7/reports/export.csv', 'version-1', strlen($bytes));
        self::assertSame($bytes, $success['body']);

        $limited = $this->files(new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => strlen($bytes),
                'ContentType' => 'text/csv',
                'Metadata' => $this->metadata(),
            ]),
        ]));
        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_object_size_invalid');
        $limited->describeVersion('org-7/reports/export.csv', 'version-1', strlen($bytes) - 1);
    }

    public function test_zero_max_bytes_requires_an_exact_version_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('s3_versioned_read_requires_version');
        $this->files(new MockHandler())->describeVersion('org-7/object.csv', null, 0);
    }

    public function test_versioned_report_metadata_case_collision_fails_closed(): void
    {
        $metadata = $this->metadata();
        $metadata['ORGANIZATION_ID'] = $metadata['organization_id'];
        $handler = new MockHandler([
            new Result([
                'ETag' => '"etag"',
                'VersionId' => 'version-1',
                'ContentLength' => 4,
                'ContentType' => 'text/csv',
                'Metadata' => $metadata,
            ]),
        ]);

        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_report_metadata_invalid');
        $this->files($handler)->describeVersion('org-7/reports/export.csv', 'version-1', 100);
    }

    public function test_provider_returned_literal_null_version_fails_closed_at_complete_and_head(): void
    {
        $bytes = str_repeat('n', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $handler = new MockHandler([
            new Result(['UploadId' => 'upload-null-version']),
            new Result(['ETag' => '"part"', 'ChecksumSHA256' => base64_encode(hex2bin($checksum))]),
            new Result(['ETag' => '"artifact"', 'VersionId' => 'null']),
        ]);
        $files = $this->files($handler);
        $upload = $files->startMultipart(
            'org-7/reports/null-version.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );
        $part = $files->uploadPart($upload, 1, $bytes, $checksum);

        try {
            $files->completeMultipart($upload, [$part], [
                'IfNoneMatch' => '*',
                'ApplicationChecksumSHA256' => $checksum,
            ]);
            self::fail('Provider null version was accepted at completion.');
        } catch (VersionedObjectIntegrityException $exception) {
            self::assertSame('s3_multipart_completion_identity_invalid', $exception->getMessage());
        }

        $headFiles = $this->files(new MockHandler([
            new Result([
                'ETag' => '"artifact"',
                'VersionId' => 'null',
                'ContentLength' => self::PART_SIZE,
                'ContentType' => 'text/csv',
                'Metadata' => $this->metadata(),
            ]),
        ]));
        $this->expectException(VersionedObjectIntegrityException::class);
        $this->expectExceptionMessage('s3_bucket_versioning_required');
        $headFiles->headVersion('org-7/reports/null-version.csv', 'version-1');
    }

    private function files(MockHandler $handler): FileService
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

        return new class($logging, $client) extends FileService
        {
            public function __construct(
                LoggingService $logging,
                private readonly S3ClientInterface $client,
            ) {
                parent::__construct($logging);
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

    public function __construct(private readonly string $bytes)
    {
    }

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
