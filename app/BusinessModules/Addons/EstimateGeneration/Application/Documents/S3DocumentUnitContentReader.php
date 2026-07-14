<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;

final readonly class S3DocumentUnitContentReader implements DocumentUnitContentReader
{
    public function __construct(private BoundedVersionedS3ObjectReader $reader) {}

    public function read(DocumentUnitExecutionContext $context): string
    {
        $path = $context->locator['artifact_path'] ?? null;
        $bytes = $context->locator['artifact_bytes'] ?? null;
        $sha256 = $context->locator['artifact_sha256'] ?? null;
        $versionId = $context->locator['artifact_version_id'] ?? null;
        if (! is_string($path) || ! is_int($bytes) || ! is_string($sha256) || ! is_string($versionId)) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_artifact_locator_invalid');
        }

        self::assertOrganizationPath($path, $context->organizationId);

        try {
            return $this->reader->read(
                $context->organizationId,
                $path,
                max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
                $bytes,
                $sha256,
                $versionId,
            )->body;
        } catch (S3ObjectLocatorException $exception) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_artifact_integrity_failed', previous: $exception);
        } catch (S3ObjectTransportException $exception) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
        }
    }

    public static function assertOrganizationPath(string $path, int $organizationId): void
    {
        if (! str_starts_with($path, 'org-'.$organizationId.'/')) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_scope_invalid');
        }
    }
}
