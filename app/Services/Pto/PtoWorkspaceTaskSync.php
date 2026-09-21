<?php

declare(strict_types=1);

namespace App\Services\Pto;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\PtoWorkspaceTask;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

final class PtoWorkspaceTaskSync
{
    public function __construct(
        private readonly PtoWorkspaceQuery $query,
        private readonly UserProjectAccessService $projectAccess,
        private readonly AuthorizationService $authorization,
        private readonly ExecutiveDocumentRequirementsService $requirements,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function syncAccessible(User $actor, int $organizationId, array $filters = []): void
    {
        $projectIds = $this->query->accessibleProjectIds($actor, $organizationId, $filters);
        if ($projectIds === []) {
            return;
        }
        $sets = ExecutiveDocumentSet::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->get();
        foreach ($sets as $set) {
            $readiness = $this->requirements->readiness($set);
            $unsatisfied = [];
            foreach ($readiness['blockers'] as $blocker) {
                if (! is_array($blocker) || empty($blocker['requirement_id'])) {
                    continue;
                }
                $unsatisfied[(int) $blocker['requirement_id']] = (string) ($blocker['message'] ?? '');
            }
            $requirements = ExecutiveDocumentRequirement::query()
                ->where('document_set_id', $set->id)
                ->whereNull('superseded_at')
                ->where('applicability', 'required')
                ->get();
            foreach ($requirements as $requirement) {
                if (! array_key_exists((int) $requirement->id, $unsatisfied)) {
                    continue;
                }
                $this->ensure(
                    $actor,
                    $organizationId,
                    [
                        'source_key' => PtoWorkspaceQuery::requirementSourceKey((int) $requirement->id),
                        'title' => (string) ($requirement->title !== '' ? $requirement->title : $unsatisfied[(int) $requirement->id]),
                        'project_id' => (int) $set->project_id,
                        'document_set_id' => (int) $set->id,
                        'requirement_id' => (int) $requirement->id,
                    ],
                    PtoWorkspaceTask::ORIGIN_AUTOMATIC,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(User $actor, int $organizationId, array $data): PtoWorkspaceTask
    {
        $sourceKey = trim((string) ($data['source_key'] ?? ''));
        if ($sourceKey === '') {
            throw new BusinessLogicException(trans_message('pto_workspace.errors.source_required'), 422);
        }
        $projectId = (int) ($data['project_id'] ?? 0);
        $this->assertCanEdit($actor, $organizationId, $projectId);

        return $this->ensure($actor, $organizationId, array_merge($data, ['source_key' => $sourceKey]), PtoWorkspaceTask::ORIGIN_MANUAL);
    }

    public function complete(User $actor, int $organizationId, PtoWorkspaceTask $task): PtoWorkspaceTask
    {
        $this->assertCanEdit($actor, $organizationId, (int) $task->project_id);
        if ((int) $task->organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('pto_workspace.errors.task_not_found'), 404);
        }
        $task->forceFill([
            'status' => PtoWorkspaceTask::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        return $task->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensure(User $actor, int $organizationId, array $data, string $origin): PtoWorkspaceTask
    {
        return DB::transaction(function () use ($actor, $organizationId, $data, $origin): PtoWorkspaceTask {
            $sourceKey = (string) $data['source_key'];
            $existing = PtoWorkspaceTask::query()
                ->where('organization_id', $organizationId)
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $updates = [];
                if (array_key_exists('responsible_user_id', $data)) {
                    $updates['responsible_user_id'] = $data['responsible_user_id'] === null ? null : (int) $data['responsible_user_id'];
                }
                if (array_key_exists('due_on', $data)) {
                    $updates['due_on'] = $data['due_on'];
                }
                if (array_key_exists('title', $data) && is_string($data['title']) && $data['title'] !== '') {
                    $updates['title'] = $data['title'];
                }
                if ($updates !== []) {
                    $existing->forceFill($updates)->save();
                }

                return $existing->refresh();
            }

            return PtoWorkspaceTask::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'source_key' => $sourceKey,
                'title' => (string) ($data['title'] ?? trans_message('pto_workspace.categories.task')),
                'responsible_user_id' => isset($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
                'due_on' => $data['due_on'] ?? null,
                'status' => PtoWorkspaceTask::STATUS_OPEN,
                'origin' => $origin,
                'document_set_id' => isset($data['document_set_id']) ? (int) $data['document_set_id'] : null,
                'requirement_id' => isset($data['requirement_id']) ? (int) $data['requirement_id'] : null,
                'created_by_user_id' => (int) $actor->id,
            ]);
        });
    }

    private function assertCanEdit(User $actor, int $organizationId, int $projectId): void
    {
        if ($projectId <= 0) {
            throw new BusinessLogicException(trans_message('pto_workspace.errors.task_not_found'), 404);
        }
        $project = Project::query()->find($projectId);
        if ($project === null || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        if (! $this->authorization->can($actor, 'executive-documentation.edit', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('pto_workspace.errors.forbidden'), 403);
        }
    }
}
