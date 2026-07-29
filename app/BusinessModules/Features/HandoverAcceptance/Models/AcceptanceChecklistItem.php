<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcceptanceChecklistItem extends Model
{
    protected $fillable = [
        'acceptance_checklist_id',
        'code',
        'title',
        'is_required',
        'status',
        'reviewed_at',
        'reviewed_by_user_id',
        'comment',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(AcceptanceChecklist::class, 'acceptance_checklist_id');
    }
}
