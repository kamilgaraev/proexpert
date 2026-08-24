<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateVersion extends Model
{
    protected $fillable = [
        'estimate_id',
        'organization_id',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'version_number',
        'label',
        'comment',
        'snapshot_type',
        'estimate_status',
        'snapshot',
        'snapshot_hash',
        'total_amount',
        'total_amount_with_vat',
        'total_direct_costs',
        'status',
        'idempotency_key',
        'superseded_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version_number' => 'integer',
        'approved_at' => 'datetime',
        'superseded_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'total_amount_with_vat' => 'decimal:2',
        'total_direct_costs' => 'decimal:2',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (EstimateVersion $version): void {
            $immutable = [
                'estimate_id',
                'organization_id',
                'created_by_user_id',
                'approved_by_user_id',
                'approved_at',
                'version_number',
                'snapshot_type',
                'estimate_status',
                'snapshot',
                'snapshot_hash',
                'total_amount',
                'total_amount_with_vat',
                'total_direct_costs',
                'idempotency_key',
            ];

            if (array_intersect(array_keys($version->getDirty()), $immutable) !== []) {
                throw new \DomainException(trans_message('estimate.version_immutable'));
            }
        });

        static::deleting(static function (): never {
            throw new \DomainException(trans_message('estimate.version_immutable'));
        });
    }
}
