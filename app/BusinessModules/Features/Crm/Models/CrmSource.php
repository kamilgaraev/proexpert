<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrmSource extends CrmModel
{
    protected $fillable = [
        'organization_id',
        'code',
        'label',
        'channel_type',
        'is_active',
        'settings',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}
