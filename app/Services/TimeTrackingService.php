<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Project;
use App\Models\Organization;
use App\Services\Logging\LoggingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class TimeTrackingService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
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
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = TimeEntry::with(['user', 'project', 'workType', 'task', 'approvedBy'])
            ->orderBy('work_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($organizationId) {
            $query->forOrganization($organizationId);
        }

        if ($userId) {
            $query->forUser($userId);
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
     * Создать новую запись времени
     */
    public function createTimeEntry(array $data): TimeEntry
    {
        $startTime = microtime(true);
        $organizationId = $data['organization_id'] ?? Auth::user()?->current_organization_id;
        $userId = $data['user_id'] ?? Auth::id();
        
        $this->logging->business('time_tracking.entry.creation.started', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'project_id' => $data['project_id'] ?? null,
            'work_date' => $data['work_date'] ?? null,
            'hours_worked' => $data['hours_worked'] ?? null,
            'is_billable' => $data['is_billable'] ?? true
        ]);

        DB::beginTransaction();
        
        try {
            // Валидация данных
            $validationStart = microtime(true);
            $this->validateTimeEntryData($data);
            $validationDuration = (microtime(true) - $validationStart) * 1000;
            
            $this->logging->technical('time_tracking.validation.completed', [
                'validation_duration_ms' => $validationDuration,
                'organization_id' => $organizationId,
                'user_id' => $userId
            ]);
            
            // Создание записи
            $timeEntry = TimeEntry::create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'project_id' => $data['project_id'],
                'work_type_id' => $data['work_type_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'work_date' => $data['work_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'hours_worked' => $data['hours_worked'],
                'break_time' => $data['break_time'] ?? 0,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'is_billable' => $data['is_billable'] ?? true,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'location' => $data['location'] ?? null,
                'custom_fields' => $data['custom_fields'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Автоматический расчет часов, если указано время начала и окончания
            if (isset($data['start_time']) && isset($data['end_time']) && !isset($data['hours_worked'])) {
                $timeEntry->calculateHoursFromTimes();
                $timeEntry->save();
                
                $this->logging->technical('time_tracking.entry.hours_calculated', [
                    'time_entry_id' => $timeEntry->id,
                    'calculated_hours' => $timeEntry->hours_worked,
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time']
                ]);
            }

            DB::commit();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('time_tracking.entry.created', [
                'time_entry_id' => $timeEntry->id,
                'organization_id' => $timeEntry->organization_id,
                'user_id' => $timeEntry->user_id,
                'project_id' => $timeEntry->project_id,
                'work_date' => $timeEntry->work_date,
                'hours_worked' => $timeEntry->hours_worked,
                'is_billable' => $timeEntry->is_billable,
                'status' => $timeEntry->status,
                'duration_ms' => $duration
            ]);
            
            $this->logging->audit('time_tracking.entry.created', [
                'time_entry_id' => $timeEntry->id,
                'organization_id' => $timeEntry->organization_id,
                'user_id' => $timeEntry->user_id,
                'project_id' => $timeEntry->project_id,
                'hours_worked' => $timeEntry->hours_worked,
                'is_billable' => $timeEntry->is_billable,
                'performed_by' => $userId
            ]);
            
            return $timeEntry->load(['user', 'project', 'workType', 'task']);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('time_tracking.entry.creation.failed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'project_id' => $data['project_id'] ?? null,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
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
            $timeEntry = TimeEntry::find($id);
            
            if (!$timeEntry || !$timeEntry->canBeEdited()) {
                return null;
            }

            // Валидация данных
            $this->validateTimeEntryData($data, $timeEntry);

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
        $timeEntry = TimeEntry::find($id);
        
        if (!$timeEntry || !$timeEntry->canBeEdited()) {
            return false;
        }

        return $timeEntry->delete();
    }

    /**
     * Утвердить запись времени
     */
    public function approveTimeEntry(int $id, User $approver): bool
    {
        $this->logging->business('time_tracking.entry.approval.started', [
            'time_entry_id' => $id,
            'approver_id' => $approver->id
        ]);

        try {
            $timeEntry = TimeEntry::find($id);
            
            if (!$timeEntry) {
                $this->logging->business('time_tracking.entry.approval.not_found', [
                    'time_entry_id' => $id,
                    'approver_id' => $approver->id
                ], 'warning');
                
                return false;
            }

            $previousStatus = $timeEntry->status;
            $result = $timeEntry->approve($approver);
            
            if ($result) {
                $this->logging->business('time_tracking.entry.approved', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'user_id' => $timeEntry->user_id,
                    'project_id' => $timeEntry->project_id,
                    'hours_worked' => $timeEntry->hours_worked,
                    'is_billable' => $timeEntry->is_billable,
                    'approver_id' => $approver->id,
                    'previous_status' => $previousStatus
                ]);
                
                $this->logging->audit('time_tracking.entry.approved', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'user_id' => $timeEntry->user_id,
                    'hours_worked' => $timeEntry->hours_worked,
                    'performed_by' => $approver->id,
                    'previous_status' => $previousStatus
                ]);
                
                $this->logging->security('time_tracking.entry.status_change', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'changed_by' => $approver->id,
                    'from_status' => $previousStatus,
                    'to_status' => 'approved',
                    'action' => 'approve'
                ]);
            } else {
                $this->logging->business('time_tracking.entry.approval.failed', [
                    'time_entry_id' => $timeEntry->id,
                    'approver_id' => $approver->id,
                    'current_status' => $timeEntry->status
                ], 'warning');
            }

            return $result;
            
        } catch (Exception $e) {
            $this->logging->technical('time_tracking.entry.approval.error', [
                'time_entry_id' => $id,
                'approver_id' => $approver->id,
                'error' => $e->getMessage()
            ], 'error');
            
            return false;
        }
    }

    /**
     * Отклонить запись времени
     */
    public function rejectTimeEntry(int $id, User $rejector, string $reason): bool
    {
        $this->logging->business('time_tracking.entry.rejection.started', [
            'time_entry_id' => $id,
            'rejector_id' => $rejector->id,
            'rejection_reason_provided' => !empty($reason)
        ]);

        try {
            $timeEntry = TimeEntry::find($id);
            
            if (!$timeEntry) {
                $this->logging->business('time_tracking.entry.rejection.not_found', [
                    'time_entry_id' => $id,
                    'rejector_id' => $rejector->id
                ], 'warning');
                
                return false;
            }

            $previousStatus = $timeEntry->status;
            $result = $timeEntry->reject($rejector, $reason);
            
            if ($result) {
                $this->logging->business('time_tracking.entry.rejected', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'user_id' => $timeEntry->user_id,
                    'project_id' => $timeEntry->project_id,
                    'hours_worked' => $timeEntry->hours_worked,
                    'rejector_id' => $rejector->id,
                    'rejection_reason' => $reason,
                    'previous_status' => $previousStatus
                ]);
                
                $this->logging->audit('time_tracking.entry.rejected', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'user_id' => $timeEntry->user_id,
                    'hours_worked' => $timeEntry->hours_worked,
                    'performed_by' => $rejector->id,
                    'rejection_reason' => $reason,
                    'previous_status' => $previousStatus
                ]);
                
                $this->logging->security('time_tracking.entry.status_change', [
                    'time_entry_id' => $timeEntry->id,
                    'organization_id' => $timeEntry->organization_id,
                    'changed_by' => $rejector->id,
                    'from_status' => $previousStatus,
                    'to_status' => 'rejected',
                    'action' => 'reject',
                    'reason' => $reason
                ]);
            } else {
                $this->logging->business('time_tracking.entry.rejection.failed', [
                    'time_entry_id' => $timeEntry->id,
                    'rejector_id' => $rejector->id,
                    'current_status' => $timeEntry->status
                ], 'warning');
            }

            return $result;
            
        } catch (Exception $e) {
            $this->logging->technical('time_tracking.entry.rejection.error', [
                'time_entry_id' => $id,
                'rejector_id' => $rejector->id,
                'error' => $e->getMessage()
            ], 'error');
            
            return false;
        }
    }

    /**
     * Отправить запись на утверждение
     */
    public function submitTimeEntry(int $id): bool
    {
        $timeEntry = TimeEntry::find($id);
        
        if (!$timeEntry) {
            return false;
        }

        return $timeEntry->submit();
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
            'has_filters' => !is_null($organizationId) || !is_null($userId) || !is_null($projectId)
        ]);

        try {
            $query = TimeEntry::query();

            if ($organizationId) {
                $query->forOrganization($organizationId);
            }

            if ($userId) {
                $query->forUser($userId);
            }

            if ($projectId) {
                $query->forProject($projectId);
            }

            if ($startDate && $endDate) {
                $query->forDateRange($startDate, $endDate);
            }

            // ДИАГНОСТИКА: Измеряем время каждого запроса
            $queryTimes = [];
            
            $queryStart = microtime(true);
            $totalHours = $query->sum('hours_worked');
            $queryTimes['total_hours'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $billableHours = $query->billable(true)->sum('hours_worked');
            $queryTimes['billable_hours'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $nonBillableHours = $query->billable(false)->sum('hours_worked');
            $queryTimes['non_billable_hours'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $totalCost = $query->billable(true)->whereNotNull('hourly_rate')
                ->get()
                ->sum(function ($entry) {
                    return $entry->hours_worked * $entry->hourly_rate;
                });
            $queryTimes['total_cost'] = (microtime(true) - $queryStart) * 1000;

            $queryStart = microtime(true);
            $entriesCount = $query->count();
            $queryTimes['entries_count'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $approvedEntries = $query->byStatus('approved')->count();
            $queryTimes['approved_entries'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $pendingEntries = $query->byStatus('submitted')->count();
            $queryTimes['pending_entries'] = (microtime(true) - $queryStart) * 1000;
            
            $queryStart = microtime(true);
            $rejectedEntries = $query->byStatus('rejected')->count();
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
                    'approval_rate' => $statistics['entries_count'] > 0 ? round(($statistics['approved_entries'] / $statistics['entries_count']) * 100, 1) : 0
                ],
                'duration_ms' => $duration
            ]);
            
            if ($duration > 2000) {
                $this->logging->technical('time_tracking.statistics.slow', [
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'duration_ms' => $duration,
                    'query_times_ms' => $queryTimes,
                    'entries_count' => $entriesCount
                ], 'warning');
            }

            return $statistics;
            
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('time_tracking.statistics.failed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }

    /**
     * Получить записи времени по дням для календаря
     */
    public function getTimeEntriesByDays(
        int $organizationId,
        ?int $userId = null,
        ?int $projectId = null,
        string $startDate,
        string $endDate
    ): array {
        $query = TimeEntry::with(['user', 'project', 'workType'])
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
        ?int $userId = null,
        ?int $projectId = null,
        string $startDate,
        string $endDate,
        string $groupBy = 'user' // user, project, work_type, date
    ): array {
        $query = TimeEntry::with(['user', 'project', 'workType'])
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

        return match($groupBy) {
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
        // Проверка существования проекта
        if (isset($data['project_id'])) {
            $project = Project::find($data['project_id']);
            if (!$project) {
                throw new Exception('Проект не найден');
            }
        }

        // Проверка корректности времени
        if (isset($data['start_time']) && isset($data['end_time'])) {
            $start = Carbon::parse($data['start_time']);
            $end = Carbon::parse($data['end_time']);
            
            if ($end->lte($start)) {
                throw new Exception('Время окончания должно быть больше времени начала');
            }
        }

        // Проверка количества часов
        if (isset($data['hours_worked']) && $data['hours_worked'] <= 0) {
            throw new Exception('Количество часов должно быть больше нуля');
        }

        // Проверка даты (не в будущем)
        if (isset($data['work_date'])) {
            $workDate = Carbon::parse($data['work_date']);
            if ($workDate->isFuture()) {
                throw new Exception('Дата работы не может быть в будущем');
            }
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
                'entries' => $userEntries->toArray()
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
                'entries' => $projectEntries->toArray()
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
                'entries' => $workTypeEntries->toArray()
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
                'entries' => $dateEntries->toArray()
            ];
        })->values()->toArray();
    }
}