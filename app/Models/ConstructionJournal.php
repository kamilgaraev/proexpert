<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use Carbon\Carbon;

class ConstructionJournal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'name',
        'journal_number',
        'start_date',
        'end_date',
        'status',
        'created_by_user_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => JournalStatusEnum::class,
    ];

    // === RELATIONSHIPS ===

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ConstructionJournalEntry::class, 'journal_id')
            ->orderBy('entry_date')
            ->orderBy('entry_number');
    }

    // === SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('status', JournalStatusEnum::ACTIVE);
    }

    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // === METHODS ===

    /**
     * Получить общее количество записей в журнале
     */
    public function getTotalEntriesCount(): int
    {
        return $this->entries()->count();
    }

    /**
     * Получить количество утвержденных записей
     */
    public function getApprovedEntriesCount(): int
    {
        return $this->entries()->approved()->count();
    }

    /**
     * Получить диапазон дат журнала
     */
    public function getDateRange(): array
    {
        return [
            'start' => $this->start_date,
            'end' => $this->end_date ?? now(),
        ];
    }

    /**
     * Проверить возможность редактирования журнала
     */
    public function canBeEdited(): bool
    {
        return $this->status === JournalStatusEnum::ACTIVE;
    }

    /**
     * Архивировать журнал
     */
    public function archive(): bool
    {
        return $this->update(['status' => JournalStatusEnum::ARCHIVED]);
    }

    /**
     * Закрыть журнал
     */
    public function close(): bool
    {
        return $this->update([
            'status' => JournalStatusEnum::CLOSED,
            'end_date' => $this->end_date ?? now(),
        ]);
    }

    /**
     * Получить следующий номер записи
     */
    public function getNextEntryNumber(): int
    {
        $lastEntry = $this->entries()->orderBy('entry_number', 'desc')->first();
        return $lastEntry ? $lastEntry->entry_number + 1 : 1;
    }

    /**
     * Resolve route binding with organization check
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $journal = static::where($this->getRouteKeyName(), $value)->firstOrFail();
        
        $user = request()->user();
        if ($user && $user->current_organization_id) {
            if ($journal->organization_id !== $user->current_organization_id) {
                abort(403, 'У вас нет доступа к этому журналу');
            }
        }
        
        return $journal;
    }
}

