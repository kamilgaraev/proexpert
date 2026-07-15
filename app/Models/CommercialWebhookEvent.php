<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_name', 'object_id', 'authoritative_status', 'processing_result',
        'source_ip', 'fingerprint', 'safe_payload', 'processed_at',
    ];

    protected $casts = [
        'safe_payload' => 'array',
        'processed_at' => 'immutable_datetime',
    ];
}
