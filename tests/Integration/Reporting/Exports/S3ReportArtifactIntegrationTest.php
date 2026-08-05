<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Exports;

use App\Services\Logging\LoggingService;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\FileService;
use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class S3ReportArtifactIntegrationTest extends TestCase
{
    private const PART_SIZE = 5 * 1024 * 1024;

    public function test_disposable_versioned_s3_enforces_immutable_race_and_pinned_operations(): void
    {
        if (env('REPORTS_S3_INTEGRATION') !== '1') {
            self::markTestSkipped('Disposable versioned S3 integration is CI-only.');
        }

        $integrationBucket = env('REPORTS_S3_INTEGRATION_BUCKET');
        $integrationEndpoint = env('REPORTS_S3_INTEGRATION_ENDPOINT');
        $configuredBucket = config('filesystems.disks.s3.bucket');
        if (
            ! is_string($integrationBucket)
            || trim($integrationBucket) === ''
            || ! is_string($integrationEndpoint)
            || filter_var($integrationEndpoint, FILTER_VALIDATE_URL) === false
            || ! is_string($configuredBucket)
            || hash_equals($configuredBucket, $integrationBucket)
            || strtolower($integrationBucket) === 'official-reports'
        ) {
            self::fail('Disposable S3 bucket/endpoint markers are missing or unsafe.');
        }

        $originalDisk = config('filesystems.disks.s3');
        config()->set('filesystems.disks.s3.bucket', $integrationBucket);
        config()->set('filesystems.disks.s3.endpoint', $integrationEndpoint);
        Storage::forgetDisk('s3');

        $files = new class($this->app->make(LoggingService::class)) extends FileService
        {
            public function reportClient(): S3ClientInterface
            {
                return $this->reportS3Client();
            }

            public function reportBucketName(): string
            {
                return $this->reportBucket();
            }
        };
        $exportId = strtoupper((string) Str::ulid());
        $runId = strtoupper((string) Str::ulid());
        $path = 'org-999999/reports/integration/'.$exportId.'/artifact.bin';
        $bytes = random_bytes(self::PART_SIZE);
        $checksum = hash('sha256', $bytes);
        $metadata = [
            'organization_id' => '999999',
            'export_id' => $exportId,
            'export_hash' => str_repeat('a', 64),
            'run_id' => $runId,
            'result_hash' => str_repeat('b', 64),
            'snapshot_id' => 'integration-snapshot',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ];
        $winner = null;
        $loser = null;

        try {
            $winner = $files->startMultipart($path, 'application/octet-stream', self::PART_SIZE, $metadata);
            $loser = $files->startMultipart($path, 'application/octet-stream', self::PART_SIZE, $metadata);
            $winnerPart = $files->uploadPart($winner, 1, $bytes, $checksum);
            $loserPart = $files->uploadPart($loser, 1, $bytes, $checksum);
            $stored = $files->completeMultipart($winner, [$winnerPart], [
                'IfNoneMatch' => '*',
                'ApplicationChecksumSHA256' => $checksum,
            ]);

            try {
                $files->completeMultipart($loser, [$loserPart], [
                    'IfNoneMatch' => '*',
                    'ApplicationChecksumSHA256' => $checksum,
                ]);
                self::fail('Concurrent immutable completion did not return 409/412.');
            } catch (Throwable $exception) {
                self::assertTrue($this->hasConditionalStatus($exception));
                $files->abortMultipart($loser);
                $loser = null;
            }

            $headed = $files->headVersion($path, $stored->versionId);
            self::assertSame($stored->versionId, $headed->versionId);
            self::assertSame($checksum, $headed->checksum->value);
            self::assertSame(self::PART_SIZE, $headed->sizeBytes);
            self::assertSame($stored->etag, $headed->etag);

            $link = $files->createTemporaryLink($path, $stored->versionId, 60);
            self::assertStringContainsString(
                rawurlencode($stored->versionId),
                rawurldecode($link->url),
            );

            $second = $files->reportClient()->putObject([
                'Bucket' => $files->reportBucketName(),
                'Key' => $path,
                'Body' => 'newer-version',
                'ContentType' => 'application/octet-stream',
                'Metadata' => $metadata,
            ]);
            $secondVersion = (string) ($second['VersionId'] ?? '');
            self::assertNotSame('', $secondVersion);

            $files->deleteVersion($path, $stored->versionId);
            $winner = null;
            try {
                $files->headVersion($path, $stored->versionId);
                self::fail('Deleted exact version is still readable.');
            } catch (VersionedObjectIntegrityException) {
            }
            self::assertSame(
                $secondVersion,
                $files->reportClient()->headObject([
                    'Bucket' => $files->reportBucketName(),
                    'Key' => $path,
                    'VersionId' => $secondVersion,
                ])['VersionId'],
            );
            $files->deleteVersion($path, $secondVersion);
        } finally {
            if ($winner instanceof MultipartUpload) {
                try {
                    $files->abortMultipart($winner);
                } catch (Throwable) {
                }
            }
            if ($loser instanceof MultipartUpload) {
                try {
                    $files->abortMultipart($loser);
                } catch (Throwable) {
                }
            }
            config()->set('filesystems.disks.s3', $originalDisk);
            Storage::forgetDisk('s3');
        }
    }

    private function hasConditionalStatus(Throwable $exception): bool
    {
        do {
            if ($exception instanceof AwsException && in_array($exception->getStatusCode(), [409, 412], true)) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }
}
