<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Services;

use App\BusinessModules\Features\TimeTracking\DTOs\MobileTimeEntryPage;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\TimeEntry;
use App\Models\WorkType;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileTimeTrackingService
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public function paginateEntries(int $organizationId, int $userId, array $filters, int $perPage): MobileTimeEntryPage
    {
        $query = $this->baseQuery($organizationId, $userId, $filters);
        $summary = $this->summaryFromEntries((clone $query)->get());
        $paginator = $query
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return new MobileTimeEntryPage($paginator, $summary);
    }

    public function dailySummary(int $organizationId, int $userId, string $date, ?int $projectId): array
    {
        $filters = ['date' => $date];
        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
            $filters['project_id'] = $projectId;
        }

        $entries = $this->baseQuery($organizationId, $userId, $filters)
            ->orderByDesc('id')
            ->get();
        $summary = $this->summaryFromEntries($entries);
        $activeTimer = $entries->first(fn (TimeEntry $entry): bool => $this->isActiveTimer($entry));

        return [
            'date' => $date,
            'project_id' => $projectId,
            'entries' => $entries,
            'active_timer' => $activeTimer,
            'totals' => $summary,
            'approval_status' => $summary['by_status'],
        ];
    }

    public function findEntry(int $organizationId, int $userId, int $entryId): TimeEntry
    {
        $entry = $this->withRelations(TimeEntry::query())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereKey($entryId)
            ->first();

        if (!$entry) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.entry_not_found'));
        }

        return $entry;
    }

    public function createManualEntry(int $organizationId, int $userId, array $data): TimeEntry
    {
        $this->assertProject($organizationId, (int) $data['project_id']);
        $this->assertWorkType($organizationId, $data['work_type_id'] ?? null);
        $this->assertTask($organizationId, (int) $data['project_id'], $data['task_id'] ?? null);

        return DB::transaction(function () use ($organizationId, $userId, $data): TimeEntry {
            $entry = TimeEntry::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'worker_type' => 'user',
                'project_id' => (int) $data['project_id'],
                'work_type_id' => isset($data['work_type_id']) ? (int) $data['work_type_id'] : null,
                'task_id' => isset($data['task_id']) ? (int) $data['task_id'] : null,
                'work_date' => $data['work_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'hours_worked' => (float) $data['hours_worked'],
                'break_time' => isset($data['break_time']) ? (float) $data['break_time'] : null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'is_billable' => (bool) $data['is_billable'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'custom_fields' => $this->freshMobileState(active: false),
            ]);

            return $this->findEntry($organizationId, $userId, (int) $entry->id);
        });
    }

    public function startTimer(int $organizationId, int $userId, array $data): TimeEntry
    {
        $projectId = (int) $data['project_id'];
        $this->assertProject($organizationId, $projectId);
        $this->assertWorkType($organizationId, $data['work_type_id'] ?? null);
        $this->assertTask($organizationId, $projectId, $data['task_id'] ?? null);

        if ($this->activeTimerQuery($organizationId, $userId)->exists()) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.active_timer_exists'));
        }

        return DB::transaction(function () use ($organizationId, $userId, $data, $projectId): TimeEntry {
            $entry = TimeEntry::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'worker_type' => 'user',
                'project_id' => $projectId,
                'work_type_id' => isset($data['work_type_id']) ? (int) $data['work_type_id'] : null,
                'task_id' => isset($data['task_id']) ? (int) $data['task_id'] : null,
                'work_date' => $data['work_date'],
                'start_time' => $data['start_time'],
                'end_time' => null,
                'hours_worked' => null,
                'break_time' => null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'is_billable' => (bool) $data['is_billable'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'custom_fields' => $this->freshMobileState(active: true, userId: $userId),
            ]);

            return $this->findEntry($organizationId, $userId, (int) $entry->id);
        });
    }

    public function stopTimer(TimeEntry $entry, int $userId, array $data): TimeEntry
    {
        if (!$this->isActiveTimer($entry)) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.no_active_timer'));
        }

        $hours = $this->calculateHours(
            $this->timeString($entry->getRawOriginal('start_time')),
            (string) $data['end_time'],
            (float) $data['break_time']
        );

        $state = $this->mobileState($entry);
        $state['active_timer'] = false;
        $state['stopped_by_user_id'] = $userId;
        $state['stopped_at'] = now()->toIso8601String();

        $entry->forceFill([
            'end_time' => $data['end_time'],
            'break_time' => (float) $data['break_time'],
            'hours_worked' => $hours,
            'notes' => $data['notes'] ?? $entry->notes,
            'custom_fields' => $this->withMobileState($entry, $state),
        ])->save();

        return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id);
    }

    public function submitEntry(TimeEntry $entry): TimeEntry
    {
        if ($this->isActiveTimer($entry) || $entry->hours_worked === null || !in_array($entry->status, ['draft', 'rejected'], true)) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.submit_forbidden'));
        }

        $entry->submit();

        return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id);
    }

    public function submitCorrection(TimeEntry $entry, int $userId, array $data): TimeEntry
    {
        if ($entry->status !== 'rejected') {
            throw new DomainException(trans_message('time_tracking.mobile.errors.correction_forbidden'));
        }

        return DB::transaction(function () use ($entry, $userId, $data): TimeEntry {
            $state = $this->mobileState($entry);
            $corrections = is_array($state['corrections'] ?? null) ? $state['corrections'] : [];
            $corrections[] = [
                'id' => (string) Str::uuid(),
                'reason' => $data['correction_reason'],
                'previous_hours' => $entry->hours_worked !== null ? (float) $entry->hours_worked : null,
                'new_hours' => (float) $data['hours_worked'],
                'submitted_by_user_id' => $userId,
                'created_at' => now()->toIso8601String(),
            ];
            $state['corrections'] = $corrections;

            $entry->forceFill([
                'hours_worked' => (float) $data['hours_worked'],
                'break_time' => array_key_exists('break_time', $data) ? (float) $data['break_time'] : $entry->break_time,
                'start_time' => $data['start_time'] ?? $entry->getRawOriginal('start_time'),
                'end_time' => $data['end_time'] ?? $entry->getRawOriginal('end_time'),
                'title' => $data['title'] ?? $entry->title,
                'description' => $data['description'] ?? $entry->description,
                'notes' => $data['notes'] ?? $entry->notes,
                'custom_fields' => $this->withMobileState($entry, $state),
            ])->save();

            if (!$entry->submit()) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.correction_forbidden'));
            }

            return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id);
        });
    }

    private function baseQuery(int $organizationId, int $userId, array $filters): Builder
    {
        return $this->withRelations(TimeEntry::query())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->when(isset($filters['project_id']), static function (Builder $query) use ($filters): void {
                $query->where('project_id', (int) $filters['project_id']);
            })
            ->when(isset($filters['date']), static function (Builder $query) use ($filters): void {
                $query->whereDate('work_date', (string) $filters['date']);
            })
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            });
    }

    private function activeTimerQuery(int $organizationId, int $userId): Builder
    {
        return TimeEntry::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'draft')
            ->whereNotNull('start_time')
            ->whereNull('end_time')
            ->where('custom_fields->mobile_time_tracking->active_timer', true);
    }

    private function withRelations(Builder $query): Builder
    {
        return $query->with(['project', 'workType', 'task', 'approvedBy']);
    }

    private function summaryFromEntries(Collection $entries): array
    {
        $byStatus = array_fill_keys(self::STATUSES, 0);
        $totalHours = 0.0;
        $billableHours = 0.0;

        foreach ($entries as $entry) {
            if (!$entry instanceof TimeEntry) {
                continue;
            }

            $byStatus[$entry->status] = ($byStatus[$entry->status] ?? 0) + 1;

            if ($entry->hours_worked === null) {
                continue;
            }

            $hours = (float) $entry->hours_worked;
            $totalHours += $hours;

            if ((bool) $entry->is_billable) {
                $billableHours += $hours;
            }
        }

        return [
            'total_hours' => round($totalHours, 2),
            'billable_hours' => round($billableHours, 2),
            'entries_count' => $entries->count(),
            'by_status' => $byStatus,
        ];
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        $exists = Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.project_not_found'));
        }
    }

    private function assertWorkType(int $organizationId, mixed $workTypeId): void
    {
        if ($workTypeId === null) {
            return;
        }

        $exists = WorkType::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereKey((int) $workTypeId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.work_type_not_found'));
        }
    }

    private function assertTask(int $organizationId, int $projectId, mixed $taskId): void
    {
        if ($taskId === null) {
            return;
        }

        $exists = ScheduleTask::query()
            ->where('organization_id', $organizationId)
            ->whereHas('schedule', static fn (Builder $query) => $query->where('project_id', $projectId))
            ->whereKey((int) $taskId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.task_not_found'));
        }
    }

    private function calculateHours(string $startTime, string $endTime, float $breakHours): float
    {
        $start = CarbonImmutable::createFromFormat('H:i', $startTime);
        $end = CarbonImmutable::createFromFormat('H:i', $endTime);

        if (!$start instanceof CarbonImmutable || !$end instanceof CarbonImmutable) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_time'));
        }

        $totalMinutes = (int) (($end->timestamp - $start->timestamp) / 60);
        $breakMinutes = (int) round($breakHours * 60);
        $workMinutes = $totalMinutes - $breakMinutes;

        if ($totalMinutes <= 0 || $workMinutes <= 0) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_duration'));
        }

        return round($workMinutes / 60, 2);
    }

    private function isActiveTimer(TimeEntry $entry): bool
    {
        $state = $this->mobileState($entry);

        return ($state['active_timer'] ?? false) === true
            && $entry->status === 'draft'
            && $entry->start_time !== null
            && $entry->end_time === null;
    }

    private function freshMobileState(bool $active, ?int $userId = null): array
    {
        $state = [
            'active_timer' => $active,
            'corrections' => [],
        ];

        if ($active) {
            $state['started_by_user_id'] = $userId;
            $state['started_at'] = now()->toIso8601String();
        }

        return [
            'mobile_time_tracking' => $state,
        ];
    }

    private function mobileState(TimeEntry $entry): array
    {
        $customFields = is_array($entry->custom_fields) ? $entry->custom_fields : [];
        $state = $customFields['mobile_time_tracking'] ?? [];

        return is_array($state) ? $state : [];
    }

    private function withMobileState(TimeEntry $entry, array $state): array
    {
        $customFields = is_array($entry->custom_fields) ? $entry->custom_fields : [];
        $customFields['mobile_time_tracking'] = $state;

        return $customFields;
    }

    private function timeString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return substr($value, 0, 5);
        }

        throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_time'));
    }
}
