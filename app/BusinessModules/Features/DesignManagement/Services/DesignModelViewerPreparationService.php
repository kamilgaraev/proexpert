<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerFileIntegrity;
use App\Models\Organization;
use App\Services\Storage\FileService;
use BackedEnum;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DesignModelViewerPreparationService
{
    public function __construct(
        private readonly DesignStoragePathService $pathService,
        private readonly FileService $fileService,
        private readonly DesignIfcToFragmentsConverterContract $converter,
        private readonly DesignIfcElementIndexer $elementIndexer,
    ) {}

    public function queuePreparation(DesignArtifactVersion $version, int $userId): DesignModelDerivative
    {
        $version->loadMissing('artifact.package');
        $package = $version->artifact?->package;

        if ($package === null) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        $shouldDispatch = false;
        $derivative = DB::transaction(function () use ($version, $userId, &$shouldDispatch): DesignModelDerivative {
            DesignArtifactVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $derivative = DesignModelDerivative::query()
                ->where('version_id', $version->id)
                ->where('viewer_provider', 'thatopen')
                ->where('derivative_format', 'thatopen_frag')
                ->lockForUpdate()
                ->first();

            if ($derivative instanceof DesignModelDerivative) {
                $status = $this->statusValue($derivative->status);

                if ($status === DesignDerivativeStatusEnum::READY->value
                    && DesignViewerConverter::isCurrent($derivative) && $this->hasOfflinePackage($derivative)) {
                    return $derivative;
                }

                if (in_array($status, [
                    DesignDerivativeStatusEnum::QUEUED->value,
                    DesignDerivativeStatusEnum::PROCESSING->value,
                ], true)) {
                    if (! $this->hasStaleActivity($derivative, $status)) {
                        return $derivative;
                    }
                }

                $lock = Cache::lock($this->processingLockKey((int) $derivative->id), $this->processingLockSeconds());
                if (! $lock->get()) {
                    return $derivative;
                }
                $lock->release();

                $derivative->fill($this->queuedAttributes($version, $userId));
                $derivative->save();
                $shouldDispatch = true;

                return $derivative;
            }

            $shouldDispatch = true;

            return DesignModelDerivative::query()->create(array_merge([
                'version_id' => $version->id,
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
            ], $this->queuedAttributes($version, $userId)));
        });

        if ($shouldDispatch) {
            PrepareDesignModelViewerJob::dispatch((int) $derivative->id, $derivative->metadata['generation'])->onQueue(PrepareDesignModelViewerJob::QUEUE);
        }

        return $derivative->fresh(['version']) ?? $derivative;
    }

    public function processQueuedDerivative(int $derivativeId, ?string $expectedGeneration = null): void
    {
        $lock = Cache::lock($this->processingLockKey($derivativeId), $this->processingLockSeconds());
        if (! $lock->get()) {
            return;
        }

        try {
            $this->processDerivative($derivativeId, $expectedGeneration);
        } finally {
            $lock->release();
        }
    }

    private function processDerivative(int $derivativeId, ?string $expectedGeneration): void
    {
        $derivative = DB::transaction(function () use ($derivativeId, $expectedGeneration): ?DesignModelDerivative {
            $locked = DesignModelDerivative::query()->lockForUpdate()->find($derivativeId);
            if (! $locked instanceof DesignModelDerivative) {
                return null;
            }
            if ($expectedGeneration !== null && ($locked->metadata['generation'] ?? null) !== $expectedGeneration) {
                return null;
            }

            $status = $this->statusValue($locked->status);
            if ($status === DesignDerivativeStatusEnum::READY->value
                && DesignViewerConverter::isCurrent($locked) && $this->hasOfflinePackage($locked)) {
                return null;
            }
            if ($status === DesignDerivativeStatusEnum::PROCESSING->value && ! $this->hasStaleActivity($locked, $status)) {
                return null;
            }

            $locked->forceFill([
                'status' => DesignDerivativeStatusEnum::PROCESSING,
                'processing_stage' => 'starting',
                'processing_started_at' => now(),
                'processing_finished_at' => null,
                'failed_reason' => null,
                'metadata' => array_merge($locked->metadata ?? [], ['generation' => $expectedGeneration ?? (string) Str::uuid()]),
            ])->save();

            return $locked;
        });

        if (! $derivative instanceof DesignModelDerivative) {
            return;
        }
        $generation = $derivative->metadata['generation'];

        $derivative->load('version.artifact.package');

        $sourcePath = null;
        $targetPath = null;
        $indexPath = null;
        $sourceSizeBytes = null;
        $derivativeSizeBytes = null;

        try {
            $version = $derivative->version;
            $package = $version?->artifact?->package;

            if ($version === null || $package === null
                || (int) $version->organization_id !== (int) $derivative->organization_id
                || (int) $version->project_id !== (int) $derivative->project_id
                || (int) $package->organization_id !== (int) $version->organization_id
                || (int) $package->project_id !== (int) $version->project_id) {
                throw new DomainException(trans_message('design_management.errors.version_not_found'));
            }

            $this->markProcessing($derivative, 5, 'downloading');

            $sourcePath = $this->temporaryPath($derivativeId, 'ifc');
            $targetPath = $this->temporaryPath($derivativeId, 'frag');
            $indexPath = $targetPath.'.ifc-index.ndjson';

            $this->copyStorageFileToPath(
                (int) $version->organization_id,
                (string) $version->source_file_path,
                $sourcePath
            );
            $sourceSizeBytes = $this->localFileSize(
                $sourcePath,
                'Temporary IFC file is not readable.',
                'Temporary IFC file is empty.'
            );

            $this->markProcessing($derivative, 15, 'converting');
            $conversionResult = $this->converter->convert($sourcePath, $targetPath, function (mixed $progress, string $stage) use ($derivative): void {
                $this->markProcessing($derivative, $this->normalizeConverterProgress($progress), $stage);
            });
            $derivativeSizeBytes = $this->localFileSize(
                $targetPath,
                'Prepared viewer file is not readable.',
                'Prepared viewer file is empty.'
            );
            $conversionResult->assertRenderableGeometry();
            $conversionResult->assertOfflineRuntime();
            $geometryIntegrity = DesignViewerFileIntegrity::forPath($targetPath);
            $propertiesIntegrity = DesignViewerFileIntegrity::forPath($indexPath);
            $this->elementIndexer->index($version, $derivative, $indexPath, $conversionResult->metadata()['ifc_metadata'] ?? []);

            $this->markProcessing($derivative, 95, 'uploading');
            $derivativePath = $this->pathService->derivativePath(
                (int) $version->organization_id,
                (int) $version->project_id,
                (int) $package->id,
                (int) $version->id,
                'frag'
            );
            $generationDirectory = dirname($derivativePath).'/'.$generation;
            $derivativePath = $generationDirectory.'/model.frag';
            $propertiesPath = $generationDirectory.'/properties.ndjson';

            $this->copyPathToStorageFile((int) $version->organization_id, $targetPath, $derivativePath);
            $this->copyPathToStorageFile((int) $version->organization_id, $indexPath, $propertiesPath);
            $this->assertStoredIntegrity((int) $version->organization_id, $derivativePath, $geometryIntegrity);
            $this->assertStoredIntegrity((int) $version->organization_id, $propertiesPath, $propertiesIntegrity);

            $attributes = [
                'derivative_file_path' => $derivativePath,
                'status' => DesignDerivativeStatusEnum::READY,
                'progress_percent' => 100,
                'processing_stage' => 'ready',
                'prepared_at' => now(),
                'processing_finished_at' => now(),
                'failed_reason' => null,
                'metadata' => DesignViewerConverter::preparedMetadata($derivative->metadata ?? [], array_merge([
                    'source_size_bytes' => $sourceSizeBytes,
                    'derivative_size_bytes' => $derivativeSizeBytes,
                    'offline_package' => [
                        'schema_version' => 1,
                        'generation' => $generation,
                        'geometry' => array_merge($geometryIntegrity, ['path' => $derivativePath, 'mime' => 'application/octet-stream']),
                        'properties' => array_merge($propertiesIntegrity, ['path' => $propertiesPath, 'mime' => 'application/x-ndjson']),
                    ],
                ], $conversionResult->metadata())),
            ];
            DB::transaction(function () use ($derivative, $generation, $attributes): void {
                $locked = DesignModelDerivative::query()->lockForUpdate()->find($derivative->id);
                if (! $locked instanceof DesignModelDerivative || ($locked->metadata['generation'] ?? null) !== $generation) {
                    throw new RuntimeException('BIM preparation generation was superseded.');
                }
                $locked->forceFill($attributes)->save();
            });
        } catch (Throwable $exception) {
            $this->markFailed($derivative, $exception);
        } finally {
            $this->removeTemporaryFile($sourcePath);
            $this->removeTemporaryFile($targetPath);
            $this->removeTemporaryFile($indexPath);
        }
    }

    public function markJobFailed(int $derivativeId, Throwable $exception, ?string $expectedGeneration = null): void
    {
        $lock = Cache::lock($this->processingLockKey($derivativeId), $this->processingLockSeconds());
        if (! $lock->get()) {
            return;
        }
        try {
            $derivative = DesignModelDerivative::query()->find($derivativeId);
            if ($derivative instanceof DesignModelDerivative
                && ($expectedGeneration === null || ($derivative->metadata['generation'] ?? null) === $expectedGeneration)
                && $this->statusValue($derivative->status) !== DesignDerivativeStatusEnum::READY->value) {
                $this->markFailed($derivative, $exception);
            }
        } finally {
            $lock->release();
        }
    }

    private function hasStaleActivity(DesignModelDerivative $derivative, string $status): bool
    {
        $seconds = $status === DesignDerivativeStatusEnum::QUEUED->value
            ? (int) config('design_management.viewer_stale_queued_seconds', 300)
            : (int) config('design_management.viewer_stale_processing_seconds', 7500);
        $activity = $derivative->updated_at ?? $derivative->processing_started_at ?? $derivative->created_at;

        return $activity !== null && $activity->lte(now()->subSeconds(max(1, $seconds)));
    }

    private function processingLockKey(int $derivativeId): string
    {
        return 'design-management:viewer-preparation:'.$derivativeId;
    }

    private function hasOfflinePackage(DesignModelDerivative $derivative): bool
    {
        $metadata = $derivative->metadata ?? [];
        $offline = $metadata['offline_package'] ?? [];

        return ($offline['schema_version'] ?? null) === 1
            && is_string($metadata['generation'] ?? null)
            && Str::isUuid($metadata['generation'])
            && ($offline['generation'] ?? null) === $metadata['generation']
            && ($offline['geometry']['path'] ?? null) === $derivative->derivative_file_path
            && str_ends_with((string) $derivative->derivative_file_path, '/viewer/'.$metadata['generation'].'/model.frag')
            && ($offline['properties']['path'] ?? null) === dirname((string) $derivative->derivative_file_path).'/properties.ndjson'
            && (int) ($offline['geometry']['size'] ?? 0) > 0
            && (int) ($offline['properties']['size'] ?? 0) > 0
            && is_string($metadata['runtime']['fragments'] ?? null)
            && $metadata['runtime']['fragments'] !== '';
    }

    private function processingLockSeconds(): int
    {
        return max(
            (int) config('design_management.viewer_job_timeout', 6900),
            (int) config('design_management.viewer_converter_timeout', 6600),
            (int) config('design_management.viewer_stale_processing_seconds', 7500),
        ) + 300;
    }

    private function assertStoredIntegrity(int $organizationId, string $path, array $expected): void
    {
        $stream = $this->fileService->disk(Organization::query()->find($organizationId))->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Stored BIM file is not readable.');
        }
        try {
            $actual = DesignViewerFileIntegrity::forStream($stream);
        } finally {
            fclose($stream);
        }
        if ($actual['size'] !== $expected['size'] || ! hash_equals($expected['sha256'], $actual['sha256'])) {
            throw new RuntimeException('Stored BIM file failed integrity verification.');
        }
    }

    private function queuedAttributes(DesignArtifactVersion $version, int $userId): array
    {
        return [
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'prepared_by' => $userId,
            'derivative_file_path' => null,
            'status' => DesignDerivativeStatusEnum::QUEUED,
            'progress_percent' => 0,
            'processing_stage' => 'queued',
            'prepared_at' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
            'failed_reason' => null,
            'metadata' => DesignViewerConverter::preparedMetadata([], ['generation' => (string) Str::uuid()]),
        ];
    }

    private function copyStorageFileToPath(int $organizationId, string $storagePath, string $localPath): void
    {
        if (! str_starts_with($storagePath, 'org-'.$organizationId.'/')) {
            throw new DomainException(trans_message('design_management.errors.source_file_not_available'));
        }

        $organization = Organization::query()->find($organizationId);
        $source = $this->fileService->disk($organization)->readStream($storagePath);

        if (! is_resource($source)) {
            throw new DomainException(trans_message('design_management.errors.source_file_not_available'));
        }

        $target = fopen($localPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Temporary IFC file is not writable.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function copyPathToStorageFile(int $organizationId, string $localPath, string $storagePath): void
    {
        $this->localFileSize(
            $localPath,
            'Prepared viewer file is not readable.',
            'Prepared viewer file is empty.'
        );

        $organization = Organization::query()->find($organizationId);
        $source = fopen($localPath, 'rb');

        if ($source === false) {
            throw new RuntimeException('Prepared viewer file is not readable.');
        }

        try {
            $stored = $this->fileService->disk($organization)->put($storagePath, $source, 'private');
        } finally {
            fclose($source);
        }

        if (! $stored) {
            throw new DomainException(trans_message('design_management.errors.derivative_file_not_available'));
        }
    }

    private function localFileSize(string $path, string $missingMessage, string $emptyMessage): int
    {
        if (! is_file($path)) {
            throw new RuntimeException($missingMessage);
        }

        $size = filesize($path);

        if ($size === false || $size <= 0) {
            throw new RuntimeException($emptyMessage);
        }

        return (int) $size;
    }

    private function markProcessing(DesignModelDerivative $derivative, int $progressPercent, string $stage): void
    {
        $progressPercent = max(0, min(99, $progressPercent));

        $attributes = [
            'status' => DesignDerivativeStatusEnum::PROCESSING->value,
            'progress_percent' => max((int) $derivative->progress_percent, $progressPercent),
            'processing_stage' => $stage,
            'processing_started_at' => $derivative->processing_started_at ?? now(),
            'processing_finished_at' => null,
            'failed_reason' => null,
        ];
        $updated = DesignModelDerivative::query()->whereKey($derivative->id)
            ->where('metadata->generation', $derivative->metadata['generation'] ?? null)
            ->update($attributes);
        if ($updated !== 1) {
            throw new RuntimeException('BIM preparation generation was superseded.');
        }

        $derivative->refresh();
    }

    private function markFailed(DesignModelDerivative $derivative, Throwable $exception): void
    {
        Log::error('design_management.viewer_preparation.failed', [
            'derivative_id' => $derivative->id,
            'version_id' => $derivative->version_id,
            'organization_id' => $derivative->organization_id,
            'error' => $exception->getMessage(),
        ]);

        $query = DesignModelDerivative::query()->whereKey($derivative->id);
        if (isset($derivative->metadata['generation'])) {
            $query->where('metadata->generation', $derivative->metadata['generation']);
        }
        $query->update([
            'derivative_file_path' => null,
            'status' => DesignDerivativeStatusEnum::FAILED->value,
            'processing_stage' => 'failed',
            'processing_finished_at' => now(),
            'failed_reason' => trans_message('design_management.errors.viewer_preparation_failed'),
        ]);
    }

    private function normalizeConverterProgress(mixed $progress): int
    {
        $value = is_numeric($progress) ? (float) $progress : 0.0;

        if ($value <= 1.0) {
            $value *= 100.0;
        }

        return max(15, min(90, (int) round($value)));
    }

    private function temporaryPath(int $derivativeId, string $extension): string
    {
        $directory = storage_path('app/design-management/viewer');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Temporary directory is not writable.');
        }

        $path = tempnam($directory, "derivative-{$derivativeId}-");

        if ($path === false) {
            throw new RuntimeException('Temporary file is not available.');
        }

        $targetPath = $path.'.'.$extension;
        rename($path, $targetPath);

        return $targetPath;
    }

    private function removeTemporaryFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof BackedEnum ? $status->value : (string) $status;
    }
}
