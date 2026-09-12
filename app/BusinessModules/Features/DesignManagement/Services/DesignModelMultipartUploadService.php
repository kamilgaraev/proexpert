<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcUploadSession;
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

    private const COMPLETION_LOCK_SECONDS = 900;

    public function __construct(
        private readonly FileService $files,
        private readonly DesignStoragePathService $pathService,
        private readonly DesignModelRegistrationService $designManagementService,
    ) {}

    public function start(DesignPackage $package, int $userId, array $payload): array
    {
        $this->designManagementService->ensurePackageAcceptsModelChanges($package);
        $this->cleanupExpiredSessions((int) $package->organization_id, $userId);

        $fileSizeBytes = (int) $payload['file_size_bytes'];
        $identity = $this->fileIdentity($payload);
        $resumable = DesignIfcUploadSession::query()
            ->where('organization_id', $package->organization_id)
            ->where('project_id', $package->project_id)
            ->where('package_id', $package->id)
            ->where('user_id', $userId)
            ->where('file_identity', $identity)
            ->whereIn('status', ['active', 'completing'])
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($resumable instanceof DesignIfcUploadSession) {
            return $this->startPayload($this->sessionArray($resumable));
        }
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
            DesignIfcUploadSession::query()->create([
                'id' => $uploadId,
                'organization_id' => $session['organization_id'],
                'project_id' => $session['project_id'],
                'package_id' => $session['package_id'],
                'user_id' => $session['user_id'],
                'file_identity' => $identity,
                's3_upload_id' => $session['s3_upload_id'],
                'source_path' => $session['source_path'],
                'original_name' => $session['file']['original_name'],
                'mime_type' => $session['file']['mime_type'],
                'size_bytes' => $session['file']['size_bytes'],
                'part_size_bytes' => $session['part_size_bytes'],
                'parts_count' => $session['parts_count'],
                'uploaded_parts' => [],
                'payload' => $session['payload'],
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);
            $sessionStored = Cache::put($this->cacheKey($uploadId), $session, $expiresAt);
        } catch (Throwable $exception) {
            DesignIfcUploadSession::query()->whereKey($uploadId)->delete();
            $this->abortUntrackedUpload($storageUpload);

            throw $this->storageFailure($exception);
        }
        if (! $sessionStored) {
            DesignIfcUploadSession::query()->whereKey($uploadId)->delete();
            $this->abortUntrackedUpload($storageUpload);

            throw $this->storageFailure(new \RuntimeException('multipart_session_store_failed'));
        }

        return $this->startPayload($session);
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
        $completed = DesignIfcUploadSession::query()
            ->with('completedVersion')
            ->whereKey($uploadId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->first();
        if ($completed?->completedVersion instanceof DesignArtifactVersion) {
            return $completed->completedVersion;
        }

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

        $registrationAttempted = false;
        try {
            $version = Cache::lock($this->cacheKey($uploadId).':completion-lock', self::COMPLETION_LOCK_SECONDS)
                ->block(self::CACHE_LOCK_WAIT_SECONDS, function () use ($uploadId, $package, $userId, $stored, $fileInfo, $session, &$registrationAttempted): DesignArtifactVersion {
                    $alreadyCompleted = DesignIfcUploadSession::query()
                        ->with('completedVersion')
                        ->whereKey($uploadId)
                        ->lockForUpdate()
                        ->first();
                    if ($alreadyCompleted?->completedVersion instanceof DesignArtifactVersion) {
                        return $alreadyCompleted->completedVersion;
                    }

                    $registrationAttempted = true;
                    $registered = $this->designManagementService->registerStoredIfcModel(
                        $package,
                        $userId,
                        $stored->key,
                        $fileInfo,
                        $session['payload'],
                    );
                    DesignIfcUploadSession::query()->whereKey($uploadId)->update([
                        'status' => 'completed',
                        'completed_version_id' => $registered->id,
                    ]);

                    return $registered;
                });
            if (! $version instanceof DesignArtifactVersion) {
                throw new \RuntimeException('multipart_completion_lock_failed');
            }
        } catch (Throwable $exception) {
            $registered = DesignIfcUploadSession::query()
                ->whereKey($uploadId)
                ->whereNotNull('completed_version_id')
                ->exists();
            if ($registrationAttempted && ! $registered) {
                $this->deleteUnregisteredObject($stored->key);
                DesignIfcUploadSession::query()->whereKey($uploadId)->delete();
                Cache::forget($this->cacheKey($uploadId));
            }

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

        DesignIfcUploadSession::query()->whereKey($uploadId)->update(['status' => 'aborted', 'cleaned_at' => now()]);
        Cache::forget($this->cacheKey($uploadId));
    }

    public function cleanupExpiredSessions(int $organizationId, int $userId, int $limit = 20): void
    {
        $sessions = DesignIfcUploadSession::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'completing'])
            ->whereNull('completed_version_id')
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get();

        foreach ($sessions as $stored) {
            try {
                $session = $this->sessionArray($stored);
                $completion = $this->multipartCompletion($session);
                if ($completion instanceof CurrentMultipartCompletion) {
                    $this->deleteCurrentIfExists($completion->key);
                } else {
                    $this->files->abortMultipart($this->multipartUpload($session));
                }
                $stored->forceFill(['status' => 'expired', 'cleaned_at' => now()])->save();
                Cache::forget($this->cacheKey((string) $stored->id));
            } catch (Throwable $exception) {
                Log::warning('design_management.ifc_upload_expiry_cleanup_failed', [
                    'upload_id' => $stored->id,
                    'organization_id' => $organizationId,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function session(int $organizationId, int $userId, string $uploadId): array
    {
        $session = Cache::get($this->cacheKey($uploadId));
        if (! is_array($session)) {
            $stored = DesignIfcUploadSession::query()
                ->whereKey($uploadId)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'completing', 'completed'])
                ->first();
            $session = $stored instanceof DesignIfcUploadSession ? $this->sessionArray($stored) : null;
        }

        if (! is_array($session)
            || (int) $session['organization_id'] !== $organizationId
            || (int) $session['user_id'] !== $userId
            || Carbon::parse((string) ($session['expires_at'] ?? now()))->isPast()
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

                DesignIfcUploadSession::query()->whereKey($uploadId)->update([
                    'uploaded_parts' => $session['uploaded_parts'],
                    'status' => 'active',
                ]);

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

                DesignIfcUploadSession::query()->whereKey($uploadId)->update([
                    'completion' => $session['completion'],
                    'status' => 'completing',
                ]);

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

    private function fileIdentity(array $payload): string
    {
        $provided = strtolower(trim((string) ($payload['file_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $provided) === 1) {
            return $provided;
        }

        return hash('sha256', implode("\0", [
            (string) $payload['original_name'],
            (string) $payload['file_size_bytes'],
            (string) ($payload['last_modified_at'] ?? ''),
        ]));
    }

    private function startPayload(array $session): array
    {
        $uploaded = array_map('intval', array_keys($session['uploaded_parts'] ?? []));
        $parts = [];
        for ($part = 1; $part <= (int) $session['parts_count']; $part++) {
            $parts[] = ['part_number' => $part, 'method' => 'POST', 'uploaded' => in_array($part, $uploaded, true)];
        }

        return [
            'upload_id' => $session['upload_id'],
            'part_size_bytes' => (int) $session['part_size_bytes'],
            'parts_count' => (int) $session['parts_count'],
            'expires_at' => $session['expires_at'],
            'parts' => $parts,
        ];
    }

    private function sessionArray(DesignIfcUploadSession $session): array
    {
        return [
            'upload_id' => $session->id,
            's3_upload_id' => $session->s3_upload_id,
            'source_path' => $session->source_path,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'package_id' => $session->package_id,
            'user_id' => $session->user_id,
            'part_size_bytes' => $session->part_size_bytes,
            'parts_count' => $session->parts_count,
            'file' => ['original_name' => $session->original_name, 'mime_type' => $session->mime_type, 'size_bytes' => $session->size_bytes],
            'uploaded_parts' => $session->uploaded_parts ?? [],
            'completion' => $session->completion,
            'expires_at' => $session->expires_at?->toISOString(),
            'payload' => $session->payload ?? [],
        ];
    }
}
