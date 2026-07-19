<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImmutableAuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'immutable_audit_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'sequence_id',
        'organization_id',
        'project_id',
        'domain',
        'event_type',
        'action',
        'result',
        'severity',
        'occurred_at',
        'recorded_at',
        'actor_type',
        'actor_user_id',
        'actor_snapshot',
        'impersonator_user_id',
        'source',
        'source_route',
        'source_model',
        'source_table',
        'source_event_id',
        'correlation_id',
        'idempotency_key',
        'subject_type',
        'subject_id',
        'subject_label',
        'related_subjects',
        'reason',
        'before_state',
        'after_state',
        'diff',
        'domain_context',
        'sensitive_fields',
        'redaction_policy_version',
        'payload_hash',
        'previous_hash',
        'record_hash',
        'chain_scope',
        'chain_version',
        'sealed_at',
        'seal_id',
        'integrity_status',
        'retention_until',
        'created_at',
    ];

    protected $casts = [
        'sequence_id' => 'integer',
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'actor_user_id' => 'integer',
        'impersonator_user_id' => 'integer',
        'actor_snapshot' => 'array',
        'related_subjects' => 'array',
        'before_state' => 'array',
        'after_state' => 'array',
        'diff' => 'array',
        'domain_context' => 'array',
        'sensitive_fields' => 'array',
        'chain_version' => 'integer',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
        'sealed_at' => 'datetime',
        'retention_until' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new ImmutableDataException(self::class, 'update');
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
