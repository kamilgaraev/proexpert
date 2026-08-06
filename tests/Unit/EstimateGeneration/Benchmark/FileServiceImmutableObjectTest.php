<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\Exceptions\VersionedObjectTransportException;
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
        $client = new RecordingS3Client([new Result(['ETag' => '"etag-1"'])]);

        $stored = $this->files($client)->putImmutable('org-7/object.json', 'body', 'application/json');

        self::assertTrue($stored['created']);
        self::assertSame('etag-1', $stored['etag']);
        self::assertArrayNotHasKey('version_id', $stored);
        self::assertSame('most', $client->calls[0]['arguments']['Bucket']);
        self::assertSame('org-7/object.json', $client->calls[0]['arguments']['Key']);
        self::assertSame('body', $client->calls[0]['arguments']['Body']);
        self::assertSame('application/json', $client->calls[0]['arguments']['ContentType']);
        self::assertSame('*', $client->calls[0]['arguments']['IfNoneMatch']);
        self::assertArrayNotHasKey('VersionId', $client->calls[0]['arguments']);
    }

    public function test_ai_estimator_immutable_object_is_tagged_for_module_lifecycle(): void
    {
        $client = new RecordingS3Client([
            new Result(['ETag' => '"etag-1"']),
            new Result,
        ]);

        $this->files($client)->putImmutable(
            'org-7/estimate-generation/benchmarks/run/object.json',
            'body',
            'application/json',
        );

        self::assertSame('putObjectTagging', $client->calls[1]['name']);
        self::assertArrayNotHasKey('VersionId', $client->calls[1]['arguments']);
        self::assertSame([
            'TagSet' => [['Key' => 'most-module', 'Value' => 'estimate-generation']],
        ], $client->calls[1]['arguments']['Tagging']);
    }

    public function test_ordinary_immutable_object_is_not_tagged(): void
    {
        $client = new RecordingS3Client([new Result(['ETag' => '"etag-1"'])]);

        $this->files($client)->putImmutable('org-7/documents/object.json', 'body', 'application/json');

        self::assertCount(1, $client->calls);
        self::assertSame('putObject', $client->calls[0]['name']);
    }

    public function test_precondition_conflict_reads_and_deletes_the_same_current_key(): void
    {
        $client = new RecordingS3Client;
        $command = $this->createMock(CommandInterface::class);
        $client->responses = [new AwsException('conflict', $command, ['response' => new Response(412)]),
            new Result(['ETag' => '"etag-existing"', 'ContentType' => 'application/json', 'ContentLength' => 4]),
            new Result(['Body' => Utils::streamFor('body')]), new Result];
        $files = $this->files($client);

        $stored = $files->putImmutable('org-7/object.json', 'body', 'application/json');
        $files->removeImmutable($stored['path']);

        self::assertFalse($stored['created']);
        self::assertSame(hash('sha256', 'body'), $stored['sha256']);
        self::assertArrayNotHasKey('VersionId', $client->calls[1]['arguments']);
        self::assertArrayNotHasKey('VersionId', $client->calls[2]['arguments']);
        self::assertArrayNotHasKey('VersionId', $client->calls[3]['arguments']);
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
            new Result(['ETag' => '"other"', 'ContentType' => 'application/json', 'ContentLength' => 9]),
            new Result(['Body' => Utils::streamFor('different')])];
        $body = json_encode([['case_id' => 'case-1']], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);
        $path = 'org-7/estimate-generation/benchmarks/123e4567-e89b-12d3-a456-426614174000/'.$hash.'.json';

        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('private_object_immutable_conflict');
        (new FileServiceBenchmarkPrivateObjectStore($this->files($client)))->putImmutable($path, $body, 'application/json');
    }

    public function test_oversized_head_rejects_before_get(): void
    {
        $client = new RecordingS3Client([new Result(['ContentLength' => 11])]);

        $this->expectExceptionMessage('s3_object_size_invalid');
        try {
            $this->files($client)->describeCurrent('org-7/object.json', 10);
        } finally {
            self::assertCount(1, $client->calls);
            self::assertSame('headObject', $client->calls[0]['name']);
        }
    }

    public function test_stream_larger_than_declared_length_is_rejected(): void
    {
        $client = new RecordingS3Client([
            new Result(['ContentLength' => 4]),
            new Result(['Body' => Utils::streamFor('overflow')]),
        ]);

        $this->expectExceptionMessage('s3_object_size_mismatch');
        $this->files($client)->describeCurrent('org-7/object.json', 20);
    }

    public function test_new_object_does_not_require_a_provider_version(): void
    {
        $client = new RecordingS3Client([new Result(['ETag' => 'etag'])]);

        $stored = $this->files($client)->putImmutable('org-7/object.json', 'body', 'application/json');

        self::assertSame(hash('sha256', 'body'), $stored['sha256']);
        self::assertArrayNotHasKey('version_id', $stored);
    }

    public function test_missing_current_key_is_integrity_failure(): void
    {
        $client = new RecordingS3Client;
        $command = $this->createMock(CommandInterface::class);
        $client->responses = [new AwsException('provider wording is irrelevant', $command, [
            'response' => new Response(404),
            'code' => 'NoSuchKey',
        ])];

        $this->expectException(VersionedObjectIntegrityException::class);
        $this->files($client)->describeCurrent('org-7/object.json', 20);
    }

    public function test_provider_outage_is_transport_failure(): void
    {
        $client = new RecordingS3Client;
        $command = $this->createMock(CommandInterface::class);
        $client->responses = [new AwsException('provider wording is irrelevant', $command, [
            'response' => new Response(503),
            'code' => 'ServiceUnavailable',
        ])];

        $this->expectException(VersionedObjectTransportException::class);
        $this->files($client)->describeCurrent('org-7/object.json', 20);
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
