<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class DesignModelSetService
{
    public function __construct(private readonly DesignModelSessionAccessService $sessionAccess)
    {
    }

    public function create(int $organizationId, User $user, array $data): DesignModelSet
    {
        return DB::transaction(function () use ($organizationId, $user, $data): DesignModelSet {
            $this->assertProjectAccess($user, $organizationId, (int) $data['project_id'], 'design-management.models.edit');
            $this->assertVersions($organizationId, (int) $data['project_id'], $data['version_ids']);
            $set = DesignModelSet::query()->create([
                'organization_id' => $organizationId, 'project_id' => $data['project_id'], 'title' => $data['title'],
                'revision' => 1, 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $this->revision($set, $user, $data['version_ids'], $data['transforms'] ?? []);

            return $set->load('revisions');
        });
    }

    public function update(int $organizationId, int $setId, User $user, array $data): DesignModelSet
    {
        return DB::transaction(function () use ($organizationId, $setId, $user, $data): DesignModelSet {
            $set = DesignModelSet::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($setId);
            $this->assertProjectAccess($user, $organizationId, (int) $set->project_id, 'design-management.models.edit');
            if ($set->revision !== (int) $data['expected_revision']) {
                throw new DomainException(trans_message('design_bim.errors.revision_conflict'));
            }
            $this->assertVersions($organizationId, (int) $set->project_id, $data['version_ids']);
            $set->update(['title' => $data['title'] ?? $set->title, 'revision' => $set->revision + 1, 'updated_by' => $user->id]);
            $this->revision($set, $user, $data['version_ids'], $data['transforms'] ?? []);

            return $set->load('revisions');
        });
    }

    public function createSession(int $organizationId, User $user, array $data): DesignModelSession
    {
        $set = DesignModelSet::query()->where('organization_id', $organizationId)->where('project_id', $data['project_id'])->findOrFail($data['model_set_id']);
        $this->assertProjectAccess($user, $organizationId, (int) $set->project_id);
        $revision = $set->revisions()->where('revision', $data['model_set_revision'])->firstOrFail();
        $this->assertVersions($organizationId, (int) $set->project_id, $revision->version_ids ?? []);

        return DesignModelSession::query()->create([
            'organization_id' => $organizationId, 'project_id' => $set->project_id, 'model_set_id' => $set->id,
            'model_set_revision_id' => $revision->id, 'created_by' => $user->id, 'title' => $data['title'],
        ])->load('modelSetRevision');
    }

    public function listSessions(int $organizationId, User $user, int $projectId, int $perPage): LengthAwarePaginator
    {
        $this->assertProjectAccess($user, $organizationId, $projectId);

        return DesignModelSession::query()
            ->select('design_model_sessions.*')
            ->join('design_model_set_revisions', 'design_model_set_revisions.id', '=', 'design_model_sessions.model_set_revision_id')
            ->where('design_model_sessions.organization_id', $organizationId)
            ->where('design_model_sessions.project_id', $projectId)
            ->whereRaw('jsonb_array_length(design_model_set_revisions.version_ids) > 0')
            ->whereRaw(<<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements_text(design_model_set_revisions.version_ids) AS pinned_version(id)
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM design_artifact_versions
                        WHERE design_artifact_versions.id = pinned_version.id::bigint
                          AND design_artifact_versions.organization_id = design_model_sessions.organization_id
                          AND design_artifact_versions.project_id = design_model_sessions.project_id
                          AND design_artifact_versions.file_format = 'ifc'
                    )
                )
            SQL)
            ->with('modelSetRevision')
            ->latest('design_model_sessions.id')
            ->paginate($perPage);
    }

    public function sessionBootstrap(int $organizationId, User $user, int $sessionId): DesignModelSession
    {
        if (! $this->sessionAccess->canJoin($user, $sessionId)) {
            throw new DomainException(trans_message('design_bim.errors.session_access_denied'));
        }

        $session = DesignModelSession::query()
            ->where('organization_id', $organizationId)
            ->with('modelSetRevision')
            ->findOrFail($sessionId);
        $versionIds = array_values(array_unique(array_map('intval', $session->modelSetRevision->version_ids ?? [])));
        $versions = DesignArtifactVersion::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $session->project_id)
            ->whereIn('id', $versionIds)
            ->with(['artifact', 'readyDerivative'])
            ->get()
            ->sortBy(static fn (DesignArtifactVersion $version): int => array_search((int) $version->id, $versionIds, true))
            ->values();
        $session->setRelation('modelVersions', $versions);

        return $session;
    }

    public function openRevision(int $organizationId, User $user, int $setId, int $revisionNumber): array
    {
        $set = DesignModelSet::query()->where('organization_id', $organizationId)->findOrFail($setId);
        $this->assertProjectAccess($user, $organizationId, (int) $set->project_id);
        $revision = $set->revisions()->where('revision', $revisionNumber)->firstOrFail();
        $ids = array_values(array_unique(array_map('intval', $revision->version_ids ?? [])));
        $versions = DesignArtifactVersion::query()
            ->where('organization_id', $organizationId)->where('project_id', $set->project_id)
            ->whereIn('id', $ids)->where('file_format', 'ifc')
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $organizationId)
                ->where('project_id', $set->project_id)->whereHas('package', fn ($packages) => $packages
                    ->where('organization_id', $organizationId)->where('project_id', $set->project_id)))
            ->with(['artifact', 'readyDerivative'])->get()->keyBy('id');
        if ($ids === [] || $versions->count() !== count($ids)) {
            throw new DomainException(trans_message('design_bim.errors.model_versions_invalid'));
        }

        return [
            'project_id' => (int) $set->project_id,
            'model_set_id' => (int) $set->id,
            'model_set_revision_id' => (int) $revision->id,
            'model_set_revision' => (int) $revision->revision,
            'title' => $set->title,
            'models' => $ids,
            'transforms' => $revision->transforms ?? [],
            'model_versions' => array_map(static function (int $id) use ($versions): array {
                $version = $versions->get($id);
                $status = $version->readyDerivative?->status;

                return [
                    'version_id' => (int) $version->id, 'model_id' => (int) $version->artifact_id,
                    'package_id' => (int) $version->artifact->package_id, 'model_title' => $version->artifact->title,
                    'title' => $version->title, 'version_number' => $version->version_number, 'revision' => $version->revision,
                    'derivative_status' => $status instanceof \BackedEnum ? $status->value : ($status ?? 'missing'),
                ];
            }, $ids),
        ];
    }

    private function revision(DesignModelSet $set, User $user, array $versionIds, array $transforms): void
    {
        DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => $set->revision,
            'version_ids' => array_values(array_unique(array_map('intval', $versionIds))), 'transforms' => $transforms, 'created_by' => $user->id]);
    }

    private function assertVersions(int $organizationId, int $projectId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereIn('id', $ids)->where('file_format', 'ifc')->count() !== count($ids)) {
            throw new DomainException(trans_message('design_bim.errors.model_versions_invalid'));
        }
    }

    private function assertProjectAccess(User $user, int $organizationId, int $projectId, string $permission = 'design-management.models.view'): void
    {
        if (! $this->sessionAccess->canAccessProject($user, $organizationId, $projectId, $permission)) {
            throw new DomainException(trans_message('design_bim.errors.session_access_denied'));
        }
    }
}
