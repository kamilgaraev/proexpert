<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProcurementAuditEvent extends Model
{
    protected $table = 'procurement_audit_events';

    protected $fillable = [
        'organization_id',
        'subject_type',
        'subject_id',
        'event_type',
        'actor_id',
        'supplier_party_id',
        'occurred_at',
        'payload',
    ];

    protected $casts = [
        'event_type' => ProcurementAuditEventTypeEnum::class,
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    protected $attributes = [
        'payload' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function supplierParty(): BelongsTo
    {
        return $this->belongsTo(SupplierParty::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
