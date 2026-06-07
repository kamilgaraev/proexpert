<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class OneCIntegrationProfile extends Model
{
    protected $table = 'one_c_integration_profiles';

    protected $fillable = [
        'organization_id',
        'one_c_base_id',
        'code',
        'name',
        'environment',
        'auth_type',
        'exchange_mode',
        'status',
        'status_reason_code',
        'is_default_for_legal_entity',
        'routing_priority',
        'allowed_scopes',
        'connection_status',
        'last_connection_check_at',
        'last_connection_check_code',
        'protocol_version',
        'connector_version',
        'supported_scopes',
        'warning_codes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_default_for_legal_entity' => 'boolean',
        'routing_priority' => 'integer',
        'allowed_scopes' => 'array',
        'last_connection_check_at' => 'datetime',
        'supported_scopes' => 'array',
        'warning_codes' => 'array',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(OneCBase::class, 'one_c_base_id');
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(OneCProfileSecret::class, 'one_c_integration_profile_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OneCProfileAuditEvent::class, 'one_c_integration_profile_id');
    }

    public function latestConnectionAuditEvent(): HasOne
    {
        return $this->hasOne(OneCProfileAuditEvent::class, 'one_c_integration_profile_id')
            ->where('event_type', 'connection_check_run')
            ->latestOfMany();
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
