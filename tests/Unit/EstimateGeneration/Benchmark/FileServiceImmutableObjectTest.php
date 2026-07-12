<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\Services\Storage\FileService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class FileServiceImmutableObjectTest extends TestCase
{
    public function test_conditional_put_returns_authoritative_provider_descriptor(): void
    {
        $client = new RecordingS3Client([new Result(['ETag' => '"etag-1"', 'VersionId' => 'version-1'])]);

        $stored = $this->files($client)->putImmutable('org-7/object.json', 'body', 'application/json');

        self::assertTrue($stored['created']);
        self::assertSame('etag-1', $stored['etag']);
        self::assertSame('version-1', $stored['version_id']);
        self::assertSame(['Bucket' => 'most', 'Key' => 'org-7/object.json', 'Body' => 'body',
            'ContentType' => 'application/json', 'IfNoneMatch' => '*'], $client->calls[0]['arguments']);
    }

    public function test_precondition_conflict_reads_same_version_and_delete_targets_exact_version(): void
    {
        $client = new RecordingS3Client;
        $command = $this->createMock(CommandInterface::class);
        $client->responses = [new AwsException('conflict', $command, ['response' => new Response(412)]),
            new Result(['ETag' => '"etag-existing"', 'VersionId' => 'version-existing', 'ContentType' => 'application/json', 'ContentLength' => 4]),
            new Result(['Body' => Utils::streamFor('body'), 'VersionId' => 'version-existing']), new Result];
        $files = $this->files($client);

        $stored = $files->putImmutable('org-7/object.json', 'body', 'application/json');
        $files->removeImmutable($stored['path'], $stored['version_id']);

        self::assertFalse($stored['created']);
        self::assertSame(hash('sha256', 'body'), $stored['sha256']);
        self::assertSame('version-existing', $client->calls[2]['arguments']['VersionId']);
        self::assertSame('version-existing', $client->calls[3]['arguments']['VersionId']);
    }

    public function test_missing_s3_client_capability_fails_closed(): void
    {
        $disk = $this->disk();
        $files = new class($disk) extends FileService
        {
            public function __construct(private readonly FilesystemAdapter $adapter) {}

            public function disk(?\App\Models\Organization $organization = null): FilesystemAdapter
            {
                return $this->adapter;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('s3_conditional_put_unavailable');
        $files->putImmutable('org-7/object.json', 'body', 'application/json');
    }

    public function test_concurrent_409_with_different_content_is_rejected_by_immutable_store(): void
    {
        $client = new RecordingS3Client;
        $command = $this->createMock(CommandInterface::class);
        $client->responses = [new AwsException('conflict', $command, ['response' => new Response(409)]),
            new Result(['ETag' => '"other"', 'VersionId' => 'version-other', 'ContentType' => 'application/json', 'ContentLength' => 9]),
            new Result(['Body' => Utils::streamFor('different'), 'VersionId' => 'version-other'])];
        $body = json_encode([['case_id' => 'case-1']], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);
        $path = 'org-7/estimate-generation/benchmarks/123e4567-e89b-12d3-a456-426614174000/'.$hash.'.json';

        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('private_object_immutable_conflict');
        (new FileServiceBenchmarkPrivateObjectStore($this->files($client)))->putImmutable($path, $body, 'application/json');
    }

    public function test_oversized_head_rejects_before_get(): void
    {
        $client = new RecordingS3Client([new Result(['VersionId' => 'v1', 'ContentLength' => 11])]);

        $this->expectExceptionMessage('s3_object_size_invalid');
        try {
            $this->files($client)->describeVersion('org-7/object.json', 'v1', 10);
        } finally {
            self::assertCount(1, $client->calls);
            self::assertSame('headObject', $client->calls[0]['name']);
        }
    }

    public function test_stream_larger_than_declared_length_is_rejected(): void
    {
        $client = new RecordingS3Client([
            new Result(['VersionId' => 'v1', 'ContentLength' => 4]),
            new Result(['VersionId' => 'v1', 'Body' => Utils::streamFor('overflow')]),
        ]);

        $this->expectExceptionMessage('s3_object_size_mismatch');
        $this->files($client)->describeVersion('org-7/object.json', 'v1', 20);
    }

    public function test_new_object_without_provider_version_fails_closed(): void
    {
        $client = new RecordingS3Client([new Result(['ETag' => 'etag'])]);

        $this->expectExceptionMessage('s3_bucket_versioning_required');
        $this->files($client)->putImmutable('org-7/object.json', 'body', 'application/json');
    }

    private function files(S3ClientInterface $client): FileService
    {
        return new class($this->disk(), $client) extends FileService
        {
            public function __construct(private readonly FilesystemAdapter $adapter, private readonly S3ClientInterface $client) {}

            public function disk(?\App\Models\Organization $organization = null): FilesystemAdapter
            {
                return $this->adapter;
            }

            protected function s3Client(): S3ClientInterface
            {
                return $this->client;
            }
        };
    }

    private function disk(): FilesystemAdapter
    {
        $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['bucket' => 'most']);
    }
}

final class RecordingS3Client extends \Aws\S3\S3Client
{
    /** @var array<int, Result|AwsException> */
    public array $responses;

    /** @var array<int, array{name:string,arguments:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<int, Result|AwsException> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function __call($name, array $args)
    {
        $this->calls[] = ['name' => (string) $name, 'arguments' => $args[0] ?? []];
        $response = array_shift($this->responses);
        if ($response instanceof AwsException) {
            throw $response;
        }

        return $response;
    }
}
