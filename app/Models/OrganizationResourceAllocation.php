<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationResourceAllocation extends Model
{
    protected $fillable = [
        'organization_id',
        'commercial_account_id',
        'resource_slug',
        'limit_key',
        'quantity',
        'source',
        'status',
        'period_start_at',
        'period_end_at',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'period_start_at' => 'immutable_datetime',
        'period_end_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function commercialAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationCommercialAccount::class, 'commercial_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($period): void {
                $period->whereNull('period_start_at')->orWhere('period_start_at', '<=', now());
            })
            ->where(function ($period): void {
                $period->whereNull('period_end_at')->orWhere('period_end_at', '>', now());
            });
    }
}
