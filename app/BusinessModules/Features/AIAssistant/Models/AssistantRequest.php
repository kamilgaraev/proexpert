<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantRequest extends Model
{
    protected $table = 'ai_assistant_requests';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
        'reservation_id' => 'integer',
        'calls_used' => 'integer',
        'max_calls' => 'integer',
        'approved_max_minor' => 'integer',
        'response' => 'array',
        'payload' => 'array',
        'started_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
