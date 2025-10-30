<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\Contract\ContractStateEventTypeEnum;

class ContractStateEvent extends Model
{
    use HasFactory;

    protected $table = 'contract_state_events';

    protected $fillable = [
        'contract_id',
        'event_type',
        'triggered_by_type',
        'triggered_by_id',
        'specification_id',
        'amount_delta',
        'effective_from',
        'supersedes_event_id',
        'metadata',
        'created_by_user_id',
    ];

    protected $casts = [
        'event_type' => ContractStateEventTypeEnum::class,
        'amount_delta' => 'decimal:2',
        'effective_from' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Договор, к которому относится событие
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Спецификация, связанная с событием
     */
    public function specification(): BelongsTo
    {
        return $this->belongsTo(Specification::class);
    }

    /**
     * Пользователь, создавший событие
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Событие, которое аннулируется этим событием
     */
    public function supersedesEvent(): BelongsTo
    {
        return $this->belongsTo(ContractStateEvent::class, 'supersedes_event_id');
    }

    /**
     * События, которые аннулируют это событие
     */
    public function supersededByEvents()
    {
        return $this->hasMany(ContractStateEvent::class, 'supersedes_event_id');
    }

    /**
     * Проверка, является ли событие активным (не аннулировано)
     */
    public function isActive(): bool
    {
        return $this->supersededByEvents()->count() === 0;
    }

    /**
     * Scope: события для конкретного договора
     */
    public function scopeForContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    /**
     * Scope: активные события (не аннулированные)
     */
    public function scopeActive($query)
    {
        return $query->whereDoesntHave('supersededByEvents');
    }

    /**
     * Scope: события до определенной даты
     */
    public function scopeAsOfDate($query, $date)
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('effective_from')
              ->orWhere('effective_from', '<=', $date);
        });
    }

    /**
     * Scope: по типу события
     */
    public function scopeOfType($query, ContractStateEventTypeEnum $type)
    {
        return $query->where('event_type', $type);
    }
}

