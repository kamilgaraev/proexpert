<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class ContractPeriodCertificate extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_ANNULLED = 'annulled';

    protected $fillable = [
        'organization_id',
        'contract_id',
        'project_id',
        'period_start',
        'period_end',
        'number',
        'version_number',
        'status',
        'calculation_version',
        'idempotency_key',
        'payload_hash',
        'source_act_ids',
        'composition',
        'snapshot',
        'totals',
        'document_date',
        'performed_at',
        'approved_at',
        'approved_by_user_id',
        'signed_at',
        'signed_by_user_id',
        'signed_file_id',
        'has_annulled_acts',
        'created_by_user_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'document_date' => 'date',
        'performed_at' => 'date',
        'source_act_ids' => 'array',
        'composition' => 'array',
        'snapshot' => 'array',
        'totals' => 'array',
        'approved_at' => 'datetime',
        'signed_at' => 'datetime',
        'has_annulled_acts' => 'boolean',
        'version_number' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ContractPeriodCertificateAct::class, 'certificate_id');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SIGNED], true);
    }
}
