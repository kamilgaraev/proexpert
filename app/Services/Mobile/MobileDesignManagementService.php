<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSetResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementQueryService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelOfflinePackageService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelViewerPreparationService;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use BackedEnum;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MobileDesignManagementService
{
    public function __construct(
        private MobileDesignManagementAccess $access,
        private MobilePtoService $pto,
        private DesignManagementService $models,
        private DesignModelViewerPreparationService $preparation,
        private DesignModelOfflinePackageService $offline,
        private DesignIfcElementQueryService $elements,
        private DesignModelSetService $sets,
    ) {}

    public function packages(User $actor, int $organizationId, array $filters): array
    {
        $this->access->project($actor, $organizationId, (int) $filters['project_id'], 'design-management.view');

        return $this->pto->designPackages($actor, $organizationId, $filters);
    }

    public function package(User $actor, int $organizationId, int $packageId): array
    {
        $package = $this->models->findPackage($organizationId, $packageId);
        if ($package === null) {
            throw new BusinessLogicException(trans_message('design_management.errors.package_not_found'), 404);
        }
        $this->access->project($actor, $organizationId, (int) $package->project_id, 'design-management.view');

        return $this->pto->designPackage($actor, $organizationId, $packageId);
    }

    public function versions(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->access->project($actor, $organizationId, $projectId);
        $query = $this->versionsQuery($organizationId, $projectId)
            ->select(['id', 'artifact_id', 'project_id', 'title', 'version_number', 'revision', 'is_current', 'status'])
            ->with(['artifact:id,package_id,title', 'derivatives' => fn ($derivatives) => $derivatives
                ->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('viewer_provider', 'thatopen')->where('derivative_format', 'thatopen_frag')
                ->select(['id', 'version_id', 'viewer_provider', 'derivative_format', 'status', 'progress_percent', 'processing_stage'])
                ->selectRaw("jsonb_build_object('converter_version', metadata->'converter_version') AS metadata")]);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($models) => $models->where('title', 'ilike', $pattern)
                ->orWhere('source_original_name', 'ilike', $pattern)->orWhere('version_number', 'ilike', $pattern)
                ->orWhereHas('artifact', fn ($artifacts) => $artifacts->where('title', 'ilike', $pattern)));
        }
        $status = $filters['status'] ?? null;
        $derivativeScope = fn ($derivatives) => $derivatives->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('viewer_provider', 'thatopen')->where('derivative_format', 'thatopen_frag');
        $converterSql = "CASE WHEN metadata->>'converter_version' ~ '^[0-9]{1,9}$' THEN (metadata->>'converter_version')::integer ELSE 0 END";
        if (in_array($status, ['uploaded', 'current', 'superseded', 'archived'], true)) {
            $query->where('status', $status);
        } elseif ($status === 'missing') {
            $query->where(fn ($models) => $models->whereDoesntHave('derivatives', $derivativeScope)
                ->orWhereHas('derivatives', fn ($derivatives) => $derivativeScope($derivatives)->where('status', 'ready')->whereRaw($converterSql.' < ?', [DesignViewerConverter::version()])));
        } elseif ($status !== null) {
            $query->whereHas('derivatives', fn ($derivatives) => $derivativeScope($derivatives)->where('status', $status)
                ->when($status === 'ready', fn ($ready) => $ready->whereRaw($converterSql.' >= ?', [DesignViewerConverter::version()])));
        }
        $page = $query->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $actions = $this->versionActions($actor, $organizationId, $projectId);

        return ['items' => $page->getCollection()->map(fn (DesignArtifactVersion $version): array => $this->versionPayload($version, $actions))->all(), 'meta' => $this->meta($page)];
    }

    public function viewer(User $actor, int $organizationId, int $versionId): array
    {
        $version = $this->access->version($actor, $organizationId, $versionId);
        if (! str_starts_with((string) $version->source_file_path, 'org-'.$organizationId.'/')) {
            throw new BusinessLogicException(trans_message('design_management.errors.version_not_found'), 404);
        }
        $version->setRelation('derivatives', $version->derivatives->filter(fn ($derivative): bool => (int) $derivative->organization_id === $organizationId
            && (int) $derivative->project_id === (int) $version->project_id
            && ($derivative->derivative_file_path === null || str_starts_with((string) $derivative->derivative_file_path, 'org-'.$organizationId.'/'))));
        $payload = $this->models->viewerPayload($version);
        $payload['available_actions'] = $this->versionActions($actor, $organizationId, (int) $version->project_id);

        return $payload;
    }

    public function prepare(User $actor, int $organizationId, int $versionId): array
    {
        $version = $this->access->version($actor, $organizationId, $versionId, 'design-management.models.edit');
        $this->preparation->queuePreparation($version, (int) $actor->id);

        return $this->viewer($actor, $organizationId, $versionId);
    }

    public function offlinePackage(User $actor, int $organizationId, int $versionId): array
    {
        $version = $this->access->version($actor, $organizationId, $versionId);

        return $this->offline->offlinePackage($actor, $organizationId, $version) + [
            'organization_id' => $organizationId, 'project_id' => (int) $version->project_id,
            'available_actions' => $this->versionActions($actor, $organizationId, (int) $version->project_id),
        ];
    }

    public function elements(User $actor, int $organizationId, int $versionId, array $filters): array
    {
        $version = $this->access->version($actor, $organizationId, $versionId);
        $this->access->project($actor, $organizationId, (int) $version->project_id);
        $page = $this->elements->paginate($actor, $organizationId, $versionId, (int) ($filters['per_page'] ?? 50), $filters['search'] ?? null);

        return ['items' => $page->getCollection()->map(fn (DesignIfcModelElement $element): array => $this->elements->payload($element))->all(), 'meta' => $this->meta($page)];
    }

    public function element(User $actor, int $organizationId, int $versionId, int $expressId): array
    {
        $this->access->version($actor, $organizationId, $versionId);

        return $this->elements->payload($this->elements->element($actor, $organizationId, $versionId, $expressId));
    }

    public function sets(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->access->project($actor, $organizationId, $projectId);
        $page = DesignModelSet::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->with('revisions')->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $canEdit = $this->access->can($actor, $organizationId, $projectId, 'design-management.models.edit');

        return ['items' => $page->getCollection()->map(fn (DesignModelSet $set): array => $this->setPayload($actor, $organizationId, $set, $canEdit))->all(),
            'meta' => $this->meta($page) + ['available_actions' => [$this->action('create', $canEdit)]]];
    }

    public function set(User $actor, int $organizationId, int $setId): array
    {
        return $this->setPayload($actor, $organizationId, $this->findSet($actor, $organizationId, $setId));
    }

    public function createSet(User $actor, int $organizationId, array $data): array
    {
        $this->access->project($actor, $organizationId, (int) $data['project_id'], 'design-management.models.edit');
        $this->assertSetVersions($actor, $organizationId, (int) $data['project_id'], $data);

        return $this->setPayload($actor, $organizationId, $this->sets->create($organizationId, $actor, $data));
    }

    public function updateSet(User $actor, int $organizationId, int $setId, array $data): array
    {
        $set = $this->findSet($actor, $organizationId, $setId, 'design-management.models.edit');
        $this->assertSetVersions($actor, $organizationId, (int) $set->project_id, $data);
        try {
            $updated = $this->sets->update($organizationId, $setId, $actor, $data);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === trans_message('design_bim.errors.revision_conflict')) {
                throw new BusinessLogicException(trans_message('design_bim.errors.revision_conflict'), 409, $exception);
            }
            throw $exception;
        }

        return $this->setPayload($actor, $organizationId, $updated);
    }

    public function deleteSet(User $actor, int $organizationId, int $setId, int $expectedRevision): array
    {
        $this->findSet($actor, $organizationId, $setId, 'design-management.models.edit');

        return DB::transaction(function () use ($actor, $organizationId, $setId, $expectedRevision): array {
            $set = DesignModelSet::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($setId);
            $this->access->project($actor, $organizationId, (int) $set->project_id, 'design-management.models.edit');
            if ((int) $set->revision !== $expectedRevision) {
                throw new BusinessLogicException(trans_message('design_bim.errors.revision_conflict'), 409);
            }
            if (DB::table('design_model_sessions')->where('model_set_id', $setId)->exists()
                || DB::table('quality_defects')->where('organization_id', $organizationId)->whereIn('metadata->design_issue_context->model_set_revision_id', $set->revisions()->pluck('id'))->exists()) {
                throw new BusinessLogicException(trans_message('mobile_design.errors.set_in_use'), 409);
            }
            $set->revisions()->delete();
            $set->delete();

            return ['id' => $setId, 'deleted' => true];
        });
    }

    public function openSet(User $actor, int $organizationId, int $setId, int $revision): array
    {
        $this->findSet($actor, $organizationId, $setId);

        return $this->sets->openRevision($organizationId, $actor, $setId, $revision);
    }

    public function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()];
    }

    private function versionsQuery(int $organizationId, int $projectId): Builder
    {
        return DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('file_format', 'ifc')
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->whereHas('package', fn ($packages) => $packages->where('organization_id', $organizationId)->where('project_id', $projectId)));
    }

    private function versionPayload(DesignArtifactVersion $version, array $availableActions): array
    {
        $derivative = $version->derivatives->first(fn ($item): bool => $item->viewer_provider === 'thatopen' && $item->derivative_format === 'thatopen_frag');
        $derivativeStatus = $derivative?->status;
        $stale = $derivative !== null && DesignViewerConverter::isStale($derivative);
        $publicStatus = $stale ? 'missing' : ($derivativeStatus instanceof BackedEnum ? $derivativeStatus->value : ($derivativeStatus ?? 'missing'));

        return ['id' => (int) $version->id, 'version_id' => (int) $version->id, 'artifact_id' => (int) $version->artifact_id,
            'package_id' => (int) $version->artifact->package_id, 'project_id' => (int) $version->project_id,
            'title' => $version->title, 'model_title' => $version->artifact->title,
            'version_number' => $version->version_number, 'revision' => $version->revision, 'is_current' => $version->is_current,
            'status' => $version->status instanceof BackedEnum ? $version->status->value : $version->status,
            'derivative_status' => $publicStatus,
            'derivative' => $derivative === null ? null : ['id' => (int) $derivative->id,
                'status' => $publicStatus,
                'progress_percent' => $stale ? 0 : (int) $derivative->progress_percent, 'processing_stage' => $stale ? 'stale' : $derivative->processing_stage,
                'is_current' => DesignViewerConverter::isCurrent($derivative)],
            'available_actions' => $availableActions];
    }

    private function versionActions(User $actor, int $organizationId, int $projectId): array
    {
        $permissions = $this->access->permissions($actor, $organizationId, $projectId, ['design-management.models.edit', 'design-management.review']);

        return [$this->action('prepare_viewer', $permissions['design-management.models.edit']),
            $this->action('create_issue', $permissions['design-management.review'])];
    }

    private function action(string $key, bool $enabled): array
    {
        return ['key' => $key, 'label' => trans_message('mobile_design.actions.'.$key), 'enabled' => $enabled];
    }

    private function findSet(User $actor, int $organizationId, int $setId, string $permission = 'design-management.models.view'): DesignModelSet
    {
        $set = DesignModelSet::query()->where('organization_id', $organizationId)->with('revisions')->find($setId);
        if (! $set instanceof DesignModelSet) {
            throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404);
        }
        $this->access->project($actor, $organizationId, (int) $set->project_id, $permission);

        return $set;
    }

    private function setPayload(User $actor, int $organizationId, DesignModelSet $set, ?bool $canEdit = null): array
    {
        $canEdit ??= $this->access->can($actor, $organizationId, (int) $set->project_id, 'design-management.models.edit');

        return (new DesignModelSetResource($set))->resolve() + ['available_actions' => [$this->action('update', $canEdit), $this->action('delete', $canEdit)]];
    }

    private function assertSetVersions(User $actor, int $organizationId, int $projectId, array $data): void
    {
        foreach ($data['version_ids'] as $id) {
            $version = $this->access->version($actor, $organizationId, (int) $id, 'design-management.models.edit');
            if ((int) $version->project_id !== $projectId) {
                throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
            }
        }
        foreach (($data['transforms'] ?? []) as $id => $transform) {
            if (! in_array((int) $id, array_map('intval', $data['version_ids']), true)) {
                throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
            }
            if (! is_array($transform) || array_diff(array_keys($transform), ['shift', 'rotation']) !== []) {
                throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
            }
            if (isset($transform['shift']) && (! is_array($transform['shift']) || ! array_is_list($transform['shift']) || count($transform['shift']) !== 3)) {
                throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
            }
            foreach ($transform['shift'] ?? [] as $coordinate) {
                if (! is_numeric($coordinate) || ! is_finite((float) $coordinate)) {
                    throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
                }
            }
            if (isset($transform['rotation']) && (! is_numeric($transform['rotation']) || ! is_finite((float) $transform['rotation']) || abs((float) $transform['rotation']) > 360)) {
                throw new BusinessLogicException(trans_message('design_bim.errors.model_versions_invalid'), 422);
            }
        }
    }
}
