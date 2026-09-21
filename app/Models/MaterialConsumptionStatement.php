<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class MaterialConsumptionStatement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SIGNED = 'signed';

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'period_start',
        'period_end',
        'number',
        'version_number',
        'status',
        'calculation_version',
        'idempotency_key',
        'payload_hash',
        'snapshot',
        'totals',
        'blockers',
        'is_ready',
        'foreman_name',
        'document_date',
        'performed_at',
        'approved_at',
        'approved_by_user_id',
        'signed_at',
        'signed_by_user_id',
        'signed_file_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'document_date' => 'date',
        'performed_at' => 'date',
        'snapshot' => 'array',
        'totals' => 'array',
        'blockers' => 'array',
        'is_ready' => 'boolean',
        'version_number' => 'integer',
        'approved_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
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
