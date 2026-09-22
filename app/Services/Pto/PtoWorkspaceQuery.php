<?php

declare(strict_types=1);

namespace App\Services\Pto;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\MaterialConsumptionFact;
use App\Models\PtoWorkspaceTask;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class PtoWorkspaceQuery
{
    public const CATEGORY_RISK = 'risk';

    public const CATEGORY_PROBLEM = 'problem';

    public const CATEGORY_BLOCKER = 'blocker';

    public const CATEGORY_TASK = 'task';

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_HORIZON_DAYS = 14;

    public function __construct(
        private readonly UserProjectAccessService $projectAccess,
        private readonly AuthorizationService $authorization,
        private readonly ExecutiveDocumentRequirementsService $requirements,
        private readonly ExecutiveDocumentProfileRegistry $profiles,
    ) {
    }

    public static function requirementSourceKey(int $requirementId): string
    {
        return 'requirement:'.$requirementId;
    }

    public static function remarkSourceKey(int $remarkId): string
    {
        return 'remark:'.$remarkId;
    }

    public static function transmittalSourceKey(int $transmittalId): string
    {
        return 'transmittal:'.$transmittalId;
    }

    public static function scheduleSourceKey(int $taskId): string
    {
        return 'schedule:'.$taskId;
    }

    public static function workSourceKey(int $workId): string
    {
        return 'work:'.$workId;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function workQueue(User $actor, int $organizationId, array $filters = []): array
    {
        $this->assertCanView($actor, $organizationId, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        $projectIds = $this->accessibleProjectIds($actor, $organizationId, $filters);
        $computedAt = now()->toIso8601String();
        $items = $this->collectItems($actor, $organizationId, $projectIds, $filters, $computedAt);
        $summary = $this->summary($items, $actor, $computedAt);
        $filtered = array_values(array_filter($items, fn (array $item): bool => $this->matchesFilters($item, $actor, $filters)));
        usort($filtered, $this->sortItems(...));
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), self::MAX_PER_PAGE);
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return [
            'paginator' => new Paginator($slice, $total, $perPage, $page, [
                'path' => '/api/v1/admin/pto/work-queue',
            ]),
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function completeness(User $actor, int $organizationId, array $filters = []): array
    {
        $this->assertCanView($actor, $organizationId, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        $projectIds = $this->accessibleProjectIds($actor, $organizationId, $filters);
        $computedAt = now()->toIso8601String();
        $rows = $this->completenessRows($organizationId, $projectIds, $computedAt);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), self::MAX_PER_PAGE);
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $queue = $this->collectItems($actor, $organizationId, $projectIds, $filters, $computedAt);

        return [
            'paginator' => new Paginator($slice, $total, $perPage, $page, [
                'path' => '/api/v1/admin/pto/completeness',
            ]),
            'summary' => $this->summary($queue, $actor, $computedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function accessibleProjectIds(User $actor, int $organizationId, array $filters = []): array
    {
        $ids = $this->projectAccess->queryAccessibleProjects($actor, $organizationId)
            ->orderBy('id')
            ->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $requested = (int) ($filters['project_id'] ?? 0);
        if ($requested > 0) {
            return in_array($requested, $ids, true) ? [$requested] : [];
        }

        return $ids;
    }

    private function assertCanView(User $actor, int $organizationId, ?int $projectId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId || ! $actor->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        $context = ['organization_id' => $organizationId, 'strict_project_scope' => true];
        if ($projectId !== null && $projectId > 0) {
            $context['project_id'] = $projectId;
        }
        if (! $this->authorization->can($actor, 'executive-documentation.view', $context)) {
            throw new BusinessLogicException(trans_message('pto_workspace.errors.forbidden'), 403);
        }
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function collectItems(User $actor, int $organizationId, array $projectIds, array $filters, string $computedAt): array
    {
        if ($projectIds === []) {
            return [];
        }
        $horizonDays = min(max((int) ($filters['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS), 1), 90);
        $today = Carbon::now()->startOfDay();
        $sets = ExecutiveDocumentSet::query()
            ->with(['project:id,name', 'documents.versions', 'documents.remarks', 'documents.workType', 'transmittal'])
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->get();
        $seen = [];
        $items = [];
        foreach ($this->blockerItems($sets, $computedAt) as $item) {
            $this->pushUnique($items, $seen, $item);
        }
        foreach ($this->problemItems($sets, $organizationId, $projectIds, $computedAt) as $item) {
            $this->pushUnique($items, $seen, $item);
        }
        foreach ($this->riskItems($organizationId, $projectIds, $sets, $today, $horizonDays, $computedAt) as $item) {
            $this->pushUnique($items, $seen, $item);
        }
        foreach ($this->taskItems($organizationId, $projectIds, $computedAt) as $item) {
            $this->pushUnique($items, $seen, $item);
        }

        return $this->withLinks($items, $organizationId);
    }

    /**
     * @param  Collection<int, ExecutiveDocumentSet>  $sets
     * @return list<array<string, mixed>>
     */
    private function blockerItems(Collection $sets, string $computedAt): array
    {
        $items = [];
        foreach ($sets as $set) {
            $readiness = $this->requirements->readiness($set);
            $tasks = $this->tasksBySource($set);
            foreach ($readiness['blockers'] as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }
                $requirementId = (int) ($blocker['requirement_id'] ?? $blocker['target']['id'] ?? 0);
                $sourceKey = $requirementId > 0
                    ? self::requirementSourceKey($requirementId)
                    : 'set:'.$set->id.':'.(string) ($blocker['code'] ?? 'requirements_not_configured');
                $issues = is_array($blocker['issues'] ?? null) ? $blocker['issues'] : [];
                $missingSignature = $this->hasIssue($issues, 'required_signatory_missing');
                $task = $tasks->get($sourceKey);
                $items[] = $this->item(
                    sourceKey: $sourceKey,
                    category: self::CATEGORY_BLOCKER,
                    title: (string) ($blocker['message'] ?? trans_message('pto_workspace.categories.blocker')),
                    set: $set,
                    computedAt: $computedAt,
                    extras: [
                        'source' => trans_message('pto_workspace.sources.requirement'),
                        'priority_basis' => $this->priorityBasis($task, 'condition'),
                        'next_action' => $missingSignature
                            ? $this->action('collect_signatures')
                            : $this->action('prepare_document'),
                        'requirement_id' => $requirementId > 0 ? $requirementId : null,
                        'document_id' => isset($blocker['target']['id']) && ($blocker['target']['type'] ?? '') === 'executive_document'
                            ? (int) $blocker['target']['id']
                            : null,
                        'version_id' => isset($blocker['target']['version_id']) ? (int) $blocker['target']['version_id'] : null,
                        'blocker_code' => $blocker['code'] ?? null,
                        'responsible' => $this->person($task?->responsible),
                        'waiting_for' => $missingSignature ? [
                            'id' => null,
                            'name' => null,
                            'reason' => trans_message('pto_workspace.waiting.signer'),
                        ] : null,
                        'due_on' => $task?->due_on?->format('Y-m-d'),
                    ],
                );
            }
        }

        return $items;
    }

    /**
     * @param  Collection<int, ExecutiveDocumentSet>  $sets
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    private function problemItems(Collection $sets, int $organizationId, array $projectIds, string $computedAt): array
    {
        $items = [];
        $transmittals = ExecutiveDocumentTransmittal::query()
            ->where('organization_id', $organizationId)
            ->whereIn('document_set_id', $sets->pluck('id')->all())
            ->where('status', 'returned')
            ->orderByDesc('id')
            ->get();
        $setsById = $sets->keyBy('id');
        foreach ($transmittals as $transmittal) {
            $set = $setsById->get($transmittal->document_set_id);
            if ($set === null) {
                continue;
            }
            $items[] = $this->item(
                sourceKey: self::transmittalSourceKey((int) $transmittal->id),
                category: self::CATEGORY_PROBLEM,
                title: trans_message('pto_workspace.sources.transmittal'),
                set: $set,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.transmittal'),
                    'priority_basis' => $this->priorityBasis(null, 'customer_return'),
                    'next_action' => $this->action('retransmit'),
                    'transmittal_id' => (int) $transmittal->id,
                    'version_id' => $this->manifestVersionId($transmittal),
                    'waiting_for' => [
                        'id' => $transmittal->decision_by ? (int) $transmittal->decision_by : null,
                        'name' => null,
                        'reason' => trans_message('pto_workspace.waiting.customer'),
                    ],
                ],
            );
        }
        $remarks = ExecutiveDocumentRemark::query()
            ->with(['document.documentSet.project'])
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'answered', 'returned'])
            ->whereHas('document', static fn ($query) => $query->whereIn('project_id', $projectIds))
            ->orderBy('id')
            ->get();
        foreach ($remarks as $remark) {
            $set = $remark->document?->documentSet;
            if ($set === null || ! in_array((int) $set->project_id, $projectIds, true)) {
                continue;
            }
            $items[] = $this->item(
                sourceKey: self::remarkSourceKey((int) $remark->id),
                category: self::CATEGORY_PROBLEM,
                title: (string) $remark->body,
                set: $set,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.remark'),
                    'priority_basis' => $this->priorityBasis(null, 'condition'),
                    'next_action' => $this->action($remark->status->value === 'open' ? 'answer_remark' : 'review_version'),
                    'document_id' => (int) $remark->document_id,
                    'version_id' => $remark->version_id ? (int) $remark->version_id : null,
                    'waiting_for' => [
                        'id' => null,
                        'name' => null,
                        'reason' => trans_message('pto_workspace.waiting.reviewer'),
                    ],
                ],
            );
        }

        return $items;
    }

    /**
     * @param  list<int>  $projectIds
     * @param  Collection<int, ExecutiveDocumentSet>  $sets
     * @return list<array<string, mixed>>
     */
    private function riskItems(int $organizationId, array $projectIds, Collection $sets, Carbon $today, int $horizonDays, string $computedAt): array
    {
        $items = [];
        $horizon = $today->copy()->addDays($horizonDays);
        $readyProjects = [];
        foreach ($sets as $set) {
            $readiness = $this->requirements->readiness($set);
            if ($readiness['ready'] === true) {
                $readyProjects[(int) $set->project_id] = true;
            }
        }
        $setsByProject = $sets->groupBy(static fn (ExecutiveDocumentSet $set): int => (int) $set->project_id);
        $tasks = ScheduleTask::query()
            ->with(['schedule:id,project_id,name', 'assignedUser:id,name'])
            ->where('organization_id', $organizationId)
            ->whereHas('schedule', static fn ($query) => $query->whereIn('project_id', $projectIds))
            ->whereDate('planned_start_date', '>=', $today->toDateString())
            ->whereDate('planned_start_date', '<=', $horizon->toDateString())
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();
        foreach ($tasks as $task) {
            $projectId = (int) ($task->schedule?->project_id ?? 0);
            if ($projectId <= 0 || isset($readyProjects[$projectId])) {
                continue;
            }
            $set = $setsByProject->get($projectId)?->first();
            $dueOn = $task->planned_start_date?->format('Y-m-d');
            $items[] = $this->item(
                sourceKey: self::scheduleSourceKey((int) $task->id),
                category: self::CATEGORY_RISK,
                title: (string) $task->name,
                set: $set,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.schedule'),
                    'priority_basis' => [
                        'type' => 'due_date',
                        'label' => trans_message('pto_workspace.priority.upcoming'),
                        'due_on' => $dueOn,
                        'overdue' => false,
                    ],
                    'next_action' => $this->action('prepare_document'),
                    'schedule_task_id' => (int) $task->id,
                    'project' => $set?->project ? ['id' => (int) $set->project->id, 'name' => $set->project->name] : [
                        'id' => $projectId,
                        'name' => null,
                    ],
                    'due_on' => $dueOn,
                    'data_complete' => true,
                    'responsible' => $this->person($task->assignedUser ?? null),
                ],
            );
        }
        $undatedTasks = ScheduleTask::query()
            ->with(['schedule:id,project_id,name'])
            ->where('organization_id', $organizationId)
            ->whereHas('schedule', static fn ($query) => $query->whereIn('project_id', $projectIds))
            ->whereNull('planned_start_date')
            ->orderBy('id')
            ->get();
        foreach ($undatedTasks as $task) {
            $projectId = (int) ($task->schedule?->project_id ?? 0);
            if ($projectId <= 0 || isset($readyProjects[$projectId])) {
                continue;
            }
            $set = $setsByProject->get($projectId)?->first();
            $items[] = $this->item(
                sourceKey: self::scheduleSourceKey((int) $task->id),
                category: self::CATEGORY_TASK,
                title: (string) $task->name,
                set: $set,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.schedule'),
                    'priority_basis' => [
                        'type' => 'incomplete_data',
                        'label' => trans_message('pto_workspace.priority.incomplete'),
                        'due_on' => null,
                        'overdue' => false,
                    ],
                    'next_action' => $this->action('review_basis'),
                    'schedule_task_id' => (int) $task->id,
                    'data_complete' => false,
                    'due_on' => null,
                    'due_label' => trans_message('pto_workspace.due_not_set'),
                    'project' => $set?->project ? ['id' => (int) $set->project->id, 'name' => $set->project->name] : [
                        'id' => $projectId,
                        'name' => null,
                    ],
                ],
            );
        }
        $works = CompletedWork::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('planning_status', CompletedWork::PLANNING_REQUIRES_SCHEDULE)
            ->whereNull('schedule_task_id')
            ->orderBy('id')
            ->get(['id', 'project_id', 'notes', 'description', 'schedule_task_id', 'journal_entry_id']);
        foreach ($works as $work) {
            $projectId = (int) $work->project_id;
            if (isset($readyProjects[$projectId])) {
                continue;
            }
            $set = $setsByProject->get($projectId)?->first();
            $items[] = $this->item(
                sourceKey: self::workSourceKey((int) $work->id),
                category: self::CATEGORY_TASK,
                title: trans_message('pto_workspace.incomplete_data'),
                set: $set,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.work'),
                    'priority_basis' => [
                        'type' => 'incomplete_data',
                        'label' => trans_message('pto_workspace.priority.incomplete'),
                        'due_on' => null,
                        'overdue' => false,
                    ],
                    'next_action' => $this->action('review_basis'),
                    'completed_work_id' => (int) $work->id,
                    'journal_entry_id' => $work->journal_entry_id ? (int) $work->journal_entry_id : null,
                    'schedule_task_id' => $work->schedule_task_id ? (int) $work->schedule_task_id : null,
                    'data_complete' => false,
                    'due_on' => null,
                    'due_label' => trans_message('pto_workspace.due_not_set'),
                    'project' => $set?->project ? ['id' => (int) $set->project->id, 'name' => $set->project->name] : [
                        'id' => $projectId,
                        'name' => null,
                    ],
                ],
            );
        }

        return $items;
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    private function taskItems(int $organizationId, array $projectIds, string $computedAt): array
    {
        $tasks = PtoWorkspaceTask::query()
            ->with(['responsible:id,name', 'documentSet.project:id,name'])
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('status', PtoWorkspaceTask::STATUS_OPEN)
            ->orderBy('id')
            ->get();
        $items = [];
        foreach ($tasks as $task) {
            $items[] = $this->item(
                sourceKey: (string) $task->source_key,
                category: self::CATEGORY_TASK,
                title: (string) $task->title,
                set: $task->documentSet,
                computedAt: $computedAt,
                extras: [
                    'source' => trans_message('pto_workspace.sources.task'),
                    'priority_basis' => $this->priorityBasis($task, 'assigned'),
                    'next_action' => $this->action('open_task'),
                    'requirement_id' => $task->requirement_id ? (int) $task->requirement_id : null,
                    'responsible' => $this->person($task->responsible),
                    'due_on' => $task->due_on?->format('Y-m-d'),
                    'task_id' => (int) $task->id,
                    'project' => [
                        'id' => (int) $task->project_id,
                        'name' => $task->documentSet?->project?->name,
                    ],
                ],
            );
        }

        return $items;
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    private function completenessRows(int $organizationId, array $projectIds, string $computedAt): array
    {
        if ($projectIds === []) {
            return [];
        }
        $sets = ExecutiveDocumentSet::query()
            ->with(['project:id,name', 'documents.versions', 'documents.remarks', 'transmittal'])
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $requirements = ExecutiveDocumentRequirement::query()
            ->with(['projectLocation:id,name', 'completedWork:id,notes,journal_entry_id', 'workType:id,name'])
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->get();
        $readinessBySet = [];
        foreach ($sets as $set) {
            $readinessBySet[(int) $set->id] = $this->requirements->readiness($set);
        }
        $rows = [];
        foreach ($requirements as $requirement) {
            $set = $sets->get($requirement->document_set_id);
            if ($set === null) {
                continue;
            }
            $readiness = $readinessBySet[(int) $set->id];
            $blocker = collect($readiness['blockers'])->first(
                static fn ($item): bool => is_array($item) && (int) ($item['requirement_id'] ?? 0) === (int) $requirement->id
            );
            $profile = $this->profiles->find($requirement->profile_type);
            $document = $set->documents->first(
                static fn (ExecutiveDocument $document): bool => $document->document_type->value === $requirement->profile_type
            );
            $latest = $document?->versions->sortByDesc('id')->first();
            $openRemarks = $document?->remarks->filter(
                static fn ($remark): bool => in_array($remark->status->value, ['open', 'answered', 'returned'], true)
            )->count() ?? 0;
            $sourceKey = self::requirementSourceKey((int) $requirement->id);
            $missingSignature = is_array($blocker) && $this->hasIssue(is_array($blocker['issues'] ?? null) ? $blocker['issues'] : [], 'required_signatory_missing');
            $rows[] = [
                'source_key' => $sourceKey,
                'requirement_id' => (int) $requirement->id,
                'document_set_id' => (int) $set->id,
                'project' => ['id' => (int) $set->project_id, 'name' => $set->project?->name],
                'title' => (string) $requirement->title,
                'profile_type' => $requirement->profile_type,
                'profile_label' => is_array($profile) ? (string) ($profile['label'] ?? $requirement->profile_type) : $requirement->profile_type,
                'location' => $requirement->projectLocation ? [
                    'id' => (int) $requirement->projectLocation->id,
                    'name' => $requirement->projectLocation->name,
                ] : null,
                'work' => $requirement->workType?->name ?? $requirement->completedWork?->notes,
                'version' => $latest?->version_number,
                'version_id' => $latest?->id ? (int) $latest->id : null,
                'document_id' => $document?->id ? (int) $document->id : null,
                'review_status' => $document?->status?->label() ?? 'Документ не создан',
                'signatures_status' => $missingSignature ? 'Нет обязательной подписи' : ($blocker ? (string) ($blocker['message'] ?? 'Не готово') : 'Подписи собраны'),
                'transmittal_status' => $set->transmittal?->status ?? $set->status->label(),
                'customer_decision' => $set->transmittal?->status,
                'open_remarks' => $openRemarks,
                'ready' => $blocker === null && $requirement->applicability === 'required',
                'next_action' => $missingSignature
                    ? $this->action('collect_signatures')
                    : ($document === null ? $this->action('prepare_document') : $this->action('review_version')),
                'computed_at' => $computedAt,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function summary(array $items, User $actor, string $computedAt): array
    {
        $counts = [
            'risk' => 0,
            'problem' => 0,
            'blocker' => 0,
            'task' => 0,
            'mine' => 0,
            'waiting' => 0,
            'unassigned' => 0,
            'overdue' => 0,
            'returns' => 0,
            'incomplete' => 0,
        ];
        $seen = [];
        foreach ($items as $item) {
            $key = (string) $item['source_key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $category = (string) $item['category'];
            if (isset($counts[$category])) {
                $counts[$category]++;
            }
            if ($this->matchesFilters($item, $actor, ['queue' => 'mine'])) {
                $counts['mine']++;
            }
            if ($this->matchesFilters($item, $actor, ['queue' => 'waiting'])) {
                $counts['waiting']++;
            }
            if ($this->matchesFilters($item, $actor, ['queue' => 'unassigned'])) {
                $counts['unassigned']++;
            }
            if ($this->matchesFilters($item, $actor, ['queue' => 'overdue'])) {
                $counts['overdue']++;
            }
            if ($this->matchesFilters($item, $actor, ['queue' => 'returns'])) {
                $counts['returns']++;
            }
            if ($item['data_complete'] === false) {
                $counts['incomplete']++;
            }
        }

        return array_merge($counts, ['computed_at' => $computedAt]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $item, User $actor, array $filters): bool
    {
        if (isset($filters['category']) && $filters['category'] !== '' && $item['category'] !== $filters['category']) {
            return false;
        }
        if (isset($filters['responsible_user_id']) && (int) $filters['responsible_user_id'] > 0) {
            $responsibleId = (int) ($item['responsible']['id'] ?? 0);
            if ($responsibleId !== (int) $filters['responsible_user_id']) {
                return false;
            }
        }
        if (isset($filters['action']) && $filters['action'] !== '' && ($item['next_action']['key'] ?? null) !== $filters['action']) {
            return false;
        }
        $queue = (string) ($filters['queue'] ?? '');
        $dueOn = $item['due_on'] ?? null;
        $overdue = (bool) ($item['priority_basis']['overdue'] ?? false);

        return match ($queue) {
            'mine' => (int) ($item['responsible']['id'] ?? 0) === (int) $actor->id,
            'waiting' => is_array($item['waiting_for'] ?? null),
            'unassigned' => ($item['responsible'] ?? null) === null,
            'overdue' => $overdue && is_string($dueOn) && $dueOn !== '',
            'risks' => $item['category'] === self::CATEGORY_RISK,
            'returns' => $item['category'] === self::CATEGORY_PROBLEM && str_starts_with((string) $item['source_key'], 'transmittal:'),
            'incomplete' => $item['data_complete'] === false,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sortItems(array $left, array $right): int
    {
        $leftOverdue = (int) ($left['priority_basis']['overdue'] ?? false);
        $rightOverdue = (int) ($right['priority_basis']['overdue'] ?? false);
        if ($leftOverdue !== $rightOverdue) {
            return $rightOverdue <=> $leftOverdue;
        }
        $order = [self::CATEGORY_BLOCKER => 0, self::CATEGORY_PROBLEM => 1, self::CATEGORY_RISK => 2, self::CATEGORY_TASK => 3];
        $byCategory = ($order[$left['category']] ?? 9) <=> ($order[$right['category']] ?? 9);
        if ($byCategory !== 0) {
            return $byCategory;
        }
        $leftDue = (string) ($left['due_on'] ?? '9999-12-31');
        $rightDue = (string) ($right['due_on'] ?? '9999-12-31');
        if ($leftDue !== $rightDue) {
            return $leftDue <=> $rightDue;
        }

        return strcmp((string) $left['source_key'], (string) $right['source_key']);
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function item(string $sourceKey, string $category, string $title, ?ExecutiveDocumentSet $set, string $computedAt, array $extras): array
    {
        $dueOn = $extras['due_on'] ?? null;
        $dueLabel = $extras['due_label'] ?? ($dueOn ? (string) $dueOn : trans_message('pto_workspace.due_not_set'));

        return array_merge([
            'source_key' => $sourceKey,
            'category' => $category,
            'title' => $title,
            'project' => $set?->project ? ['id' => (int) $set->project->id, 'name' => $set->project->name] : ($extras['project'] ?? null),
            'document_set_id' => $set?->id ? (int) $set->id : null,
            'document_id' => $extras['document_id'] ?? null,
            'version_id' => $extras['version_id'] ?? null,
            'requirement_id' => $extras['requirement_id'] ?? null,
            'completed_work_id' => $extras['completed_work_id'] ?? null,
            'journal_entry_id' => $extras['journal_entry_id'] ?? null,
            'work_volume_statement_id' => null,
            'design_revision_id' => null,
            'material_batch_id' => null,
            'transmittal_id' => $extras['transmittal_id'] ?? null,
            'schedule_task_id' => $extras['schedule_task_id'] ?? null,
            'responsible' => $extras['responsible'] ?? null,
            'waiting_for' => $extras['waiting_for'] ?? null,
            'due_on' => $dueOn,
            'due_label' => $dueLabel,
            'data_complete' => $extras['data_complete'] ?? true,
            'source' => $extras['source'] ?? trans_message('pto_workspace.sources.requirement'),
            'priority_basis' => $extras['priority_basis'] ?? $this->priorityBasis(null, 'condition'),
            'next_action' => $extras['next_action'] ?? $this->action('prepare_document'),
            'computed_at' => $computedAt,
            'task_id' => $extras['task_id'] ?? null,
            'blocker_code' => $extras['blocker_code'] ?? null,
        ], []);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $item
     */
    private function pushUnique(array &$items, array &$seen, array $item): void
    {
        $key = (string) $item['source_key'];
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $items[] = $item;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withLinks(array $items, int $organizationId): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map(static fn (array $item): int => (int) ($item['document_id'] ?? 0), $items))));
        $workIds = array_values(array_unique(array_filter(array_map(static fn (array $item): int => (int) ($item['completed_work_id'] ?? 0), $items))));
        $setIds = array_values(array_unique(array_filter(array_map(static fn (array $item): int => (int) ($item['document_set_id'] ?? 0), $items))));
        $facts = $documentIds === [] ? collect() : MaterialConsumptionFact::query()
            ->where('organization_id', $organizationId)
            ->whereIn('quality_document_id', $documentIds)
            ->orderByDesc('id')
            ->get(['id', 'quality_document_id', 'batch_number']);
        $factsByDocument = $facts->groupBy(static fn ($fact): int => (int) $fact->quality_document_id);
        $statements = $setIds === [] ? collect() : WorkVolumeStatement::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', array_values(array_unique(array_filter(array_map(static fn (array $item): int => (int) ($item['project']['id'] ?? 0), $items)))))
            ->where('status', WorkVolumeStatement::STATUS_APPROVED)
            ->orderByDesc('id')
            ->get(['id', 'project_id']);
        $statementByProject = $statements->groupBy(static fn ($statement): int => (int) $statement->project_id);
        $targets = [];
        foreach ($documentIds as $id) {
            $targets[] = ['type' => 'executive_document', 'id' => $id];
        }
        foreach ($workIds as $id) {
            $targets[] = ['type' => 'completed_work', 'id' => $id];
        }
        $links = $targets === [] ? collect() : DesignSourceLink::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($query) use ($documentIds, $workIds): void {
                if ($documentIds !== []) {
                    $query->orWhere(function ($inner) use ($documentIds): void {
                        $inner->where('target_type', 'executive_document')->whereIn('target_id', $documentIds);
                    });
                }
                if ($workIds !== []) {
                    $query->orWhere(function ($inner) use ($workIds): void {
                        $inner->where('target_type', 'completed_work')->whereIn('target_id', $workIds);
                    });
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'source_version_id', 'target_type', 'target_id']);

        return array_map(static function (array $item) use ($factsByDocument, $statementByProject, $links): array {
            $documentId = (int) ($item['document_id'] ?? 0);
            $workId = (int) ($item['completed_work_id'] ?? 0);
            $projectId = (int) ($item['project']['id'] ?? 0);
            $fact = $documentId > 0 ? $factsByDocument->get($documentId)?->first() : null;
            $statement = $projectId > 0 ? $statementByProject->get($projectId)?->first() : null;
            $design = $links->first(static function ($link) use ($documentId, $workId): bool {
                $targetId = (int) $link->target_id;
                return ($link->target_type === 'executive_document' && $targetId === $documentId && $documentId > 0)
                    || ($link->target_type === 'completed_work' && $targetId === $workId && $workId > 0);
            });
            $item['material_batch_id'] = $fact?->id ? (int) $fact->id : null;
            $item['work_volume_statement_id'] = $statement?->id ? (int) $statement->id : null;
            $item['design_revision_id'] = $design?->source_version_id ? (int) $design->source_version_id : null;
            $item['links'] = [
                'document_set_id' => $item['document_set_id'],
                'document_id' => $item['document_id'],
                'version_id' => $item['version_id'],
                'requirement_id' => $item['requirement_id'],
                'completed_work_id' => $item['completed_work_id'],
                'journal_entry_id' => $item['journal_entry_id'],
                'work_volume_statement_id' => $item['work_volume_statement_id'],
                'design_revision_id' => $item['design_revision_id'],
                'material_batch_id' => $item['material_batch_id'],
                'transmittal_id' => $item['transmittal_id'],
                'schedule_task_id' => $item['schedule_task_id'],
            ];

            return $item;
        }, $items);
    }

    /**
     * @return Collection<string, PtoWorkspaceTask>
     */
    private function tasksBySource(ExecutiveDocumentSet $set): Collection
    {
        return PtoWorkspaceTask::query()
            ->with('responsible:id,name')
            ->where('organization_id', $set->organization_id)
            ->where('document_set_id', $set->id)
            ->get()
            ->keyBy(static fn (PtoWorkspaceTask $task): string => (string) $task->source_key);
    }

    /**
     * @return array{type: string, label: string, due_on: string|null, overdue: bool}
     */
    private function priorityBasis(?PtoWorkspaceTask $task, string $type): array
    {
        $dueOn = $task?->due_on?->format('Y-m-d');
        $overdue = $dueOn !== null && $dueOn < Carbon::now()->toDateString();
        $label = match (true) {
            $overdue => trans_message('pto_workspace.priority.overdue'),
            $dueOn !== null => trans_message('pto_workspace.priority.due_date', ['date' => $dueOn]),
            $type === 'customer_return' => trans_message('pto_workspace.priority.customer_return'),
            $type === 'assigned' => trans_message('pto_workspace.priority.assigned'),
            default => trans_message('pto_workspace.priority.condition'),
        };

        return [
            'type' => $dueOn === null ? ($type === 'condition' ? 'condition' : $type) : 'due_date',
            'label' => $label,
            'due_on' => $dueOn,
            'overdue' => $overdue,
        ];
    }

    /**
     * @return array{key: string, label: string, enabled: bool}
     */
    private function action(string $key): array
    {
        return [
            'key' => $key,
            'label' => trans_message('pto_workspace.actions.'.$key),
            'enabled' => true,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => (int) $user->id, 'name' => (string) $user->name];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function hasIssue(array $issues, string $code): bool
    {
        foreach ($issues as $issue) {
            if (is_array($issue) && ($issue['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    private function manifestVersionId(ExecutiveDocumentTransmittal $transmittal): ?int
    {
        $manifest = is_array($transmittal->manifest) ? $transmittal->manifest : [];
        foreach ((array) ($manifest['versions'] ?? $manifest['expected_versions'] ?? []) as $row) {
            if (is_array($row) && isset($row['version_id'])) {
                return (int) $row['version_id'];
            }
        }

        return null;
    }
}
