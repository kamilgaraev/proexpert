<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignVersionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignNormativeCatalog;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Models\Organization;
use App\Models\User;
use App\Models\Project;
use App\Services\Storage\FileService;
use BackedEnum;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DesignManagementService implements Contracts\DesignModelRegistrationService
{
    private static function packageRelations(): array
    {
        return [
            'project:id,name,organization_id',
            'artifacts.currentVersion.readyDerivative' => static fn ($query) => $query->forResponse(),
            'artifacts.currentVersion.derivatives' => static fn ($query) => $query->forResponse(),
            'artifacts.section',
            'artifacts.currentVersion.sheets',
            'artifacts.versions.derivatives' => static fn ($query) => $query->forResponse(),
            'artifacts.versions.sheets',
            'sections.artifacts.currentVersion.sheets',
            'sections.artifacts.versions.sheets',
            'reviewComments',
            'workflowEvents',
            'latestCompletenessCheck',
        ];
    }

    private static function versionRelations(): array
    {
        return [
            'artifact.project:id,name,organization_id',
            'artifact.package.project:id,name,organization_id',
            'artifact.section',
            'sheets',
            'derivatives' => static fn ($query) => $query->forResponse(),
            'readyDerivative' => static fn ($query) => $query->forResponse(),
        ];
    }

    public function __construct(
        private readonly DesignStoragePathService $pathService,
        private readonly FileService $fileService,
        private readonly DesignSectionGenerationService $sectionGenerationService,
        private readonly DesignAccessService $access,
        private readonly DesignCompositionService $composition,
        private readonly DesignCompletenessService $completeness,
    ) {}

    public function listPackages(int $organizationId, array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $actor = $this->access->requireActor($actor);
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return $this->packageQuery($organizationId, $filters, $actor)
            ->withOpenBlockingCount()
            ->with(self::packageRelations())
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function packageSummary(int $organizationId, array $filters, ?User $actor = null): array
    {
        $query = $this->packageQuery($organizationId, $filters, $this->access->requireActor($actor));
        $packageIds = (clone $query)->select('design_packages.id');
        $artifacts = DesignArtifact::query()->forOrganization($organizationId)->whereIn('package_id', $packageIds)
            ->whereRaw("COALESCE(design_artifacts.metadata->>'composition_active', 'true') != 'false'")
            ->where(static fn ($scope) => $scope->whereNull('section_id')->orWhereHas('section', static fn ($section) => $section
                ->whereRaw("COALESCE(design_package_sections.metadata->>'composition_active', 'true') != 'false'")));
        $documents = (clone $artifacts)->where('artifact_type', '!=', DesignArtifactTypeEnum::MODEL->value);
        $models = (clone $artifacts)->where('artifact_type', DesignArtifactTypeEnum::MODEL->value);
        $blockingPackages = (clone $query)->withOpenBlockingCount()->get(['design_packages.id']);
        $sections = DesignPackageSection::query()->where('organization_id', $organizationId)->whereIn('package_id', $packageIds)
            ->whereRaw("COALESCE(design_package_sections.metadata->>'composition_active', 'true') != 'false'")
            ->orderBy('code')->get(['code', 'title'])->unique('code')->values()->all();

        return [
            'total' => (clone $query)->count(),
            'packages_count' => (clone $query)->count(),
            'documents_count' => (clone $documents)->count(),
            'uploaded_documents_count' => (clone $documents)->whereHas('currentVersion')->count(),
            'models_count' => (clone $models)->count(),
            'ready_models_count' => (clone $models)->whereHas('currentVersion.readyDerivative', static fn ($derivative) => $derivative
                ->whereRaw("COALESCE(metadata->>'converter_version', '0')::integer >= ?", [DesignViewerConverter::version()]))->count(),
            'blocking_issue_occurrences_count' => $blockingPackages->sum('open_blocking_comments_count'),
            'blocked_packages_count' => $blockingPackages->where('open_blocking_comments_count', '>', 0)->count(),
            'overdue_count' => (clone $query)->whereDate('planned_issue_date', '<', now()->toDateString())
                ->whereNotIn('status', ['approved', 'issued', 'archived'])->count(),
            'section_options' => $sections,
        ];
    }

    private function packageQuery(int $organizationId, array $filters, User $actor): Builder
    {
        return DesignPackage::forOrganization($organizationId)
            ->whereIn('project_id', $this->access->accessibleProjectIds($actor, $organizationId, 'design-management.view'))
            ->when(! empty($filters['project_id']), static fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(! empty($filters['status']), static fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(! empty($filters['discipline']), static fn ($query) => $query->where('discipline', (string) $filters['discipline']))
            ->when(! empty($filters['project_stage']), static fn ($query) => $query->where('project_stage', (string) $filters['project_stage']))
            ->when(! empty($filters['object_type']), static fn ($query) => $query->where('object_type', (string) $filters['object_type']))
            ->when(! empty($filters['normative_profile_code']), static fn ($query) => $query->where('normative_profile_code', (string) $filters['normative_profile_code']))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', static fn ($query) => $query->where('title', 'ilike', '%' . trim((string) $filters['search']) . '%'))
            ->when(! empty($filters['section_code']), static fn ($query) => $query->whereHas('sections', static fn ($sections) => $sections
                ->where('code', (string) $filters['section_code'])
                ->whereRaw("COALESCE(design_package_sections.metadata->>'composition_active', 'true') != 'false'")))
            ->when(! empty($filters['viewer_status']), fn ($query) => $query->whereRaw($this->viewerStatusExpression() . ' = ?', [(string) $filters['viewer_status']]))
            ->when(! empty($filters['readiness_status']), function ($query) use ($filters): void {
                $ready = static function ($checks) use ($filters): void {
                    $checks->whereRaw("design_completeness_checks.metadata->>'composition_revision_id' IS NOT DISTINCT FROM design_packages.composition_revision_id::text")
                        ->whereRaw("design_completeness_checks.metadata->>'completeness_invalidated_at' IS NOT DISTINCT FROM design_packages.metadata->>'completeness_invalidated_at'");
                    if ($filters['readiness_status'] !== 'not_checked') {
                        $checks->where('status', (string) $filters['readiness_status']);
                    }
                };
                if ($filters['readiness_status'] === 'not_checked') {
                    $query->whereDoesntHave('latestCompletenessCheck', $ready);
                } else {
                    $query->whereHas('latestCompletenessCheck', $ready);
                }
            });
    }

    private function viewerStatusExpression(): string
    {
        $requiredConverter = DesignViewerConverter::version();

        return <<<SQL
            COALESCE((
                SELECT CASE
                    WHEN derivative.status = 'ready' AND
                        (CASE WHEN COALESCE(derivative.metadata->>'converter_version', '') ~ '^[0-9]+$'
                            THEN (derivative.metadata->>'converter_version')::numeric ELSE 0 END) < {$requiredConverter}
                    THEN 'missing' ELSE derivative.status END
                FROM design_model_derivatives AS derivative
                WHERE derivative.organization_id = design_packages.organization_id
                    AND derivative.project_id = design_packages.project_id
                    AND derivative.viewer_provider = 'thatopen'
                    AND derivative.derivative_format = 'thatopen_frag'
                    AND derivative.version_id = (
                        SELECT version.id
                        FROM design_artifact_versions AS version
                        JOIN design_artifacts AS artifact ON artifact.id = version.artifact_id
                        LEFT JOIN design_package_sections AS section ON section.id = artifact.section_id
                        WHERE artifact.package_id = design_packages.id
                            AND artifact.organization_id = design_packages.organization_id
                            AND artifact.project_id = design_packages.project_id
                            AND version.organization_id = design_packages.organization_id
                            AND version.project_id = design_packages.project_id
                            AND artifact.artifact_type = 'model'
                            AND COALESCE(artifact.metadata->>'composition_active', 'true') != 'false'
                            AND (artifact.section_id IS NULL OR
                                (section.package_id = design_packages.id
                                    AND section.organization_id = design_packages.organization_id
                                    AND section.project_id = design_packages.project_id
                                    AND COALESCE(section.metadata->>'composition_active', 'true') != 'false'))
                        ORDER BY version.is_current DESC, artifact.id DESC, version.id DESC
                        LIMIT 1
                    )
                ORDER BY (derivative.status = 'ready') DESC, derivative.id DESC
                LIMIT 1
            ), 'missing')
            SQL;
    }

    public function updatePackage(DesignPackage $package, int $userId, array $payload): DesignPackage
    {
        $actor = User::query()->findOrFail($userId);

        return DB::transaction(function () use ($package, $actor, $payload): DesignPackage {
            $package = DesignPackage::forOrganization((int) $package->organization_id)->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $this->access->assertPackage($package, $actor, 'design-management.edit');
            if (! DesignPackageWorkflow::canChangeDocuments($package)) {
                throw new DomainException(trans_message('design_management.errors.package_locked_for_document_changes'));
            }
            if (! $package->updated_at->equalTo(\Illuminate\Support\Carbon::parse($payload['expected_updated_at']))) {
                throw new DomainException(trans_message('design_bim.errors.revision_conflict'));
            }
            $package->update(array_intersect_key($payload, array_flip(['title', 'planned_issue_date'])) + ['updated_by' => $actor->id]);

            return $package->fresh(self::packageRelations());
        });
    }

    public function createPackage(int $organizationId, int $userId, array $payload): DesignPackage
    {
        $actor = User::query()->findOrFail($userId);
        if (! $this->access->canAccessProject($actor, $organizationId, (int) $payload['project_id'], 'design-management.create')) {
            throw new DomainException(trans_message('design_management.errors.project_not_found'));
        }
        if ($this->enumValue($payload['status'] ?? DesignPackageStatusEnum::DRAFT) !== DesignPackageStatusEnum::DRAFT->value) {
            throw new DomainException(trans_message('design_management.errors.workflow_action_not_available'));
        }
        $this->assertProjectBelongsToOrganization((int) $payload['project_id'], $organizationId);
        $projectStage = $this->normalizeProjectStage($payload);
        $objectType = $this->normalizeObjectType($payload);
        $profileCode = is_string($payload['normative_profile_code'] ?? null) && trim((string) $payload['normative_profile_code']) !== ''
            ? trim((string) $payload['normative_profile_code'])
            : $this->defaultProfileCode($projectStage, $objectType);

        $payload['project_stage'] = $projectStage;
        $payload['composition'] ??= null;
        $payload['composition'] = $this->composition->preview($organizationId, $actor, $payload);
        $package = DB::transaction(function () use ($organizationId, $userId, $actor, $payload, $projectStage, $objectType, $profileCode): DesignPackage {
            $package = DesignPackage::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $payload['project_id'],
                'created_by' => $userId,
                'updated_by' => $userId,
                'title' => $payload['title'],
                'stage' => $payload['stage'] ?? strtoupper($projectStage),
                'project_stage' => $projectStage,
                'object_type' => $objectType,
                'normative_profile_code' => $profileCode,
                'discipline' => $payload['discipline'] ?? null,
                'status' => DesignPackageStatusEnum::DRAFT,
                'planned_issue_date' => $payload['planned_issue_date'] ?? null,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $this->composition->createRevision($package, $actor, $payload);

            return $package;
        });

        return DesignPackage::forOrganization($organizationId)->withOpenBlockingCount()->with(self::packageRelations())->findOrFail($package->id);
    }

    public function findPackage(int $organizationId, int $packageId, User|int|null $actor = null, string $permission = 'design-management.view'): ?DesignPackage
    {
        $actor = $this->access->requireActor($actor);
        $package = DesignPackage::forOrganization($organizationId)
            ->withOpenBlockingCount()
            ->find($packageId);
        if ($package === null) {
            return null;
        }
        try {
            $this->access->assertPackage($package, $actor, $permission);
        } catch (DomainException) {
            return null;
        }

        return $package->load(self::packageRelations());
    }

    public function findPackageForComposition(int $organizationId, int $packageId, ?User $actor = null): ?DesignPackage
    {
        $actor = $this->access->requireActor($actor);
        $package = DesignPackage::forOrganization($organizationId)->find($packageId);
        if ($package === null) {
            return null;
        }
        try {
            $this->access->assertPackage($package, $actor, 'design-management.view');
        } catch (DomainException) {
            return null;
        }

        return $package;
    }

    private function materializeCompositionSections(DesignPackage $package, array $composition): void
    {
        $stage = $this->value($package->project_stage);
        $items = match ($stage) {
            'pd' => array_values($composition['sections'] ?? []),
            'rd' => array_values($composition['document_groups'] ?? []),
            'survey', 'bim' => array_values($composition['items'] ?? []),
            default => [],
        };
        foreach ($items as $index => $item) {
            if (! is_array($item) || trim((string) ($item['code'] ?? '')) === '') {
                continue;
            }
            $code = mb_strtoupper(trim((string) $item['code']), 'UTF-8');
            DesignPackageSection::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'code' => $code, 'title' => trim((string) ($item['title'] ?? $code)), 'project_stage' => $stage, 'object_type' => $this->value($package->object_type), 'required' => (bool) ($item['required'] ?? true), 'sort_order' => ($index + 1) * 10, 'metadata' => ['documents' => array_values($item['documents'] ?? [])]]);
        }
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }

    public function findVersion(int $organizationId, int $versionId, ?User $actor = null, string $permission = 'design-management.models.view'): ?DesignArtifactVersion
    {
        $actor = $this->access->requireActor($actor);
        $version = DesignArtifactVersion::forOrganization($organizationId)
            ->where('file_format', 'ifc')
            ->whereHas('artifact', static fn ($query) => $query->where('artifact_type', DesignArtifactTypeEnum::MODEL->value))
            ->with(self::versionRelations())
            ->find($versionId);
        if ($version === null) {
            return null;
        }
        try {
            $this->access->assertVersion($version, $actor, $permission, true);
        } catch (DomainException) {
            return null;
        }

        return $version;
    }

    public function uploadIfcModel(
        DesignPackage $package,
        int $userId,
        UploadedFile $file,
        array $payload
    ): DesignArtifactVersion {
        $this->access->assertPackage($package, User::query()->findOrFail($userId), 'design-management.models.upload');
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
                'revision_label' => $payload['revision_label'] ?? ($payload['revision'] ?? null),
                'source_format' => 'ifc',
                'file_format' => 'ifc',
                'source_file_path' => 'pending',
                'source_original_name' => $file->getClientOriginalName(),
                'source_mime_type' => $file->getClientMimeType() ?: 'application/x-step',
                'source_size_bytes' => (int) $file->getSize(),
                'source_sha256' => $this->sha256($file),
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

            $this->invalidateCompleteness($lockedPackage, $userId, $artifact);

            return $version->fresh(self::versionRelations());
        });
    }

    public function registerStoredIfcModel(
        DesignPackage $package,
        int $userId,
        string $sourcePath,
        array $fileInfo,
        array $payload
    ): DesignArtifactVersion {
        $this->access->assertPackage($package, User::query()->findOrFail($userId), 'design-management.models.upload');
        $this->assertIfcOriginalName((string) $fileInfo['original_name']);
        if (! str_starts_with($sourcePath, 'org-' . $package->organization_id . '/pir/projects/' . $package->project_id . '/packages/' . $package->id . '/')) {
            throw new DomainException(trans_message('design_management.errors.source_file_not_available'));
        }

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
                'revision_label' => $payload['revision_label'] ?? ($payload['revision'] ?? null),
                'source_format' => 'ifc',
                'file_format' => 'ifc',
                'source_file_path' => $sourcePath,
                'source_original_name' => (string) $fileInfo['original_name'],
                'source_mime_type' => (string) ($fileInfo['mime_type'] ?? 'application/x-step'),
                'source_size_bytes' => (int) $fileInfo['size_bytes'],
                'source_sha256' => $fileInfo['sha256'] ?? null,
                'model_date' => $payload['model_date'] ?? null,
                'status' => DesignVersionStatusEnum::UPLOADED,
                'is_current' => false,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            if ((bool) ($payload['make_current'] ?? true)) {
                $this->setCurrentVersion($version, $userId);
            }

            $this->invalidateCompleteness($lockedPackage, $userId, $artifact);

            return $version->fresh(self::versionRelations());
        });
    }

    public function storeDerivative(
        DesignArtifactVersion $version,
        int $userId,
        UploadedFile $file,
        array $payload
    ): DesignModelDerivative {
        $this->access->assertVersion($version, User::query()->findOrFail($userId), 'design-management.models.prepare_viewer', true);
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

    public function viewerPayload(DesignArtifactVersion $version, ?User $actor = null): array
    {
        $this->access->assertVersion($version, $this->access->requireActor($actor), 'design-management.models.view', true);
        if (! str_starts_with((string) $version->source_file_path, 'org-' . $version->organization_id . '/')) {
            throw new DomainException(trans_message('design_management.errors.source_file_not_available'));
        }
        $version->loadMissing(['derivatives' => static fn ($query) => $query->forResponse()]);

        $organization = Organization::query()->find((int) $version->organization_id);
        $derivative = $version->derivatives
            ->first(static fn (DesignModelDerivative $item): bool => (int) $item->organization_id === (int) $version->organization_id
                && (int) $item->project_id === (int) $version->project_id
                && str_starts_with((string) $item->derivative_file_path, 'org-' . $version->organization_id . '/')
                && $item->viewer_provider === 'thatopen'
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
    public function sourceFileStream(DesignArtifactVersion $version, ?User $actor = null): array
    {
        $this->access->assertVersion($version, $this->access->requireActor($actor), 'design-management.models.view', true);
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
    public function derivativeFileStream(DesignArtifactVersion $version, ?User $actor = null): array
    {
        $this->access->assertVersion($version, $this->access->requireActor($actor), 'design-management.models.view', true);
        $version->loadMissing('derivatives');

        $derivative = $version->derivatives
            ->first(static function (DesignModelDerivative $item) use ($version): bool {
                $status = $item->status instanceof DesignDerivativeStatusEnum
                    ? $item->status->value
                    : (string) $item->status;

                return (int) $item->organization_id === (int) $version->organization_id
                    && (int) $item->project_id === (int) $version->project_id
                    && $item->viewer_provider === 'thatopen'
                    && $item->derivative_format === 'thatopen_frag'
                    && $status === DesignDerivativeStatusEnum::READY->value
                    && DesignViewerConverter::isCurrent($item);
            });

        if (! $derivative instanceof DesignModelDerivative || empty($derivative->derivative_file_path)) {
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
        $this->access->assertVersion($version, User::query()->findOrFail($userId), 'design-management.models.edit', true);
        return DB::transaction(function () use ($version, $userId): DesignArtifactVersion {
            $version->loadMissing('artifact.package');
            $package = $version->artifact?->package;

            if (! $package instanceof DesignPackage) {
                throw new DomainException(trans_message('design_management.errors.version_not_found'));
            }

            $package = $this->lockedPackageForModelChanges($package);
            $this->setCurrentVersion($version, $userId);
            $this->invalidateCompleteness($package, $userId, $version->artifact);

            return $version->fresh(self::versionRelations());
        });
    }

    public function ensurePackageAcceptsModelChanges(DesignPackage $package, User|int|null $actor = null, ?int $artifactId = null): void
    {
        $this->access->assertPackage($package, $this->access->requireActor($actor), 'design-management.models.upload');
        $this->assertPackageAcceptsModelChanges($package);
        if ($artifactId !== null) {
            $this->modelArtifactTarget($package, $artifactId);
        }
    }

    public function ensureVersionPackageAcceptsModelChanges(DesignArtifactVersion $version, ?User $actor = null): void
    {
        $this->access->assertVersion($version, $this->access->requireActor($actor), 'design-management.models.prepare_viewer', true);
        $version->loadMissing('artifact.package');
        $package = $version->artifact?->package;

        if (! $package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        $this->assertPackageAcceptsModelChanges($package);
    }

    private function resolveArtifact(DesignPackage $package, int $userId, array $payload): DesignArtifact
    {
        if (isset($payload['artifact_id'])) {
            return $this->modelArtifactTarget($package, (int) $payload['artifact_id']);
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

    private function modelArtifactTarget(DesignPackage $package, int $artifactId): DesignArtifact
    {
        $artifact = DesignArtifact::forOrganization((int) $package->organization_id)
            ->where('package_id', $package->id)
            ->where('project_id', $package->project_id)
            ->where('artifact_type', DesignArtifactTypeEnum::MODEL->value)
            ->with('section')->find($artifactId);
        if (! $artifact instanceof DesignArtifact) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }
        if (($artifact->metadata['composition_active'] ?? true) === false
            || ($artifact->section?->metadata['composition_active'] ?? true) === false) {
            throw ValidationException::withMessages([
                'artifact_id' => trans_message('design_management.errors.model_slot_inactive'),
            ]);
        }
        if ($artifact->section_id !== null && ($artifact->section === null
            || (int) $artifact->section->organization_id !== (int) $package->organization_id
            || (int) $artifact->section->project_id !== (int) $package->project_id
            || (int) $artifact->section->package_id !== (int) $package->id)) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        return $artifact;
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

    private function invalidateCompleteness(DesignPackage $package, int $userId, DesignArtifact $artifact): void
    {
        $this->completeness->invalidateLockedInputs($package, $userId);
        if ($artifact->section_id !== null) {
            $artifact->loadMissing('section');
            $sectionMetadata = $artifact->section->metadata ?? [];
            $sectionMetadata['readiness_status'] = 'not_checked';
            unset($sectionMetadata['readiness_results'], $sectionMetadata['readiness_check_id']);
            $artifact->section->update(['status' => DesignDocumentSectionStatusEnum::IN_WORK, 'metadata' => $sectionMetadata]);
        }
    }

    private function storeUploadedFile(UploadedFile $file, string $path, int $organizationId): void
    {
        $organization = Organization::query()->find($organizationId);
        $stream = $this->readUploadedFileStream($file);

        try {
            $stored = $this->fileService->disk($organization)->put($path, $stream, 'private');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }
    }

    private function readUploadedFileStream(UploadedFile $file): mixed
    {
        $realPath = $file->getRealPath();

        if (! $realPath || ! is_file($realPath)) {
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
        if (! str_starts_with($path, 'org-' . $organizationId . '/')) {
            throw new DomainException($errorMessage);
        }

        $organization = Organization::query()->find($organizationId);
        $stream = $this->fileService->disk($organization)->readStream($path);

        if (! is_resource($stream)) {
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

        if (! $exists) {
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

        if (! $lockedPackage instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }

        $this->assertPackageAcceptsModelChanges($lockedPackage);

        return $lockedPackage;
    }

    private function assertPackageAcceptsModelChanges(DesignPackage $package): void
    {
        if (! DesignPackageWorkflow::canChangeModels($package)) {
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

    private function normalizeProjectStage(array $payload): string
    {
        $value = strtolower((string) ($payload['project_stage'] ?? $payload['stage'] ?? DesignProjectStageEnum::RD->value));

        return in_array($value, array_map(static fn (DesignProjectStageEnum $stage): string => $stage->value, DesignProjectStageEnum::cases()), true)
            ? $value
            : DesignProjectStageEnum::RD->value;
    }

    private function normalizeObjectType(array $payload): string
    {
        $value = (string) ($payload['object_type'] ?? DesignObjectTypeEnum::NON_LINEAR_NON_PRODUCTION->value);

        return in_array($value, array_map(static fn (DesignObjectTypeEnum $type): string => $type->value, DesignObjectTypeEnum::cases()), true)
            ? $value
            : DesignObjectTypeEnum::NON_LINEAR_NON_PRODUCTION->value;
    }

    private function defaultProfileCode(string $projectStage, string $objectType): string
    {
        return DesignNormativeCatalog::profileFor($projectStage, $objectType);
    }

    private function sha256(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        return $realPath && is_file($realPath) ? hash_file('sha256', $realPath) : null;
    }

    private function derivativePayload(?DesignModelDerivative $derivative, ?Organization $organization): array
    {
        if (! $derivative instanceof DesignModelDerivative) {
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
                'metadata' => DesignViewerConverter::staleMetadata(DesignViewerConverter::publicMetadata(is_array($derivative->metadata) ? $derivative->metadata : [])),
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
            'metadata' => DesignViewerConverter::publicMetadata(is_array($derivative->metadata) ? $derivative->metadata : []),
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
