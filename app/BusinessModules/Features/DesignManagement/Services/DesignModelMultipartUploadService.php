<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelMultipartUploader;
use Aws\S3\S3ClientInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DesignModelMultipartUploadService implements DesignModelMultipartUploader
{
    private const PART_SIZE_BYTES = 8_388_608;
    private const MAX_PARTS = 10_000;
    private const CACHE_PREFIX = 'design_management:model_upload:';

    public function __construct(
        private readonly S3ClientInterface $s3Client,
        private readonly DesignStoragePathService $pathService,
        private readonly DesignManagementService $designManagementService,
    ) {
    }

    public function start(DesignPackage $package, int $userId, array $payload): array
    {
        $fileSizeBytes = (int) $payload['file_size_bytes'];
        $partsCount = max(1, (int) ceil($fileSizeBytes / self::PART_SIZE_BYTES));

        if ($partsCount > self::MAX_PARTS) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_too_large'));
        }

        $bucket = $this->bucket();
        $uploadId = (string) Str::uuid();
        $sourcePath = $this->pathService->multipartSourcePath(
            (int) $package->organization_id,
            (int) $package->project_id,
            (int) $package->id,
            $uploadId,
            (string) $payload['original_name']
        );
        $result = $this->s3Client->createMultipartUpload(array_filter([
            'Bucket' => $bucket,
            'Key' => $sourcePath,
            'ACL' => 'private',
            'ContentType' => $payload['content_type'] ?? 'application/octet-stream',
            'Metadata' => [
                'organization_id' => (string) $package->organization_id,
                'project_id' => (string) $package->project_id,
                'package_id' => (string) $package->id,
                'upload_id' => $uploadId,
            ],
        ], static fn (mixed $value): bool => $value !== null));
        $s3UploadId = (string) ($result->get('UploadId') ?? '');

        if ($s3UploadId === '') {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_failed'));
        }

        $expiresAt = Carbon::now()->addHours(2);
        $parts = [];

        for ($partNumber = 1; $partNumber <= $partsCount; $partNumber++) {
            $command = $this->s3Client->getCommand('UploadPart', [
                'Bucket' => $bucket,
                'Key' => $sourcePath,
                'UploadId' => $s3UploadId,
                'PartNumber' => $partNumber,
            ]);
            $request = $this->s3Client->createPresignedRequest($command, $expiresAt);

            $parts[] = [
                'part_number' => $partNumber,
                'upload_url' => (string) $request->getUri(),
                'method' => 'PUT',
            ];
        }

        Cache::put($this->cacheKey($uploadId), [
            'upload_id' => $uploadId,
            's3_upload_id' => $s3UploadId,
            'bucket' => $bucket,
            'source_path' => $sourcePath,
            'organization_id' => (int) $package->organization_id,
            'project_id' => (int) $package->project_id,
            'package_id' => (int) $package->id,
            'user_id' => $userId,
            'part_size_bytes' => self::PART_SIZE_BYTES,
            'parts_count' => $partsCount,
            'file' => [
                'original_name' => (string) $payload['original_name'],
                'mime_type' => (string) ($payload['content_type'] ?? 'application/octet-stream'),
                'size_bytes' => $fileSizeBytes,
            ],
            'payload' => $this->modelPayload($payload),
        ], Carbon::now()->addDay());

        return [
            'upload_id' => $uploadId,
            'part_size_bytes' => self::PART_SIZE_BYTES,
            'parts_count' => $partsCount,
            'expires_at' => $expiresAt->toISOString(),
            'parts' => $parts,
        ];
    }

    public function complete(int $organizationId, int $userId, string $uploadId): DesignArtifactVersion
    {
        $session = $this->session($organizationId, $userId, $uploadId);
        $parts = $this->listUploadedParts($session);

        if (count($parts) !== (int) $session['parts_count']) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_incomplete'));
        }

        $uploadedBytes = array_sum(array_map(static fn (array $part): int => (int) ($part['Size'] ?? 0), $parts));
        if ($uploadedBytes !== (int) $session['file']['size_bytes']) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_incomplete'));
        }

        $this->s3Client->completeMultipartUpload([
            'Bucket' => (string) $session['bucket'],
            'Key' => (string) $session['source_path'],
            'UploadId' => (string) $session['s3_upload_id'],
            'MultipartUpload' => [
                'Parts' => array_map(static fn (array $part): array => [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag' => (string) $part['ETag'],
                ], $parts),
            ],
        ]);

        $package = $this->designManagementService->findPackage($organizationId, (int) $session['package_id']);

        if (!$package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }

        $version = $this->designManagementService->registerStoredIfcModel(
            $package,
            $userId,
            (string) $session['source_path'],
            $session['file'],
            $session['payload']
        );

        Cache::forget($this->cacheKey($uploadId));

        return $version;
    }

    public function abort(int $organizationId, int $userId, string $uploadId): void
    {
        $session = Cache::get($this->cacheKey($uploadId));

        if (!is_array($session)) {
            return;
        }

        if ((int) $session['organization_id'] !== $organizationId || (int) $session['user_id'] !== $userId) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_not_found'));
        }

        $this->s3Client->abortMultipartUpload([
            'Bucket' => (string) $session['bucket'],
            'Key' => (string) $session['source_path'],
            'UploadId' => (string) $session['s3_upload_id'],
        ]);

        Cache::forget($this->cacheKey($uploadId));
    }

    private function session(int $organizationId, int $userId, string $uploadId): array
    {
        $session = Cache::get($this->cacheKey($uploadId));

        if (!is_array($session)
            || (int) $session['organization_id'] !== $organizationId
            || (int) $session['user_id'] !== $userId
        ) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_not_found'));
        }

        return $session;
    }

    private function listUploadedParts(array $session): array
    {
        $parts = [];
        $marker = null;

        do {
            $result = $this->s3Client->listParts(array_filter([
                'Bucket' => (string) $session['bucket'],
                'Key' => (string) $session['source_path'],
                'UploadId' => (string) $session['s3_upload_id'],
                'PartNumberMarker' => $marker,
            ], static fn (mixed $value): bool => $value !== null));

            $parts = array_merge($parts, $result->get('Parts') ?? []);
            $marker = $result->get('NextPartNumberMarker');
        } while ((bool) ($result->get('IsTruncated') ?? false));

        usort($parts, static fn (array $left, array $right): int => (int) $left['PartNumber'] <=> (int) $right['PartNumber']);

        return $parts;
    }

    private function modelPayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'title',
            'version_number',
            'revision',
            'discipline',
            'stage',
            'model_date',
            'make_current',
            'metadata',
            'artifact_metadata',
        ]));
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.s3.bucket', 'prohelper-storage');
    }

    private function cacheKey(string $uploadId): string
    {
        return self::CACHE_PREFIX . $uploadId;
    }
}
