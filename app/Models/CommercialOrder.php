<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\CommercialOfferType;
use App\Enums\Billing\CommercialOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommercialOrder extends Model
{
    protected $hidden = ['assistant_revenue_allocation', 'paid_period_snapshot'];

    protected $fillable = [
        'public_id', 'organization_id', 'commercial_account_id', 'user_id', 'status',
        'offer_type', 'quote_version', 'billing_period', 'renew_subscription', 'selected_package_slugs', 'current_package_slugs', 'selected_resource_addons',
        'amount_minor', 'amount', 'currency', 'period_start_at', 'period_end_at',
        'auto_renew_consent', 'client_idempotency_key', 'server_idempotency_key', 'kind',
    ];

    protected $casts = [
        'status' => CommercialOrderStatus::class,
        'offer_type' => CommercialOfferType::class,
        'quote_version' => 'integer',
        'renew_subscription' => 'boolean',
        'selected_package_slugs' => 'array',
        'current_package_slugs' => 'array',
        'selected_resource_addons' => 'array',
        'assistant_revenue_allocation' => 'array',
        'paid_period_snapshot' => 'array',
        'amount_minor' => 'integer',
        'amount' => 'decimal:2',
        'period_start_at' => 'immutable_datetime',
        'period_end_at' => 'immutable_datetime',
        'auto_renew_consent' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function commercialAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationCommercialAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercialPayment::class)->orderBy('attempt_number');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(CommercialPayment::class)->ofMany('attempt_number', 'max');
    }

    public function renewalCycle(): HasOne
    {
        return $this->hasOne(CommercialRenewalCycle::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CommercialRefund::class);
    }

    public function bankRefunds(): HasManyThrough
    {
        return $this->hasManyThrough(CommercialBankRefund::class, CommercialBankInvoice::class,
            'commercial_order_id', 'commercial_bank_invoice_id');
    }
}
