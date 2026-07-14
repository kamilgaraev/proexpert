<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\S3PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\Exceptions\VersionedObjectTransportException;
use App\Services\Storage\FileService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class S3PipelineArtifactStoreTest extends TestCase
{
    #[Test]
    #[DataProvider('storageFailures')]
    public function it_maps_versioned_storage_failures_to_pipeline_contract(
        RuntimeException $storageFailure,
        FailureCategory $expectedCategory,
        string $expectedCode,
    ): void {
        $files = new class($storageFailure) extends FileService
        {
            public function __construct(private readonly RuntimeException $failure) {}

            public function describeVersion(string $path, ?string $versionId, int $maxBytes = 64_000_000): array
            {
                throw $this->failure;
            }
        };
        $store = new S3PipelineArtifactStore($files, new BoundedVersionedS3ObjectReader($files));

        try {
            $store->read($this->context(), $this->reference());
            self::fail('Storage failure must be mapped.');
        } catch (TypedFailureException $exception) {
            self::assertSame($expectedCategory, $exception->category);
            self::assertSame($expectedCode, $exception->safeCode);
        }
    }

    /** @return iterable<string, array{RuntimeException, FailureCategory, string}> */
    public static function storageFailures(): iterable
    {
        yield 'transport can be retried' => [
            new VersionedObjectTransportException('provider details'),
            FailureCategory::Recoverable,
            'pipeline_artifact_storage_unavailable',
        ];
        yield 'missing pinned version is terminal' => [
            new VersionedObjectIntegrityException('provider details'),
            FailureCategory::Terminal,
            'pipeline_artifact_integrity_failed',
        ];
    }

    #[Test]
    public function it_rejects_a_non_s3_reference_as_terminal_artifact_integrity_failure(): void
    {
        $files = new class extends FileService
        {
            public function __construct() {}
        };
        $store = new S3PipelineArtifactStore($files, new BoundedVersionedS3ObjectReader($files));
        $reference = new PipelineArtifactReference(
            'memory_json_v1',
            'pipeline/object.json',
            'sha256:'.str_repeat('b', 64),
            1,
        );

        try {
            $store->read($this->context(), $reference);
            self::fail('Invalid artifact reference must be rejected.');
        } catch (TypedFailureException $exception) {
            self::assertSame(FailureCategory::Terminal, $exception->category);
            self::assertSame('pipeline_artifact_integrity_failed', $exception->safeCode);
        }
    }

    private function context(): PipelineContext
    {
        return new PipelineContext(
            sessionId: 30,
            organizationId: 20,
            projectId: 10,
            stateVersion: 3,
            inputVersion: 'sha256:'.str_repeat('a', 64),
            sessionStatus: 'generating',
            generationAttemptId: '00000000-0000-4000-8000-000000000001',
        );
    }

    private function reference(): PipelineArtifactReference
    {
        return new PipelineArtifactReference(
            's3_json_v1',
            'org-20/estimate-generation/sessions/30/pipeline/attempts/00000000-0000-4000-8000-000000000001/object.json',
            'sha256:'.str_repeat('b', 64),
            1,
            'version-1',
        );
    }
}
