<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrmMergeEvent extends CrmModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'entity_type',
        'master_id',
        'duplicate_id',
        'actor_user_id',
        'reason',
        'before',
        'after',
        'affected_links',
        'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'affected_links' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
