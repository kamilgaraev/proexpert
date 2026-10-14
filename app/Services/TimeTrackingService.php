<?php

declare(strict_types=1);

namespace App\Services;

use App\BusinessModules\Features\TimeTracking\Reporting\ApprovedTimeEntryReportingFactRecorder;
use App\BusinessModules\Features\TimeTracking\Support\WorkingTimezoneResolver;
use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ScheduleTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Logging\LoggingService;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TimeTrackingService
{
    protected LoggingService $logging;

    public function __construct(
        LoggingService $logging,
        private readonly ApprovedTimeEntryReportingFactRecorder $reportingFacts,
    ) {
        $this->logging = $logging;
    }

    /**
     * Получить записи времени с пагинацией
     */
    public function getTimeEntries(
        ?int $organizationId = null,
        ?int $userId = null,
        ?int $projectId = null,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?bool $billable = null,
        int $perPage = 15,
        ?string $workerType = null,
        ?string $workerName = null
    ): LengthAwarePaginator {
        $organizationId = $this->readOrganization($organizationId);
        $query = $this->readQuery($organizationId)->with(['user', 'project', 'workType', 'task', 'approvedBy'])
            ->orderBy('work_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($organizationId) {
            $query->forOrganization($organizationId);
        }

        if ($userId) {
            $query->forUser($userId);
        }

        if ($workerType) {
            $query->where('worker_type', $workerType);
        }

        if ($workerName) {
            $query->where('worker_name', $workerName);
        }

        if ($projectId) {
            $query->forProject($projectId);
        }

        if ($status) {
            $query->byStatus($status);
        }

        if ($startDate && $endDate) {
            $query->forDateRange($startDate, $endDate);
        }

        if ($billable !== null) {
            $query->billable($billable);
        }

        return $query->paginate($perPage);
    }

    /**
     * Получить плоский список всех доступных исполнителей для фильтров
     */
    public function getWorkers(int $organizationId): Collection
    {
        $this->assertActor($organizationId, 'time_tracking.view');
        // 1. Реальные пользователи организации
        $users = User::whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organizations.id', $organizationId);
        })->get(['id', 'name'])->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'type' => 'user',
        ]);

        // 2. Виртуальные работники из записей времени
        $virtuals = $this->readQuery($organizationId)
            ->where('worker_type', 'virtual')
            ->whereNotNull('worker_name')
            ->distinct()
            ->pluck('worker_name')
            ->map(fn ($name) => [
                'id' => $name,
                'name' => $name.' (вирт.)',
                'type' => 'virtual',
            ]);

        // 3. Бригады из записей времени
        $brigades = $this->readQuery($organizationId)
            ->where('worker_type', 'brigade')
            ->whereNotNull('worker_name')
            ->distinct()
            ->pluck('worker_name')
            ->map(fn ($name) => [
                'id' => $name,
                'name' => $name,
                'type' => 'brigade',
            ]);

        return $users->concat($virtuals)->concat($brigades);
    }

    /**
     * Создать новую запись времени
     */
    public function createTimeEntry(array $data): TimeEntry
    {
        $startTime = microtime(true);
        $organizationId = (int) ($data['organization_id'] ?? Auth::user()?->current_organization_id);
        $this->assertActor($organizationId, 'time_tracking.create');
        $data['organization_id'] = $organizationId;
        $userId = $data['user_id'] ?? null;
        $workerType = $data['worker_type'] ?? 'user';

        $this->logging->business('time_tracking.entry.creation.started', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'worker_type' => $workerType,
            'worker_name' => $data['worker_name'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'work_date' => $data['work_date'] ?? null,
            'hours_worked' => $data['hours_worked'] ?? null,
            'is_billable' => $data['is_billable'] ?? true,
        ]);

        DB::beginTransaction();

        try {
            app(WorkforcePeriodMutationGuard::class)->lockOrganization($organizationId);
            $data = $this->resolveWorkingTimezone($organizationId, $data);
            $data = $this->normalizeInterval($data);
            $validationStart = microtime(true);
            $this->validateTimeEntryData($data);
            app(WorkforcePeriodMutationGuard::class)->assertMutable($organizationId, (int) $data['project_id'], $data);
            $validationDuration = (microtime(true) - $validationStart) * 1000;

            $this->logging->technical('time_tracking.validation.completed', [
                'validation_duration_ms' => $validationDuration,
                'organization_id' => $organizationId,
                'worker_type' => $workerType,
            ]);

            $timeEntry = TimeEntry::create([
                'organization_id' => $organizationId,
                'user_id' => $workerType === 'user' ? $userId : null,
                'worker_type' => $workerType,
                'worker_name' => $data['worker_name'] ?? null,
                'worker_count' => $data['worker_count'] ?? null,
                'project_id' => $data['project_id'],
                'work_type_id' => $data['work_type_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'work_date' => $data['work_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'hours_worked' => $data['hours_worked'] ?? null,
                'break_time' => $data['break_time'] ?? 0,
                'volume_completed' => $data['volume_completed'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'is_billable' => $data['is_billable'] ?? true,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'location' => $data['location'] ?? null,
                'custom_fields' => $data['custom_fields'] ?? null,
                'notes' => $data['notes'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'end_at' => $data['end_at'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'timezone_requires_reconciliation' => $data['timezone_requires_reconciliation'] ?? empty($data['timezone']),
                'net_minutes' => $data['net_minutes'] ?? null,
                'net_microseconds' => $data['net_microseconds'] ?? null,
                'break_intervals' => $data['break_intervals'] ?? null,
            ]);

            if (! isset($data['hours_worked']) && isset($data['start_time']) && isset($data['end_time'])) {
                $timeEntry->calculateHoursFromTimes();
                $timeEntry->save();

                $this->logging->technical('time_tracking.entry.hours_calculated', [
                    'time_entry_id' => $timeEntry->id,
                    'calculated_hours' => $timeEntry->hours_worked,
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'break_time' => $timeEntry->break_time,
                ]);
            }

            DB::commit();

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logging->business('time_tracking.entry.created', [
                'time_entry_id' => $timeEntry->id,
                'organization_id' => $timeEntry->organization_id,
                'user_id' => $timeEntry->user_id,
                'worker_type' => $timeEntry->worker_type,
                'worker_name' => $timeEntry->worker_name,
                'project_id' => $timeEntry->project_id,
                'work_date' => $timeEntry->work_date instanceof \Carbon\Carbon ? $timeEntry->work_date->format('Y-m-d') : (string) $timeEntry->work_date,
                'hours_worked' => $timeEntry->hours_worked,
                'volume_completed' => $timeEntry->volume_completed,
                'is_billable' => $timeEntry->is_billable,
                'status' => $timeEntry->status,
                'duration_ms' => $duration,
            ]);

            $this->logging->audit('time_tracking.entry.created', [
                'time_entry_id' => $timeEntry->id,
                'organization_id' => $timeEntry->organization_id,
                'user_id' => $timeEntry->user_id,
                'worker_type' => $timeEntry->worker_type,
                'project_id' => $timeEntry->project_id,
                'hours_worked' => $timeEntry->hours_worked,
                'is_billable' => $timeEntry->is_billable,
                'performed_by' => Auth::id(),
            ]);

            return $timeEntry->load(['user', 'project', 'workType', 'task']);

        } catch (Exception $e) {
            DB::rollBack();

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logging->technical('time_tracking.entry.creation.failed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'worker_type' => $workerType,
                'project_id' => $data['project_id'] ?? null,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ], 'error');

            throw $e;
        }
    }

    /**
     * Обновить запись времени
     */
    public function updateTimeEntry(int $id, array $data): ?TimeEntry
    {
        DB::beginTransaction();

        try {
            $organizationId = (int) Auth::user()?->current_organization_id;
            app(WorkforcePeriodMutationGuard::class)->lockOrganization($organizationId);
            $timeEntry = TimeEntry::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id);

            if (! $timeEntry || ! $timeEntry->canBeEdited()) {
                DB::rollBack();

                return null;
            }

            $this->assertEntryActor($timeEntry, 'time_tracking.edit');
            unset($data['organization_id']);
            $wasTimezoneUnconfirmed = (bool) $timeEntry->timezone_requires_reconciliation;
            $merged = array_replace($timeEntry->getAttributes(), $data);
            $merged['custom_fields'] = $data['custom_fields'] ?? $timeEntry->custom_fields ?? [];
            if (isset($data['employment_contract_id']) || isset($merged['custom_fields']['employment_contract_id'])) {
                $resolved = $this->resolveWorkingTimezone((int) $timeEntry->organization_id, $merged);
                $merged['custom_fields'] = $resolved['custom_fields'];
                $data['custom_fields'] = $resolved['custom_fields'];
            }
            if (! empty($data['timezone'])) {
                $merged['timezone_requires_reconciliation'] = false;
            }
            $merged['work_date'] = $data['work_date'] ?? $timeEntry->work_date->format('Y-m-d');
            $merged['break_intervals'] = $data['break_intervals'] ?? $timeEntry->break_intervals ?? [];
            foreach (['start', 'end'] as $boundary) {
                $key = $boundary.'_at';
                if ((array_key_exists($boundary.'_time', $data) || ($wasTimezoneUnconfirmed && ! empty($data['timezone']) && ($timeEntry->custom_fields['time_tracking_interval']['clock_source'] ?? 'legacy_local') === 'legacy_local')) && ! array_key_exists($key, $data)) {
                    $merged[$key] = null;
                } elseif (! array_key_exists($key, $data) && $timeEntry->{$key}) {
                    $merged[$key] = $timeEntry->{$key}->utc()->format('Y-m-d\\TH:i:s.u\\Z');
                }
            }
            if (! array_key_exists('status', $data)) {
                unset($merged['status']);
            }
            $this->validateTimeEntryData($merged, $timeEntry);
            $data = array_replace($data, array_intersect_key($this->normalizeInterval($merged), array_flip(['start_at', 'end_at', 'timezone', 'timezone_requires_reconciliation', 'start_time', 'end_time', 'work_date', 'net_minutes', 'net_microseconds', 'break_intervals', 'hours_worked', 'break_time'])));

            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->assertMutable($organizationId, (int) $timeEntry->project_id, $guard->entryFacts($timeEntry));
            $guard->assertMutable($organizationId, (int) ($data['project_id'] ?? $timeEntry->project_id), array_replace($guard->entryFacts($timeEntry), $data));
            $timeEntry->update($data);

            // Автоматический расчет часов, если указано время начала и окончания
            if ((isset($data['start_time']) || isset($data['end_time'])) &&
                $timeEntry->start_time && $timeEntry->end_time) {
                $timeEntry->calculateHoursFromTimes();
                $timeEntry->save();
            }

            DB::commit();

            return $timeEntry->load(['user', 'project', 'workType', 'task']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Удалить запись времени
     */
    public function deleteTimeEntry(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $organizationId = (int) Auth::user()?->current_organization_id;
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $entry = TimeEntry::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id);
            if (! $entry || ! $entry->canBeEdited()) {
                return false;
            }
            $this->assertEntryActor($entry, 'time_tracking.edit');
            $guard->assertMutable($organizationId, (int) $entry->project_id, $guard->entryFacts($entry));

            return (bool) $entry->delete();
        });
    }

    /**
     * Утвердить запись времени
     */
    public function approveTimeEntry(int $id, User $approver): bool
    {
        return DB::transaction(function () use ($id, $approver): bool {
            $organizationId = (int) $approver->current_organization_id;
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $entry = TimeEntry::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id);
            if (! $entry) {
                return false;
            }
            $this->assertEntryActor($entry, 'time_tracking.approve', $approver);
            $guard->assertMutable($organizationId, (int) $entry->project_id, $guard->entryFacts($entry));
            $previousStatus = $entry->status;
            $approved = $entry->approve($approver);
            if ($approved) {
                $this->reportingFacts->record($entry->refresh());
                $this->logging->audit('time_tracking.entry.approved', ['time_entry_id' => $id, 'organization_id' => $organizationId, 'performed_by' => $approver->id, 'previous_status' => $previousStatus]);
            }

            return $approved;
        });
    }

    /**
     * Отклонить запись времени
     */
    public function rejectTimeEntry(int $id, User $rejector, string $reason): bool
    {
        return DB::transaction(function () use ($id, $rejector, $reason): bool {
            $organizationId = (int) $rejector->current_organization_id;
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $entry = TimeEntry::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id);
            if (! $entry) {
                return false;
            }
            $this->assertEntryActor($entry, 'time_tracking.reject', $rejector);
            $guard->assertMutable($organizationId, (int) $entry->project_id, $guard->entryFacts($entry));
            $previousStatus = $entry->status;
            $rejected = $entry->reject($rejector, $reason);
            if ($rejected) {
                $this->logging->audit('time_tracking.entry.rejected', ['time_entry_id' => $id, 'organization_id' => $organizationId, 'performed_by' => $rejector->id, 'previous_status' => $previousStatus, 'rejection_reason' => $reason]);
            }

            return $rejected;
        });
    }

    /**
     * Отправить запись на утверждение
     */
    public function submitTimeEntry(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $organizationId = (int) Auth::user()?->current_organization_id;
            $guard = app(WorkforcePeriodMutationGuard::class);
            $guard->lockOrganization($organizationId);
            $entry = TimeEntry::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id);
            if (! $entry) {
                return false;
            }
            $this->assertEntryActor($entry, 'time_tracking.submit');
            $guard->assertMutable($organizationId, (int) $entry->project_id, $guard->entryFacts($entry));

            return $entry->submit();
        });
    }

    /**
     * Получить статистику по времени
     */
    public function getTimeStatistics(
        ?int $organizationId = null,
        ?int $userId = null,
        ?int $projectId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $startTime = microtime(true);

        $this->logging->business('time_tracking.statistics.requested', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'date_range' => $startDate && $endDate ? [$startDate, $endDate] : null,
            'has_filters' => ! is_null($organizationId) || ! is_null($userId) || ! is_null($projectId),
        ]);

        try {
            $organizationId = $this->readOrganization($organizationId);
            $baseQuery = $this->readQuery($organizationId);

            if ($organizationId) {
                $baseQuery->forOrganization($organizationId);
            }

            if ($userId) {
                $baseQuery->forUser($userId);
            }

            if ($projectId) {
                $baseQuery->forProject($projectId);
            }

            if ($startDate && $endDate) {
                $baseQuery->forDateRange($startDate, $endDate);
            }

            $queryTimes = [];

            $queryStart = microtime(true);
            $totalHours = $this->sumExactHours(clone $baseQuery);
            $queryTimes['total_hours'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $billableHours = $this->sumExactHours((clone $baseQuery)->billable(true));
            $queryTimes['billable_hours'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $nonBillableHours = $this->sumExactHours((clone $baseQuery)->billable(false));
            $queryTimes['non_billable_hours'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $totalCost = (clone $baseQuery)->billable(true)->whereNotNull('hourly_rate')
                ->get()
                ->sum(function ($entry) {
                    return $entry->hours_worked * $entry->hourly_rate;
                });
            $queryTimes['total_cost'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $entriesCount = (clone $baseQuery)->count();
            $queryTimes['entries_count'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $approvedEntries = (clone $baseQuery)->byStatus('approved')->count();
            $queryTimes['approved_entries'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $pendingEntries = (clone $baseQuery)->byStatus('submitted')->count();
            $queryTimes['pending_entries'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $rejectedEntries = (clone $baseQuery)->byStatus('rejected')->count();
            $queryTimes['rejected_entries'] = (microtime(true) - $queryStart) * 1000;

            $duration = (microtime(true) - $startTime) * 1000;

            $statistics = [
                'total_hours' => round($totalHours, 2),
                'billable_hours' => round($billableHours, 2),
                'non_billable_hours' => round($nonBillableHours, 2),
                'total_cost' => round($totalCost, 2),
                'entries_count' => $entriesCount,
                'approved_entries' => $approvedEntries,
                'pending_entries' => $pendingEntries,
                'rejected_entries' => $rejectedEntries,
            ];

            $this->logging->business('time_tracking.statistics.completed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'statistics_summary' => [
                    'total_hours' => $statistics['total_hours'],
                    'entries_count' => $statistics['entries_count'],
                    'billable_percentage' => $statistics['total_hours'] > 0 ? round(($statistics['billable_hours'] / $statistics['total_hours']) * 100, 1) : 0,
                    'approval_rate' => $statistics['entries_count'] > 0 ? round(($statistics['approved_entries'] / $statistics['entries_count']) * 100, 1) : 0,
                ],
                'duration_ms' => $duration,
            ]);

            if ($duration > 2000) {
                $this->logging->technical('time_tracking.statistics.slow', [
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'duration_ms' => $duration,
                    'query_times_ms' => $queryTimes,
                    'entries_count' => $entriesCount,
                ], 'warning');
            }

            return $statistics;

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::error('[TimeTrackingService] Ошибка статистики: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Получить записи времени по дням для календаря
     */
    public function getTimeEntriesByDays(
        int $organizationId,
        string $startDate,
        string $endDate,
        ?int $userId = null,
        ?int $projectId = null
    ): array {
        $organizationId = $this->readOrganization($organizationId);
        $query = $this->readQuery($organizationId)->with(['user', 'project', 'workType'])
            ->forOrganization($organizationId)
            ->forDateRange($startDate, $endDate)
            ->orderBy('work_date')
            ->orderBy('start_time');

        if ($userId) {
            $query->forUser($userId);
        }

        if ($projectId) {
            $query->forProject($projectId);
        }

        $entries = $query->get();

        return $entries->groupBy(function ($entry) {
            return $entry->work_date->format('Y-m-d');
        })->toArray();
    }

    /**
     * Получить отчет по времени для экспорта
     */
    public function getTimeReport(
        int $organizationId,
        string $startDate,
        string $endDate,
        ?int $userId = null,
        ?int $projectId = null,
        string $groupBy = 'user'
    ): array {
        $organizationId = $this->readOrganization($organizationId);
        $query = $this->readQuery($organizationId)->with(['user', 'project', 'workType'])
            ->forOrganization($organizationId)
            ->forDateRange($startDate, $endDate)
            ->byStatus('approved');

        if ($userId) {
            $query->forUser($userId);
        }

        if ($projectId) {
            $query->forProject($projectId);
        }

        $entries = $query->get();

        return match ($groupBy) {
            'user' => $this->groupTimeEntriesByUser($entries),
            'project' => $this->groupTimeEntriesByProject($entries),
            'work_type' => $this->groupTimeEntriesByWorkType($entries),
            'date' => $this->groupTimeEntriesByDate($entries),
            default => $entries->toArray()
        };
    }

    /**
     * Валидация данных записи времени
     */
    private function validateTimeEntryData(array $data, ?TimeEntry $existingEntry = null): void
    {
        $organizationId = (int) ($data['organization_id'] ?? $existingEntry?->organization_id ?? Auth::user()?->current_organization_id);
        $this->assertActor($organizationId, $existingEntry ? 'time_tracking.edit' : 'time_tracking.create');
        $projectId = (int) ($data['project_id'] ?? $existingEntry?->project_id);
        $actor = Auth::user();
        if (! $actor instanceof User || ! app(UserProjectAccessService::class)->queryAccessibleProjects($actor, $organizationId)->whereKey($projectId)->exists()) {
            throw ValidationException::withMessages(['project_id' => trans_message('time_tracking.mobile.errors.project_not_found')]);
        }
        if (! empty($data['work_type_id']) && ! WorkType::query()->where('organization_id', $organizationId)->whereKey($data['work_type_id'])->exists()) {
            throw ValidationException::withMessages(['work_type_id' => trans_message('time_tracking.mobile.errors.work_type_not_found')]);
        }
        if (! empty($data['task_id']) && ! ScheduleTask::query()->where('organization_id', $organizationId)
            ->whereHas('schedule', fn ($query) => $query->where('project_id', $projectId))->whereKey($data['task_id'])->exists()) {
            throw ValidationException::withMessages(['task_id' => trans_message('time_tracking.mobile.errors.task_not_found')]);
        }
        if (($data['worker_type'] ?? 'user') === 'user' && (empty($data['user_id']) || ! User::query()->whereKey($data['user_id'])
            ->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organizationId)->where('organization_user.is_active', true))->exists())) {
            throw ValidationException::withMessages(['user_id' => trans_message('time_tracking.mobile.errors.permission_denied')]);
        }
        if (isset($data['status']) && ! in_array($data['status'], ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['status' => trans_message('time_tracking.already_approved')]);
        }
        $this->normalizeInterval($data);
        if (isset($data['hours_worked']) && ((float) $data['hours_worked'] <= 0 || (float) $data['hours_worked'] > 24)) {
            throw ValidationException::withMessages(['hours_worked' => trans_message('time_tracking.mobile.errors.invalid_duration')]);
        }
        if (isset($data['work_date']) && substr((string) $data['work_date'], 0, 10) > now()->format('Y-m-d')) {
            throw ValidationException::withMessages(['work_date' => trans_message('time_tracking.mobile.validation.work_date_future')]);
        }
    }

    private function resolveWorkingTimezone(int $organizationId, array $data): array
    {
        $custom = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
        $explicitId = isset($data['employment_contract_id']) ? (int) $data['employment_contract_id'] : (isset($custom['employment_contract_id']) ? (int) $custom['employment_contract_id'] : null);
        $userId = ($data['worker_type'] ?? 'user') === 'user' ? (int) ($data['user_id'] ?? 0) : 0;
        $resolved = app(WorkingTimezoneResolver::class)->resolve($organizationId, $userId, (string) $data['work_date'], $explicitId);
        if ($explicitId !== null || $resolved['employment_contract_id'] !== null) {
            $custom['employment_contract_id'] = $explicitId ?? $resolved['employment_contract_id'];
        }
        if (! array_key_exists('timezone_requires_reconciliation', $data) || ! isset($custom['time_tracking_interval']['clock_source'])) {
            $custom['time_tracking_interval']['clock_source'] = ! empty($data['start_at']) ? 'absolute' : 'legacy_local';
        }
        if (empty($data['timezone']) && $resolved['timezone'] !== null) {
            $data['timezone'] = $resolved['timezone'];
        }
        $data['custom_fields'] = $custom;

        return $data;
    }

    private function sumExactHours(\Illuminate\Database\Eloquent\Builder $query): float
    {
        return (float) $query->selectRaw('COALESCE(SUM(net_microseconds), 0) / 3600000000.0 + COALESCE(SUM(CASE WHEN net_microseconds IS NULL THEN COALESCE(net_minutes / 60.0, hours_worked) ELSE 0 END), 0) AS aggregate')->value('aggregate');
    }

    public function authorizeImport(int $organizationId, User $actor): void
    {
        $this->assertActor($organizationId, 'time_tracking.create', $actor);
    }

    public function validateImportRow(array $data): array
    {
        $data = $this->resolveWorkingTimezone((int) $data['organization_id'], $data);
        $data = $this->normalizeInterval($data);
        $this->validateTimeEntryData($data);
        app(WorkforcePeriodMutationGuard::class)->assertMutable((int) $data['organization_id'], (int) $data['project_id'], $data);

        return $data;
    }

    public function normalizeInterval(array $data): array
    {
        if ((! empty($data['start_at']) || ! empty($data['start_time'])) && (! empty($data['end_at']) || ! empty($data['end_time']))) {
            try {
                return array_replace($data, WorkTimeInterval::fromInput($data, (string) config('app.timezone', 'UTC'))->persistence());
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['end_time' => trans_message('time_tracking.mobile.errors.invalid_duration')]);
            }
        }
        if (! empty($data['start_at']) || ! empty($data['end_at']) || ! empty($data['start_time']) || ! empty($data['end_time'])) {
            throw ValidationException::withMessages(['end_time' => trans_message('time_tracking.mobile.errors.invalid_time')]);
        }

        return $data;
    }

    private function readOrganization(?int $organizationId): int
    {
        $organizationId ??= (int) Auth::user()?->current_organization_id;
        $this->assertActor($organizationId, 'time_tracking.view');

        return $organizationId;
    }

    private function readQuery(int $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        return TimeEntry::query()->where('organization_id', $organizationId)->whereIn('project_id',
            app(UserProjectAccessService::class)->queryAccessibleProjects(Auth::user(), $organizationId)->select('projects.id'));
    }

    private function assertActor(int $organizationId, string $permission, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User || $organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId
            || ! app(AuthorizationService::class)->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.permission_denied'), 403);
        }
    }

    private function assertEntryActor(TimeEntry $entry, string $permission, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        $this->assertActor((int) $entry->organization_id, $permission, $actor);
        if (! app(UserProjectAccessService::class)->queryAccessibleProjects($actor, (int) $entry->organization_id)->whereKey($entry->project_id)->exists()) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.project_not_found'), 403);
        }
    }

    /**
     * Группировка записей по пользователям
     */
    private function groupTimeEntriesByUser(Collection $entries): array
    {
        return $entries->groupBy('user_id')->map(function ($userEntries) {
            $user = $userEntries->first()->user;

            return [
                'user' => $user,
                'total_hours' => $userEntries->sum('hours_worked'),
                'billable_hours' => $userEntries->where('is_billable', true)->sum('hours_worked'),
                'entries_count' => $userEntries->count(),
                'entries' => $userEntries->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * Группировка записей по проектам
     */
    private function groupTimeEntriesByProject(Collection $entries): array
    {
        return $entries->groupBy('project_id')->map(function ($projectEntries) {
            $project = $projectEntries->first()->project;

            return [
                'project' => $project,
                'total_hours' => $projectEntries->sum('hours_worked'),
                'billable_hours' => $projectEntries->where('is_billable', true)->sum('hours_worked'),
                'entries_count' => $projectEntries->count(),
                'entries' => $projectEntries->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * Группировка записей по типам работ
     */
    private function groupTimeEntriesByWorkType(Collection $entries): array
    {
        return $entries->groupBy('work_type_id')->map(function ($workTypeEntries) {
            $workType = $workTypeEntries->first()->workType;

            return [
                'work_type' => $workType,
                'total_hours' => $workTypeEntries->sum('hours_worked'),
                'billable_hours' => $workTypeEntries->where('is_billable', true)->sum('hours_worked'),
                'entries_count' => $workTypeEntries->count(),
                'entries' => $workTypeEntries->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * Группировка записей по датам
     */
    private function groupTimeEntriesByDate(Collection $entries): array
    {
        return $entries->groupBy(function ($entry) {
            return $entry->work_date->format('Y-m-d');
        })->map(function ($dateEntries, $date) {
            return [
                'date' => $date,
                'total_hours' => $dateEntries->sum('hours_worked'),
                'billable_hours' => $dateEntries->where('is_billable', true)->sum('hours_worked'),
                'entries_count' => $dateEntries->count(),
                'entries' => $dateEntries->toArray(),
            ];
        })->values()->toArray();
    }
}
