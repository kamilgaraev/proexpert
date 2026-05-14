<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlanAssignment;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Services\LookaheadPlanningService;
use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\Enums\Schedule\ScheduleStatusEnum;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MobileProjectScheduleService
{
    public function __construct(
        private readonly LookaheadPlanningService $lookaheadPlanningService,
        private readonly SiteRequestService $siteRequestService,
        private readonly QualityDefectService $qualityDefectService,
    ) {
    }

    public function list(User $user, ?int $projectId): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $project = $this->resolveAccessibleProject($user, $organizationId, $projectId);

        $schedules = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::COMPLETED->value),
                'tasks as overdue_tasks_count' => fn($query) => $query
                    ->whereDate('planned_end_date', '<', now()->toDateString())
                    ->where('status', '!=', TaskStatusEnum::COMPLETED->value),
            ])
            ->orderByDesc('created_at')
            ->get();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'summary' => [
                'total_schedules' => $schedules->count(),
                'active_schedules' => $schedules
                    ->filter(fn(ProjectSchedule $schedule): bool => $this->resolveStatusValue($schedule->status) === ScheduleStatusEnum::ACTIVE->value)
                    ->count(),
                'completed_schedules' => $schedules
                    ->filter(fn(ProjectSchedule $schedule): bool => $this->resolveStatusValue($schedule->status) === ScheduleStatusEnum::COMPLETED->value)
                    ->count(),
                'average_progress_percent' => round((float) ($schedules->avg('overall_progress_percent') ?? 0), 1),
            ],
            'schedules' => $this->mapSchedules($schedules),
        ];
    }

    public function show(User $user, int $scheduleId): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $schedule = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->where('id', $scheduleId)
            ->with([
                'project:id,name',
                'tasks' => fn($query) => $query
                    ->with(['measurementUnit:id,name,short_name'])
                    ->withCount('childTasks')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::COMPLETED->value),
                'tasks as in_progress_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::IN_PROGRESS->value),
                'tasks as overdue_tasks_count' => fn($query) => $query
                    ->whereDate('planned_end_date', '<', now()->toDateString())
                    ->where('status', '!=', TaskStatusEnum::COMPLETED->value),
            ])
            ->first();

        if (!$schedule) {
            throw new DomainException(trans_message('mobile_schedule.errors.load_failed'));
        }

        $this->assertProjectAccess($user, $organizationId, (int) $schedule->project_id);

        return [
            'project' => [
                'id' => $schedule->project?->id,
                'name' => $schedule->project?->name,
            ],
            'schedule' => $this->mapSchedule($schedule),
            'summary' => [
                'tasks_count' => (int) ($schedule->tasks_count ?? 0),
                'completed_tasks_count' => (int) ($schedule->completed_tasks_count ?? 0),
                'in_progress_tasks_count' => (int) ($schedule->in_progress_tasks_count ?? 0),
                'overdue_tasks_count' => (int) ($schedule->overdue_tasks_count ?? 0),
            ],
            'tasks' => $this->mapTasks($schedule->tasks),
        ];
    }

    public function dailyPlans(User $user, ?int $projectId): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $project = $this->resolveAccessibleProject($user, $organizationId, $projectId);

        $dailyPlans = DailyWorkPlan::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->whereIn('status', ['published', 'in_progress', 'submitted'])
            ->with([
                'schedule:id,name',
                'assignments.scheduleTask:id,name',
                'assignments.journalEntry:id,status',
                'assignments.lookaheadPlanTask.constraints',
            ])
            ->orderBy('work_date')
            ->orderBy('id')
            ->get();

        return $dailyPlans
            ->map(fn (DailyWorkPlan $dailyPlan): array => $this->mapDailyPlan($dailyPlan))
            ->values()
            ->all();
    }

    public function recordDailyPlanFact(User $user, int $assignmentId, array $data): array
    {
        $assignment = $this->findAccessibleAssignment($user, $assignmentId);
        $schedule = ProjectSchedule::query()->findOrFail($assignment->schedule_id);

        return $this->mapDailyAssignment($this->lookaheadPlanningService->recordAssignmentFact(
            $schedule,
            $assignment,
            (int) $user->id,
            $data
        ));
    }

    public function submitDailyPlan(User $user, int $dailyPlanId, array $data): array
    {
        $dailyPlan = $this->findAccessibleDailyPlan($user, $dailyPlanId);
        $schedule = ProjectSchedule::query()->findOrFail($dailyPlan->schedule_id);

        return $this->mapDailyPlan($this->lookaheadPlanningService->submitDailyPlan($schedule, $dailyPlan, $data));
    }

    public function createLinkedActionForConstraint(User $user, int $constraintId, array $data): array
    {
        $constraint = $this->findAccessibleConstraint($user, $constraintId);

        if ($constraint->status !== 'open') {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_not_open'));
        }

        $existing = $this->findExistingLinkedAction($constraint);

        if ($existing !== null) {
            return $existing + ['created' => false];
        }

        return DB::transaction(function () use ($user, $constraint, $data): array {
            $action = $constraint->constraint_type === 'quality_blocker'
                ? $this->createQualityDefectFromConstraint($user, $constraint, $data)
                : $this->createSiteRequestFromConstraint($user, $constraint, $data);

            $metadata = $constraint->metadata ?? [];
            $metadata['linked_action'] = [
                'type' => $action['type'],
                'id' => $action['entity']['id'],
                'created_by_user_id' => (int) $user->id,
                'created_at' => now()->toIso8601String(),
            ];
            $constraint->update(['metadata' => $metadata]);

            return $action + ['created' => true];
        });
    }

    private function resolveAccessibleProject(User $user, int $organizationId, ?int $projectId): Project
    {
        if (($projectId ?? 0) <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
        }

        return $this->findAccessibleProject($user, $organizationId, $projectId)
            ?? throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
    }

    private function assertProjectAccess(User $user, int $organizationId, int $projectId): void
    {
        if (!$this->findAccessibleProject($user, $organizationId, $projectId)) {
            throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
        }
    }

    private function findAccessibleProject(User $user, int $organizationId, int $projectId): ?Project
    {
        $query = Project::query()
            ->where('organization_id', $organizationId)
            ->where('id', $projectId);

        if (!$user->isOrganizationAdmin($organizationId)) {
            $query->whereHas('users', function ($usersQuery) use ($user): void {
                $usersQuery->where('users.id', $user->id);
            });
        }

        return $query->first();
    }

    private function mapSchedules(Collection $schedules): array
    {
        return $schedules
            ->map(fn(ProjectSchedule $schedule): array => $this->mapSchedule($schedule))
            ->values()
            ->all();
    }

    private function mapSchedule(ProjectSchedule $schedule): array
    {
        $status = $this->resolveStatusValue($schedule->status);
        $progress = round((float) ($schedule->overall_progress_percent ?? 0), 1);

        return [
            'id' => $schedule->id,
            'project_id' => $schedule->project_id,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'status' => $status,
            'status_label' => $this->resolveStatusLabel($schedule->status),
            'status_color' => $this->resolveStatusColor($status),
            'overall_progress_percent' => $progress,
            'progress_color' => $this->resolveProgressColor($progress),
            'health_status' => $schedule->health_status,
            'planned_start_date' => $schedule->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $schedule->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $schedule->planned_duration_days,
            'actual_start_date' => $schedule->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $schedule->actual_end_date?->format('Y-m-d'),
            'critical_path_calculated' => (bool) $schedule->critical_path_calculated,
            'critical_path_duration_days' => $schedule->critical_path_duration_days,
            'tasks_count' => (int) ($schedule->tasks_count ?? 0),
            'completed_tasks_count' => (int) ($schedule->completed_tasks_count ?? 0),
            'overdue_tasks_count' => (int) ($schedule->overdue_tasks_count ?? 0),
            'created_at' => $schedule->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $schedule->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function mapTasks(Collection $tasks): array
    {
        return $tasks
            ->map(function ($task): array {
                $status = $this->resolveStatusValue($task->status);

                return [
                    'id' => $task->id,
                    'parent_task_id' => $task->parent_task_id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'task_type' => $task->task_type?->value ?? $task->task_type,
                    'task_type_label' => method_exists($task->task_type, 'label')
                        ? $task->task_type->label()
                        : (string) ($task->task_type?->value ?? $task->task_type ?? ''),
                    'status' => $status,
                    'status_label' => $this->resolveStatusLabel($task->status),
                    'status_color' => method_exists($task->status, 'color')
                        ? $task->status->color()
                        : $this->resolveStatusColor($status),
                    'progress_percent' => round((float) ($task->progress_percent ?? 0), 1),
                    'is_critical' => (bool) $task->is_critical,
                    'level' => (int) ($task->level ?? 0),
                    'children_count' => (int) ($task->child_tasks_count ?? 0),
                    'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
                    'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
                    'planned_duration_days' => $task->planned_duration_days,
                    'actual_start_date' => $task->actual_start_date?->format('Y-m-d'),
                    'actual_end_date' => $task->actual_end_date?->format('Y-m-d'),
                    'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
                    'completed_quantity' => $task->completed_quantity !== null ? (float) $task->completed_quantity : null,
                    'measurement_unit' => $task->measurementUnit?->short_name ?? $task->measurementUnit?->name,
                ];
            })
            ->values()
            ->all();
    }

    private function findAccessibleAssignment(User $user, int $assignmentId): DailyWorkPlanAssignment
    {
        $organizationId = (int) $user->current_organization_id;

        $assignment = DailyWorkPlanAssignment::query()
            ->where('organization_id', $organizationId)
            ->with(['dailyWorkPlan', 'scheduleTask', 'journalEntry', 'lookaheadPlanTask.constraints'])
            ->find($assignmentId);

        if (!$assignment) {
            throw new DomainException(trans_message('mobile_schedule.errors.assignment_not_found'));
        }

        $this->assertProjectAccess($user, $organizationId, (int) $assignment->project_id);

        return $assignment;
    }

    private function findAccessibleDailyPlan(User $user, int $dailyPlanId): DailyWorkPlan
    {
        $organizationId = (int) $user->current_organization_id;

        $dailyPlan = DailyWorkPlan::query()
            ->where('organization_id', $organizationId)
            ->with(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints'])
            ->find($dailyPlanId);

        if (!$dailyPlan) {
            throw new DomainException(trans_message('mobile_schedule.errors.daily_plan_not_found'));
        }

        $this->assertProjectAccess($user, $organizationId, (int) $dailyPlan->project_id);

        return $dailyPlan;
    }

    private function findAccessibleConstraint(User $user, int $constraintId): WorkConstraint
    {
        $organizationId = (int) $user->current_organization_id;

        $constraint = WorkConstraint::query()
            ->where('organization_id', $organizationId)
            ->with(['scheduleTask'])
            ->find($constraintId);

        if (!$constraint) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_not_found'));
        }

        $this->assertProjectAccess($user, $organizationId, (int) $constraint->project_id);

        return $constraint;
    }

    private function findExistingLinkedAction(WorkConstraint $constraint): ?array
    {
        $linkedAction = $constraint->metadata['linked_action'] ?? null;

        if (!is_array($linkedAction)) {
            return null;
        }

        $type = (string) ($linkedAction['type'] ?? '');
        $id = (int) ($linkedAction['id'] ?? 0);

        if ($type === 'site_request' && $id > 0) {
            $siteRequest = SiteRequest::query()
                ->where('organization_id', $constraint->organization_id)
                ->find($id);

            return $siteRequest ? $this->mapLinkedSiteRequest($siteRequest) : null;
        }

        if ($type === 'quality_defect' && $id > 0) {
            $defect = QualityDefect::query()
                ->where('organization_id', $constraint->organization_id)
                ->find($id);

            return $defect ? $this->mapLinkedQualityDefect($defect) : null;
        }

        return null;
    }

    private function createSiteRequestFromConstraint(User $user, WorkConstraint $constraint, array $data): array
    {
        $siteRequest = $this->siteRequestService->create(
            (int) $constraint->organization_id,
            (int) $user->id,
            $this->makeSiteRequestData($constraint, $data)
        );

        return $this->mapLinkedSiteRequest($siteRequest);
    }

    private function createQualityDefectFromConstraint(User $user, WorkConstraint $constraint, array $data): array
    {
        $defect = $this->qualityDefectService->create(
            (int) $constraint->organization_id,
            (int) $user->id,
            [
                'project_id' => (int) $constraint->project_id,
                'title' => $constraint->title,
                'description' => $this->appendComment($constraint->description, $data['comment'] ?? null),
                'severity' => $constraint->severity === 'hard' ? 'critical' : 'major',
                'schedule_task_id' => (int) $constraint->schedule_task_id,
                'due_date' => $constraint->due_date?->format('Y-m-d'),
                'inspection_required' => true,
                'metadata' => $this->makeLinkedEntityMetadata($constraint),
            ]
        );

        return $this->mapLinkedQualityDefect($defect);
    }

    private function makeSiteRequestData(WorkConstraint $constraint, array $data): array
    {
        $requestType = $this->resolveSiteRequestType((string) $constraint->constraint_type);
        $baseData = [
            'project_id' => (int) $constraint->project_id,
            'title' => $constraint->title,
            'description' => $this->appendComment($constraint->description, $data['comment'] ?? null),
            'request_type' => $requestType->value,
            'priority' => $constraint->severity === 'hard'
                ? SiteRequestPriorityEnum::URGENT->value
                : SiteRequestPriorityEnum::HIGH->value,
            'required_date' => $constraint->due_date?->format('Y-m-d'),
            'metadata' => $this->makeLinkedEntityMetadata($constraint),
        ];

        return match ($requestType) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => $baseData + $this->makeMaterialRequestData($constraint),
            SiteRequestTypeEnum::PERSONNEL_REQUEST => $baseData + $this->makePersonnelRequestData($constraint),
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => $baseData + $this->makeEquipmentRequestData($constraint),
            default => $baseData,
        };
    }

    private function makeMaterialRequestData(WorkConstraint $constraint): array
    {
        $material = $constraint->metadata['required_material'] ?? null;

        if (!is_array($material)) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_material_data_required'));
        }

        $name = trim((string) ($material['name'] ?? ''));
        $quantity = (float) ($material['quantity'] ?? 0);
        $unit = trim((string) ($material['unit'] ?? ''));

        if ($name === '' || $quantity <= 0 || $unit === '') {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_material_data_required'));
        }

        return [
            'material_name' => $name,
            'material_quantity' => $quantity,
            'material_unit' => $unit,
        ];
    }

    private function makePersonnelRequestData(WorkConstraint $constraint): array
    {
        $personnel = $constraint->metadata['required_personnel'] ?? null;

        if (!is_array($personnel)) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_personnel_data_required'));
        }

        $count = (int) ($personnel['count'] ?? 0);

        if ($count <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_personnel_data_required'));
        }

        return [
            'personnel_type' => (string) ($personnel['type'] ?? PersonnelTypeEnum::OTHER->value),
            'personnel_count' => $count,
            'work_start_date' => $constraint->due_date?->format('Y-m-d'),
            'work_location' => (string) ($personnel['location'] ?? ''),
        ];
    }

    private function makeEquipmentRequestData(WorkConstraint $constraint): array
    {
        $equipment = $constraint->metadata['required_equipment'] ?? null;

        if (!is_array($equipment)) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_equipment_data_required'));
        }

        $count = (int) ($equipment['count'] ?? 0);

        if ($count <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.constraint_equipment_data_required'));
        }

        return [
            'equipment_type' => (string) ($equipment['type'] ?? EquipmentTypeEnum::OTHER->value),
            'equipment_count' => $count,
            'rental_start_date' => $constraint->due_date?->format('Y-m-d') ?? now()->toDateString(),
            'equipment_location' => (string) ($equipment['location'] ?? ''),
        ];
    }

    private function resolveSiteRequestType(string $constraintType): SiteRequestTypeEnum
    {
        return match ($constraintType) {
            'material_missing' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'labor_missing' => SiteRequestTypeEnum::PERSONNEL_REQUEST,
            'machinery_missing' => SiteRequestTypeEnum::EQUIPMENT_REQUEST,
            'access_blocked', 'customer_decision', 'design_question', 'executive_doc_missing', 'safety_permit_missing', 'weather_risk', 'other' => SiteRequestTypeEnum::ISSUE_REPORT,
            default => throw new DomainException(trans_message('mobile_schedule.errors.constraint_type_not_supported')),
        };
    }

    private function makeLinkedEntityMetadata(WorkConstraint $constraint): array
    {
        return [
            'source' => [
                'type' => 'work_constraint',
                'work_constraint_id' => (int) $constraint->id,
                'lookahead_plan_task_id' => (int) $constraint->lookahead_plan_task_id,
                'schedule_task_id' => (int) $constraint->schedule_task_id,
                'schedule_id' => (int) $constraint->schedule_id,
            ],
        ];
    }

    private function appendComment(?string $description, mixed $comment): ?string
    {
        $trimmedComment = trim((string) ($comment ?? ''));

        if ($trimmedComment === '') {
            return $description;
        }

        return trim((string) $description) !== ''
            ? trim((string) $description) . "\n\n" . $trimmedComment
            : $trimmedComment;
    }

    private function mapLinkedSiteRequest(SiteRequest $siteRequest): array
    {
        return [
            'type' => 'site_request',
            'entity' => (new SiteRequestResource($siteRequest->fresh(['project', 'user', 'assignedUser', 'group'])))->resolve(),
            'route' => "/site-requests/{$siteRequest->id}",
        ];
    }

    private function mapLinkedQualityDefect(QualityDefect $defect): array
    {
        return [
            'type' => 'quality_defect',
            'entity' => (new QualityDefectResource($defect->fresh([
                'project',
                'contractor',
                'createdBy',
                'assignedUser',
                'photos.uploadedBy',
                'statusHistory.changedBy',
            ])))->resolve(),
            'route' => "/quality-control/defects/{$defect->id}",
        ];
    }

    private function mapDailyPlan(DailyWorkPlan $dailyPlan): array
    {
        return [
            'id' => $dailyPlan->id,
            'project_id' => $dailyPlan->project_id,
            'schedule_id' => $dailyPlan->schedule_id,
            'schedule_name' => $dailyPlan->schedule?->name,
            'lookahead_plan_id' => $dailyPlan->lookahead_plan_id,
            'work_date' => $dailyPlan->work_date?->format('Y-m-d'),
            'status' => $dailyPlan->status,
            'status_label' => trans_message("schedule_management.daily_plan_statuses.{$dailyPlan->status}"),
            'available_actions' => match ($dailyPlan->status) {
                'published', 'in_progress' => ['record_fact', 'submit'],
                'submitted' => [],
                default => [],
            },
            'assignments' => $dailyPlan->assignments
                ->map(fn (DailyWorkPlanAssignment $assignment): array => $this->mapDailyAssignment($assignment))
                ->values()
                ->all(),
        ];
    }

    private function mapDailyAssignment(DailyWorkPlanAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'daily_work_plan_id' => $assignment->daily_work_plan_id,
            'lookahead_plan_task_id' => $assignment->lookahead_plan_task_id,
            'schedule_task_id' => $assignment->schedule_task_id,
            'journal_entry_id' => $assignment->journal_entry_id,
            'status' => $assignment->status,
            'planned_quantity' => $assignment->planned_quantity !== null ? (float) $assignment->planned_quantity : null,
            'completed_quantity' => $assignment->completed_quantity !== null ? (float) $assignment->completed_quantity : null,
            'planned_work_hours' => $assignment->planned_work_hours !== null ? (float) $assignment->planned_work_hours : null,
            'actual_work_hours' => $assignment->actual_work_hours !== null ? (float) $assignment->actual_work_hours : null,
            'failure_reason' => $assignment->failure_reason,
            'fact_comment' => $assignment->fact_comment,
            'linked_blocking_entities' => $assignment->metadata['linked_blocking_entities'] ?? [],
            'schedule_task' => $assignment->scheduleTask ? [
                'id' => $assignment->scheduleTask->id,
                'name' => $assignment->scheduleTask->name,
            ] : null,
            'constraints' => $assignment->lookaheadPlanTask?->constraints
                ? $assignment->lookaheadPlanTask->constraints
                    ->map(fn ($constraint): array => [
                        'id' => $constraint->id,
                        'title' => $constraint->title,
                        'constraint_type' => $constraint->constraint_type,
                        'severity' => $constraint->severity,
                        'status' => $constraint->status,
                        'due_date' => $constraint->due_date?->format('Y-m-d'),
                        'linked_action' => $constraint->metadata['linked_action'] ?? null,
                        'linked_entity' => $constraint->metadata['linked_action'] ?? $constraint->metadata['linked_entity'] ?? null,
                        'available_actions' => $constraint->status === 'open'
                            && (($constraint->metadata['linked_action'] ?? null) === null)
                            ? ['create_linked_action']
                            : [],
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function resolveStatusValue(mixed $status): string
    {
        return is_object($status) && isset($status->value)
            ? (string) $status->value
            : (string) $status;
    }

    private function resolveStatusLabel(mixed $status): string
    {
        if (is_object($status) && method_exists($status, 'label')) {
            return $status->label();
        }

        return $this->resolveStatusValue($status);
    }

    private function resolveStatusColor(string $status): string
    {
        return match ($status) {
            ScheduleStatusEnum::DRAFT->value,
            TaskStatusEnum::NOT_STARTED->value => '#6B7280',
            ScheduleStatusEnum::ACTIVE->value,
            TaskStatusEnum::IN_PROGRESS->value => '#3B82F6',
            ScheduleStatusEnum::PAUSED->value,
            TaskStatusEnum::ON_HOLD->value => '#F59E0B',
            ScheduleStatusEnum::COMPLETED->value,
            TaskStatusEnum::COMPLETED->value => '#10B981',
            ScheduleStatusEnum::CANCELLED->value,
            TaskStatusEnum::CANCELLED->value => '#EF4444',
            TaskStatusEnum::WAITING->value => '#8B5CF6',
            default => '#6B7280',
        };
    }

    private function resolveProgressColor(float $progress): string
    {
        return match (true) {
            $progress < 25 => '#EF4444',
            $progress < 50 => '#F59E0B',
            $progress < 75 => '#3B82F6',
            $progress < 100 => '#10B981',
            default => '#059669',
        };
    }
}
