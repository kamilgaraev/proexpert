<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEquipment extends Model
{
    use HasFactory;

    protected $table = 'journal_equipment';

    protected $fillable = [
        'journal_entry_id',
        'contract_id',
        'estimate_item_id',
        'equipment_name',
        'equipment_type',
        'quantity',
        'hours_used',
    ];

    protected $casts = [
        'contract_id' => 'integer',
        'quantity' => 'integer',
        'hours_used' => 'decimal:8',
    ];

    // === RELATIONSHIPS ===

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
