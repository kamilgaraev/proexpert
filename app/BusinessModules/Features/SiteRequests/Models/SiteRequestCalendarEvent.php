<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\Models\Organization;
use App\Models\Project;
use Carbon\Carbon;

/**
 * Модель календарного события для заявки
 */
class SiteRequestCalendarEvent extends Model
{
    use HasFactory;

    protected $table = 'site_request_calendar_events';

    protected $fillable = [
        'site_request_id',
        'organization_id',
        'project_id',
        'event_type',
        'title',
        'description',
        'color',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'all_day',
        'schedule_event_id',
    ];

    protected $casts = [
        'event_type' => CalendarEventTypeEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'all_day' => 'boolean',
    ];

    protected $attributes = [
        'all_day' => true,
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Заявка
     */
    public function siteRequest(): BelongsTo
    {
        return $this->belongsTo(SiteRequest::class);
    }

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Проект
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope для проекта
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope для типа события
     */
    public function scopeOfType($query, string|CalendarEventTypeEnum $type)
    {
        $value = $type instanceof CalendarEventTypeEnum ? $type->value : $type;
        return $query->where('event_type', $value);
    }

    /**
     * Scope для событий в диапазоне дат
     */
    public function scopeInDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($q2) use ($startDate, $endDate) {
                  $q2->where('start_date', '<=', $startDate)
                     ->where(function ($q3) use ($endDate) {
                         $q3->whereNull('end_date')
                            ->orWhere('end_date', '>=', $endDate);
                     });
              });
        });
    }

    /**
     * Scope для событий на конкретную дату
     */
    public function scopeOnDate($query, Carbon $date)
    {
        return $query->where(function ($q) use ($date) {
            $q->whereDate('start_date', $date)
              ->orWhere(function ($q2) use ($date) {
                  $q2->where('start_date', '<=', $date)
                     ->where(function ($q3) use ($date) {
                         $q3->whereNull('end_date')
                            ->orWhere('end_date', '>=', $date);
                     });
              });
        });
    }

    // ============================================
    // ACCESSORS
    // ============================================

    /**
     * Получить полную дату и время начала
     */
    public function getStartDateTimeAttribute(): Carbon
    {
        if ($this->start_time && !$this->all_day) {
            return Carbon::parse($this->start_date->format('Y-m-d') . ' ' . $this->start_time);
        }
        return $this->start_date->startOfDay();
    }

    /**
     * Получить полную дату и время окончания
     */
    public function getEndDateTimeAttribute(): Carbon
    {
        $endDate = $this->end_date ?? $this->start_date;

        if ($this->end_time && !$this->all_day) {
            return Carbon::parse($endDate->format('Y-m-d') . ' ' . $this->end_time);
        }
        return $endDate->endOfDay();
    }

    /**
     * Проверить, многодневное ли событие
     */
    public function getIsMultiDayAttribute(): bool
    {
        if (!$this->end_date) {
            return false;
        }
        return !$this->start_date->isSameDay($this->end_date);
    }

    /**
     * Получить длительность в днях
     */
    public function getDurationDaysAttribute(): int
    {
        if (!$this->end_date) {
            return 1;
        }
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверить, связано ли с модулем schedule-management
     */
    public function hasScheduleEvent(): bool
    {
        return !is_null($this->schedule_event_id);
    }

    /**
     * Получить данные для API ответа
     */
    public function toCalendarArray(): array
    {
        return [
            'id' => $this->id,
            'site_request_id' => $this->site_request_id,
            'title' => $this->title,
            'description' => $this->description,
            'event_type' => $this->event_type->value,
            'event_type_label' => $this->event_type->label(),
            'color' => $this->color,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'all_day' => $this->all_day,
            'is_multi_day' => $this->is_multi_day,
            'duration_days' => $this->duration_days,
        ];
    }
}

