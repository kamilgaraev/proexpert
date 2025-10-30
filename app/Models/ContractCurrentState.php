<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractCurrentState extends Model
{
    use HasFactory;

    protected $table = 'contract_current_state';

    protected $primaryKey = 'contract_id';

    public $incrementing = false;

    protected $fillable = [
        'contract_id',
        'active_specification_id',
        'current_total_amount',
        'active_events',
        'calculated_at',
    ];

    protected $casts = [
        'current_total_amount' => 'decimal:2',
        'active_events' => 'array',
        'calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Договор, к которому относится состояние
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Активная спецификация
     */
    public function activeSpecification(): BelongsTo
    {
        return $this->belongsTo(Specification::class, 'active_specification_id');
    }

    /**
     * Получить активные события как коллекцию моделей
     */
    public function getActiveEventModelsAttribute()
    {
        if (empty($this->active_events)) {
            return collect();
        }

        return ContractStateEvent::whereIn('id', $this->active_events)->get();
    }

    /**
     * Проверка, устарело ли состояние (нужен пересчет)
     */
    public function isStale($thresholdMinutes = 5): bool
    {
        if (!$this->calculated_at) {
            return true;
        }

        return $this->calculated_at->diffInMinutes(now()) > $thresholdMinutes;
    }
}

