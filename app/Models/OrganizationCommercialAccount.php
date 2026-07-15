<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\CommercialAccountStatus;
use App\Enums\Billing\CommercialOfferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationCommercialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'responsible_user_id',
        'status',
        'offer_type',
        'quote_version',
        'billing_anchor_at',
        'current_period_start_at',
        'current_period_end_at',
        'auto_renew_enabled',
        'saved_payment_method_id', 'saved_payment_method_at', 'saved_payment_method_active',
        'auto_renew_consented_at', 'auto_renew_terms_version', 'grace_started_at', 'grace_ends_at',
    ];

    protected $casts = [
        'status' => CommercialAccountStatus::class,
        'offer_type' => CommercialOfferType::class,
        'quote_version' => 'integer',
        'billing_anchor_at' => 'datetime',
        'current_period_start_at' => 'datetime',
        'current_period_end_at' => 'datetime',
        'auto_renew_enabled' => 'boolean',
        'saved_payment_method_at' => 'immutable_datetime',
        'saved_payment_method_active' => 'boolean',
        'auto_renew_consented_at' => 'immutable_datetime',
        'grace_started_at' => 'immutable_datetime',
        'grace_ends_at' => 'immutable_datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function packageSubscriptions(): HasMany
    {
        return $this->hasMany(OrganizationPackageSubscription::class, 'commercial_account_id');
    }

    public function renewalCycles(): HasMany
    {
        return $this->hasMany(CommercialRenewalCycle::class, 'commercial_account_id');
    }

    public function contourChanges(): HasMany
    {
        return $this->hasMany(CommercialContourChange::class, 'commercial_account_id');
    }
}
