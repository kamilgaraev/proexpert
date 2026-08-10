<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration;

use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentArtifactS3IntegrationTest extends TestCase
{
    public function test_configured_s3_preserves_tenant_path_and_immutable_artifact(): void
    {
        if (env('MOST_CI_DOCUMENT_S3_GATE') !== '1') {
            self::markTestSkipped('Disposable document S3 integration is CI-only.');
        }
        $integrationBucket = env('MOST_CI_DOCUMENT_S3_BUCKET');
        $integrationEndpoint = env('MOST_CI_DOCUMENT_S3_ENDPOINT');
        $deletionPrefix = 'org-999999/estimate-generation/integration';
        if (! is_string($integrationBucket) || $integrationBucket === ''
            || ! is_string($integrationEndpoint) || filter_var($integrationEndpoint, FILTER_VALIDATE_URL) === false
            || env('MOST_CI_DOCUMENT_S3_DELETE_ACK') !== $deletionPrefix) {
            self::fail('Dedicated disposable S3 bucket, endpoint and exact deletion-prefix acknowledgement are required.');
        }
        $original = config('filesystems.disks.s3');
        config()->set('filesystems.disks.s3.bucket', $integrationBucket);
        config()->set('filesystems.disks.s3.endpoint', $integrationEndpoint);
        Storage::forgetDisk('s3');
        $path = $deletionPrefix.'/'.bin2hex(random_bytes(12)).'/artifact.json';
        $files = app(FileService::class);
        try {
            $created = $files->putImmutable($path, '{"version":1}', 'application/json');
            $collision = $files->putImmutable($path, '{"version":2}', 'application/json');

            self::assertTrue($created['created']);
            self::assertFalse($collision['created']);
            self::assertSame($path, $collision['path']);
            self::assertSame(hash('sha256', '{"version":1}'), $collision['sha256']);
            self::assertSame('{"version":1}', $files->describeCurrent($path)['body']);
        } finally {
            $files->deleteCurrent($path);
            config()->set('filesystems.disks.s3', $original);
            Storage::forgetDisk('s3');
        }
    }
}
