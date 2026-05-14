<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlanAssignment;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlanTask;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class LookaheadPlanningService
{
    private const RELATIONS = [
        'tasks.scheduleTask',
        'tasks.constraints',
        'dailyPlans.assignments.scheduleTask',
        'dailyPlans.assignments.journalEntry',
        'dailyPlans.assignments.lookaheadPlanTask.constraints',
    ];

    public function listPlans(ProjectSchedule $schedule): Collection
    {
        return LookaheadPlan::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('schedule_id', $schedule->id)
            ->with(self::RELATIONS)
            ->orderByDesc('start_date')
            ->get();
    }

    public function createPlan(ProjectSchedule $schedule, int $userId, array $data): LookaheadPlan
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        $weeks = max(1, (int) ceil(($start->diffInDays($end) + 1) / 7));

        if ($weeks < 2 || $weeks > 6) {
            throw new DomainException(trans_message('schedule_management.lookahead_invalid_range'));
        }

        return LookaheadPlan::query()->create([
            'organization_id' => $schedule->organization_id,
            'project_id' => $schedule->project_id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $userId,
            'title' => $data['title'] ?? ($start->format('d.m.Y') . ' - ' . $end->format('d.m.Y')),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => 'draft',
            'metadata' => ['weeks' => $weeks],
        ])->fresh(self::RELATIONS);
    }

    public function addTask(ProjectSchedule $schedule, LookaheadPlan $plan, array $data): LookaheadPlanTask
    {
        $task = $this->findScheduleTask($schedule, (int) $data['schedule_task_id']);

        return LookaheadPlanTask::query()->create([
            'organization_id' => $schedule->organization_id,
            'project_id' => $schedule->project_id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $plan->id,
            'schedule_task_id' => $task->id,
            'planned_start_date' => $data['planned_start_date'],
            'planned_end_date' => $data['planned_end_date'],
            'planned_quantity' => $data['planned_quantity'] ?? null,
            'planned_work_hours' => $data['planned_work_hours'] ?? null,
            'readiness_status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ])->fresh(['scheduleTask', 'constraints']);
    }

    public function addConstraint(ProjectSchedule $schedule, LookaheadPlanTask $planTask, int $userId, array $data): WorkConstraint
    {
        $this->assertPlanTaskBelongsToSchedule($planTask, $schedule);

        return WorkConstraint::query()->create([
            'organization_id' => $schedule->organization_id,
            'project_id' => $schedule->project_id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $planTask->id,
            'schedule_task_id' => $planTask->schedule_task_id,
            'created_by_user_id' => $userId,
            'constraint_type' => $data['constraint_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'soft',
            'status' => 'open',
            'due_date' => $data['due_date'] ?? null,
        ])->fresh(['lookaheadPlanTask']);
    }

    public function createDailyPlan(ProjectSchedule $schedule, int $userId, array $data): DailyWorkPlan
    {
        $plan = $this->findLookaheadPlan($schedule, (int) $data['lookahead_plan_id']);

        return DB::transaction(function () use ($schedule, $userId, $data, $plan): DailyWorkPlan {
            $daily = DailyWorkPlan::query()->create([
                'organization_id' => $schedule->organization_id,
                'project_id' => $schedule->project_id,
                'schedule_id' => $schedule->id,
                'lookahead_plan_id' => $plan->id,
                'created_by_user_id' => $userId,
                'work_date' => $data['work_date'],
                'status' => 'draft',
                'summary_comment' => $data['summary_comment'] ?? null,
            ]);

            foreach ($data['assignments'] as $assignment) {
                $planTask = $this->findLookaheadTask($schedule, (int) $assignment['lookahead_plan_task_id']);

                $daily->assignments()->create([
                    'organization_id' => $schedule->organization_id,
                    'project_id' => $schedule->project_id,
                    'schedule_id' => $schedule->id,
                    'lookahead_plan_task_id' => $planTask->id,
                    'schedule_task_id' => $planTask->schedule_task_id,
                    'assigned_user_id' => $assignment['assigned_user_id'] ?? null,
                    'planned_quantity' => $assignment['planned_quantity'] ?? $planTask->planned_quantity,
                    'planned_work_hours' => $assignment['planned_work_hours'] ?? $planTask->planned_work_hours,
                    'status' => 'planned',
                ]);
            }

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function publishDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, int $userId, array $data = []): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if ($daily->status !== 'draft') {
            throw new DomainException(trans_message('schedule_management.daily_plan_publish_invalid_status'));
        }

        $hardConstraints = $this->openHardConstraintsForDailyPlan($daily);
        $overrideIds = array_map('intval', $data['override_constraint_ids'] ?? []);
        $blocking = $hardConstraints->reject(static fn (WorkConstraint $constraint): bool => in_array($constraint->id, $overrideIds, true));

        if ($blocking->isNotEmpty()) {
            throw new DomainException(trans_message('schedule_management.daily_plan_blocked_by_constraints'));
        }

        return DB::transaction(function () use ($daily, $hardConstraints, $overrideIds, $userId, $data): DailyWorkPlan {
            if (!empty($overrideIds)) {
                $hardConstraints
                    ->filter(static fn (WorkConstraint $constraint): bool => in_array($constraint->id, $overrideIds, true))
                    ->each(function (WorkConstraint $constraint) use ($userId, $data): void {
                        $constraint->update([
                            'overridden_by_user_id' => $userId,
                            'overridden_at' => now(),
                            'override_reason' => $data['override_reason'] ?? null,
                        ]);
                    });
            }

            $daily->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function recordAssignmentFact(ProjectSchedule $schedule, DailyWorkPlanAssignment $assignment, int $userId, array $data): DailyWorkPlanAssignment
    {
        $this->assertAssignmentBelongsToSchedule($assignment, $schedule);

        $daily = $assignment->dailyWorkPlan()->firstOrFail();

        if (!in_array($daily->status, ['published', 'in_progress', 'returned'], true)) {
            throw new DomainException(trans_message('schedule_management.daily_plan_fact_invalid_status'));
        }

        return DB::transaction(function () use ($schedule, $assignment, $daily, $userId, $data): DailyWorkPlanAssignment {
            if (in_array($daily->status, ['published', 'returned'], true)) {
                $daily->update(['status' => 'in_progress']);
            }

            $entry = $this->syncJournalEntry($schedule, $assignment, $daily, $userId, $data);

            $assignment->update([
                'journal_entry_id' => $entry->id,
                'completed_quantity' => $data['completed_quantity'] ?? $assignment->completed_quantity,
                'actual_work_hours' => $data['actual_work_hours'] ?? $assignment->actual_work_hours,
                'status' => $data['status'],
                'failure_reason' => $data['failure_reason'] ?? null,
                'fact_comment' => $data['fact_comment'] ?? $assignment->fact_comment,
            ]);

            return $assignment->fresh(['scheduleTask', 'journalEntry', 'lookaheadPlanTask.constraints']);
        });
    }

    public function submitDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, array $data = []): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if (!in_array($daily->status, ['published', 'in_progress', 'returned'], true)) {
            throw new DomainException(trans_message('schedule_management.daily_plan_submit_invalid_status'));
        }

        return DB::transaction(function () use ($daily, $data): DailyWorkPlan {
            $daily->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'summary_comment' => $data['summary_comment'] ?? $daily->summary_comment,
            ]);

            $this->journalEntriesForDailyPlan($daily)
                ->where('status', JournalEntryStatusEnum::DRAFT)
                ->each(fn (ConstructionJournalEntry $entry): bool => $entry->update(['status' => JournalEntryStatusEnum::SUBMITTED]));

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function acceptDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, int $userId): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if ($daily->status !== 'submitted') {
            throw new DomainException(trans_message('schedule_management.daily_plan_accept_invalid_status'));
        }

        return DB::transaction(function () use ($daily, $userId): DailyWorkPlan {
            $daily->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by_user_id' => $userId,
            ]);

            $this->journalEntriesForDailyPlan($daily)
                ->where('status', JournalEntryStatusEnum::SUBMITTED)
                ->each(fn (ConstructionJournalEntry $entry): bool => $entry->update([
                    'status' => JournalEntryStatusEnum::APPROVED,
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                ]));

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function returnDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, int $userId, array $data): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if ($daily->status !== 'submitted') {
            throw new DomainException(trans_message('schedule_management.daily_plan_return_invalid_status'));
        }

        return DB::transaction(function () use ($daily, $userId, $data): DailyWorkPlan {
            $daily->update([
                'status' => 'returned',
                'returned_at' => now(),
                'returned_by_user_id' => $userId,
                'return_reason' => $data['return_reason'],
            ]);

            $this->journalEntriesForDailyPlan($daily)
                ->where('status', JournalEntryStatusEnum::SUBMITTED)
                ->each(fn (ConstructionJournalEntry $entry): bool => $entry->update(['status' => JournalEntryStatusEnum::DRAFT]));

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function closeDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, int $userId): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if ($daily->status !== 'accepted') {
            throw new DomainException(trans_message('schedule_management.daily_plan_close_invalid_status'));
        }

        return DB::transaction(function () use ($daily, $userId): DailyWorkPlan {
            $daily->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $userId,
            ]);

            return $daily->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function reviseDailyPlan(ProjectSchedule $schedule, DailyWorkPlan $daily, int $userId, array $data): DailyWorkPlan
    {
        $this->assertDailyPlanBelongsToSchedule($daily, $schedule);

        if (!in_array($daily->status, ['published', 'in_progress', 'returned', 'closed'], true)) {
            throw new DomainException(trans_message('schedule_management.daily_plan_revise_invalid_status'));
        }

        return DB::transaction(function () use ($schedule, $daily, $userId, $data): DailyWorkPlan {
            $sourceStatus = $daily->status;
            $daily->update([
                'status' => $sourceStatus === 'closed' ? 'closed' : 'revised',
                'revised_at' => now(),
                'revised_by_user_id' => $userId,
                'revision_reason' => $data['revision_reason'],
            ]);

            $revision = DailyWorkPlan::query()->create([
                'organization_id' => $daily->organization_id,
                'project_id' => $daily->project_id,
                'schedule_id' => $daily->schedule_id,
                'lookahead_plan_id' => $daily->lookahead_plan_id,
                'created_by_user_id' => $userId,
                'work_date' => $daily->work_date,
                'status' => 'draft',
                'summary_comment' => $daily->summary_comment,
                'revision_of_daily_plan_id' => $daily->id,
                'revision_number' => ((int) $daily->revision_number) + 1,
                'revision_reason' => $data['revision_reason'],
                'metadata' => array_filter([
                    'source_daily_plan_status' => $sourceStatus,
                    'source_daily_plan_id' => $daily->id,
                ]),
            ]);

            $daily->assignments()
                ->orderBy('id')
                ->get()
                ->each(function (DailyWorkPlanAssignment $assignment) use ($schedule, $revision): void {
                    $revision->assignments()->create([
                        'organization_id' => $schedule->organization_id,
                        'project_id' => $schedule->project_id,
                        'schedule_id' => $schedule->id,
                        'lookahead_plan_task_id' => $assignment->lookahead_plan_task_id,
                        'schedule_task_id' => $assignment->schedule_task_id,
                        'assigned_user_id' => $assignment->assigned_user_id,
                        'planned_quantity' => $assignment->planned_quantity,
                        'planned_work_hours' => $assignment->planned_work_hours,
                        'status' => 'planned',
                    ]);
                });

            return $revision->fresh(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints']);
        });
    }

    public function openHardConstraintsForDailyPlan(DailyWorkPlan $daily): Collection
    {
        $taskIds = $daily->assignments()->pluck('lookahead_plan_task_id')->all();

        return WorkConstraint::query()
            ->whereIn('lookahead_plan_task_id', $taskIds)
            ->where('severity', 'hard')
            ->where('status', 'open')
            ->get();
    }

    public function findLookaheadPlan(ProjectSchedule $schedule, int $id): LookaheadPlan
    {
        $plan = LookaheadPlan::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('schedule_id', $schedule->id)
            ->with(self::RELATIONS)
            ->find($id);

        if (!$plan) {
            throw new DomainException(trans_message('schedule_management.lookahead_not_found'));
        }

        return $plan;
    }

    public function findLookaheadTask(ProjectSchedule $schedule, int $id): LookaheadPlanTask
    {
        $task = LookaheadPlanTask::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('schedule_id', $schedule->id)
            ->with(['scheduleTask', 'constraints'])
            ->find($id);

        if (!$task) {
            throw new DomainException(trans_message('schedule_management.lookahead_task_not_found'));
        }

        return $task;
    }

    public function findDailyPlan(ProjectSchedule $schedule, int $id): DailyWorkPlan
    {
        $daily = DailyWorkPlan::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('schedule_id', $schedule->id)
            ->with(['assignments.scheduleTask', 'assignments.journalEntry', 'assignments.lookaheadPlanTask.constraints'])
            ->find($id);

        if (!$daily) {
            throw new DomainException(trans_message('schedule_management.daily_plan_not_found'));
        }

        return $daily;
    }

    public function findAssignment(ProjectSchedule $schedule, int $id): DailyWorkPlanAssignment
    {
        $assignment = DailyWorkPlanAssignment::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('schedule_id', $schedule->id)
            ->with(['dailyWorkPlan', 'scheduleTask', 'journalEntry', 'lookaheadPlanTask.constraints'])
            ->find($id);

        if (!$assignment) {
            throw new DomainException(trans_message('schedule_management.daily_plan_assignment_not_found'));
        }

        return $assignment;
    }

    private function findScheduleTask(ProjectSchedule $schedule, int $id): ScheduleTask
    {
        $task = ScheduleTask::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('schedule_id', $schedule->id)
            ->find($id);

        if (!$task) {
            throw new DomainException(trans_message('schedule_management.task_not_found'));
        }

        return $task;
    }

    private function assertPlanTaskBelongsToSchedule(LookaheadPlanTask $planTask, ProjectSchedule $schedule): void
    {
        if ((int) $planTask->schedule_id !== (int) $schedule->id || (int) $planTask->organization_id !== (int) $schedule->organization_id) {
            throw new DomainException(trans_message('schedule_management.lookahead_task_not_found'));
        }
    }

    private function assertDailyPlanBelongsToSchedule(DailyWorkPlan $daily, ProjectSchedule $schedule): void
    {
        if ((int) $daily->schedule_id !== (int) $schedule->id || (int) $daily->organization_id !== (int) $schedule->organization_id) {
            throw new DomainException(trans_message('schedule_management.daily_plan_not_found'));
        }
    }

    private function assertAssignmentBelongsToSchedule(DailyWorkPlanAssignment $assignment, ProjectSchedule $schedule): void
    {
        if ((int) $assignment->schedule_id !== (int) $schedule->id || (int) $assignment->organization_id !== (int) $schedule->organization_id) {
            throw new DomainException(trans_message('schedule_management.daily_plan_assignment_not_found'));
        }
    }

    private function syncJournalEntry(ProjectSchedule $schedule, DailyWorkPlanAssignment $assignment, DailyWorkPlan $daily, int $userId, array $data): ConstructionJournalEntry
    {
        $journal = ConstructionJournal::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('project_id', $schedule->project_id)
            ->where('status', JournalStatusEnum::ACTIVE)
            ->orderBy('id')
            ->first();

        if (!$journal) {
            throw new DomainException(trans_message('schedule_management.daily_plan_journal_required'));
        }

        $entry = $assignment->journal_entry_id
            ? ConstructionJournalEntry::query()->where('journal_id', $journal->id)->find($assignment->journal_entry_id)
            : null;

        if (!$entry) {
            $entry = ConstructionJournalEntry::query()
                ->where('journal_id', $journal->id)
                ->where('schedule_task_id', $assignment->schedule_task_id)
                ->whereDate('entry_date', $daily->work_date)
                ->first();
        }

        if ($entry && !$entry->canBeEdited()) {
            throw new DomainException(trans_message('schedule_management.daily_plan_fact_invalid_status'));
        }

        $payload = [
            'journal_id' => $journal->id,
            'schedule_task_id' => $assignment->schedule_task_id,
            'entry_date' => $daily->work_date,
            'work_description' => $data['fact_comment'] ?? $assignment->fact_comment ?? $assignment->scheduleTask?->name ?? '',
            'status' => JournalEntryStatusEnum::DRAFT,
            'created_by_user_id' => $userId,
        ];

        if ($entry) {
            $entry->update($payload);

            return $entry->refresh();
        }

        return ConstructionJournalEntry::query()->create($payload + [
            'entry_number' => $journal->getNextEntryNumber(),
        ]);
    }

    private function journalEntriesForDailyPlan(DailyWorkPlan $daily): Collection
    {
        $entryIds = $daily->assignments()
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id')
            ->all();

        return ConstructionJournalEntry::query()
            ->whereIn('id', $entryIds)
            ->get();
    }
}
