<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Exceptions\ConstructionProgressRevisionConflict;
use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroup;
use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroupElement;
use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroupHistory;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\CompletedWork;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BimConstructionProgressService
{
    private const QUANTITY_TOLERANCE = 0.00000001;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly DesignModelSessionAccessService $projectAccess,
    ) {
    }

    /** @return array<string, mixed> */
    public function index(User $actor, int $organizationId, int $versionId): array
    {
        $version = $this->version($actor, $organizationId, $versionId, 'design-management.models.view');
        $capabilities = $this->capabilities($actor, $organizationId, (int) $version->project_id);
        if (! $capabilities['schedule']) {
            return ['capabilities' => $capabilities, 'groups' => []];
        }

        $groups = BimConstructionProgressGroup::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $version->project_id)
            ->where('version_id', $version->id)
            ->where('status', 'active')
            ->with([
                'task' => fn ($query) => $query->select(['id', 'organization_id', 'schedule_id', 'name', 'planned_end_date', 'quantity'])
                    ->where('organization_id', $organizationId)
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('schedule', fn ($schedule) => $schedule->where('organization_id', $organizationId)->where('project_id', $version->project_id)->where('status', '!=', 'cancelled')),
                'elements' => static fn ($query) => $query->where('status', 'active')->orderBy('element_id'),
            ])
            ->orderBy('id')
            ->get();

        $facts = $capabilities['actual'] ? $this->actualFacts($organizationId, (int) $version->project_id, $groups) : [];

        return [
            'capabilities' => $capabilities,
            'groups' => $groups->map(fn (BimConstructionProgressGroup $group): array => $this->present($group, $capabilities['actual'] ? ($facts[$group->id] ?? $this->emptyActual($group)) : null))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function create(User $actor, int $organizationId, int $versionId, array $data): array
    {
        $version = $this->version($actor, $organizationId, $versionId, 'design-management.models.edit');
        $this->assertManage($actor, $organizationId, (int) $version->project_id);

        $group = DB::transaction(function () use ($actor, $organizationId, $version, $data): BimConstructionProgressGroup {
            DesignArtifactVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $existing = BimConstructionProgressGroup::query()
                ->where('version_id', $version->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing instanceof BimConstructionProgressGroup) {
                if ($existing->status !== 'active' || ! $this->sameCreatePayload($existing, $data)) {
                    throw new DomainException(trans_message('design_construction_progress.errors.idempotency_conflict'));
                }
                $this->task($organizationId, (int) $version->project_id, (int) $existing->schedule_task_id);
                return $existing->load(['task', 'elements' => static fn ($query) => $query->where('status', 'active')->orderBy('element_id')]);
            }

            $task = $this->task($organizationId, (int) $version->project_id, (int) $data['task_id']);
            $elementIds = $this->elementIds($organizationId, (int) $version->project_id, (int) $version->id, $data['element_ids']);
            $this->assertElementsAvailable((int) $version->id, (int) $task->id, $elementIds);
            $group = BimConstructionProgressGroup::query()->create([
                'organization_id' => $organizationId, 'project_id' => $version->project_id, 'version_id' => $version->id,
                'schedule_task_id' => $task->id, 'title' => $data['title'] ?? null, 'floor' => $data['floor'] ?? null,
                'zone' => $data['zone'] ?? null, 'work_kind' => $data['work_kind'] ?? null, 'status' => 'active',
                'revision' => 1, 'idempotency_key' => $data['idempotency_key'], 'created_by' => $actor->id,
            ]);
            $this->activateElements($group, $elementIds);
            $this->recordHistory($group, 'created', $actor->id, null, $elementIds);

            return $group->load(['task', 'elements' => static fn ($query) => $query->where('status', 'active')->orderBy('element_id')]);
        });

        return $this->present($group, null);
    }

    /** @return array<string, mixed> */
    public function update(User $actor, int $organizationId, int $versionId, int $groupId, array $data): array
    {
        $version = $this->version($actor, $organizationId, $versionId, 'design-management.models.edit');
        $this->assertManage($actor, $organizationId, (int) $version->project_id);

        $group = DB::transaction(function () use ($actor, $organizationId, $version, $groupId, $data): BimConstructionProgressGroup {
            DesignArtifactVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $group = $this->activeGroupForUpdate($organizationId, (int) $version->id, $groupId);
            $this->task($organizationId, (int) $version->project_id, (int) $group->schedule_task_id);
            $this->assertRevision($group, (int) $data['revision']);
            $before = $this->snapshot($group, BimConstructionProgressGroupElement::query()->where('group_id', $group->id)->where('status', 'active')->pluck('element_id')->map('intval')->all());
            if (array_key_exists('element_ids', $data)) {
                $elementIds = $this->elementIds($organizationId, (int) $version->project_id, (int) $version->id, $data['element_ids']);
                $this->assertElementsAvailable((int) $version->id, (int) $group->schedule_task_id, $elementIds, $group->id);
                BimConstructionProgressGroupElement::query()->where('group_id', $group->id)->where('status', 'active')->whereNotIn('element_id', $elementIds)
                    ->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);
                $active = BimConstructionProgressGroupElement::query()->where('group_id', $group->id)->where('status', 'active')->pluck('element_id')->map(static fn ($id): int => (int) $id)->all();
                $this->activateElements($group, array_values(array_diff($elementIds, $active)));
            }
            $changes = array_intersect_key($data, array_flip(['title', 'floor', 'zone', 'work_kind']));
            $group->fill($changes + ['updated_by' => $actor->id, 'revision' => $group->revision + 1])->save();
            $this->recordHistory($group, 'updated', $actor->id, null, $before['element_ids'], $before);

            return $group->fresh(['task', 'elements' => static fn ($query) => $query->where('status', 'active')->orderBy('element_id')]);
        });

        return $this->present($group, null);
    }

    public function delete(User $actor, int $organizationId, int $versionId, int $groupId, int $revision, ?string $reason): void
    {
        $version = $this->version($actor, $organizationId, $versionId, 'design-management.models.edit');
        $this->assertManage($actor, $organizationId, (int) $version->project_id);
        DB::transaction(function () use ($actor, $organizationId, $version, $groupId, $revision, $reason): void {
            DesignArtifactVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $group = $this->activeGroupForUpdate($organizationId, (int) $version->id, $groupId);
            $this->assertRevision($group, $revision);
            $now = now();
            $elementIds = BimConstructionProgressGroupElement::query()->where('group_id', $group->id)->where('status', 'active')->pluck('element_id')->map('intval')->all();
            $snapshot = $this->snapshot($group, $elementIds);
            BimConstructionProgressGroupElement::query()->where('group_id', $group->id)->where('status', 'active')->update(['status' => 'ended', 'ended_at' => $now, 'updated_at' => $now]);
            $group->update(['status' => 'ended', 'ended_by' => $actor->id, 'ended_at' => $now, 'end_reason' => $reason, 'updated_by' => $actor->id, 'revision' => $group->revision + 1]);
            $this->recordHistory($group, 'ended', $actor->id, $reason, $elementIds, $snapshot);
        });
    }

    /** @return array{schedule:bool,actual:bool,manage:bool} */
    private function capabilities(User $actor, int $organizationId, int $projectId): array
    {
        $schedule = $this->access->hasModuleAccess($organizationId, 'schedule-management')
            && $this->authorization->can($actor, 'schedule.view', ['organization_id' => $organizationId, 'project_id' => $projectId]);
        $actual = $schedule && $this->access->hasModuleAccess($organizationId, 'workflow-management')
            && $this->authorization->can($actor, 'completed_works.view', ['organization_id' => $organizationId, 'project_id' => $projectId]);

        return [
            'schedule' => $schedule,
            'actual' => $actual,
            'manage' => $schedule && $this->authorization->can($actor, 'design-management.models.edit', ['organization_id' => $organizationId, 'project_id' => $projectId]),
        ];
    }

    private function assertManage(User $actor, int $organizationId, int $projectId): void
    {
        if (! $this->capabilities($actor, $organizationId, $projectId)['manage']) {
            throw new DomainException(trans_message('design_construction_progress.errors.forbidden'));
        }
    }

    private function version(User $actor, int $organizationId, int $versionId, string $permission): DesignArtifactVersion
    {
        $version = DesignArtifactVersion::query()->where('organization_id', $organizationId)->whereKey($versionId)
            ->whereHas('artifact', fn ($artifact) => $artifact->where('organization_id', $organizationId)
                ->whereColumn('design_artifacts.project_id', 'design_artifact_versions.project_id'))
            ->whereHas('artifact.package', fn ($package) => $package->where('organization_id', $organizationId)
                ->whereColumn('design_packages.project_id', 'design_artifact_versions.project_id'))
            ->first();
        if (! $this->access->hasModuleAccess($organizationId, 'design-management') || ! $version instanceof DesignArtifactVersion || strtolower((string) $version->file_format) !== 'ifc'
            || ! $this->projectAccess->canAccessProject($actor, $organizationId, (int) $version->project_id, $permission)) {
            throw new DomainException(trans_message('design_construction_progress.errors.version_not_found'));
        }

        return $version;
    }

    private function task(int $organizationId, int $projectId, int $taskId): ScheduleTask
    {
        $task = ScheduleTask::query()->where('organization_id', $organizationId)->whereKey($taskId)
            ->where('status', '!=', 'cancelled')
            ->whereHas('schedule', static fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $projectId)->where('status', '!=', 'cancelled'))->first();
        if (! $task instanceof ScheduleTask) {
            throw new DomainException(trans_message('design_construction_progress.errors.task_not_found'));
        }

        return $task;
    }

    /** @param array<int, mixed> $elementIds
     * @return array<int, int>
     */
    private function elementIds(int $organizationId, int $projectId, int $versionId, array $elementIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $elementIds)));
        if ($ids === [] || count($ids) > 1000 || min($ids) < 1) {
            throw new DomainException(trans_message('design_construction_progress.errors.element_not_found'));
        }
        $count = DesignIfcModelElement::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('version_id', $versionId)->whereIn('express_id', $ids)->count();
        if ($count !== count($ids)) {
            throw new DomainException(trans_message('design_construction_progress.errors.element_not_found'));
        }

        return $ids;
    }

    /** @param array<int, int> $elementIds */
    private function assertElementsAvailable(int $versionId, int $taskId, array $elementIds, ?int $groupId = null): void
    {
        $query = BimConstructionProgressGroupElement::query()->where('version_id', $versionId)->where('schedule_task_id', $taskId)
            ->where('status', 'active')->whereIn('element_id', $elementIds);
        if ($groupId !== null) {
            $query->where('group_id', '!=', $groupId);
        }
        if ($query->exists()) {
            throw new DomainException(trans_message('design_construction_progress.errors.element_conflict'));
        }
    }

    /** @param array<int, int> $elementIds */
    private function activateElements(BimConstructionProgressGroup $group, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            BimConstructionProgressGroupElement::query()->create([
                'group_id' => $group->id, 'version_id' => $group->version_id, 'schedule_task_id' => $group->schedule_task_id,
                'element_id' => $elementId, 'status' => 'active',
            ]);
        }
    }

    private function activeGroupForUpdate(int $organizationId, int $versionId, int $groupId): BimConstructionProgressGroup
    {
        $group = BimConstructionProgressGroup::query()->where('organization_id', $organizationId)->where('version_id', $versionId)
            ->where('status', 'active')->lockForUpdate()->find($groupId);
        if (! $group instanceof BimConstructionProgressGroup) {
            throw new DomainException(trans_message('design_construction_progress.errors.group_not_found'));
        }

        return $group;
    }

    /** @param array<string, mixed> $data */
    private function sameCreatePayload(BimConstructionProgressGroup $group, array $data): bool
    {
        $existingElements = $group->elements()->where('status', 'active')->pluck('element_id')->map('intval')->sort()->values()->all();
        $requestedElements = collect($data['element_ids'])->map('intval')->sort()->values()->all();

        return (int) $group->schedule_task_id === (int) $data['task_id']
            && $existingElements === $requestedElements
            && $group->title === ($data['title'] ?? null)
            && $group->floor === ($data['floor'] ?? null)
            && $group->zone === ($data['zone'] ?? null)
            && $group->work_kind === ($data['work_kind'] ?? null);
    }

    private function assertRevision(BimConstructionProgressGroup $group, int $revision): void
    {
        if ((int) $group->revision !== $revision) {
            throw new ConstructionProgressRevisionConflict(trans_message('design_construction_progress.errors.revision_conflict'));
        }
    }

    /** @param Collection<int, BimConstructionProgressGroup> $groups
     * @return array<int, array<string, mixed>>
     */
    private function actualFacts(int $organizationId, int $projectId, Collection $groups): array
    {
        $activeGroups = $groups->filter(static fn (BimConstructionProgressGroup $group): bool => $group->task instanceof ScheduleTask);
        $taskIds = $activeGroups->pluck('schedule_task_id')->filter()->map('intval')->unique()->values()->all();
        if ($taskIds === []) {
            return $groups->mapWithKeys(fn (BimConstructionProgressGroup $group): array => [$group->id => $this->emptyActual($group)])->all();
        }
        $works = CompletedWork::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereIn('schedule_task_id', $taskIds)
            ->where('status', 'confirmed')
            ->where(static function ($query) use ($organizationId, $projectId): void {
                $query->whereNull('journal_entry_id')->orWhereHas('journalEntry', static fn ($journal) => $journal
                    ->where('status', JournalEntryStatusEnum::APPROVED)
                    ->whereHas('journal', static fn ($entryJournal) => $entryJournal
                        ->where('organization_id', $organizationId)
                        ->where('project_id', $projectId)));
            })->get(['id', 'schedule_task_id', 'quantity', 'completed_quantity', 'completion_date']);
        $worksByTask = $works->groupBy('schedule_task_id');

        return $groups->mapWithKeys(function (BimConstructionProgressGroup $group) use ($worksByTask): array {
            if (! $group->task instanceof ScheduleTask) {
                return [$group->id => $this->emptyActual($group)];
            }
            return [$group->id => $this->actual($group, $worksByTask->get($group->schedule_task_id, collect()))];
        })->all();
    }

    /** @param Collection<int, CompletedWork> $works
     * @return array<string, mixed>
     */
    private function actual(BimConstructionProgressGroup $group, Collection $works): array
    {
        $confirmed = (float) $works->sum(static fn (CompletedWork $work): float => (float) ($work->completed_quantity ?? 0));
        $planned = (float) ($group->task?->quantity ?? 0);
        $known = $planned > 0;
        $volumeCompleted = $known && $confirmed + self::QUANTITY_TOLERANCE >= $planned;
        $datedWorks = $works->filter(static fn (CompletedWork $work): bool => $work->completion_date !== null)->sortBy('completion_date')->values();
        $accumulated = 0.0;
        $completedAt = null;
        if ($volumeCompleted) {
            foreach ($datedWorks as $work) {
                $accumulated += (float) ($work->completed_quantity ?? 0);
                if ($accumulated + self::QUANTITY_TOLERANCE >= $planned) {
                    $completedAt = $work->completion_date?->format('Y-m-d');
                    break;
                }
            }
        }
        $completed = $volumeCompleted && $completedAt !== null;

        return [
            'confirmed_quantity' => round($confirmed, 4),
            'planned_quantity' => $known ? round($planned, 4) : null,
            'progress_percent' => $known ? min(100, round($confirmed / $planned * 100, 2)) : null,
            'completed' => $completed,
            'completed_at' => $completedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyActual(BimConstructionProgressGroup $group): array
    {
        return $this->actual($group, collect());
    }

    /** @param array<string, mixed>|null $actual
     * @return array<string, mixed>
     */
    private function present(BimConstructionProgressGroup $group, ?array $actual): array
    {
        return [
            'id' => (int) $group->id, 'revision' => (int) $group->revision, 'title' => $group->title,
            'floor' => $group->floor, 'zone' => $group->zone, 'work_kind' => $group->work_kind,
            'task' => $group->task ? ['id' => (int) $group->task->id, 'title' => $group->task->name, 'planned_end_date' => $group->task->planned_end_date?->format('Y-m-d')] : null,
            'element_ids' => $group->elements->pluck('element_id')->map(static fn ($id): int => (int) $id)->values()->all(),
            'planned_date' => $group->task?->planned_end_date?->format('Y-m-d'), 'actual' => $actual,
        ];
    }

    /** @param array<int, int> $elementIds
     * @param array<string, mixed>|null $snapshot
     */
    private function recordHistory(BimConstructionProgressGroup $group, string $action, int $actorId, ?string $reason, array $elementIds, ?array $snapshot = null): void
    {
        BimConstructionProgressGroupHistory::query()->create([
            'group_id' => $group->id, 'organization_id' => $group->organization_id, 'project_id' => $group->project_id,
            'version_id' => $group->version_id, 'action' => $action, 'revision' => $group->revision,
            'snapshot' => $snapshot ?? $this->snapshot($group, $elementIds), 'reason' => $reason, 'actor_id' => $actorId,
        ]);
    }

    /** @param array<int, int> $elementIds
     * @return array<string, mixed>
     */
    private function snapshot(BimConstructionProgressGroup $group, array $elementIds): array
    {
        return ['title' => $group->title, 'floor' => $group->floor, 'zone' => $group->zone, 'work_kind' => $group->work_kind,
            'schedule_task_id' => (int) $group->schedule_task_id, 'element_ids' => array_values($elementIds)];
    }
}
