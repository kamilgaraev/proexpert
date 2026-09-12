<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class DesignProjectIssueService
{
    public function __construct(private readonly QualityDefectService $defects, private readonly FileService $files, private readonly AuthorizationService $authorization, private readonly DesignIssueContextResolver $contextResolver, private readonly DesignModelSessionAccessService $sessionAccess) {}

    /** @return Collection<int, QualityDefect> */
    public function list(User $actor, int $organizationId, int $projectId, array $filters = []): Collection
    {
        $this->project($organizationId, $projectId);
        $this->authorize($actor, 'design-management.view', $organizationId, $projectId);

        return QualityDefect::query()
            ->forOrganization($organizationId)
            ->projectIssues()
            ->where('project_id', $projectId)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['version_id']), static fn ($query) => $query->whereJsonContains('metadata->design_issue_context->version_id', (int) $filters['version_id']))
            ->with(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy'])
            ->latest('id')
            ->get();
    }

    public function find(User $actor, int $organizationId, int $issueId): ?QualityDefect
    {
        $issue = QualityDefect::query()->forOrganization($organizationId)->projectIssues()
            ->with(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy'])
            ->find($issueId);

        if ($issue !== null) {
            $this->project($organizationId, (int) $issue->project_id);
            $this->authorize($actor, 'design-management.view', $organizationId, (int) $issue->project_id);
        }

        return $issue;
    }

    public function create(User $actor, int $organizationId, int $projectId, array $payload): QualityDefect
    {
        $this->project($organizationId, $projectId);
        $this->authorize($actor, 'design-management.review', $organizationId, $projectId);
        $context = $this->context($actor, $organizationId, $projectId, $payload);
        $issue = $this->defects->create($organizationId, (int) $actor->id, [
            'project_id' => $projectId,
            'kind' => 'project',
            'assigned_to' => $payload['assignee_id'] ?? null,
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'severity' => $payload['severity'],
            'due_date' => $payload['due_date'] ?? null,
            'inspection_required' => false,
            'metadata' => ['design_issue_context' => $context],
        ]);

        return $issue->fresh(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy']);
    }

    public function assign(QualityDefect $issue, User $actor, int $assigneeId, ?string $comment): QualityDefect
    {
        $this->authorize($actor, 'design-management.review', (int) $issue->organization_id, (int) $issue->project_id);

        return $this->defects->assign($issue, $assigneeId, (int) $actor->id, $comment);
    }

    public function resolve(QualityDefect $issue, User $actor, ?string $comment): QualityDefect
    {
        $this->authorize($actor, 'design-management.review', (int) $issue->organization_id, (int) $issue->project_id);

        return $this->defects->resolve($issue, (int) $actor->id, ['comment' => $comment]);
    }

    public function verify(QualityDefect $issue, User $actor, bool $accepted, ?string $comment): QualityDefect
    {
        $this->authorize($actor, 'design-management.review', (int) $issue->organization_id, (int) $issue->project_id);

        return $this->defects->verify($issue, (int) $actor->id, $accepted, $comment);
    }

    public function setBlocking(QualityDefect $issue, User $actor, bool $active, ?string $reason): QualityDefect
    {
        return DB::transaction(function () use ($issue, $actor, $active, $reason): QualityDefect {
            $locked = QualityDefect::query()->forOrganization((int) $issue->organization_id)
                ->where('project_id', $issue->project_id)->projectIssues()->whereKey($issue->id)
                ->lockForUpdate()->firstOrFail();

            return $this->applyBlocking($locked, $actor, $active, $reason);
        });
    }

    private function applyBlocking(QualityDefect $issue, User $actor, bool $active, ?string $reason): QualityDefect
    {
        $this->authorize($actor, 'design-management.issues.manage_blocking', (int) $issue->organization_id, (int) $issue->project_id);
        if (! $active && trim((string) $reason) === '') {
            throw new DomainException(trans_message('design_issues.errors.blocking_reason_required'));
        }

        $metadata = $issue->metadata ?? [];
        $previous = (array) ($metadata['blocking'] ?? []);
        $metadata['blocking'] = [
            'active' => $active,
            'reason' => trim((string) $reason),
            'changed_by' => $actor->id,
            'changed_at' => now()->toIso8601String(),
        ];
        $issue->update(['metadata' => $metadata, 'row_version' => (int) $issue->getAttribute('row_version') + 1]);
        $issue->statusHistory()->create([
            'organization_id' => $issue->organization_id,
            'from_status' => $issue->status,
            'to_status' => $issue->status,
            'comment' => ($active ? 'blocking_set: ' : 'blocking_cleared: ').trim((string) $reason),
            'changed_by' => $actor->id,
            'changed_at' => now(),
            'reporting_dimensions' => ['project_id' => (int) $issue->project_id],
            'reporting_evidence_refs' => [],
        ]);
        if (($previous['active'] ?? false) !== $active || ($previous['reason'] ?? null) !== ($active ? trim((string) $reason) : null)) {
            $packageId = $metadata['design_issue_context']['package_id'] ?? null;
            if ($packageId !== null) {
                $package = DesignPackage::query()->forOrganization((int) $issue->organization_id)->where('project_id', $issue->project_id)->find($packageId);
                if ($package !== null) {
                    $packageMetadata = $package->metadata ?? [];
                    $packageMetadata['completeness_invalidated_at'] = now()->toIso8601String();
                    $packageMetadata['completeness_invalidated_by_issue_id'] = $issue->id;
                    $package->update(['metadata' => $packageMetadata]);
                }
            }
        }

        return $issue->fresh(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy']);
    }

    public function storeSnapshot(QualityDefect $issue, User $actor, UploadedFile $file): QualityDefect
    {
        return $this->withRevision($issue, (int) $issue->getAttribute('row_version'),
            fn (QualityDefect $locked): QualityDefect => $this->saveSnapshot($locked, $actor, $file));
    }

    public function withRevision(QualityDefect $issue, int $expectedRevision, callable $operation): QualityDefect
    {
        return DB::transaction(function () use ($issue, $expectedRevision, $operation): QualityDefect {
            $locked = QualityDefect::query()->forOrganization((int) $issue->organization_id)
                ->where('project_id', $issue->project_id)->projectIssues()->whereKey($issue->id)
                ->lockForUpdate()->firstOrFail();
            if ((int) $locked->getAttribute('row_version') !== $expectedRevision) {
                throw new DomainException(trans_message('design_issues.errors.stale_revision'));
            }

            return $operation($locked);
        });
    }

    private function saveSnapshot(QualityDefect $issue, User $actor, UploadedFile $file): QualityDefect
    {
        $this->authorize($actor, 'design-management.review', (int) $issue->organization_id, (int) $issue->project_id);
        $organization = Organization::query()->findOrFail($issue->organization_id);
        $path = $this->files->upload($file, "design-management/issues/{$issue->id}", null, 'private', $organization);
        if ($path === false) {
            throw new DomainException(trans_message('design_issues.errors.snapshot_upload_failed'));
        }
        $metadata = $issue->metadata ?? [];
        $context = (array) ($metadata['design_issue_context'] ?? []);
        $context['snapshot'] = ['path' => $path, 'mime_type' => $file->getClientMimeType(), 'original_name' => $file->getClientOriginalName()];
        $metadata['design_issue_context'] = $context;
        $issue->update(['metadata' => $metadata, 'row_version' => (int) $issue->getAttribute('row_version') + 1]);

        return $issue->fresh(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy']);
    }

    public function bimContext(QualityDefect $issue, User $actor): array
    {
        $this->authorize($actor, 'design-management.view', (int) $issue->organization_id, (int) $issue->project_id);
        if (! $this->sessionAccess->canAccessProject($actor, (int) $issue->organization_id, (int) $issue->project_id)) {
            throw new DomainException(trans_message('design_issues.errors.target_not_found'));
        }
        $context = (array) (($issue->metadata ?? [])['design_issue_context'] ?? []);
        $revisionId = isset($context['model_set_revision_id']) ? (int) $context['model_set_revision_id'] : null;
        $transforms = [];
        if (isset($context['view_models'])) {
            $validated = $this->contextResolver->resolve($actor, (int) $issue->organization_id, (int) $issue->project_id, [
                'view_models' => $context['view_models'], 'elements' => $context['elements'] ?? [],
            ]);
            $versionIds = array_column($validated['view_models'], 'version_id');
            foreach ($validated['view_models'] as $model) {
                $transforms[(string) $model['version_id']] = $model['transform'];
            }
        } elseif ($revisionId !== null) {
            $revision = DesignModelSetRevision::query()->with('modelSet')->find($revisionId);
            if (! $revision instanceof DesignModelSetRevision || $revision->modelSet === null || (int) $revision->modelSet->organization_id !== (int) $issue->organization_id || (int) $revision->modelSet->project_id !== (int) $issue->project_id) {
                throw new DomainException(trans_message('design_issues.errors.target_not_found'));
            }
            $versionIds = array_values(array_unique(array_map('intval', $revision->version_ids ?? [])));
            $transforms = $revision->transforms ?? [];
        } elseif (isset($context['version_id'])) {
            $versionIds = [(int) $context['version_id']];
        } else {
            throw new DomainException(trans_message('design_issues.errors.target_not_found'));
        }
        $versions = DesignArtifactVersion::query()->where('organization_id', $issue->organization_id)->where('project_id', $issue->project_id)->whereIn('id', $versionIds)->where('file_format', 'ifc')->with(['artifact', 'readyDerivative'])->get()->keyBy('id');
        if (count($versionIds) === 0 || $versions->count() !== count($versionIds)) {
            throw new DomainException(trans_message('design_issues.errors.target_not_found'));
        }
        return [
            'models' => $versionIds,
            'model_versions' => collect($versionIds)->map(function (int $versionId) use ($versions): array {
                $version = $versions->get($versionId);
                return ['version_id' => $version->id, 'model_id' => $version->artifact_id, 'package_id' => $version->artifact?->package_id, 'model_title' => $version->artifact?->title, 'title' => $version->title, 'version_number' => $version->version_number, 'revision' => $version->revision, 'derivative_status' => $version->readyDerivative?->status?->value ?? 'missing'];
            })->all(),
            'transforms' => $transforms,
            'model_set_revision_id' => $revisionId,
            'camera' => $context['camera'] ?? null,
            'point' => $context['point'] ?? null,
            'elements' => $context['elements'] ?? (isset($context['bim_element_id']) && isset($context['version_id']) ? [['version_id' => (int) $context['version_id'], 'element_id' => (int) $context['bim_element_id']]] : []),
        ];
    }

    private function project(int $organizationId, int $projectId): void
    {
        if (! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('design_issues.errors.project_not_found'));
        }
    }

    private function authorize(User $actor, string $permission, int $organizationId, int $projectId): void
    {
        if (! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId, $permission)) {
            throw new DomainException(trans_message('design_issues.errors.forbidden'));
        }
    }

    private function context(User $actor, int $organizationId, int $projectId, array $payload): array
    {
        return $this->contextResolver->resolve($actor, $organizationId, $projectId, $payload);
    }
}
