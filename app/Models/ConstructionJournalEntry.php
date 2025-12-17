<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Carbon\Carbon;

class ConstructionJournalEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'journal_id',
        'schedule_task_id',
        'entry_date',
        'entry_number',
        'work_description',
        'status',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'weather_conditions',
        'problems_description',
        'safety_notes',
        'visitors_notes',
        'quality_notes',
        'rejection_reason',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'approved_at' => 'datetime',
        'weather_conditions' => 'array',
        'status' => JournalEntryStatusEnum::class,
    ];

    // === RELATIONSHIPS ===

    public function journal(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournal::class, 'journal_id');
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function workVolumes(): HasMany
    {
        return $this->hasMany(JournalWorkVolume::class, 'journal_entry_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(JournalWorker::class, 'journal_entry_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(JournalEquipment::class, 'journal_entry_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(JournalMaterial::class, 'journal_entry_id');
    }

    // === SCOPES ===

    public function scopeDraft($query)
    {
        return $query->where('status', JournalEntryStatusEnum::DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', JournalEntryStatusEnum::SUBMITTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', JournalEntryStatusEnum::APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', JournalEntryStatusEnum::REJECTED);
    }

    public function scopeByDate($query, Carbon $date)
    {
        return $query->whereDate('entry_date', $date);
    }

    public function scopeByDateRange($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    // === METHODS ===

    /**
     * Отправить запись на утверждение
     */
    public function submit(): bool
    {
        if (!$this->status->canSubmit()) {
            throw new \DomainException('Запись не может быть отправлена на утверждение в текущем статусе');
        }

        return $this->update(['status' => JournalEntryStatusEnum::SUBMITTED]);
    }

    /**
     * Утвердить запись
     */
    public function approve(User $approver): bool
    {
        if (!$this->status->canApprove()) {
            throw new \DomainException('Запись не может быть утверждена в текущем статусе');
        }

        return $this->update([
            'status' => JournalEntryStatusEnum::APPROVED,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Отклонить запись
     */
    public function reject(User $approver, string $reason): bool
    {
        if (!$this->status->canReject()) {
            throw new \DomainException('Запись не может быть отклонена в текущем статусе');
        }

        return $this->update([
            'status' => JournalEntryStatusEnum::REJECTED,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Проверить возможность редактирования записи
     */
    public function canBeEdited(): bool
    {
        return $this->status->canEdit();
    }

    /**
     * Обновить прогресс связанной задачи графика
     */
    public function updateScheduleProgress(): void
    {
        if (!$this->schedule_task_id || $this->status !== JournalEntryStatusEnum::APPROVED) {
            return;
        }

        // Логика обновления будет в JournalScheduleIntegrationService
        event(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved($this));
    }

    /**
     * Получить общий объем выполненных работ
     */
    public function getTotalWorkVolume(): float
    {
        return $this->workVolumes()->sum('quantity');
    }

    /**
     * Получить общее количество рабочих
     */
    public function getTotalWorkersCount(): int
    {
        return $this->workers()->sum('workers_count');
    }

    /**
     * Resolve route binding with organization check
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $entry = static::where($this->getRouteKeyName(), $value)->firstOrFail();
        
        $user = request()->user();
        if ($user && $user->current_organization_id) {
            $journal = $entry->journal;
            if ($journal && $journal->organization_id !== $user->current_organization_id) {
                abort(403, 'У вас нет доступа к этой записи журнала');
            }
        }
        
        return $entry;
    }
}

