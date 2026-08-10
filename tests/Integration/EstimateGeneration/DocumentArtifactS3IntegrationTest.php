<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration;

use App\Services\Storage\FileService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExternalIntegrationGate;

final class DocumentArtifactS3IntegrationTest extends TestCase
{
    public function test_configured_s3_preserves_tenant_path_and_immutable_artifact(): void
    {
        if (! ExternalIntegrationGate::enabled('MOST_CI_DOCUMENT_S3_GATE')) {
            self::markTestSkipped('Disposable document S3 integration is CI-only.');
        }
        $integrationBucket = ExternalIntegrationGate::required('MOST_CI_DOCUMENT_S3_BUCKET');
        $integrationEndpoint = ExternalIntegrationGate::required('MOST_CI_DOCUMENT_S3_ENDPOINT');
        $deletionPrefix = 'org-999999/estimate-generation/integration';
        if (filter_var($integrationEndpoint, FILTER_VALIDATE_URL) === false
            || ExternalIntegrationGate::required('MOST_CI_DOCUMENT_S3_DELETE_ACK') !== $deletionPrefix) {
            self::fail('Dedicated disposable S3 bucket, endpoint and exact deletion-prefix acknowledgement are required.');
        }
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $original = config('filesystems.disks.s3');
        config()->set('filesystems.disks.s3.bucket', $integrationBucket);
        config()->set('filesystems.disks.s3.endpoint', $integrationEndpoint);
        Storage::forgetDisk('s3');
        $runPrefix = $deletionPrefix.'/'.bin2hex(random_bytes(12));
        $path = $runPrefix.'/artifact.json';
        self::assertMatchesRegularExpression(
            '~^org-999999/estimate-generation/integration/[a-f0-9]{24}/artifact\.json$~D',
            $path,
        );
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
            if (str_starts_with($path, $runPrefix.'/')
                && str_starts_with($runPrefix, $deletionPrefix.'/')) {
                $files->deleteCurrent($path);
            }
            config()->set('filesystems.disks.s3', $original);
            Storage::forgetDisk('s3');
            Facade::clearResolvedInstances();
            $app->flush();
            Facade::setFacadeApplication($previousFacadeApplication);
            Container::setInstance($previousContainer);
        }
    }
}
