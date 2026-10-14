<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Services;

use App\BusinessModules\Features\TimeTracking\DTOs\MobileTimeEntryPage;
use App\BusinessModules\Features\TimeTracking\Support\WorkingTimezoneResolver;
use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ScheduleTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\WorkforcePeriodMutationGuard;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileTimeTrackingService
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public function __construct(
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function paginateEntries(int $organizationId, User $user, array $filters, int $perPage): MobileTimeEntryPage
    {
        $this->assertActor($user, $organizationId, 'time_tracking.view');
        if (isset($filters['project_id'])) {
            $this->assertProject($user, $organizationId, (int) $filters['project_id']);
        }

        $query = $this->baseQuery($organizationId, (int) $user->id, $filters);
        $summary = $this->summaryFromEntries((clone $query)->get());
        $paginator = $query
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return new MobileTimeEntryPage($paginator, $summary);
    }

    public function dailySummary(int $organizationId, User $user, string $date, ?int $projectId): array
    {
        $this->assertActor($user, $organizationId, 'time_tracking.view');
        $filters = ['date' => $date];
        if ($projectId !== null) {
            $this->assertProject($user, $organizationId, $projectId);
            $filters['project_id'] = $projectId;
        }

        $entries = $this->baseQuery($organizationId, (int) $user->id, $filters)
            ->orderByDesc('id')
            ->get();
        $summary = $this->summaryFromEntries($entries);
        $activeTimer = $this->withRelations($this->activeTimerQuery($organizationId, (int) $user->id))
            ->whereIn('project_id', $this->projectAccess->query($user, $organizationId)->select('projects.id'))
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))->first();

        return [
            'date' => $date,
            'project_id' => $projectId,
            'entries' => $entries,
            'active_timer' => $activeTimer,
            'totals' => $summary,
            'approval_status' => $summary['by_status'],
        ];
    }

    public function findEntry(int $organizationId, int $userId, int $entryId, string $permission = 'time_tracking.view'): TimeEntry
    {
        $user = User::query()->find($userId);
        if (! $user) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.entry_not_found'));
        }
        $this->assertActor($user, $organizationId, $permission);
        $entry = $this->withRelations(TimeEntry::query())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereKey($entryId)
            ->first();

        if (! $entry) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.entry_not_found'));
        }

        $this->assertProject($user, $organizationId, (int) $entry->project_id);

        return $entry;
    }

    public function createManualEntry(int $organizationId, User $user, array $data): TimeEntry
    {
        $userId = (int) $user->id;
        $this->assertActor($user, $organizationId, 'time_tracking.create');
        if (isset($data['hours_worked']) && ((float) $data['hours_worked'] <= 0 || (float) $data['hours_worked'] > 24)) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_duration'));
        }

        $this->assertProject($user, $organizationId, (int) $data['project_id']);
        $this->assertWorkType($organizationId, $data['work_type_id'] ?? null);
        $this->assertTask($organizationId, (int) $data['project_id'], $data['task_id'] ?? null);

        return DB::transaction(function () use ($organizationId, $userId, $data): TimeEntry {
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $data = $this->normalizeInterval($this->resolveWorkingTimezone($organizationId, $userId, $data));
            $guard->assertMutable($organizationId, (int) $data['project_id'], $data + ['user_id' => $userId]);
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
                'hours_worked' => (float) ($data['hours_worked'] ?? 0),
                'break_time' => isset($data['break_time']) ? (float) $data['break_time'] : null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'is_billable' => (bool) $data['is_billable'],
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'custom_fields' => array_replace_recursive($this->freshMobileState(active: false), $data['custom_fields'] ?? []),
                ...array_intersect_key($data, array_flip(['start_at', 'end_at', 'timezone', 'timezone_requires_reconciliation', 'net_minutes', 'net_microseconds', 'break_intervals'])),
            ]);

            return $this->findEntry($organizationId, $userId, (int) $entry->id, 'time_tracking.create');
        });
    }

    public function startTimer(int $organizationId, User $user, array $data): TimeEntry
    {
        $userId = (int) $user->id;
        $projectId = (int) $data['project_id'];
        $this->assertProject($user, $organizationId, $projectId);
        $this->assertWorkType($organizationId, $data['work_type_id'] ?? null);
        $this->assertTask($organizationId, $projectId, $data['task_id'] ?? null);

        $this->assertActor($user, $organizationId, 'time_tracking.create');

        return DB::transaction(function () use ($organizationId, $userId, $data, $projectId): TimeEntry {
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $data = $this->resolveWorkingTimezone($organizationId, $userId, $data);
            try {
                $data = array_replace($data, WorkTimeInterval::normalizeStart($data, (string) config('app.timezone', 'UTC')));
            } catch (\Throwable $exception) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_time'));
            }

            $guard->assertMutable($organizationId, $projectId, $data + ['user_id' => $userId]);
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($this->activeTimerQuery($organizationId, $userId)->exists()) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.active_timer_exists'), 409);
            }
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
                'custom_fields' => array_replace_recursive($this->freshMobileState(active: true, userId: $userId), $data['custom_fields'] ?? []),
                'start_at' => $data['start_at'],
                'timezone' => $data['timezone'],
                'timezone_requires_reconciliation' => $data['timezone_requires_reconciliation'],
            ]);

            return $this->findEntry($organizationId, $userId, (int) $entry->id, 'time_tracking.create');
        });
    }

    public function stopTimer(TimeEntry $entry, int $userId, array $data): TimeEntry
    {
        $this->assertEntryActor($entry, $userId, 'time_tracking.edit');

        return DB::transaction(function () use ($entry, $userId, $data): TimeEntry {
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization((int) $entry->organization_id);
            $entry = TimeEntry::query()->where('organization_id', $entry->organization_id)->where('user_id', $userId)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! $this->isActiveTimer($entry)) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.no_active_timer'), 409);
            }
            $interval = $this->normalizeInterval([
                'work_date' => $entry->work_date->format('Y-m-d'),
                'start_time' => $this->timeString($entry->getRawOriginal('start_time')),
                'start_at' => $entry->start_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
                'timezone' => $entry->timezone ?? config('app.timezone', 'UTC'),
                'timezone_requires_reconciliation' => $entry->timezone === null || (bool) $entry->timezone_requires_reconciliation,
                ...$data,
            ]);
            $guard->assertMutable((int) $entry->organization_id, (int) $entry->project_id, $guard->entryFacts($entry));
            $guard->assertMutable((int) $entry->organization_id, (int) $entry->project_id, array_replace($guard->entryFacts($entry), $interval));
            $state = $this->mobileState($entry);
            $state['active_timer'] = false;
            $state['stopped_by_user_id'] = $userId;
            $state['stopped_at'] = now()->toIso8601String();
            $entry->forceFill([
                ...array_intersect_key($interval, array_flip(['start_at', 'end_at', 'timezone', 'timezone_requires_reconciliation', 'net_minutes', 'net_microseconds', 'break_intervals', 'end_time', 'break_time', 'hours_worked'])),
                'notes' => $data['notes'] ?? $entry->notes,
                'custom_fields' => $this->withMobileState($entry, $state),
            ])->save();

            return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id, 'time_tracking.edit');
        });
    }

    public function submitEntry(TimeEntry $entry): TimeEntry
    {
        return DB::transaction(function () use ($entry): TimeEntry {
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization((int) $entry->organization_id);
            $entry = TimeEntry::query()->where('organization_id', $entry->organization_id)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->assertEntryActor($entry, (int) Auth::id(), 'time_tracking.submit');
            if ($this->isActiveTimer($entry) || $entry->hours_worked === null || ! in_array($entry->status, ['draft', 'rejected'], true)) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.submit_forbidden'));
            }
            $guard->assertMutable((int) $entry->organization_id, (int) $entry->project_id, $guard->entryFacts($entry));
            $entry->submit();

            return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id, 'time_tracking.submit');
        });
    }

    public function submitCorrection(TimeEntry $entry, int $userId, array $data): TimeEntry
    {
        $this->assertEntryActor($entry, $userId, 'time_tracking.edit');
        $this->assertEntryActor($entry, $userId, 'time_tracking.submit');

        return DB::transaction(function () use ($entry, $userId, $data): TimeEntry {
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization((int) $entry->organization_id);
            $entry = TimeEntry::query()->where('organization_id', $entry->organization_id)->where('user_id', $userId)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'rejected') {
                throw new DomainException(trans_message('time_tracking.mobile.errors.correction_forbidden'));
            }
            $merged = [
                'work_date' => $entry->work_date->format('Y-m-d'),
                'start_time' => $entry->getRawOriginal('start_time'),
                'end_time' => $entry->getRawOriginal('end_time'),
                'timezone' => $entry->timezone ?? config('app.timezone', 'UTC'),
                'timezone_requires_reconciliation' => $entry->timezone === null || (bool) $entry->timezone_requires_reconciliation,
                'break_time' => $entry->break_time,
                'break_intervals' => $entry->break_intervals ?? [],
                ...$data,
            ];
            if (! empty($data['timezone'])) {
                $merged['timezone_requires_reconciliation'] = false;
            }
            if (! isset($data['start_time']) && ! isset($data['end_time']) && ! ($entry->timezone_requires_reconciliation && ! empty($data['timezone']) && ($entry->custom_fields['time_tracking_interval']['clock_source'] ?? 'legacy_local') === 'legacy_local')) {
                $merged['start_at'] = $entry->start_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z');
                $merged['end_at'] = $entry->end_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z');
            }
            $data = $this->normalizeInterval($merged);
            if ($entry->status !== 'rejected') {
                throw new DomainException(trans_message('time_tracking.mobile.errors.correction_forbidden'));
            }

            $guard->assertMutable((int) $entry->organization_id, (int) $entry->project_id, $guard->entryFacts($entry));
            $guard->assertMutable((int) $entry->organization_id, (int) $entry->project_id, array_replace($guard->entryFacts($entry), $data));
            $state = $this->mobileState($entry);
            $corrections = is_array($state['corrections'] ?? null) ? $state['corrections'] : [];
            $corrections[] = [
                'id' => (string) Str::uuid(),
                'reason' => $data['correction_reason'],
                'previous_hours' => $entry->hours_worked !== null ? (float) $entry->hours_worked : null,
                'new_hours' => (float) ($data['hours_worked'] ?? 0),
                'submitted_by_user_id' => $userId,
                'created_at' => now()->toIso8601String(),
            ];
            $state['corrections'] = $corrections;

            $entry->forceFill([
                'hours_worked' => (float) ($data['hours_worked'] ?? 0),
                'break_time' => array_key_exists('break_time', $data) ? (float) $data['break_time'] : $entry->break_time,
                'start_time' => $data['start_time'] ?? $entry->getRawOriginal('start_time'),
                'end_time' => $data['end_time'] ?? $entry->getRawOriginal('end_time'),
                'title' => $data['title'] ?? $entry->title,
                'description' => $data['description'] ?? $entry->description,
                'notes' => $data['notes'] ?? $entry->notes,
                'custom_fields' => $this->withMobileState($entry, $state),
                ...array_intersect_key($data, array_flip(['start_at', 'end_at', 'timezone', 'timezone_requires_reconciliation', 'net_minutes', 'net_microseconds', 'break_intervals'])),
            ])->save();

            if (! $entry->submit()) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.correction_forbidden'));
            }

            return $this->findEntry((int) $entry->organization_id, (int) $entry->user_id, (int) $entry->id, 'time_tracking.submit');
        });
    }

    private function baseQuery(int $organizationId, int $userId, array $filters): Builder
    {
        return $this->withRelations(TimeEntry::query())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('project_id', $this->projectAccess->query(User::query()->findOrFail($userId), $organizationId)->select('projects.id'))
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
            if (! $entry instanceof TimeEntry) {
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

    private function assertProject(User $user, int $organizationId, int $projectId): void
    {
        $this->projectAccess->assert(
            $user,
            $organizationId,
            $projectId,
            trans_message('time_tracking.mobile.errors.project_not_found'),
        );
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

        if (! $exists) {
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

        if (! $exists) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.task_not_found'));
        }
    }

    private function resolveWorkingTimezone(int $organizationId, int $userId, array $data): array
    {
        $explicitId = isset($data['employment_contract_id']) ? (int) $data['employment_contract_id'] : null;
        $resolved = app(WorkingTimezoneResolver::class)->resolve($organizationId, $userId, (string) $data['work_date'], $explicitId);
        $custom = $data['custom_fields'] ?? [];
        if ($explicitId !== null || $resolved['employment_contract_id'] !== null) {
            $custom['employment_contract_id'] = $explicitId ?? $resolved['employment_contract_id'];
        }
        $custom['time_tracking_interval']['clock_source'] = ! empty($data['start_at']) ? 'absolute' : 'legacy_local';
        if (empty($data['timezone']) && $resolved['timezone'] !== null) {
            $data['timezone'] = $resolved['timezone'];
        }
        $data['custom_fields'] = $custom;

        return $data;
    }

    private function normalizeInterval(array $data): array
    {
        if ((! empty($data['start_at']) || ! empty($data['start_time'])) && (! empty($data['end_at']) || ! empty($data['end_time']))) {
            try {
                return array_replace($data, WorkTimeInterval::fromInput($data, (string) config('app.timezone', 'UTC'))->persistence());
            } catch (\Throwable $exception) {
                throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_duration'));
            }
        }
        if (! empty($data['start_time']) || ! empty($data['end_time']) || ! empty($data['start_at']) || ! empty($data['end_at'])) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_time'));
        }

        return $data;
    }

    private function assertActor(User $user, int $organizationId, string $permission): void
    {
        if ($organizationId <= 0 || ! $user->organizations()->where('organizations.id', $organizationId)->wherePivot('is_active', true)->exists()
            || ! app(AuthorizationService::class)->can($user, $permission, ['organization_id' => $organizationId])) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.permission_denied'), 403);
        }
    }

    private function assertEntryActor(TimeEntry $entry, int $userId, string $permission): void
    {
        $user = User::query()->find($userId);
        if (! $user || (int) $entry->user_id !== $userId) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.permission_denied'), 403);
        }
        $this->assertActor($user, (int) $entry->organization_id, $permission);
        $this->assertProject($user, (int) $entry->organization_id, (int) $entry->project_id);
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
