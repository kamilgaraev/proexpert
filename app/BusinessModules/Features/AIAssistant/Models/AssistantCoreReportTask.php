<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssistantCoreReportTask extends Model
{
    public const PENDING = ['queued', 'building_run', 'building_export'];

    protected $table = 'ai_assistant_core_report_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'assistant_request_id' => 'integer',
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'parameters' => 'array',
        'failure_count' => 'integer',
        'retry_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
        'execution_expires_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    public function assistantRequest(): BelongsTo
    {
        return $this->belongsTo(AssistantRequest::class);
    }
}
