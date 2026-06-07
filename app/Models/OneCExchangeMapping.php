<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OneCExchangeMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'scope',
        'external_type',
        'external_id',
        'external_name',
        'local_type',
        'local_id',
        'local_display_name',
        'status',
        'confidence_score',
        'source',
        'duplicate_warning',
        'safe_payload_preview',
        'approved_by',
        'verified_at',
        'archived_at',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'duplicate_warning' => 'boolean',
        'safe_payload_preview' => 'array',
        'verified_at' => 'datetime',
        'archived_at' => 'datetime',
    ];
}
