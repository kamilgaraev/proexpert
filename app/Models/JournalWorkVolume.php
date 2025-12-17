<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalWorkVolume extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'estimate_item_id',
        'work_type_id',
        'quantity',
        'measurement_unit_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    // === RELATIONSHIPS ===

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }
}

