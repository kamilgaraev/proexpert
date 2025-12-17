<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'material_id',
        'material_name',
        'quantity',
        'measurement_unit',
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

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}

