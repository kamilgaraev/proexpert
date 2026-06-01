<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignVersionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use BackedEnum;

final class DesignManagementService
{
    private const PACKAGE_RELATIONS = [
        'project:id,name,organization_id',
        'artifacts.currentVersion.readyDerivative',
        'artifacts.versions.derivatives',
    ];

    private const VERSION_RELATIONS = [
        'artifact.project:id,name,organization_id',
        'artifact.package.project:id,name,organization_id',
        'derivatives',
        'readyDerivative',
    ];

    public function __construct(
        private readonly DesignStoragePathService $pathService,
        private readonly FileService $fileService,
    ) {
    }

    public function listPackages(int $organizationId, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return DesignPackage::forOrganization($organizationId)
            ->with(self::PACKAGE_RELATIONS)
            ->when(!empty($filters['project_id']), static fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), static fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['discipline']), static fn ($query) => $query->where('discipline', (string) $filters['discipline']))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function createPackage(int $organizationId, int $userId, array $payload): DesignPackage
    {
        $this->assertProjectBelongsToOrganization((int) $payload['project_id'], $organizationId);

        $package = DesignPackage::query()->create([
            'organization_id' => $organizationId,
            'project_id' => (int) $payload['project_id'],
            'created_by' => $userId,
            'updated_by' => $userId,
            'title' => $payload['title'],
            'stage' => $payload['stage'] ?? null,
            'discipline' => $payload['discipline'] ?? null,
            'status' => $payload['status'] ?? DesignPackageStatusEnum::DRAFT,
            'planned_issue_date' => $payload['planned_issue_date'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
        ]);

        return $package->fresh(self::PACKAGE_RELATIONS);
    }

    public function findPackage(int $organizationId, int $packageId): ?DesignPackage
    {
        return DesignPackage::forOrganization($organizationId)
            ->with(self::PACKAGE_RELATIONS)
            ->find($packageId);
    }

    public function findVersion(int $organizationId, int $versionId): ?DesignArtifactVersion
    {
        return DesignArtifactVersion::forOrganization($organizationId)
            ->with(self::VERSION_RELATIONS)
            ->find($versionId);
    }

    public function uploadIfcModel(
        DesignPackage $package,
        int $userId,
        UploadedFile $file,
        array $payload
    ): DesignArtifactVersion {
        $this->assertPackageBelongsToOrganization($package, (int) $package->organization_id);
        $this->assertIfcFile($file);

        return DB::transaction(function () use ($package, $userId, $file, $payload): DesignArtifactVersion {
            $lockedPackage = $this->lockedPackageForModelChanges($package);
            $artifact = $this->resolveArtifact($lockedPackage, $userId, $payload);

            $version = $artifact->versions()->create([
                'organization_id' => $lockedPackage->organization_id,
                'project_id' => $lockedPackage->project_id,
                'created_by' => $userId,
                'updated_by' => $userId,
                'uploaded_by' => $userId,
                'title' => $payload['title'] ?? $artifact->title,
                'version_number' => (string) $payload['version_number'],
                'revision' => $payload['revision'] ?? null,
                'source_format' => 'ifc',
                'source_file_path' => 'pending',
                'source_original_name' => $file->getClientOriginalName(),
                'source_mime_type' => $file->getClientMimeType() ?: 'application/x-step',
                'source_size_bytes' => (int) $file->getSize(),
                'model_date' => $payload['model_date'] ?? null,
                'status' => DesignVersionStatusEnum::UPLOADED,
                'is_current' => false,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $path = $this->pathService->sourcePath(
                (int) $lockedPackage->organization_id,
                (int) $lockedPackage->project_id,
                (int) $lockedPackage->id,
                (int) $version->id,
                $file->getClientOriginalName()
            );

            $this->storeUploadedFile($file, $path, (int) $lockedPackage->organization_id);
            $version->update(['source_file_path' => $path]);

            if ((bool) ($payload['make_current'] ?? true)) {
                $this->setCurrentVersion($version, $userId);
            }

            return $version->fresh(self::VERSION_RELATIONS);
        });
    }

    public function registerStoredIfcModel(
        DesignPackage $package,
        int $userId,
        string $sourcePath,
        array $fileInfo,
        array $payload
    ): DesignArtifactVersion {
        $this->assertPackageBelongsToOrganization($package, (int) $package->organization_id);
        $this->assertIfcOriginalName((string) $fileInfo['original_name']);

        return DB::transaction(function () use ($package, $userId, $sourcePath, $fileInfo, $payload): DesignArtifactVersion {
            $lockedPackage = $this->lockedPackageForModelChanges($package);
            $artifact = $this->resolveArtifact($lockedPackage, $userId, $payload);

            $version = $artifact->versions()->create([
                'organization_id' => $lockedPackage->organization_id,
                'project_id' => $lockedPackage->project_id,
                'created_by' => $userId,
                'updated_by' => $userId,
                'uploaded_by' => $userId,
                'title' => $payload['title'] ?? $artifact->title,
                'version_number' => (string) $payload['version_number'],
                'revision' => $payload['revision'] ?? null,
                'source_format' => 'ifc',
                'source_file_path' => $sourcePath,
                'source_original_name' => (string) $fileInfo['original_name'],
                'source_mime_type' => (string) ($fileInfo['mime_type'] ?? 'application/x-step'),
                'source_size_bytes' => (int) $fileInfo['size_bytes'],
                'model_date' => $payload['model_date'] ?? null,
                'status' => DesignVersionStatusEnum::UPLOADED,
                'is_current' => false,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            if ((bool) ($payload['make_current'] ?? true)) {
                $this->setCurrentVersion($version, $userId);
            }

            return $version->fresh(self::VERSION_RELATIONS);
        });
    }

    public function storeDerivative(
        DesignArtifactVersion $version,
        int $userId,
        UploadedFile $file,
        array $payload
    ): DesignModelDerivative {
        $this->assertFragFile($file);
        $version->loadMissing('artifact.package');

        $artifact = $version->artifact;
        $package = $artifact?->package;

        if ($artifact === null || $package === null) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        return DB::transaction(function () use ($version, $userId, $file, $payload, $package): DesignModelDerivative {
            $lockedPackage = $this->lockedPackageForModelChanges($package);
            $format = (string) ($payload['derivative_format'] ?? 'thatopen_frag');
            $provider = (string) ($payload['viewer_provider'] ?? 'thatopen');

            if ($format !== 'thatopen_frag' || $provider !== 'thatopen') {
                throw new DomainException(trans_message('design_management.errors.derivative_format_not_supported'));
            }

            $path = $this->pathService->derivativePath(
                (int) $version->organization_id,
                (int) $version->project_id,
                (int) $lockedPackage->id,
                (int) $version->id,
                $file->getClientOriginalExtension() ?: 'frag'
            );

            $derivative = DesignModelDerivative::query()->updateOrCreate(
                [
                    'version_id' => $version->id,
                    'viewer_provider' => $provider,
                    'derivative_format' => $format,
                ],
                [
                    'organization_id' => $version->organization_id,
                    'project_id' => $version->project_id,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'prepared_by' => $userId,
                    'derivative_file_path' => null,
                    'status' => DesignDerivativeStatusEnum::PROCESSING,
                    'progress_percent' => 0,
                    'processing_stage' => 'uploading',
                    'processing_started_at' => now(),
                    'processing_finished_at' => null,
                    'failed_reason' => null,
                    'metadata' => DesignViewerConverter::preparedMetadata($payload['metadata'] ?? []),
                ]
            );

            try {
                $this->storeUploadedFile($file, $path, (int) $version->organization_id);
            } catch (DomainException $exception) {
                $derivative->update([
                    'status' => DesignDerivativeStatusEnum::FAILED,
                    'processing_stage' => 'failed',
                    'processing_finished_at' => now(),
                    'failed_reason' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $derivative->update([
                'derivative_file_path' => $path,
                'status' => DesignDerivativeStatusEnum::READY,
                'progress_percent' => 100,
                'processing_stage' => 'ready',
                'prepared_at' => now(),
                'processing_finished_at' => now(),
                'metadata' => DesignViewerConverter::preparedMetadata($derivative->metadata ?? []),
            ]);

            return $derivative->fresh(['version']);
        });
    }

    public function viewerPayload(DesignArtifactVersion $version): array
    {
        $version->loadMissing(self::VERSION_RELATIONS);

        $organization = Organization::query()->find((int) $version->organization_id);
        $derivative = $version->derivatives
            ->first(static fn (DesignModelDerivative $item): bool => $item->viewer_provider === 'thatopen'
                && $item->derivative_format === 'thatopen_frag');

        return [
            'version' => [
                'id' => $version->id,
                'artifact_id' => $version->artifact_id,
                'title' => $version->title,
                'source_format' => $version->source_format,
                'version_number' => $version->version_number,
                'revision' => $version->revision,
                'status' => $this->enumValue($version->status),
                'metadata' => $version->metadata ?? [],
            ],
            'source' => [
                'download_url' => $this->fileService->temporaryUrl($version->source_file_path, 60, $organization),
                'mime_type' => $version->source_mime_type,
                'size_bytes' => $version->source_size_bytes,
                'original_name' => $version->source_original_name,
            ],
            'derivative' => $this->derivativePayload($derivative, $organization),
        ];
    }

    /**
     * @return array{stream: resource, filename: string, mime_type: string}
     */
    public function sourceFileStream(DesignArtifactVersion $version): array
    {
        return $this->fileStream(
            (int) $version->organization_id,
            (string) $version->source_file_path,
            $version->source_original_name ?: 'model.ifc',
            $version->source_mime_type ?: 'application/x-step',
            trans_message('design_management.errors.source_file_not_available')
        );
    }

    /**
     * @return array{stream: resource, filename: string, mime_type: string}
     */
    public function derivativeFileStream(DesignArtifactVersion $version): array
    {
        $version->loadMissing('derivatives');

        $derivative = $version->derivatives
            ->first(static function (DesignModelDerivative $item): bool {
                $status = $item->status instanceof DesignDerivativeStatusEnum
                    ? $item->status->value
                    : (string) $item->status;

                return $item->viewer_provider === 'thatopen'
                    && $item->derivative_format === 'thatopen_frag'
                    && $status === DesignDerivativeStatusEnum::READY->value
                    && DesignViewerConverter::isCurrent($item);
            });

        if (!$derivative instanceof DesignModelDerivative || empty($derivative->derivative_file_path)) {
            throw new DomainException(trans_message('design_management.errors.derivative_file_not_available'));
        }

        return $this->fileStream(
            (int) $version->organization_id,
            (string) $derivative->derivative_file_path,
            pathinfo((string) $derivative->derivative_file_path, PATHINFO_BASENAME) ?: 'model.frag',
            'application/octet-stream',
            trans_message('design_management.errors.derivative_file_not_available')
        );
    }

    public function markCurrent(DesignArtifactVersion $version, int $userId): DesignArtifactVersion
    {
        return DB::transaction(function () use ($version, $userId): DesignArtifactVersion {
            $version->loadMissing('artifact.package');
            $package = $version->artifact?->package;

            if (!$package instanceof DesignPackage) {
                throw new DomainException(trans_message('design_management.errors.version_not_found'));
            }

            $this->lockedPackageForModelChanges($package);
            $this->setCurrentVersion($version, $userId);

            return $version->fresh(self::VERSION_RELATIONS);
        });
    }

    public function ensurePackageAcceptsModelChanges(DesignPackage $package): void
    {
        $this->assertPackageAcceptsModelChanges($package);
    }

    public function ensureVersionPackageAcceptsModelChanges(DesignArtifactVersion $version): void
    {
        $version->loadMissing('artifact.package');
        $package = $version->artifact?->package;

        if (!$package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        $this->assertPackageAcceptsModelChanges($package);
    }

    public function transitionPackageWorkflow(
        DesignPackage $package,
        int $userId,
        string $action,
        ?string $comment = null
    ): DesignPackage {
        return DB::transaction(function () use ($package, $userId, $action, $comment): DesignPackage {
            $lockedPackage = DesignPackage::forOrganization((int) $package->organization_id)
                ->whereKey($package->id)
                ->lockForUpdate()
                ->with(self::PACKAGE_RELATIONS)
                ->first();

            if (!$lockedPackage instanceof DesignPackage) {
                throw new DomainException(trans_message('design_management.errors.package_not_found'));
            }

            if (DesignPackageWorkflow::isCompletedAction($lockedPackage, $action)) {
                return $lockedPackage->fresh(self::PACKAGE_RELATIONS);
            }

            $nextStatus = DesignPackageWorkflow::nextStatus($lockedPackage, $action);

            if ($nextStatus === null) {
                throw new DomainException(trans_message('design_management.errors.workflow_action_not_available'));
            }

            $metadata = $lockedPackage->metadata ?? [];
            $history = is_array($metadata['workflow_history'] ?? null)
                ? array_values($metadata['workflow_history'])
                : [];

            $history[] = [
                'action' => $action,
                'from_status' => $this->enumValue($lockedPackage->status),
                'to_status' => $nextStatus->value,
                'user_id' => $userId,
                'comment' => $comment,
                'at' => now()->toISOString(),
            ];

            $metadata['workflow_history'] = array_slice($history, -50);

            $lockedPackage->update([
                'status' => $nextStatus,
                'updated_by' => $userId,
                'metadata' => $metadata,
            ]);

            return $lockedPackage->fresh(self::PACKAGE_RELATIONS);
        });
    }

    private function resolveArtifact(DesignPackage $package, int $userId, array $payload): DesignArtifact
    {
        if (!empty($payload['artifact_id'])) {
            $artifact = DesignArtifact::forOrganization((int) $package->organization_id)
                ->where('package_id', $package->id)
                ->find((int) $payload['artifact_id']);

            if ($artifact instanceof DesignArtifact) {
                return $artifact;
            }
        }

        return DesignArtifact::query()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'package_id' => $package->id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'artifact_type' => DesignArtifactTypeEnum::MODEL,
            'title' => $payload['title'],
            'discipline' => $payload['discipline'] ?? $package->discipline,
            'stage' => $payload['stage'] ?? $package->stage,
            'status' => 'active',
            'metadata' => $payload['artifact_metadata'] ?? [],
        ]);
    }

    private function setCurrentVersion(DesignArtifactVersion $version, int $userId): void
    {
        DesignArtifactVersion::query()
            ->where('artifact_id', $version->artifact_id)
            ->whereKeyNot($version->id)
            ->update([
                'is_current' => false,
                'status' => DesignVersionStatusEnum::SUPERSEDED->value,
                'updated_by' => $userId,
            ]);

        $version->update([
            'is_current' => true,
            'status' => DesignVersionStatusEnum::CURRENT,
            'updated_by' => $userId,
        ]);
    }

    private function storeUploadedFile(UploadedFile $file, string $path, int $organizationId): void
    {
        $organization = Organization::query()->find($organizationId);
        $stream = $this->readUploadedFileStream($file);

        try {
            $stored = $this->fileService->disk($organization)->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }

        if (!$stored) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }
    }

    private function readUploadedFileStream(UploadedFile $file): mixed
    {
        $realPath = $file->getRealPath();

        if (!$realPath || !is_file($realPath)) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        return $stream;
    }

    /**
     * @return array{stream: resource, filename: string, mime_type: string}
     */
    private function fileStream(
        int $organizationId,
        string $path,
        string $filename,
        string $mimeType,
        string $errorMessage
    ): array {
        if ($path === '' || $path === 'pending') {
            throw new DomainException($errorMessage);
        }

        $organization = Organization::query()->find($organizationId);
        $stream = $this->fileService->disk($organization)->readStream($path);

        if (!is_resource($stream)) {
            throw new DomainException($errorMessage);
        }

        return [
            'stream' => $stream,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ];
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('design_management.errors.project_not_found'));
        }
    }

    private function assertPackageBelongsToOrganization(DesignPackage $package, int $organizationId): void
    {
        if ((int) $package->organization_id !== $organizationId) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }
    }

    private function lockedPackageForModelChanges(DesignPackage $package): DesignPackage
    {
        $lockedPackage = DesignPackage::forOrganization((int) $package->organization_id)
            ->whereKey($package->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedPackage instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }

        $this->assertPackageAcceptsModelChanges($lockedPackage);

        return $lockedPackage;
    }

    private function assertPackageAcceptsModelChanges(DesignPackage $package): void
    {
        if (!DesignPackageWorkflow::canChangeModels($package)) {
            throw new DomainException(trans_message('design_management.errors.package_locked_for_model_changes'));
        }
    }

    private function assertIfcFile(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'ifc') {
            throw new DomainException(trans_message('design_management.errors.ifc_file_required'));
        }
    }

    private function assertIfcOriginalName(string $originalName): void
    {
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'ifc') {
            throw new DomainException(trans_message('design_management.errors.ifc_file_required'));
        }
    }

    private function assertFragFile(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'frag') {
            throw new DomainException(trans_message('design_management.errors.frag_file_required'));
        }
    }

    private function derivativePayload(?DesignModelDerivative $derivative, ?Organization $organization): array
    {
        if (!$derivative instanceof DesignModelDerivative) {
            return [
                'id' => null,
                'status' => DesignDerivativeStatusEnum::MISSING->value,
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
                'download_url' => null,
                'progress_percent' => 0,
                'processing_stage' => null,
                'metadata' => [],
            ];
        }

        if (DesignViewerConverter::isStale($derivative)) {
            return [
                'id' => $derivative->id,
                'status' => DesignDerivativeStatusEnum::MISSING->value,
                'viewer_provider' => $derivative->viewer_provider,
                'derivative_format' => $derivative->derivative_format,
                'download_url' => null,
                'progress_percent' => 0,
                'processing_stage' => 'stale',
                'metadata' => DesignViewerConverter::staleMetadata($derivative->metadata ?? []),
                'failed_reason' => null,
                'prepared_at' => optional($derivative->prepared_at)?->toISOString(),
                'processing_started_at' => optional($derivative->processing_started_at)?->toISOString(),
                'processing_finished_at' => optional($derivative->processing_finished_at)?->toISOString(),
            ];
        }

        return [
            'id' => $derivative->id,
            'status' => $this->enumValue($derivative->status),
            'viewer_provider' => $derivative->viewer_provider,
            'derivative_format' => $derivative->derivative_format,
            'download_url' => $this->fileService->temporaryUrl($derivative->derivative_file_path, 60, $organization),
            'progress_percent' => (int) $derivative->progress_percent,
            'processing_stage' => $derivative->processing_stage,
            'metadata' => $derivative->metadata ?? [],
            'failed_reason' => $derivative->failed_reason,
            'prepared_at' => optional($derivative->prepared_at)?->toISOString(),
            'processing_started_at' => optional($derivative->processing_started_at)?->toISOString(),
            'processing_finished_at' => optional($derivative->processing_finished_at)?->toISOString(),
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
