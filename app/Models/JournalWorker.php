<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalWorker extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'specialty',
        'workers_count',
        'hours_worked',
    ];

    protected $casts = [
        'workers_count' => 'integer',
        'hours_worked' => 'decimal:2',
    ];

    // === RELATIONSHIPS ===

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }
}

