<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcceptanceSignoff extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'acceptance_scope_id',
        'signed_by_user_id',
        'status',
        'comment',
        'signed_at',
        'metadata',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScope::class, 'acceptance_scope_id');
    }
}
