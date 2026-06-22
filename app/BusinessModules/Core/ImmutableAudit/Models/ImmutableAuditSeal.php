<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImmutableAuditSeal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'immutable_audit_seals';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'organization_id',
        'chain_scope',
        'from_sequence_id',
        'to_sequence_id',
        'events_count',
        'root_hash',
        'previous_seal_hash',
        'seal_hash',
        'sealed_at',
        'sealed_by',
        'storage_anchor',
        'integrity_status',
        'created_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'from_sequence_id' => 'integer',
        'to_sequence_id' => 'integer',
        'events_count' => 'integer',
        'storage_anchor' => 'array',
        'sealed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by');
    }
}
