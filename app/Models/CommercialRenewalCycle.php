<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommercialRenewalCycle extends Model
{
    protected $fillable = [
        'organization_id', 'commercial_account_id', 'commercial_order_id', 'status', 'due_at', 'billing_due_date',
        'target_period_start_at', 'target_period_end_at', 'grace_deadline_at', 'attempt_count',
        'last_attempt_at', 'next_attempt_at', 'paid_at', 'suspended_at', 'manual_review_at',
    ];

    protected $casts = [
        'due_at' => 'immutable_datetime', 'billing_due_date' => 'immutable_date', 'target_period_start_at' => 'immutable_datetime',
        'target_period_end_at' => 'immutable_datetime', 'grace_deadline_at' => 'immutable_datetime',
        'attempt_count' => 'integer', 'last_attempt_at' => 'immutable_datetime',
        'next_attempt_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime',
        'suspended_at' => 'immutable_datetime', 'manual_review_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(OrganizationCommercialAccount::class, 'commercial_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercialPayment::class);
    }
}
