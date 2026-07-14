<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class FileServiceS3ClientTest extends TestCase
{
    public function test_it_uses_the_client_exposed_by_laravel_s3_adapter(): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'ru-central1',
            'endpoint' => 'https://storage.yandexcloud.net',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $flysystemAdapter = new FlysystemS3Adapter($client, 'bucket');
        $disk = new LaravelS3Adapter(
            new Filesystem($flysystemAdapter),
            $flysystemAdapter,
            ['bucket' => 'bucket'],
            $client,
        );
        $logging = $this->createMock(LoggingService::class);
        $service = new class($logging, $disk) extends FileService
        {
            public function __construct(
                LoggingService $logging,
                private readonly LaravelS3Adapter $fakeDisk,
            ) {
                parent::__construct($logging);
            }

            public function disk(?Organization $organization = null): FilesystemAdapter|FilesystemContract
            {
                return $this->fakeDisk;
            }

            public function exposedS3Client(): S3ClientInterface
            {
                return $this->s3Client();
            }
        };

        self::assertSame($client, $service->exposedS3Client());
    }
}
