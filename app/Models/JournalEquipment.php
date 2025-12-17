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
        'equipment_name',
        'equipment_type',
        'quantity',
        'hours_used',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'hours_used' => 'decimal:2',
    ];

    // === RELATIONSHIPS ===

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }
}

