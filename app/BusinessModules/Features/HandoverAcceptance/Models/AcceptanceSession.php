<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AcceptanceSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'acceptance_scope_id',
        'created_by_user_id',
        'scheduled_at',
        'started_at',
        'completed_at',
        'status',
        'participant_user_ids',
        'summary',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'participant_user_ids' => 'array',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScope::class, 'acceptance_scope_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AcceptanceFinding::class);
    }
}
