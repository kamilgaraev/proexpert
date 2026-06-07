<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OneCExchangeMessage extends Model
{
    protected $fillable = [
        'organization_id',
        'operation_id',
        'attempt_number',
        'status',
        'failure_type',
        'transport_status',
        'retryable',
        'next_retry_at',
        'safe_error_code',
        'safe_error_message',
        'request_hash',
        'response_hash',
        'safe_request_preview',
        'safe_response_preview',
        'duration_ms',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'retryable' => 'boolean',
        'next_retry_at' => 'datetime',
        'safe_request_preview' => 'array',
        'safe_response_preview' => 'array',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
