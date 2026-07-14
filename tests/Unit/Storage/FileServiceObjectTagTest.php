<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class FileServiceObjectTagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('log', new class
        {
            public function __call(string $name, array $arguments): void {}
        });
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_ai_content_write_is_tagged_on_the_exact_resolved_version(): void
    {
        $client = new RecordingTagS3Client([
            new Result(['VersionId' => 'version-7']),
            new Result(),
        ]);
        $organization = new Organization();
        $organization->id = 7;

        $stored = $this->service($client)->putContent(
            'body',
            'estimate-generation/sessions/42',
            'document.json',
            organization: $organization,
        );

        self::assertSame('org-7/estimate-generation/sessions/42/document.json', $stored);
        self::assertSame('headObject', $client->calls[0]['name']);
        self::assertSame('putObjectTagging', $client->calls[1]['name']);
        self::assertSame('version-7', $client->calls[1]['arguments']['VersionId']);
    }

    public function test_ordinary_content_write_is_not_tagged(): void
    {
        $client = new RecordingTagS3Client();
        $organization = new Organization();
        $organization->id = 7;

        $stored = $this->service($client)->putContent(
            'body',
            'documents',
            'document.json',
            organization: $organization,
        );

        self::assertSame('org-7/documents/document.json', $stored);
        self::assertSame([], $client->calls);
    }

    private function service(S3ClientInterface $client): FileService
    {
        $disk = new class extends FilesystemAdapter
        {
            public function __construct() {}

            public function getConfig(): array
            {
                return ['bucket' => 'most'];
            }

            public function put($path, $contents, $options = []): bool
            {
                return true;
            }
        };

        $logging = new class extends LoggingService
        {
            public function __construct() {}
        };

        return new class($logging, $disk, $client) extends FileService
        {
            public function __construct(
                LoggingService $logging,
                private readonly FilesystemAdapter $fakeDisk,
                private readonly S3ClientInterface $fakeClient,
            ) {
                parent::__construct($logging);
            }

            public function disk(?Organization $organization = null): FilesystemAdapter
            {
                return $this->fakeDisk;
            }

            protected function s3Client(): S3ClientInterface
            {
                return $this->fakeClient;
            }
        };
    }
}

final class RecordingTagS3Client extends \Aws\S3\S3Client
{
    /** @var array<int, Result> */
    public array $responses;

    /** @var array<int, array{name:string,arguments:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<int, Result> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function __call($name, array $args)
    {
        $this->calls[] = ['name' => (string) $name, 'arguments' => $args[0] ?? []];

        return array_shift($this->responses);
    }
}
