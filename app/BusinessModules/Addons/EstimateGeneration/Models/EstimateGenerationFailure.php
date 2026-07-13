<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationFailure extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'estimate_generation_failures';

    protected $guarded = [];

    protected $casts = [
        'safe_context' => 'array',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'occurrence_count' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
