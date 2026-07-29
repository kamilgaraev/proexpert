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
        yield 'invalid link URL' => [static fn () => new TemporaryFileLink(
            'not-a-url',
            'version',
            new DateTimeImmutable('+60 seconds'),
        )];
    }

    public function test_all_seven_operations_use_the_reports_bucket_and_exact_version_identity(): void
    {
        $commands = [];
        $bytes = str_repeat('a', self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $checksumBase64 = base64_encode(hex2bin($checksum));
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
            static function (CommandInterface $command) use (&$commands, $checksumBase64): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'ETag' => '"artifact-etag"',
                    'VersionId' => 'version-1',
                    'ChecksumSHA256' => $checksumBase64,
                ]);
            },
            static function (CommandInterface $command) use (&$commands, $checksumBase64, $metadata): Result {
                $commands[] = [$command->getName(), $command->toArray()];

                return new Result([
                    'ETag' => '"artifact-etag"',
                    'VersionId' => 'version-1',
                    'ContentLength' => self::PART_SIZE,
                    'ContentType' => 'text/csv',
                    'ChecksumSHA256' => $checksumBase64,
                    'Metadata' => $metadata,
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
            'ChecksumSHA256' => $checksumBase64,
            'MpuObjectSize' => self::PART_SIZE,
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
                'AbortMultipartUpload',
                'DeleteObject',
            ],
            array_column($commands, 0),
        );
        self::assertSame('reports-test', $commands[0][1]['Bucket']);
        self::assertSame('SHA256', $commands[0][1]['ChecksumAlgorithm']);
        self::assertSame($metadata, $commands[0][1]['Metadata']);
        self::assertSame($checksumBase64, $commands[1][1]['ChecksumSHA256']);
        self::assertSame('*', $commands[2][1]['IfNoneMatch']);
        self::assertSame('version-1', $commands[3][1]['VersionId']);
        self::assertSame('ENABLED', $commands[3][1]['ChecksumMode']);
        self::assertSame('version-1', $commands[5][1]['VersionId']);
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
                'ChecksumSHA256' => base64_encode(str_repeat("\0", 32)),
                'MpuObjectSize' => self::PART_SIZE,
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
            'ChecksumSHA256' => $checksumBase64,
            'MpuObjectSize' => self::PART_SIZE,
        ]);
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
