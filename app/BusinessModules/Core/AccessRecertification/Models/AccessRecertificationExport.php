<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessRecertificationExport extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_exports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'campaign_id',
        'requested_by_user_id',
        'status',
        'format',
        'filters',
        'row_count',
        'file_path',
        'audit_event_id',
        'completed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'filters' => 'array',
        'row_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationCampaign::class, 'campaign_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(ImmutableAuditEvent::class, 'audit_event_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
