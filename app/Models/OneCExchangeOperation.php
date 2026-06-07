<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OneCExchangeOperation extends Model
{
    protected $fillable = [
        'organization_id',
        'run_id',
        'created_by',
        'mapping_id',
        'operation_key',
        'correlation_id',
        'idempotency_key',
        'direction',
        'scope',
        'entity_type',
        'entity_id',
        'external_id',
        'status',
        'accounting_status',
        'failure_type',
        'safe_error_code',
        'safe_error_message',
        'retry_count',
        'max_attempts',
        'retryable',
        'next_retry_at',
        'last_attempt_at',
        'dead_lettered_at',
        'source_hash',
        'payload_hash',
        'safe_payload_preview',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'retryable' => 'boolean',
        'next_retry_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
        'safe_payload_preview' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(OneCExchangeMessage::class, 'operation_id');
    }
}
