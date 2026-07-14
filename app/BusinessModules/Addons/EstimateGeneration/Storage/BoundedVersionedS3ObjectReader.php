<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Storage;

use App\Services\Storage\FileService;
use RuntimeException;
use Throwable;

final readonly class BoundedVersionedS3ObjectReader
{
    public function __construct(private FileService $files) {}

    public function read(
        int $organizationId,
        string $path,
        int $maxBytes,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
        ?string $versionId = null,
    ): VersionedS3ObjectContent {
        if ($organizationId < 1 || ! str_starts_with($path, "org-{$organizationId}/")
            || str_contains($path, '..') || str_contains($path, "\0") || $maxBytes < 1) {
            throw new S3ObjectLocatorException('estimate_generation_object_locator_invalid');
        }
        if ($expectedBytes !== null && ($expectedBytes < 1 || $expectedBytes > $maxBytes)) {
            throw new S3ObjectLocatorException('estimate_generation_object_size_invalid');
        }
        if ($expectedSha256 !== null && preg_match('/\Asha256:[0-9a-f]{64}\z/', $expectedSha256) !== 1) {
            throw new S3ObjectLocatorException('estimate_generation_object_hash_invalid');
        }
        if ($versionId !== null && preg_match('/\A[\x21-\x7e]{1,1024}\z/D', $versionId) !== 1) {
            throw new S3ObjectLocatorException('estimate_generation_object_version_invalid');
        }
        try {
            $object = $this->files->describeVersion($path, $versionId, $maxBytes);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                's3_object_size_invalid',
                's3_object_size_mismatch',
                's3_object_version_mismatch',
                's3_object_stream_invalid',
                's3_bucket_versioning_required',
            ], true)) {
                throw new S3ObjectLocatorException('estimate_generation_object_integrity_failed', 0, $exception);
            }
            throw new S3ObjectTransportException('estimate_generation_object_storage_unavailable', 0, $exception);
        } catch (Throwable $exception) {
            throw new S3ObjectTransportException('estimate_generation_object_storage_unavailable', 0, $exception);
        }
        $resolvedHash = 'sha256:'.$object['sha256'];
        if (($expectedBytes !== null && $object['size'] !== $expectedBytes)
            || ($expectedSha256 !== null && ! hash_equals($expectedSha256, $resolvedHash))
            || ($versionId !== null && ! hash_equals($versionId, $object['version_id']))) {
            throw new S3ObjectLocatorException('estimate_generation_object_integrity_failed');
        }

        return new VersionedS3ObjectContent(
            $object['body'],
            $object['size'],
            $resolvedHash,
            $object['version_id'],
            $object['content_type'],
        );
    }
}
