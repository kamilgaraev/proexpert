<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class RagGlobalIndexEvent extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';

    protected $table = 'ai_rag_global_index_events';

    protected $fillable = ['source_type', 'entity_type', 'entity_id', 'revision', 'after_organization_id', 'status',
        'queued_at', 'heartbeat_at', 'lease_expires_at', 'lease_token', 'completed_at', 'last_error'];

    protected $casts = ['revision' => 'integer', 'after_organization_id' => 'integer', 'queued_at' => 'datetime',
        'heartbeat_at' => 'datetime', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime'];
}
