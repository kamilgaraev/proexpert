<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Models\Organization;
use App\Services\Storage\FileService;
use BackedEnum;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DesignModelViewerPreparationService
{
    public function __construct(
        private readonly DesignStoragePathService $pathService,
        private readonly FileService $fileService,
        private readonly DesignIfcToFragmentsConverterContract $converter,
    ) {
    }

    public function queuePreparation(DesignArtifactVersion $version, int $userId): DesignModelDerivative
    {
        $version->loadMissing('artifact.package');
        $package = $version->artifact?->package;

        if ($package === null) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        $shouldDispatch = false;
        $derivative = DB::transaction(function () use ($version, $userId, &$shouldDispatch): DesignModelDerivative {
            $derivative = DesignModelDerivative::query()
                ->where('version_id', $version->id)
                ->where('viewer_provider', 'thatopen')
                ->where('derivative_format', 'thatopen_frag')
                ->lockForUpdate()
                ->first();

            if ($derivative instanceof DesignModelDerivative) {
                $status = $this->statusValue($derivative->status);

                if ($status === DesignDerivativeStatusEnum::READY->value && DesignViewerConverter::isCurrent($derivative)) {
                    return $derivative;
                }

                if (in_array($status, [
                    DesignDerivativeStatusEnum::QUEUED->value,
                    DesignDerivativeStatusEnum::PROCESSING->value,
                ], true)) {
                    return $derivative;
                }

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
            PrepareDesignModelViewerJob::dispatch((int) $derivative->id)->onQueue(PrepareDesignModelViewerJob::QUEUE);
        }

        return $derivative->fresh(['version']) ?? $derivative;
    }

    public function processQueuedDerivative(int $derivativeId): void
    {
        $derivative = DesignModelDerivative::query()
            ->with('version.artifact.package')
            ->find($derivativeId);

        if (!$derivative instanceof DesignModelDerivative) {
            return;
        }

        $sourcePath = null;
        $targetPath = null;
        $sourceSizeBytes = null;
        $derivativeSizeBytes = null;

        try {
            $version = $derivative->version;
            $package = $version?->artifact?->package;

            if ($version === null || $package === null) {
                throw new DomainException(trans_message('design_management.errors.version_not_found'));
            }

            $this->markProcessing($derivative, 5, 'downloading');

            $sourcePath = $this->temporaryPath($derivativeId, 'ifc');
            $targetPath = $this->temporaryPath($derivativeId, 'frag');

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
            $this->converter->convert($sourcePath, $targetPath, function (mixed $progress, string $stage) use ($derivative): void {
                $this->markProcessing($derivative, $this->normalizeConverterProgress($progress), $stage);
            });
            $derivativeSizeBytes = $this->localFileSize(
                $targetPath,
                'Prepared viewer file is not readable.',
                'Prepared viewer file is empty.'
            );

            $this->markProcessing($derivative, 95, 'uploading');
            $derivativePath = $this->pathService->derivativePath(
                (int) $version->organization_id,
                (int) $version->project_id,
                (int) $package->id,
                (int) $version->id,
                'frag'
            );

            $this->copyPathToStorageFile((int) $version->organization_id, $targetPath, $derivativePath);

            $derivative->forceFill([
                'derivative_file_path' => $derivativePath,
                'status' => DesignDerivativeStatusEnum::READY,
                'progress_percent' => 100,
                'processing_stage' => 'ready',
                'prepared_at' => now(),
                'processing_finished_at' => now(),
                'failed_reason' => null,
                'metadata' => DesignViewerConverter::preparedMetadata($derivative->metadata ?? [], [
                    'source_size_bytes' => $sourceSizeBytes,
                    'derivative_size_bytes' => $derivativeSizeBytes,
                ]),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($derivative, $exception);
        } finally {
            $this->removeTemporaryFile($sourcePath);
            $this->removeTemporaryFile($targetPath);
        }
    }

    public function markJobFailed(int $derivativeId, Throwable $exception): void
    {
        $derivative = DesignModelDerivative::query()->find($derivativeId);

        if ($derivative instanceof DesignModelDerivative) {
            $this->markFailed($derivative, $exception);
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
            'metadata' => DesignViewerConverter::preparedMetadata(),
        ];
    }

    private function copyStorageFileToPath(int $organizationId, string $storagePath, string $localPath): void
    {
        if ($storagePath === '' || $storagePath === 'pending') {
            throw new DomainException(trans_message('design_management.errors.source_file_not_available'));
        }

        $organization = Organization::query()->find($organizationId);
        $source = $this->fileService->disk($organization)->readStream($storagePath);

        if (!is_resource($source)) {
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

        if (!$stored) {
            throw new DomainException(trans_message('design_management.errors.derivative_file_not_available'));
        }
    }

    private function localFileSize(string $path, string $missingMessage, string $emptyMessage): int
    {
        if (!is_file($path)) {
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

        $derivative->forceFill([
            'status' => DesignDerivativeStatusEnum::PROCESSING,
            'progress_percent' => max((int) $derivative->progress_percent, $progressPercent),
            'processing_stage' => $stage,
            'processing_started_at' => $derivative->processing_started_at ?? now(),
            'processing_finished_at' => null,
            'failed_reason' => null,
        ])->save();

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

        $derivative->forceFill([
            'derivative_file_path' => null,
            'status' => DesignDerivativeStatusEnum::FAILED,
            'processing_stage' => 'failed',
            'processing_finished_at' => now(),
            'failed_reason' => trans_message('design_management.errors.viewer_preparation_failed'),
        ])->save();
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

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Temporary directory is not writable.');
        }

        $path = tempnam($directory, "derivative-{$derivativeId}-");

        if ($path === false) {
            throw new RuntimeException('Temporary file is not available.');
        }

        $targetPath = $path . '.' . $extension;
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
