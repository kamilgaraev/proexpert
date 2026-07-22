<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationTargetedRebuildOperation extends Model
{
    protected $table = 'estimate_generation_targeted_rebuild_operations';

    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'operation_id',
        'idempotency_key',
        'organization_id',
        'project_id',
        'session_id',
        'expected_state_version',
        'source_input_version',
        'root_input_hash',
        'source_draft_fingerprint',
        'package_key',
        'status',
        'lease_token',
        'lease_expires_at',
        'attempt_count',
        'result_delta',
        'safe_arbiter_review',
        'reviewed_at',
        'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'session_id' => 'integer',
        'expected_state_version' => 'integer',
        'attempt_count' => 'integer',
        'result_delta' => 'array',
        'safe_arbiter_review' => 'array',
        'lease_expires_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }
}
