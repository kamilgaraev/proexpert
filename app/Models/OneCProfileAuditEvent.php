<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OneCProfileAuditEvent extends Model
{
    protected $table = 'one_c_profile_audit_events';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'one_c_integration_profile_id',
        'one_c_base_id',
        'actor_id',
        'event_type',
        'result_code',
        'result_status',
        'duration_ms',
        'safe_context',
        'created_at',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'safe_context' => 'array',
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OneCIntegrationProfile::class, 'one_c_integration_profile_id');
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(OneCBase::class, 'one_c_base_id');
    }
}
