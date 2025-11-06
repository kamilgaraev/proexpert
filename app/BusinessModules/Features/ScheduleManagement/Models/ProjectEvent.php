<?php

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Carbon\Carbon;

class ProjectEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'organization_id',
        'schedule_id',
        'related_task_id',
        'created_by_user_id',
        'event_type',
        'title',
        'description',
        'location',
        'event_date',
        'event_time',
        'duration_minutes',
        'is_all_day',
        'end_date',
        'is_blocking',
        'priority',
        'status',
        'participants',
        'responsible_users',
        'organizations',
        'reminder_before_hours',
        'reminder_sent',
        'reminder_sent_at',
        'is_recurring',
        'recurrence_pattern',
        'recurrence_config',
        'recurring_parent_id',
        'attachments',
        'notes',
        'custom_fields',
        'color',
        'icon',
    ];

    protected $casts = [
        'event_date' => 'date',
        'end_date' => 'date',
        'event_time' => 'datetime:H:i',
        'is_all_day' => 'boolean',
        'is_blocking' => 'boolean',
        'is_recurring' => 'boolean',
        'reminder_sent' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'participants' => 'array',
        'responsible_users' => 'array',
        'organizations' => 'array',
        'attachments' => 'array',
        'custom_fields' => 'array',
        'recurrence_config' => 'array',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'related_task_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function recurringParent(): BelongsTo
    {
        return $this->belongsTo(ProjectEvent::class, 'recurring_parent_id');
    }

    public function recurringChildren(): HasMany
    {
        return $this->hasMany(ProjectEvent::class, 'recurring_parent_id');
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    /**
     * Получить дату и время начала события
     */
    public function getStartDateTimeAttribute(): Carbon
    {
        if ($this->event_time && !$this->is_all_day) {
            return Carbon::parse($this->event_date->format('Y-m-d') . ' ' . $this->event_time);
        }
        return $this->event_date->startOfDay();
    }

    /**
     * Получить дату и время окончания события
     */
    public function getEndDateTimeAttribute(): Carbon
    {
        $start = $this->getStartDateTimeAttribute();
        
        if ($this->end_date) {
            return $this->end_date->endOfDay();
        }
        
        if ($this->is_all_day) {
            return $this->event_date->endOfDay();
        }
        
        return $start->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Проверка, прошло ли событие
     */
    public function getIsPastAttribute(): bool
    {
        return $this->event_date->isPast();
    }

    /**
     * Проверка, сегодня ли событие
     */
    public function getIsTodayAttribute(): bool
    {
        return $this->event_date->isToday();
    }

    /**
     * Проверка, скоро ли событие (в течение 24 часов)
     */
    public function getIsUpcomingAttribute(): bool
    {
        return $this->event_date->isFuture() && 
               $this->event_date->diffInHours(now()) <= 24;
    }

    /**
     * Получить цвет события в зависимости от типа
     */
    public function getEventColorAttribute(): string
    {
        if ($this->color) {
            return $this->color;
        }

        return match($this->event_type) {
            'inspection' => '#ef4444', // red
            'delivery' => '#3b82f6', // blue
            'meeting' => '#8b5cf6', // purple
            'maintenance' => '#f59e0b', // amber
            'weather' => '#06b6d4', // cyan
            default => '#6b7280', // gray
        };
    }

    /**
     * Получить иконку события в зависимости от типа
     */
    public function getEventIconAttribute(): string
    {
        if ($this->icon) {
            return $this->icon;
        }

        return match($this->event_type) {
            'inspection' => 'clipboard-check',
            'delivery' => 'truck',
            'meeting' => 'users',
            'maintenance' => 'wrench',
            'weather' => 'cloud',
            default => 'calendar',
        };
    }

    // ============================================
    // BUSINESS METHODS
    // ============================================

    /**
     * Запланировать напоминание
     */
    public function scheduleReminder(): bool
    {
        if (!$this->reminder_before_hours || $this->reminder_sent) {
            return false;
        }

        // Логика для отправки напоминания
        // Можно использовать Laravel Queue для отложенной отправки
        
        return true;
    }

    /**
     * Отметить напоминание как отправленное
     */
    public function markReminderSent(): void
    {
        $this->update([
            'reminder_sent' => true,
            'reminder_sent_at' => now(),
        ]);
    }

    /**
     * Изменить статус события
     */
    public function updateStatus(string $newStatus): bool
    {
        $allowedStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];
        
        if (!in_array($newStatus, $allowedStatuses)) {
            return false;
        }

        return $this->update(['status' => $newStatus]);
    }

    /**
     * Проверить, конфликтует ли событие с другими
     */
    public function hasConflicts(): bool
    {
        $start = $this->getStartDateTimeAttribute();
        $end = $this->getEndDateTimeAttribute();

        return static::query()
            ->where('project_id', $this->project_id)
            ->where('id', '!=', $this->id)
            ->where('is_blocking', true)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('event_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('event_date', '<=', $start->toDateString())
                          ->whereNotNull('end_date')
                          ->where('end_date', '>=', $start->toDateString());
                    });
            })
            ->exists();
    }

    /**
     * Создать повторяющиеся события
     */
    public function generateRecurringEvents(Carbon $until): array
    {
        if (!$this->is_recurring || !$this->recurrence_pattern) {
            return [];
        }

        $events = [];
        $currentDate = $this->event_date->copy();
        $config = $this->recurrence_config ?? [];

        while ($currentDate->lte($until)) {
            $currentDate = $this->getNextRecurrenceDate($currentDate);
            
            if ($currentDate->gt($until)) {
                break;
            }

            $events[] = $this->replicate()->fill([
                'event_date' => $currentDate,
                'recurring_parent_id' => $this->id,
                'is_recurring' => false,
            ]);
        }

        return $events;
    }

    /**
     * Получить следующую дату повторения
     */
    private function getNextRecurrenceDate(Carbon $currentDate): Carbon
    {
        return match($this->recurrence_pattern) {
            'daily' => $currentDate->addDay(),
            'weekly' => $currentDate->addWeek(),
            'monthly' => $currentDate->addMonth(),
            default => $currentDate,
        };
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('event_date', [$startDate, $endDate])
              ->orWhere(function ($subQuery) use ($startDate, $endDate) {
                  $subQuery->where('event_date', '<=', $startDate)
                           ->whereNotNull('end_date')
                           ->where('end_date', '>=', $startDate);
              });
        });
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereBetween('event_date', [
            now()->toDateString(),
            now()->addDays($days)->toDateString()
        ])->whereIn('status', ['scheduled', 'in_progress']);
    }

    public function scopeBlocking($query)
    {
        return $query->where('is_blocking', true);
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeNeedsReminder($query)
    {
        return $query->whereNotNull('reminder_before_hours')
            ->where('reminder_sent', false)
            ->where('event_date', '>=', now()->toDateString());
    }
}

