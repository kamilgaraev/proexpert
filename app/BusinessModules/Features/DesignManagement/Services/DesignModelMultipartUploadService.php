<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelMultipartUploader;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelRegistrationService;
use App\Services\Storage\DTO\CurrentMultipartCompletion;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class DesignModelMultipartUploadService implements DesignModelMultipartUploader
{
    private const PART_SIZE_BYTES = 5_242_880;

    private const MAX_PARTS = 10_000;

    private const CACHE_PREFIX = 'design_management:model_upload:';

    private const CACHE_LOCK_SECONDS = 10;

    private const CACHE_LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly FileService $files,
        private readonly DesignStoragePathService $pathService,
        private readonly DesignModelRegistrationService $designManagementService,
    ) {}

    public function start(DesignPackage $package, int $userId, array $payload): array
    {
        $this->designManagementService->ensurePackageAcceptsModelChanges($package);

        $fileSizeBytes = (int) $payload['file_size_bytes'];
        $partsCount = max(1, (int) ceil($fileSizeBytes / self::PART_SIZE_BYTES));

        if ($partsCount > self::MAX_PARTS) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_too_large'));
        }

        $uploadId = (string) Str::uuid();
        $sourcePath = $this->pathService->multipartSourcePath(
            (int) $package->organization_id,
            (int) $package->project_id,
            (int) $package->id,
            $userId,
            $uploadId,
            (string) $payload['original_name']
        );
        $mime = (string) ($payload['content_type'] ?? 'application/octet-stream');

        try {
            $storageUpload = $this->files->startMultipart(
                $sourcePath,
                $mime,
                self::PART_SIZE_BYTES,
                [
                    'organization_id' => (string) $package->organization_id,
                    'project_id' => (string) $package->project_id,
                    'package_id' => (string) $package->id,
                    'user_id' => (string) $userId,
                    'upload_id' => $uploadId,
                ],
            );
        } catch (Throwable $exception) {
            throw $this->storageFailure($exception);
        }

        $expiresAt = Carbon::now()->addHours(2);
        $parts = [];

        for ($partNumber = 1; $partNumber <= $partsCount; $partNumber++) {
            $parts[] = [
                'part_number' => $partNumber,
                'method' => 'POST',
            ];
        }

        $session = [
            'upload_id' => $uploadId,
            's3_upload_id' => $storageUpload->uploadId,
            'source_path' => $sourcePath,
            'organization_id' => (int) $package->organization_id,
            'project_id' => (int) $package->project_id,
            'package_id' => (int) $package->id,
            'user_id' => $userId,
            'part_size_bytes' => self::PART_SIZE_BYTES,
            'parts_count' => $partsCount,
            'file' => [
                'original_name' => (string) $payload['original_name'],
                'mime_type' => $mime,
                'size_bytes' => $fileSizeBytes,
            ],
            'uploaded_parts' => [],
            'completion' => null,
            'expires_at' => $expiresAt->toISOString(),
            'payload' => $this->modelPayload($payload),
        ];
        try {
            $sessionStored = Cache::put($this->cacheKey($uploadId), $session, $expiresAt);
        } catch (Throwable $exception) {
            $this->abortUntrackedUpload($storageUpload);

            throw $this->storageFailure($exception);
        }
        if (! $sessionStored) {
            $this->abortUntrackedUpload($storageUpload);

            throw $this->storageFailure(new \RuntimeException('multipart_session_store_failed'));
        }

        return [
            'upload_id' => $uploadId,
            'part_size_bytes' => self::PART_SIZE_BYTES,
            'parts_count' => $partsCount,
            'expires_at' => $expiresAt->toISOString(),
            'parts' => $parts,
        ];
    }

    public function uploadPart(
        int $organizationId,
        int $userId,
        string $uploadId,
        int $partNumber,
        UploadedFile $chunk
    ): array {
        $session = $this->session($organizationId, $userId, $uploadId);

        if ($partNumber < 1 || $partNumber > (int) $session['parts_count']) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_not_found'));
        }

        $realPath = $chunk->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        $bytes = file_get_contents($realPath);
        if (! is_string($bytes) || $bytes === '') {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        try {
            $part = $this->files->uploadPart(
                $this->multipartUpload($session),
                $partNumber,
                $bytes,
                hash('sha256', $bytes),
            );
        } catch (Throwable $exception) {
            throw $this->storageFailure($exception);
        }

        $this->storeUploadedPart($organizationId, $userId, $uploadId, $part);

        return [
            'upload_id' => $uploadId,
            'part_number' => $part->number,
            'etag' => $part->etag,
            'size_bytes' => $part->sizeBytes,
        ];
    }

    public function complete(int $organizationId, int $userId, string $uploadId): DesignArtifactVersion
    {
        $session = $this->session($organizationId, $userId, $uploadId);
        $upload = $this->multipartUpload($session);
        $parts = $this->multipartParts($session, $upload);

        $package = $this->designManagementService->findPackage($organizationId, (int) $session['package_id']);

        if (! $package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }

        $this->designManagementService->ensurePackageAcceptsModelChanges($package);

        $completion = $this->multipartCompletion($session);
        if (! $completion instanceof CurrentMultipartCompletion) {
            try {
                $completion = $this->files->completeCurrentMultipart(
                    $upload,
                    $parts,
                    (int) $session['file']['size_bytes'],
                );
            } catch (Throwable $exception) {
                throw $this->storageFailure($exception);
            }

            $this->storeCompletion($organizationId, $userId, $uploadId, $completion);
        }

        try {
            $stored = $this->files->verifyCurrentMultipart($completion);
        } catch (Throwable $exception) {
            throw $this->storageFailure($exception);
        }

        $fileInfo = $session['file'];
        $fileInfo['size_bytes'] = $stored->sizeBytes;
        $fileInfo['sha256'] = $stored->sha256;

        try {
            $version = $this->designManagementService->registerStoredIfcModel(
                $package,
                $userId,
                $stored->key,
                $fileInfo,
                $session['payload']
            );
        } catch (Throwable $exception) {
            $this->deleteUnregisteredObject($stored->key);
            Cache::forget($this->cacheKey($uploadId));

            throw $exception;
        }

        Cache::forget($this->cacheKey($uploadId));

        return $version;
    }

    public function abort(int $organizationId, int $userId, string $uploadId): void
    {
        $session = Cache::get($this->cacheKey($uploadId));

        if (! is_array($session)) {
            return;
        }

        if ((int) $session['organization_id'] !== $organizationId || (int) $session['user_id'] !== $userId) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_not_found'));
        }

        try {
            $completion = $this->multipartCompletion($session);
            if ($completion instanceof CurrentMultipartCompletion) {
                $this->deleteCurrentIfExists($completion->key);
            } else {
                $upload = $this->multipartUpload($session);
                $this->files->abortMultipart($upload);
                $this->deleteCurrentIfExists($upload->organizationPath);
            }
        } catch (Throwable $exception) {
            throw $this->storageFailure($exception);
        }

        Cache::forget($this->cacheKey($uploadId));
    }

    private function session(int $organizationId, int $userId, string $uploadId): array
    {
        $session = Cache::get($this->cacheKey($uploadId));

        if (! is_array($session)
            || (int) $session['organization_id'] !== $organizationId
            || (int) $session['user_id'] !== $userId
        ) {
            throw new DomainException(trans_message('design_management.errors.multipart_upload_not_found'));
        }

        return $session;
    }

    private function multipartUpload(array $session): MultipartUpload
    {
        try {
            return new MultipartUpload(
                (string) $session['source_path'],
                (string) $session['s3_upload_id'],
                (string) $session['file']['mime_type'],
                (int) $session['part_size_bytes'],
                [
                    'organization_id' => (string) $session['organization_id'],
                    'project_id' => (string) $session['project_id'],
                    'package_id' => (string) $session['package_id'],
                    'user_id' => (string) $session['user_id'],
                    'upload_id' => (string) $session['upload_id'],
                ],
            );
        } catch (Throwable $exception) {
            throw new DomainException(
                trans_message('design_management.errors.multipart_upload_not_found'),
                0,
                $exception,
            );
        }
    }

    private function multipartCompletion(array $session): ?CurrentMultipartCompletion
    {
        $completion = $session['completion'] ?? null;
        if ($completion === null) {
            return null;
        }

        try {
            if (! is_array($completion)) {
                throw new \InvalidArgumentException('multipart_completion_invalid');
            }

            return new CurrentMultipartCompletion(
                (string) ($completion['key'] ?? ''),
                (string) ($completion['etag'] ?? ''),
                (int) ($completion['size_bytes'] ?? 0),
                (string) ($completion['mime'] ?? ''),
            );
        } catch (Throwable $exception) {
            throw new DomainException(
                trans_message('design_management.errors.multipart_upload_not_found'),
                0,
                $exception,
            );
        }
    }

    /** @return list<MultipartPart> */
    private function multipartParts(array $session, MultipartUpload $upload): array
    {
        $parts = [];
        for ($partNumber = 1; $partNumber <= (int) $session['parts_count']; $partNumber++) {
            $cached = $session['uploaded_parts'][$partNumber] ?? null;
            if (! is_array($cached)) {
                throw new DomainException(trans_message('design_management.errors.multipart_upload_incomplete'));
            }

            try {
                $parts[] = new MultipartPart(
                    $upload->organizationPath,
                    $upload->uploadId,
                    (int) ($cached['PartNumber'] ?? 0),
                    (string) ($cached['ETag'] ?? ''),
                    (int) ($cached['Size'] ?? 0),
                    (string) ($cached['ChecksumSHA256'] ?? ''),
                );
            } catch (Throwable $exception) {
                throw new DomainException(
                    trans_message('design_management.errors.multipart_upload_incomplete'),
                    0,
                    $exception,
                );
            }
        }

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

    private function storageFailure(Throwable $exception): DomainException
    {
        return new DomainException(
            trans_message('design_management.errors.multipart_upload_failed'),
            0,
            $exception,
        );
    }

    private function storeUploadedPart(
        int $organizationId,
        int $userId,
        string $uploadId,
        MultipartPart $part,
    ): void {
        try {
            $stored = Cache::lock(
                $this->cacheKey($uploadId).':lock',
                self::CACHE_LOCK_SECONDS,
            )->block(self::CACHE_LOCK_WAIT_SECONDS, function () use (
                $organizationId,
                $userId,
                $uploadId,
                $part,
            ): bool {
                $session = $this->session($organizationId, $userId, $uploadId);
                $session['uploaded_parts'][$part->number] = [
                    'PartNumber' => $part->number,
                    'ETag' => $part->etag,
                    'Size' => $part->sizeBytes,
                    'ChecksumSHA256' => $part->checksumSha256,
                ];

                return Cache::put(
                    $this->cacheKey($uploadId),
                    $session,
                    $this->sessionExpiry($session),
                );
            });
        } catch (Throwable $exception) {
            throw $this->storageFailure($exception);
        }

        if ($stored !== true) {
            throw $this->storageFailure(new \RuntimeException('multipart_part_state_store_failed'));
        }
    }

    private function storeCompletion(
        int $organizationId,
        int $userId,
        string $uploadId,
        CurrentMultipartCompletion $completion,
    ): void {
        try {
            $stored = Cache::lock(
                $this->cacheKey($uploadId).':lock',
                self::CACHE_LOCK_SECONDS,
            )->block(self::CACHE_LOCK_WAIT_SECONDS, function () use (
                $organizationId,
                $userId,
                $uploadId,
                $completion,
            ): bool {
                $session = $this->session($organizationId, $userId, $uploadId);
                $session['completion'] = [
                    'key' => $completion->key,
                    'etag' => $completion->etag,
                    'size_bytes' => $completion->sizeBytes,
                    'mime' => $completion->mime,
                ];

                return Cache::put(
                    $this->cacheKey($uploadId),
                    $session,
                    $this->sessionExpiry($session),
                );
            });
        } catch (Throwable $exception) {
            try {
                $this->discardUntrackedCompletion($uploadId, $completion);
            } catch (Throwable $cleanupException) {
                throw $this->storageFailure($cleanupException);
            }

            throw $this->storageFailure($exception);
        }

        if ($stored !== true) {
            try {
                $this->discardUntrackedCompletion($uploadId, $completion);
            } catch (Throwable $cleanupException) {
                throw $this->storageFailure($cleanupException);
            }

            throw $this->storageFailure(new \RuntimeException('multipart_completion_state_store_failed'));
        }
    }

    private function sessionExpiry(array $session): Carbon
    {
        $expiresAt = $session['expires_at'] ?? null;
        if (is_string($expiresAt)) {
            try {
                return Carbon::parse($expiresAt);
            } catch (Throwable) {
            }
        }

        return Carbon::now()->addHours(2);
    }

    private function abortUntrackedUpload(MultipartUpload $upload): void
    {
        try {
            $this->files->abortMultipart($upload);
        } catch (Throwable $exception) {
            Log::error('Failed to abort untracked design model upload', [
                'key' => $upload->organizationPath,
                'exception' => $exception::class,
            ]);
        }
    }

    private function discardUntrackedCompletion(
        string $uploadId,
        CurrentMultipartCompletion $completion,
    ): void {
        try {
            $this->deleteCurrentIfExists($completion->key);
        } catch (Throwable $exception) {
            Log::error('Failed to remove untracked completed design model upload', [
                'key' => $completion->key,
                'exception' => $exception::class,
            ]);

            throw new \RuntimeException('multipart_completion_cleanup_failed', 0, $exception);
        }

        if (! Cache::forget($this->cacheKey($uploadId))) {
            throw new \RuntimeException('multipart_completion_session_cleanup_failed');
        }
    }

    private function deleteCurrentIfExists(string $key): void
    {
        if ($this->files->existsCurrent($key)) {
            $this->files->deleteCurrent($key);
        }
    }

    private function deleteUnregisteredObject(string $key): void
    {
        try {
            $this->files->deleteCurrent($key);
        } catch (Throwable $exception) {
            Log::error('Failed to remove unregistered design model object', [
                'key' => $key,
                'exception' => $exception::class,
            ]);
        }
    }

    private function cacheKey(string $uploadId): string
    {
        return self::CACHE_PREFIX.$uploadId;
    }
}
