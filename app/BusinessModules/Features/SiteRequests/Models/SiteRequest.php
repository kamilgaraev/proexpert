<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;

/**
 * Модель заявки с объекта
 */
class SiteRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'site_requests';

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'assigned_to',
        'title',
        'description',
        'status',
        'priority',
        'request_type',
        'required_date',
        'notes',
        // Материалы
        'material_id',
        'material_name',
        'material_quantity',
        'material_unit',
        'delivery_address',
        'delivery_time_from',
        'delivery_time_to',
        'contact_person_name',
        'contact_person_phone',
        // Персонал
        'personnel_type',
        'personnel_count',
        'personnel_requirements',
        'hourly_rate',
        'work_hours_per_day',
        'work_start_date',
        'work_end_date',
        'work_location',
        'additional_conditions',
        // Техника
        'equipment_type',
        'equipment_specs',
        'rental_start_date',
        'rental_end_date',
        'rental_hours_per_day',
        'with_operator',
        'equipment_location',
        // Метаданные
        'metadata',
        'template_id',
        'payment_document_id',
    ];

    protected $casts = [
        'status' => SiteRequestStatusEnum::class,
        'priority' => SiteRequestPriorityEnum::class,
        'request_type' => SiteRequestTypeEnum::class,
        'personnel_type' => PersonnelTypeEnum::class,
        'required_date' => 'date',
        'work_start_date' => 'date',
        'work_end_date' => 'date',
        'rental_start_date' => 'date',
        'rental_end_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'material_quantity' => 'decimal:3',
        'with_operator' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'priority' => 'medium',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация-владелец
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

    /**
     * Пользователь-создатель (прораб)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Исполнитель заявки
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Шаблон, из которого создана заявка
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteRequestTemplate::class, 'template_id');
    }

    /**
     * История изменений
     */
    public function history(): HasMany
    {
        return $this->hasMany(SiteRequestHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * Событие календаря
     */
    public function calendarEvent(): HasOne
    {
        return $this->hasOne(SiteRequestCalendarEvent::class);
    }

    /**
     * Прикрепленные файлы (полиморфная связь)
     */
    public function files(): MorphMany
    {
        return $this->morphMany(\App\Models\File::class, 'fileable');
    }

    /**
     * Платежи, связанные с этой заявкой (many-to-many)
     */
    public function paymentDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\BusinessModules\Core\Payments\Models\PaymentDocument::class,
            'payment_document_site_requests',
            'site_request_id',
            'payment_document_id'
        )->withPivot('amount')->withTimestamps();
    }

    /**
     * Платеж, созданный из этой заявки (для быстрого доступа, если платеж один)
     */
    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(
            \App\BusinessModules\Core\Payments\Models\PaymentDocument::class,
            'payment_document_id'
        );
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
     * Scope для пользователя (создатель)
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для статуса
     */
    public function scopeWithStatus($query, string|SiteRequestStatusEnum $status)
    {
        $value = $status instanceof SiteRequestStatusEnum ? $status->value : $status;
        return $query->where('status', $value);
    }

    /**
     * Scope для типа заявки
     */
    public function scopeOfType($query, string|SiteRequestTypeEnum $type)
    {
        $value = $type instanceof SiteRequestTypeEnum ? $type->value : $type;
        return $query->where('request_type', $value);
    }

    /**
     * Scope для приоритета
     */
    public function scopeWithPriority($query, string|SiteRequestPriorityEnum $priority)
    {
        $value = $priority instanceof SiteRequestPriorityEnum ? $priority->value : $priority;
        return $query->where('priority', $value);
    }

    /**
     * Scope для срочных заявок
     */
    public function scopeUrgent($query)
    {
        return $query->where('priority', SiteRequestPriorityEnum::URGENT->value);
    }

    /**
     * Scope для активных заявок (не завершенных/отмененных)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            SiteRequestStatusEnum::COMPLETED->value,
            SiteRequestStatusEnum::CANCELLED->value,
            SiteRequestStatusEnum::REJECTED->value,
        ]);
    }

    /**
     * Scope для просроченных заявок
     */
    public function scopeOverdue($query)
    {
        return $query->active()
            ->whereNotNull('required_date')
            ->where('required_date', '<', now()->toDateString());
    }

    /**
     * Scope для заявок в диапазоне дат
     */
    public function scopeInDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope для заявок с календарными датами
     */
    public function scopeWithCalendarDates($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('required_date')
              ->orWhereNotNull('work_start_date')
              ->orWhereNotNull('rental_start_date');
        });
    }

    // ============================================
    // ACCESSORS
    // ============================================

    /**
     * Форматированная сумма для персонала
     */
    public function getEstimatedPersonnelCostAttribute(): ?float
    {
        if ($this->request_type !== SiteRequestTypeEnum::PERSONNEL_REQUEST) {
            return null;
        }

        if (!$this->personnel_count || !$this->hourly_rate || !$this->work_hours_per_day) {
            return null;
        }

        $days = 1;
        if ($this->work_start_date && $this->work_end_date) {
            $days = $this->work_start_date->diffInDays($this->work_end_date) + 1;
        }

        return $this->personnel_count * $this->hourly_rate * $this->work_hours_per_day * $days;
    }

    /**
     * Количество дней до required_date
     */
    public function getDaysUntilRequiredAttribute(): ?int
    {
        if (!$this->required_date) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->required_date, false);
    }

    /**
     * Проверка, просрочена ли заявка
     */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->required_date || $this->status->isFinal()) {
            return false;
        }

        return $this->required_date->isPast();
    }

    /**
     * Полное имя типа заявки
     */
    public function getTypeNameAttribute(): string
    {
        return $this->request_type->label();
    }

    /**
     * Полное имя статуса
     */
    public function getStatusNameAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * Цвет статуса
     */
    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверка возможности редактирования
     */
    public function canBeEdited(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Проверка возможности отмены
     */
    public function canBeCancelled(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Проверка наличия календарного события
     */
    public function hasCalendarEvent(): bool
    {
        return $this->required_date !== null ||
               $this->work_start_date !== null ||
               $this->rental_start_date !== null;
    }

    /**
     * Получить дату начала для календаря
     */
    public function getCalendarStartDate(): ?Carbon
    {
        return $this->work_start_date
            ?? $this->rental_start_date
            ?? $this->required_date;
    }

    /**
     * Получить дату окончания для календаря
     */
    public function getCalendarEndDate(): ?Carbon
    {
        return $this->work_end_date
            ?? $this->rental_end_date;
    }

    /**
     * Проверить принадлежность пользователю
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Проверить, назначена ли заявка пользователю
     */
    public function isAssignedTo(int $userId): bool
    {
        return $this->assigned_to === $userId;
    }

    /**
     * Проверить наличие связанного платежа
     */
    public function hasPaymentDocument(): bool
    {
        return $this->paymentDocuments()->exists() || $this->payment_document_id !== null;
    }

    /**
     * Проверить возможность создания платежа из заявки
     */
    public function canCreatePayment(): bool
    {
        // Заявка должна быть в статусе approved
        if ($this->status !== SiteRequestStatusEnum::APPROVED) {
            return false;
        }

        // Заявка не должна быть уже связана с платежом
        if ($this->hasPaymentDocument()) {
            return false;
        }

        return true;
    }
}

