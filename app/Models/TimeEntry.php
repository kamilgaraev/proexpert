<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class TimeEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'worker_type',
        'worker_name',
        'worker_count',
        'project_id',
        'work_type_id',
        'task_id',
        'work_date',
        'start_time',
        'end_time',
        'hours_worked',
        'break_time',
        'volume_completed',
        'title',
        'description',
        'status',
        'approved_by_user_id',
        'approved_at',
        'rejection_reason',
        'is_billable',
        'hourly_rate',
        'location',
        'custom_fields',
        'notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'hours_worked' => 'decimal:2',
        'break_time' => 'decimal:2',
        'volume_completed' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'is_billable' => 'boolean',
        'worker_count' => 'integer',
        'custom_fields' => 'array',
        'approved_at' => 'datetime',
    ];

    /**
     * Получить организацию, к которой относится запись времени.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить пользователя, который создал запись времени.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить проект, к которому относится запись времени.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить тип работы.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Получить задачу из расписания.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    /**
     * Получить пользователя, который утвердил запись.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Скоупы для фильтрации
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('work_date', [$startDate, $endDate]);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBillable($query, $billable = true)
    {
        return $query->where('is_billable', $billable);
    }

    /**
     * Вычислить общую стоимость времени
     */
    public function getTotalCostAttribute()
    {
        if (!$this->is_billable || !$this->hourly_rate) {
            return 0;
        }
        
        return $this->hours_worked * $this->hourly_rate;
    }

    /**
     * Проверить, можно ли редактировать запись
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    /**
     * Проверить, можно ли утвердить запись
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Утвердить запись времени
     */
    public function approve(User $approver): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->update([
            'status' => 'approved',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return true;
    }

    /**
     * Отклонить запись времени
     */
    public function reject(User $rejector, string $reason): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'approved_by_user_id' => $rejector->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return true;
    }

    /**
     * Отправить на утверждение
     */
    public function submit(): bool
    {
        if (!$this->canBeEdited()) {
            return false;
        }

        $this->update([
            'status' => 'submitted',
            'approved_by_user_id' => null,
            'approved_at' => null,
            'rejection_reason' => null,
        ]);

        return true;
    }

    /**
     * Автоматически вычислить часы работы по времени начала и окончания
     */
    public function calculateHoursFromTimes(): void
    {
        if ($this->start_time && $this->end_time) {
            $start = Carbon::parse($this->start_time);
            $end = Carbon::parse($this->end_time);
            
            // Если время окончания меньше времени начала, значит работа продолжилась на следующий день
            if ($end->lt($start)) {
                $end->addDay();
            }
            
            $totalMinutes = $end->diffInMinutes($start);
            $breakMinutes = $this->break_time * 60;
            $workMinutes = $totalMinutes - $breakMinutes;
            
            $this->hours_worked = round($workMinutes / 60, 2);
        }
    }

    /**
     * Получить статус на русском языке
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Черновик',
            'submitted' => 'На утверждении',
            'approved' => 'Утверждено',
            'rejected' => 'Отклонено',
            default => 'Неизвестно'
        };
    }

    public function isVirtualWorker(): bool
    {
        return $this->worker_type === 'virtual';
    }

    public function isBrigade(): bool
    {
        return $this->worker_type === 'brigade';
    }

    public function isRegisteredUser(): bool
    {
        return $this->worker_type === 'user';
    }

    public function getWorkerDisplayNameAttribute(): string
    {
        if ($this->isRegisteredUser() && $this->user) {
            return $this->user->name;
        }
        
        if ($this->isBrigade()) {
            $count = $this->worker_count ? " ({$this->worker_count} чел.)" : '';
            return ($this->worker_name ?? 'Бригада') . $count;
        }
        
        return $this->worker_name ?? 'Не указан';
    }

    public function scopeForWorkerType($query, string $workerType)
    {
        return $query->where('worker_type', $workerType);
    }

    public function scopeForWorkerName($query, string $workerName)
    {
        return $query->where('worker_name', $workerName);
    }
}