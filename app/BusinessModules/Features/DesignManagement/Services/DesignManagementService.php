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
        'artifact.package',
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
            $artifact = $this->resolveArtifact($package, $userId, $payload);

            $version = $artifact->versions()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
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
                (int) $package->organization_id,
                (int) $package->project_id,
                (int) $package->id,
                (int) $version->id,
                $file->getClientOriginalName()
            );

            $this->storeUploadedFile($file, $path, (int) $package->organization_id);
            $version->update(['source_file_path' => $path]);

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
            $format = (string) ($payload['derivative_format'] ?? 'thatopen_frag');
            $provider = (string) ($payload['viewer_provider'] ?? 'thatopen');

            if ($format !== 'thatopen_frag' || $provider !== 'thatopen') {
                throw new DomainException(trans_message('design_management.errors.derivative_format_not_supported'));
            }

            $path = $this->pathService->derivativePath(
                (int) $version->organization_id,
                (int) $version->project_id,
                (int) $package->id,
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
                    'updated_by' => $userId,
                    'prepared_by' => $userId,
                    'derivative_file_path' => null,
                    'status' => DesignDerivativeStatusEnum::PROCESSING,
                    'failed_reason' => null,
                    'metadata' => $payload['metadata'] ?? [],
                ]
            );

            try {
                $this->storeUploadedFile($file, $path, (int) $version->organization_id);
            } catch (DomainException $exception) {
                $derivative->update([
                    'status' => DesignDerivativeStatusEnum::FAILED,
                    'failed_reason' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $derivative->update([
                'derivative_file_path' => $path,
                'status' => DesignDerivativeStatusEnum::READY,
                'prepared_at' => now(),
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
                'path' => $version->source_file_path,
                'mime_type' => $version->source_mime_type,
                'size_bytes' => $version->source_size_bytes,
                'original_name' => $version->source_original_name,
            ],
            'derivative' => $this->derivativePayload($derivative, $organization),
        ];
    }

    public function markCurrent(DesignArtifactVersion $version, int $userId): DesignArtifactVersion
    {
        return DB::transaction(function () use ($version, $userId): DesignArtifactVersion {
            $this->setCurrentVersion($version, $userId);

            return $version->fresh(self::VERSION_RELATIONS);
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
        $contents = $this->readUploadedFile($file);

        $stored = $this->fileService->disk($organization)->put($path, $contents, 'private');

        if (!$stored) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }
    }

    private function readUploadedFile(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if (!$realPath || !is_file($realPath)) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        $contents = file_get_contents($realPath);

        if ($contents === false) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        return $contents;
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

    private function assertIfcFile(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'ifc') {
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
                'metadata' => [],
            ];
        }

        return [
            'id' => $derivative->id,
            'status' => $this->enumValue($derivative->status),
            'viewer_provider' => $derivative->viewer_provider,
            'derivative_format' => $derivative->derivative_format,
            'download_url' => $this->fileService->temporaryUrl($derivative->derivative_file_path, 60, $organization),
            'metadata' => $derivative->metadata ?? [],
            'failed_reason' => $derivative->failed_reason,
            'prepared_at' => optional($derivative->prepared_at)?->toISOString(),
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
